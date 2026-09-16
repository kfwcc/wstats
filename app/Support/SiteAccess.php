<?php
/**
 * 站点访问控制（多用户协作）
 *
 * 角色三级，权限递增：viewer（只读） < editor（可改漏斗等站点内分析对象） < owner（站点所有者）。
 * 站点所有者天然是 owner；其他用户通过 site_members 表被授权。
 *
 * 兼容性：site_members 表可能尚未创建（老库未跑增量迁移），此时一律按「仅所有者可见」降级，
 * 不抛异常，保证升级前的站点仍可正常使用。
 */
declare(strict_types=1);

namespace Wstat\Support;

use Wstat\Http\Request;

class SiteAccess
{
    public const OWNER  = 'owner';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    /** 角色权重（越大权限越高） */
    private const RANK = [self::VIEWER => 1, self::EDITOR => 2, self::OWNER => 3];

    private static ?bool $hasMembers = null;

    /** site_members 表是否可用（进程内缓存一次） */
    public static function hasMemberTable(): bool
    {
        if (self::$hasMembers === null) {
            try {
                self::$hasMembers = (int) Db::value(
                    'SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                    ['site_members']
                ) > 0;
            } catch (\Throwable $e) {
                self::$hasMembers = false;
            }
        }
        return self::$hasMembers;
    }

    /** 角色权重，未知角色返回 0 */
    public static function rank(?string $role): int
    {
        return self::RANK[(string) $role] ?? 0;
    }

    public static function validRole(string $role): bool
    {
        // 不开放「再授 owner」：转移所有权请走站点归属变更，避免权限扩散
        return in_array($role, [self::VIEWER, self::EDITOR], true);
    }

    /**
     * 当前用户在指定站点的角色；无权访问返回 null（站点不存在同样返回 null）。
     */
    public static function role(int $userId, int $siteId): ?string
    {
        if ($userId <= 0 || $siteId <= 0) {
            return null;
        }
        $site = Db::first('SELECT id,user_id FROM sites WHERE id=? LIMIT 1', [$siteId]);
        if ($site === null) {
            return null;
        }
        if ((int) $site['user_id'] === $userId) {
            return self::OWNER;
        }
        if (!self::hasMemberTable()) {
            return null;
        }
        $m = Db::first(
            'SELECT role FROM site_members WHERE site_id=? AND user_id=? LIMIT 1',
            [$siteId, $userId]
        );
        if ($m === null) {
            return null;
        }
        $r = (string) $m['role'];
        return isset(self::RANK[$r]) ? $r : self::VIEWER;
    }

    /**
     * 要求当前用户对站点至少具备 $min 角色。
     * 无权访问 → 404（不泄露站点是否存在）；权限不足 → 403。
     * 返回 [用户行, 角色]。
     */
    public static function require(Request $req, int $siteId, string $min = self::VIEWER): array
    {
        $u = Auth::requireUser($req);
        $role = self::role((int) $u['id'], $siteId);
        if ($role === null) {
            wstat_err('站点不存在', 404, 404);
        }
        if (self::rank($role) < self::rank($min)) {
            wstat_err('当前账号对该站点仅有只读权限，无法执行此操作', 403, 403);
        }
        return [$u, $role];
    }

    /**
     * 站点行 + 访问校验（默认只读及以上），返回的站点数组附带 role 字段。
     * $min 为要求的最低角色：读接口用 VIEWER，站点设置类写入用 EDITOR / OWNER。
     */
    public static function site(Request $req, int $siteId, string $min = self::VIEWER): array
    {
        $u = Auth::requireUser($req);
        $role = self::role((int) $u['id'], $siteId);
        if ($role === null) {
            wstat_err('站点不存在', 404, 404);
        }
        if (self::rank($role) < self::rank($min)) {
            wstat_err('当前账号对该站点权限不足，无法执行此操作', 403, 403);
        }
        $site = Db::first(
            'SELECT id,user_id,name,domain,site_key,timezone,status,verified_at FROM sites WHERE id=? LIMIT 1',
            [$siteId]
        );
        if ($site === null) {
            wstat_err('站点不存在', 404, 404);
        }
        $site['role'] = $role;
        return $site;
    }

    /**
     * 当前用户可见的全部站点（自有 + 被授权），按角色标注。
     * 供 GET /api/sites 与前端站点切换器使用。
     */
    public static function sitesFor(int $userId): array
    {
        $cols = 's.id,s.name,s.domain,s.site_key,s.timezone,s.status,'
            . 's.verify_token,s.verified_at,s.created_at';
        if (!self::hasMemberTable()) {
            $rows = Db::select(
                "SELECT $cols, 'owner' AS role FROM sites s WHERE s.user_id=? ORDER BY s.id DESC",
                [$userId]
            );
        } else {
            $rows = Db::select(
                "SELECT $cols,
                        CASE WHEN s.user_id=? THEN 'owner' ELSE m.role END AS role
                 FROM sites s
                 LEFT JOIN site_members m ON m.site_id=s.id AND m.user_id=?
                 WHERE s.user_id=? OR m.user_id IS NOT NULL
                 ORDER BY s.id DESC",
                [$userId, $userId, $userId]
            );
        }
        // 验证令牌只对所有者可见（协作成员无需它，避免无谓的敏感字段外泄）
        foreach ($rows as &$r) {
            if ((string) ($r['role'] ?? '') !== self::OWNER) {
                $r['verify_token'] = '';
            }
        }
        unset($r);
        return $rows;
    }

    /** 站点所有者 id（成员管理需校验所有权/发通知用） */
    public static function ownerId(int $siteId): int
    {
        return (int) Db::value('SELECT user_id FROM sites WHERE id=? LIMIT 1', [$siteId]);
    }
}
