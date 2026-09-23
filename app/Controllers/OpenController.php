<?php
/**
 * 开放 API（/open/v1/*）：个人访问令牌（PAT）鉴权，只读统计白名单。
 *
 * 鉴权：Authorization: Bearer <64位token>（api_tokens 表，见 Support\PAT）。
 * 授权：令牌属主对该站点具备任意角色（owner/editor/viewer 均可只读）。
 * 限额：暂不启用硬性限流；last_used_at 节流刷新用于审计。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Db;
use Wstat\Support\PAT;
use Wstat\Support\Rds;
use Wstat\Support\Referrer;
use Wstat\Support\SiteAccess;
use Wstat\Support\Util;

class OpenController
{
    /** 与 StatsController::URL_PATH_EXPR 同款路径表达式（跨控制器私有，故此处独立维护） */
    private const URL_PATH_EXPR = "IF(LOCATE('//', url) > 0, IF(LOCATE('/', url, LOCATE('//', url) + 2) > 0, SUBSTRING(url, LOCATE('/', url, LOCATE('//', url) + 2)), '/'), url)";

    /**
     * GET /open/v1/overview?site_id=&start=&end=
     * 概览：totals（pv/uv/ip/visits/bounce_rate/avg_duration/new_users）+ daily 逐日序列。
     * 口径与看板概览一致：events 按 day（站点时区本地日）、sessions 按站点本地日聚合。
     */
    public function overview(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $offset = Util::tzOffsetSec((string) $site['timezone']);

        $daily = [];
        foreach (Util::dailySeries($startTs, $endTs, (string) $site['timezone'], fn () => null) as $d => $_) {
            $daily[(string) $d] = ['day' => (string) $d, 'pv' => 0, 'uv' => 0, 'ip' => 0,
                'visits' => 0, 'bounce_rate' => 0.0, 'avg_duration' => 0.0, 'new_users' => 0];
        }

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
            if (isset($daily[$d])) {
                $daily[$d]['pv'] = (int) $r['pv'];
                $daily[$d]['uv'] = (int) $r['uv'];
                $daily[$d]['ip'] = (int) $r['ipc'];
            }
        }
        foreach (
            Db::select(
                "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d, COUNT(*) c,
                        COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du, COALESCE(SUM(is_new),0) nw
                 FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                [$offset, $sid, $startTs, $endTs]
            ) as $r
        ) {
            $d = (string) $r['d'];
            if (isset($daily[$d])) {
                $c = (int) $r['c'];
                $daily[$d]['visits'] = $c;
                $daily[$d]['bounce_rate'] = $c > 0 ? round((int) $r['b'] / $c * 100, 1) : 0.0;
                $daily[$d]['avg_duration'] = $c > 0 ? round((int) $r['du'] / $c) : 0.0;
                $daily[$d]['new_users'] = (int) $r['nw'];
            }
        }

        $rows = array_values($daily);
        $t = ['pv' => 0, 'uv' => 0, 'ip' => 0, 'visits' => 0, 'bounce' => 0, 'duration' => 0, 'new_users' => 0];
        foreach ($rows as $r) {
            $t['pv'] += $r['pv'];
            $t['visits'] += $r['visits'];
            $t['bounce'] += round($r['bounce_rate'] * $r['visits'] / 100);
            $t['duration'] += round($r['avg_duration'] * $r['visits']);
            $t['new_users'] += $r['new_users'];
        }
        // 区间 UV / 独立 IP：跨日必须去重（逐日相加会把同一访客 / IP 在多天里重复计数）。
        // 与看板概览（StatsController::exactUniq）同一条 SQL，保证开放接口与面板数字一致。
        $uniq = Db::first(
            "SELECT COUNT(DISTINCT visitor_id) uv, COUNT(DISTINCT CASE WHEN ip<>'' THEN ip END) ip
             FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        ) ?: [];
        $t['uv'] = (int) ($uniq['uv'] ?? 0);
        $t['ip'] = (int) ($uniq['ip'] ?? 0);
        $totals = [
            'pv' => $t['pv'], 'uv' => $t['uv'], 'ip' => $t['ip'],
            'visits' => $t['visits'],
            'bounce_rate' => $t['visits'] > 0 ? round($t['bounce'] / $t['visits'] * 100, 1) : 0.0,
            'avg_duration' => $t['visits'] > 0 ? round($t['duration'] / $t['visits']) : 0.0,
            'new_users' => $t['new_users'],
        ];

        wstat_json([
            'site'   => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range'  => ['start' => $start, 'end' => $end],
            'totals' => $totals,
            'daily'  => $rows,
        ]);
    }

    /**
     * GET /open/v1/pages?site_id=&start=&end=&limit=50
     * 热门页面 TOP N（按 PV 降序，附 UV）。
     */
    public function pages(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $limit = min(200, max(1, (int) $req->input('limit', 50)));
        $items = [];
        foreach (
            Db::select(
                'SELECT ' . self::URL_PATH_EXPR . ' AS path,
                        SUM(type=\'pageview\') pv,
                        COUNT(DISTINCT CASE WHEN type=\'pageview\' THEN visitor_id END) uv
                 FROM events
                 WHERE site_id=? AND `day` BETWEEN ? AND ? AND type=\'pageview\'
                 GROUP BY path ORDER BY pv DESC LIMIT ' . $limit,
                [(int) $site['id'], $start, $end]
            ) as $r
        ) {
            $items[] = ['path' => (string) $r['path'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
        }
        wstat_json([
            'site'  => ['id' => (int) $site['id'], 'name' => $site['name'], 'domain' => $site['domain']],
            'range' => ['start' => $start, 'end' => $end],
            'items' => $items,
        ]);
    }

    /**
     * GET /open/v1/online?site_id=
     * 当前在线访客数（5 分钟窗口，Redis zset）。
     */
    public function online(Request $req): void
    {
        [$site] = $this->site($req);
        $sid = (int) $site['id'];
        $now = time();
        wstat_json([
            'site'  => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'online' => $this->onlineCount($sid, $now),
            'time'  => $now,
        ]);
    }

    /**
     * 当前在线数（5 分钟窗口）。
     * Redis 模式取在线 ZSet（精确到秒）；无 Redis 模式退化为 sessions 表近 5 分钟活跃会话数。
     */
    private function onlineCount(int $sid, int $now): int
    {
        if (!Rds::enabled()) {
            return (int) Db::value(
                'SELECT COUNT(*) FROM sessions WHERE site_id=? AND end_ts>=? AND start_ts<=?',
                [$sid, $now - 300, $now]
            );
        }
        return (int) Rds::safe(function ($r) use ($sid, $now) {
            return $r->zcount("on:$sid", (string) ($now - 300), (string) ($now + 1));
        });
    }

    /**
     * GET /open/v1/realtime?site_id=&minutes=30
     * 实时窗口（默认近 30 分钟，1..180）：PV / UV / 独立 IP + 当前在线数。
     * 注意不是「今日累计」——需要今日累计请用 /overview（start=end=今天）。
     */
    public function realtime(Request $req): void
    {
        [$site] = $this->site($req);
        $sid = (int) $site['id'];
        $now = time();
        $minutes = min(180, max(1, (int) $req->input('minutes', 30)));
        $offset = Util::tzOffsetSec((string) $site['timezone']);
        $from = $now - $minutes * 60;
        // 窗口可能跨站点本地零点：day 取窗口两端各自所属的本地日（至多两个），
        // 既能走 day 索引，又不会漏掉跨零点的那部分。
        $d0 = gmdate('Y-m-d', $from + $offset);
        $d1 = gmdate('Y-m-d', $now + $offset);
        $row = Db::first(
            "SELECT COUNT(*) pv,
                    COUNT(DISTINCT visitor_id) uv,
                    COUNT(DISTINCT CASE WHEN ip<>'' THEN ip END) ipc
             FROM events
             WHERE site_id=? AND type='pageview' AND `day` IN (?,?) AND ts>=? AND ts<=?",
            [$sid, $d0, $d1, $from, $now]
        ) ?: [];
        wstat_json([
            'site'    => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'minutes' => $minutes,
            'window'  => ['from' => $from, 'to' => $now],
            'totals'  => [
                'pv' => (int) ($row['pv'] ?? 0),
                'uv' => (int) ($row['uv'] ?? 0),
                'ip' => (int) ($row['ipc'] ?? 0),
            ],
            'online' => $this->onlineCount($sid, $now),
            'time'   => $now,
        ]);
    }

    /**
     * GET /open/v1/sources?site_id=&start=&end=&limit=20
     * 来源分析：渠道构成（source 类型）+ 外链主机 TOP + UTM 组合 TOP。
     */
    public function sources(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $sid = (int) $site['id'];
        $limit = min(100, max(1, (int) $req->input('limit', 20)));
        $w = 'site_id=? AND type="pageview" AND `day` BETWEEN ? AND ?';
        $args = [$sid, $start, $end];

        $channels = [];
        foreach (
            Db::select(
                "SELECT source k, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events WHERE $w GROUP BY source ORDER BY pv DESC",
                $args
            ) as $r
        ) {
            $channels[] = ['source' => (string) $r['k'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
        }

        // 归因扩展列可能尚未迁移：缺失时对应块返回空数组，不抛 500
        $cols = Db::tableColumns('events');
        $referrers = [];
        if (isset($cols['ref_host'])) {
            foreach (
                Db::select(
                    "SELECT ref_host host, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                     FROM events WHERE $w AND ref_host<>''
                     GROUP BY ref_host ORDER BY pv DESC LIMIT " . $limit,
                    $args
                ) as $r
            ) {
                $referrers[] = ['host' => (string) $r['host'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
            }
        }

        $utms = [];
        if (isset($cols['utm_source'])) {
            foreach (
                Db::select(
                    "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                     FROM events
                     WHERE $w AND (utm_source<>'' OR utm_medium<>'' OR utm_campaign<>'')
                     GROUP BY utm_source, utm_medium, utm_campaign ORDER BY pv DESC LIMIT " . $limit,
                    $args
                ) as $r
            ) {
                $utms[] = [
                    'source'   => (string) $r['utm_source'],
                    'medium'   => (string) $r['utm_medium'],
                    'campaign' => (string) $r['utm_campaign'],
                    'pv'       => (int) $r['pv'],
                    'uv'       => (int) $r['uv'],
                ];
            }
        }

        wstat_json([
            'site'      => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range'     => ['start' => $start, 'end' => $end],
            'channels'  => $channels,
            'referrers' => $referrers,
            'utms'      => $utms,
        ]);
    }

    /**
     * GET /open/v1/geo?site_id=&start=&end=&limit=40
     * 地域分布（sessions 口径，与面板「地域」页一致）：国家 TOP + 中国省份 TOP。
     */
    public function geo(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $sid = (int) $site['id'];
        $limit = min(200, max(1, (int) $req->input('limit', 40)));
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $w = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [$sid, $startTs, $endTs];

        $countries = [];
        foreach (
            Db::select(
                "SELECT country, COUNT(*) visits, COUNT(DISTINCT visitor_id) uv,
                        COALESCE(SUM(pageviews),0) pv
                 FROM sessions WHERE $w AND country<>''
                 GROUP BY country ORDER BY visits DESC LIMIT " . $limit,
                $args
            ) as $r
        ) {
            $countries[] = [
                'country' => (string) $r['country'],
                'visits'  => (int) $r['visits'],
                'uv'      => (int) $r['uv'],
                'pv'      => (int) $r['pv'],
            ];
        }
        $provinces = [];
        foreach (
            Db::select(
                "SELECT province, COUNT(*) visits, COUNT(DISTINCT visitor_id) uv,
                        COALESCE(SUM(pageviews),0) pv
                 FROM sessions WHERE $w AND country IN ('中国','China') AND province<>''
                 GROUP BY province ORDER BY visits DESC LIMIT " . $limit,
                $args
            ) as $r
        ) {
            $provinces[] = [
                'province' => (string) $r['province'],
                'visits'   => (int) $r['visits'],
                'uv'       => (int) $r['uv'],
                'pv'       => (int) $r['pv'],
            ];
        }
        wstat_json([
            'site'      => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range'     => ['start' => $start, 'end' => $end],
            'total_visits' => (int) Db::value("SELECT COUNT(*) FROM sessions WHERE $w", $args),
            'countries' => $countries,
            'provinces' => $provinces,
        ]);
    }

    /**
     * GET /open/v1/engines?site_id=&start=&end=&limit=50
     * 搜索引擎：按来路主域归并的引擎排行 + 搜索词 TOP。
     * 口径与面板一致：只统计 source='search' 的 pageview；kw 由采集端从 referrer 提取。
     */
    public function engines(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $sid = (int) $site['id'];
        $limit = min(200, max(1, (int) $req->input('limit', 50)));
        $offset = Util::tzOffsetSec((string) $site['timezone']);
        $w = "site_id=? AND type=\"pageview\" AND source='search' AND `day` BETWEEN ? AND ?";
        $args = [$sid, $start, $end];

        $engines = [];   // 展示名 => ['pv'=>n,'uv'=>n,'no_kw_pv'=>n]
        $terms = [];
        $engineUv = [];  // 展示名 => 去重访客集合（跨 host 归并后精确去重）
        foreach (
            Db::select(
                "SELECT ref_host host, visitor_id, kw, COUNT(*) pv
                 FROM events WHERE $w AND ref_host<>''
                 GROUP BY ref_host, visitor_id, kw",
                $args
            ) as $r
        ) {
            $brand = Referrer::brand((string) $r['host']);
            $pv = (int) $r['pv'];
            $kw = trim((string) $r['kw']);
            $engines[$brand]['pv'] = ($engines[$brand]['pv'] ?? 0) + $pv;
            if ($kw === '') {
                $engines[$brand]['no_kw_pv'] = ($engines[$brand]['no_kw_pv'] ?? 0) + $pv;
            } else {
                $terms[$brand . "\0" . $kw]['brand'] = $brand;
                $terms[$brand . "\0" . $kw]['kw'] = $kw;
                $terms[$brand . "\0" . $kw]['pv'] = ($terms[$brand . "\0" . $kw]['pv'] ?? 0) + $pv;
            }
            if ((string) $r['visitor_id'] !== '') {
                $engineUv[$brand][(string) $r['visitor_id']] = true;
            }
        }
        foreach ($engines as $name => $v) {
            $engines[$name]['engine'] = $name;
            $engines[$name]['uv'] = isset($engineUv[$name]) ? count($engineUv[$name]) : 0;
            $engines[$name]['no_kw_pv'] = $v['no_kw_pv'] ?? 0;
        }
        $engines = array_values($engines);
        usort($engines, fn ($a, $b) => $b['pv'] <=> $a['pv']);
        $engines = array_slice($engines, 0, $limit);

        $terms = array_values($terms);
        usort($terms, fn ($a, $b) => $b['pv'] <=> $a['pv']);
        $terms = array_slice($terms, 0, $limit);

        wstat_json([
            'site'    => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range'   => ['start' => $start, 'end' => $end],
            'engines' => $engines,
            'terms'   => $terms,
        ]);
    }

    /**
     * GET /open/v1/events?site_id=&start=&end=&limit=50
     * 自定义事件 TOP（type=event，按 payload.n 事件名聚合）+ 自动事件（出链/下载/站内搜索）。
     */
    public function events(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $sid = (int) $site['id'];
        $limit = min(200, max(1, (int) $req->input('limit', 50)));

        $agg = static function (string $type, string $field) use ($sid, $start, $end, $limit): array {
            $rows = Db::select(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) k,
                        COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events
                 WHERE site_id=? AND type=? AND `day` BETWEEN ? AND ? AND payload IS NOT NULL
                 GROUP BY k HAVING k IS NOT NULL AND k <> ''
                 ORDER BY pv DESC LIMIT " . $limit,
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
            'site'      => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range'     => ['start' => $start, 'end' => $end],
            'custom'    => $agg('event', 'n'),
            'outlinks'  => $agg('outlink', 'to'),
            'downloads' => $agg('download', 'file'),
            'searches'  => $agg('search', 'term'),
        ]);
    }

    /**
     * GET /open/v1/sessions?site_id=&start=&end=&page=1&size=50
     * 会话明细（导出 / 对接用）。默认剔除访客标识之外的敏感信息无关项，只读。
     */
    public function sessions(Request $req): void
    {
        [$site, $start, $end] = $this->site($req);
        $sid = (int) $site['id'];
        $page = max(1, (int) $req->input('page', 1));
        $size = min(200, max(1, (int) $req->input('size', 50)));
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $w = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [$sid, $startTs, $endTs];

        $total = (int) Db::value("SELECT COUNT(*) FROM sessions WHERE $w", $args);
        $items = [];
        foreach (
            Db::select(
                "SELECT session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                        entry_url,exit_url,source,medium,campaign,browser,os,device,country,province,city
                 FROM sessions WHERE $w ORDER BY start_ts DESC LIMIT " . (($page - 1) * $size) . ",$size",
                $args
            ) as $r
        ) {
            $items[] = [
                'session_id'  => (string) $r['session_id'],
                'visitor_id'  => (string) $r['visitor_id'],
                'start_ts'    => (int) $r['start_ts'],
                'end_ts'      => (int) $r['end_ts'],
                'pageviews'   => (int) $r['pageviews'],
                'duration'    => (int) $r['duration'],
                'bounce'      => (int) $r['bounce'] === 1,
                'is_new'      => (int) $r['is_new'] === 1,
                'entry_url'   => (string) $r['entry_url'],
                'exit_url'    => (string) $r['exit_url'],
                'source'      => (string) $r['source'],
                'medium'      => (string) $r['medium'],
                'campaign'    => (string) $r['campaign'],
                'browser'     => (string) $r['browser'],
                'os'          => (string) $r['os'],
                'device'      => (string) $r['device'],
                'country'     => (string) $r['country'],
                'province'    => (string) $r['province'],
                'city'        => (string) $r['city'],
            ];
        }
        wstat_json([
            'site'  => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'range' => ['start' => $start, 'end' => $end],
            'page'  => $page,
            'size'  => $size,
            'total' => $total,
            'items' => $items,
        ]);
    }

    /** PAT 鉴权 + 站点访问校验，返回 [站点行, start, end] */
    private function site(Request $req): array
    {
        $u = PAT::user($req);
        if ($u === null) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'invalid_token', 'message' => '无效或过期的访问令牌'], JSON_UNESCAPED_UNICODE);
            exit(0);
        }
        $sid = (int) $req->input('site_id', 0);
        $site = Db::first(
            'SELECT id,name,domain,site_key,timezone FROM sites WHERE id=? AND status=1 LIMIT 1',
            [$sid]
        );
        if ($site === null || SiteAccess::role((int) $u['id'], $sid) === null) {
            wstat_err('站点不存在', 404, 404);
        }
        // 日期区间（站点时区本地日，默认近 30 天）
        $offset = Util::tzOffsetSec((string) $site['timezone']);
        $start = (string) $req->input('start', gmdate('Y-m-d', time() + $offset - 29 * 86400));
        $end = (string) $req->input('end', gmdate('Y-m-d', time() + $offset));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            wstat_err('日期格式应为 YYYY-MM-DD', 422);
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        return [$site, $start, $end];
    }
}
