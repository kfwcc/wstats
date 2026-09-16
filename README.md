# 网站统计系统（WebStats）

自建网站统计平台：**原生 PHP + MySQL + Redis** 后端，**React + Semi Design** 管理端，**零依赖 JS SDK** 前端采集。支持多站点、多用户（注册登录 + 文件验证）、多语言（中/EN）。

> 当前交付：概览看板（PV/UV/IP/跳出率/平均时长/活跃趋势/今日实时分时）、**来源分析**（渠道构成/趋势/外链/UTM 系列/广告平台）、
> 会话列表 + 单会话完整行为日志、注册登录、多站点管理 + 两步文件验证、SDK 安装引导（**已验证站点不可修改，仅验证后可用 SDK**）。

---

## 1. 架构总览

```
┌──────────── 被统计站点 ────────────┐      ┌────────────── WebStats 服务端 ──────────────┐
│ <script src="…/sdk/wstat.js"      │      │  入口：/collect.php（采集）                  │
│        data-site-key="xxx">       │  ──► │      ├ 站点校验(Redis缓存)                  │
│  · Cookie: uid(2y)/sid(30min滑动) │      │      ├ Redis 实时计数(PV/在线/分时)         │
│  · UTM 五参 + ClickID 首触留存     │      │      └ 事件 JSON → Redis 队列                │
│  · Web Vitals(采集)                │      │                    │                        │
│  · SPA 路由自动上报 / sendBeacon   │      │  常驻 worker(批量100/条) ──► events 明细表   │
└────────────────────────────────────┘      │        · 会话聚合(Redis ssn hash)          │
                                            │        · 空闲30min → sessions 表           │
                                            │ cron: session/rollup/partition/clean       │
                                            │        ↓ site_daily 日汇总                  │
                                            │ 管理API：/api/*（token 认证，多用户多站点） │
                                            │      └── React + Semi Design 前端          │
                                            └──────────────────────────────────────────┘
```

**高流量瓶颈对策（核心设计）**
| 瓶颈 | 对策 |
|---|---|
| 埋点写放大 | 采集端只写 Redis（毫秒级）；worker 每 100 行/条 SQL 批量写库，DB 峰值写量降 2 个数量级 |
| events 表膨胀 | 按月 `RANGE(TO_DAYS(day))` 分区，`cron partition` 自动预建分区，`cron clean` 直接 DROP 过期分区 |
| 明细读报表慢 | `cron rollup` 每5分钟刷新 `site_daily` 汇总，概览读汇总表（缺失天自动回填） |
| 高并发唯一统计 | UV/IP 走事件明细**精确去重**（一次扫描同时取 UV 与独立 IP，口径全站统一）；Redis 只承担 PV/在线/进行中会话等精确实时计数 |
| Redis 故障 | 采集端自动降级直写 MySQL（不丢点，会话退化为单页近似），恢复后自动切回 |
| Redis 无扩展 | 自带纯 PHP RESP 协议客户端（`Support/RedisClient`），无需 phpredis/Predis |

## 2. 目录结构

发布包布局（v1.0.1 起统一，不再区分普通版 / 宝塔版）：**包根 = 站点目录，`public/` 为网站运行目录**。

```
<包根>/
├── public/                   # ← Web 根（运行目录）：只有这个目录能被访问
│   ├── index.php             # 入口 + 管理 API（未安装时自动 302 → /install/）
│   ├── collect.php           # 采集入口
│   ├── index.html  assets/   # 前端产物（SPA）
│   ├── geo/                  # 地图数据（世界 / 中国）
│   ├── sdk/wstat.js          # 对外提供的采集 SDK
│   └── install/              # 安装向导（Web 四步向导 + CLI + 自带 install.sql），装完请删除
├── app/                      # 后端代码（无 Composer 依赖）
│   ├── bootstrap.php         # 自动加载 / 全局异常 / 未安装守卫
│   ├── Http/                 # Router / Request
│   ├── Support/              # Db / RedisClient(RESP) / Rds / Auth / Util / Settings / Updater …
│   └── Controllers/          # Auth / Site / Stats / Collect / Setting / Alert / Export / Open
├── config.php                # 出厂默认配置（凭据在 data/installed.php，WSTAT_* 环境变量优先级最高）
├── scripts/                  # worker.php（常驻消费）· cron.php（定时）· doctor.php / geo-doctor.php（诊断）
│                             # · selfcheck.php（离线自检）· fetch-geo.php（更新 IP 库）
├── data/                     # 运行期数据：installed.php / install.lock / ip2region.xdb + ip2region_v6.xdb / verify/ / backup/
├── deploy/                   # Nginx / Apache 配置样例
├── docs/deploy.md            # 部署与运维手册（含排查速查表）
└── README.md  RELEASE.md  CHANGELOG.md
```

