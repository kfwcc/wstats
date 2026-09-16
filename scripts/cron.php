<?php
/**
 * 定时维护脚本（建议 crontab 每1分钟~每天执行，见 sql/install.sql 底部注释）
 *
 * 用法（在项目根目录下执行；开发副本为 php server/scripts/cron.php …）：
 *   php scripts/cron.php session     # 回收超时空闲会话 + 清理离线访客
 *   php scripts/cron.php rollup      # 重建近3天 site_daily 汇总（幂等）
 *   php scripts/cron.php partition   # 预建 events 后续月份分区
 *   php scripts/cron.php clean       # 清理过期明细（按保留期删旧分区）
 *   php scripts/cron.php alert       # 评估流量异常告警规则并推送（建议每5分钟）
 *   php scripts/cron.php report      # 到点发送日报/周报（建议每小时）
 *   php scripts/cron.php session rollup   # 可叠加执行
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wstat\Support\AlertEngine;
use Wstat\Support\Db;
use Wstat\Support\Rds;
use Wstat\Support\Sessionizer;
use Wstat\Support\Settings;

$actions = array_slice($argv, 1);
if (!$actions) {
    $actions = ['session'];
}

foreach ($actions as $act) {
    if (!method_exists(Cron::class, $act)) {
        fwrite(STDERR, "[cron] unknown action: $act\n");
        exit(1);
    }
    $t0 = microtime(true);
    $msg = Cron::$act();
    fwrite(STDOUT, sprintf("[cron] %-9s %s (%.2fs)\n", $act, $msg, microtime(true) - $t0));
}
exit(0);

final class Cron
{
    /** 会话回收：关闭空闲超时的 ssn hash，并清理在线集合中的离线成员 */
    public static function session(): string
    {
        $r = Rds::get();
        if ($r === null) {
            return 'redis unavailable';
        }
        [$closed, $offline] = Sessionizer::reapIdle($r);
        return "closed=$closed offline=$offline";
    }

    /** 重建近 3 天 site_daily（幂等：先删近段再回填） */
    public static function rollup(): string
    {
        // UTC 近 3 天为删除窗口（放宽至4天覆盖 +14 时区站点本地日）
        $cutoff = gmdate('Y-m-d', time() - 4 * 86400);
        Db::execute('DELETE FROM site_daily WHERE `day` >= ?', [$cutoff]);

        // 1) 明细聚合（pv/uv/ipc），单条 SQL 覆盖全部站点
        $n1 = Db::execute(
            'INSERT INTO site_daily (site_id, `day`, pv, uv, ipc, updated_at)
             SELECT site_id, `day`,
                    SUM(type="pageview") pv,
                    COUNT(DISTINCT CASE WHEN type="pageview" THEN visitor_id END) uv,
                    COUNT(DISTINCT CASE WHEN type="pageview" THEN ip END) ipc,
                    UNIX_TIMESTAMP()
             FROM events WHERE `day` >= ?
             GROUP BY site_id, `day`',
            [$cutoff]
        );

        // 2) 会话聚合（visits/bounce/duration/new_users），按站点时区逐日回填
        $sites = Db::select('SELECT id, timezone FROM sites WHERE status=1');
        foreach ($sites as $s) {
            $tz = (string) $s['timezone'];
            $off = \Wstat\Support\Util::tzOffsetSec($tz);
            $wins = [];
            for ($i = 3; $i >= 0; $i--) {
                $d = gmdate('Y-m-d', time() + $off - $i * 86400);
                $wins[$d] = \Wstat\Support\Util::dateRangeToTs($d, $d, $tz);
            }
            foreach ($wins as $d => [$ts0, $ts1]) {
                $agg = Db::first(
                    'SELECT COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                            COALESCE(SUM(is_new),0) nw
                     FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
                    [(int) $s['id'], $ts0, $ts1]
                ) ?: ['c' => 0, 'b' => 0, 'du' => 0, 'nw' => 0];
                if ((int) $agg['c'] <= 0) {
                    continue;
                }
                Db::execute(
                    'INSERT INTO site_daily (site_id,`day`,visits,bounce,duration,new_users,updated_at)
                     VALUES (?,?,?,?,?,?,UNIX_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE visits=visits+?, bounce=bounce+?, duration=duration+?, new_users=new_users+?',
                    [
                        (int) $s['id'], $d, (int) $agg['c'], (int) $agg['b'], (int) $agg['du'], (int) $agg['nw'],
                        (int) $agg['c'], (int) $agg['b'], (int) $agg['du'], (int) $agg['nw'],
                    ]
                );
            }
        }
        return 'sites=' . count($sites) . ' events_rows=' . $n1;
    }

    /** 预建 events 后续月份分区（当月 + 未来2个月） */
    public static function partition(): string
    {
        $row = Db::first(
            "SELECT PARTITION_NAME pn, PARTITION_DESCRIPTION pd
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='events'
             ORDER BY PARTITION_ORDINAL_POSITION DESC LIMIT 1"
        );
        if ($row === null || !preg_match('/\'(\d{4}-\d{2}-\d{2})\'/', (string) $row['pd'], $m)) {
            return 'no partition or unparseable';
        }
        $maxDate = new DateTimeImmutable($m[1]);   // 当前最大分区边界
        $target = (new DateTimeImmutable('now'))->modify('+2 months');
        $targetDay = new DateTimeImmutable($target->format('Y-m-01'));
        $added = 0;
        while ($maxDate <= $targetDay) {
            $next = $maxDate->modify('+1 month');
            $name = 'p' . $next->format('Ym');
            $bound = $next->format('Y-m-d');
            Db::execute("ALTER TABLE `events` ADD PARTITION (PARTITION `$name` VALUES LESS THAN (TO_DAYS('$bound')))");
            $added++;
            $maxDate = $next;
        }
        return "added=$added max_bound={$maxDate->format('Y-m-d')}";
    }

    /** 评估流量异常告警规则（命中即推送并留痕） */
    public static function alert(): string
    {
        return AlertEngine::evaluate();
    }

    /** 到点发送日报 / 周报（按订阅的本地小时判断，当天幂等） */
    public static function report(): string
    {
        return AlertEngine::runReports();
    }

    /** 按保留期清理过期明细（直接 DROP 过期分区，最快） */
    public static function clean(): string
    {
        // 保留天数：系统设置 retention_days 优先（管理员界面可改）；空/非法时回退 config.php
        $cfgDays = (int) wstat_config('collect.event_retention');
        $s = trim(Settings::get('retention_days'));
        $retentionDays = ($s !== '' && (int) $s > 0) ? (int) $s : max(1, $cfgDays);
        $cutoff = (new DateTimeImmutable('now'))->modify('-' . $retentionDays . ' days');
        $cutoffMonth = $cutoff->format('Y-m-01');     // 早于该月的数据都删
        $rows = Db::select(
            "SELECT PARTITION_NAME pn, PARTITION_DESCRIPTION pd
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='events'
             ORDER BY PARTITION_ORDINAL_POSITION ASC"
        );
        $dropped = 0;
        foreach ($rows as $r) {
            $pd = (string) $r['pd'];
            if ($pd === '' || !preg_match('/\'(\d{4}-\d{2}-\d{2})\'/', $pd, $m)) {
                continue;
            }
            $high = new DateTimeImmutable($m[1]);
            // 只删“上边界 <= 截止月”的分区，且至少保留2个分区兜底
            if ($high->format('Y-m-d') <= $cutoffMonth && count($rows) - $dropped > 2) {
                $pn = (string) $r['pn'];
                Db::execute("ALTER TABLE `events` DROP PARTITION `$pn`");
                $dropped++;
            }
        }
        return "dropped=$dropped (retention={$retentionDays}d)";
    }
}
