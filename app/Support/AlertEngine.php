<?php
/**
 * 流量异常告警引擎 + 日报/周报生成
 *
 * 由 cron 调用：
 *   php server/scripts/cron.php alert     # 评估全部启用规则（建议每 5 分钟）
 *   php server/scripts/cron.php report    # 到点发送日报/周报（建议每小时）
 *
 * 设计原则：
 *   1) 宁可少报不可乱报：比例类比较必须在基线样本量 ≥ min_sample 时才判定，
 *      避免凌晨低流量时「1 → 3」被判成暴涨 200%；
 *   2) 每条规则有冷却时间，风暴期间不会刷屏；
 *   3) 触发即留痕（alert_logs），发送失败也记录原因，前端可查历史。
 */
declare(strict_types=1);

namespace Wstat\Support;

class AlertEngine
{
    public const METRICS = ['pv', 'uv', 'visits', 'online', 'bounce_rate', 'avg_duration'];
    public const COMPARES = ['ratio_up', 'ratio_down', 'abs_over', 'abs_under', 'zero'];
    public const BASELINES = ['prev_day', 'prev_window'];
    public const LEVELS = ['info', 'warn', 'critical'];

    /** @var bool|null alert_rules 新列（recover_notify/quiet_start/quiet_end/firing）是否存在（进程内缓存，老库优雅降级） */
    private static ?bool $extColsOk = null;

    /* ==================== 告警评估 ==================== */

    /** 评估全部启用规则，返回摘要字符串 */
    public static function evaluate(): string
    {
        // 推送总开关（系统设置 → 功能开关）：关闭后告警与日报都不发送
        if (!Settings::int('alert_enabled')) {
            return 'alerts disabled by sys setting';
        }
        $rules = Db::select('SELECT * FROM alert_rules WHERE is_active=1 ORDER BY id');
        $fired = 0;
        $sent = 0;
        $failed = 0;
        $now = time();

        foreach ($rules as $rule) {
            try {
                // 站点成员被移除（或降级）后不再为其推送，避免数据泄露给已失去权限的用户
                $sid = (int) $rule['site_id'];
                if ($sid > 0 && SiteAccess::role((int) $rule['user_id'], $sid) === null) {
                    continue;
                }
                $r = self::evalRule($rule, $now);
                if ($r === null) {
                    continue;
                }
                $fired++;
                if ($r['status'] === 'sent') {
                    $sent++;
                } elseif ($r['status'] === 'failed') {
                    $failed++;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, '[alert] rule#' . (int) $rule['id'] . ' error: ' . $e->getMessage() . "\n");
            }
        }
        return 'rules=' . count($rules) . " fired=$fired sent=$sent failed=$failed";
    }