`app/ scripts/ data/ sql/ config.php` 全在 Web 根之外 → **天然不可直访，无需任何屏蔽规则**，
`data/installed.php`（含库 / Redis 凭据）也不可能被下载。

> 完整部署手册见 [`docs/deploy.md`](docs/deploy.md)。

## 3. 部署（安装向导）

### 3.1 上传与目录权限
把发布包解压到服务器（如 `/www/wwwroot/webstats`），**站点目录 = 包根、运行目录 = `/public`**
（宝塔：网站 → 设置 → 网站目录选包根，运行目录选 `/public`；自建 Nginx：`root <包根>/public;`），
并确保 PHP 进程可写 `data/`：

```bash
chown -R www:www /www/wwwroot/webstats/data
```

**环境要求**：PHP ≥ 7.4（推荐 8.1/8.2，扩展 `pdo_mysql`/`json`/`mbstring`；**无需 redis 扩展**）、
MySQL ≥ 5.7、Redis（可选，缺失自动降级）、Node 18+（仅自行构建前端时需要）。

### 3.2 安装（Web 向导 / 命令行二选一）

**A. Web 向导（推荐）**：访问 `http://你的域名/install/`，四步完成 —— 环境检测 → 数据库与 Redis（自动实测连接）→ 创建管理员 → 完成页给出后续命令与站点接入代码。向导会写入 `data/installed.php` 并加安装锁，防止被他人重装。**安装后请删除 `public/install` 目录。**

**B. 命令行（SSH / 自动化）**：

```bash
php public/install/cli.php --check                     # 环境自检
php public/install/cli.php \                           # 一键安装（不带参数则为交互问答）
  --db-name=webstats --db-user=webstats --db-pass='密码' --create-db \
  --redis-host=127.0.0.1 --redis-port=6379 --redis-auth='密码' \
  --admin-email=admin@example.com --admin-pass='Admin@12345'
php public/install/cli.php --status                    # 查看安装状态
php public/install/cli.php --unlock                    # 解除安装锁（重装/迁移用）
```

> 配置优先级：`config.php` 默认值 ← `data/installed.php`（向导生成）← `WSTAT_*` 环境变量。
> 因此也支持「不写配置文件、纯环境变量部署」（容器/K8s 场景）：
> `WSTAT_DB_HOST/WSTAT_DB_PORT/WSTAT_DB_NAME/WSTAT_DB_USER/WSTAT_DB_PASS`、
> `WSTAT_REDIS_HOST/WSTAT_REDIS_PORT/WSTAT_REDIS_AUTH/WSTAT_REDIS_DB`、`WSTAT_DEBUG=0`。

### 3.3 启动后台进程（必须）

```bash
cd /www/wwwroot/webstats
nohup php scripts/worker.php >> data/worker.log 2>&1 &   # 常驻，建议 supervisor/宝塔守护
# crontab（在项目根目录下执行）:
# * * * * *   php {path}/scripts/cron.php session      # 回收空闲会话
# 0 * * * *   php {path}/scripts/cron.php rollup       # 生成 site_daily
# 0 3 * * *   php {path}/scripts/cron.php partition    # 预建未来分区
# 0 4 * * *   php {path}/scripts/cron.php clean        # 清理过期明细
```

> ⚠️ 没有 worker：`events` 明细不入库、会话/停留时长/实时数据永远为空。
> ⚠️ 更新代码后必须**重启 worker**（PHP 常驻进程不会热加载）；请从项目根目录启动，避免误加载其它副本的旧代码。

### 3.4 Nginx 站点配置（管理端）

完整样例见 [`deploy/nginx.conf.sample`](deploy/nginx.conf.sample)（Apache 用户见 `deploy/apache.htaccess.sample`）：

