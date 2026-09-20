# WebStats 部署与运维手册

> 适用于 v1.0.0 及以后的发布包。安装向导支持 **Web 图形化安装** 与 **命令行安装** 两种方式，
> 全程不会 DROP/TRUNCATE 任何已有数据，脚本可重复执行。

---

## 一、环境要求

| 组件 | 最低要求 | 推荐 | 说明 |
| --- | --- | --- | --- |
| PHP | 7.4 | 8.1 / 8.2 | 扩展：`pdo`、`pdo_mysql`、`json`、`mbstring`；可选 `openssl`、`curl`、`zip` |
| MySQL | 5.7 | 8.0 | 字符集 utf8mb4；`events` 表按 `day` 月分区（5.7 已支持） |
| Redis | 3.2 | 6.x / 7.x | **不需要 phpredis 扩展**（内置纯 PHP RESP 客户端）；可选，缺失则降级 |
| Web 服务器 | — | Nginx / Apache | 站点根目录指向 `<包根>/public` |
| Node.js | 18 | 20+ | 仅「自行构建前端」时需要；发布包已内置构建产物 |

> **Redis 是可选的**：连接失败时系统自动降级为直写 MySQL，此时「实时访客 / 在线数 / 会话与停留时长」不可用（明细仍完整），
> 安装向导与 `scripts/doctor.php` 都会提示。

---

## 二、安装

### 2.0 目录布局（先确认这一件事）

v1.0.1 起发布包：

```
<包根>/                        站点目录，例：/www/wwwroot/webstats
├── app/                       后端代码（入口引导 app/bootstrap.php）
├── config.php                 出厂默认配置（部署凭据在 data/installed.php）
├── data/                      运行期数据：installed.php / install.lock / ip2region.xdb + ip2region_v6.xdb / verify/ / backup/
├── public/                    ★ Web 根目录（运行目录）
│   ├── index.php              管理端 API 入口 + 未安装引导 + SPA 回退
│   ├── collect.php            采集端点
│   ├── index.html assets/ geo/ sdk/
│   └── install/               安装向导（安装完成后请删除）
├── scripts/                   worker.php / cron.php / doctor.php / geo-doctor.php / selfcheck.php / fetch-geo.php
├── sql/                       install.sql（幂等全量建表）+ upgrade-*.sql（增量升级）
└── deploy/  docs/deploy.md  README.md  RELEASE.md  CHANGELOG.md
```

> 发布包已裁剪：`sdk/`（与 `public/sdk/wstat.js` 重复）、`sql/schema.sql`、`sql/wstat.sql`
> （历史参考脚本，含 DROP，仅供源码仓库查阅）、`docs/architecture.md`、`docs/review-feature-audit.html`
> （开发/内部文档）、`deploy/nginx.bt.conf.sample`（已废弃布局专用）、`scripts/seed-*.php`
> （演示数据造数）以及全部 `selftest-*` / 探针脚本。这些裁剪由构建脚本的「包内容校验」强制保证，见 §9.3。

| 面板项 | 填什么 |
| --- | --- |
| 网站目录（宝塔） | `<包根>`，例 `/www/wwwroot/webstats` |
| 运行目录（宝塔） | `/public` |
| Nginx `root`（自建/其他面板） | `<包根>/public` |

> `app/` `data/` `scripts/` `sql/` 都在 Web 根**之外**，天然不可直访
> （`data/installed.php` 含数据库凭据，也不会被下载到），**无需**任何额外屏蔽规则。

**安装器仍会自动探测**后端目录（含 `app/bootstrap.php`）与建表脚本位置，下列历史布局照旧可以安装
（仅用于从 ≤1.0.0 原地升级的场景，新部署请一律使用上面的统一布局）：

**`sql/install.sql` 的搜索顺序**（命中即用，可用环境变量 `WSTAT_SQL_FILE` 强制指定）：

```
WSTAT_SQL_FILE → 项目根/sql/ → 项目根/server/sql/ → 安装器目录/sql/
              → Web根/sql/ → 网站同级目录/sql/ → 上一级目录/sql/
```

> 发布包已在 `public/install/sql/install.sql` **自带一份**建表脚本，
> 因此即使 `sql/` 被放到别处，安装也不会失败。

### 方式 A：Web 安装向导（推荐）

1. 上传发布包到服务器（例如 `/www/wwwroot/webstats`），按上表把「网站目录 / 运行目录」指对；
2. **直接访问站点首页** `http://你的域名/` —— **未安装时服务端自动 302 跳转到 `/install/`**，不必记地址
   （若 Web 服务器直接由 `index.html` 提供首页，前端还会兜底探测 `/api/health` 并跳转）；
