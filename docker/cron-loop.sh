#!/bin/sh
# WebStats 容器内定时任务循环（替代宿主机 crontab）
#
# 与 README「3.3 启动后台进程」中的 crontab 等价：
#   session   每分钟    回收空闲会话
#   rollup    每 5 分钟 生成/刷新 site_daily 汇总
#   partition 每日 03:00 预建未来分区
#   clean     每日 04:00 清理过期明细
#
# 容器时区由 TZ 环境变量决定（默认 Asia/Shanghai），因此 03:00 / 04:00 是本地时间。
set -u

cd "${WSTAT_ROOT_DIR:-/var/www/html}"

ROLLUP_MIN="${WSTAT_CRON_ROLLUP_MIN:-5}"
PARTITION_HOUR="${WSTAT_CRON_PARTITION_HOUR:-3}"
CLEAN_HOUR="${WSTAT_CRON_CLEAN_HOUR:-4}"

PART_DATE=""
CLEAN_DATE=""

echo "[wstat-cron] 启动：session 每分钟 · rollup 每 ${ROLLUP_MIN} 分钟 · partition ${PARTITION_HOUR}:00 · clean ${CLEAN_HOUR}:00"

while true; do
    M=$(date +%-M)
    H=$(date +%-H)
    TODAY=$(date +%F)

    php scripts/cron.php session || echo "[wstat-cron] session 执行失败"

    if [ $((M % ROLLUP_MIN)) -eq 0 ]; then
        php scripts/cron.php rollup || echo "[wstat-cron] rollup 执行失败"
    fi

    if [ "$H" -eq "$PARTITION_HOUR" ] && [ "$M" -eq 0 ] && [ "$TODAY" != "$PART_DATE" ]; then
        php scripts/cron.php partition || echo "[wstat-cron] partition 执行失败"
        PART_DATE="$TODAY"
    fi

    if [ "$H" -eq "$CLEAN_HOUR" ] && [ "$M" -eq 0 ] && [ "$TODAY" != "$CLEAN_DATE" ]; then
        php scripts/cron.php clean || echo "[wstat-cron] clean 执行失败"
        CLEAN_DATE="$TODAY"
    fi

    sleep 60
done
