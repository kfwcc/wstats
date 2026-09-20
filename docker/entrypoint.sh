#!/bin/sh
# WebStats 容器入口脚本
#
# 1) 修正 data/ 属主（具名卷首次挂载后可能是 root）
# 2) 可选首次自动安装：WSTAT_AUTO_INSTALL=1 且尚未安装时，用环境变量跑公共安装器
#    （等价于 php public/install/cli.php 手工安装，安装器自动写 data/installed.php 与安装锁）
# 3) 然后 exec 传入的命令（默认 apache2-foreground；worker / cron 容器各自传自己的命令）
set -eu

APP_ROOT="${WSTAT_ROOT_DIR:-/var/www/html}"
cd "$APP_ROOT"

DATA_DIR="$APP_ROOT/data"

log() { echo "[wstat-entrypoint] $*"; }

fix_data_owner() {
    mkdir -p "$DATA_DIR"
    # 安装器 / worker / cron 都要写这里；Apache 以 www-data 运行
    chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || true
}

fix_data_owner

# ---- MySQL 就绪等待（最多 120 秒）----
db_ready() {
    i=0
    while [ "$i" -lt 60 ]; do
        if DBH="$WSTAT_DB_HOST" \
           DBP="${WSTAT_DB_PORT:-3306}" \
           DBU="${WSTAT_DB_USER:-}" \
           DBPW="${WSTAT_DB_PASS:-}" \
           php -r '$dsn=sprintf("mysql:host=%s;port=%s",getenv("DBH"),getenv("DBP"));
                   try { new PDO($dsn, getenv("DBU"), getenv("DBPW"), [PDO::ATTR_TIMEOUT => 3]); exit(0); }
                   catch (Throwable $e) { exit(1); }'
        then
            return 0
        fi
        i=$((i + 1))
        if [ "$i" -eq 1 ]; then
            log "等待 MySQL ${WSTAT_DB_HOST}:${WSTAT_DB_PORT:-3306} 就绪 ..."
        fi
        sleep 2
    done
    return 1
}

if [ "${WSTAT_AUTO_INSTALL:-0}" = "1" ] && [ ! -f "$DATA_DIR/install.lock" ]; then
    if [ -z "${WSTAT_DB_HOST:-}" ]; then
        log "未设置 WSTAT_DB_HOST，跳过自动安装（可访问 /install/ 用向导安装）"
    elif [ -z "${WSTAT_ADMIN_EMAIL:-}" ] || [ -z "${WSTAT_ADMIN_PASS:-}" ]; then
        log "未设置 WSTAT_ADMIN_EMAIL / WSTAT_ADMIN_PASS，跳过自动安装（可访问 /install/ 用向导安装）"
    elif ! db_ready; then
        log "MySQL 连接超时，跳过自动安装（可稍后手动执行 install/cli.php）"
    else
        log "检测到未安装，开始自动安装 ..."

        # 建库用的账号：默认用应用账号；若该账号无建库权限，可用 WSTAT_INSTALL_DB_USER/PASS 指定 root
        INS_USER="${WSTAT_INSTALL_DB_USER:-${WSTAT_DB_USER:-}}"
        INS_PASS="${WSTAT_INSTALL_DB_PASS:-${WSTAT_DB_PASS:-}}"

        set -- --no-interactive \
            --root="$APP_ROOT" \
            --db-host="${WSTAT_DB_HOST:-127.0.0.1}" \
            --db-port="${WSTAT_DB_PORT:-3306}" \
            --db-name="${WSTAT_DB_NAME:-}" \
            --db-user="$INS_USER" \
            --db-pass="$INS_PASS" \
            --create-db

        if [ "${WSTAT_NO_REDIS:-0}" = "1" ]; then
            set -- "$@" --skip-redis
        else
            set -- "$@" \
                --redis-host="${WSTAT_REDIS_HOST:-127.0.0.1}" \
                --redis-port="${WSTAT_REDIS_PORT:-6379}" \
                --redis-db="${WSTAT_REDIS_DB:-0}"
            if [ -n "${WSTAT_REDIS_AUTH:-}" ]; then
                set -- "$@" --redis-auth="$WSTAT_REDIS_AUTH"
            fi
        fi

        set -- "$@" \
            --admin-email="$WSTAT_ADMIN_EMAIL" \
            --admin-pass="$WSTAT_ADMIN_PASS" \
            --allow-register="${WSTAT_ALLOW_REGISTER:-0}"

        if php public/install/cli.php "$@"; then
            log "自动安装完成（管理员：$WSTAT_ADMIN_EMAIL）"
            fix_data_owner
        else
            log "自动安装失败 —— 请用 Web 向导完成：http://<本机地址>/install/"
            log "或进容器手工排查：docker compose exec web php public/install/cli.php --check"
        fi
    fi
fi

# ---- worker / cron 启动前等待安装完成（安装由 web 容器负责）----
if [ "${1:-}" != "apache2-foreground" ] \
    && [ "${WSTAT_WAIT_INSTALL:-1}" = "1" ] \
    && [ ! -f "$DATA_DIR/installed.php" ] \
    && [ ! -f "$DATA_DIR/install.lock" ]; then
    log "等待 web 容器完成安装（最多 300 秒）..."
    i=0
    while [ "$i" -lt 60 ] \
        && [ ! -f "$DATA_DIR/installed.php" ] \
        && [ ! -f "$DATA_DIR/install.lock" ]; do
        i=$((i + 1))
        sleep 5
    done
    if [ ! -f "$DATA_DIR/installed.php" ] && [ ! -f "$DATA_DIR/install.lock" ]; then
        log "等待超时，按「纯环境变量部署」直接启动（请确认库表已存在）"
    fi
fi

exec "$@"