3. 按四步向导操作：
   1. **环境检测** —— PHP 版本、扩展、目录写权限、**部署布局**（项目根 / 后端目录 / 建表脚本实际路径）、离线 IP 库；
   2. **数据库与 Redis** —— 填写连接信息，向导会实测连接（可勾选「库不存在时自动创建」）；
   3. **创建管理员** —— 设置登录邮箱与密码，可选开启调试模式；
   4. **完成** —— 显示必须执行的后续命令、完整站点接入代码。
4. 安装完成后**务必删除 `install` 目录**：
   ```bash
   rm -rf /www/wwwroot/webstats/public/install
   ```

> **目录结构特殊时（重要）**：若向导第一步出现「目录结构诊断」页（例如宝塔 `open_basedir` 只允许 Web 根、
> 包根在其外部），页面上会逐级列出探测过的路径、标出被 `open_basedir` 拦下的层级，并提供**手动指路**表单：
> 填入项目根（含 `app/` 与 `config.php` 的目录）的绝对路径，即生成 `public/install/local.php`，
> **无需修改 PHP-FPM / Nginx 配置**。也可手工创建 `public/install/local.php`：
> ```php
> <?php
> return [
>     'root' => '/www/wwwroot/webstats',   // 统一布局：包根即项目根
>     // 'server_dir' => '/www/wwwroot/webstats/server',  // ≤1.0.0 旧包才是 server 目录
>     // 'sql_file' => '/www/wwwroot/webstats/sql/install.sql',
> ];
> ```
> 删除该文件即恢复自动探测。

> 向导会写入 `data/installed.php`（数据库/Redis 凭据）与 `data/install.lock`（防重复安装）。
> 已安装状态下再次访问向导会看到「系统已安装」提示；如需重装：`php public/install/cli.php --unlock`（或删除 lock 文件）。

### 方式 B：命令行安装（SSH / 自动化 / 无浏览器场景）

```bash
cd /www/wwwroot/webstats

# 环境自检（含目录布局探测）
php public/install/cli.php --check

# 目录结构特殊时显式指定项目根（等价于写 local.php）
php public/install/cli.php --check --root=/www/wwwroot/webstats

# 一键安装（示例）
php public/install/cli.php \
  --db-host=127.0.0.1 --db-port=3306 \
  --db-name=webstats --db-user=webstats --db-pass='你的数据库密码' --create-db \
  --redis-host=127.0.0.1 --redis-port=6379 --redis-auth='你的redis密码' --redis-db=0 \
  --admin-email=admin@example.com --admin-pass='Admin@12345'
```

不带参数执行则为**交互式问答**。其它子命令：

| 命令 | 作用 |
| --- | --- |
| `php public/install/cli.php --check` | 仅环境自检（退出码非 0 表示存在必需项未通过） |
| `php public/install/cli.php --status` | 查看安装状态、数据库/Redis 配置、锁文件位置、**部署布局** |
| `php public/install/cli.php --unlock` | 解除安装锁（重装 / 迁移前使用） |
| `php public/install/cli.php --force ...` | 忽略已安装状态强制重装（会覆盖 `installed.php`） |
| `--root=<目录>` / `--sql=<文件>` | 显式指定项目根 / 建表脚本（面板环境常用） |

### 方式 C：完全手工（不推荐）

```bash
# install.sql 内的分区占位标记 __PARTITIONS__ 需替换为真实分区行（参考文件末尾注释或安装器生成结果）
mysql -uwebstats -p webstats < sql/install.sql
cp data/installed.php.example data/installed.php   # 按需手写
```

---

## 三、服务器配置

### 3.1 Nginx

完整样例见 `deploy/nginx.conf.sample`。核心片段：

