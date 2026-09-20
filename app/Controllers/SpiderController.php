<?php
/**
 * 蜘蛛爬虫统计控制器。
 *
 * POST /spider.php             服务端蜘蛛上报（站点侧接入；公开端点，站点 key 鉴权）
 * GET  /api/stats/spiders      蜘蛛统计报表（登录 + 站点权限）
 *
 * 为什么需要服务端上报端点：
 *   被统计站点上跑的 SDK 是 JS，而**绝大多数字节流爬虫不执行 JS** —— 只靠 JS 侧
 *   永远看不到它们。真实产品（百度统计等）的蜘蛛统计同样来自服务端侧数据。
 *   因此提供一行式服务端接入（PHP include / auto_prepend_file，或任何语言发一个 GET），
 *   由站点在「疑似爬虫请求」上把 UA + URL 转给我们 ——
 *   判定是否爬虫、以及是哪只爬虫，**都在服务端做**（客户端只做廉价的预筛）。
 *
 * 隔离性：爬虫数据只落 spider_hits，**永不进入 events / sessions / site_daily**，
 * 因此对 PV/UV/会话等任何访客指标零影响。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Db;
use Wstat\Support\RateLimiter;
use Wstat\Support\Settings;
use Wstat\Support\SiteAccess;
use Wstat\Support\SiteStore;
use Wstat\Support\Spider;
use Wstat\Support\SpiderLog;
use Wstat\Support\Util;

class SpiderController
{
    private const MAX_URL = 600;
    private const RATE_PER_MIN = 600;   // 同站点同 IP 每分钟上报上限（爬虫突发抓取足够用）

    /**
     * 服务端接入用的代码模板（占位符 {host} / {site_key}，由前端替换）。
     *
     * 客户端只做**廉价预筛**（UA 里含 bot/spider/crawl 等字样），
     * 真正的「是不是爬虫 + 是谁」由本控制器判定 —— 预筛放宽/收紧都不会造成误记账：
     * 多报的会被服务端丢掉，少报的只是漏统计。
     */
    public const PHP_SNIPPET = <<<'PHP'
