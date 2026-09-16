<?php
/**
 * 数据导出控制器（CSV）
 *
 * 统一入口：GET /api/export/{kind}?site_id=&start=&end=[&筛选参数]
 *   kind = daily      按日 PV / UV / 独立IP / 访问次数 / 跳出率 / 平均访问时长
 *        | channels   来源渠道构成
 *        | referrers  外链来源（referrer 主机名）
 *        | pages      页面路径（按完整 URL 聚合）
 *        | entries    入口页
 *        | exits      退出页
 *        | sessions   会话明细（支持 q/browser/os/device/source 筛选，最多 5万行）
 *        | geo        国家 / 省份 / 城市
 *        | devices / browsers / oses / langs   环境维度
 *
 * 设计要点：
 *   1) 一律直接聚合 events / sessions（不依赖 site_daily 预聚合），保证导出数字与
 *      「会话/明细」口径一致，不会因 rollup 未跑而缺行；
 *   2) 输出带 UTF-8 BOM，Excel 双击不乱码；文件名同时给 ASCII 与 filename*（中文名）；
 *   3) 只读权限即可导出（复用 SiteAccess，viewer 也能拿到自己可见站点的数据）；
 *   4) 每类导出都设行数上限，避免超大站点一次性拉爆内存。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Db;
use Wstat\Support\SiteAccess;
use Wstat\Support\Util;

class ExportController
{
    /** 单次导出行数上限 */
    private const MAX_ROWS = 50000;

    /** 日期区间上限（与 StatsController 保持一致） */
    private const MAX_RANGE_DAYS = 366;

    /** 分组维度的行数上限（榜单类） */
    private const MAX_GROUP = 5000;

    /**
     * GET /api/export/{kind}
     * 路由统一指向本方法，kind 由路由参数给出。
     */
    public function handle(Request $req): void
    {
        $kind = (string) $req->param('kind', 'daily');
        $site = SiteAccess::site($req, (int) $req->input('site_id', 0));
        [$start, $end] = $this->range($req);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);
        $sid = (int) $site['id'];
        $tz = (string) $site['timezone'];

        $stamp = $start === $end ? $start : ($start . '_' . $end);
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $site['domain']);

        switch ($kind) {
            case 'daily':
                [$header, $rows] = $this->daily($sid, $start, $end, $tz);
                $this->csv("wstat-daily-$base-$stamp.csv", "流量趋势(PV-UV)_$stamp.csv", $header, $rows);
                break;
            case 'channels':
                [$header, $rows] = $this->groupByEvents('source', "AND source<>''", $sid, $start, $end, ['来源渠道', 'PV', '占比(%)']);
                $this->csv("wstat-channels-$base-$stamp.csv", "来源渠道_$stamp.csv", $header, $rows);
                break;
            case 'referrers':
                [$header, $rows] = $this->groupByEvents('ref_host', "AND ref_host<>''", $sid, $start, $end, ['外链来源', 'PV', '占比(%)']);
                $this->csv("wstat-referrers-$base-$stamp.csv", "外链来源_$stamp.csv", $header, $rows);
                break;
            case 'pages':
                [$header, $rows] = $this->groupByEvents('url', '', $sid, $start, $end, ['页面URL', 'PV', '占比(%)']);
                $this->csv("wstat-pages-$base-$stamp.csv", "页面分析_$stamp.csv", $header, $rows);
                break;
            case 'entries':
                [$header, $rows] = $this->groupBySessions('entry_url', $sid, $startTs, $endTs, ['入口页', 'PV', '占比(%)']);
                $this->csv("wstat-entries-$base-$stamp.csv", "入口页_$stamp.csv", $header, $rows);
                break;
            case 'exits':
                [$header, $rows] = $this->groupBySessions('exit_url', $sid, $startTs, $endTs, ['退出页', 'PV', '占比(%)']);
                $this->csv("wstat-exits-$base-$stamp.csv", "退出页_$stamp.csv", $header, $rows);
                break;
            case 'sessions':
                [$header, $rows] = $this->sessions($req, $sid, $startTs, $endTs);
                $this->csv("wstat-sessions-$base-$stamp.csv", "会话明细_$stamp.csv", $header, $rows);
                break;
            case 'geo':
                [$header, $rows] = $this->geo($sid, $start, $end);
                $this->csv("wstat-geo-$base-$stamp.csv", "地域分布_$stamp.csv", $header, $rows);
                break;
            case 'devices':
                [$header, $rows] = $this->groupByEvents('device', "AND device<>''", $sid, $start, $end, ['设备', 'PV', '占比(%)']);
                $this->csv("wstat-devices-$base-$stamp.csv", "设备分布_$stamp.csv", $header, $rows);
                break;
            case 'browsers':
                [$header, $rows] = $this->groupByEvents('browser', "AND browser<>''", $sid, $start, $end, ['浏览器', 'PV', '占比(%)']);
                $this->csv("wstat-browsers-$base-$stamp.csv", "浏览器分布_$stamp.csv", $header, $rows);
                break;
            case 'oses':
                [$header, $rows] = $this->groupByEvents('os', "AND os<>''", $sid, $start, $end, ['操作系统', 'PV', '占比(%)']);
                $this->csv("wstat-oses-$base-$stamp.csv", "操作系统分布_$stamp.csv", $header, $rows);
                break;
            case 'langs':
                [$header, $rows] = $this->groupByEvents('lang', "AND lang<>''", $sid, $start, $end, ['语言', 'PV', '占比(%)']);
                $this->csv("wstat-langs-$base-$stamp.csv", "语言分布_$stamp.csv", $header, $rows);
                break;
            default:
                wstat_err('不支持的导出类型: ' . $kind, 422);
        }
    }

    /* ==================== 各导出数据集 ==================== */

    /** 按日趋势（零填充完整日期轴） */
    private function daily(int $sid, string $start, string $end, string $tz): array
    {
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, $tz);
        $offset = Util::tzOffsetSec($tz);

        $ev = [];
        foreach (
            Db::select(
                "SELECT `day`,
                        SUM(type='pageview') pv,
                        COUNT(DISTINCT CASE WHEN type='pageview' THEN visitor_id END) uv,
                        COUNT(DISTINCT CASE WHEN type='pageview' THEN ip END) ipc
                 FROM events WHERE site_id=? AND `day` BETWEEN ? AND ? GROUP BY `day`",
                [$sid, $start, $end]
            ) as $r
        ) {
            $ev[(string) $r['day']] = [
                'pv' => (int) $r['pv'], 'uv' => (int) $r['uv'], 'ipc' => (int) $r['ipc'],
            ];
        }

        $ss = [];
        foreach (
            Db::select(
                "SELECT DATE(FROM_UNIXTIME(start_ts+?)) d,
                        COUNT(*) c, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du
                 FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? GROUP BY d",
                [$offset, $sid, $startTs, $endTs]
            ) as $r
        ) {
            $ss[(string) $r['d']] = ['visits' => (int) $r['c'], 'bounce' => (int) $r['b'], 'dur' => (int) $r['du']];
        }

        $header = ['日期', '浏览量(PV)', '访客数(UV)', '独立IP', '访问次数', '跳出率(%)', '平均访问时长(秒)'];
        $rows = [];
        // 完整日期轴（用 UTC 日推进，避免 DST 误算；本站时区已折算到 day 字段）
        $cur = strtotime($start . ' 00:00:00 UTC');
        $last = strtotime($end . ' 00:00:00 UTC');
        for (; $cur <= $last; $cur += 86400) {
            $d = gmdate('Y-m-d', $cur);
            $e = $ev[$d] ?? ['pv' => 0, 'uv' => 0, 'ipc' => 0];
            $s = $ss[$d] ?? ['visits' => 0, 'bounce' => 0, 'dur' => 0];
            $visits = (int) $s['visits'];
            $rows[] = [
                $d,
                (int) $e['pv'],
                (int) $e['uv'],
                (int) $e['ipc'],
                $visits,
                $visits > 0 ? round((int) $s['bounce'] / $visits * 100, 2) : 0,
                $visits > 0 ? round((int) $s['dur'] / $visits, 1) : 0,
            ];
        }
        return [$header, $rows];
    }

    /** events 维度聚合（$col 为内部白名单，无注入面） */
    private function groupByEvents(string $col, string $cond, int $sid, string $start, string $end, array $header): array
    {
        $total = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        );
        $rows = [];
        foreach (
            Db::select(
                "SELECT `$col` AS name, COUNT(*) AS pv
                 FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ? $cond
                 GROUP BY `$col` ORDER BY pv DESC LIMIT " . self::MAX_GROUP,
                [$sid, $start, $end]
            ) as $r
        ) {
            $name = (string) $r['name'];
            $pv = (int) $r['pv'];
            $rows[] = [$name === '' ? '(空)' : $name, $pv, $total > 0 ? round($pv / $total * 100, 2) : 0];
        }
        return [$header, $rows];
    }

    /** sessions 维度聚合（入口/退出页） */
    private function groupBySessions(string $col, int $sid, int $startTs, int $endTs, array $header): array
    {
        $rows = Db::select(
            "SELECT `$col` AS name, COALESCE(SUM(pageviews),0) AS pv
             FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<? AND `$col`<>''
             GROUP BY `$col` ORDER BY pv DESC LIMIT " . self::MAX_GROUP,
            [$sid, $startTs, $endTs]
        );
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['pv'];
        }
        $out = [];
        foreach ($rows as $r) {
            $pv = (int) $r['pv'];
            $out[] = [(string) $r['name'], $pv, $total > 0 ? round($pv / $total * 100, 2) : 0];
        }
        return [$header, $out];
    }

    /** 会话明细（筛选逻辑与 /api/stats/sessions 保持一致） */
    private function sessions(Request $req, int $sid, int $startTs, int $endTs): array
    {
        $where = 'site_id=? AND start_ts>=? AND start_ts<?';
        $args = [$sid, $startTs, $endTs];

        $q = trim((string) $req->input('q', ''));
        if ($q !== '') {
            $like = '%' . $this->likeEscape($q) . '%';
            $ipLike = isset(Db::tableColumns('sessions')['ip']) ? " OR ip LIKE ? ESCAPE '\\\\'" : '';
            $where .= " AND (visitor_id LIKE ? ESCAPE '\\\\' OR entry_url LIKE ? ESCAPE '\\\\'$ipLike)";
            $args[] = $like;
            $args[] = $like;
            if ($ipLike !== '') {
                $args[] = $like;
            }
        }
        foreach (['browser', 'os', 'device', 'source'] as $col) {
            $val = trim((string) $req->input($col, ''));
            if ($val !== '') {
                $where .= " AND $col LIKE ? ESCAPE '\\\\'";
                $args[] = '%' . $this->likeEscape($val) . '%';
            }
        }

        $ipCol = isset(Db::tableColumns('sessions')['ip']) ? ',ip' : '';
        $rows = Db::select(
            "SELECT session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,click_id,
                    browser,os,device,screen,lang,country,province,city$ipCol
             FROM sessions WHERE $where ORDER BY start_ts DESC LIMIT " . self::MAX_ROWS,
            $args
        );

        $header = [
            '会话ID', '访客ID', '开始时间', '结束时间', '浏览页数', '时长(秒)', '是否跳出', '是否新访客',
            '入口页', '退出页', '来源', '媒介', '广告系列', 'ClickID',
            '浏览器', '操作系统', '设备', '屏幕', '语言', '国家', '省份', '城市', 'IP',
        ];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                (string) $r['session_id'],
                (string) $r['visitor_id'],
                date('Y-m-d H:i:s', (int) $r['start_ts']),
                date('Y-m-d H:i:s', (int) $r['end_ts']),
                (int) $r['pageviews'],
                (int) $r['duration'],
                (int) $r['bounce'] ? '是' : '否',
                (int) $r['is_new'] ? '是' : '否',
                (string) $r['entry_url'],
                (string) $r['exit_url'],
                (string) $r['source'],
                (string) $r['medium'],
                (string) $r['campaign'],
                (string) $r['click_id'],
                (string) $r['browser'],
                (string) $r['os'],
                (string) $r['device'],
                (string) $r['screen'],
                (string) $r['lang'],
                (string) $r['country'],
                (string) $r['province'],
                (string) $r['city'],
                (string) ($r['ip'] ?? ''),
            ];
        }
        return [$header, $out];
    }

    /** 地域分布（国家 / 省份 / 城市三层，去重 PV） */
    private function geo(int $sid, string $start, string $end): array
    {
        $header = ['国家/地区', '省份', '城市', 'PV', '占比(%)'];
        $total = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        );
        $rows = [];
        foreach (
            Db::select(
                "SELECT country,province,city,COUNT(*) pv
                 FROM events
                 WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?
                   AND (country<>'' OR province<>'' OR city<>'')
                 GROUP BY country,province,city ORDER BY pv DESC LIMIT " . self::MAX_GROUP,
                [$sid, $start, $end]
            ) as $r
        ) {
            $pv = (int) $r['pv'];
            $rows[] = [
                (string) $r['country'], (string) $r['province'], (string) $r['city'],
                $pv, $total > 0 ? round($pv / $total * 100, 2) : 0,
            ];
        }
        return [$header, $rows];
    }

    /* ==================== 输出与工具 ==================== */

    /**
     * 输出 CSV 并结束请求。
     * $asciiName 给老浏览器（Content-Disposition filename），$utf8Name 给现代浏览器（filename*）。
     */
    private function csv(string $asciiName, string $utf8Name, array $header, array $rows): void
    {
        // 丢弃此前可能的杂散输出，避免污染 CSV 正文。
        // 只清空、不销毁最外层缓冲：销毁所有层级会连带破坏调用方（如 CLI 自检
        // 脚本用 ob_start() 回收响应体）的缓冲，使其拿到空响应。
        while (ob_get_level() > 1) {
            ob_end_clean();
        }
        if (ob_get_level() > 0) {
            ob_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header(
            'Content-Disposition: attachment; filename="' . $asciiName . '"; '
            . "filename*=UTF-8''" . rawurlencode($utf8Name)
        );
        header('Cache-Control: no-store');
        header('X-Export-Rows: ' . count($rows));

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit(0);
        }
        fwrite($out, "\xEF\xBB\xBF");                    // UTF-8 BOM：Excel 识别编码
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit(0);
    }

    /** 校验/默认日期区间（与 StatsController::range 同语义） */
    private function range(Request $req): array
    {
        $today = date('Y-m-d');
        $start = $this->ymd((string) $req->input('start', date('Y-m-d', strtotime('-29 days'))));
        $end = $this->ymd((string) $req->input('end', $today));
        if ($start === '' || $end === '') {
            wstat_err('日期格式应为 YYYY-MM-DD', 422);
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if (strtotime($end) - strtotime($start) > (self::MAX_RANGE_DAYS - 1) * 86400) {
            wstat_err('导出区间最长 366 天', 422);
        }
        return [$start, $end];
    }

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
            return (string) substr($v, 0, 10);
        }
        $ts = strtotime($v);
        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    private function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
