<?php
/**
 * 站点控制器：多站点 CRUD + 文件验证
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\SiteAccess;
use Wstat\Support\SiteStore;
use Wstat\Support\Util;

class SiteController
{
    /** GET /api/sites  当前用户可见站点（自有 + 被授权协作），带 role 字段 */
    public function index(Request $req): void
    {
        $u = Auth::requireUser($req);
        wstat_json(SiteAccess::sitesFor((int) $u['id']));
    }

    /** POST /api/sites */
    public function store(Request $req): void
    {
        $u = Auth::requireUser($req);
        $name = trim((string) $req->input('name', ''));
        $domain = trim((string) $req->input('domain', ''));
        $tz = (string) $req->input('timezone', 'Asia/Shanghai');

        if ($name === '' || mb_strlen($name) > 100) {
            wstat_err('请输入站点名称（≤100字）', 422);
        }
        if (!Util::validDomain($domain)) {
            wstat_err('域名格式不正确（仅主机名，如 www.example.com）', 422);
        }
        if (!Util::validTz($tz)) {
            wstat_err('时区不正确', 422);
        }
        $cnt = Db::value('SELECT COUNT(*) FROM sites WHERE user_id=?', [(int) $u['id']]);
        if ((int) $cnt >= 50) {
            wstat_err('站点数量已达上限（50）', 422);
        }
        // 域名可跨用户重复，但不允许同一用户重复添加
        if (Db::value('SELECT id FROM sites WHERE user_id=? AND domain=?', [(int) $u['id'], $domain]) !== null) {
            wstat_err('该域名已在你的站点列表中', 409);
        }

        $now = time();
        $id = Db::insert('sites', [
            'user_id' => (int) $u['id'],
            'name' => $name,
            'domain' => $domain,
            'site_key' => Util::randBase62(10),
            'timezone' => $tz,
            'status' => 1,
            'verify_token' => '',
            'verified_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        wstat_json($this->own($req, (int) $id), 200);
    }

    /** GET /api/sites/{id} */
    public function show(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'));
        wstat_json($site);
    }

    /** PATCH /api/sites/{id} */
    public function update(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        if ((int) $site['verified_at'] > 0) {
            wstat_err('站点已验证，不可再编辑（如需变更请删除后重新创建并验证）', 409);
        }
        $fields = [];
        $name = trim((string) $req->input('name', ''));
        $domain = trim((string) $req->input('domain', ''));
        $tz = (string) $req->input('timezone', '');
        $status = $req->input('status', null);

        if ($name !== '' && mb_strlen($name) <= 100) {
            $fields['name'] = $name;
        }
        if ($domain !== '') {
            if (!Util::validDomain($domain)) {
                wstat_err('域名格式不正确', 422);
            }
            $dup = Db::value('SELECT id FROM sites WHERE user_id=? AND domain=? AND id<>?',
                [(int) $site['user_id'], $domain, (int) $site['id']]);
            if ($dup !== null) {
                wstat_err('该域名已在你的站点列表中', 409);
            }
            $fields['domain'] = $domain;
            $fields['verified_at'] = 0;   // 域名变更需重新验证
            $fields['verify_token'] = '';
        }
        if ($tz !== '') {
            if (!Util::validTz($tz)) {
                wstat_err('时区不正确', 422);
            }
            $fields['timezone'] = $tz;
        }
        if ($status !== null && in_array((int) $status, [0, 1], true)) {
            $fields['status'] = (int) $status;
        }
        if (empty($fields)) {
            wstat_err('无有效字段', 422);
        }
        $set = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $set[] = "`$k`=?";
            $vals[] = $v;
        }
        $vals[] = (int) $site['id'];
        $vals[] = (int) $site['user_id'];
        Db::execute('UPDATE sites SET ' . implode(',', $set) . ", updated_at=" . time() . ' WHERE id=? AND user_id=?', $vals);
        SiteStore::invalidate((string) $site['site_key']);
        wstat_json($this->own($req, (int) $site['id']));
    }

    /** DELETE /api/sites/{id}  删除站点并级联清理其全部统计数据 */
    public function destroy(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $sid = (int) $site['id'];
        $uid = (int) $site['user_id'];
        SiteStore::invalidate((string) $site['site_key']);

        // 1) 级联删除所有含 site_id 的统计表（events 为分区表，按 site_id 删除即可）
        $deleted = [
            'events'     => (int) Db::value('SELECT COUNT(*) FROM events WHERE site_id=?', [$sid]),
            'sessions'   => (int) Db::value('SELECT COUNT(*) FROM sessions WHERE site_id=?', [$sid]),
            'site_daily' => (int) Db::value('SELECT COUNT(*) FROM site_daily WHERE site_id=?', [$sid]),
            'funnels'    => (int) Db::value('SELECT COUNT(*) FROM funnels WHERE site_id=?', [$sid]),
        ];
        Db::execute('DELETE FROM events WHERE site_id=?', [$sid]);
        Db::execute('DELETE FROM sessions WHERE site_id=?', [$sid]);
        Db::execute('DELETE FROM site_daily WHERE site_id=?', [$sid]);
        Db::execute('DELETE FROM funnels WHERE site_id=?', [$sid]);

        // 2) 清理 Redis 实时态（计数器 / 在线集合 / 进行中会话 / 基数估算）——尽力而为
        try {
            $r = \Wstat\Support\Rds::get();
            if ($r !== null) {
                $r->del("today:$sid", "rt:min:$sid", "on:$sid");
                foreach (["uv:$sid:*", "ip:$sid:*", "live:$sid:*"] as $pat) {
                    $cursor = 0;
                    do {
                        $keys = $r->scanKeys($pat, $cursor, 500);
                        if ($keys) {
                            $r->del(...$keys);
                        }
                    } while ($cursor !== 0);
                }
            }
        } catch (\Throwable $e) {
            /* Redis 不可用不影响删除结果 */
        }

        // 3) 最后删除站点行
        Db::execute('DELETE FROM sites WHERE id=? AND user_id=?', [$sid, $uid]);
        wstat_json(['deleted' => $deleted]);
    }

    /**
     * GET /api/sites/{id}/verify-file  生成(或复用)验证文件信息，不做远端校验
     * 返回 {file, content, verified}，前端据此引导用户放置文件后调 /verify 校验。
     */
    public function verifyFile(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $token = (string) $site['verify_token'];
        if ($token === '') {
            $token = Util::randHex(16);
            Db::execute('UPDATE sites SET verify_token=? WHERE id=?', [$token, (int) $site['id']]);
            SiteStore::invalidate((string) $site['site_key']);
            $site['verify_token'] = $token;
        }
        $cfg = wstat_config('verify');
        $fileName = $cfg['file_prefix'] . $token . '.txt';
        wstat_json([
            'verified' => (int) $site['verified_at'] > 0,
            'file' => $fileName,
            'content' => $token,
        ]);
    }

    /**
     * POST /api/sites/{id}/verify  站点文件验证（仅远端校验）
     * 前置：先调用 verify-file 生成令牌并放置文件，再调用本接口。
     * 服务端拉取 https/http://{domain}/wstat_verify_{token}.txt，内容与令牌一致即通过。
     */
    public function verify(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $token = (string) $site['verify_token'];
        if ($token === '') {
            wstat_json([
                'verified' => false,
                'hint' => '请先生成验证文件（调用 verify-file 或重新打开验证窗口）',
            ], 200, 0, '尚未生成验证文件');
        }
        $cfg = wstat_config('verify');
        $fileName = $cfg['file_prefix'] . $token . '.txt';
        $domain = (string) $site['domain'];
        $found = false;
        foreach (['https://', 'http://'] as $scheme) {
            $url = $scheme . $domain . '/' . $fileName;
            $body = self::httpGet($url, (int) $cfg['timeout']);
            if ($body !== null && trim($body) === $token) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            wstat_json([
                'verified' => false,
                'file' => $fileName,
                'content' => $token,
                'hint' => '无法从站点拉取文件。请确认：① 文件 ' . $fileName
                    . ' 已上传到 https://' . $domain . '/ 根目录且可公开访问；'
                    . '② 文件内容为 ' . $token . '（无多余空格/换行）；③ 域名已解析且未屏蔽统计服务器。',
            ], 200, 0, '验证未通过，请检查文件');
        }
        Db::execute('UPDATE sites SET verified_at=? WHERE id=?', [time(), (int) $site['id']]);
        SiteStore::invalidate((string) $site['site_key']);
        wstat_json(['verified' => true, 'file' => $fileName]);
    }

    /**
     * GET /api/sites/{id}/sdk-check?since=<unix>  检测统计代码（SDK）是否生效
     * 返回：
     *  - count_since: since 之后收到的事件数（since=0 时为近两天事件数）
     *  - last_ts    : 近两天窗口内最近一次事件时间（0=窗口内无数据）
     *  - last_day   : 全历史最近有数据的天（site_daily，null=从未收到过任何数据）
     * 查询走 idx_site_day 前缀：只扫 since 所在天与昨今两天，避免全表。
     */
    public function sdkCheck(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'));
        $sid = (int) $site['id'];
        $now = time();
        $since = max(0, (int) $req->input('since', 0));

        // events.day 是站点本地日期：覆盖 [since, now] 最多跨两天（今天 + 昨天）
        $tzMin = (int) round(Util::tzOffsetSec((string) $site['timezone']) / 60);
        $days = [];
        for ($t = ($since > 0 ? $since : $now) - 86400; $t <= $now + 60; $t += 86400) {
            $d = Util::localDay($t, $tzMin);
            $days[$d] = true;
        }
        $dayList = implode(',', array_fill(0, count($days), '?'));
        $vals = array_values(array_map(fn ($d) => (string) $d, array_keys($days)));
        array_unshift($vals, $sid, $since);

        $row = Db::first(
            "SELECT COUNT(*) AS c, COALESCE(MAX(ts),0) AS last_ts
             FROM events WHERE site_id=? AND ts>=? AND day IN ($dayList)",
            $vals
        ) ?? ['c' => 0, 'last_ts' => 0];

        // 全历史是否收过数据：site_daily 每站点每天一行，很小，直接取最近一天
        $lastDay = Db::value('SELECT MAX(day) FROM site_daily WHERE site_id=?', [$sid]);

        wstat_json([
            'count_since' => (int) $row['c'],
            'last_ts'     => (int) $row['last_ts'],
            'last_day'    => $lastDay !== null ? (string) $lastDay : null,
            'server_time' => $now,
        ]);
    }

    /**
     * 当前用户可访问的站点（含归属/角色校验）。
     * 默认只读即可（VIEWER）；写操作显式传 SiteAccess::OWNER。
     */
    private function own(Request $req, int $id, string $min = SiteAccess::VIEWER): array
    {
        SiteAccess::require($req, $id, $min);
        $site = Db::first(
            'SELECT id,user_id,name,domain,site_key,timezone,status,verify_token,verified_at,created_at
             FROM sites WHERE id=? LIMIT 1', [$id]
        );
        if ($site === null) {
            wstat_err('站点不存在', 404, 404);
        }
        return $site;
    }

    /* ==================== 成员与权限（仅站点所有者可管理） ==================== */

    /** GET /api/sites/{id}/members  成员列表（含所有者） */
    public function members(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $sid = (int) $site['id'];

        $owner = Db::first('SELECT id,email,nickname FROM users WHERE id=?', [(int) $site['user_id']]);
        $items = [[
            'id' => 0,
            'user_id' => (int) $site['user_id'],
            'email' => (string) ($owner['email'] ?? ''),
            'nickname' => (string) ($owner['nickname'] ?? ''),
            'role' => SiteAccess::OWNER,
            'is_owner' => true,
            'created_at' => (int) $site['created_at'],
        ]];
        if (SiteAccess::hasMemberTable()) {
            foreach (
                Db::select(
                    'SELECT m.id,m.user_id,m.role,m.created_at,u.email,u.nickname
                     FROM site_members m JOIN users u ON u.id=m.user_id
                     WHERE m.site_id=? ORDER BY m.id',
                    [$sid]
                ) as $m
            ) {
                $items[] = [
                    'id' => (int) $m['id'],
                    'user_id' => (int) $m['user_id'],
                    'email' => (string) $m['email'],
                    'nickname' => (string) $m['nickname'],
                    'role' => (string) $m['role'],
                    'is_owner' => false,
                    'created_at' => (int) $m['created_at'],
                ];
            }
        }
        wstat_json([
            'items' => $items,
            'member_table' => SiteAccess::hasMemberTable(),
            'roles' => [SiteAccess::EDITOR, SiteAccess::VIEWER],
        ]);
    }

    /** POST /api/sites/{id}/members  按邮箱添加成员 {email, role} */
    public function memberAdd(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        if (!SiteAccess::hasMemberTable()) {
            wstat_err('成员功能未启用：请先执行增量脚本 sql/upgrade-2026-09-11-members-alerts.sql', 409);
        }
        $email = trim((string) $req->input('email', ''));
        $role = (string) $req->input('role', SiteAccess::VIEWER);
        if (!Util::validEmail($email)) {
            wstat_err('邮箱格式不正确', 422);
        }
        if (!SiteAccess::validRole($role)) {
            wstat_err('角色只能是 editor（可编辑）或 viewer（只读）', 422);
        }
        $u = Db::first('SELECT id,email,nickname,status FROM users WHERE email=? LIMIT 1', [$email]);
        if ($u === null) {
            wstat_err('该邮箱尚未注册本站账号，请先让对方注册后再添加', 404, 404);
        }
        if ((int) $u['id'] === (int) $site['user_id']) {
            wstat_err('站点所有者无需添加为成员', 409);
        }
        $exists = Db::value('SELECT id FROM site_members WHERE site_id=? AND user_id=? LIMIT 1',
            [(int) $site['id'], (int) $u['id']]);
        if ($exists !== null) {
            wstat_err('该用户已是站点成员，可直接调整角色', 409);
        }
        $id = Db::insert('site_members', [
            'site_id' => (int) $site['id'],
            'user_id' => (int) $u['id'],
            'role' => $role,
            'invited_by' => (int) $this->uid($req),
            'created_at' => time(),
        ]);
        wstat_json(['id' => $id, 'role' => $role]);
    }

    /** PATCH /api/sites/{id}/members/{mid}  调整成员角色 {role} */
    public function memberUpdate(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $role = (string) $req->input('role', '');
        if (!SiteAccess::validRole($role)) {
            wstat_err('角色只能是 editor（可编辑）或 viewer（只读）', 422);
        }
        $mid = (int) $req->param('mid');
        $n = Db::execute('UPDATE site_members SET role=? WHERE id=? AND site_id=?', [$role, $mid, (int) $site['id']]);
        if ($n <= 0) {
            wstat_err('成员不存在', 404, 404);
        }
        wstat_json(['ok' => true, 'role' => $role]);
    }

    /** DELETE /api/sites/{id}/members/{mid}  移除成员 */
    public function memberRemove(Request $req): void
    {
        $site = $this->own($req, (int) $req->param('id'), SiteAccess::OWNER);
        $mid = (int) $req->param('mid');
        $n = Db::execute('DELETE FROM site_members WHERE id=? AND site_id=?', [$mid, (int) $site['id']]);
        if ($n <= 0) {
            wstat_err('成员不存在', 404, 404);
        }
        wstat_json(['ok' => true]);
    }

    /** GET /api/members/sites  当前用户被授权的站点（我做客的） */
    public function sharedWithMe(Request $req): void
    {
        $u = Auth::requireUser($req);
        if (!SiteAccess::hasMemberTable()) {
            wstat_json(['items' => []]);
        }
        $rows = Db::select(
            'SELECT s.id,s.name,s.domain,m.role,s.created_at,o.email AS owner_email,o.nickname AS owner_name
             FROM site_members m
             JOIN sites s ON s.id=m.site_id
             JOIN users o ON o.id=s.user_id
             WHERE m.user_id=? ORDER BY m.id DESC',
            [(int) $u['id']]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
        }
        unset($r);
        wstat_json(['items' => $rows]);
    }

    private function uid(Request $req): int
    {
        $u = Auth::requireUser($req);
        return (int) $u['id'];
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'WebStats-Verify/1.0',
        ]);
        $body = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return $err === 0 && is_string($body) ? $body : null;
    }
}