```nginx
root {包根}/public;                                # ← 关键：根目录指向包根下的 public
index index.php index.html;                        # 须含 index.php（/install/ 与 SPA 回退都靠它）

location / {                                       # SPA 前端 + 未安装 302 /install/
    try_files $uri $uri/ /index.php$is_args$args;
}
location ^~ /api/ {
    try_files $uri /index.php$is_args$args;        # 管理 API
}
# 采集入口 /collect.php 不要单独写 location：POST 会被当静态文件拦成 405，
# 交给 PHP-FPM 处理即可（宝塔站点配置里已自带 location ~ [^/]\.php(/|$) 块）。
location /sdk/ { try_files $uri =404; }            # SDK 静态直出
# 安装完成后建议：location ^~ /install/ { deny all; }
```
> `app/ scripts/ data/ sql/ config.php` 都在 Web 根之外，**不需要任何 deny 规则**。
> 对外提供的 SDK 就是 `public/sdk/wstat.js`（源码改完后重新打包即可，无需手工同步）。
> 站点安装代码中的 `data-host` 填本系统域名（如 `https://stats.your.com`）。
> 若安装了统计脚本但无数据，先跑 `php scripts/doctor.php` 一键诊断（常见：SDK 404、站点未验证、worker 未启动）。
>
> **events 有数据但看板为空**：概览读 `site_daily` 汇总（缺失天自动按 events 回填，接口含 0 值行兜底校正）。
> 依次执行 `php scripts/cron.php session rollup` → 刷新概览；仍无则用 doctor 的 `[4b] 数据链路快照`
> 核对：events 的 `site_id` 与所选站点一致？`day`（站点本地日 YYYY-MM-DD）在所选区间？`type='pageview'`？
> PV 正常但 visits/跳出率/时长为 0 → sessions 表为空，需 worker 常驻 + cron session 回收（Redis 可用时会话先驻留 Redis）。

## 4. 使用流程（一次闭环）
1. 注册/登录 → 2. 「站点管理」添加站点（域名仅主机名）→ 3. 文件验证：**两步** —— 打开验证弹窗自动生成 `wstat_verify_{token}.txt`（令牌落库）→ 将该文件放到站点根目录（内容为 token）→ 点击「我已上传，立即校验」（统计服务器回源拉取比对，通过后状态变绿）→ 4. **验证通过后 SDK 按钮才可用**，复制安装代码到目标站 `<head>` → 5. 「概览看板」看总量与趋势，「来源分析」看渠道构成/外链/UTM/广告，「会话列表」查看每次访问与逐条行为日志。
> **验证后站点即锁定**：不可再编辑（名称/域名/时区），如需变更删除后重建。数据采集中断排查：`php scripts/doctor.php`。

> 采集端默认拒绝未验证站点的上报（HTTP 403）。本地联调需放开时：启动环境加 `WSTAT_ALLOW_UNVERIFIED=1`。

## 5. API 一览
| 方法 | 路径 | 说明 |
|---|---|---|
| POST | /api/auth/register · login · logout | 注册 / 登录 / 退出 |
| GET | /api/auth/me | 当前用户 |
| GET/PATCH | /api/profile | 个人中心：资料查看 / 修改昵称与界面语言（邮箱为登录凭证，只读） |
| POST | /api/profile/password | 修改密码（需当前密码；成功后吊销全部旧会话并换发新令牌） |
| GET | /api/profile/logs | 我的操作日志（登录/改资料/改密 + 管理员对本账号的操作留痕） |
| GET/POST | /api/users | 用户管理（**仅管理员**）：分页列表（关键词/状态筛选、站点数、最近登录）/ 新建 |
| PATCH | /api/users/{id} | 编辑昵称/语言/管理员标记/重置密码（重置后该用户全部会话失效） |
| POST | /api/users/{id}/status | 禁用（立即踢下线）/ 启用 |
| DELETE | /api/users/{id} | 删除（不能删自己/最后一个管理员；名下有站点时拒绝） |
| GET | /api/users/{id}/sites | 查看某用户的站点（自有 owner + 被授权 editor/viewer） |
| GET/POST | /api/sites | 站点列表 / 新增 |
| GET/PATCH/DELETE | /api/sites/{id} | 详情 / 修改 / 删除（**已验证站点拒绝修改**，需删除重建） |
| GET | /api/sites/{id}/verify-file | 生成/复用验证文件（返回文件名与令牌，不联网） |
| POST | /api/sites/{id}/verify | 远端校验（服务端回源拉取验证文件比对） |
| GET | /api/stats/overview?site_id&start&end | 概览（totals/trend/live，site_daily 快路径） |
| GET | /api/stats/sources?site_id&start&end | 来源分析（渠道/外链/UTM/广告平台） |
| GET | /api/stats/sessions?site_id&page&size&q | 会话分页 |
| GET | /api/stats/sessions/{id}?site_id | 会话详情 + 行为日志 |
| GET | /api/stats/online?site_id | 实时在线访客 |
| POST | /collect.php | 采集上报（任意 Origin；**仅已验证站点**接收，未验证 403） |

响应统一 `{code,msg,data}`；认证 `Authorization: Bearer {token}`。

