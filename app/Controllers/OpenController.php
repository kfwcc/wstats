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
        if (!\Wstat\Support\Rds::enabled()) {
            // 无 Redis 模式：sessions 近 5 分钟活跃
            $count = (int) Db::value(
                'SELECT COUNT(*) FROM sessions WHERE site_id=? AND end_ts>=? AND start_ts<=?',
                [$sid, $now - 300, $now]
            );
        } else {
            $count = (int) Rds::safe(function ($r) use ($sid, $now) {
                return $r->zcount("on:$sid", (string) ($now - 300), (string) ($now + 1));
            });
        }
        wstat_json([
            'site'  => ['id' => $sid, 'name' => $site['name'], 'domain' => $site['domain']],
            'online' => $count,
            'time'  => $now,
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
