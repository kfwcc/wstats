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
use Wstat\Support\Sessionizer;
use Wstat\Support\Settings;
use Wstat\Support\SiteStore;
use Wstat\Support\UaParser;
use Wstat\Support\Util;

class CollectController
{
    private const TYPES = ['pageview', 'event', 'perf', 'click', 'scroll', 'hb', 'outlink', 'download', 'search'];
    private const MAX_QUEUE = 200000;               // 队列积压保护
    private const RATE_PER_MIN = 300;               // 同站点同 IP 每分钟上报上限（正常页面远低于此值）
    private const MAX_PAYLOAD = 8192;               // 事件附加数据 JSON 上限（字节）

    /** 入口 */
    public function handle(Request $req): void
    {
        // ---- 全局采集开关（系统设置 → 功能开关）----
        if (!Settings::int('collect_enabled')) {
            wstat_err('collect disabled', 403);
        }

        // ---- 站点校验 ----
        $siteKey = trim((string) $req->input('ak', ''));
        $site = SiteStore::byKey($siteKey);
        if ($site === null) {
            wstat_err('bad site', 404);
        }
        if ((int) $site['status'] !== 1) {
            wstat_err('site disabled', 403);
        }
        // 仅已验证站点才允许采集上报（SDK 生效前提）。本地联调可设 WSTAT_ALLOW_UNVERIFIED=1 放开。
        $allowUnverified = (bool) (getenv('WSTAT_ALLOW_UNVERIFIED') ?: '0');
        if (!$allowUnverified && (int) $site['verified_at'] === 0) {
            wstat_err('site unverified', 403);
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

        // ---- 爬虫过滤（系统设置可关）：命中爬虫/HTTP 客户端 UA 静默丢弃。
        // 返回 200 而非 403 —— 大多数爬虫会对 4xx 重试，静默丢弃可避免刷日志与放大流量。
        if (Settings::int('bot_filter_enabled') && UaParser::isBot($uaRaw)) {
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
            // 占位符严格按列顺序：site_id,session_id,visitor_id[,end_user],start_ts,end_ts,
            // entry_url,exit_url,source,medium,campaign,content,term,click_id,
            // browser,os,device,screen,lang,ip,country,province,city,created_at；
            // pageviews=1 / duration=0 / bounce=1 / is_new=0 为字面常量
            $sql = $hasEndUser
                ? 'INSERT INTO sessions
                   (site_id,session_id,visitor_id,end_user,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,content,term,click_id,
                    browser,os,device,screen,lang,ip,country,province,city,created_at)
                   VALUES (?,?,?,?,?,?,?,1,0,1,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE
                     pageviews=pageviews+1,
                     end_ts=VALUES(end_ts),
                     duration=VALUES(end_ts)-start_ts,
                     bounce=0,
                     exit_url=VALUES(exit_url)'
                : 'INSERT INTO sessions
                   (site_id,session_id,visitor_id,start_ts,end_ts,pageviews,duration,bounce,is_new,
                    entry_url,exit_url,source,medium,campaign,content,term,click_id,
                    browser,os,device,screen,lang,ip,country,province,city,created_at)
                   VALUES (?,?,?,?,?,1,0,1,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE
                     pageviews=pageviews+1,
                     end_ts=VALUES(end_ts),
                     duration=VALUES(end_ts)-start_ts,
                     bounce=0,
                     exit_url=VALUES(exit_url)';
            $args = $hasEndUser
                ? [$evt['site_id'], $evt['session_id'], $evt['visitor_id'], $evt['end_user'], $now, $now,
                   $evt['url'], $evt['url'],
                   $evt['source'], $evt['medium'], $evt['campaign'], $evt['content'], $evt['term'], $evt['click_id'],
                   $evt['browser'], $evt['os'], $evt['device'], $evt['screen'], $evt['lang'],
                   $evt['ip'], $evt['country'], $evt['province'], $evt['city'], $now]
                : [$evt['site_id'], $evt['session_id'], $evt['visitor_id'], $now, $now,
                   $evt['url'], $evt['url'],
                   $evt['source'], $evt['medium'], $evt['campaign'], $evt['content'], $evt['term'], $evt['click_id'],
                   $evt['browser'], $evt['os'], $evt['device'], $evt['screen'], $evt['lang'],
                   $evt['ip'], $evt['country'], $evt['province'], $evt['city'], $now];
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

    /** 极简成功体 */
    private function tiny(): void
    {
        wstat_json(['ok' => 1], 200);
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