```nginx
root /www/wwwroot/webstats/public;             # ← 包根下的 public（宝塔：网站目录=包根、运行目录=/public）
index index.php index.html;                    # 必须含 index.php：/install/ 才能直接打开

# 非 API 也交给 index.php：未安装 → 302 /install/；已安装 → 输出 index.html
location / { try_files $uri $uri/ /index.php$is_args$args; }
location ^~ /api/ { try_files $uri /index.php$is_args$args; }
# app/ data/ scripts/ sql/ 都在 Web 根之外，无需屏蔽；仅从 ≤1.0.0 原地升级时才有 /server/ 需要 deny
location ~ \.php$ {                            # 采集端点 /collect.php 也走这里，不要单独拦截
    fastcgi_pass 127.0.0.1:9000;               # 宝塔已自带该块，无需重复配置
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

> **不要把 `/api/` 与 `/collect.php` 交给缓存。** 应用已对全部 JSON 响应发送
> `Cache-Control: no-store, no-cache, must-revalidate`，但 CDN、Nginx `proxy_cache` 等中间层
> **可能无视源站指令**而自行缓存。若站点前面套了 CDN，或你为 `/api/` 配了 `proxy_cache`，
> 请把这两类路径加入**不缓存**名单，否则面板会出现「浏览量 / 访客信息几分钟甚至更久不刷新」的假象
> （数据其实早已入库，只是被中间层挡住了）。

> **不要写** `location = /collect.php { try_files $uri =404; }`。`location =` 属精确匹配、优先级高于正则，
> 会把 `/collect.php` 当静态文件直出：POST 一律 **405 Method Not Allowed**，GET 变成下载 PHP 源码。
> 详见 [6.x 故障排查](#六故障排查)。

> **未安装自动跳转**：`location /` 交给 `index.php` 后，未安装时访问首页/任意前端路由都会被 **302 到 `/install/`**；
> 已安装后同一规则输出 `index.html`（SPA 回退）。若你坚持用 `try_files $uri $uri/ /index.html`，
> 跳转由前端兜底完成（探测 `/api/health` 的 503 响应）—— 建议还是按上面写法，服务端跳转更稳。

**宝塔面板操作**：网站 → 添加站点（**网站目录填包根**，例如 `/www/wwwroot/webstats`；**运行目录选 `/public`**）
→ 设置 → 伪静态粘贴上述规则（或直接选「自定义」）→ PHP 版本选 8.1/8.2 → 保存。

**关于宝塔「防跨站攻击 (open_basedir)」**：默认会把 PHP 可访问范围限制为「站点目录 : /tmp」，这是安全设置，
**保留即可**（安装器已做越界保护，不会再输出 open_basedir 警告）。注意**运行目录不影响 open_basedir**：
站点目录填包根，`app/` `data/` `scripts/` 就都在允许范围内，安装与运行都正常。
目录结构确实特殊时，用安装向导第一步的「手动指路」或 `public/install/local.php` 指定路径，**不要**为此关闭防跨站。

### 3.2 Apache

把 `deploy/apache.htaccess.sample` 复制为 `public/.htaccess`，确保 `mod_rewrite` 开启、`AllowOverride All`。

### 3.3 目录权限

```bash
chown -R www:www /www/wwwroot/webstats/data
chmod 755 /www/wwwroot/webstats/data
```

安装向导需要写 `data/`（生成 `installed.php` 与 `install.lock`，以及在线更新的 `backup/`）。
在线更新会覆盖 `app/` `public/` `scripts/` 下的程序文件，因此**整个包根目录都建议给 Web 进程写权限**
（宝塔默认 `www:www` 且目录归 `www` 所有时通常已满足）；只想手改代码不想自动升级的话，
可在 `config.php` 里设 `update.enabled = false`（或环境变量 `WSTAT_UPDATE_OFF=1`）。

---

## 四、必须启动的后台进程（关键）

统计链路是：**采集端 → Redis 队列 → worker 落库**。没有常驻 worker，`events` 明细不会入库、
`sessions`（会话/停留时长/跳出）与实时数据永远为空。

### 4.1 worker 常驻

```bash
cd /www/wwwroot/webstats
nohup php scripts/worker.php >> data/worker.log 2>&1 &
```

- 宝塔：「软件商店 → 进程守护管理器 / Supervisor」添加进程，启动命令
  `php /www/wwwroot/webstats/scripts/worker.php`，目录填项目根；
- systemd 示例（`/etc/systemd/system/wstat-worker.service`）：

```ini
[Unit]
Description=WebStats queue worker
After=network.target redis.service mysql.service

[Service]
Type=simple
User=www
WorkingDirectory=/www/wwwroot/webstats
ExecStart=/usr/bin/php /www/wwwroot/webstats/scripts/worker.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

> ⚠️ **worker 必须从项目根目录启动，且代码更新后必须重启进程**（PHP 常驻进程不会热加载代码）。
> 若机器上存在多个历史版本的 worker 进程，会出现「PV 计数器增长了、但明细/会话没有」的错乱现象：
> 旧进程会按旧逻辑吞掉事件。排查方式见第七章。

worker 的异常行为约定（便于 supervisor / 日志告警识别）：

