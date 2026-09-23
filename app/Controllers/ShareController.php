<?php
/**
 * 公开只读分享链接（Share）
 *
 * 用途：把某个站点的汇总报表以「链接」形式只读分享给没有账号的人（老板 / 甲方 / 外包同学）。
 *   · 管理侧（需登录）：创建 / 列表 / 撤销；权限按站点角色（创建需 editor，查看列表 viewer 即可）
 *   · 公开侧（免登录）：GET /api/share/{token} 返回聚合报表，链接可带访问密码
 *
 * 安全边界（是设计，不是待办）：
 *   · 只读且只有聚合：**不含站点 site_key**（防止拿到后伪造上报）、不含访客 IP / visitor_id、
 *     不含会话明细与原始事件；渠道与热门页仅到「主机 / 路径」粒度。
 *   · token = 32 字节随机数的 hex（128 bit 熵），不可枚举；支持密码、过期时间与一键撤销。
 *   · 公开端点按 (token, IP) 限流，防暴力猜密码与刷量。
 *   · 可查区间上限 90 天：防止用一条分享链接遍历站点全部历史。
 *
 * 口径：PV / UV / 独立 IP 一律 events 明细精确去重（与面板概览、大屏、开放 API 同源）；
 *   访问数 / 跳出 / 时长 / 新访客取自 sessions。因不合并 Redis 中「进行中会话」，
 *   今日数字可能略滞后于登录态面板（分享页面向「概况」而非「秒级实时」）。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Db;
use Wstat\Support\RateLimiter;
use Wstat\Support\SiteAccess;
use Wstat\Support\Util;

class ShareController
{
    private const MAX_DAYS = 90;            // 可查看/配置的最长区间（天）
    private const MAX_LINKS = 50;           // 单站点最多同时存在的有效链接数
    private const VIEW_PER_MIN = 120;       // 公开端点同 token 同 IP 每分钟访问上限
    private const CREATE_PER_MIN = 30;      // 同用户每分钟创建上限

    /** 与 StatsController::URL_PATH_EXPR 同款路径表达式（跨控制器私有，故此处独立维护） */
    private const URL_PATH_EXPR = "IF(LOCATE('//', url) > 0, IF(LOCATE('/', url, LOCATE('//', url) + 2) > 0, SUBSTRING(url, LOCATE('/', url, LOCATE('//', url) + 2)), '/'), url)";

    /* ==================== 管理侧（需登录） ==================== */

    /**
     * POST /api/shares
     * body: site_id（必填）、name、days（默认区间，1..90）、password（可空，≤64）、expire_days（0=永久）
     */
    public function store(Request $req): void
    {
        $this->ready();
        $siteId = (int) $req->input('site_id', 0);
        $site = SiteAccess::site($req, $siteId, SiteAccess::EDITOR);
        $u = \Wstat\Support\Auth::requireUser($req);

        $limit = filter_var(getenv('WSTAT_RATE_LIMIT') ?: self::CREATE_PER_MIN, FILTER_VALIDATE_INT);
        if ($limit !== false && !RateLimiter::allow('share-create:' . (int) $u['id'], $limit)) {
            wstat_err('创建过于频繁，请稍后再试', 429, 429);
        }

        $active = (int) Db::value(
            'SELECT COUNT(*) FROM share_links WHERE site_id=? AND revoked=0 AND (expire_at=0 OR expire_at>?)',
            [$siteId, time()]
        );
        if ($active >= self::MAX_LINKS) {
            wstat_err('该站点有效分享链接已达上限（' . self::MAX_LINKS . ' 条），请先撤销不再使用的链接', 422, 422);
        }

        $name = self::cut((string) $req->input('name', ''), 64);
        $days = (int) $req->input('days', 7);
        $days = max(1, min(self::MAX_DAYS, $days === 0 ? 7 : $days));
        $pwd = (string) $req->input('password', '');
        if (strlen($pwd) > 64) {
            wstat_err('访问密码最长 64 个字符', 422, 422);
        }
        $expireDays = (int) $req->input('expire_days', 0);
        $expireDays = max(0, min(3650, $expireDays));

        $token = bin2hex(random_bytes(32));
        Db::execute(
            'INSERT INTO share_links (site_id,token,name,days,password_hash,created_by,created_at,expire_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $siteId, $token, $name, $days,
                $pwd !== '' ? password_hash($pwd, PASSWORD_DEFAULT) : '',
                (int) $u['id'], time(),
                $expireDays > 0 ? time() + $expireDays * 86400 : 0,
            ]
        );

        $id = (int) Db::value('SELECT id FROM share_links WHERE token=? LIMIT 1', [$token]);
        wstat_json([
            'id'        => $id,
            'site'      => ['id' => (int) $site['id'], 'name' => $site['name'], 'domain' => $site['domain']],
            'token'     => $token,
            'path'      => '/share/' . $token,
            'name'      => $name,
            'days'      => $days,
            'has_password' => $pwd !== '',
            'expire_at' => $expireDays > 0 ? time() + $expireDays * 86400 : 0,
        ]);
    }

    /** GET /api/shares?site_id=  该站点的分享链接列表（viewer 及以上） */
    public function index(Request $req): void
    {
        $this->ready();
        $siteId = (int) $req->input('site_id', 0);
        $site = SiteAccess::site($req, $siteId, SiteAccess::VIEWER);

        $rows = Db::select(
            'SELECT id,token,name,days,password_hash<>"" AS has_password,created_at,expire_at,
                    last_view_at,views
             FROM share_links WHERE site_id=? AND revoked=0 ORDER BY id DESC LIMIT 200',
            [$siteId]
        );
        $now = time();
        $items = [];
        foreach ($rows as $r) {
            $expireAt = (int) $r['expire_at'];
            $items[] = [
                'id'           => (int) $r['id'],
                'token'        => (string) $r['token'],
                'path'         => '/share/' . (string) $r['token'],
                'name'         => (string) $r['name'],
                'days'         => (int) $r['days'],
                'has_password' => (int) $r['has_password'] === 1,
                'created_at'   => (int) $r['created_at'],
                'expire_at'    => $expireAt,
                'expired'      => $expireAt > 0 && $expireAt <= $now,
                'last_view_at' => (int) $r['last_view_at'],
                'views'        => (int) $r['views'],
            ];
        }
        wstat_json([
            'site'     => ['id' => (int) $site['id'], 'name' => $site['name'], 'domain' => $site['domain']],
            'max_days' => self::MAX_DAYS,
            'items'    => $items,
        ]);
    }

    /** DELETE /api/shares/{id}  撤销（软删除：置 revoked=1，保留行便于审计） */
    public function destroy(Request $req): void
    {
        $this->ready();
        $id = (int) $req->param('id');
        $link = Db::first('SELECT id,site_id FROM share_links WHERE id=? LIMIT 1', [$id]);
        if ($link === null) {
            wstat_err('分享链接不存在', 404, 404);
        }
        SiteAccess::site($req, (int) $link['site_id'], SiteAccess::EDITOR);
        Db::execute('UPDATE share_links SET revoked=1 WHERE id=?', [$id]);
        wstat_json(['ok' => 1, 'id' => $id]);
    }

    /* ==================== 公开侧（免登录） ==================== */

    /**
     * GET /api/share/{token}?password=&days=&start=&end=
     * 免登录只读报表。code：4101=已过期 / 4102=需要密码 / 4103=密码错误（前端据此渲染对应提示）。
     */
    public function publicData(Request $req): void
    {
        $this->ready();
        $token = (string) $req->param('token');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            wstat_err('分享链接无效', 404, 404);
        }
        $link = Db::first(
            'SELECT id,site_id,days,password_hash,expire_at,revoked FROM share_links WHERE token=? LIMIT 1',
            [$token]
        );
        if ($link === null || (int) $link['revoked'] === 1) {
            wstat_err('分享链接不存在或已被撤销', 404, 404);
        }
        $now = time();
        if ((int) $link['expire_at'] > 0 && (int) $link['expire_at'] <= $now) {
            wstat_err('分享链接已过期', 410, 4101);
        }

        // 限流：同 token 同 IP（防暴力猜密码 / 刷量）。Redis 不可用时 fail-open（不阻断合法访问）。
        $ip = Util::clientIp();
        $limit = filter_var(getenv('WSTAT_RATE_LIMIT') ?: self::VIEW_PER_MIN, FILTER_VALIDATE_INT);
        if ($limit !== false && !RateLimiter::allow('share:' . $token . ':' . ($ip !== '' ? $ip : 'unknown'), $limit)) {
            wstat_err('访问过于频繁，请稍后再试', 429, 429);
        }

        $hash = (string) $link['password_hash'];
        if ($hash !== '') {
            $got = (string) $req->input('password', '');
            if ($got === '') {
                wstat_err('需要访问密码', 403, 4102);
            }
            if (!password_verify($got, $hash)) {
                wstat_err('访问密码错误', 403, 4103);
            }
        }

        $sid = (int) $link['site_id'];
        $site = Db::first(
            'SELECT id,name,domain,timezone FROM sites WHERE id=? AND status=1 LIMIT 1',
            [$sid]
        );
        if ($site === null) {
            wstat_err('站点不存在或已停用', 404, 404);
        }

        // 区间：优先显式 start/end（≤90 天），否则按 days（1..90，默认取链接配置）
        $tz = (string) $site['timezone'];
        $today = gmdate('Y-m-d', time() + Util::tzOffsetSec($tz));
        [$start, $end] = $this->range($req, $today, (int) $link['days']);
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, $tz);

        // ---- 逐日趋势（events 明细，精确去重） ----
        $trend = [];
        foreach (Util::dailySeries($startTs, $endTs, $tz, fn () => null) as $d => $_) {
            $trend[(string) $d] = ['day' => (string) $d, 'pv' => 0, 'uv' => 0];
        }
        foreach (
            Db::select(
                "SELECT `day`, COUNT(*) pv, COUNT(DISTINCT visitor_id) uv
                 FROM events
                 WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?
                 GROUP BY `day`",
                [$sid, $start, $end]
            ) as $r
        ) {
            $d = (string) $r['day'];
            if (isset($trend[$d])) {
                $trend[$d]['pv'] = (int) $r['pv'];
                $trend[$d]['uv'] = (int) $r['uv'];
            }
        }

        // ---- 渠道构成（source 类型）+ 外链主机 + 热门页面 ----
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
        $cols = Db::tableColumns('events');
        $referrers = [];
        if (isset($cols['ref_host'])) {
            foreach (
                Db::select(
                    "SELECT ref_host host, COUNT(*) pv FROM events
                     WHERE $w AND ref_host<>'' GROUP BY ref_host ORDER BY pv DESC LIMIT 10",
                    $args
                ) as $r
            ) {
                $referrers[] = ['host' => (string) $r['host'], 'pv' => (int) $r['pv']];
            }
        }
        $pages = [];
        foreach (
            Db::select(
                'SELECT ' . self::URL_PATH_EXPR . ' AS path, COUNT(*) pv,
                        COUNT(DISTINCT visitor_id) uv
                 FROM events WHERE ' . $w . ' GROUP BY path ORDER BY pv DESC LIMIT 15',
                $args
            ) as $r
        ) {
            $pages[] = ['path' => (string) $r['path'], 'pv' => (int) $r['pv'], 'uv' => (int) $r['uv']];
        }

        // ---- 总量 + 环比（上一等长区间） ----
        $totals = $this->totalsOf($sid, $tz, $start, $end);
        $len = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        $pEnd = date('Y-m-d', strtotime($start . ' 00:00:00') - 86400);
        $pStart = date('Y-m-d', strtotime($pEnd . ' 00:00:00') - ($len - 1) * 86400);
        $prev = $this->totalsOf($sid, $tz, $pStart, $pEnd);
        $deltas = [];
        foreach (['pv', 'uv', 'ipc', 'visits', 'bounce_rate', 'avg_duration', 'new_users'] as $k) {
            $c = (float) ($totals[$k] ?? 0);
            $p = (float) ($prev[$k] ?? 0);
            $deltas[$k] = $p > 0 ? round(($c - $p) / $p * 100, 1) : ($c > 0 ? 100.0 : null);
        }

        // 访问计数（失败不影响出数）
        try {
            Db::execute('UPDATE share_links SET views=views+1, last_view_at=? WHERE id=?', [$now, (int) $link['id']]);
        } catch (\Throwable $e) {
            // 忽略
        }

        wstat_json([
            'site'      => ['id' => $sid, 'name' => (string) $site['name'], 'domain' => (string) $site['domain']],
            'range'     => ['start' => $start, 'end' => $end],
            'max_days'  => self::MAX_DAYS,
            'totals'    => $totals,
            'deltas'    => $deltas,
            'trend'     => array_values($trend),
            'channels'  => $channels,
            'referrers' => $referrers,
            'pages'     => $pages,
            'expire_at' => (int) $link['expire_at'],
        ]);
    }

    /* ==================== 内部 ==================== */

    /**
     * share_links 表是否可用。缺失（老库未跑增量迁移）时给出明确指引而非 500。
     * 无返回值：表存在则正常返回；不存在则 wstat_err() 直接结束响应（exit），
     * 所以**调用方不得**把它的返回值当成站点信息用（那是曾经的错法，会让 site.id=0）。
     */
    private function ready(): void
    {
        try {
            if (count(Db::tableColumns('share_links')) > 0) {
                return;
            }
        } catch (\Throwable $e) {
            // 继续走下方统一提示
        }
        wstat_err(
            'share_links 表不存在。请在数据库执行升级：'
            . "CREATE TABLE IF NOT EXISTS `share_links` (...);"
            . '（完整语句见 sql/upgrade-2026-09-21-share-links.sql，'
            . '或执行：php dev/apply-upgrade.php sql/upgrade-2026-09-21-share-links.sql）',
            200,
            1
        );
    }

    /** 区间归一：显式 start/end 优先（≤90 天），否则按 days 取「含今日的最近 N 天」 */
    private function range(Request $req, string $today, int $defaultDays): array
    {
        $start = trim((string) $req->input('start', ''));
        $end = trim((string) $req->input('end', ''));
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (preg_match($re, $start) && preg_match($re, $end)) {
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            if ($end > $today) {
                $end = $today;
            }
            if ($start > $end) {
                $start = $end;
            }
            $n = (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;
            if ($n > self::MAX_DAYS) {
                $start = date('Y-m-d', strtotime($end) - (self::MAX_DAYS - 1) * 86400);
            }
            return [$start, $end];
        }
        $days = (int) $req->input('days', $defaultDays);
        $days = max(1, min(self::MAX_DAYS, $days === 0 ? $defaultDays : $days));
        return [date('Y-m-d', strtotime($today) - ($days - 1) * 86400), $today];
    }

    /** 等长区间汇总（PV/UV/独立 IP 走 events 精确去重；访问/跳出/时长/新访客走 sessions） */
    private function totalsOf(int $sid, string $tz, string $start, string $end): array
    {
        [$startTs, $endTs] = Util::dateRangeToTs($start, $end, $tz);
        $ev = Db::first(
            "SELECT COUNT(*) pv,
                    COUNT(DISTINCT visitor_id) uv,
                    COUNT(DISTINCT CASE WHEN ip<>'' THEN ip END) ipc
             FROM events WHERE site_id=? AND type='pageview' AND `day` BETWEEN ? AND ?",
            [$sid, $start, $end]
        ) ?: [];
        $se = Db::first(
            'SELECT COUNT(*) v, COALESCE(SUM(bounce),0) b, COALESCE(SUM(duration),0) du,
                    COALESCE(SUM(is_new),0) nw
             FROM sessions WHERE site_id=? AND start_ts>=? AND start_ts<?',
            [$sid, $startTs, $endTs]
        ) ?: [];
        $visits = (int) ($se['v'] ?? 0);
        return [
            'pv'           => (int) ($ev['pv'] ?? 0),
            'uv'           => (int) ($ev['uv'] ?? 0),
            'ipc'          => (int) ($ev['ipc'] ?? 0),
            'visits'       => $visits,
            'bounce_rate'  => $visits > 0 ? round((int) $se['b'] / $visits * 100, 1) : 0.0,
            'avg_duration' => $visits > 0 ? (int) round((int) $se['du'] / $visits) : 0,
            'new_users'    => (int) ($se['nw'] ?? 0),
        ];
    }

    private static function cut(string $s, int $len): string
    {
        $s = trim($s);
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) : $s;
    }
}
