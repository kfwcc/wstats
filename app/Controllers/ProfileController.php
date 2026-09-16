<?php
/**
 * 个人中心：个人资料查看/修改、修改密码、我的操作日志
 *
 * 设计要点：
 *  - 邮箱**只读**：它是登录凭证且唯一，变更涉及邮箱验证与通知，暂不开放（界面明确标注）；
 *  - 修改密码成功后吊销全部旧会话并下发新 token（与找回密码一致的安全语义），
 *    当前设备的会话换新令牌继续有效，其它设备立即失效；
 *  - 所有变更动作写入 user_op_logs（表缺失时降级，见 Support\OpLog）。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\OpLog;
use Wstat\Support\Settings;

class ProfileController
{
    /** GET /api/profile —— 当前用户资料 */
    public function show(Request $req): void
    {
        $u = Auth::requireUser($req);
        wstat_json(self::data((int) $u['id']));
    }

    /** PATCH /api/profile —— 修改昵称 / 界面语言 */
    public function update(Request $req): void
    {
        $u = Auth::requireUser($req);
        $id = (int) $u['id'];

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
        if ($upd === []) {
            wstat_err('没有需要修改的字段', 422);
        }
        $upd['updated_at'] = time();

        $sets = implode(',', array_map(static fn (string $k): string => '`' . $k . '`=?', array_keys($upd)));
        Db::execute(
            'UPDATE users SET ' . $sets . ' WHERE id=?',
            array_merge(array_values($upd), [$id])
        );
        OpLog::log($id, 'profile_update', '修改了 ' . implode('、', $changed));
        wstat_json(self::data($id));
    }

    /**
     * POST /api/profile/password —— 修改密码。
     * body: {old_password, new_password}
     * 成功返回新 token（当前会话无缝续期）；其它设备全部吊销。
     */
    public function password(Request $req): void
    {
        $u = Auth::requireUser($req);
        $id = (int) $u['id'];
        $old = (string) $req->input('old_password', '');
        $new = (string) $req->input('new_password', '');
        $min = (int) wstat_config('security.pwd_min');

        $row = Db::first('SELECT password FROM users WHERE id=? LIMIT 1', [$id]);
        if ($row === null) {
            wstat_err('账号不存在', 404, 404);
        }
        if (!password_verify($old, (string) $row['password'])) {
            wstat_err('当前密码不正确', 422, 4224);
        }
        if (strlen($new) < $min || strlen($new) > 72) {
            wstat_err('新密码长度需 ' . $min . '-72 位', 422);
        }
        if ($new === $old) {
            wstat_err('新密码不能与当前密码相同', 422);
        }

        Db::execute(
            'UPDATE users SET password=?, updated_at=? WHERE id=?',
            [password_hash($new, PASSWORD_DEFAULT), time(), $id]
        );
        $revoked = Auth::revokeAll($id);
        $token = Auth::issue($id);
        OpLog::log($id, 'password_change', '修改密码（吊销旧会话 ' . $revoked . ' 个，当前设备已换新令牌）');
        wstat_json(['ok' => true, 'token' => $token, 'revoked' => $revoked]);
    }

    /** GET /api/profile/logs?page=&page_size= —— 我的操作日志 */
    public function logs(Request $req): void
    {
        $u = Auth::requireUser($req);
        $page = max(1, (int) $req->input('page', 1));
        $size = (int) $req->input('page_size', 20);
        wstat_json(OpLog::page((int) $u['id'], $page, $size));
    }

    /** 资料数组（不含 password）；附带名下/被授权站点计数 */
    private static function data(int $userId): array
    {
        $row = Db::first(
            'SELECT id,email,nickname,lang,status,created_at,updated_at FROM users WHERE id=? LIMIT 1',
            [$userId]
        );
        if ($row === null) {
            wstat_err('账号不存在', 404, 404);
        }
        $row['is_admin'] = Settings::isAdmin($userId);
        $row['sites_owned'] = (int) Db::value('SELECT COUNT(*) FROM sites WHERE user_id=?', [$userId]);
        $row['sites_shared'] = 0;
        try {
            if ((int) Db::value(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                ['site_members']
            ) > 0) {
                $row['sites_shared'] = (int) Db::value(
                    'SELECT COUNT(*) FROM site_members WHERE user_id=?',
                    [$userId]
                );
            }
        } catch (\Throwable $e) {
            $row['sites_shared'] = 0;
        }
        return $row;
    }
}
