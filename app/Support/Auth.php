<?php
/**
 * 认证服务：token（auth_tokens 表）→ 用户
 */
declare(strict_types=1);

namespace Wstat\Support;

use Wstat\Http\Request;

class Auth
{
    /** 通过请求解析当前用户（未登录返回 null） */
    public static function user(Request $req): ?array
    {
        static $cache = null;   // 每请求单次查询
        static $done = false;
        if ($done) {
            return $cache;
        }
        $done = true;
        $token = $req->bearer();
        if ($token === '' || strlen($token) !== 64) {
            $cache = null;
            return null;
        }
        $now = time();
        $row = Db::first(
            'SELECT u.* FROM auth_tokens t JOIN users u ON u.id=t.user_id
             WHERE t.token=? AND t.expires_at>? AND u.status=1',
            [$token, $now]
        );
        $cache = $row;
        return $row;
    }

    /** 必须登录，否则 401 */
    public static function requireUser(Request $req): array
    {
        $u = self::user($req);
        if ($u === null) {
            wstat_err('未登录或登录已过期', 401, 401);
        }
        return $u;
    }

    /** 创建登录令牌 */
    public static function issue(int $userId): string
    {
        $token = Util::randHex(32);
        $now = time();
        Db::execute(
            'INSERT INTO auth_tokens(token,user_id,expires_at,created_at) VALUES(?,?,?,?)',
            [$token, $userId, $now + (int) wstat_config('security.token_ttl'), $now]
        );
        return $token;
    }

    public static function revoke(string $token): void
    {
        Db::execute('DELETE FROM auth_tokens WHERE token=?', [$token]);
    }

    /**
     * 吊销某用户的全部登录令牌（改密码 / 重置密码后调用）。
     * 目的：密码一旦变更，其它设备上的旧会话立即失效，避免「改了密码但别人还登着」。
     * 返回被清理的令牌数（表缺失等异常时静默返回 0，不阻断改密主流程）。
     */
    public static function revokeAll(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        try {
            return (int) Db::execute('DELETE FROM auth_tokens WHERE user_id=?', [$userId]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
