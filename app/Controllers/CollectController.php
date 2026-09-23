<?php
/**
 * 采集控制器（数据入口 /collect.php）
 *
 * 职责：
 *  1. 校验站点 (site_key) 与基础字段
 *  2. Redis 实时计数（今日 PV/UV/IP、分钟 PV、在线访客）—— 毫秒级热路径
 *  3. 原始事件压入队列，由 worker 批量落库（避免高并发直写 MySQL）
 *  4. Redis 不可用时降级为直写（单页会话近似），保证不丢点
 *
 * 响应保持最小体积，采集端仅关心 HTTP 200。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Db;
use Wstat\Support\IpLocator;
use Wstat\Support\RateLimiter;
use Wstat\Support\Rds;
use Wstat\Support\RedisGuard;
use Wstat\Support\Referrer;
use Wstat\Support\SearchEngine;
use Wstat\Support\Sessionizer;
use Wstat\Support\Settings;
use Wstat\Support\SiteStore;
use Wstat\Support\Spider;
use Wstat\Support\SpiderLog;
use Wstat\Support\UaParser;
use Wstat\Support\Util;

class CollectController
{
    private const TYPES = ['pageview', 'event', 'perf', 'click', 'scroll', 'hb', 'outlink', 'download', 'search'];
    private const MAX_QUEUE = 200000;               // 队列积压保护
    private const RATE_PER_MIN = 300;               // 同站点同 IP 每分钟上报上限（正常页面远低于此值）
    private const SPIDER_RATE_PER_MIN = 300;        // 同站点同 IP 每分钟**蜘蛛记账**上限（独立桶，见 handle()）
    private const MAX_PAYLOAD = 8192;               // 事件附加数据 JSON 上限（字节）

    /**
     * 1×1 透明 GIF（42 字节，base64）。
     * 图片信标模式（/pixel.php，用于邮件打开率 / 无 JS 环境）下，**无论成功或失败**都回它：
     *  - 图片永远能正常渲染，不破图；
     *  - 不向被统计页面的访问者泄露「站点是否启用/是否验证」这类采集状态。
     */
    public const PIXEL_GIF_B64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** 响应形态：json（/collect.php、/api/collect）/ gif（/pixel.php 图片信标） */
    private string $out;

    public function __construct(string $out = 'json')
    {
        $this->out = $out === 'gif' ? 'gif' : 'json';
    }

    /** 入口 */
    public function handle(Request $req): void
    {
        // ⚠️ 每个 fail() 之后必须显式 return：
        // JSON 模式下 fail() 走 wstat_err → wstat_json → exit(0)，自然终止；
        // 但**图片信标模式**下 fail() 只输出 GIF 就返回（不能让图片破图），
        // 少了 return 会继续往下跑：坏 ak 会走到 $site['status'] 抛错，
        // 报错文本被追加进图片体 → 破图，且可能继续写脏数据。
        // ---- 全局采集开关（系统设置 → 功能开关）----
        if (!Settings::int('collect_enabled')) {
            $this->fail('collect disabled', 403);
            return;
        }

        // ---- 站点校验 ----
        $siteKey = trim((string) $req->input('ak', ''));
        $site = SiteStore::byKey($siteKey);
        if ($site === null) {
            $this->fail('bad site', 404);
            return;
        }
        if ((int) $site['status'] !== 1) {
            $this->fail('site disabled', 403);
            return;
        }
        // 仅已验证站点才允许采集上报（SDK 生效前提）。本地联调可设 WSTAT_ALLOW_UNVERIFIED=1 放开。
        $allowUnverified = (bool) (getenv('WSTAT_ALLOW_UNVERIFIED') ?: '0');
        if (!$allowUnverified && (int) $site['verified_at'] === 0) {
            $this->fail('site unverified', 403);
            return;
        }

        $sid = (int) $site['id'];
        $type = (string) $req->input('t', 'pageview');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'pageview';
        }

        // ---- 访客 / 会话 / 上下文 ----
        $uid = self::id($req->input('uid', ''), 40);
        $sidStr = self::id($req->input('sid', ''), 40);
        $endUser = self::endUser($req->input('ui', ''));
        $url = self::safeUrl((string) $req->input('url', ''), 600);
        $title = self::cut((string) $req->input('title', ''), 255);
        $ref = self::safeUrl((string) $req->input('ref', ''), 600);

        // ---- 301/302 来源保真：跳板跳转时以 ?wstat_ref=<真源> 携带。
        // SDK v1.5+ 已优先上报并从 url 剔除该参数；旧版 SDK 仍会把它留在 url 里 ——
        // 这里兜底提取，并从存库 url 中剔除以免污染页面明细。
        // wstat_ref 是跳板显式声明的完整真源，信息量 ≥ 被浏览器按 Referrer-Policy
        // 裁剪的 referrer（跨域往往只剩 origin），与 UTM 同属「客户端声明、只影响归因」
        // 的信任级别 —— 存在即采信。
        $uParts = parse_url($url);
        if (is_array($uParts) && !empty($uParts['query']) && strpos($uParts['query'], 'wstat_ref=') !== false) {
            $wref = '';
            parse_str($uParts['query'], $uq);
            if (isset($uq['wstat_ref']) && is_string($uq['wstat_ref'])) {
                $wref = self::safeUrl($uq['wstat_ref'], 600);
            }
            $qs = trim((string) preg_replace('/(^|&)wstat_ref=[^&]*/', '', (string) $uParts['query']), '&');
            $url = (string) ($uParts['path'] ?? '') . ($qs !== '' ? '?' . $qs : '');
            if ($wref !== '') {
                $ref = $wref;
            }
        }
        $screen = self::cut((string) $req->input('scr', ''), 24);
        $lang = self::cut((string) $req->input('lang', ''), 16);
        $tzMin = (int) $req->input('tz', 0);
        if ($tzMin < -720 || $tzMin > 840) {
            $tzMin = 0;
        }
        $ip = Util::clientIp();
        $now = time();
        $uaRaw = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaRow = UaParser::parse($uaRaw);

        // ---- 蜘蛛统计（与下面的过滤**互相独立**）：识别为爬虫就先记一笔蜘蛛账。
        // 覆盖面说明：服务端接入（/spider.php）负责「不执行 JS 的爬虫」（绝大多数），
        // 这里负责「爬虫恰好也执行了 JS」的兜底（Googlebot / 移动版 Baiduspider 常见），
        // 站长零接入也能看到一部分数据。爬虫数据只落 spider_hits，**不参与任何访客指标**，
        // 所以「记账」与「过滤开关」是两件事、互不影响。
        // 写库前过一道按 (站点,IP) 的限流桶：爬虫从少量 IP 高频抓取，避免把写库打成主要开销。
        $spiderInfo = Spider::detect($uaRaw);
        if ($spiderInfo['bot']) {
            $sLimit = filter_var(getenv('WSTAT_RATE_LIMIT') ?: self::SPIDER_RATE_PER_MIN, FILTER_VALIDATE_INT);
            if ($sLimit === false
                || RateLimiter::allow('spider:' . $sid . ':' . ($ip !== '' ? $ip : 'unknown'), $sLimit)) {
                SpiderLog::hit(
                    $sid,
                    Util::localDay($now, $tzMin !== 0 ? $tzMin : (int) round(Util::tzOffsetSec((string) $site['timezone']) / 60)),
                    $spiderInfo['name'],
                    SpiderLog::cleanUrl($url),
                    0,
                    0,
                    $now
                );
            }
        }

        // ---- 爬虫过滤（系统设置可关）：命中爬虫/HTTP 客户端 UA 静默丢弃。
        // 返回 200 而非 403 —— 大多数爬虫会对 4xx 重试，静默丢弃可避免刷日志与放大流量。
        if (Settings::int('bot_filter_enabled') && $spiderInfo['bot']) {
            $this->tiny();
            return;
        }

        // ---- 频率限制：同站点同 IP 固定窗口计数（防恶意刷量/重放）。
        // 同样静默丢弃（200）：返回 429 只会触发对方重试，放大攻击流量。
        // Redis 不可用时 fail-open 放行（限流不得成为可用性故障点）。
        // WSTAT_RATE_LIMIT 环境变量可覆盖限额（自测用；0=不限）。
        $limit = filter_var(getenv('WSTAT_RATE_LIMIT') ?: self::RATE_PER_MIN, FILTER_VALIDATE_INT);
        if ($limit !== false && !RateLimiter::allow('collect:' . $sid . ':' . ($ip !== '' ? $ip : 'unknown'), $limit)) {
            $this->tiny();
            return;
        }

        // UTM 五参数 + ClickID（SDK 已做首触留存，服务端再次判定）
        $utm = [
            'source' => self::cut((string) $req->input('utm_source', ''), 128),
            'medium' => self::cut((string) $req->input('utm_medium', ''), 128),
            'campaign' => self::cut((string) $req->input('utm_campaign', ''), 128),
            'content' => self::cut((string) $req->input('utm_content', ''), 128),
            'term' => self::cut((string) $req->input('utm_term', ''), 128),
        ];
        $clickId = self::cut((string) $req->input('click_id', ''), 128);
        $clickSource = self::cut((string) $req->input('click_name', ''), 64);
        $cls = Referrer::classify($ref, (string) $site['domain'], $utm, $clickId);

        // ---- 事件明细（落库形态，worker 消费） ----
        $extra = $req->input('d');
        $payload = null;
        if (is_array($extra) && count($extra) > 0) {
            $payload = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // 超限/编码失败一律置空：拒绝超大附加数据占库
            $payload = is_string($payload) && strlen($payload) <= self::MAX_PAYLOAD ? $payload : null;
        }
        $evt = [
            'day' => Util::localDay($now, $tzMin !== 0 ? $tzMin : (int) round(Util::tzOffsetSec((string) $site['timezone']) / 60)),
            'site_id' => $sid,
            'session_id' => $sidStr,
            'visitor_id' => $uid,
            'end_user' => $endUser,
            'type' => $type,
            'ts' => $now,
            'url' => $url,
            'title' => $title,
            'ref' => $ref,
            'browser' => $uaRow['browser'],
            'os' => $uaRow['os'],
            'device' => $uaRow['device'],
            'screen' => $screen,
            'lang' => $lang,
            'ip' => $ip,
            'country' => '',
            'province' => '',
            'city' => '',
            'source' => $cls['type'],
            'medium' => $cls['medium'],
            'utm_source' => $utm['source'],
            'utm_medium' => $utm['medium'],
            'campaign' => $utm['campaign'],
            'content' => $utm['content'],
            'term' => $utm['term'],
            'click_id' => $clickId,
            'click_source' => $clickSource,
            'ref_host' => (string) ($cls['host'] ?? ''),
            // 搜索引擎来路关键词：仅 search 来源从 ref 提取（拿不到=空，报表归「未提供」）
            'kw' => $cls['type'] === 'search' ? SearchEngine::kw($ref) : '',
            'payload' => $payload,
        ];

        // 心跳事件只刷新在线状态，不落库不入队
        if ($type === 'hb') {
            $this->touchOnline($sid, $sidStr);
            $this->tiny();
            return;
        }

        // ---- IP 归属解析（离线 xdb 本地检索，微秒级；失败/未识别保持空串） ----
        if (($evt['country'] ?? '') === '' && $ip !== '') {
            $geo = IpLocator::resolve($ip);
            $evt['country'] = (string) ($geo['country'] ?? '');
            $evt['province'] = (string) ($geo['province'] ?? '');
            $evt['city'] = (string) ($geo['city'] ?? '');
        }

        $redis = Rds::get();
        if ($redis !== null) {
            // ---- 热路径：实时计数 + 队列 ----
            // 任何 Redis 异常（含键类型污染 WRONGTYPE、超时、断连）都不得让采集返回 500：
            // 捕获后置空 $redis，走下方降级直写，保证不丢点。
            try {
                $this->realtime($redis, $sid, $sidStr, $tzMin, $type, $now);
                // hb 已提前返回；白名单内的其余类型（pageview/event/perf/click/scroll/outlink/download/search）一律入队
                $queueLen = (int) $redis->llen('queue');
                if ($queueLen < self::MAX_QUEUE) {
                    $redis->lpush('queue', json_encode($evt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Throwable $e) {
                $redis = null;
                // WRONGTYPE 单独标记：便于与网络抖动区分（后者可自愈，前者必须人工处理）
                $tag = RedisGuard::isWrongType($e) ? 'WRONGTYPE(键类型被污染)' : 'redis error';
                error_log(sprintf(
                    '[wstat] collect 热路径 %s，已降级直写 MySQL；键类型可用 php %s 审计：%s',
                    $tag,
                    wstat_rel('scripts/doctor.php'),
                    $e->getMessage()
                ));
            }
        }
        if ($redis === null) {
            // ---- 降级：直写（事件表；自动剔除未迁移列容错） ----
            Db::insertBatch('events', [Db::filterColumns('events', $evt)]);
            if ($type === 'pageview') {
                $this->degradedSession($evt);
            }
        }
        $this->tiny();
    }

    /* ================= 内部 ================= */

    /**
     * 采集热路径的实时计数（写入 Redis）。
     *
     * 注意：自 2026-09-15 起这里**不再写 `uv:` / `ip:` 两个 HyperLogLog 键**。
     * 原因：HLL 是估算结构，在极小基数下会多估，直接导致「概览 IP 数 4 / IP 地域页 3」这类
     * 口径不一致；现在 UV 与独立 IP 一律由 events 明细精确去重
     * （见 StatsController::exactUniq / todayExact），今日实时数字同样取自 events。
     * 保留 `today:pv` / `rt:min:` / 在线 ZSet 等精确实时计数不变。
     */
    private function realtime($r, int $sid, string $sidStr, int $tzMin, string $type, int $now): void
    {
        $ymd = gmdate('Ymd', $now + $tzMin * 60);
        if ($type === 'pageview') {
            $r->hincrby("today:$sid", "$ymd:pv", 1);
            $r->hincrby("rt:min:$sid", gmdate('YmdHi', $now + $tzMin * 60), 1);
            Sessionizer::markLive($r, $sid, $sidStr, $now);   // 进入“进行中会话”，实时可见
        }
        $this->touchOnline($sid, $sidStr, $r);
    }

    private function touchOnline(int $sid, string $sidStr, $r = null): void
    {
        if ($sidStr === '') {
            return;
        }
        $now = time();
        $fn = function ($r) use ($sid, $sidStr, $now) {
            $r->zadd("on:$sid", (float) $now, $sidStr);
        };
        if ($r !== null) {
            $fn($r);
        } else {
            Rds::safe($fn);
        }
    }

    /**
     * 降级/无 Redis 模式：会话累计行。
     * 同 session 多次 pageview 自动累加 pageviews、刷新离开时间并近似结算时长；
     * 写失败仅记 error_log，绝不阻断采集（events 已先行落库）。
     */
    private function degradedSession(array $evt): void
    {
        try {
            $now = $evt['ts'];
            // 老库可能还没有 end_user 列（batch3 未执行）：有则一并写入，无则省略
            static $hasEndUser = null;
            if ($hasEndUser === null) {
                $hasEndUser = array_key_exists('end_user', Db::tableColumns('sessions'));
            }
            // 列与值**全部占位符绑定**（不写字面量常量）：一旦列数/顺序与参数不一致，
            // 下方自检立刻拦下。历史事故：字面量 1,0,1,0 夹在占位符序列中间时多写了一个
            // `?`，整段值右移一位 —— pageviews 绑定到 URL 字符串（→0）、entry_url 绑到字面量
            // 0（→入口页显示 “0”）、duration/bounce/is_new 全错，且占位符数与参数数「同时
            // 多一个」使旧自检形同虚设。
            // 顺序：site_id,session_id,visitor_id[,end_user],start_ts,end_ts,
            //      pageviews,duration,bounce,is_new,entry_url,exit_url,
            //      source,medium,campaign,content,term,click_id,
            //      browser,os,device,screen,lang,ip,country,province,city,created_at
            $dup = ' ON DUPLICATE KEY UPDATE
                     pageviews=pageviews+1,
                     end_ts=VALUES(end_ts),
                     duration=VALUES(end_ts)-start_ts,
                     bounce=0,
                     exit_url=VALUES(exit_url)';
            if ($hasEndUser) {
                $sql = 'INSERT INTO sessions
                   (site_id,session_id,visitor_id,end_user,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,content,term,click_id,
                    browser,os,device,screen,lang,ip,country,province,city,created_at)
                   VALUES (' . implode(',', array_fill(0, 28, '?')) . ')' . $dup;
                $args = [
                    $evt['site_id'], $evt['session_id'], $evt['visitor_id'], $evt['end_user'],
                    $now, $now, 1, 0, 1, 0,
                    $evt['url'], $evt['url'],
                    $evt['source'], $evt['medium'], $evt['campaign'], $evt['content'], $evt['term'], $evt['click_id'],
                    $evt['browser'], $evt['os'], $evt['device'], $evt['screen'], $evt['lang'],
                    $evt['ip'], $evt['country'], $evt['province'], $evt['city'], $now,
                ];
            } else {
                $sql = 'INSERT INTO sessions
                   (site_id,session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,content,term,click_id,
                    browser,os,device,screen,lang,ip,country,province,city,created_at)
                   VALUES (' . implode(',', array_fill(0, 27, '?')) . ')' . $dup;
                $args = [
                    $evt['site_id'], $evt['session_id'], $evt['visitor_id'],
                    $now, $now, 1, 0, 1, 0,
                    $evt['url'], $evt['url'],
                    $evt['source'], $evt['medium'], $evt['campaign'], $evt['content'], $evt['term'], $evt['click_id'],
                    $evt['browser'], $evt['os'], $evt['device'], $evt['screen'], $evt['lang'],
                    $evt['ip'], $evt['country'], $evt['province'], $evt['city'], $now,
                ];
            }
            // 自检：占位符数量必须与绑定参数一致（防再次错位）
            if (substr_count($sql, '?') !== count($args)) {
                error_log('[wstat] degradedSession 占位符/参数数量不一致，跳过会话直写');
                return;
            }
            Db::execute($sql, $args);
        } catch (\Throwable $e) {
            error_log('[wstat] degradedSession failed: ' . $e->getMessage());
        }
    }

    /** 极简成功体（按响应形态分支：JSON 或 1×1 GIF） */
    private function tiny(): void
    {
        if ($this->out === 'gif') {
            $this->pix();
            return;
        }
        wstat_json(['ok' => 1], 200);
    }

    /**
     * 失败响应：JSON 模式沿用 wstat_err（带 HTTP 状态码便于 SDK 排查）；
     * 图片信标模式静默回 1×1 GIF + 200 —— 图片信标不应对访问者产生任何可感知差异，
     * 也不该让邮件客户端的隐私代理从状态码推断站点状态（与爬虫静默丢弃同一设计取向）。
     */
    private function fail(string $msg, int $http): void
    {
        if ($this->out === 'gif') {
            $this->pix();
            return;
        }
        wstat_err($msg, $http);
    }

    /** 输出 1×1 透明 GIF（图片信标专用，永不缓存） */
    private function pix(): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: image/gif');
            // 信标必须每次真实回源：缓存住就等于丢失后续打开/点击的计数
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        echo base64_decode(self::PIXEL_GIF_B64);
    }

    private static function id($v, int $maxLen): string
    {
        $v = (string) $v;
        if (!preg_match('/^[A-Za-z0-9_\-]{8,' . $maxLen . '}$/', $v)) {
            return '';
        }
        return $v;
    }

    private static function cut(string $s, int $len): string
    {
        $s = trim($s);
        if (mb_strlen($s) > $len) {
            $s = mb_substr($s, 0, $len);
        }
        return $s;
    }

    /**
     * 页面 URL / Referer 白名单：仅接受 http(s) 绝对地址或站内相对路径。
     * 拒绝 javascript: / data: / file: 等协议 —— 这类值一旦入库，
     * 会在管理端各报表的「链接直达」跳转处成为存储型 XSS 载体。
     */
    private static function safeUrl(string $s, int $len): string
    {
        $s = self::cut($s, $len);
        if ($s === '') {
            return '';
        }
        // 去掉控制字符与不可见空白（防御性：URL 正常不含这些）
        $s = preg_replace('/[\x00-\x1F\x7F]+/', '', $s) ?? '';
        return preg_match('#^(https?://|/)#i', $s) === 1 ? $s : '';
    }

    /** 终端用户标识（SDK identify 上报）：字母数字与 _. - @ + :，1-128 位；不合法返回空 */
    private static function endUser($v): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_.\-@+:]{1,128}$/', $v) === 1 ? $v : '';
    }
}
