<?php
/**
 * 统计控制器（概览 / 会话 / 实时在线）
 * 数据口径：概览走 site_daily 预聚合快路径（cron rollup 维护，缺失天自动回填预热）；
 * UV/IP 周期去重优先 Redis HLL 多键合并；会话/明细走 (site_id,start_ts) / (session_id,ts) 索引。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\IpLocator;
use Wstat\Support\Rds;
use Wstat\Support\Referrer;
use Wstat\Support\Sessionizer;
use Wstat\Support\Settings;
use Wstat\Support\SiteAccess;
use Wstat\Support\Util;

class StatsController
{
    private const MAX_RANGE_DAYS = 366;

    /** GET /api/stats/overview?site_id=&start=&end=  （预聚合快路径，毫秒级） */
    public function overview(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];
        $offset = Util::tzOffsetSec($tz);

        // ---- 1) 预聚合快路径：site_daily（uk_site_day 索引） ----
        $rows = Db::select(
            'SELECT `day`,pv,uv,ipc,visits,bounce,duration,new_users
             FROM site_daily WHERE site_id=? AND `day` BETWEEN ? AND ? ORDER BY `day`',
            [$sid, $start, $end]
        );
        $have = [];
        foreach ($rows as $r) {
            $have[(string) $r['day']] = $r;
        }

        // ---- 2) 缺失天补齐（rollup 未覆盖/首次部署）：批量走 day 索引查明细并回写预热 ----
        $days = [];
        foreach (Util::dailySeries($startTs, $endTs, $tz, fn () => null) as $d => $_) {
            $days[] = (string) $d;
        }
        $missing = array_values(array_filter($days, fn ($d) => !isset($have[$d])));
        if (!empty($missing)) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            // 2a) events 按日聚合（idx_site_day_type）
            foreach (
                Db::select(
                    "SELECT `day`,
                            SUM(type='pageview') pv,
                            COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv,
                            COUNT(DISTINCT CASE WHEN type='pageview' AND ip<>'' THEN ip END) ipc
                     FROM events WHERE site_id=? AND `day` IN ($ph) GROUP BY `day`",
                    [$sid, ...$missing]
                ) as $r
            ) {
                $d = (string) $r['day'];
                $base = $have[$d] ?? self::emptyDaily($d);
                $have[$d] = array_merge($base, [
                    'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'ipc' => (int) $r['ipc'],
                ]);
            }
            // 2b) sessions 按站点本地日聚合（idx_site_start 范围扫描）
            foreach (
                Db::select(
                    "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d,
                            COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                            COALESCE(SUM(is_new),0) nw
                     FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                    [$offset, $sid, $startTs, $endTs]
                ) as $r
            ) {
                $d = (string) $r['d'];
                if (!in_array($d, $missing, true)) {
                    continue;
                }
                $base = $have[$d] ?? self::emptyDaily($d);
                $have[$d] = array_merge($base, [
                    'visits' => (int) $r['c'], 'bounce' => (int) $r['b'],
                    'duration' => (int) $r['du'], 'new_users' => (int) $r['nw'],
                ]);
            }
            // 回写 site_daily 预热，下次请求直接命中快路径（幂等）
            foreach ($missing as $d) {
                $h = $have[$d] ?? null;
                if ($h === null) {
                    continue;
                }
                Db::execute(
                    'INSERT INTO site_daily (site_id,`day`,pv,uv,ipc,visits,bounce,duration,new_users,updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,UNIX_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE pv=VALUES(pv), uv=VALUES(uv), ipc=VALUES(ipc),
                        visits=VALUES(visits), bounce=VALUES(bounce), duration=VALUES(duration), new_users=VALUES(new_users)',
                    [$sid, $d, (int) $h['pv'], (int) $h['uv'], (int) $h['ipc'], (int) $h['visits'],
                        (int) $h['bounce'], (int) $h['duration'], (int) $h['new_users']]
                );
            }
        }

        // ---- 2c) 兜底校正：0 值汇总行 / 中间态（仅 PV 回写而 visits 未回写）会挡住面板指标。
        // 区间内 PV 或 visits 任一合计为 0，而明细确有 pageview（访问真实发生）→ 强制按明细重算并回写。
        $pvSum2c = 0;
        $visitSum2c = 0;
        foreach ($days as $d) {
            $h = $have[$d] ?? null;
            $pvSum2c += (int) ($h['pv'] ?? 0);
            $visitSum2c += (int) ($h['visits'] ?? 0);
        }
        $needCorr = ($pvSum2c === 0 || $visitSum2c === 0);
        if ($needCorr) {
            $hasPv = (int) Db::value(
                'SELECT 1 FROM events WHERE site_id=? AND type="pageview" AND `day` BETWEEN ? AND ? LIMIT 1',
                [$sid, $start, $end]
            );
            if ($hasPv === 1) {
                foreach (
                    Db::select(
                        "SELECT `day`,
                                SUM(type='pageview') pv,
                                COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv,
                                COUNT(DISTINCT CASE WHEN type='pageview' AND ip<>'' THEN ip END) ipc
                         FROM events WHERE site_id=? AND `day` BETWEEN ? AND ? GROUP BY `day`",
                        [$sid, $start, $end]
                    ) as $r
                ) {
                    $d = (string) $r['day'];
                    $base = $have[$d] ?? self::emptyDaily($d);
                    $have[$d] = array_merge($base, [
                        'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'ipc' => (int) $r['ipc'],
                    ]);
                }
                foreach (
                    Db::select(
                        "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d,
                                COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                                COALESCE(SUM(is_new),0) nw
                         FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                        [$offset, $sid, $startTs, $endTs]
                    ) as $r
                ) {
                    $d = (string) $r['d'];
                    if (!in_array($d, $days, true)) {
                        continue;
                    }
                    $base = $have[$d] ?? self::emptyDaily($d);
                    $have[$d] = array_merge($base, [
                        'visits' => (int) $r['c'], 'bounce' => (int) $r['b'],
                        'duration' => (int) $r['du'], 'new_users' => (int) $r['nw'],
                    ]);
                }
                foreach ($days as $d) {
                    $h = $have[$d] ?? null;
                    if ($h === null) {
                        continue;
                    }
                    Db::execute(
                        'INSERT INTO site_daily (site_id,`day`,pv,uv,ipc,visits,bounce,duration,new_users,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,UNIX_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE pv=VALUES(pv), uv=VALUES(uv), ipc=VALUES(ipc),
                            visits=VALUES(visits), bounce=VALUES(bounce), duration=VALUES(duration), new_users=VALUES(new_users)',
                        [$sid, $d, (int) $h['pv'], (int) $h['uv'], (int) $h['ipc'], (int) $h['visits'],
                            (int) $h['bounce'], (int) $h['duration'], (int) $h['new_users']]
                    );
                }
            }
        }

        // ---- 3) 趋势序列 + 区间求和 ----
        $sum = ['pv' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
        $trend = [];
        foreach ($days as $d) {
            $h = $have[$d] ?? null;
            $trend[] = [
                'day' => $d,
                'pv' => (int) ($h['pv'] ?? 0),
                'uv' => (int) ($h['uv'] ?? 0),
                'ipc' => (int) ($h['ipc'] ?? 0),
            ];
            $sum['pv'] += (int) ($h['pv'] ?? 0);
            $sum['visits'] += (int) ($h['visits'] ?? 0);
            $sum['bounce'] += (int) ($h['bounce'] ?? 0);
            $sum['duration'] += (int) ($h['duration'] ?? 0);
            $sum['new_users'] += (int) ($h['new_users'] ?? 0);
        }

        // ---- 4) UV/IP 周期去重（精确）：events 明细 DISTINCT，一次扫描同时取 UV 与独立 IP ----
        // 2026-09-15 变更：此前 Redis 可用时走 HyperLogLog（PFCOUNT）估算，而估算在极小基数下会多估，
        // 出现「概览 IP 数 4 / IP 地域页 3」这类不一致。现在无论是否部署 Redis，UV/IP 一律走同一条
        // 精确 SQL，与 IP 地域页、大屏、会话明细完全同口径（口径一致优先于省内存）。
        $uniq = $this->exactUniq($sid, $start, $end);
        $uvTotal = $uniq['uv'];
        $ipcTotal = $uniq['ipc'];

        // Redis 句柄：此后仅用于「进行中会话」合并，不再参与 UV/IP 统计
        $redis = null;
        try {
            $redis = Rds::get();
        } catch (\Throwable $e) {
            $redis = null;
        }

        // ---- 5) 今日实时修正：rollup 有分钟级延迟，故今日 PV/UV/IP 一律用 events 实况覆盖。
        // 这里不再是「Redis 分支 / 无 Redis 分支」两套数字：PV/UV/IP 走同一个 todayExact()，
        // 保证同一站点同一时刻，无论是否部署 Redis，概览数字完全相同。会话侧才按模式分流：
        //   · Redis 模式：并入仍驻留 Redis 的「进行中会话」（尚未回收写入 sessions 表）；
        //   · 无 Redis 模式：sessions 表即时写入，用「实况 - 已汇总」差值修正。
        $todayLocal = gmdate('Y-m-d', time() + $offset);
        if (in_array($todayLocal, $days, true)) {
            try {
                $t = $this->todayExact($sid, $todayLocal);
                $rolledPv = (int) ($have[$todayLocal]['pv'] ?? 0);
                foreach ($trend as &$tr) {
                    if ($tr['day'] === $todayLocal) {
                        $tr['pv'] = $t['pv'];
                        $tr['uv'] = $t['uv'];
                        $tr['ipc'] = $t['ipc'];
                    }
                }
                unset($tr);
                $sum['pv'] += max(0, $t['pv'] - $rolledPv);   // 总计补上 rollup 未落库的今日增量

                [$ts0, $ts1] = Util::dateRangeToTs($todayLocal, $todayLocal, $tz);
                if ($redis !== null) {
                    $liveRows = Sessionizer::liveRows($redis, $sid, $ts0, $ts1);
                    if ($liveRows) {
                        $liveVisits = count($liveRows);
                        $liveBounce = 0;
                        $liveDur = 0;
                        foreach ($liveRows as $lv) {
                            $liveBounce += (int) $lv['bounce'];
                            $liveDur += (int) $lv['duration'];
                        }
                        $sum['visits'] += $liveVisits;
                        $sum['bounce'] += $liveBounce;
                        $sum['duration'] += $liveDur;
                        foreach ($trend as &$tr) {
                            if ($tr['day'] === $todayLocal) {
                                $tr['visits'] = ($tr['visits'] ?? 0) + $liveVisits;
                            }
                        }
                        unset($tr);
                    }
                } else {
                    $srow = Db::first(
                        'SELECT COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du
                         FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
                        [$sid, $ts0, $ts1]
                    );
                    if ($srow) {
                        $lv = (int) $srow['c'];
                        $lb = (int) $srow['b'];
                        $ld = (int) $srow['du'];
                        $sum['visits'] += max(0, $lv - (int) ($have[$todayLocal]['visits'] ?? 0));
                        $sum['bounce'] += max(0, $lb - (int) ($have[$todayLocal]['bounce'] ?? 0));
                        $sum['duration'] += max(0, $ld - (int) ($have[$todayLocal]['duration'] ?? 0));
                        foreach ($trend as &$tr) {
                            if ($tr['day'] === $todayLocal) {
                                $tr['visits'] = $lv;
                            }
                        }
                        unset($tr);
                    }
                }
            } catch (\Throwable $e) { /* 忽略实时修正失败 */ }
        }

        $visits = $sum['visits'];
        $totals = [
            'pv' => $sum['pv'], 'uv' => $uvTotal, 'ipc' => $ipcTotal, 'visits' => $visits,
            'bounce' => (int) $sum['bounce'],
            'bounce_rate' => $visits > 0 ? round($sum['bounce'] / $visits * 100, 1) : 0,
            'avg_duration' => $visits > 0 ? (int) round($sum['duration'] / $visits) : 0,
            'new_users' => (int) $sum['new_users'],
        ];
        $totals['old_users'] = max(0, (int) $uvTotal - (int) $totals['new_users']);

        // ---- 5b) 单日区间：小时级分时序列（趋势卡分时视图） ----
        $hourly = [];
        if ($start === $end) {
            $hmap = [];
            foreach (
                Db::select(
                    "SELECT LPAD(HOUR(FROM_UNIXTIME(ts+?)),2,'0') h,
                            SUM(type='pageview') pv,
                            COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv
                     FROM events WHERE site_id=? AND `day`=? GROUP BY h",
                    [$offset, $sid, $start]
                ) as $r
            ) {
                $hmap[(string) $r['h']] = ['pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
            }
            for ($i = 0; $i < 24; $i++) {
                $h = sprintf('%02d', $i);
                $hourly[] = ['hour' => $h . ':00', 'pv' => $hmap[$h]['pv'] ?? 0, 'uv' => $hmap[$h]['uv'] ?? 0];
            }
        }

        // ---- 5c) 环比：上一等长区间汇总（site_daily 快路径，覆盖不全退回明细聚合） ----
        // 注意：strtotime/date 均按默认时区解析与格式化，二者配对自洽；不可混用 gmdate
        $len = count($days);
        $pEnd = date('Y-m-d', strtotime($start . ' 00:00:00') - 86400);
        $pStart = date('Y-m-d', strtotime($pEnd . ' 00:00:00') - ($len - 1) * 86400);
        $prev = $this->periodTotals($sid, $pStart, $pEnd, $tz);
        $deltaKeys = ['pv', 'uv', 'ipc', 'visits', 'bounce_rate', 'avg_duration', 'new_users', 'old_users'];
        $deltas = [];
        foreach ($deltaKeys as $k) {
            $c = (float) ($totals[$k] ?? 0);
            $p = (float) ($prev[$k] ?? 0);
            if ($p > 0) {
                $deltas[$k] = round(($c - $p) / $p * 100, 1);
            } else {
                $deltas[$k] = $c > 0 ? 100.0 : null;
            }
        }

        // ---- 实时（今日/分钟/在线） ----
        $live = $this->live($sid, 0, $offset);

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'totals' => $totals,
            'deltas' => $deltas,
            'trend' => $trend,
            'hourly' => $hourly,
            'live' => $live,
        ]);
    }

    /** 等长区间汇总（环比用）：site_daily 优先，覆盖不全时退回 events/sessions 明细聚合 */
    private function periodTotals(int $sid, string $start, string $end, string $tz): array
    {
        $rows = Db::select(
            'SELECT pv,visits,bounce,duration,new_users FROM site_daily
             WHERE site_id=? AND `day` BETWEEN ? AND ?',
            [$sid, $start, $end]
        );
        $pv = 0; $visits = 0; $bounce = 0; $dur = 0; $new = 0;
        foreach ($rows as $r) {
            $pv += (int) $r['pv'];
            $visits += (int) $r['visits'];
            $bounce += (int) $r['bounce'];
            $dur += (int) $r['duration'];
            $new += (int) $r['new_users'];
        }
        $expected = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        $partial = count($rows) < $expected;

        // UV/IP 精确去重：与 overview 共用同一条 SQL，环比数字必须与主指标同口径
        $uniq = $this->exactUniq($sid, $start, $end);
        $uv = $uniq['uv'];
        $ipc = $uniq['ipc'];
        if ($partial) {
            $pv2 = (int) Db::value(
                "SELECT COUNT(*) FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
                [$sid, $start, $end]
            );
            if ($pv2 > $pv) {
                $pv = $pv2;
            }
        }
        if ($partial) {
            [$ts0, $ts1] = Util::dateRangeToTs($start, $end, $tz);
            $s = Db::first(
                'SELECT COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                        COALESCE(SUM(is_new),0) nw
                 FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
                [$sid, $ts0, $ts1]
            );
            if ($s) {
                $visits = max($visits, (int) $s['c']);
                $bounce = max($bounce, (int) $s['b']);
                $dur = max($dur, (int) $s['du']);
                $new = max($new, (int) $s['nw']);
            }
        }
        return [
            'pv' => $pv, 'uv' => $uv, 'ipc' => $ipc, 'visits' => $visits,
            'bounce_rate' => $visits > 0 ? round($bounce / $visits * 100, 1) : 0,
            'avg_duration' => $visits > 0 ? (int) round($dur / $visits) : 0,
            'new_users' => $new,
            'old_users' => max(0, (int) $uv - $new),
        ];
    }

    /**
     * 区间「唯一值」精确去重：UV 与独立 IP 一次扫描同时得到。
     *
     * 这是全站 UV / 独立 IP 的唯一口径来源 —— 概览、环比、大屏、IP 地域页都必须经由它，
     * 保证同一站点同一区间在任何页面看到的数字完全一致（是否部署 Redis 都不影响结果）。
     * 独立 IP 排除空串（取不到真实 IP 的事件不计入），与 IP 地域页的 `ip<>''` 口径一致。
     */
    private function exactUniq(int $sid, string $start, string $end): array
    {
        $r = Db::first(
            "SELECT COUNT(DISTINCT visitor_id) uv,
                    COUNT(DISTINCT CASE WHEN ip<>'' THEN ip END) ipc
             FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        ) ?: [];
        return ['uv' => (int) ($r['uv'] ?? 0), 'ipc' => (int) ($r['ipc'] ?? 0)];
    }

    /**
     * 今日实况（精确）：PV / UV / 独立 IP 一次扫描。
     * 用于覆盖 rollup 滞后导致的今日数据陈旧；Redis 模式与无 Redis 模式共用本方法，
     * 因此两种部署形态的今日数字完全相同。
     */
    private function todayExact(int $sid, string $day): array
    {
        $r = Db::first(
            "SELECT COUNT(*) pv,
                    COUNT(DISTINCT visitor_id) uv,
                    COUNT(DISTINCT CASE WHEN ip<>'' THEN ip END) ipc
             FROM events WHERE site_id=? AND type='pageview' AND `day`=?",
            [$sid, $day]
        ) ?: [];
        return [
            'pv' => (int) ($r['pv'] ?? 0),
            'uv' => (int) ($r['uv'] ?? 0),
            'ipc' => (int) ($r['ipc'] ?? 0),
        ];
    }

    /** GET /api/stats/sources?site_id=&start=&end=  来源分析 */
    public function sources(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];

        $w = 'site_id=? AND type="pageview" AND `day` BETWEEN ? AND ?';
        $args = [$sid, $start, $end];
        $types = ['direct', 'search', 'social', 'link', 'paid', 'utm', 'internal'];

        // 预检：来源分析查询依赖归因扩展列；库未跑增量迁移时给出明确提示而非 SQLSTATE 500
        $missing = [];
        $cols = Db::tableColumns('events');
        foreach (['utm_source', 'utm_medium', 'click_source', 'ref_host', 'kw'] as $need) {
            if (!isset($cols[$need])) {
                $missing[] = $need;
            }
        }
        if ($missing) {
            $alter = "ALTER TABLE `events`\n  ADD COLUMN `utm_source`   VARCHAR(128) NOT NULL DEFAULT '' AFTER `medium`,\n  ADD COLUMN `utm_medium`   VARCHAR(128) NOT NULL DEFAULT '' AFTER `utm_source`,\n  ADD COLUMN `click_source` VARCHAR(64)  NOT NULL DEFAULT '' AFTER `click_id`,\n  ADD COLUMN `ref_host`     VARCHAR(255) NOT NULL DEFAULT '' AFTER `click_source`,\n  ADD COLUMN `kw`           VARCHAR(255) NOT NULL DEFAULT '' AFTER `ref_host`;";
            wstat_err(
                'events 表缺少来源分析列：' . implode(', ', $missing) . "。请在数据库执行升级：\n\n" . $alter
                . "\n\n或直接运行：mysql -uroot -p < sql/upgrade-2026-09-17-search-keywords.sql（路径以部署目录为准）。"
                . '执行后刷新本页即可；若 worker/collect 为常驻进程，请重启以刷新列缓存。',
                200, 1
            );
            return;
        }

        // 渠道 PV（source 列 = 渠道类型）
        $ch = [];
        foreach ($types as $tp) {
            $ch[$tp] = 0;
        }
        foreach (
            Db::select("SELECT source k, COUNT(*) pv FROM events WHERE $w AND source IN ('" . implode("','", $types) . "') GROUP BY source", $args) as $r
        ) {
            $ch[(string) $r['k']] = (int) $r['pv'];
        }
        $totalPv = array_sum($ch);

        // 渠道×日趋势（堆叠图数据）
        $days = [];
        foreach (Util::dailySeries($startTs, $endTs, $tz, fn () => null) as $d => $_) {
            $days[] = (string) $d;
        }
        $trendData = [];
        foreach ($types as $tp) {
            $trendData[$tp] = array_fill(0, count($days), 0);
        }
        $dayIdx = array_flip($days);
        foreach (
            Db::select("SELECT `day` d, source k, COUNT(*) pv FROM events WHERE $w AND source IN ('" . implode("','", $types) . "') GROUP BY `day`, source ORDER BY d", $args) as $r
        ) {
            $i = $dayIdx[(string) $r['d']] ?? -1;
            $k = (string) $r['k'];
            if ($i >= 0 && isset($trendData[$k])) {
                $trendData[$k][$i] = (int) $r['pv'];
            }
        }

        // 外链主机 TOP（ref_host）
        $referrers = Db::select(
            "SELECT ref_host host, COUNT(*) pv FROM events WHERE $w AND ref_host<>'' GROUP BY ref_host ORDER BY pv DESC LIMIT 20",
            $args
        );

        // ---- 搜索引擎来路关键词（kw 由采集端从 ref 提取，未提取到=「未提供」） ----
        // ref_host → 引擎品牌归并在 PHP 做（www.baidu.com / m.baidu.com → baidu）。
        // 引擎级 UV 用 ref_host 精确去重；关键词级 UV 用 (ref_host, kw) 精确去重，
        // 两者跨行相加都可能高估（同一访客多词/多 host），故只作为上界口径在页面说明。
        $terms = [];
        $engineRows = [];   // host => ['pv'=>n, 'uv'=>n, 'no_kw_pv'=>n]
        foreach (
            Db::select(
                "SELECT ref_host host, kw, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events WHERE $w AND source='search' GROUP BY ref_host, kw",
                $args
            ) as $r
        ) {
            $host = (string) $r['host'];
            $kw = trim((string) $r['kw']);
            $pv = (int) $r['pv'];
            if ($kw === '') {
                $engineRows[$host]['no_kw_pv'] = ($engineRows[$host]['no_kw_pv'] ?? 0) + $pv;
                continue;
            }
            $terms[] = ['engine' => $host !== '' ? Referrer::brand($host) : 'other', 'kw' => $kw, 'pv' => $pv, 'uv' => (int) $r['uv']];
        }
        foreach (
            Db::select(
                "SELECT ref_host host, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events WHERE $w AND source='search' GROUP BY ref_host",
                $args
            ) as $r
        ) {
            $host = (string) $r['host'];
            $engineRows[$host]['pv'] = (int) $r['pv'];
            $engineRows[$host]['uv'] = (int) $r['uv'];
        }
        $engines = [];
        $noKwPv = 0;
        foreach ($engineRows as $host => $row) {
            $engine = $host !== '' ? Referrer::brand($host) : 'other';
            if (!isset($engines[$engine])) {
                $engines[$engine] = ['engine' => $engine, 'pv' => 0, 'uv' => 0];
            }
            $engines[$engine]['pv'] += (int) ($row['pv'] ?? 0);
            $engines[$engine]['uv'] = max($engines[$engine]['uv'], (int) ($row['uv'] ?? 0));
            $noKwPv += (int) ($row['no_kw_pv'] ?? 0);
        }
        $enginesOut = array_values($engines);
        usort($enginesOut, fn ($a, $b) => $b['pv'] - $a['pv']);
        usort($terms, fn ($a, $b) => $b['pv'] - $a['pv'] ?: $b['uv'] - $a['uv']);
        $terms = array_slice($terms, 0, 100);

        // 说明：UTM 投放系列与广告平台（ClickID）已统一归口「广告追踪」页（/api/stats/ads），
        // 本接口不再返回 campaigns / ads，避免同一批数据两处展示、口径分裂。

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'total_pv' => $totalPv,
            'channels' => array_map(fn ($tp) => ['type' => $tp, 'pv' => $ch[$tp]], $types),
            'trend' => ['days' => $days, 'data' => $trendData],
            'referrers' => $referrers,
            'engines' => $enginesOut,
            'terms' => $terms,
            'no_kw_pv' => $noKwPv,
        ]);
    }

    /** GET /api/stats/sessions?site_id=&page=&size=&start=&end=&q= */
    public function sessions(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $page = max(1, (int) $req->input('page', 1));
        $size = min(100, max(1, (int) $req->input('size', 15)));
        $q = trim((string) $req->input('q', ''));

        $where = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [(int) $site['id'], $startTs, $endTs];
        if ($q !== '') {
            $like = '%' . $this->likeEscape($q) . '%';
            // 支持按 访客/URL/IP/终端用户标识 检索（ip/end_user 为迁移项：仅当列存在时加入匹配）
            $scols = Db::tableColumns('sessions');
            $ipLike = isset($scols['ip']) ? " OR ip LIKE ? ESCAPE '\\\\'" : '';
            $euLike = isset($scols['end_user']) ? " OR end_user LIKE ? ESCAPE '\\\\'" : '';
            $where .= " AND (visitor_id LIKE ? ESCAPE '\\\\' OR entry_url LIKE ? ESCAPE '\\\\'$ipLike$euLike)";
            $args[] = $like;
            $args[] = $like;
            if ($ipLike !== '') {
                $args[] = $like;
            }
            if ($euLike !== '') {
                $args[] = $like;
            }
        }
        // 高级筛选（模糊匹配）：浏览器 / 操作系统 / 设备 / 来源渠道
        foreach (['browser', 'os', 'device', 'source'] as $fcol) {
            $fval = trim((string) $req->input($fcol, ''));
            if ($fval !== '') {
                $where .= " AND $fcol LIKE ? ESCAPE '\\\\'";
                $args[] = '%' . $this->likeEscape($fval) . '%';
            }
        }
        $hasFilter = $q !== '' || (bool) array_filter(array_map(fn ($c) => trim((string) $req->input($c, '')), ['browser', 'os', 'device', 'source']));
        $total = (int) Db::value("SELECT COUNT(*) FROM sessions WHERE $where", $args);
        $ipCol = isset(Db::tableColumns('sessions')['ip']) ? ',ip' : '';
        $euCol = isset(Db::tableColumns('sessions')['end_user']) ? ',end_user' : '';
        $rows = Db::select(
            "SELECT id,session_id,visitor_id$euCol,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,click_id,
                    browser,os,device,screen,lang,country,province,city$ipCol
             FROM sessions WHERE $where ORDER BY start_ts DESC LIMIT " . (($page - 1) * $size) . ",$size",
            $args
        );

        // ---- 进行中会话（Redis 实时）合并：仅首页/无搜索/区间含今日时前置展示 ----
        $todayLocal = gmdate('Y-m-d', time() + Util::tzOffsetSec((string) $site['timezone']));
        $redis = Rds::get();
        if ($redis !== null && $page === 1 && !$hasFilter && $start <= $todayLocal && $todayLocal <= $end) {
            $pre = [];
            foreach (Sessionizer::liveRows($redis, (int) $site['id'], $startTs, $endTs) as $i => $row) {
                $pre[] = [
                    'id' => -(100000 + $i),   // 伪 id（负数，不与 MySQL 行冲突，rowKey 唯一）
                    'session_id' => (string) ($row['session_id'] ?? ''),
                    'visitor_id' => (string) ($row['visitor_id'] ?? ''),
                    'end_user' => (string) ($row['end_user'] ?? ''),
                    'start_ts' => (int) ($row['start_ts'] ?? 0),
                    'end_ts' => (int) ($row['end_ts'] ?? 0),
                    'pageviews' => (int) ($row['pageviews'] ?? 1),
                    'duration' => (int) ($row['duration'] ?? 0),
                    'bounce' => (int) ($row['bounce'] ?? 0),
                    'is_new' => (int) ($row['is_new'] ?? 0),
                    'entry_url' => (string) ($row['entry_url'] ?? ''),
                    'exit_url' => (string) ($row['exit_url'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'direct'),
                    'medium' => (string) ($row['medium'] ?? ''),
                    'campaign' => (string) ($row['campaign'] ?? ''),
                    'click_id' => (string) ($row['click_id'] ?? ''),
                    'browser' => (string) ($row['browser'] ?? ''),
                    'os' => (string) ($row['os'] ?? ''),
                    'device' => (string) ($row['device'] ?? ''),
                    'screen' => (string) ($row['screen'] ?? ''),
                    'lang' => (string) ($row['lang'] ?? ''),
                    'ip' => (string) ($row['ip'] ?? ''),
                    'country' => (string) ($row['country'] ?? ''),
                    'province' => (string) ($row['province'] ?? ''),
                    'city' => (string) ($row['city'] ?? ''),
                    'live' => true,
                ];
            }
            if ($pre) {
                $rows = array_merge($pre, $rows);
                $total += count($pre);
            }
        }
        wstat_json(['items' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /** GET /api/stats/sessions/{id}?site_id=  单个会话 + 完整行为日志 */
    public function sessionDetail(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $ipCol = isset(Db::tableColumns('sessions')['ip']) ? ',ip' : '';
        $sess = Db::first(
            'SELECT id,session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,content,term,click_id,
                    browser,os,device,screen,lang,country,province,city' . $ipCol . '
             FROM sessions WHERE id=? AND site_id=? LIMIT 1',
            [(int) $req->param('id'), (int) $site['id']]
        );
        if ($sess === null) {
            // 进行中会话（尚未落库）：按 session_id 从 Redis 构建
            $sidQ = trim((string) $req->input('sid', ''));
            if ($sidQ !== '') {
                $r = Rds::get();
                if ($r !== null) {
                    $h = $r->hgetall(Sessionizer::HASH_KEY . $sidQ);
                    $row = Sessionizer::buildRow($h);
                    if ($row !== null && (int) $row['site_id'] === (int) $site['id']) {
                        $row['id'] = 0;
                        $row['live'] = true;
                        $sess = $row;
                    }
                }
            }
            if ($sess === null) {
                wstat_err('会话不存在', 404, 404);
            }
        }
        // 行为日志：该会话下的全部事件（session_id 精确命中，含类型细分）
        $events = Db::select(
            'SELECT ts,type,url,title,ref,source,payload
             FROM events WHERE session_id=? AND ts>=? AND ts<=?
             ORDER BY ts ASC LIMIT 1000',
            [(string) $sess['session_id'], (int) $sess['start_ts'] - 30, (int) $sess['end_ts'] + 30]
        );
        foreach ($events as &$e) {
            $e['payload'] = $e['payload'] !== null && $e['payload'] !== '' ? json_decode($e['payload'], true) : null;
        }
        unset($e);

        // 访客级统计：同一 visitor_id 的访问次数 / 总浏览量 / 平均访问时长 / 首末出现
        $vid = (string) ($sess['visitor_id'] ?? '');
        $live = !empty($sess['live']);
        $vs = [
            'visits'   => 1,
            'views'    => (int) ($sess['pageviews'] ?? 0),
            'avg_dur'  => (int) ($sess['duration'] ?? 0),
            'first_ts' => (int) ($sess['start_ts'] ?? 0),
            'last_ts'  => (int) ($sess['end_ts'] ?? 0),
        ];
        if ($vid !== '') {
            $agg = Db::first(
                'SELECT COUNT(*) c, COALESCE(SUM(pageviews),0) pv, COALESCE(ROUND(AVG(duration)),0) dur,
                        MIN(start_ts) f, MAX(end_ts) l
                 FROM sessions WHERE site_id=? AND visitor_id=?',
                [(int) $site['id'], $vid]
            );
            if ($agg) {
                $c = (int) $agg['c'];
                $den = $c + ($live ? 1 : 0);
                $vs['visits'] = $den;
                $vs['views'] = (int) $agg['pv'] + ($live ? (int) $sess['pageviews'] : 0);
                $sumDur = ((int) $agg['dur']) * $c + ($live ? (int) $sess['duration'] : 0);
                $vs['avg_dur'] = $den > 0 ? (int) round($sumDur / $den) : 0;
                $vs['first_ts'] = min((int) $agg['f'] ?: PHP_INT_MAX, (int) $sess['start_ts']);
                $vs['last_ts'] = max((int) $agg['l'], (int) $sess['end_ts']);
            }
        }
        wstat_json(['session' => $sess, 'events' => $events, 'visitor' => $vs, 'now' => time()]);
    }

    /**
     * GET /api/stats/iptrace?site_id=&start=&end=&q=&page=&size=
     * IP 追踪溯源：按 IP 聚合会话（地域 / 会话数 / PV / 访客数 / 首末访问），
     * 附渠道构成（sessions.source）与主要来路主机（events.ref_host TOP）。
     * 展开轨迹由前端复用 /api/stats/sessions?q=<ip> 完成（该端点已支持按 IP 检索）。
     */
    public function iptrace(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $page = max(1, (int) $req->input('page', 1));
        $size = min(100, max(1, (int) $req->input('size', 15)));
        $q = trim((string) $req->input('q', ''));

        // 老库缺 ip 列：给出明确迁移提示而非 SQLSTATE 500
        if (!isset(Db::tableColumns('sessions')['ip'])) {
            wstat_err(
                'sessions 表缺少 ip 列。请执行升级：mysql -uroot -p <库名> < sql/upgrade-2026-09-09-sessions-ip.sql（路径以部署目录为准）。',
                200, 1
            );
            return;
        }

        $where = "site_id=? AND start_ts>=? AND start_ts<? AND ip<>''";
        $args = [$sid, $startTs, $endTs];
        if ($q !== '') {
            // IP 前缀匹配（112.10. 可框定网段，112.10.3.4 精确到点分前缀）
            $where .= " AND ip LIKE ? ESCAPE '\\\\'";
            $args[] = $this->likeEscape($q) . '%';
        }

        $total = (int) Db::value("SELECT COUNT(DISTINCT ip) FROM sessions WHERE $where", $args);
        $rows = Db::select(
            "SELECT ip,
                    MAX(country) country, MAX(province) province, MAX(city) city,
                    COUNT(*) sessions, COALESCE(SUM(pageviews),0) pv,
                    COUNT(DISTINCT visitor_id) visitors,
                    COALESCE(ROUND(AVG(duration)),0) avg_duration,
                    MIN(start_ts) first_ts, MAX(start_ts) last_ts
             FROM sessions WHERE $where
             GROUP BY ip
             ORDER BY last_ts DESC
             LIMIT " . (($page - 1) * $size) . ",$size",
            $args
        );

        if ($rows) {
            $ips = array_map('strval', array_column($rows, 'ip'));
            $ph = implode(',', array_fill(0, count($ips), '?'));

            // 渠道构成（会话口径）
            $mix = [];
            foreach (
                Db::select(
                    "SELECT ip, source, COUNT(*) n FROM sessions
                     WHERE site_id=? AND start_ts>=? AND start_ts<? AND ip IN ($ph)
                     GROUP BY ip, source",
                    array_merge([$sid, $startTs, $endTs], $ips)
                ) as $r
            ) {
                $mix[(string) $r['ip']][(string) $r['source']] = (int) $r['n'];
            }

            // 主要来路主机（事件口径；老库无 ref_host 列时跳过）
            $refs = [];
            if (isset(Db::tableColumns('events')['ref_host'])) {
                foreach (
                    Db::select(
                        "SELECT ip, ref_host, COUNT(*) n FROM events
                         WHERE site_id=? AND `day` BETWEEN ? AND ? AND type='pageview'
                           AND ip IN ($ph) AND ref_host<>''
                         GROUP BY ip, ref_host ORDER BY n DESC LIMIT 500",
                        array_merge([$sid, $start, $end], $ips)
                    ) as $r
                ) {
                    $rip = (string) $r['ip'];
                    $refs[$rip][(string) $r['ref_host']] = ($refs[$rip][(string) $r['ref_host']] ?? 0) + (int) $r['n'];
                }
            }

            foreach ($rows as &$row) {
                $ip = (string) $row['ip'];
                $srcMix = $mix[$ip] ?? [];
                arsort($srcMix);
                $row['source_mix'] = $srcMix;
                $refMap = $refs[$ip] ?? [];
                arsort($refMap);
                $topRef = array_slice($refMap, 0, 1, true);
                $row['top_ref_host'] = $topRef ? (string) array_key_first($topRef) : '';
                $row['top_ref_pv'] = $topRef ? (int) reset($topRef) : 0;
            }
            unset($row);
        }

        wstat_json(['items' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /** GET /api/stats/online?site_id=  当前在线访客（近5分钟活跃） */
    public function online(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $sid = (int) $site['id'];
        $now = time();
        $items = [];
        if (!Rds::enabled()) {
            // 无 Redis 模式：在线 = sessions 近 5 分钟活跃（end_ts 随 pageview 刷新）
            $count = self::onlineCountDb($sid, $now);
            $rows = Db::select(
                'SELECT session_id, end_ts, pageviews, exit_url url, entry_url,
                        browser, os, device, country, province, city
                 FROM sessions WHERE site_id=? AND end_ts>=? AND start_ts<=?
                 ORDER BY end_ts DESC LIMIT 50',
                [$sid, $now - 300, $now]
            );
            foreach ($rows as $r) {
                $items[] = [
                    'session_id' => (string) $r['session_id'],
                    'last_seen' => (int) $r['end_ts'],
                    'pageviews' => (int) $r['pageviews'],
                    'url' => (string) ($r['url'] ?: $r['entry_url']),
                    'title' => '',
                    'browser' => (string) $r['browser'],
                    'os' => (string) $r['os'],
                    'device' => (string) $r['device'],
                    'country' => (string) $r['country'],
                    'province' => (string) $r['province'],
                    'city' => (string) $r['city'],
                ];
            }
            wstat_json(['online' => $count, 'items' => $items]);
            return;
        }
        $count = (int) Rds::safe(function ($r) use ($sid, $now, &$items) {
            $min = (string) ($now - 300);
            $cnt = $r->zcount("on:$sid", $min, (string) ($now + 1));
            $tops = $r->zrangebyscore("on:$sid", $min, (string) ($now + 1), true, 50);
            foreach ($tops as $sessId => $score) {
                $ssn = $r->hgetall("ssn:$sessId");
                if (!$ssn) {
                    continue;
                }
                $items[] = [
                    'session_id' => $sessId,
                    'last_seen' => (int) $score,
                    'pageviews' => (int) ($ssn['pv'] ?? 0),
                    'url' => $ssn['url'] ?? '',
                    'title' => $ssn['title'] ?? '',
                    'browser' => $ssn['browser'] ?? '',
                    'os' => $ssn['os'] ?? '',
                    'device' => $ssn['device'] ?? '',
                    'country' => $ssn['country'] ?? '',
                    'province' => $ssn['province'] ?? '',
                    'city' => $ssn['city'] ?? '',
                ];
            }
            return $cnt;
        }, 0);
        wstat_json(['online' => $count, 'items' => $items]);
    }

    /**
     * GET /api/stats/all-sites  全部站点今日/昨日数据对比（跨站点看板）。
     * 口径与单站点概览一致：pv/uv/ip 走 events（events.day 已是站点本地日），
     * visits/bounce/duration/new_users 走 sessions 按站点本地日聚合；在线数走 Redis zset（5 分钟窗口）。
     * 返回 {items:[{site_id,name,domain,role,verified,today,yesterday,online}], total:{today,yesterday}, generated_at}
     */
    public function allSites(Request $req): void
    {
        $u = Auth::requireUser($req);
        $sites = SiteAccess::sitesFor((int) $u['id']);
        $now = time();

        $redis = null;
        try {
            $redis = Rds::get();
        } catch (\Throwable $e) {
            $redis = null;
        }

        $empty = static fn (): array => ['pv' => 0, 'uv' => 0, 'ipc' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
        $fmt = static function (array $r): array {
            $v = (int) $r['visits'];
            return [
                'pv' => (int) $r['pv'],
                'uv' => (int) $r['uv'],
                'ipc' => (int) $r['ipc'],
                'visits' => $v,
                'bounce_rate' => $v > 0 ? round((int) $r['bounce'] / $v * 100, 1) : 0,
                'avg_duration' => $v > 0 ? (int) round((int) $r['duration'] / $v) : 0,
                'new_users' => (int) $r['new_users'],
            ];
        };

        $items = [];
        $sumT = $empty();
        $sumY = $empty();
        foreach ($sites as $s) {
            $sid = (int) $s['id'];
            $tz = (string) $s['timezone'];
            $offset = Util::tzOffsetSec($tz);
            $today = gmdate('Y-m-d', $now + $offset);
            $yest = gmdate('Y-m-d', $now + $offset - 86400);
            [$ts0, $ts1] = Util::dateRangeToTs($yest, $today, $tz);

            // events 按 day 聚合（idx_site_day_type），day 已是站点本地日，两天的量一次查完
            $ev = [];
            foreach (
                Db::select(
                    "SELECT `day`, SUM(type='pageview') pv,
                            COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv,
                            COUNT(DISTINCT CASE WHEN type='pageview' AND ip<>'' THEN ip END) ipc
                     FROM events WHERE site_id=? AND `day` IN (?,?) GROUP BY `day`",
                    [$sid, $yest, $today]
                ) as $r
            ) {
                $ev[(string) $r['day']] = $r;
            }

            // sessions 按站点本地日聚合（idx_site_start 范围扫描，昨天 0 点 ~ 明天 0 点）
            $se = [];
            foreach (
                Db::select(
                    "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d, COUNT(*) c,
                            COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                            COALESCE(SUM(is_new),0) nw
                     FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                    [$offset, $sid, $ts0, $ts1]
                ) as $r
            ) {
                $se[(string) $r['d']] = $r;
            }

            $mk = static function (string $d) use ($ev, $se): array {
                $e = $ev[$d] ?? null;
                $s = $se[$d] ?? null;
                return [
                    'pv' => (int) ($e['pv'] ?? 0),
                    'uv' => (int) ($e['uv'] ?? 0),
                    'ipc' => (int) ($e['ipc'] ?? 0),
                    'visits' => (int) ($s['c'] ?? 0),
                    'bounce' => (int) ($s['b'] ?? 0),
                    'duration' => (int) ($s['du'] ?? 0),
                    'new_users' => (int) ($s['nw'] ?? 0),
                ];
            };
            $t = $mk($today);
            $y = $mk($yest);

            // 实时在线（5 分钟窗口）：Redis 模式走 zset；无 Redis 模式走 sessions 近 5 分钟活跃
            if ($redis !== null) {
                $online = (int) Rds::safe(static function ($r) use ($sid, $now) {
                    return $r->zcount("on:$sid", (string) ($now - 300), (string) ($now + 1));
                }, 0);
            } else {
                $online = self::onlineCountDb($sid, $now);
            }

            $items[] = [
                'site_id' => $sid,
                'name' => (string) $s['name'],
                'domain' => (string) $s['domain'],
                'role' => (string) ($s['role'] ?? ''),
                'verified' => (int) ($s['verified_at'] ?? 0) > 0,
                'today' => $fmt($t),
                'yesterday' => $fmt($y),
                'online' => $online,
            ];
            foreach (['pv', 'uv', 'ipc', 'visits', 'bounce', 'duration', 'new_users'] as $k) {
                $sumT[$k] += (int) $t[$k];
                $sumY[$k] += (int) $y[$k];
            }
        }

        // 默认按今日 PV 降序（前端可再排序）
        usort($items, static fn ($a, $b) => $b['today']['pv'] <=> $a['today']['pv']);

        wstat_json([
            'items' => $items,
            'total' => ['today' => $fmt($sumT), 'yesterday' => $fmt($sumY)],
            'generated_at' => $now,
        ]);
    }

    /**
     * GET /api/stats/auto-events?site_id=&start=&end=  自动事件 TOP（出链/下载/站内搜索）。
     * 目标信息在 payload JSON（$.to / $.file / $.term），按其聚合；无数据返回空数组。
     */
    public function autoEvents(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        $sid = (int) $site['id'];

        $agg = static function (string $type, string $field) use ($sid, $start, $end): array {
            $rows = Db::select(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) u,
                        COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events
                 WHERE site_id=? AND type=? AND `day` BETWEEN ? AND ? AND payload IS NOT NULL
                 GROUP BY u HAVING u IS NOT NULL AND u <> ''
                 ORDER BY pv DESC LIMIT 50",
                ["\$.{$field}", $sid, $type, $start, $end]
            );
            foreach ($rows as &$r) {
                $r['pv'] = (int) $r['pv'];
                $r['uv'] = (int) $r['uv'];
            }
            unset($r);
            return $rows;
        };

        wstat_json([
            'outlinks' => $agg('outlink', 'to'),
            'downloads' => $agg('download', 'file'),
            'searches' => $agg('search', 'term'),
        ]);
    }

    /* ================= 内部 ================= */

    /** Web Vitals 指标清单（payload JSON 字段，前端同一顺序渲染） */
    private const VITALS = ['ttfb', 'fcp', 'lcp', 'inp', 'cls'];

    /** GET /api/stats/performance?site_id=&start=&end=  页面性能（perf 事件 payload 聚合） */
    public function performance(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $w = 'site_id=? AND type="perf" AND `day` BETWEEN ? AND ?';
        $args = [$sid, $start, $end];

        $avgExpr = function (string $m): string {
            return "ROUND(AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'\$." . $m . "')) AS DECIMAL(12,3))),3) AS `" . $m . '`';
        };
        $avgs = implode(', ', array_map($avgExpr, self::VITALS));

        // 1) 区间汇总（不含分组的全体平均）
        $row = Db::first(
            "SELECT COUNT(*) AS samples, $avgs FROM events
             WHERE $w AND payload IS NOT NULL AND JSON_VALID(payload)",
            $args
        );
        $totals = ['samples' => (int) ($row['samples'] ?? 0)];
        foreach (self::VITALS as $m) {
            $totals[$m] = ($row[$m] ?? null) === null ? null : round((float) $row[$m], 3);
        }

        // 2) 页面 TOP（按样本数倒序；仅含至少一次有效 LCP 的页面更贴近 LCP 排行榜）
        $pages = Db::select(
            "SELECT url, COUNT(*) AS samples, $avgs FROM events
             WHERE $w AND payload IS NOT NULL AND JSON_VALID(payload)
             GROUP BY url ORDER BY samples DESC LIMIT 40",
            $args
        );
        foreach ($pages as &$p) {
            foreach (self::VITALS as $m) {
                $p[$m] = ($p[$m] ?? null) === null ? null : round((float) $p[$m], 3);
            }
        }
        unset($p);

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'totals' => $totals,
            'pages' => $pages,
        ]);
    }

    /** GET /api/stats/realtime?site_id=  实时访客（进行中会话 + 在线 + 今日实时） */
    public function realtime(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];
        $offset = Util::tzOffsetSec($tz);
        $now = time();
        $out = [
            'now' => $now,
            'today' => ['pv' => 0, 'uv' => 0, 'ipc' => 0, 'series' => []],
            'online' => 0,
            'list' => ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 10, 'day' => gmdate('Y-m-d', $now + $offset)],
        ];

        $redis = Rds::get();
        if ($redis === null) {
            // 无 Redis 模式：今日实时 + 在线 + 60 分钟折线全部走数据库；
            // 访客列表（下方 DB 查询）照常工作，仅无「进行中会话」合并
            $live = $this->liveFromDb($sid, $offset);
            $out['online'] = $live['online'];
            $out['today']['pv'] = $live['pv'];
            $out['today']['uv'] = $live['uv'];
            $out['today']['ipc'] = $live['ipc'];
            $out['today']['series'] = $live['series'];
        }
        // Redis 直读段只在该分支里做：无 Redis 时 $redis 为 null，绝不能在 null 上调方法 ——
        // 此前这里的异常会被下方大 try 的 catch 吞掉，连带把「访客列表」整段 DB 查询一起跳过，
        // 表现为无 Redis 模式下实时访客页永远空列表（2026-09-15 修复）。
        if ($redis !== null) {
            try {
                $ymd = gmdate('Ymd', $now + $offset);
                $out['online'] = $redis->zcount("on:$sid", (string) ($now - 300), (string) ($now + 1));
                $out['today']['pv'] = (int) $redis->hget("today:$sid", $ymd . ':pv');
                // UV / 独立 IP 一律精确（events 去重），不再用 HyperLogLog 估算：
                // 与概览、IP 地域页、大屏同一口径，Redis 与无 Redis 两种部署形态数字完全相同
                $t = $this->todayExact($sid, gmdate('Y-m-d', $now + $offset));
                $out['today']['uv'] = $t['uv'];
                $out['today']['ipc'] = $t['ipc'];

                // 近 60 分钟 PV 折线
                $fields = [];
                for ($i = 59; $i >= 0; $i--) {
                    $fields[] = gmdate('YmdHi', $now - $i * 60 + $offset);
                }
                $vals = $redis->hmget("rt:min:$sid", $fields);
                if (is_array($vals)) {
                    foreach ($fields as $i => $f) {
                        $out['today']['series'][] = [
                            'min' => substr($f, -4, 2) . ':' . substr($f, -2),
                            'pv' => (int) ($vals[$i] ?? 0),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Redis 瞬时异常：退回数据库口径（直写模式下 events/sessions 本就是唯一数据源）
                try {
                    $live = $this->liveFromDb($sid, $offset);
                    $out['online'] = $live['online'];
                    $out['today'] = ['pv' => $live['pv'], 'uv' => $live['uv'], 'ipc' => $live['ipc'], 'series' => $live['series']];
                } catch (\Throwable $e2) {
                    /* 保持初始空值 */
                }
            }
        }

        try {
            // ---- 访客列表：所选日期的会话（DB 已落库） + Redis 进行中会话合并 ----
            $day = $this->ymd((string) $req->input('day', gmdate('Y-m-d', $now + $offset)));
            if ($day === '') {
                wstat_err('日期格式应为 YYYY-MM-DD', 422);
            }
            $scope = (string) $req->input('scope', 'all');           // all | paid（推广流量）
            $ipF = trim((string) $req->input('ip', ''));
            $srcF = trim((string) $req->input('source', ''));
            $devF = trim((string) $req->input('device', ''));
            $brF = trim((string) $req->input('browser', ''));
            $page = max(1, (int) $req->input('page', 1));
            $pageSize = min(50, max(5, (int) $req->input('page_size', 10)));

            [$ts0, $ts1] = Util::dateRangeToTs($day, $day, $tz);
            $w = 'site_id=? AND start_ts>=? AND start_ts<?';
            $bind = [$sid, $ts0, $ts1];
            if ($scope === 'paid') {
                $w .= " AND (click_id<>'' OR source IN ('paid','utm') OR medium IN ('cpc','ppc','paid'))";
            }
            if ($ipF !== '') {
                $w .= ' AND ip LIKE ?';
                $bind[] = $this->likeEscape($ipF) . '%';
            }
            if ($srcF !== '') {
                $w .= ' AND source=?';
                $bind[] = $srcF;
            }
            if ($devF !== '') {
                $w .= ' AND device=?';
                $bind[] = $devF;
            }
            if ($brF !== '') {
                $w .= ' AND browser LIKE ?';
                $bind[] = $this->likeEscape($brF) . '%';
            }
            $rows = Db::select(
                "SELECT id,session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                        entry_url,exit_url,source,medium,campaign,click_id,
                        browser,os,device,screen,lang,ip,country,province,city
                 FROM sessions WHERE $w ORDER BY start_ts DESC LIMIT 5000",
                $bind
            );

            // Redis 进行中会话（未落库/未关闭）：仅所选日期当天，且按 session_id 去重（以 Redis 为准）
            $isToday = ($day === gmdate('Y-m-d', $now + $offset));
            $liveMap = [];
            if ($isToday && $redis !== null) {
                foreach (Sessionizer::liveRows($redis, $sid, max($ts0, $now - 2 * 86400), $now + 1) as $lr) {
                    if ((int) ($lr['start_ts'] ?? 0) < $ts0) {
                        continue;
                    }
                    $liveMap[(string) ($lr['session_id'] ?? '')] = $lr;
                }
            }
            $merged = [];
            $seen = [];
            foreach ($liveMap as $lsid => $lr) {
                $seen[$lsid] = true;
                $lr['duration'] = max(0, $now - (int) ($lr['start_ts'] ?? $now));
                $merged[] = $lr;
            }
            foreach ($rows as $r) {
                if (isset($seen[(string) $r['session_id']])) {
                    continue;
                }
                $merged[] = $r;
            }
            usort($merged, fn ($a, $b) => (int) ($b['start_ts'] ?? 0) - (int) ($a['start_ts'] ?? 0));

            // 应用端过滤（Redis 行无法走 SQL）：scope/ip/source/device/browser
            $merged = array_values(array_filter($merged, function ($r) use ($scope, $ipF, $srcF, $devF, $brF) {
                if ($scope === 'paid'
                    && (string) ($r['click_id'] ?? '') === ''
                    && !in_array((string) ($r['source'] ?? ''), ['paid', 'utm'], true)
                    && !in_array((string) ($r['medium'] ?? ''), ['cpc', 'ppc', 'paid'], true)) {
                    return false;
                }
                if ($ipF !== '' && !str_starts_with((string) ($r['ip'] ?? ''), $ipF)) {
                    return false;
                }
                if ($srcF !== '' && (string) ($r['source'] ?? '') !== $srcF) {
                    return false;
                }
                if ($devF !== '' && (string) ($r['device'] ?? '') !== $devF) {
                    return false;
                }
                if ($brF !== '' && stripos((string) ($r['browser'] ?? ''), $brF) !== 0) {
                    return false;
                }
                return true;
            }));
            $total = count($merged);
            $slice = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

            // 补充来源域名（events.ref_host，仅当前页行，一次批量查询）
            $refMap = [];
            $sids = array_values(array_filter(array_map(fn ($r) => (string) ($r['session_id'] ?? ''), $slice)));
            if (!empty($sids)) {
                $ph = implode(',', array_fill(0, count($sids), '?'));
                foreach (
                    Db::select(
                        "SELECT session_id, MIN(ref_host) ref_host FROM events
                         WHERE site_id=? AND session_id IN ($ph) AND ref_host<>'' GROUP BY session_id",
                        array_merge([$sid], $sids)
                    ) as $er
                ) {
                    $refMap[(string) $er['session_id']] = (string) $er['ref_host'];
                }
            }

            foreach ($slice as $i => $row) {
                $start = (int) ($row['start_ts'] ?? 0);
                $last = (int) ($row['end_ts'] ?? 0);
                $isLive = isset($liveMap[(string) ($row['session_id'] ?? '')]);
                $out['list']['items'][] = [
                    'id' => $isLive ? -(200000 + $i) : (int) ($row['id'] ?? 0),
                    'session_id' => (string) ($row['session_id'] ?? ''),
                    'visitor_id' => (string) ($row['visitor_id'] ?? ''),
                    'start_ts' => $start,
                    'last_ts' => $last,
                    'duration' => $isLive ? (int) $row['duration'] : (int) ($row['duration'] ?? 0),
                    'pageviews' => (int) ($row['pageviews'] ?? 1),
                    'entry_url' => (string) ($row['entry_url'] ?? ''),
                    'exit_url' => (string) ($row['exit_url'] ?? ''),
                    'source' => (string) ($row['source'] ?? 'direct'),
                    'medium' => (string) ($row['medium'] ?? ''),
                    'campaign' => (string) ($row['campaign'] ?? ''),
                    'click_id' => (string) ($row['click_id'] ?? ''),
                    'ref_host' => $refMap[(string) ($row['session_id'] ?? '')] ?? '',
                    'browser' => (string) ($row['browser'] ?? ''),
                    'os' => (string) ($row['os'] ?? ''),
                    'device' => (string) ($row['device'] ?? ''),
                    'screen' => (string) ($row['screen'] ?? ''),
                    'lang' => (string) ($row['lang'] ?? ''),
                    'ip' => (string) ($row['ip'] ?? ''),
                    'country' => (string) ($row['country'] ?? ''),
                    'province' => (string) ($row['province'] ?? ''),
                    'city' => (string) ($row['city'] ?? ''),
                    'online' => $isToday && $last > 0 && ($now - $last) <= 300,
                    'live' => $isLive,
                ];
            }
            $out['list']['total'] = $total;
            $out['list']['page'] = $page;
            $out['list']['page_size'] = $pageSize;
            $out['list']['day'] = $day;
        } catch (\Throwable $e) {
            /* Redis/DB 瞬时异常按空数据返回 */
        }
        wstat_json($out);
    }

    /** GET /api/stats/geo?site_id=&start=&end=  IP 地理分布（会话表聚合：国家 / 省份） */
    public function geo(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $w = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [$sid, $startTs, $endTs];

        $total = (int) Db::value("SELECT COUNT(*) FROM sessions WHERE $w", $args);
        $geoVisits = (int) Db::value("SELECT COUNT(*) FROM sessions WHERE $w AND country<>''", $args);

        // 国家 TOP（省份维度独立下钻，避免混合分组）
        $countries = Db::select(
            "SELECT country, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS uv,
                    COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(bounce),0) AS bounce
             FROM sessions WHERE $w AND country<>''
             GROUP BY country ORDER BY visits DESC LIMIT 40",
            $args
        );

        // 中国省份 TOP
        $provinces = Db::select(
            "SELECT province, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS uv,
                    COALESCE(SUM(pageviews),0) AS pv
             FROM sessions WHERE $w AND country IN ('中国','China') AND province<>''
             GROUP BY province ORDER BY visits DESC LIMIT 40",
            $args
        );

        // IP TOP（按 PV 降序，供「IP 统计」页签）
        // 2026-09-15 变更：改由 events（pageview）按天聚合 —— 与概览「IP 数」同源、同区间、同口径，
        // 概览 IP 数与这里的 IP 条数因此严格一致（旧实现取 sessions，会话尚未结算或跨零点会让两者对不上）。
        //   pv = 该 IP 的页面浏览数；uv = 去重访客数；visits = 去重会话数
        $ips = Db::select(
            "SELECT ip, COUNT(DISTINCT session_id) AS visits, COUNT(DISTINCT visitor_id) AS uv,
                    COUNT(*) AS pv
             FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? AND ip<>''
             GROUP BY ip ORDER BY pv DESC, visits DESC LIMIT 100",
            [$sid, $start, $end]
        );
        // 区间独立 IP 总数（精确，与概览 ipc 完全一致；IP 列表受 LIMIT 100 截断时以本值为准）
        $ipTotal = $this->exactUniq($sid, $start, $end)['ipc'];

        // 诊断：驱动/离线库状态 + 区间内地理字段覆盖率（前端据此给出「为什么没有地域数据」的具体原因）
        $st = IpLocator::status();
        $evTotal = (int) Db::value(
            'SELECT COUNT(*) FROM events WHERE site_id=? AND `day` BETWEEN ? AND ?',
            [$sid, $start, $end]
        );
        $evGeo = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND `day` BETWEEN ? AND ? AND country<>''",
            [$sid, $start, $end]
        );
        // IPv6 访客单独计数：地域库分 v4/v6 两份，只有分开看才能定位「是 v6 库缺了」
        // 还是「整体都没解析」（ip 里有冒号即为 IPv6，含 ::ffff: 映射形式）
        $evIpv6 = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND `day` BETWEEN ? AND ? AND ip LIKE '%:%'",
            [$sid, $start, $end]
        );
        $evIpv6Geo = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND `day` BETWEEN ? AND ? AND ip LIKE '%:%' AND country<>''",
            [$sid, $start, $end]
        );

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'total_visits' => $total,
            'geo_visits' => $geoVisits,
            'ip_total' => $ipTotal,
            'countries' => $countries,
            'provinces' => $provinces,
            'ips' => $ips,
            'geo_status' => [
                'driver' => $st['effective'],
                'configured' => $st['configured'],
                'driver_v4' => $st['effective_v4'],
                'driver_v6' => $st['effective_v6'],
                'xdb_exists' => $st['xdb_exists'],
                'xdb_size' => $st['xdb_size'],
                'xdb_path' => $st['xdb_path'],
                'xdb6_exists' => $st['xdb6_exists'],
                'xdb6_size' => $st['xdb6_size'],
                'xdb6_path' => $st['xdb6_path'],
                'xdb_candidates' => array_map(fn ($c) => $c['file'], $st['xdb_candidates']),
                'events_total' => $evTotal,
                'events_geo' => $evGeo,
                'events_ipv6' => $evIpv6,
                'events_ipv6_geo' => $evIpv6Geo,
                'sessions_total' => $total,
                'sessions_geo' => $geoVisits,
            ],
        ]);
    }

    /**
     * GET /api/stats/analysis?site_id=&start=&end=
     * 页面分析：路径 / 入口URL / 退出URL / 来源域名 / 渠道 / 环境 / 位置（TOP50，含 PV 与百分比）
     * 口径：页面级维度走 events pageview（day 索引）；入口/退出走 sessions（SUM(pageviews)）。
     */
    public function analysis(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];

        $totalPv = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        );
        $pct = static fn (int $pv): float => $totalPv > 0 ? round($pv / $totalPv * 100, 2) : 0.0;

        // events 维度聚合（pageview 事件，idx_site_day_type 索引；$col 为内部白名单调用，无注入面）
        $ev = function (string $col, string $cond = '') use ($sid, $start, $end, $pct): array {
            $rows = Db::select(
                "SELECT `$col` AS name, COUNT(*) AS pv
                 FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $cond
                 GROUP BY `$col` ORDER BY pv DESC LIMIT 50",
                [$sid, $start, $end]
            );
            $out = [];
            foreach ($rows as $r) {
                $name = (string) $r['name'];
                if ($name === '') {
                    $name = '-';
                }
                $pv = (int) $r['pv'];
                $out[] = ['name' => $name, 'pv' => $pv, 'pct' => $pct($pv)];
            }
            return $out;
        };

        // sessions 维度（入口/退出 URL：PV=SUM(pageviews)，idx_site_start 索引）
        $sess = function (string $col) use ($sid, $startTs, $endTs, $pct): array {
            $rows = Db::select(
                "SELECT `$col` AS name, COALESCE(SUM(pageviews),0) AS pv
                 FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? AND `$col`<>''
                 GROUP BY `$col` ORDER BY pv DESC LIMIT 50",
                [$sid, $startTs, $endTs]
            );
            $out = [];
            foreach ($rows as $r) {
                $pv = (int) $r['pv'];
                $out[] = ['name' => (string) $r['name'], 'pv' => $pv, 'pct' => $pct($pv)];
            }
            return $out;
        };

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'total_pv' => $totalPv,
            'pages' => $ev('url'),
            'entries' => $sess('entry_url'),
            'exits' => $sess('exit_url'),
            'referrers' => $ev('ref_host', "AND ref_host<>''"),
            'channels' => $ev('source'),
            'browsers' => $ev('browser', "AND browser<>''"),
            'oses' => $ev('os', "AND os<>''"),
            'devices' => $ev('device', "AND device<>''"),
            'countries' => $ev('country', "AND country<>''"),
            'provinces' => $ev('province', "AND province<>'' AND country IN ('中国','China')"),
            'cities' => $ev('city', "AND city<>''"),
        ]);
    }

    /* ==================== 单页面（URL）下钻详情 ==================== */

    /** 流向分析（上一页/下一页）允许扫描的页面 PV 上限，超过则标记 partial */
    private const PAGE_FLOW_MAX = 50000;
    /** 参与会话指标聚合的最大会话数（derived table LIMIT） */
    private const PAGE_SESS_MAX = 20000;

    /**
     * GET /api/stats/page?site_id=&url=&match=exact|prefix&start=&end=
     *
     * 单页面深度分析：汇总指标 / 按天与按小时趋势 / 来源构成 / 环境与地域 /
     * 上一页与下一页流向 / 页面自定义事件与点击 / 最近会话。
     *
     * 匹配口径：默认 exact 精确匹配完整 URL；prefix 为前缀匹配。
     * 两者都同时对「完整 url」与「URL 路径」生效，兼容 SDK 上报完整 URL 与只存路径两种情形。
     * 大量数据下流向分析与会话指标为主采样（partial=true），其余指标为精确值。
     */
    public function pageDetail(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];
        $offset = Util::tzOffsetSec($tz);

        $url = trim((string) $req->input('url', ''));
        if ($url === '') {
            wstat_err('缺少页面地址参数 url', 422);
        }
        $match = (string) $req->input('match', 'exact');
        if (!in_array($match, ['exact', 'prefix'], true)) {
            $match = 'exact';
        }

        // 匹配条件（对完整 URL 与路径表达式双匹配）；SQL 片段与绑定值成对出现
        $pathExpr = self::URL_PATH_EXPR;
        if ($match === 'prefix') {
            $pat = $this->likeEscape($url) . '%';
            $condSql = "AND (url LIKE ? ESCAPE '\\\\' OR $pathExpr LIKE ? ESCAPE '\\\\')";
            $condArgs = [$pat, $pat];
        } else {
            $condSql = "AND (url = ? OR $pathExpr = ?)";
            $condArgs = [$url, $url];
        }

        $base = [$sid, $start, $end, ...$condArgs];

        // ---- 1) 汇总 ----
        $sum = Db::first(
            "SELECT COUNT(*) pv,
                    COUNT(DISTINCT visitor_id) uv,
                    COUNT(DISTINCT ip) ipc,
                    COUNT(DISTINCT session_id) sess,
                    MIN(ts) first_ts, MAX(ts) last_ts
             FROM events
             WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql",
            $base
        ) ?: [];
        $pv = (int) ($sum['pv'] ?? 0);

        // 页面标题（访问量最高的那个）
        $title = (string) (Db::value(
            "SELECT title FROM events
             WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql AND title<>''
             GROUP BY title ORDER BY COUNT(*) DESC LIMIT 1",
            $base
        ) ?? '');

        // ---- 2) 会话侧指标（停留时长 / 跳出率）----
        $sessAgg = Db::first(
            "SELECT COUNT(*) c, COALESCE(SUM(s.bounce),0) b, COALESCE(SUM(s.duration),0) du
             FROM sessions s
             JOIN (SELECT DISTINCT session_id, site_id FROM events
                   WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql
                   LIMIT " . self::PAGE_SESS_MAX . ") x
               ON x.session_id=s.session_id AND x.site_id=s.site_id
             WHERE s.site_id=? AND s.start_ts>=? AND s.start_ts<?",
            [...$base, $sid, $startTs, $endTs]
        ) ?: ['c' => 0, 'b' => 0, 'du' => 0];
        $sessCnt = (int) $sessAgg['c'];
        $avgDur = $sessCnt > 0 ? round((int) $sessAgg['du'] / $sessCnt, 1) : 0;
        $bounceRate = $sessCnt > 0 ? round((int) $sessAgg['b'] / $sessCnt * 100, 2) : 0;

        // 作为入口页 / 退出页的次数（会话聚合表，精确）
        $entryCond = $match === 'prefix'
            ? 'entry_url LIKE ? ESCAPE \'\\\\\''
            : 'entry_url = ?';
        $exitCond = $match === 'prefix'
            ? 'exit_url LIKE ? ESCAPE \'\\\\\''
            : 'exit_url = ?';
        $entryVal = $match === 'prefix' ? $this->likeEscape($url) . '%' : $url;
        $entryCnt = (int) Db::value(
            "SELECT COUNT(*) FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? AND $entryCond",
            [$sid, $startTs, $endTs, $entryVal]
        );
        $exitCnt = (int) Db::value(
            "SELECT COUNT(*) FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? AND $exitCond",
            [$sid, $startTs, $endTs, $entryVal]
        );

        // ---- 3) 趋势：按天 / 按小时 ----
        $trend = [];
        foreach (
            Db::select(
                "SELECT `day`, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql
                 GROUP BY `day` ORDER BY `day`",
                $base
            ) as $r
        ) {
            $trend[] = ['day' => (string) $r['day'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
        }
        $hourly = [];
        foreach (
            Db::select(
                "SELECT HOUR(FROM_UNIXTIME(ts+?)) h, COUNT(*) pv
                 FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql
                 GROUP BY h ORDER BY h",
                [$offset, ...$base]
            ) as $r
        ) {
            $hourly[] = ['hour' => (int) $r['h'], 'pv' => (int) $r['pv']];
        }

        // ---- 4) 维度分布（复用统一聚合） ----
        $dim = function (string $col, string $extra = '') use ($base, $condSql): array {
            $out = [];
            foreach (
                Db::select(
                    "SELECT `$col` AS name, COUNT(*) pv FROM events
                     WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql $extra
                     GROUP BY `$col` ORDER BY pv DESC LIMIT 30",
                    $base
                ) as $r
            ) {
                $out[] = ['name' => (string) $r['name'], 'pv' => (int) $r['pv']];
            }
            return $out;
        };
        $referrers = $dim('ref_host', "AND ref_host<>''");
        $channels = $dim('source', "AND source<>''");
        $devices = $dim('device', "AND device<>''");
        $browsers = $dim('browser', "AND browser<>''");
        $oses = $dim('os', "AND os<>''");
        $countries = $dim('country', "AND country<>''");
        $provinces = $dim('province', "AND province<>'' AND country IN ('中国','China')");
        $screens = $dim('screen', "AND screen<>''");
        $langs = $dim('lang', "AND lang<>''");
        $utmCampaigns = $dim('campaign', "AND campaign<>''");

        // ---- 5) 页面事件与点击 ----
        $events = [];
        foreach (
            Db::select(
                "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.n')),'(未命名)') AS name,
                        COUNT(*) c, COUNT(DISTINCT visitor_id) uv
                 FROM events
                 WHERE site_id=? AND type='event' AND `day` BETWEEN ? AND ? $condSql
                 GROUP BY name ORDER BY c DESC LIMIT 30",
                $base
            ) as $r
        ) {
            $events[] = ['name' => (string) $r['name'], 'count' => (int) $r['c'], 'uv' => (int) $r['uv']];
        }
        $clicks = (int) Db::value(
            "SELECT COUNT(*) FROM events
             WHERE site_id=? AND type='click' AND `day` BETWEEN ? AND ? $condSql",
            $base
        );
        $scrollDepth = Db::first(
            "SELECT ROUND(AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.d')) AS DECIMAL(6,2))),1) d
             FROM events
             WHERE site_id=? AND type='scroll' AND `day` BETWEEN ? AND ? $condSql",
            $base
        );
        $avgScroll = isset($scrollDepth['d']) && $scrollDepth['d'] !== null ? (float) $scrollDepth['d'] : null;

        // ---- 6) 流向（上一页 / 下一页）：以会话为单位，锚定本页首次出现时间 ----
        $flow = function (string $dir) use ($sid, $start, $end, $condSql, $condArgs): array {
            $cmp = $dir === 'next' ? '>' : '<';
            $sql = "SELECT e2.url AS url, COUNT(*) c
                    FROM (SELECT e1.session_id, " . ($dir === 'next' ? 'MIN' : 'MAX') . "(e1.ts) t0
                          FROM events e1
                          WHERE e1.site_id=? AND e1.type='pageview' AND e1.day BETWEEN ? AND ? $condSql
                          GROUP BY e1.session_id LIMIT " . self::PAGE_SESS_MAX . ") x
                    JOIN events e2
                      ON e2.session_id=x.session_id AND e2.site_id=? AND e2.type='pageview'
                     AND e2.ts $cmp x.t0 AND e2.url<>''
                    GROUP BY e2.url ORDER BY c DESC LIMIT 12";
            $rows = [];
            foreach (Db::select($sql, [$sid, $start, $end, ...$condArgs, $sid]) as $r) {
                $rows[] = ['url' => (string) $r['url'], 'count' => (int) $r['c']];
            }
            return $rows;
        };
        $partial = $pv > self::PAGE_FLOW_MAX;
        $nextPages = $flow('next');
        $prevPages = $flow('prev');

        // ---- 7) 最近会话（含该页的会话明细）----
        $ipCol = isset(Db::tableColumns('sessions')['ip']) ? ',s.ip' : '';
        $recentSessions = Db::select(
            "SELECT s.id,s.session_id,s.visitor_id,s.start_ts,s.end_ts,s.pageviews,s.duration,
                    s.bounce,s.is_new,s.entry_url,s.exit_url,s.source,s.browser,s.os,s.device,
                    s.country,s.province,s.city$ipCol
             FROM sessions s
             JOIN (SELECT DISTINCT session_id, site_id FROM events
                   WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $condSql
                   ORDER BY ts DESC LIMIT 200) x
               ON x.session_id=s.session_id AND x.site_id=s.site_id
             ORDER BY s.start_ts DESC LIMIT 20",
            [...$base]
        );

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'url' => $url,
            'match' => $match,
            'title' => $title,
            'partial' => $partial,
            'summary' => [
                'pv' => $pv,
                'uv' => (int) ($sum['uv'] ?? 0),
                'ipc' => (int) ($sum['ipc'] ?? 0),
                'sessions' => (int) ($sum['sess'] ?? 0),
                'entries' => $entryCnt,
                'exits' => $exitCnt,
                'avg_duration' => $avgDur,
                'bounce_rate' => $bounceRate,
                'pv_per_visit' => $sessCnt > 0 ? round($pv / $sessCnt, 2) : 0,
                'clicks' => $clicks,
                'avg_scroll' => $avgScroll,
                'first_ts' => (int) ($sum['first_ts'] ?? 0),
                'last_ts' => (int) ($sum['last_ts'] ?? 0),
            ],
            'trend' => $trend,
            'hourly' => $hourly,
            'referrers' => $referrers,
            'channels' => $channels,
            'devices' => $devices,
            'browsers' => $browsers,
            'oses' => $oses,
            'countries' => $countries,
            'provinces' => $provinces,
            'screens' => $screens,
            'langs' => $langs,
            'campaigns' => $utmCampaigns,
            'events' => $events,
            'next_pages' => $nextPages,
            'prev_pages' => $prevPages,
            'recent_sessions' => $recentSessions,
        ]);
    }

    /**
     * GET /api/stats/audience?site_id=&start=&end=
     * 访问分析（访问时长/深度分布、新老访问、跳出、平均时长/深度）
     * + 访客分析（新/回访访客、访问频次分布、人均访问次数/人均PV）
     * 数据源：sessions（idx_site_start 索引），全部按区间聚合。
     */
    public function audience(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $w = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [$sid, $startTs, $endTs];

        // ---- 访问总量指标 ----
        $tot = Db::first(
            "SELECT COUNT(*) AS visits, COALESCE(SUM(is_new),0) AS new_visits,
                    COALESCE(SUM(bounce),0) AS bounce, COALESCE(AVG(duration),0) AS avg_dur,
                    COALESCE(AVG(pageviews),0) AS avg_pv, COALESCE(SUM(pageviews),0) AS pv,
                    COUNT(DISTINCT visitor_id) AS uv
             FROM sessions WHERE $w",
            $args
        ) ?: ['visits' => 0, 'new_visits' => 0, 'bounce' => 0, 'avg_dur' => 0, 'avg_pv' => 0, 'pv' => 0, 'uv' => 0];

        $bucketize = function (array $rows): array {
            $map = [];
            foreach ($rows as $r) {
                $map[(string) $r['k']] = (int) $r['c'];
            }
            return $map;
        };

        // ---- 访问时长分布 ----
        $durMap = $bucketize(Db::select(
            "SELECT CASE
                        WHEN duration <= 10 THEN 'd0_10'
                        WHEN duration <= 30 THEN 'd11_30'
                        WHEN duration <= 60 THEN 'd31_60'
                        WHEN duration <= 180 THEN 'd1_3'
                        WHEN duration <= 600 THEN 'd3_10'
                        ELSE 'd10m' END AS k, COUNT(*) AS c
             FROM sessions WHERE $w GROUP BY k",
            $args
        ));

        // ---- 访问深度分布（每次访问 PV 数） ----
        $depthMap = $bucketize(Db::select(
            "SELECT CASE
                        WHEN pageviews <= 1 THEN 'p1'
                        WHEN pageviews <= 3 THEN 'p2_3'
                        WHEN pageviews <= 6 THEN 'p4_6'
                        WHEN pageviews <= 10 THEN 'p7_10'
                        ELSE 'p10m' END AS k, COUNT(*) AS c
             FROM sessions WHERE $w GROUP BY k",
            $args
        ));

        // ---- 访客维度：访问频次分布（区间内每访客访问次数） ----
        $freqRaw = Db::select(
            "SELECT cnt, COUNT(*) AS c FROM (
                 SELECT visitor_id, COUNT(*) AS cnt
                 FROM sessions WHERE $w GROUP BY visitor_id
             ) t GROUP BY cnt",
            $args
        );
        $freqMap = ['f1' => 0, 'f2' => 0, 'f3_5' => 0, 'f6_10' => 0, 'f10m' => 0];
        foreach ($freqRaw as $r) {
            $n = (int) $r['cnt'];
            $k = $n <= 1 ? 'f1' : ($n === 2 ? 'f2' : ($n <= 5 ? 'f3_5' : ($n <= 10 ? 'f6_10' : 'f10m')));
            $freqMap[$k] += (int) $r['c'];
        }

        // ---- 访问维度：入口/出口页排名 TOP10（访问次数 + PV） ----
        $pageRank = function (string $col) use ($w, $args): array {
            $rows = Db::select(
                "SELECT `$col` AS name, COUNT(*) AS visits, COALESCE(SUM(pageviews),0) AS pv
                 FROM sessions WHERE $w AND `$col`<>''
                 GROUP BY `$col` ORDER BY visits DESC LIMIT 10",
                $args
            );
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['name' => (string) $r['name'], 'visits' => (int) $r['visits'], 'pv' => (int) $r['pv']];
            }
            return $out;
        };

        // ---- 访客维度：地域/浏览器/OS/设备/屏幕/语言 TOP10（按去重访客数） ----
        $dim = function (string $col) use ($w, $args): array {
            $rows = Db::select(
                "SELECT `$col` AS name, COUNT(DISTINCT visitor_id) AS c
                 FROM sessions WHERE $w AND `$col`<>''
                 GROUP BY `$col` ORDER BY c DESC LIMIT 10",
                $args
            );
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['name' => (string) $r['name'], 'c' => (int) $r['c']];
            }
            return $out;
        };
        // 地域：国家+省份组合（country · province），仅统计已归属数据
        $geoRows = Db::select(
            "SELECT CONCAT_WS(' · ', NULLIF(country,''), NULLIF(province,'')) AS name,
                    COUNT(DISTINCT visitor_id) AS c
             FROM sessions WHERE $w AND country<>''
             GROUP BY name ORDER BY c DESC LIMIT 10",
            $args
        );
        $geo = [];
        foreach ($geoRows as $r) {
            $geo[] = ['name' => (string) $r['name'], 'c' => (int) $r['c']];
        }

        $uv = (int) $tot['uv'];
        $visits = (int) $tot['visits'];
        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'totals' => [
                'visits' => $visits,
                'new_visits' => (int) $tot['new_visits'],
                'return_visits' => max(0, $visits - (int) $tot['new_visits']),
                'bounce_rate' => $visits > 0 ? round((int) $tot['bounce'] / $visits * 100, 2) : 0.0,
                'avg_duration' => (int) round((float) $tot['avg_dur']),
                'avg_pv' => round((float) $tot['avg_pv'], 2),
                'pv' => (int) $tot['pv'],
                'uv' => $uv,
                'uv_new' => (int) Db::value(
                    "SELECT COUNT(DISTINCT CASE WHEN is_new=1 THEN visitor_id END) FROM sessions WHERE $w",
                    $args
                ),
                'visits_per_uv' => $uv > 0 ? round($visits / $uv, 2) : 0.0,
                'pv_per_uv' => $uv > 0 ? round((int) $tot['pv'] / $uv, 2) : 0.0,
            ],
            'duration_dist' => $durMap,   // {d0_10, d11_30, d31_60, d1_3, d3_10, d10m}
            'depth_dist' => $depthMap,    // {p1, p2_3, p4_6, p7_10, p10m}
            'freq_dist' => $freqMap,      // {f1, f2, f3_5, f6_10, f10m} 单位=访客数
            'entries' => $pageRank('entry_url'),  // [{name, visits, pv}]
            'exits' => $pageRank('exit_url'),
            'geo' => $geo,                // [{name, c}] 以下均按去重访客数
            'browsers' => $dim('browser'),
            'oses' => $dim('os'),
            'devices' => $dim('device'),
            'screens' => $dim('screen'),
            'langs' => $dim('lang'),
        ]);
    }

    /* ================= 内部 ================= */

    /** 无 Redis（DB 直写）模式：在线访客数（sessions.end_ts 近 5 分钟活跃；end_ts 随 pageview 刷新） */
    public static function onlineCountDb(int $sid, ?int $now = null): int
    {
        $now = $now ?? time();
        return (int) Db::value(
            'SELECT COUNT(*) FROM sessions WHERE site_id=? AND end_ts>=? AND start_ts<=?',
            [$sid, $now - 300, $now]
        );
    }

    /**
     * 无 Redis（DB 直写）模式：今日实时指标（今日 pv/uv/ip + 近 60 分钟 PV 折线 + 在线数）。
     * 与 Redis 实时口径对齐：pv/uv/ip 走 events（day=站点本地今日），折线按分钟桶聚合 pageview。
     */
    private function liveFromDb(int $sid, int $offset): array
    {
        $now = time();
        $today = gmdate('Y-m-d', $now + $offset);
        $todayKey = gmdate('Ymd', $now + $offset);

        // 与 Redis 模式共用 todayExact()：同一站点同一时刻，两种部署形态的今日 PV/UV/IP 一定相同
        $t = $this->todayExact($sid, $today);
        $pv = $t['pv'];
        $uv = $t['uv'];
        $ipc = $t['ipc'];
        $online = self::onlineCountDb($sid, $now);

        // 近 60 分钟分钟桶（站点本地时）：一次范围聚合，桶序号 floor((ts+offset)/60)
        $bmap = [];
        foreach (
            Db::select(
                "SELECT (ts + ?) DIV 60 m, COUNT(*) pv FROM events
                 WHERE site_id=? AND type='pageview' AND ts>=? AND ts<?
                 GROUP BY m",
                [$offset, $sid, $now - 59 * 60, $now + 60]
            ) as $r
        ) {
            $bmap[(int) $r['m']] = (int) $r['pv'];
        }
        $series = [];
        for ($i = 59; $i >= 0; $i--) {
            $t = $now - $i * 60;
            $m = intdiv($t + $offset, 60);
            $series[] = ['min' => gmdate('H:i', $m * 60), 'pv' => $bmap[$m] ?? 0];
        }

        return [
            'date' => $today, 'todayKey' => $todayKey,
            'pv' => $pv, 'uv' => $uv, 'ipc' => $ipc,
            'online' => $online, 'online10' => $online,
            'series' => $series,
        ];
    }

    /** 实时数据（Redis 缺失时返回 0 占位） */
    private function live(int $sid, int $tzJunk, int $offset): array
    {
        $now = time();
        $today = gmdate('Y-m-d', $now + $offset);
        $todayKey = gmdate('Ymd', $now + $offset);
        $redis = Rds::get();

        if ($redis === null) {
            // 无 Redis 模式（或 Redis 故障）：今日实时直接从数据库计算
            return $this->liveFromDb($sid, $offset);
        }
        try {
            $pv = (int) $redis->hget("today:$sid", $todayKey . ':pv');
            // UV / 独立 IP 精确取自 events（与概览、IP 地域页、大屏同一口径），不使用 HLL 估算
            $t = $this->todayExact($sid, $today);
            $uv = $t['uv'];
            $ipc = $t['ipc'];
            $online = $redis->zcount("on:$sid", (string) ($now - 300), (string) ($now + 1));
            $online10 = $redis->zcount("on:$sid", (string) ($now - 600), (string) ($now + 1));

            // 最近60分钟 PV 折线
            $fields = [];
            for ($i = 59; $i >= 0; $i--) {
                $fields[] = gmdate('YmdHi', $now - $i * 60 + $offset);
            }
            $vals = $redis->hmget("rt:min:$sid", $fields);
            $series = [];
            if (is_array($vals)) {
                foreach ($fields as $idx => $f) {
                    $series[] = [
                        'min' => substr($f, -4, 2) . ':' . substr($f, -2),
                        'pv' => (int) ($vals[$idx] ?? 0),
                    ];
                }
            }
            return ['date' => $today, 'pv' => $pv, 'uv' => $uv, 'ipc' => $ipc, 'online' => $online, 'online10' => $online10, 'series' => $series];
        } catch (\Throwable $e) {
            // Redis 瞬时异常：退回数据库口径（直写模式下 events/sessions 本就是唯一数据源）
            try {
                return $this->liveFromDb($sid, $offset);
            } catch (\Throwable $e2) {
                return ['date' => $today, 'pv' => 0, 'uv' => 0, 'ipc' => 0, 'online' => 0, 'online10' => 0, 'series' => []];
            }
        }
    }

    /**
     * 站点归属 + 基本信息（多用户：改为 SiteAccess 校验，只读成员亦可查看报表）。
     * 返回的站点数组附带 role 字段；$min 用于需要更高权限的调用点。
     */
    private function own(Request $req, int $id, string $min = SiteAccess::VIEWER): array
    {
        return SiteAccess::site($req, $id, $min);
    }

    private function siteShape(array $site): array
    {
        return [
            'id' => (int) $site['id'],
            'name' => $site['name'],
            'domain' => $site['domain'],
            'site_key' => $site['site_key'],
            'timezone' => $site['timezone'],
            'verified' => (int) $site['verified_at'] > 0,
            'status' => (int) $site['status'],
        ];
    }

    /** 空日行（缺失天补齐用） */
    private static function emptyDaily(string $d): array
    {
        return ['day' => $d, 'pv' => 0, 'uv' => 0, 'ipc' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
    }

    /** 校验/默认日期区间 */
    private function range(Request $req, string $tz): array
    {
        $offset = Util::tzOffsetSec($tz);
        $today = gmdate('Y-m-d', time() + $offset);
        $start = (string) $req->input('start', gmdate('Y-m-d', time() + $offset - 29 * 86400));
        $end = (string) $req->input('end', $today);
        // 容错归一：仅接受 YYYY-MM-DD；对 ISO 串 / Date.toString() 等可解析格式自动转换
        $start = $this->ymd($start);
        $end = $this->ymd($end);
        if ($start === '' || $end === '') {
            wstat_err('日期格式应为 YYYY-MM-DD', 422);
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $d1 = strtotime($start);
        $d2 = strtotime($end);
        if ($d2 - $d1 > (self::MAX_RANGE_DAYS - 1) * 86400) {
            wstat_err('查询区间最长 366 天', 422);
        }
        return [$start, $end];
    }

    /** LIKE 转义（防通配符注入） */
    private function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    /**
     * 日期参数归一为 YYYY-MM-DD：
     * - 已是标准格式直接返回；
     * - 兼容 ISO8601（2026-09-02T00:00:00.000Z）与 JS Date.toString()（Wed Sep 02 2026 00:00:00 GMT+0800）；
     * - 无法解析返回 ''（由调用方回 422）。
     */
    private function ymd(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $v;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $v)) {
            return (string) substr($v, 0, 10);            // ISO 串按字面日期取，避免时区偏移
        }
        // JS Date.toString() 形如 "Wed Sep 02 2026 00:00:00 GMT+0800 (GMT+08:00)"：
        // 尾部括号说明会让 strtotime 解析失败，先剥离再解析
        $plain = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $v);
        $ts = strtotime($plain);
        if ($ts === false) {
            $ts = strtotime($v);
        }
        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    /* ================= 用户旅程 ================= */

    private const JOURNEY_MAX_ROWS  = 200000;   // 单次聚合的 pageview 上限（防内存失控）
    private const JOURNEY_PATH_STEP = 6;        // 路径排名最多展开步数
    private const JOURNEY_FLOW_STEP = 4;        // 桑基图步数
    private const JOURNEY_FLOW_TOP  = 8;        // 桑基图每步展示节点数

    /**
     * GET /api/stats/journey?site_id=&start=&end=
     * 用户旅程：路径排名（访问次数）、跳转 TOP（from→to PV）、桑基流（入口→…→出口）。
     * 数据源：events pageview 按 (session_id, ts) 时序聚合（idx_site_day_type + (session_id,ts) 索引）。
     */
    public function journey(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        $sid = (int) $site['id'];

        $rows = Db::select(
            "SELECT session_id, url FROM events
             WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?
             ORDER BY session_id, ts, id LIMIT " . self::JOURNEY_MAX_ROWS,
            [$sid, $start, $end]
        );

        $paths = [];        // 路径串 => 访问次数
        $trans = [];        // "from\x1fto" => PV
        $stepNodes = [];    // [step][url] => PV
        $stepEdges = [];    // [step]["from\x1fto"] => PV
        $sessPv = [];       // session_id => pv（算桑基节点占比）

        $curSid = null;
        $seq = [];
        $flush = function () use (&$seq, &$curSid, &$paths, &$trans, &$stepNodes, &$stepEdges, &$sessPv) {
            if ($curSid === null) {
                return;
            }
            $sessPv[$curSid] = count($seq);
            // 连续重复页折叠（刷新不重复计）
            $fold = [];
            foreach ($seq as $u) {
                $n = count($fold);
                if ($n === 0 || $fold[$n - 1] !== $u) {
                    $fold[] = $u;
                }
            }
            $n = count($fold);
            if ($n === 0) {
                return;
            }
            // 路径排名（超长截断标记 …）
            $cut = array_slice($fold, 0, self::JOURNEY_PATH_STEP);
            $key = implode(' → ', $cut) . ($n > self::JOURNEY_PATH_STEP ? ' → …' : '');
            $paths[$key] = ($paths[$key] ?? 0) + 1;
            // 跳转对 + 桑基节点/边
            for ($i = 0; $i < $n; $i++) {
                $stepNodes[$i][$fold[$i]] = ($stepNodes[$i][$fold[$i]] ?? 0) + 1;
                if ($i + 1 < $n) {
                    $pair = $fold[$i] . "\x1f" . $fold[$i + 1];
                    $trans[$pair] = ($trans[$pair] ?? 0) + 1;
                    if ($i < self::JOURNEY_FLOW_STEP - 1) {
                        $stepEdges[$i][$pair] = ($stepEdges[$i][$pair] ?? 0) + 1;
                    }
                }
            }
            $seq = [];
            $curSid = null;
        };

        foreach ($rows as $r) {
            $s = (string) $r['session_id'];
            if ($s !== $curSid) {
                $flush();
                $curSid = $s;
            }
            $seq[] = (string) $r['url'];
        }
        $flush();

        arsort($paths);
        $totalVisits = count($sessPv);
        $pathItems = [];
        $i = 0;
        foreach ($paths as $k => $c) {
            if ($i++ >= 30) {
                break;
            }
            $pathItems[] = ['path' => $k, 'visits' => $c, 'pct' => $totalVisits > 0 ? round($c / $totalVisits * 100, 2) : 0.0];
        }

        arsort($trans);
        $transItems = [];
        $i = 0;
        foreach ($trans as $k => $c) {
            if ($i++ >= 30) {
                break;
            }
            [$a, $b] = explode("\x1f", $k);
            $transItems[] = ['from' => $a, 'to' => $b, 'pv' => $c];
        }

        // ---- 桑基流：每步取 TOP N 节点，其余归并为「其他」；同节点边丢弃 ----
        $flowNodes = [];    // 最终节点名集合
        $nodeTop = [];      // [step] => [url => true]（TOP 集合）
        foreach ($stepNodes as $step => $urls) {
            arsort($urls);
            $top = array_slice(array_keys($urls), 0, self::JOURNEY_FLOW_TOP, true);
            foreach ($top as $u) {
                $nodeTop[$step][$u] = true;
            }
        }
        $mapNode = static function (int $step, string $url) use ($nodeTop): string {
            // 节点名带层级前缀：ECharts sankey 按名字合并节点，同一 URL 跨层复用
            // 会形成 A→B→A 环（sankey 要求 DAG，有环直接抛错）。加前缀后边
            // 严格从 step i 指向 i+1，天然无环；前端展示时剥掉前缀。
            return $step . '|' . (isset($nodeTop[$step][$url]) ? $url : '其他');
        };
        $flowLinks = [];
        foreach ($stepEdges as $step => $pairs) {
            $agg = [];
            foreach ($pairs as $pair => $c) {
                [$a, $b] = explode("\x1f", $pair);
                $sa = $mapNode($step, $a);
                $sb = $mapNode($step + 1, $b);
                if ($sa === $sb
                    || (str_ends_with($sa, '|其他') && str_ends_with($sb, '|其他'))) {
                    continue;   // 自环与「其他→其他」长尾边不画
                }
                $k = $sa . "\x1f" . $sb;
                $agg[$k] = ($agg[$k] ?? 0) + $c;
            }
            arsort($agg);
            $j = 0;
            foreach ($agg as $k => $c) {
                if ($j++ >= 60) {
                    break;
                }
                [$a, $b] = explode("\x1f", $k);
                $flowLinks[] = ['source' => $a, 'target' => $b, 'value' => $c];
                $flowNodes[$a] = true;
                $flowNodes[$b] = true;
            }
        }

        wstat_json([
            'site' => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'totals' => ['sessions' => $totalVisits, 'pv' => array_sum($sessPv), 'capped' => count($rows) >= self::JOURNEY_MAX_ROWS],
            'paths' => $pathItems,
            'transitions' => $transItems,
            'flow' => ['nodes' => array_keys($flowNodes), 'links' => $flowLinks],
        ]);
    }

    /* ================= 转化漏斗 ================= */

    private const FUNNEL_MAX_STEPS = 8;   // 单条漏斗最多步数

    /** 校验并归一漏斗入参：[name, steps[], match_type] */
    private function funnelInput(Request $req): array
    {
        $name = trim((string) $req->input('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            wstat_err('漏斗名称必填且不超过 100 字', 422);
        }
        $steps = $req->input('steps');
        if (!is_array($steps)) {
            wstat_err('步骤格式错误', 422);
        }
        $steps = array_values(array_filter(
            array_map(static fn ($s) => trim((string) $s), $steps),
            static fn ($s) => $s !== ''
        ));
        if (count($steps) < 2) {
            wstat_err('漏斗至少需要 2 个步骤', 422);
        }
        if (count($steps) > self::FUNNEL_MAX_STEPS) {
            wstat_err('漏斗最多 ' . self::FUNNEL_MAX_STEPS . ' 步', 422);
        }
        foreach ($steps as $s) {
            if (strlen($s) > 255) {
                wstat_err('步骤 URL 过长（≤255 字符）', 422);
            }
        }
        $match = (string) $req->input('match_type', 'prefix');
        if (!in_array($match, ['prefix', 'contains', 'exact'], true)) {
            $match = 'prefix';
        }
        return [$name, $steps, $match];
    }

    /**
     * 取当前用户可访问站点下的漏斗；默认要求可编辑（写入场景）。
     * 无权访问 → 404（不泄露漏斗是否存在）；权限不足 → 403。
     */
    private function funnelOwn(Request $req, int $id, string $min = SiteAccess::EDITOR): array
    {
        $f = Db::first('SELECT id,site_id,name,steps,match_type,is_active,created_at FROM funnels WHERE id=? LIMIT 1', [$id]);
        if ($f === null) {
            wstat_err('漏斗不存在', 404, 404);
        }
        SiteAccess::require($req, (int) $f['site_id'], $min);
        return $f;
    }

    /** events.url 的路径表达式：完整 URL 取 path 部分，纯路径原样返回 */
    private const URL_PATH_EXPR = "IF(LOCATE('//', url) > 0, IF(LOCATE('/', url, LOCATE('//', url) + 2) > 0, SUBSTRING(url, LOCATE('/', url, LOCATE('//', url) + 2)), '/'), url)";

    /** 步骤匹配片段（返回 SQL 片段与绑定参数；一律按路径匹配） */
    private function funnelLike(string $step, string $match): array
    {
        $s = $this->likeEscape($step);
        if ($s !== '' && $s[0] !== '/') {
            $s = '/' . $s;   // 归一为路径形态
        }
        $expr = self::URL_PATH_EXPR;
        if ($match === 'exact') {
            return [$expr . ' = ?', $s];
        }
        $pat = $match === 'contains' ? '%' . $s . '%' : $s . '%';
        return [$expr . ' LIKE ?', $pat];
    }

    /** GET /api/stats/funnels?site_id=  漏斗列表 */
    public function funnels(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $rows = Db::select(
            'SELECT id,name,steps,match_type,is_active,created_at FROM funnels
             WHERE site_id=? ORDER BY id DESC',
            [(int) $site['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['steps'] = json_decode((string) $r['steps'], true) ?: [];
            $r['is_active'] = (int) $r['is_active'];
            $r['created_at'] = (int) $r['created_at'];
        }
        unset($r);
        wstat_json(['items' => $rows]);
    }

    /** POST /api/stats/funnels  新建漏斗（name, steps[], match_type） */
    public function funnelSave(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0), SiteAccess::EDITOR);
        [$name, $steps, $match] = $this->funnelInput($req);
        $id = Db::insert('funnels', [
            'site_id'    => (int) $site['id'],
            'name'       => $name,
            'steps'      => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'match_type' => $match,
            'is_active'  => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        wstat_json(['id' => $id]);
    }

    /** PATCH /api/stats/funnels/{id}  更新漏斗 */
    public function funnelUpdate(Request $req): void
    {
        $f = $this->funnelOwn($req, (int) $req->param('id'));
        [$name, $steps, $match] = $this->funnelInput($req);
        Db::execute(
            'UPDATE funnels SET name=?, steps=?, match_type=?, updated_at=? WHERE id=?',
            [
                $name,
                json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $match,
                time(),
                (int) $f['id'],
            ]
        );
        wstat_json(['ok' => true]);
    }

    /** DELETE /api/stats/funnels/{id}  删除漏斗——需 editor 及以上 */
    public function funnelDelete(Request $req): void
    {
        $f = $this->funnelOwn($req, (int) $req->param('id'));
        SiteAccess::require($req, (int) $f['site_id'], SiteAccess::EDITOR);
        Db::execute('DELETE FROM funnels WHERE id=?', [(int) $f['id']]);
        wstat_json(['ok' => true]);
    }

    /**
     * GET /api/stats/funnels/{id}/data?start=&end=&ordered=1
     * 转化统计：逐级累计「命中前 N 步」的去重会话数（events pageview，session_id 去重）。
     * ordered=1（默认）要求步骤按时间先后依次发生；ordered=0 为旧口径（顺序无关）
     * —— 只要区间内命中过全部步骤即计入，转化率会偏高，仅作对照。
     */
    public function funnelData(Request $req): void
    {
        // 读接口：只读成员即可查看漏斗转化数据
        $f = $this->funnelOwn($req, (int) $req->param('id'), SiteAccess::VIEWER);
        $site = $this->own($req, (int) $f['site_id']);
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        $sid = (int) $f['site_id'];
        $steps = json_decode((string) $f['steps'], true) ?: [];
        $match = (string) $f['match_type'];
        $ordered = (string) $req->input('ordered', '1') !== '0';

        $orConds = [];   // 命中任意一步（缩小扫描范围）
        $orArgs = [];
        $mins = [];      // 有序模式：每个步骤的首次命中时间/事件ID
        $minsArgs = [];
        $idMins = [];
        $idArgs = [];
        foreach ($steps as $i => $step) {
            [$cond, $pat] = $this->funnelLike((string) $step, $match);
            $orConds[] = $cond;
            $orArgs[] = $pat;
            $mins[] = 'MIN(CASE WHEN ' . $cond . ' THEN `ts` END) AS t' . ($i + 1);
            $minsArgs[] = $pat;
            $idMins[] = 'MIN(CASE WHEN ' . $cond . ' THEN `id` END) AS e' . ($i + 1);
            $idArgs[] = $pat;
        }
        $where = 'site_id=? AND type=\'pageview\' AND `day` BETWEEN ? AND ?'
            . ' AND session_id<>\'\' AND (' . implode(' OR ', $orConds) . ')';
        $baseArgs = [$sid, $start, $end, ...$orArgs];

        $n = count($steps);
        $out = [];
        for ($k = 1; $k <= $n; $k++) {
            if ($ordered) {
                // 有序：步骤 k 要求 t1 ≤ t2 ≤ … ≤ tk（同秒用事件 id 兜底比较），
                // 即「先到过第 1 步、再到第 2 步 …」的真实转化路径。
                $h = ['t1 IS NOT NULL'];
                for ($j = 2; $j <= $k; $j++) {
                    $p = $j - 1;
                    $h[] = "(t{$j} > t{$p} OR (t{$j} = t{$p} AND e{$j} > e{$p}))";
                }
                $sql = 'SELECT COUNT(*) FROM (
                            SELECT session_id, ' . implode(', ', $mins) . ', ' . implode(', ', $idMins) . '
                            FROM events
                            WHERE ' . $where . '
                            GROUP BY session_id
                            HAVING ' . implode(' AND ', $h) . '
                        ) t';
                // 绑定顺序必须与 SQL 文本一致：SELECT 列表里的 CASE 占位符在前，WHERE 的在后
                $args = [...$minsArgs, ...$idArgs, ...$baseArgs];
            } else {
                // 无序：只要求区间内命中过前 k 步（旧行为，顺序无关）。
                $h = [];
                for ($j = 1; $j <= $k; $j++) {
                    $h[] = 'SUM(' . $orConds[$j - 1] . ')>0';
                }
                $sql = 'SELECT COUNT(*) FROM (
                            SELECT session_id FROM events
                            WHERE ' . $where . '
                            GROUP BY session_id
                            HAVING ' . implode(' AND ', $h) . '
                        ) t';
                // WHERE 用全部 n 个条件，HAVING 复用前 k 个 → 占位符再按前 k 步传一份
                $args = [...$baseArgs, ...array_slice($orArgs, 0, $k)];
            }
            $c = (int) Db::value($sql, $args);
            $out[] = [
                'step'     => $k,
                'url'      => (string) $steps[$k - 1],
                'visitors' => $c,
            ];
        }

        $base = $out[0]['visitors'] ?? 0;
        $prev = 0;
        foreach ($out as &$s) {
            $s['rate'] = $base > 0 ? round($s['visitors'] / $base * 100, 1) : 0.0;
            $s['dropRate'] = $prev > 0 ? round(max(0, $prev - $s['visitors']) / $prev * 100, 1) : 0.0;
            $prev = $s['visitors'];
        }
        unset($s);

        wstat_json([
            'funnel' => [
                'id'         => (int) $f['id'],
                'name'       => $f['name'],
                'steps'      => $steps,
                'match_type' => $match,
                'ordered'    => $ordered,
            ],
            'site'    => $this->siteShape($site),
            'range'   => ['start' => $start, 'end' => $end],
            'steps'   => $out,
            'overall' => ['rate' => $base > 0 ? round(($out[count($out) - 1]['visitors'] ?? 0) / $base * 100, 1) : 0.0],
        ]);
    }

    /* ================= 转化目标 Goal ================= */

    /** GET /api/stats/goals?site_id=  目标列表 */
    public function goals(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $rows = Db::select(
            'SELECT id,name,type,target,match_type,is_active,created_at FROM goals
             WHERE site_id=? ORDER BY id DESC',
            [(int) $site['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];
            $r['created_at'] = (int) $r['created_at'];
        }
        unset($r);
        wstat_json([
            'items'   => $rows,
            'types'   => ['page', 'event'],
            'matches' => ['prefix', 'contains', 'exact'],
        ]);
    }

    /** POST /api/stats/goals  新建目标（site_id, name, type, target, match_type） */
    public function goalSave(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0), SiteAccess::EDITOR);
        [$name, $type, $target, $match] = $this->goalInput($req);
        $id = Db::insert('goals', [
            'site_id'    => (int) $site['id'],
            'name'       => $name,
            'type'       => $type,
            'target'     => $target,
            'match_type' => $match,
            'is_active'  => 1,
            'created_at' => time(),
        ]);
        wstat_json(['id' => $id]);
    }

    /** PATCH /api/stats/goals/{id}  更新目标 */
    public function goalUpdate(Request $req): void
    {
        $g = $this->goalOwn($req, (int) $req->param('id'));
        [$name, $type, $target, $match] = $this->goalInput($req);
        Db::execute(
            'UPDATE goals SET name=?, type=?, target=?, match_type=? WHERE id=?',
            [$name, $type, $target, $match, (int) $g['id']]
        );
        wstat_json(['ok' => true]);
    }

    /** DELETE /api/stats/goals/{id}  删除目标 */
    public function goalDelete(Request $req): void
    {
        $g = $this->goalOwn($req, (int) $req->param('id'));
        Db::execute('DELETE FROM goals WHERE id=?', [(int) $g['id']]);
        wstat_json(['ok' => true]);
    }

    /**
     * GET /api/stats/goals/{id}/data?start=&end=
     * 转化统计：分母 = 区间内会话总数（sessions）；分子 = 命中目标的去重会话数。
     * page 目标按 URL 路径匹配（prefix/contains/exact）；event 目标按 SDK 上报事件名（payload.n）精确匹配。
     */
    public function goalData(Request $req): void
    {
        $g = $this->goalOwn($req, (int) $req->param('id'), SiteAccess::VIEWER);
        $site = $this->own($req, (int) $g['site_id']);
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $g['site_id'];

        $visits = (int) Db::value(
            'SELECT COUNT(*) FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
            [$sid, $startTs, $endTs]
        );

        [$cond, $bind] = $this->goalCond((string) $g['type'], (string) $g['target'], (string) $g['match_type']);
        $row = Db::first(
            "SELECT COUNT(DISTINCT session_id) conv, COUNT(DISTINCT visitor_id) users
             FROM events
             WHERE site_id=? AND `day` BETWEEN ? AND ? AND session_id<>'' AND ($cond)",
            [$sid, $start, $end, $bind]
        ) ?: ['conv' => 0, 'users' => 0];

        // 按日转化趋势（命中会话数）
        $trend = [];
        foreach (
            Db::select(
                "SELECT `day`, COUNT(DISTINCT session_id) c FROM events
                 WHERE site_id=? AND `day` BETWEEN ? AND ? AND session_id<>'' AND ($cond)
                 GROUP BY `day` ORDER BY `day`",
                [$sid, $start, $end, $bind]
            ) as $r
        ) {
            $trend[] = ['day' => (string) $r['day'], 'conversions' => (int) $r['c']];
        }

        $conv = (int) $row['conv'];
        wstat_json([
            'goal' => [
                'id'         => (int) $g['id'],
                'name'       => $g['name'],
                'type'       => $g['type'],
                'target'     => $g['target'],
                'match_type' => $g['match_type'],
            ],
            'site'               => $this->siteShape($site),
            'range'              => ['start' => $start, 'end' => $end],
            'visits'             => $visits,
            'conversions'        => $conv,
            'converted_visitors' => (int) $row['users'],
            'rate'               => $visits > 0 ? round($conv / $visits * 100, 2) : 0.0,
            'trend'              => $trend,
        ]);
    }

    private function goalOwn(Request $req, int $id, string $min = SiteAccess::EDITOR): array
    {
        $g = Db::first(
            'SELECT id,site_id,name,type,target,match_type,is_active,created_at FROM goals WHERE id=? LIMIT 1',
            [$id]
        );
        if ($g === null) {
            wstat_err('目标不存在', 404, 404);
        }
        SiteAccess::require($req, (int) $g['site_id'], $min);
        return $g;
    }

    /** 目标入参校验，返回 [name, type, target, match_type] */
    private function goalInput(Request $req): array
    {
        $name = trim((string) $req->input('name', ''));
        if ($name === '' || mb_strlen($name) > 100) {
            wstat_err('目标名称必填且不超过 100 字', 422);
        }
        $type = (string) $req->input('type', 'page');
        if (!in_array($type, ['page', 'event'], true)) {
            wstat_err('目标类型应为 page 或 event', 422);
        }
        $target = trim((string) $req->input('target', ''));
        if ($target === '' || strlen($target) > 255) {
            wstat_err($type === 'page' ? '目标路径必填且不超过 255 字符' : '事件名必填且不超过 255 字符', 422);
        }
        $match = (string) $req->input('match_type', 'contains');
        if (!in_array($match, ['prefix', 'contains', 'exact'], true)) {
            $match = 'contains';
        }
        if ($type === 'event') {
            $match = 'exact';   // 事件名一律精确匹配
        }
        return [$name, $type, $target, $match];
    }

    /**
     * 目标命中 SQL 片段 + 绑定值。
     * page 目标按 URL_PATH_EXPR（路径）匹配；event 目标按 JSON_EXTRACT(payload,'$.n') 精确匹配。
     */
    private function goalCond(string $type, string $target, string $match): array
    {
        if ($type === 'event') {
            return ["type='event' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.n')) = ?", $target];
        }
        $expr = self::URL_PATH_EXPR;
        $s = $this->likeEscape($target);
        if ($s !== '' && $s[0] !== '/') {
            $s = '/' . $s;   // 归一为路径形态（与漏斗口径一致）
        }
        if ($match === 'exact') {
            return ["type='pageview' AND $expr = ?", $s];
        }
        $pat = $match === 'contains' ? '%' . $s . '%' : $s . '%';
        return ["type='pageview' AND $expr LIKE ?", $pat];
    }

    /* ================= 广告追踪专项 ================= */

    /** 付费流量口径（events 与 sessions 通用列：click_id / source / medium） */
    private const ADS_PAID_W = "(click_id<>'' OR source IN ('paid','utm') OR medium IN ('cpc','ppc','paid'))";

    /** 归因扩展列预检：缺失则提示执行迁移（避免 SQLSTATE 500） */
    private function requireSourceColumns(): void
    {
        $missing = [];
        $cols = Db::tableColumns('events');
        foreach (['utm_source', 'utm_medium', 'click_source', 'ref_host'] as $need) {
            if (!isset($cols[$need])) {
                $missing[] = $need;
            }
        }
        if ($missing) {
            $alter = "ALTER TABLE `events`\n  ADD COLUMN `utm_source`   VARCHAR(128) NOT NULL DEFAULT '' AFTER `medium`,\n  ADD COLUMN `utm_medium`   VARCHAR(128) NOT NULL DEFAULT '' AFTER `utm_source`,\n  ADD COLUMN `click_source` VARCHAR(64)  NOT NULL DEFAULT '' AFTER `click_id`,\n  ADD COLUMN `ref_host`     VARCHAR(255) NOT NULL DEFAULT '' AFTER `click_source`;";
            wstat_err(
                'events 表缺少来源分析列：' . implode(', ', $missing) . "。请在数据库执行升级：\n\n" . $alter
                . "\n\n或直接运行：mysql -uroot -p <库名> < sql/upgrade-2026-09-09-sources.sql（路径以部署目录为准）。",
                200, 1
            );
        }
    }

    /**
     * GET /api/stats/ads?site_id=&start=&end=
     * 广告追踪专项：付费口径汇总、付费/全站趋势、多维度归因明细、付费落地页质量。
     */
    public function ads(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];

        $this->requireSourceColumns();

        $wAll = 'site_id=? AND type="pageview" AND `day` BETWEEN ? AND ?';
        $wPaid = $wAll . ' AND ' . self::ADS_PAID_W;
        $args = [$sid, $start, $end];
        $wSessPaid = 'site_id=? AND start_ts>=? AND start_ts<? AND ' . self::ADS_PAID_W;
        $sessArgs = [$sid, $startTs, $endTs];

        // ---- 汇总 ----
        $paidPv = (int) Db::value("SELECT COUNT(*) FROM events WHERE $wPaid", $args);
        $totalPv = (int) Db::value("SELECT COUNT(*) FROM events WHERE $wAll", $args);
        $q = Db::first(
            "SELECT COUNT(*) v, COALESCE(SUM(bounce),0) b, COALESCE(ROUND(AVG(duration)),0) d, COALESCE(SUM(is_new),0) n
             FROM sessions WHERE $wSessPaid", $sessArgs
        ) ?: ['v' => 0, 'b' => 0, 'd' => 0, 'n' => 0];
        $qAll = Db::first(
            'SELECT COUNT(*) v FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
            $sessArgs
        ) ?: ['v' => 0];
        $paidVisits = (int) $q['v'];

        // ---- 趋势：付费 / 全站 PV 按天 ----
        $days = [];
        foreach (Util::dailySeries($startTs, $endTs, $tz, fn () => null) as $d => $_) {
            $days[] = (string) $d;
        }
        $idx = array_flip($days);
        $trPaid = array_fill(0, count($days), 0);
        $trTotal = array_fill(0, count($days), 0);
        foreach (
            Db::select("SELECT `day` d, COUNT(*) pv FROM events WHERE $wPaid GROUP BY `day` ORDER BY d", $args) as $r
        ) {
            $i = $idx[(string) $r['d']] ?? -1;
            if ($i >= 0) {
                $trPaid[$i] = (int) $r['pv'];
            }
        }
        foreach (
            Db::select("SELECT `day` d, COUNT(*) pv FROM events WHERE $wAll GROUP BY `day` ORDER BY d", $args) as $r
        ) {
            $i = $idx[(string) $r['d']] ?? -1;
            if ($i >= 0) {
                $trTotal[$i] = (int) $r['pv'];
            }
        }

        // ---- 维度明细（events：PV + 去重访问） ----
        $byCampaign = Db::select(
            "SELECT COALESCE(NULLIF(campaign,''),'(未命名)') k, COUNT(*) pv, COUNT(DISTINCT session_id) visits
             FROM events WHERE $wPaid GROUP BY k ORDER BY pv DESC LIMIT 20", $args
        );
        $byUtm = Db::select(
            "SELECT CONCAT(COALESCE(NULLIF(utm_source,''),'—'),' / ',COALESCE(NULLIF(utm_medium,''),'—')) k,
                    COUNT(*) pv, COUNT(DISTINCT session_id) visits
             FROM events WHERE $wPaid AND (utm_source<>'' OR utm_medium<>'') GROUP BY k ORDER BY pv DESC LIMIT 20", $args
        );
        $byPlatform = Db::select(
            "SELECT COALESCE(NULLIF(click_source,''),'(未知平台)') k, COUNT(*) pv, COUNT(DISTINCT session_id) visits
             FROM events WHERE $wPaid AND click_id<>'' GROUP BY k ORDER BY pv DESC LIMIT 20", $args
        );
        $byTerm = Db::select(
            "SELECT COALESCE(NULLIF(term,''),'(无关键词)') k, COUNT(*) pv, COUNT(DISTINCT session_id) visits
             FROM events WHERE $wPaid AND term<>'' GROUP BY k ORDER BY pv DESC LIMIT 20", $args
        );

        // ---- 付费落地页（sessions：访问/跳出/时长） ----
        $landings = Db::select(
            "SELECT entry_url k, COUNT(*) visits,
                    COALESCE(SUM(bounce),0) b, COALESCE(ROUND(AVG(duration)),0) d
             FROM sessions WHERE $wSessPaid
             GROUP BY entry_url ORDER BY visits DESC LIMIT 20", $sessArgs
        );
        foreach ($landings as &$l) {
            $v = (int) $l['visits'];
            $l['visits'] = $v;
            $l['bounce_rate'] = $v > 0 ? round((int) $l['b'] / $v * 100, 1) : 0.0;
            $l['duration'] = (int) $l['d'];
            unset($l['b'], $l['d']);
        }
        unset($l);

        wstat_json([
            'site'  => $this->siteShape($site),
            'range' => ['start' => $start, 'end' => $end],
            'totals' => [
                'paid_pv'      => $paidPv,
                'total_pv'     => $totalPv,
                'paid_visits'  => $paidVisits,
                'total_visits' => (int) $qAll['v'],
                'paid_new'     => (int) $q['n'],
                'bounce_rate'  => $paidVisits > 0 ? round((int) $q['b'] / $paidVisits * 100, 1) : 0.0,
                'avg_duration' => (int) $q['d'],
            ],
            'trend'     => ['days' => $days, 'paid' => $trPaid, 'total' => $trTotal],
            'campaigns' => $byCampaign,
            'utms'      => $byUtm,
            'platforms' => $byPlatform,
            'terms'     => $byTerm,
            'landings'  => $landings,
        ]);
    }

    /* ================= 点击热力图 ================= */

    private const HEAT_MAX_ROWS = 100000;   // 单页点击聚合扫描上限

    /**
     * GET /api/stats/heatmap
     *   ?site_id&start&end              → 页面列表（点击量排名）
     *   ?site_id&start&end&page=/path&device=desktop|mobile|tablet → 该页坐标点
     */
    public function heatmap(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $path = trim((string) $req->input('page', ''));
        $device = (string) $req->input('device', '');

        if ($path === '') {
            // ---- 页面列表：click 事件按路径聚合 ----
            $expr = self::URL_PATH_EXPR;
            $rows = Db::select(
                "SELECT {$expr} AS path, COUNT(*) AS clicks, COUNT(DISTINCT session_id) AS visits
                 FROM events
                 WHERE site_id=? AND type='click' AND `day` BETWEEN ? AND ?
                 GROUP BY path ORDER BY clicks DESC LIMIT 50",
                [$sid, $start, $end]
            );
            wstat_json(['pages' => $rows]);
            return;
        }

        // ---- 单页坐标聚合 ----
        $cond = "site_id=? AND type='click' AND `day` BETWEEN ? AND ? AND {$this->pathCond()}";
        $bind = [$sid, $start, $end, $path, $path];
        if (in_array($device, ['desktop', 'mobile', 'tablet'], true)) {
            $cond .= ' AND device=?';
            $bind[] = $device;
        }

        $rows = Db::select(
            "SELECT
                CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.x')) AS DECIMAL(8,2)) AS x,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.y')) AS DECIMAL(8,2)) AS y,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.dh')) AS DECIMAL(10,0)) AS dh,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tag')) AS tag,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.txt')) AS txt
             FROM events
             WHERE {$cond}
             ORDER BY id DESC LIMIT " . self::HEAT_MAX_ROWS,
            $bind
        );

        // 1% x 1% 分桶（x: 视口宽百分比；y: 文档高百分比）
        $cells = [];
        $dhSum = 0;
        $dhN = 0;
        $tags = [];
        foreach ($rows as $r) {
            $x = (float) $r['x'];
            $y = (float) $r['y'];
            if ($x < 0 || $x > 100 || $y < 0 || $y > 100) {
                continue;   // 异常坐标丢弃
            }
            $gx = (int) floor($x);
            $gy = (int) floor($y);
            $k = $gx . '_' . $gy;
            if (!isset($cells[$k])) {
                $cells[$k] = ['x' => $gx, 'y' => $gy, 'c' => 0];
            }
            $cells[$k]['c']++;
            if ((float) $r['dh'] > 0) {
                $dhSum += (float) $r['dh'];
                $dhN++;
            }
            $tag = trim((string) $r['tag']);
            if ($tag !== '') {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
        arsort($tags);

        wstat_json([
            'page'      => $path,
            'clicks'    => count($rows),
            'avg_dh'    => $dhN > 0 ? (int) round($dhSum / $dhN) : 0,
            'max_cell'  => $cells ? max(array_column($cells, 'c')) : 0,
            'cells'     => array_values($cells),
            'tags'      => array_slice(array_map(null, array_keys($tags), array_values($tags)), 0, 8),
        ]);
    }

    /** 路径精确匹配条件（events.url 可能存完整 URL 或纯路径，两个 ? 共用同一 $path） */
    private function pathCond(): string
    {
        $expr = self::URL_PATH_EXPR;
        return "({$expr} = ? OR url = ?)";
    }

    /* ================= 数据大屏（只读 token，免登录） ================= */

    /** 大屏签名密钥：环境变量优先，缺省由 DB 密码派生（部署后轮换 DB 密码即失效） */
    private function screenSecret(): string
    {
        $s = getenv('WSTAT_SCREEN_SECRET');
        if ($s && $s !== '') {
            return $s;
        }
        $cfg = wstat_config();
        return 'wstat-screen:' . $cfg['db']['pass'];
    }

    /** GET /api/screen/token?site_id=  （需登录）签发大屏只读 token */
    public function screenToken(Request $req): void
    {
        $site = $this->own($req, (int) $req->input('site_id', 0));
        $token = hash_hmac('sha256', 'screen:' . $site['id'], $this->screenSecret());
        wstat_json(['site' => $this->siteShape($site), 'token' => $token]);
    }

    /**
     * 解析大屏时间范围：range=today|yesterday|7d|30d|custom（兼容旧参 days=N，1..90）。
     * 时间基准是站点时区的「今日」，返回 [start(Y-m-d), end(Y-m-d), days]（闭区间）。
     */
    private function screenRange(Request $req, string $today): array
    {
        $back = fn (int $n) => date('Y-m-d', strtotime($today) - $n * 86400);
        $range = strtolower(trim((string) $req->input('range', '')));
        if ($range === 'yesterday') {
            return [$back(1), $back(1), 1];
        }
        if ($range === '7d') {
            return [$back(6), $today, 7];
        }
        if ($range === '30d') {
            return [$back(29), $today, 30];
        }
        if ($range === 'today') {
            return [$today, $today, 1];
        }
        if ($range === 'custom') {
            $s = (string) $req->input('start', '');
            $e = (string) $req->input('end', $s);
            $re = '/^\d{4}-\d{2}-\d{2}$/';
            if (!preg_match($re, $s) || !preg_match($re, $e)) {
                return [$today, $today, 1];
            }
            if ($s > $e) {
                [$s, $e] = [$e, $s];
            }
            if ($e > $today) {
                $e = $today;
            }
            if ($s > $e) {
                $s = $e;
            }
            $n = (int) round((strtotime($e) - strtotime($s)) / 86400) + 1;
            if ($n > 90) { // 上限 90 天，避免超长区间拖垮查询
                $s = date('Y-m-d', strtotime($e) - 89 * 86400);
                $n = 90;
            }
            return [$s, $e, $n];
        }
        // 未指定 range：兼容旧参 days=N（缺省今日）
        $n = max(1, min(90, (int) $req->input('days', 1)));
        return [$back($n - 1), $today, $n];
    }

    /**
     * GET /api/screen/data?site_id=&token=&range=today|yesterday|7d|30d|custom&start=&end=
     * 大屏聚合数据（HMAC token 校验，只读，无用户态）。
     */
    public function screenData(Request $req): void
    {
        $sid = (int) $req->input('site_id', 0);
        $site = Db::first('SELECT id,name,domain,site_key,timezone,verified_at FROM sites WHERE id=? LIMIT 1', [$sid]);
        if ($site === null) {
            wstat_err('站点不存在', 404, 404);
        }
        $token = (string) $req->input('token', '');
        $expect = hash_hmac('sha256', 'screen:' . $sid, $this->screenSecret());
        if ($token === '' || !hash_equals($expect, $token)) {
            wstat_err('大屏 token 无效', 403, 403);
        }
        // 访问密码（系统设置配置；空=不启用）。code=4031 供前端识别弹出密码门。
        $needPwd = (string) Settings::get('screen_password');
        if ($needPwd !== '') {
            $got = (string) $req->input('password', '');
            if ($got === '') {
                wstat_err('需要访问密码', 403, 4031);
            }
            if (!hash_equals($needPwd, $got)) {
                wstat_err('访问密码错误', 403, 4032);
            }
        }
        $tz = (string) $site['timezone'];
        $offset = Util::tzOffsetSec($tz);
        $today = gmdate('Y-m-d', time() + $offset);
        // 时间筛选：默认今日；range=custom 时用 start/end。
        [$start, $end, $days] = $this->screenRange($req, $today);
        $startTs = strtotime($start);
        $endTs = strtotime($end) + 86400;   // 区间右开界
        $liveRange = ($end === $today);     // 区间覆盖今日时需要实况修正
        // 环比对照窗：紧邻的等长前一段（用于指标卡的 环比 百分比）
        $prevStartTs = $startTs - $days * 86400;
        $prevStart = date('Y-m-d', $prevStartTs);
        $prevEndTs = $startTs;

        // ---- 区间总量：site_daily 快路径 + 明细兜底（与 overview 同口径的精简版） ----
        $rows = Db::select(
            'SELECT `day`,pv,uv,ipc,visits,bounce,duration,new_users
             FROM site_daily WHERE site_id=? AND `day` BETWEEN ? AND ? ORDER BY `day`',
            [$sid, $prevStart, $end]
        );
        $have = [];
        foreach ($rows as $r) {
            $have[(string) $r['day']] = $r;
        }
        $days_ = [];
        for ($t = $prevStartTs; $t <= strtotime($end); $t += 86400) {
            $days_[] = date('Y-m-d', $t);
        }
        $missing = array_values(array_filter($days_, fn ($d) => !isset($have[$d])));
        // 今日必须是实况：site_daily 的今日行只是某次 rollup / 概览预热的快照（可能停在数小时前），
        // 直接沿用会让大屏的 PV / 访客数长时间卡住不动。故区间含今日时，用明细重算覆盖今日。
        if ($liveRange && !in_array($today, $missing, true)) {
            $missing[] = $today;
        }
        if (!empty($missing)) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            foreach (
                Db::select(
                    "SELECT `day`, SUM(type='pageview') pv,
                            COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv,
                            COUNT(DISTINCT CASE WHEN type='pageview' AND ip<>'' THEN ip END) ipc
                     FROM events WHERE site_id=? AND `day` IN ($ph) GROUP BY `day`",
                    [$sid, ...$missing]
                ) as $r
            ) {
                $have[(string) $r['day']] = array_merge(self::emptyDaily((string) $r['day']), [
                    'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'ipc' => (int) $r['ipc'],
                ]);
            }
            foreach (
                Db::select(
                    "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d, COUNT(*) c,
                            COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                            COALESCE(SUM(is_new),0) nu
                     FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                    [$offset, $sid, $prevStartTs, $endTs]
                ) as $r
            ) {
                $d = (string) $r['d'];
                if (isset($have[$d])) {
                    $have[$d]['visits'] = (int) $r['c'];
                    $have[$d]['bounce'] = (int) $r['b'];
                    $have[$d]['duration'] = (int) $r['du'];
                    $have[$d]['new_users'] = (int) $r['nu'];
                }
            }
        }

        // 今日「进行中会话」（仍驻留 Redis、尚未回收落库）并入今日访问次数/跳出/时长/新访客，
        // 与 PV 的实况口径保持一致；失败仅忽略，不影响大屏其余数据。
        try {
            $redisLive = Rds::get();
            if ($liveRange && $redisLive !== null) {
                [$liveTs0, $liveTs1] = Util::dateRangeToTs($today, $today, $tz);
                $liveRows = Sessionizer::liveRows($redisLive, $sid, $liveTs0, $liveTs1);
                if ($liveRows) {
                    $base = $have[$today] ?? self::emptyDaily($today);
                    $lb = 0;
                    $ld = 0;
                    $ln = 0;
                    foreach ($liveRows as $lv) {
                        $lb += (int) $lv['bounce'];
                        $ld += (int) $lv['duration'];
                        $ln += (int) ($lv['is_new'] ?? 0);
                    }
                    $base['visits'] = (int) ($base['visits'] ?? 0) + count($liveRows);
                    $base['bounce'] = (int) ($base['bounce'] ?? 0) + $lb;
                    $base['duration'] = (int) ($base['duration'] ?? 0) + $ld;
                    $base['new_users'] = (int) ($base['new_users'] ?? 0) + $ln;
                    $have[$today] = $base;
                }
            }
        } catch (\Throwable $e) { /* 忽略实时修正失败 */ }

        $sum = ['pv' => 0, 'uv' => 0, 'ipc' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
        $prevSum = ['pv' => 0, 'uv' => 0, 'ipc' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
        $trend = [];
        foreach ($days_ as $d) {
            $r = $have[$d] ?? self::emptyDaily($d);
            if ($d >= $start) {
                foreach (array_keys($sum) as $k) {
                    $sum[$k] += (int) $r[$k];
                }
                $trend[] = ['day' => $d, 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'visits' => (int) $r['visits']];
            } else {
                foreach (array_keys($prevSum) as $k) {
                    $prevSum[$k] += (int) $r[$k];
                }
            }
        }
        // 区间 UV / 独立 IP：跨天必须去重 —— 逐日相加会把同一访客 / IP 在多天里重复计数（旧问题）。
        // 统一走 exactUniq()，与概览、IP 地域页同一口径；环比窗同理。
        $uniq = $this->exactUniq($sid, $start, $end);
        $sum['uv'] = $uniq['uv'];
        $sum['ipc'] = $uniq['ipc'];
        $prevUniq = $this->exactUniq($sid, $prevStart, date('Y-m-d', $startTs - 86400));
        $prevSum['uv'] = $prevUniq['uv'];
        $prevSum['ipc'] = $prevUniq['ipc'];

        $bounceRate = $sum['visits'] > 0 ? round($sum['bounce'] / $sum['visits'] * 100, 1) : 0.0;
        $avgDur = $sum['visits'] > 0 ? (int) round($sum['duration'] / $sum['visits']) : 0;
        $prevBounceRate = $prevSum['visits'] > 0 ? round($prevSum['bounce'] / $prevSum['visits'] * 100, 1) : 0.0;
        $prevAvgDur = $prevSum['visits'] > 0 ? (int) round($prevSum['duration'] / $prevSum['visits']) : 0;

        // ---- 区间末日分时 PV（区间为单日时前端用它画折线） ----
        $hourly = array_fill(0, 24, 0);
        foreach (
            Db::select(
                "SELECT HOUR(FROM_UNIXTIME(ts+?)) h, COUNT(*) c FROM events
                 WHERE site_id=? AND type='pageview' AND `day`=? GROUP BY h",
                [$offset, $sid, $end]
            ) as $r
        ) {
            $hourly[(int) $r['h']] = (int) $r['c'];
        }

        // ---- 区间 TOP 聚合（$col 均来自下方字面量调用，无注入面） ----
        $agg = function (string $col, int $limit = 10, string $cond = '') use ($sid, $start, $end): array {
            $out = [];
            foreach (
                Db::select(
                    "SELECT `$col` AS name, COUNT(*) pv FROM events
                     WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $cond
                     GROUP BY `$col` ORDER BY pv DESC LIMIT $limit",
                    [$sid, $start, $end]
                ) as $r
            ) {
                $out[] = ['name' => (string) $r['name'], 'pv' => (int) $r['pv']];
            }
            return $out;
        };
        // 入口/退出页来自会话表（访问次数口径，与区间严格对齐）
        $sessTop = function (string $col, int $limit = 10) use ($sid, $startTs, $endTs): array {
            $out = [];
            foreach (
                Db::select(
                    "SELECT `$col` AS name, COUNT(*) pv FROM sessions
                     WHERE site_id=? AND start_ts>=? AND start_ts<? AND `$col`<>''
                     GROUP BY `$col` ORDER BY pv DESC LIMIT $limit",
                    [$sid, $startTs, $endTs]
                ) as $r
            ) {
                $out[] = ['name' => (string) $r['name'], 'pv' => (int) $r['pv']];
            }
            return $out;
        };
        // TOP IP（PV + 独立访客）
        $ips = [];
        foreach (
            Db::select(
                "SELECT ip AS name, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv FROM events
                 WHERE site_id=? AND type='pageview' AND ip<>'' AND `day` BETWEEN ? AND ?
                 GROUP BY ip ORDER BY pv DESC LIMIT 10",
                [$sid, $start, $end]
            ) as $r
        ) {
            $ips[] = ['name' => (string) $r['name'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
        }
        // 受访页数（区间内去重 URL）
        $pagesCount = (int) Db::value(
            "SELECT COUNT(DISTINCT url) FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        );

        // ---- 实时在线：Redis 模式走 5 分钟窗口 zset；无 Redis 模式走 sessions 活跃数 ----
        if (Rds::enabled()) {
            $online = Rds::safe(function ($r) use ($sid) {
                return (int) $r->zcount('on:' . $sid, (string) (time() - 300), (string) (time() + 1));
            }, 0);
        } else {
            $online = self::onlineCountDb($sid);
        }

        wstat_json([
            'site' => ['id' => (int) $site['id'], 'name' => $site['name'], 'domain' => $site['domain']],
            'now' => time(),
            'today' => $today,
            'days' => $days,
            'range' => [
                'start' => $start, 'end' => $end, 'days' => $days,
                'single_day' => $start === $end,
                'is_today' => $end === $today,
                'hourly_day' => $end,
            ],
            'totals' => [
                'pv' => $sum['pv'], 'uv' => $sum['uv'], 'ip' => $sum['ipc'],
                'visits' => $sum['visits'], 'bounce_rate' => $bounceRate, 'avg_duration' => $avgDur,
                'new_users' => $sum['new_users'], 'pages' => $pagesCount, 'online' => $online,
            ],
            'prev' => [
                'pv' => $prevSum['pv'], 'uv' => $prevSum['uv'], 'ip' => $prevSum['ipc'],
                'visits' => $prevSum['visits'], 'bounce_rate' => $prevBounceRate, 'avg_duration' => $prevAvgDur,
                'new_users' => $prevSum['new_users'],
            ],
            'trend' => $trend,
            'hourly' => $hourly,
            'paths' => [
                'pages' => $agg('url'),
                'entries' => $sessTop('entry_url'),
                'exits' => $sessTop('exit_url'),
            ],
            'sources' => [
                'channels' => $agg('source'),
                'referrers' => $agg('ref_host', 10, "AND ref_host<>''"),
            ],
            'env' => [
                'browsers' => $agg('browser', 10, "AND browser<>''"),
                'os' => $agg('os', 10, "AND os<>''"),
                'devices' => $agg('device', 10, "AND device<>''"),
            ],
            'geo' => [
                'countries' => $agg('country', 40, "AND country<>''"),
                'provinces' => $agg('province', 40, "AND province<>''"),
                'cities' => $agg('city', 10, "AND city<>''"),
            ],
            'ips' => $ips,
        ]);
    }
}