<?php
// ---- WebStats 蜘蛛统计（仅疑似爬虫请求会上报，人类访客零开销）----
// 建议放到站点公共入口文件最前，或 php.ini 的 auto_prepend_file；也可作为 nginx 的
// fastcgi_param 之外的兜底。上报是「尽力而为」的：失败绝不影响页面输出。
$__ws_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($__ws_ua !== '' && preg_match('/bot|spider|crawl|slurp|httpclient|curl|wget|python|java\//i', $__ws_ua)) {
    $__ws_url = '{host}/spider.php?' . http_build_query([
        'ak'  => '{site_key}',
        'url' => $_SERVER['REQUEST_URI'] ?? '/',
        'ua'  => $__ws_ua,
    ]);
    if (function_exists('curl_init')) {
        $__ws_ch = curl_init($__ws_url);
        curl_setopt_array($__ws_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_NOSIGNAL => true]);
        curl_exec($__ws_ch);
        curl_close($__ws_ch);
    } else {
        @file_get_contents($__ws_url);
    }
}
PHP;

    /**
     * POST|GET /spider.php —— 服务端蜘蛛上报。
     *
     * 参数：ak=站点 key，url=被抓取路径（或完整 URL），ua=爬虫 UA（缺省用本次请求的 UA）
     * 语义：命中爬虫 → 记账并返回 {"ok":true,"spider":"..."}；
     *       非爬虫 / 开关关闭 / 表缺失 → 静默 204（接入方无需判断，可无条件调用）。
     */
    public function collect(Request $req): void
    {
        // 采集总开关关闭时连爬虫也不记（与访客采集保持同一个「停止采集」语义）
        if (!Settings::int('collect_enabled') || !Settings::int('spider_enabled')) {
            self::noContent();
        }

        $site = SiteStore::byKey(trim((string) $req->input('ak', '')));
        if ($site === null || (int) $site['status'] !== 1) {
            self::noContent();
        }
        $sid = (int) $site['id'];

        $ua = (string) $req->input('ua', '');
        if (trim($ua) === '') {
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        }
        $hit = Spider::detect($ua);
        if (!$hit['bot']) {
            self::noContent();          // 客户端预筛放宽报上来的正常访客：直接忽略
        }
        if (!SpiderLog::ready()) {
            self::noContent();          // 表缺失（未跑增量 SQL）：不报错，避免站点侧一直重试
        }

        // 频率限制：同站点同 IP 固定窗口计数。超限静默丢弃（不给 429，避免对方重试放大流量）
        $limit = filter_var(getenv('WSTAT_RATE_LIMIT') ?: self::RATE_PER_MIN, FILTER_VALIDATE_INT);
        $ip = Util::clientIp();
        if ($limit !== false && !RateLimiter::allow('spider:' . $sid . ':' . ($ip !== '' ? $ip : 'unknown'), $limit)) {
            self::noContent();
        }

        $now = time();
        $tzMin = (int) round(Util::tzOffsetSec((string) $site['timezone']) / 60);
        $url = SpiderLog::cleanUrl(self::cut((string) $req->input('url', ''), self::MAX_URL));
        $spider = $hit['name'];

        $ok = SpiderLog::hit($sid, Util::localDay($now, $tzMin), $spider, $url, 0, 0, $now);
        if (!$ok) {
            self::noContent();
        }
        // 显式声明 200：本端点是「有响应就说明记账成功」的语义，不能依赖 SAPI 默认状态码
        // （否则任何中间层改过默认码都会让接入方误判；也便于自测直接断言）。
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true,"spider":' . json_encode($spider, JSON_UNESCAPED_UNICODE) . '}';
        exit(0);
    }

    /**
     * GET /api/stats/spiders —— 蜘蛛统计报表。
     *
     * 返回：概览（次数/页面数/爬虫数/字节/最近抓取）、爬虫排行、类别构成、
     *       日趋势（TOP6 爬虫 + 其它）、被爬页面 TOP、服务端接入代码。
     */
    public function stats(Request $req): void
    {
        $site = SiteAccess::site($req, (int) $req->input('site_id', 0), SiteAccess::VIEWER);
        $sid = (int) $site['id'];
        [$start, $end] = $this->range($req, (string) $site['timezone']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, (string) $site['timezone']);

        $out = [
            'range'   => ['start' => $start, 'end' => $end],
            'enabled' => Settings::int('spider_enabled') === 1,
            'table_ready' => SpiderLog::ready() || $this->tableExists(),
            'snippet' => ['php' => self::PHP_SNIPPET, 'endpoint' => '{host}/spider.php'],
        ];

        if (!$this->tableExists()) {
            // 未跑增量 SQL：返回空壳 + 明确的修复指引，前端照常渲染（不 500）
            wstat_json($out + [
                'summary'  => ['hits' => 0, 'pages' => 0, 'spiders' => 0, 'bytes' => 0, 'last_ts' => 0, 'last_spider' => '', 'days' => 0],
                'spiders'  => [],
                'kinds'    => [],
                'trend'    => ['days' => [], 'series' => []],
                'pages'    => [],
            ]);
            return;
        }

        $w = 'site_id=? AND `day` BETWEEN ? AND ?';
        $args = [$sid, $start, $end];

        // 概览
        $sum = Db::first(
            "SELECT COUNT(DISTINCT url_md5) pages, COUNT(DISTINCT spider) spiders,
                    COALESCE(SUM(hits),0) hits, COALESCE(SUM(bytes),0) bytes, COALESCE(MAX(last_ts),0) last_ts
             FROM " . SpiderLog::TABLE . " WHERE $w",
            $args
        ) ?: [];

        // 爬虫排行（含每只爬虫的页面数与最近抓取时间）
        $spiders = [];
        foreach (
            Db::select(
                "SELECT spider, COALESCE(SUM(hits),0) hits, COUNT(DISTINCT url_md5) pages, COALESCE(MAX(last_ts),0) last_ts
                 FROM " . SpiderLog::TABLE . " WHERE $w GROUP BY spider ORDER BY hits DESC LIMIT 50",
                $args
            ) as $r
        ) {
            $name = (string) $r['spider'];
            $spiders[] = [
                'spider'  => $name,
                'kind'    => Spider::kindOfName($name),
                'hits'    => (int) $r['hits'],
                'pages'   => (int) $r['pages'],
                'last_ts' => (int) $r['last_ts'],
            ];
        }
        $totalHits = 0;
        foreach ($spiders as $r) {
            $totalHits += $r['hits'];
        }
        foreach ($spiders as $i => $r) {
            $spiders[$i]['share'] = $totalHits > 0 ? round($r['hits'] * 100 / $totalHits, 1) : 0.0;
        }

        // 类别构成（类别由爬虫名反推，不在库里冗余存储）
        $kinds = [];
        foreach ($spiders as $r) {
            $k = $r['kind'];
            if (!isset($kinds[$k])) {
                $kinds[$k] = ['kind' => $k, 'hits' => 0, 'spiders' => 0];
            }
            $kinds[$k]['hits'] += $r['hits'];
            $kinds[$k]['spiders']++;
        }
        $kinds = array_values($kinds);
        usort($kinds, static fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

        // 日趋势：TOP6 爬虫单列，其余并入「其它」
        $days = [];
        foreach (Util::dailySeries($startTs, $endTs, (string) $site['timezone'], static fn () => null) as $d => $_) {
            $days[] = (string) $d;
        }
        $dayIdx = array_flip($days);
        $topNames = array_slice(array_column($spiders, 'spider'), 0, 6);
        $series = [];
        foreach ($topNames as $n) {
            $series[$n] = array_fill(0, count($days), 0);
        }
        $series['__other__'] = array_fill(0, count($days), 0);
        $dayTotals = array_fill(0, count($days), 0);
        foreach (
            Db::select(
                "SELECT `day` d, spider, COALESCE(SUM(hits),0) hits
                 FROM " . SpiderLog::TABLE . " WHERE $w GROUP BY `day`, spider ORDER BY d",
                $args
            ) as $r
        ) {
            $i = $dayIdx[(string) $r['d']] ?? -1;
            if ($i < 0) {
                continue;
            }
            $hits = (int) $r['hits'];
            $dayTotals[$i] += $hits;
            $n = (string) $r['spider'];
            $key = isset($series[$n]) ? $n : '__other__';
            $series[$key][$i] += $hits;
        }
        $seriesRows = [];
        foreach ($series as $n => $vals) {
            $seriesRows[] = ['spider' => $n === '__other__' ? '' : $n, 'other' => $n === '__other__', 'data' => $vals];
        }

        // 被爬页面 TOP
        $pages = [];
        foreach (
            Db::select(
                "SELECT url, COALESCE(SUM(hits),0) hits, COUNT(DISTINCT spider) spiders, COALESCE(MAX(last_ts),0) last_ts
                 FROM " . SpiderLog::TABLE . " WHERE $w GROUP BY url_md5, url ORDER BY hits DESC LIMIT 30",
                $args
            ) as $r
        ) {
            $pages[] = [
                'url'     => (string) $r['url'],
                'hits'    => (int) $r['hits'],
                'spiders' => (int) $r['spiders'],
                'last_ts' => (int) $r['last_ts'],
            ];
        }

        // 有抓取记录的天数 + 最活跃的爬虫
        $activeDays = 0;
        foreach ($dayTotals as $v) {
            if ($v > 0) {
                $activeDays++;
            }
        }

        $out['summary'] = [
            'hits'        => (int) ($sum['hits'] ?? 0),
            'pages'       => (int) ($sum['pages'] ?? 0),
            'spiders'     => (int) ($sum['spiders'] ?? 0),
            'bytes'       => (int) ($sum['bytes'] ?? 0),
            'last_ts'     => (int) ($sum['last_ts'] ?? 0),
            'last_spider' => $spiders[0]['spider'] ?? '',
            'days'        => $activeDays,
            'range_days'  => count($days),
        ];
        $out['spiders'] = $spiders;
        $out['kinds'] = $kinds;
        $out['trend'] = ['days' => $days, 'series' => $seriesRows, 'totals' => $dayTotals];
        $out['pages'] = $pages;
        wstat_json($out);
    }

    private function tableExists(): bool
    {
        try {
            return array_key_exists('url', Db::tableColumns(SpiderLog::TABLE));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 日期区间（默认最近 30 天，与其它统计页口径一致）。
     * 报表页对区间**宽松处理**（解析不了就用默认值），避免一个脏参数把整页变成报错。
     */
    private function range(Request $req, string $tz): array
    {
        $offset = Util::tzOffsetSec($tz);
        $start = self::ymd((string) $req->input('start', ''), gmdate('Y-m-d', time() + $offset - 29 * 86400));
        $end = self::ymd((string) $req->input('end', ''), gmdate('Y-m-d', time() + $offset));
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        // 上限 366 天：误传超长区间不至于把库拖死
        if (strtotime($end) - strtotime($start) > 365 * 86400) {
            $start = gmdate('Y-m-d', (int) strtotime($end . ' -365 days'));
        }
        return [$start, $end];
    }

    /** 宽松归一为 YYYY-MM-DD；无法解析时回落到默认值 */
    private static function ymd(string $v, string $def): string
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
            return $v;
        }
        if ($v !== '') {
            $ts = strtotime($v);
            if (is_int($ts) && $ts > 0) {
                return gmdate('Y-m-d', $ts);
            }
        }
        return $def;
    }

    /** 截断 + 去控制字符 */
    private static function cut(string $s, int $max): string
    {
        $s = (string) (preg_replace('/[\x00-\x1F\x7F]+/', '', trim($s)) ?? '');
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    /** 静默无内容（接入方无需判断响应，可无条件调用） */
    private static function noContent(): void
    {
        http_response_code(204);
        exit(0);
    }
}