| 日志 | 含义 | 处理 |
| --- | --- | --- |
| `[worker] Redis unavailable, retry in 3s...` | Redis 连不上（含密码错误/NOAUTH） | 3 秒后自动重连，会自愈；持续刷则修 Redis |
| `[worker] reaped closed=N offline=M` | 正常心跳：回收空闲会话 + 清理在线僵尸（仅在有回收时打印） | 无需处理 |
| `[worker] Redis error: ...retry in 3s...` | 临时性 Redis 异常（超时/断连） | 会自愈；持续刷则查网络与 Redis 负载 |
| `[FATAL] Redis 键类型错误（WRONGTYPE）` + 诊断块 | **键类型被污染**，重试无法自愈 | 按诊断块给出的 RENAME 命令处理；或启动加 `--quarantine` 自动隔离。详见第七章 |

### 4.2 定时任务（crontab）

```cron
* * * * *   php /www/wwwroot/webstats/scripts/cron.php session     # 回收空闲会话
0 * * * *   php /www/wwwroot/webstats/scripts/cron.php rollup      # 生成每日汇总 site_daily
0 3 * * *   php /www/wwwroot/webstats/scripts/cron.php partition   # 预建未来分区（避免写入超界）
0 4 * * *   php /www/wwwroot/webstats/scripts/cron.php clean       # 清理过期明细（按分区 DROP）
*/5 * * * * php /www/wwwroot/webstats/scripts/cron.php alert       # 流量异常告警判定（5 分钟一次）
0 * * * *   php /www/wwwroot/webstats/scripts/cron.php report      # 日报推送（按订阅设定的整点发送）
```

> `alert` 与 `report` 建议至少每 5 分钟 / 每小时各跑一次。两者都是**幂等**的：
> `alert` 依 `cooldown_min` 冷却，`report` 依 `last_sent_day` 去重，重复执行不会刷屏。
> 未配置任何规则/订阅时，两条命令只做一次空查询即退出，可安全常驻。

宝塔：计划任务 → 添加（Shell 脚本，周期如上）。

---

## 五、站点接入（埋点）

1. 登录管理端 → 站点管理 → 新建站点 → 填写域名；
2. 按提示在站点根目录放置验证文件完成「文件验证」（未验证站点默认拒绝采集）；
3. 把下面代码粘贴到被统计网站的 `<head>` 中（`data-site-key` 换成后台生成的 key）：

```html
<script async src="https://stats.example.com/sdk/wstat.js"
        data-site-key="YOUR_SITE_KEY"
        data-host="https://stats.example.com"></script>
```

> ⚠️ `src` 与 `data-host` 必须都指向**统计服务地址**，绝不能指向前端开发服务器（如 `localhost:5173`），
> 否则线上永远采集不到数据。
> 采集端点也支持跨域，无需额外 CORS 配置。

---

## 六、升级与迁移

### 6.1 升级

升级服务地址：**https://wstats-update.kfw.cc/update.php**（面板「系统设置 → 关于与更新」会自动检查；
`config.php` 的 `update_check_url` 可改指向自建服务，环境变量 `WSTAT_UPDATE_URL` 可覆盖）。

#### 方式一：面板「一键升级」（推荐）

系统设置 → 「关于与更新」→ 检查更新 → **一键升级**。服务端会：

1. 从更新服务取回**增量包**（只含变化的程序文件 + `update.json` 清单 + 新增的 `sql/upgrade-*.sql`）；
2. 逐条校验包内每个文件的 sha256，任一条不符即**整体中止**（不会出现半个新版本）；
3. 把将被覆盖/删除的文件备份到 `data/backup/<新版本>-<时间>/`（含 `rollback.json`）；
4. 覆盖 `app/` `public/` `scripts/` `sql/` 等程序文件 → 执行新增的增量 SQL → 把本次升级写进 `data/version.json`
   （版本号本身**随代码走**：`app/version.php`（明文版本号）被覆盖即自动生效，
   不再需要也不应该去改 `data/installed.php` 的 `version`）；
5. 旧版本备份默认保留最近 3 份（`config.php` 的 `update.keep_backup`）。

**一键升级永不触碰** `data/` 与 `config.php`；若你改过 `config.php`（与基线哈希不一致），
新版本的默认配置会落在 `config.php.new`，需要你自己对比合并。

> ⚠️ 升级后请**重启 worker 进程**（PHP 常驻进程不会热加载代码），并刷新面板页面。
> 回滚：把 `data/backup/<目录>/` 下的文件按相同相对路径复制回包根即可。

#### 方式二：手工升级

1. **备份**：数据库 + `data/installed.php`；
2. 解压新包，把 `app/` `public/` `scripts/` `sql/` `deploy/` `docs/` `config.php` 覆盖过去
   （**保留** `data/`；改过 `config.php` 的话先备份再决定是否覆盖）；
   注意包根 `sdk/` 已不再分发（对外提供的一直是 `public/sdk/wstat.js`），可顺手删掉旧结构里的 `sdk/`；