    /**
     * 评估单条规则：命中则写日志 + 推送，返回 ['status'=>...]；未命中返回 null。
     * v1.3 增强：恢复通知（触发态 → 未命中时补发一条「已恢复」）+ 静默时段（站点本地时区，支持跨零点）。
     * 老库缺新列时自动降级为旧行为（无恢复/静默）。
     */
    private static function evalRule(array $rule, int $now): ?array
    {
        $uid = (int) $rule['user_id'];
        $cooldown = max(1, (int) $rule['cooldown_min']) * 60;
        $inCooldown = (int) $rule['last_fired_at'] > 0 && $now - (int) $rule['last_fired_at'] < $cooldown;
        $ext = self::hasExtCols();
        $recoverOn = $ext && (int) ($rule['recover_notify'] ?? 0) === 1;
        $wasFiring = $ext && (int) ($rule['firing'] ?? 0) === 1;

        $metric = (string) $rule['metric'];
        if (!in_array($metric, self::METRICS, true)) {
            return null;
        }
        $sites = self::ruleSites($rule);
        if (!$sites) {
            return null;
        }

        $win = max(1, (int) $rule['window_min']) * 60;
        $ts1 = $now;
        $ts0 = $now - $win;

        $anyHit = false;    // 有站点命中（含被静默/冷却抑制的）
        $didSend = false;   // 实际执行了发送/留痕
        $statuses = [];
        foreach ($sites as $site) {
            $sid = (int) $site['id'];
            $val = self::metricValue($sid, $metric, $ts0, $ts1);

            $baseline = (string) $rule['baseline'];
            if ($baseline === 'prev_window') {
                $b0 = $ts0 - $win;
                $b1 = $ts0;
            } else {   // prev_day：昨日同时段
                $b0 = $ts0 - 86400;
                $b1 = $ts1 - 86400;
            }
            $baseVal = self::metricValue($sid, $metric, $b0, $b1);

            if (!self::hit($rule, $val, $baseVal)) {
                continue;
            }
            $anyHit = true;

            // 静默时段：命中但不推送、不留痕，静默结束后首次评估自然触发
            if ($ext && self::inQuiet($site, $rule, $now)) {
                continue;
            }
            // 冷却中：命中但不重复推送，也不留痕（不延长冷却）
            if ($inCooldown) {
                continue;
            }
            $didSend = true;

            $msg = self::message($rule, $site, $metric, $val, $baseVal, $ts0, $ts1);
            $level = in_array((string) $rule['level'], self::LEVELS, true) ? (string) $rule['level'] : 'warn';

            // 解析推送渠道：规则指定优先，否则用户全部启用渠道
            $channels = self::resolveChannels($uid, (string) $rule['channels']);
            $res = $channels
                ? Notifier::send($channels, self::subject($rule, $site, $level), $msg)
                : ['sent' => 0, 'failed' => 0, 'results' => []];

            $status = 'skipped';
            $err = '';
            if ($channels) {
                $status = $res['sent'] > 0 ? 'sent' : 'failed';
                $errs = [];
                foreach ($res['results'] as $rr) {
                    if (!$rr['ok']) {
                        $errs[] = $rr['name'] . ': ' . $rr['msg'];
                    }
                }
                $err = implode(' | ', $errs);
            } else {
                $err = '未配置推送渠道，仅记录';
            }
            $statuses[] = $status;

            Db::insert('alert_logs', [
                'rule_id' => (int) $rule['id'],
                'user_id' => $uid,
                'site_id' => $sid,
                'level' => $level,
                'metric' => $metric,
                'value' => round($val, 2),
                'baseline' => round($baseVal, 2),
                'message' => mb_substr($msg, 0, 480),
                'channel_ids' => implode(',', array_map(static fn ($c) => (int) $c['id'], $channels)),
                'status' => $status,
                'error' => mb_substr($err, 0, 240),
                'created_at' => $now,
            ]);
        }

        if ($didSend) {
            if ($ext) {
                self::markFiring((int) $rule['id'], 1);
            }
            Db::execute('UPDATE alert_rules SET last_fired_at=? WHERE id=?', [$now, (int) $rule['id']]);
            return ['status' => in_array('failed', $statuses, true) ? 'failed' : 'sent'];
        }
        if ($anyHit) {
            return null;   // 被静默/冷却抑制：保持现状，等待下一次评估
        }
        // 全部站点未命中：若此前处于触发态 → 补发恢复通知
        if ($wasFiring) {
            self::markFiring((int) $rule['id'], 0);
            if ($recoverOn) {
                return self::sendRecovery($rule, $sites, $metric, $ts0, $ts1, $now);
            }
        }
        return null;
    }

    /** 规则命中判定 */
    private static function hit(array $rule, float $val, float $base): bool
    {
        $th = (float) $rule['threshold'];
        $minSample = max(0, (int) $rule['min_sample']);
        switch ((string) $rule['compare']) {
            case 'ratio_up':
                return $base >= $minSample && $base > 0 && $val >= $base * (1 + $th / 100);
            case 'ratio_down':
                return $base >= $minSample && $base > 0 && $val <= $base * (1 - $th / 100);
            case 'abs_over':
                return $val > $th;
            case 'abs_under':
                return $val < $th;
            case 'zero':
                // 有基线活动却变成 0，才算异常（否则夜间静默会一直误报）
                return $val <= 0 && $base > 0;
            default:
                return false;
        }
    }

