# WebStats 发布包 v1.0.10

- 构建时间：2026-09-16 15:48:54
- 构建环境：PHP 8.2.20
- 安装方式：访问 https://你的域名/install/ 或执行 php public/install/cli.php

## 目录结构

包根 = 站点目录；**Web 运行目录 = `public`**。

| 路径 | 作用 |
| --- | --- |
| public/ | **Web 根目录（运行目录）**：index.php(API/入口)、collect.php(采集)、index.html + assets/(前端)、geo/、sdk/ |
| public/install/ | 安装向导（含自带 sql/install.sql 兜底），部署完成后请删除 |
| app/ | 后端代码（无 Composer 依赖；入口引导 app/bootstrap.php） |
| app/version.php | **版本号唯一来源**（明文，改版本号见下） |
| config.php | 出厂默认配置（部署凭据在 data/installed.php，环境变量 WSTAT_* 优先级最高） |
| scripts/ | worker.php（常驻消费）、cron.php（定时）、doctor.php / geo-doctor.php（诊断）、selfcheck.php（离线自检）、fetch-geo.php（更新 IP 库） |
| data/ | 运行期数据：installed.php / install.lock / ip2region.xdb + ip2region_v6.xdb（IPv4/IPv6 离线库）/ verify/ / backup/（不在 Web 根内，天然不可直访） |
| deploy/ | Nginx / Apache 配置样例 |
| docs/deploy.md | 部署与运维手册 |

## 部署三步

1. 上传本包到服务器（例如 /www/wwwroot/webstats），**站点目录=包根、运行目录=`/public`**
   （宝塔：网站 → 设置 → 网站目录 = 包根，运行目录选 `/public`；其他面板/自建：root 指向 `<包根>/public`）；
2. 访问站点首页或 `/install/`：未安装时会自动跳转安装向导，按提示填数据库、Redis、管理员账号；
3. 按安装完成页提示启动 worker 常驻进程并配置 cron，最后删除 `public/install` 目录。

> app/ 、scripts/ 、data/ 、sql/ 都在 Web 根之外，无需额外屏蔽规则；
> 目录结构特殊（面板 open_basedir 受限）时：安装向导会显示「目录结构诊断」页，
> 可直接填入项目根生成 `public/install/local.php`，或执行 `php public/install/cli.php --check`。

## 版本号怎么改（自己二次开发时）

版本号只有一处需要手改：`install/lib.php` 的 `Installer::VERSION`；改完执行
`php scripts/write-version.php` 把 `app/version.php`（一个明文版本号）同步成同一个值。
发版脚本会校验两者一致，不一致直接中止构建。
**不要**去改 `data/installed.php` 的 version —— 那只是安装快照，不参与版本判定；
也不要指望在 `config.php` 里写版本号 —— 版本号只在 `app/version.php` 这一处。

详见 `docs/deploy.md`。
