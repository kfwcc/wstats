本目录为运行期数据目录（Web 服务器层面应禁止访问）：
  installed.php      安装向导生成的部署配置（含数据库/Redis 凭据）
  install.lock       安装锁，存在则表示已安装
  ip2region.xdb      离线 IP 地理库（IPv4）
  ip2region_v6.xdb   离线 IP 地理库（IPv6）
  verify/            图形/邮箱验证码临时文件
  backup/            在线更新前的自动备份
请确保 Web 服务进程对本目录有写权限。