    /** alert_rules 新列是否可用（缺列=老库未跑增量，自动降级为旧行为） */
    private static function hasExtCols(): bool
    {
        if (self::$extColsOk === null) {
            try {
                // tableColumns() 返回键=列名，判列必须 array_key_exists
                $c = Db::tableColumns('alert_rules');
                self::$extColsOk = array_key_exists('recover_notify', $c) && array_key_exists('quiet_start', $c);
            } catch (\Throwable $e) {
                self::$extColsOk = false;
            }
        }
        return self::$extColsOk;
    }

    /** 维护规则触发态（恢复判定用） */
    private static function markFiring(int $ruleId, int $v): void
    {
        try {
            Db::execute('UPDATE alert_rules SET firing=? WHERE id=?', [$v, $ruleId]);
        } catch (\Throwable $e) {
            /* 老库缺列：忽略（hasExtCols=false 时不会走到这里） */
        }
    }

    /**
     * 静默时段判定（站点本地时区）。
     * start > end 视为跨零点窗口（如 23:00~07:00）；两者相等或留空=不启用。
     */
    private static function inQuiet(array $site, array $rule, int $now): bool
    {
        $qs = trim((string) ($rule['quiet_start'] ?? ''));
        $qe = trim((string) ($rule['quiet_end'] ?? ''));
        if ($qs === '' || $qe === '' || $qs === $qe) {
            return false;
        }
        $toMin = static function (string $s): ?int {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s, $m) !== 1) {
                return null;
            }
            return (int) $m[1] * 60 + (int) $m[2];
        };
        $a = $toMin($qs);
        $b = $toMin($qe);
        if ($a === null || $b === null) {
            return false;
        }
        $local = $now + Util::tzOffsetSec((string) $site['timezone']);
        $cur = (int) gmdate('G', $local) * 60 + (int) gmdate('i', $local);
        return $a < $b ? ($cur >= $a && $cur < $b) : ($cur >= $a || $cur < $b);
    }

    /** 恢复通知：规则从触发态回到正常时补发一条（同渠道、同样留痕，level=info） */
    private static function sendRecovery(array $rule, array $sites, string $metric, int $ts0, int $ts1, int $now): array
    {
        $uid = (int) $rule['user_id'];
        $site = $sites[0];
        $win = max(1, (int) $rule['window_min']);
        $val = self::metricValue((int) $site['id'], $metric, $ts0, $ts1);

        $title = '[恢复] ' . (string) $site['name'] . ' · ' . (string) $rule['name'];
        $msg = '站点：' . (string) $site['name'] . '（' . (string) $site['domain'] . "）\n"
            . '规则：' . (string) $rule['name'] . "\n"
            . '指标：' . self::metricLabel($metric) . "\n"
            . sprintf("窗口：%s ~ %s（近 %d 分钟）\n", date('Y-m-d H:i', $ts0), date('H:i', $ts1), $win)
            . sprintf('当前值：%s%s —— 此前触发的异常已恢复正常。', self::num($val), self::unit($metric));

        $channels = self::resolveChannels($uid, (string) $rule['channels']);
        $res = $channels ? Notifier::send($channels, $title, $msg) : ['sent' => 0, 'failed' => 0, 'results' => []];
        $status = 'skipped';
        $err = '';
        if ($channels) {
            $status = $res['sent'] > 0 ? 'sent' : 'failed';
            $errs = [];
            foreach ($res['results'] as $rr) {
                if (!$rr['ok']) {
                    $errs[] = $rr['name'] . ': ' . $rr['msg'];
                }
            }
            $err = implode(' | ', $errs);
        } else {
            $err = '未配置推送渠道，仅记录';
        }
        try {
            Db::insert('alert_logs', [
                'rule_id' => (int) $rule['id'],
                'user_id' => $uid,
                'site_id' => (int) $site['id'],
                'level' => 'info',
                'metric' => $metric,
                'value' => round($val, 2),
                'baseline' => 0,
                'message' => mb_substr('已恢复：' . $title, 0, 480),
                'channel_ids' => implode(',', array_map(static fn ($c) => (int) $c['id'], $channels)),
                'status' => $status,
                'error' => mb_substr($err, 0, 240),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            /* 留痕失败不影响恢复流程 */
        }
        return ['status' => $status];
    }

    /** 规则适用的站点列表（site_id=0 表示全部启用站点，并按用户归属过滤） */
    private static function ruleSites(array $rule): array
    {
        $uid = (int) $rule['user_id'];
        $sid = (int) $rule['site_id'];
        if ($sid > 0) {
            $s = Db::first('SELECT id,name,domain,timezone FROM sites WHERE id=? AND user_id=?', [$sid, $uid]);
            return $s ? [$s] : [];
        }
        return Db::select(
            'SELECT id,name,domain,timezone FROM sites WHERE user_id=? AND status=1 ORDER BY id',
            [$uid]
        );
    }

    /** 指标取值（ts 为服务端接收时间；带上 day 边界利于分区裁剪） */
    private static function metricValue(int $sid, string $metric, int $ts0, int $ts1): float
    {
        $d0 = gmdate('Y-m-d', $ts0 - 86400);
        $d1 = gmdate('Y-m-d', $ts1 + 86400);
        switch ($metric) {
            case 'pv':
                return (float) Db::value(
                    "SELECT COUNT(*) FROM events
                     WHERE site_id=? AND type='pageview' AND day BETWEEN ? AND ? AND ts>=? AND ts<?",
                    [$sid, $d0, $d1, $ts0, $ts1]
                );
            case 'uv':
                return (float) Db::value(
                    "SELECT COUNT(DISTINCT visitor_id) FROM events
                     WHERE site_id=? AND type='pageview' AND day BETWEEN ? AND ? AND ts>=? AND ts<?",
                    [$sid, $d0, $d1, $ts0, $ts1]
                );
            case 'visits':
                return (float) Db::value(
                    'SELECT COUNT(*) FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
                    [$sid, $ts0, $ts1]
                );
            case 'bounce_rate': {
                $r = Db::first(
                    'SELECT COUNT(*) c, COALESCE(SUM(bounce),0) b FROM sessions
                     WHERE site_id=? AND start_ts>=? AND start_ts<?',
                    [$sid, $ts0, $ts1]
                );
                $c = (int) ($r['c'] ?? 0);
                return $c > 0 ? round((int) $r['b'] / $c * 100, 2) : 0.0;
            }
            case 'avg_duration': {
                $r = Db::first(
                    'SELECT COUNT(*) c, COALESCE(SUM(duration),0) d FROM sessions
                     WHERE site_id=? AND start_ts>=? AND start_ts<?',
                    [$sid, $ts0, $ts1]
                );
                $c = (int) ($r['c'] ?? 0);
                return $c > 0 ? round((int) $r['d'] / $c, 1) : 0.0;
            }
            case 'online': {
                // 无 Redis 模式：在线 = sessions 近 5 分钟活跃（end_ts 随 pageview 刷新）
                if (!Rds::enabled()) {
                    $r = Db::first(
                        'SELECT COUNT(*) c FROM sessions WHERE site_id=? AND end_ts>=? AND start_ts<=?',
                        [$sid, time() - 300, time()]
                    );
                    return (float) ($r['c'] ?? 0);
                }
                $r = Rds::get();
                if ($r === null) {
                    return 0.0;
                }
                try {
                    return (float) $r->zcard('on:' . $sid);
                } catch (\Throwable $e) {
                    return 0.0;
                }
            }
        }
        return 0.0;
    }

    /** 渠道解析：规则指定 > 用户全部启用渠道 */
    private static function resolveChannels(int $uid, string $spec): array
    {
        $spec = trim($spec);
        if ($spec !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $spec))));
            if (!$ids) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            return Db::select(
                "SELECT id,name,type,config FROM notify_channels
                 WHERE user_id=? AND is_active=1 AND id IN ($ph) ORDER BY id",
                [$uid, ...$ids]
            );
        }
        return Db::select(
            'SELECT id,name,type,config FROM notify_channels WHERE user_id=? AND is_active=1 ORDER BY id',
            [$uid]
        );
    }

    /* ==================== 文案 ==================== */

    private static function metricLabel(string $m): string
    {
        $map = [
            'pv' => '浏览量(PV)', 'uv' => '访客数(UV)', 'visits' => '访问次数',
            'online' => '在线人数', 'bounce_rate' => '跳出率', 'avg_duration' => '平均访问时长',
        ];
        return $map[$m] ?? $m;
    }

    private static function unit(string $m): string
    {
        if ($m === 'bounce_rate') {
            return '%';
        }
        if ($m === 'avg_duration') {
            return ' 秒';
        }
        return '';
    }

    private static function compareLabel(string $c): string
    {
        $map = [
            'ratio_up' => '较基线上升', 'ratio_down' => '较基线下降',
            'abs_over' => '高于阈值', 'abs_under' => '低于阈值', 'zero' => '降为 0',
        ];
        return $map[$c] ?? $c;
    }

    private static function subject(array $rule, array $site, string $level): string
    {
        $lv = ['info' => '提示', 'warn' => '警告', 'critical' => '严重'][$level] ?? $level;
        return "[$lv] " . (string) $site['name'] . ' · ' . (string) $rule['name'];
    }

    private static function message(array $rule, array $site, string $metric, float $val, float $base, int $ts0, int $ts1): string
    {
        $unit = self::unit($metric);
        $win = max(1, (int) $rule['window_min']);
        $baseName = (string) $rule['baseline'] === 'prev_window' ? '上一时段' : '昨日同时段';
        $diff = $base > 0 ? round(($val - $base) / $base * 100, 1) : null;

        $lines = [];
        $lines[] = '站点：' . (string) $site['name'] . '（' . (string) $site['domain'] . '）';
        $lines[] = '规则：' . (string) $rule['name'];
        $lines[] = '指标：' . self::metricLabel($metric);
        $lines[] = sprintf('窗口：%s ~ %s（近 %d 分钟）', date('Y-m-d H:i', $ts0), date('H:i', $ts1), $win);
        $lines[] = sprintf('当前值：%s%s', self::num($val), $unit);
        $lines[] = sprintf('%s：%s%s', $baseName, self::num($base), $unit);
        if ($diff !== null) {
            $lines[] = sprintf('变化：%s%s%%', $diff >= 0 ? '+' : '', $diff);
        }
        $lines[] = '判定：' . self::compareLabel((string) $rule['compare']) . '（阈值 ' . self::num((float) $rule['threshold']) . ($metric === 'bounce_rate' ? '%' : '') . '）';
        return implode("\n", $lines);
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /* ==================== 日报 / 周报 ==================== */

    /** 到点发送订阅的日报/周报，返回摘要 */
    public static function runReports(): string
    {
        // 推送总开关（同 evaluate）
        if (!Settings::int('alert_enabled')) {
            return 'reports disabled by sys setting';
        }
        $subs = Db::select('SELECT * FROM report_subscriptions WHERE is_active=1 ORDER BY id');
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($subs as $sub) {
            try {
                // 同告警规则：失去站点权限后不再推送日报
                $sid = (int) $sub['site_id'];
                if ($sid > 0 && SiteAccess::role((int) $sub['user_id'], $sid) === null) {
                    $skipped++;
                    continue;
                }
                $r = self::maybeSend($sub);
                if ($r === 'sent') {
                    $sent++;
                } elseif ($r === 'failed') {
                    $failed++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                fwrite(STDERR, '[report] sub#' . (int) $sub['id'] . ' error: ' . $e->getMessage() . "\n");
            }
        }
        return 'subs=' . count($subs) . " sent=$sent failed=$failed skipped=$skipped";
    }

    /** 判断并发送单条订阅 */
    private static function maybeSend(array $sub): string
    {
        $uid = (int) $sub['user_id'];
        $tz = self::userTz($uid, (int) $sub['site_id']);
        $off = Util::tzOffsetSec($tz);
        $nowLocal = time() + $off;
        $today = date('Y-m-d', $nowLocal);
        $hour = (int) date('G', $nowLocal);
        $dow = (int) date('N', $nowLocal);   // 1=周一 … 7=周日

        if ((string) $sub['last_sent_day'] === $today) {
            return 'skipped';                 // 当天已发过（幂等）
        }
        if ($hour < (int) $sub['hour']) {
            return 'skipped';                 // 还没到点
        }
        if ((string) $sub['freq'] === 'weekly') {
            $days = array_filter(array_map('intval', explode(',', (string) $sub['days'])));
            if (!in_array($dow, $days, true)) {
                return 'skipped';
            }
        }

        [$title, $text] = self::buildDigest($sub, $tz);
        $channels = self::resolveChannels($uid, (string) $sub['channels']);
        if (!$channels) {
            Db::execute('UPDATE report_subscriptions SET last_sent_day=?, last_status=? WHERE id=?',
                [$today, '未配置推送渠道，仅记录', (int) $sub['id']]);
            return 'failed';
        }
        $res = Notifier::send($channels, $title, $text);
        $status = $res['sent'] > 0 ? 'sent' : 'failed';
        $errs = [];
        foreach ($res['results'] as $rr) {
            if (!$rr['ok']) {
                $errs[] = $rr['name'] . ': ' . $rr['msg'];
            }
        }
        $note = $status === 'sent'
            ? ('已发送 ' . $res['sent'] . ' 个渠道' . ($errs ? '；部分失败: ' . implode(' | ', $errs) : ''))
            : ('发送失败: ' . implode(' | ', $errs));
        Db::execute('UPDATE report_subscriptions SET last_sent_day=?, last_status=?, updated_at=? WHERE id=?',
            [$today, mb_substr($note, 0, 190), time(), (int) $sub['id']]);
        return $status;
    }

    /** 订阅主体时区：指定站点用站点时区，全部站点用站点里第一个（或系统默认） */
    private static function userTz(int $uid, int $siteId): string
    {
        if ($siteId > 0) {
            $tz = Db::value('SELECT timezone FROM sites WHERE id=? AND user_id=?', [$siteId, $uid]);
            if ($tz) {
                return (string) $tz;
            }
        }
        $tz = Db::value('SELECT timezone FROM sites WHERE user_id=? ORDER BY id LIMIT 1', [$uid]);
        return $tz ? (string) $tz : (string) wstat_config('timezone');
    }

    /** 生成日报正文（昨日数据 + 环比 + TOP 榜单），返回 [标题, 正文] */
    private static function buildDigest(array $sub, string $tz): array
    {
        $uid = (int) $sub['user_id'];
        $sid = (int) $sub['site_id'];
        if ($sid > 0) {
            $sites = Db::select('SELECT id,name,domain,timezone FROM sites WHERE id=? AND user_id=?', [$sid, $uid]);
        } else {
            $sites = Db::select('SELECT id,name,domain,timezone FROM sites WHERE user_id=? AND status=1 ORDER BY id', [$uid]);
        }

        $off = Util::tzOffsetSec($tz);
        $nowLocal = time() + $off;
        $yDay = date('Y-m-d', $nowLocal - 86400);
        $dDay = date('Y-m-d', $nowLocal - 2 * 86400);
        $freqName = (string) $sub['freq'] === 'weekly' ? '周报' : '日报';

        $title = sprintf('%s · %s（%s）', wstat_config('app_name') ?: 'WebStats', $freqName, $yDay);
        $lines = [];
        $lines[] = sprintf('%s 数据概览 · %s', $freqName, $yDay);
        $lines[] = str_repeat('-', 24);

        if (!$sites) {
            $lines[] = '（暂无站点）';
            return [$title, implode("\n", $lines)];
        }

        $totPv = 0;
        $totUv = 0;
        $totVisits = 0;
        foreach ($sites as $s) {
            $stz = (string) $s['timezone'];
            [$ts0, $ts1] = Util::dateRangeToTs($yDay, $yDay, $stz);
            [$p0, $p1] = Util::dateRangeToTs($dDay, $dDay, $stz);
            $sidI = (int) $s['id'];

            $m = self::dayMetrics($sidI, $ts0, $ts1);
            $prev = self::dayMetrics($sidI, $p0, $p1);
            $totPv += (int) $m['pv'];
            $totUv += (int) $m['uv'];
            $totVisits += (int) $m['visits'];

            $lines[] = '';
            $lines[] = '● ' . (string) $s['name'] . '（' . (string) $s['domain'] . '）';
            $lines[] = sprintf(
                '  PV %s（%s） / UV %s（%s） / 访问 %s（%s）',
                $m['pv'], self::delta((float) $m['pv'], (float) $prev['pv']),
                $m['uv'], self::delta((float) $m['uv'], (float) $prev['uv']),
                $m['visits'], self::delta((float) $m['visits'], (float) $prev['visits'])
            );
            $lines[] = sprintf('  跳出率 %s%% / 平均停留 %ss', $m['bounce'], $m['dur']);

            $tops = self::topPages($sidI, $yDay, $stz, 5);
            if ($tops) {
                $lines[] = '  热门页面:';
                foreach ($tops as $i => $t) {
                    $lines[] = sprintf('    %d. %s — %s PV', $i + 1, self::shortUrl((string) $t['url']), $t['pv']);
                }
            }
            $chans = self::topChannels($sidI, $yDay, $stz, 3);
            if ($chans) {
                $lines[] = '  来源构成: ' . implode('；', array_map(
                    static fn ($c) => $c['name'] . ' ' . $c['pv'],
                    $chans
                ));
            }
        }

        if (count($sites) > 1) {
            $lines[] = '';
            $lines[] = str_repeat('-', 24);
            $lines[] = sprintf('合计：PV %s / UV %s / 访问 %s（%d 个站点）', $totPv, $totUv, $totVisits, count($sites));
        }
        $lines[] = '';
        $lines[] = '数据区间：' . $yDay . '（' . $tz . '） · 环比为前一日同口径';

        return [$title, implode("\n", $lines)];
    }

    /** 单站点某日核心指标 */
    private static function dayMetrics(int $sid, int $ts0, int $ts1): array
    {
        $d0 = gmdate('Y-m-d', $ts0 - 86400);
        $d1 = gmdate('Y-m-d', $ts1 + 86400);
        $ev = Db::first(
            "SELECT SUM(type='pageview') pv, COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv
             FROM events WHERE site_id=? AND day BETWEEN ? AND ? AND ts>=? AND ts<?",
            [$sid, $d0, $d1, $ts0, $ts1]
        ) ?: ['pv' => 0, 'uv' => 0];
        $ss = Db::first(
            'SELECT COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) d
             FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
            [$sid, $ts0, $ts1]
        ) ?: ['c' => 0, 'b' => 0, 'd' => 0];
        $c = (int) $ss['c'];
        return [
            'pv' => (int) $ev['pv'],
            'uv' => (int) $ev['uv'],
            'visits' => $c,
            'bounce' => $c > 0 ? round((int) $ss['b'] / $c * 100, 1) : 0,
            'dur' => $c > 0 ? round((int) $ss['d'] / $c) : 0,
        ];
    }

    private static function topPages(int $sid, string $day, string $tz, int $limit): array
    {
        [$ts0, $ts1] = Util::dateRangeToTs($day, $day, $tz);
        return Db::select(
            "SELECT url, COUNT(*) pv FROM events
             WHERE site_id=? AND type='pageview' AND day=? AND url<>''
             GROUP BY url ORDER BY pv DESC LIMIT " . max(1, $limit),
            [$sid, $day]
        );
    }

    private static function topChannels(int $sid, string $day, string $tz, int $limit): array
    {
        return Db::select(
            "SELECT source AS name, COUNT(*) pv FROM events
             WHERE site_id=? AND type='pageview' AND day=? AND source<>''
             GROUP BY source ORDER BY pv DESC LIMIT " . max(1, $limit),
            [$sid, $day]
        );
    }

    /** 环比文案，如「+12.3%」「-8%」「持平」 */
    private static function delta(float $cur, float $prev): string
    {
        if ($prev <= 0) {
            return $cur > 0 ? '新增' : '—';
        }
        $d = round(($cur - $prev) / $prev * 100, 1);
        if ($d === 0.0) {
            return '持平';
        }
        return ($d > 0 ? '+' : '') . $d . '%';
    }

    /** URL 只保留路径，日报里更易读 */
    private static function shortUrl(string $url): string
    {
        $p = parse_url($url, PHP_URL_PATH);
        if (is_string($p) && $p !== '') {
            $q = parse_url($url, PHP_URL_QUERY);
            return $p . (is_string($q) && $q !== '' ? '?' . mb_substr($q, 0, 30) : '');
        }
        return mb_substr($url, 0, 80);
    }
}