3. 执行增量 SQL（如有）：`mysql -u... -p 库名 < sql/upgrade-xxxx.sql`；
4. **不用改任何版本号**：生效版本号取自随包覆盖的 `app/version.php`（明文版本号，见第 8.11 节），
   覆盖完程序文件就自动跟上了。`data/installed.php` 里的 `'version'` 只是「安装当刻的快照」，
   合并配置时被显式忽略 —— v1.0.3 起它既不是判定依据，也不必手工同步
   （旧文档曾要求自行 `sed` 改它，那一步已删除；改与不改都不影响生效版本）；
5. **重启 worker 进程**（否则仍跑旧代码）；
6. 发布包内的 SDK 已自动混淆，无需手工同步；自建 SDK 时两处副本
   （仓库根 `sdk/wstat.js` → `public/sdk/wstat.js`，以及 `frontend/sdk/wstat.js`）保持一致并清 CDN 缓存。

### 6.2 迁移

新机器按第二章安装 → 导入数据库备份 → 把 `installed.php` 里的库名/密码改对 → 启动 worker 与 cron。

---

## 七、运维排查

| 现象 | 定位与处理 |
| --- | --- |
| 后台无数据/接口 503 | 未安装或配置缺失：访问 `/install/`（或直接访问首页会自动跳转）；或检查 `data/installed.php` |
| 访问 `/install/` 报 `open_basedir restriction` 或「无法定位项目根目录」 | 安装器与后端不在同一目录树内。按 2.0 节核对「网站目录 / 运行目录」，或用向导第一步的「手动指路」生成 `public/install/local.php`（等价于 `php public/install/cli.php --check --root=/www/wwwroot/xxx`）。**不要**为此关闭宝塔防跨站 |
| 站点有访问但无数据 | ① 站点未验证（采集端返回 403）；② 接入代码指向了开发服务器；③ worker 未运行 |
| PV 计数器有增长，但明细分页/会话为空 | Redis 队列有旧版 worker 进程在消费旧逻辑：`netstat -ano \| grep 6379` 找连接，`ps aux \| grep worker` 核对路径与启动时间，杀掉旧进程后从项目根重启；PHP 常驻进程不热加载代码 |
| 实时/在线数/停留时长长期为空 | Redis 不可用导致降级，或 worker 未运行；执行 `php scripts/doctor.php` 查看链路各环节 |
| 采集接口 403 site unverified | 完成站点文件验证；本地联调可临时设环境变量 `WSTAT_ALLOW_UNVERIFIED=1` |
| **`POST /collect.php` 返回 405 Method Not Allowed** | nginx 把采集端点当**静态文件**直出了（本系统代码不会返回 405，任何 405 都出在 Web 服务器层）。两种自检：<br>① `curl -I https://统计域名/collect.php` → 若 `Content-Type` 是 `application/octet-stream`/`text/plain` 而非 `application/json`，说明返回的是 PHP 源码，nginx 没交给 PHP-FPM；<br>② 响应头无 `X-Powered-By`、状态 405 且带 `Allow:` 头 → nginx 静态模块拒绝 POST。<br>**根因**：站点配置里存在精确匹配块 `location = /collect.php { try_files $uri =404; }`（`location =` 优先级高于 `location ~ \.php$`，且块内没有 fastcgi 处理器）。<br>**修复**：删除该 `location = /collect.php` 块（交给默认的 `location ~ [^/]\.php(/|$)` 处理）；如确需显式声明，块内必须写 `fastcgi_pass` + `SCRIPT_FILENAME`，只写 `try_files` 一律 405。改完 `nginx -t && nginx -s reload`。<br>**备用通道**：`POST /api/collect`（走 index.php，参数与 `/collect.php` 完全一致），用于 `.php` 路径被 CDN/WAF 拦截的环境 |
| `GET /collect.php` 下载了文件 / 直接看到 PHP 源码 | 同上：nginx 未把 `.php` 交给 PHP-FPM（PHP 版本为「静态」或 php location 未生效）。宝塔：网站设置 → PHP 版本选 8.x 并保存 |
| 地理信息全空 | `data/ip2region.xdb`（IPv4）/ `data/ip2region_v6.xdb`（IPv6）缺失，或 `collect.geo_driver` 配置为 none。两族互相独立：只有 IPv6 访客没地域就是缺 v6 库，跑 `php scripts/fetch-geo.php` 补齐 |
| **worker 日志刷 `WRONGTYPE Operation against a key holding the wrong kind of value`** | **某个 Redis 键被写成了别的数据结构**（不是网络抖动，重试永远不会自愈）。<br>**含义**：worker 循环里的 `BRPOP wstat:queue`（或会话化阶段的 `HGETALL ssn:*`）撞上了一个类型不符的键，Redis 直接拒绝执行。<br>**常见根因**：① 同一个 Redis 库被**别的程序/站点共用**且键名冲突（`config.php` 的 `redis.prefix` 为空时键名就是裸 `queue`，最易撞车）；② 曾跑过键结构不同的**旧版本**（如哈希队列）后残留；③ 人工用 `redis-cli` / 面板工具往同名键写过值。<br>**定位**：`php scripts/doctor.php` → 第 [3] 节会打印**实际类型 / 期望类型 / 坏键清单**与逐键修复命令（也可以看 worker 自己打印的诊断块，含键名、类型、元素数、内容预览）。手工核对：`redis-cli -n 0 TYPE wstat:queue`（期望 `list`）。<br>**修复**：先备份再重建，不要直接删——<br>`redis-cli -n 0 RENAME wstat:queue wstat:queue:bad_$(date +%Y%m%d%H%M%S)`<br>确认与其它程序无关后也可 `DEL`。随后重启 worker。急用时可直接 `php scripts/worker.php --quarantine`（自动把坏键 RENAME 备份后继续消费）。<br>**已有防护**：采集端遇到该错误会**自动降级直写 MySQL**（不再 500 丢点）；worker 遇到队列键类型错误会打印诊断并**快速退出**（不再 3 秒一轮刷屏假死）。<br>**彻底避免**：把 `config.php` 的 `redis.prefix` 改为站点专属前缀（如 `wstat_xxx:`）并保持各环境一致，避免与同库其它应用撞键 |
| 采集接口偶发 500 | 看 PHP 错误日志：若含 `Redis error`，多为键类型污染（见上条）或 Redis 连接超时；已实现捕获降级，正常应只记一条 `[wstat] collect 热路径 ... 已降级直写 MySQL` 日志且接口仍返回 200 |
| 写入报错 no partition | 分区未覆盖当前日期：执行 `php scripts/cron.php partition`（应加入 crontab） |
| 一键自检 | `php scripts/doctor.php`（数据链路）/ `php scripts/selfcheck.php`（组件自检） |

