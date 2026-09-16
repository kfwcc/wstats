<?php
/**
 * 用户管理（仅系统管理员）：列表 / 创建 / 编辑 / 禁用启用 / 删除 / 查看用户站点
 *
 * 硬约束（防把系统锁死）：
 *  - 不能禁用 / 删除 / 降级 **自己**；
 *  - 不能把**最后一个管理员**降级、禁用或删除（is_admin 列缺失的老库按 MIN(id) 语义处理）；
 *  - 名下有站点的用户**不允许删除**（站点 user_id 悬空会破坏归属与权限体系），
 *    需先转移或删除站点；删除用户时级联清理其令牌/成员关系/告警配置等个人数据，
 *    alert_logs 留痕不删（审计需要）。
 * 所有写操作写双份日志：操作者（user_*）+ 被操作者（同动作，个人中心可见）。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\OpLog;
use Wstat\Support\Settings;
use Wstat\Support\Util;

class UserController
{
    /** GET /api/users?keyword=&status=&page=&page_size= */
    public function index(Request $req): void
    {
        $me = Settings::requireAdmin($req);
        $keyword = trim((string) $req->input('keyword', ''));
        $status = $req->input('status', '');
        $page = max(1, (int) $req->input('page', 1));
        $size = min(100, max(5, (int) $req->input('page_size', 20)));

        $where = [];
        $args = [];
        if ($keyword !== '') {
            $where[] = '(u.email LIKE ? OR u.nickname LIKE ?)';
            $args[] = '%' . $keyword . '%';
            $args[] = '%' . $keyword . '%';
        }
        if ($status !== '' && $status !== null) {
            $where[] = 'u.status=?';
            $args[] = (int) $status;
        }
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        // 最近登录时间取自 user_op_logs（表缺失时降级为 NULL 列，不阻断列表）
        $lastLoginSql = 'NULL AS last_login_at';
        $extraArgs = [];
        if (OpLog::available()) {
            $lastLoginSql = '(SELECT MAX(l.created_at) FROM user_op_logs l
                              WHERE l.user_id=u.id AND l.action=?)
                             AS last_login_at';
            $extraArgs = ['login'];
        }

        $total = (int) Db::value('SELECT COUNT(*) FROM users u' . $whereSql, $args);
        $offset = ($page - 1) * $size;
        $rows = Db::select(
            'SELECT u.id,u.email,u.nickname,u.lang,u.status,u.created_at,u.updated_at,
                    (SELECT COUNT(*) FROM sites s WHERE s.user_id=u.id) AS sites_owned,
                    ' . $lastLoginSql . '
             FROM users u' . $whereSql . '
             ORDER BY u.id ASC LIMIT ' . $size . ' OFFSET ' . $offset,
            array_merge($args, $extraArgs)
        );
        foreach ($rows as &$r) {
            $r['is_admin'] = Settings::isAdmin((int) $r['id']);
            $r['last_login_at'] = $r['last_login_at'] !== null ? (int) $r['last_login_at'] : 0;
            $r['is_self'] = (int) $r['id'] === (int) $me['id'];
        }
        unset($r);

        wstat_json([
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $size,
            'user_table' => true,
        ]);
    }

    /** POST /api/users —— 创建用户 */
    public function store(Request $req): void
    {
        $me = Settings::requireAdmin($req);
        $email = trim((string) $req->input('email', ''));
        $pass = (string) $req->input('password', '');
        $nickname = trim((string) $req->input('nickname', ''));
        $lang = (string) $req->input('lang', 'zh-CN');
        $min = (int) wstat_config('security.pwd_min');

        if (!Util::validEmail($email)) {
            wstat_err('邮箱格式不正确', 422);
        }
        if (Db::value('SELECT id FROM users WHERE email=? LIMIT 1', [$email]) !== null) {
            wstat_err('该邮箱已注册', 409);
        }
        if (strlen($pass) < $min || strlen($pass) > 72) {
            wstat_err('密码长度需 ' . $min . '-72 位', 422);
        }
        if (mb_strlen($nickname) > 30) {
            wstat_err('昵称过长（最多 30 字）', 422);
        }
        if (!in_array($lang, ['zh-CN', 'en-US'], true)) {
            $lang = 'zh-CN';
        }

        $now = time();
        $row = [
            'email' => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'nickname' => $nickname !== '' ? $nickname : explode('@', $email)[0],
            'lang' => $lang,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($req->input('is_admin') !== null) {
            $row['is_admin'] = (int) $req->input('is_admin') === 1 ? 1 : 0;
        }
        $id = Db::insert('users', Db::filterColumns('users', $row));
        OpLog::log((int) $me['id'], 'user_create', '创建用户 ' . $email);
        OpLog::log($id, 'user_create', '账号由管理员创建');
        wstat_json(['id' => $id] + self::shape((int) $id));
    }

    /** PATCH /api/users/{id} —— 编辑昵称/语言/管理员标记/重置密码 */
    public function update(Request $req): void
    {
        $me = Settings::requireAdmin($req);
        $id = (int) $req->param('id');
        $target = Db::first('SELECT id,email FROM users WHERE id=? LIMIT 1', [$id]);
        if ($target === null) {
            wstat_err('用户不存在', 404, 404);
        }
        $self = (int) $me['id'] === $id;

        $upd = [];
        $changed = [];
        if ($req->input('nickname') !== null) {
            $nick = trim((string) $req->input('nickname'));
            if (mb_strlen($nick) > 30) {
                wstat_err('昵称过长（最多 30 字）', 422);
            }
            $upd['nickname'] = $nick;
            $changed[] = '昵称';
        }
        if ($req->input('lang') !== null) {
            $lang = (string) $req->input('lang');
            if (!in_array($lang, ['zh-CN', 'en-US'], true)) {
                wstat_err('界面语言仅支持 zh-CN / en-US', 422);
            }
            $upd['lang'] = $lang;
            $changed[] = '界面语言';
        }
        if ($req->input('is_admin') !== null && array_key_exists('is_admin', Db::tableColumns('users'))) {
            $want = (int) $req->input('is_admin') === 1 ? 1 : 0;
            $cur = Settings::isAdmin($id) ? 1 : 0;
            if ($want !== $cur) {
                if ($want === 0 && $self) {
                    wstat_err('不能取消自己的管理员权限', 422);
                }
                if ($want === 0 && self::adminCount() <= 1) {
                    wstat_err('系统至少要保留一个管理员', 422);
                }
                $upd['is_admin'] = $want;
                $changed[] = $want === 1 ? '设为管理员' : '取消管理员';
            }
        }
        $pwdChanged = false;
        if ($req->input('password') !== null && (string) $req->input('password') !== '') {
            $pass = (string) $req->input('password');
            $min = (int) wstat_config('security.pwd_min');
            if (strlen($pass) < $min || strlen($pass) > 72) {
                wstat_err('密码长度需 ' . $min . '-72 位', 422);
            }
            $upd['password'] = password_hash($pass, PASSWORD_DEFAULT);
            $changed[] = '重置密码';
            $pwdChanged = true;
        }
        if ($upd === []) {
            wstat_err('没有需要修改的字段', 422);
        }
        $upd['updated_at'] = time();

        $sets = implode(',', array_map(static fn (string $k): string => '`' . $k . '`=?', array_keys($upd)));
        Db::execute('UPDATE users SET ' . $sets . ' WHERE id=?', array_merge(array_values($upd), [$id]));
        if ($pwdChanged) {
            Auth::revokeAll($id);   // 重置密码后旧会话全部失效
        }
        $detail = '更新用户 ' . (string) $target['email'] . '（' . implode('、', $changed) . '）';
        OpLog::log((int) $me['id'], 'user_update', $detail);
        OpLog::log($id, $pwdChanged ? 'user_reset_password' : 'user_update',
            $pwdChanged ? '密码已由管理员重置' : '资料由管理员更新（' . implode('、', $changed) . '）');
        wstat_json(self::shape($id));
    }

    /** POST /api/users/{id}/status —— 启用 / 禁用（body: {status:0|1}） */
    public function status(Request $req): void
    {
        $me = Settings::requireAdmin($req);
        $id = (int) $req->param('id');
        $status = (int) $req->input('status', 1) === 1 ? 1 : 0;
        $target = Db::first('SELECT id,email FROM users WHERE id=? LIMIT 1', [$id]);
        if ($target === null) {
            wstat_err('用户不存在', 404, 404);
        }
        if ($id === (int) $me['id']) {
            wstat_err('不能禁用自己的账号', 422);
        }
        if ($status === 0 && Settings::isAdmin($id) && self::adminCount() <= 1) {
            wstat_err('系统至少要保留一个可登录的管理员', 422);
        }

        Db::execute('UPDATE users SET status=?, updated_at=? WHERE id=?', [$status, time(), $id]);
        $revoked = 0;
        if ($status === 0) {
            $revoked = Auth::revokeAll($id);   // 禁用即踢下线
        }
        $action = $status === 1 ? 'user_enable' : 'user_disable';
        $what = $status === 1 ? '启用' : '禁用';
        OpLog::log((int) $me['id'], $action, $what . '用户 ' . (string) $target['email']);
        OpLog::log($id, $action, '账号已被管理员' . $what . ($status === 0 ? '（下线会话 ' . $revoked . ' 个）' : ''));
        wstat_json(['ok' => true, 'status' => $status, 'revoked' => $revoked]);
    }

    /** DELETE /api/users/{id} —— 删除用户（名下有站点时拒绝） */
    public function destroy(Request $req): void
    {
        $me = Settings::requireAdmin($req);
        $id = (int) $req->param('id');
        $target = Db::first('SELECT id,email FROM users WHERE id=? LIMIT 1', [$id]);
        if ($target === null) {
            wstat_err('用户不存在', 404, 404);
        }
        if ($id === (int) $me['id']) {
            wstat_err('不能删除自己的账号', 422);
        }
        if (Settings::isAdmin($id) && self::adminCount() <= 1) {
            wstat_err('不能删除最后一个管理员', 422);
        }
        $owned = (int) Db::value('SELECT COUNT(*) FROM sites WHERE user_id=?', [$id]);
        if ($owned > 0) {
            wstat_err('该用户名下有 ' . $owned . ' 个站点，请先转移或删除这些站点', 409);
        }

        Db::transaction(function () use ($id): void {
            Db::execute('DELETE FROM auth_tokens WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM api_tokens WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM site_members WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM notify_channels WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM alert_rules WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM report_subscriptions WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM user_op_logs WHERE user_id=?', [$id]);
            Db::execute('DELETE FROM users WHERE id=?', [$id]);
        });
        // 操作者留痕：被删用户的日志已级联清除，不再向其写日志
        OpLog::log((int) $me['id'], 'user_delete', '删除用户 ' . (string) $target['email']);
        wstat_json(['ok' => true]);
    }

    /** GET /api/users/{id}/sites —— 某用户的站点（自有 owner + 被授权 editor/viewer） */
    public function sites(Request $req): void
    {
        Settings::requireAdmin($req);
        $id = (int) $req->param('id');
        $target = Db::first('SELECT id FROM users WHERE id=? LIMIT 1', [$id]);
        if ($target === null) {
            wstat_err('用户不存在', 404, 404);
        }

        $rows = Db::select(
            "SELECT s.id,s.name,s.domain,s.status,s.verified_at,s.created_at,'owner' AS role
             FROM sites s WHERE s.user_id=?",
            [$id]
        );
        try {
            if ((int) Db::value(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                ['site_members']
            ) > 0) {
                $shared = Db::select(
                    "SELECT s.id,s.name,s.domain,s.status,s.verified_at,s.created_at,m.role
                     FROM site_members m JOIN sites s ON s.id=m.site_id
                     WHERE m.user_id=?",
                    [$id]
                );
                foreach ($shared as $s) {
                    $rows[] = $s;
                }
            }
        } catch (\Throwable $e) {
            /* 成员表缺失：仅返回自有站点 */
        }
        wstat_json(['items' => $rows, 'total' => count($rows)]);
    }

    /** 管理员数量（is_admin 列缺失的老库：MIN(id) 语义恒为 1） */
    private static function adminCount(): int
    {
        try {
            if (array_key_exists('is_admin', Db::tableColumns('users'))) {
                return (int) Db::value('SELECT COUNT(*) FROM users WHERE is_admin=1');
            }
        } catch (\Throwable $e) {
            /* 列探测失败按老库语义处理 */
        }
        return 1;
    }

    /** 用户行脱敏（去掉 password，补 is_admin） */
    private static function shape(int $id): array
    {
        $r = Db::first(
            'SELECT id,email,nickname,lang,status,created_at,updated_at FROM users WHERE id=? LIMIT 1',
            [$id]
        );
        if ($r === null) {
            wstat_err('用户不存在', 404, 404);
        }
        $r['is_admin'] = Settings::isAdmin($id);
        return $r;
    }
}
