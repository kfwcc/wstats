<?php
/**
 * 开放 API 个人访问令牌（PAT）
 *
 * 与 auth_tokens（登录会话）完全隔离：
 *   - 仅可用于 /open/v1/* 只读统计接口白名单；
 *   - 只认 Authorization: Bearer 头（不认 ?token= 参数，避免与屏幕令牌等语义混淆）；
 *   - expires_at = 0 表示永不过期；每次调用刷新 last_used_at。
 */
declare(strict_types=1);

namespace Wstat\Support;

use Wstat\Http\Request;

class PAT
{
    /** 通过请求解析 PAT 用户（无效/过期/停用返回 null） */
    public static function user(Request $req): ?array
    {
        $h = $req->header('Authorization');
        if (!preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $h, $m)) {
            return null;
        }
        $token = strtolower($m[1]);
        $row = Db::first(
            'SELECT t.id AS pat_id, u.* FROM api_tokens t JOIN users u ON u.id=t.user_id
             WHERE t.token=? AND t.is_active=1 AND (t.expires_at=0 OR t.expires_at>?)
               AND u.status=1
             LIMIT 1',
            [$token, time()]
        );
        if ($row === null) {
            return null;
        }
        // 最近调用时间节流写（1 分钟内不重复写，减少高频调用下的写放大）
        $patId = (int) $row['pat_id'];
        $last = (int) Db::value('SELECT last_used_at FROM api_tokens WHERE id=?', [$patId]);
        if (time() - $last >= 60) {
            Db::execute('UPDATE api_tokens SET last_used_at=? WHERE id=?', [time(), $patId]);
        }
        return $row;
    }

    /** 创建 PAT，返回明文 token（仅此一次可见） */
    public static function issue(int $userId, string $name, int $expiresAt): string
    {
        $token = Util::randHex(32);
        Db::execute(
            'INSERT INTO api_tokens(user_id,name,token,expires_at,is_active,created_at) VALUES(?,?,?,?,1,?)',
            [$userId, mb_substr($name, 0, 60), $token, max(0, $expiresAt), time()]
        );
        return $token;
    }

    /** 某用户的 PAT 列表（脱敏） */
    public static function listOf(int $userId): array
    {
        $rows = Db::select(
            'SELECT id,name,token,expires_at,last_used_at,is_active,created_at
             FROM api_tokens WHERE user_id=? ORDER BY id DESC',
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['expires_at'] = (int) $r['expires_at'];
            $r['last_used_at'] = (int) $r['last_used_at'];
            $r['is_active'] = (int) $r['is_active'];
            $r['created_at'] = (int) $r['created_at'];
            $t = (string) $r['token'];
            $r['token_masked'] = strlen($t) > 12 ? substr($t, 0, 8) . '…' . substr($t, -4) : '******';
            unset($r['token']);
        }
        unset($r);
        return $rows;
    }

    /** 撤销（删除）某用户的一枚 PAT */
    public static function revoke(int $userId, int $id): bool
    {
        return Db::execute('DELETE FROM api_tokens WHERE id=? AND user_id=?', [$id, $userId]) > 0;
    }
}