日志位置：PHP-FPM 错误日志（宝塔：网站日志）、`data/worker.log`、`data/worker-err.log`（按 §4.1 的重定向路径）。

**健康检查接口**：`GET /api/health` —— 已安装返回 `{"code":0,"data":{"installed":true,"version":"..."}}`；
未安装返回 `503 + install_url`（前端据此跳转安装向导）。可用于探活与部署流水线校验。

---

## 八、导出 · 下钻 · 告警日报 · 多用户协作

### 8.1 数据导出（CSV）

管理端「概览」「来源分析」「页面分析」「会话明细」右上角的**导出**按钮；多项时以下拉菜单呈现。

| kind | 内容 |
| --- | --- |
| `daily` | 按日 PV / UV / 独立 IP / 访问次数（流量趋势，即 ① 中的 PV·UV） |
| `channels` | 来源渠道构成（① 中的「来源」） |
| `referrers` | 外链来源（referrer 主机名） |
| `pages` | 页面路径（① 中的「页面」） |
| `entries` / `exits` | 入口页 / 退出页 |
| `sessions` | 会话明细（支持 q / browser / os / device / source 筛选，① 中的「会话」） |
| `geo` | 国家 / 省份 / 城市 |
| `devices` / `browsers` / `oses` / `langs` | 环境维度 |

- **口径**：一律直查 `events` / `sessions` 现算，不依赖 `site_daily` 预聚合，因此导出数字与页面、
  「会话明细」口径一致，rollup 未跑也不会缺行。
- **编码**：输出带 UTF-8 BOM，Excel 双击不乱码；`Content-Disposition` 同时提供 ASCII 文件名与
  `filename*`（中文名），老浏览器与新浏览器都能正确命名。
- **上限**：单次导出 5 万行、分组榜单 5000 行（超出截断，属预期保护）。
- **权限**：**只读成员（viewer）也可导出**其可见站点的数据。
- 直连（脚本/Airflow 等）：`GET /api/export/{kind}?site_id=&start=&end=`，需 `Authorization: Bearer <token>`；
  管理端前端走 `fetch` + Bearer，**token 不进入 URL**，避免落到访问日志。

### 8.2 单页面（URL）下钻

- **入口**：页面分析列表中**点击任意一行**即可下钻；也可直接访问 `/#/page?url=<编码后的地址>&match=prefix`。
- **内容**：汇总卡（PV/UV/独立 IP/会话数/入口次数/退出次数/平均停留/跳出率）→ PV·UV 按天趋势 → 24h 分时柱图 →
  上一页/下一页流向（可继续点击下钻）→ 来源·环境·地域 / 页面自定义事件 / 最近会话。
- **匹配口径**：`exact`（精确，默认）或 `prefix`（前缀）；两者同时作用于「完整 URL」与「URL 路径」表达式，
  兼容 SDK 上报完整 URL 与只存路径两种情形。
- **大站点保护**：流向分析与会话侧指标采用采样上限（5 万 PV / 2 万会话），触发时接口返回 `partial=true`，
  页面顶部出现黄色提示条，说明这两组数字为采样近似值，其余指标仍为精确值。

### 8.3 流量异常告警与日报推送

**规则维度**：

| 维度 | 取值 |
| --- | --- |
| 指标 metric | `pv` / `uv` / `visits`（会话数）/ `bounce_rate` / `avg_duration` / `online`（实时在线） |
| 窗口 window_min | 统计最近 N 分钟 |
| 比较 compare | `ratio_up` / `ratio_down`（涨跌百分比）、`abs_over` / `abs_under`（绝对值高低）、`zero`（掉零） |
| 基线 baseline | `prev_day`（昨日同时段）、`prev_window`（上一个等长窗口） |
| 阈值 threshold | 百分比或绝对数值（按比较方式解释） |
| 防误报 | `min_sample` 基线样本下限（低于则跳过，专治夜间小流量误报）；`cooldown_min` 冷却时间（避免刷屏） |
| 级别 level | `info` / `warning` / `critical`（体现在推送标题前缀与历史列表） |

**推送渠道 7 类**：`webhook`、`feishu`（飞书）、`dingtalk`（钉钉，支持加签）、`wecom`（企业微信）、
`serverchan`（Server 酱）、`bark`、`email`。

> 所有推送正文均以关键词 **WebStats** 开头，便于通过飞书/钉钉/企微机器人的「自定义关键词」安全设置；
> 若机器人已按关键词过滤，请把该关键词设为 `WebStats`。
>
> 渠道密钥在列表接口中以 `******` 掩码返回；编辑时**留空或保持掩码不变**即沿用已存密钥，不会误清空。
> 每个渠道都提供「发送测试」，保存前可先验证连通性。

**日报推送**：按订阅设定（频率：每日 / 每周指定星期；发送整点 `hour`；站点范围：指定站点或全部）聚合
PV/UV、Top 页面、Top 来源等生成正文并推送。`last_sent_day` 做幂等，cron 每小时跑也不会重复发送。

**留痕**：每次触发都写入 `alert_logs`（含指标值、基线值、判定结果、各渠道推送状态），
管理端「告警历史」可按级别/站点分页筛选。

**依赖 cron**（见 4.2）：`cron.php alert` 建议每 5 分钟、`cron.php report` 每小时。

**权限**：推送渠道 / 告警规则 / 日报订阅都是**按用户**的个人配置；规则绑定站点时要求对该站点
至少 **editor**（所有者与协作者可配，只读成员不可）。站点成员被移除后，其针对该站点的规则与订阅
会自动停止推送，避免数据继续外泄。

### 8.4 站点成员与多用户只读权限

**角色三级**（左低右高）：

| 角色 | 能力 |
| --- | --- |
| `viewer` 只读 | 查看该站点全部报表、导出 CSV；任何写操作返回 403 |
| `editor` 可编辑 | 以上 + 新建/修改/删除该站点的漏斗、配置该站点的告警规则 |
| `owner` 所有者 | 以上 + 修改/删除站点、生成与校验验证文件、管理成员 |

- **站点所有者天然是 owner**，无需在成员表中插入自己；
- **管理入口**：站点管理 → 选中站点 → 「成员」；可添加（按注册邮箱）、改角色、移除；
- **被授权用户**登录后站点切换器会出现该站点，当前角色以标签显示（只读=黄色、可编辑/所有者=蓝色）；
- **越权口径**：无权访问的站点统一返回 **404**（不泄露「该站点是否存在」）；权限不足返回 **403**；
- **敏感字段**：站点列表接口对非 owner 的条目**清空 `verify_token`**，避免验证令牌外泄；
- **不开放「再授 owner」**：成员只能被授予 viewer/editor，以免权限扩散；转移所有权请走站点归属变更。

### 8.5 系统设置（管理员）：邮件 SMTP / 功能开关 / 注册开关

仅**系统管理员**可见（左侧「系统设置」，或 `GET/PATCH /api/settings`）。

- **管理员判定**：`users.is_admin=1`。老库未跑增量时自动回退「首个注册账号（最小 id）为管理员」。
- **功能开关**（即时生效）：
  - **开放注册**：关闭后登录页隐藏注册入口、注册页禁用提交，直接调 `POST /api/auth/register` 返回 403；
  - **注册邮箱验证码**（默认开）：开启后注册改为两步 —— 先输入**图片验证码**点「获取验证码」，
    邮箱收到 6 位验证码（10 分钟有效、60 秒可重发、每邮箱每小时 ≤5 封、每 IP 每小时 ≤20 封），
    再凭验证码提交注册。图片验证码由 GD 生成（`GET /api/auth/captcha`），**缺 GD 扩展时该开关等于锁死注册**，
    设置页与注册页都会给出红色提示；存储在 `data/verify/`（文件型，单机部署）。
  - **采集上报**：关闭后全部站点的 SDK 上报被拒（`/api/collect` 返回 403），已有数据仍可查看；
  - **告警与日报推送**：关闭后 `cron.php alert` / `cron.php report` 直接跳过（规则与订阅保留）。
  - **爬虫过滤**（默认开）：命中爬虫 / HTTP 客户端 UA 的采集请求静默丢弃（返回 200 而非 403，
    避免爬虫对 4xx 重试放大流量）；关闭后爬虫会计入普通访客口径。
  - **蜘蛛爬虫统计**（默认开，v1.0.13 新增）：关闭后服务端上报端点 `/spider.php` 与采集端
    都不再写 `spider_hits`（见 §8.19）。它与上一条**职责分离、互不影响** ——
    「爬虫过滤」管*爬虫算不算访客*，「蜘蛛统计」管*要不要记蜘蛛账*；
    任一开关都不会让爬虫数据混进 PV/UV/会话。
- **事件明细保留天数**（`retention_days`，v1.0.13 新增可保存）：留空 = 跟随 `config.php` 的
  `collect.event_retention`；非空必须是 **1–3650** 的整数（`cron.php clean` 直接拿它算分区删除边界）。
  非法值返回 422 且**整批不落库**（不会留下「开关已改、天数没改」的半保存状态）。
- **保存入口**：「功能开关」卡片自带保存按钮（v1.0.13 前必须滚到下方「接入代码」卡片才有保存按钮，
  容易被当成「改了不保存」）。**开发约定**：`Settings::KEYS` / `DEFAULTS` 与前端 `save()` 里出现的键，
  必须在 `SettingController::update()` 有对应写入分支 —— 只在白名单里登记、忘了写分支时，
  服务端会静默丢弃该键（其余键照常落库 → 提示「保存成功」是真的，刷新后该项变回旧值）。
- **邮件发送（SMTP）**：配置后，「告警渠道-邮件」「日报订阅邮件」「发送测试邮件」与**注册/重置邮箱验证码**统一走 SMTP
  （支持 SSL(465) / STARTTLS(587) / 明文(25)，AUTH LOGIN）。**未填 SMTP 服务器时回退 PHP mail()**（需主机 MTA）。
  SMTP 密码接口掩码回显（`******`），保存时留空或保持掩码即沿用旧值。
- **公共开关接口**：`GET /api/auth/public`（无需登录）返回
  `{registration_enabled, email_verify_enabled, captcha_ready}`，供登录/注册页联动。

#### 忘记密码（自助重置）

登录页「忘记密码？」→ `/forgot`，也即三条公开接口（均无需登录）：

| 接口 | 说明 |
|---|---|
| `GET /api/auth/captcha` | 图片验证码（与注册共用；一次性，校验后即作废） |
| `POST /api/auth/reset-code` | `{email, captcha_id, captcha, lang?}` → 发送重置验证码 |
| `POST /api/auth/reset-password` | `{email, code, password}` → 重置密码 |

- **邮箱必须已注册**（否则 404「该邮箱未注册」），账号被禁用时 403；
- 邮箱验证码**恒定启用**，不受「注册邮箱验证码」开关影响 —— 注册可以关闭，已有账号必须能自助找回；
- **不看 `registration_enabled`**：注册关闭时重置流程照常可用；
- 重置验证码与注册验证码**存储键互不通用**（`mailreset:` / `mailcode:`），注册码不能拿来改密码（反之亦然）；
  冷却与小时配额（每邮箱 5 封/小时、每 IP 20 封/小时、60 秒重发）两类用途**共用**，防止换用途绕开限流；
- 重置成功后**吊销该账号全部登录令牌**（其它设备上的旧会话立即失效），返回 `{ok:true, revoked:N}`；
- **缺 GD 扩展时**取码不再强制图片验证码（退化为仅邮件配额限流），`/forgot` 页会给出黄色提示；
- 前置限流顺序：图码 → 邮箱存在性 → 发信，前两步失败都不消耗邮件额度。
