<?php
/**
 * 站点读取（采集端高频路径，Redis 缓存 10 分钟）
 */
declare(strict_types=1);

namespace Wstat\Support;

class SiteStore
{
    public const CACHE_TTL = 600;

    /** 按 site_key 取站点（采集上报用）。不存在返回 null */
    public static function byKey(string $siteKey): ?array
    {
        $siteKey = trim($siteKey);
        if ($siteKey === '' || strlen($siteKey) > 16) {
            return null;
        }
        $cacheKey = 'site:' . $siteKey;
        $cached = Rds::safe(fn($r) => $r->get($cacheKey));
        if (is_string($cached) && $cached !== '') {
            $d = json_decode($cached, true);
            if (is_array($d)) {
                return $d;
            }
        }
        $site = Db::first(
            'SELECT id,user_id,name,domain,site_key,timezone,status,verify_token,verified_at
             FROM sites WHERE site_key=? LIMIT 1', [$siteKey]
        );
        if ($site !== null) {
            Rds::safe(fn($r) => $r->set($cacheKey, json_encode($site, JSON_UNESCAPED_UNICODE), self::CACHE_TTL));
        }
        return $site;
    }

    /** 使缓存失效（站点更新/删除后调用） */
    public static function invalidate(string $siteKey): void
    {
        if ($siteKey !== '') {
            Rds::safe(fn($r) => $r->del('site:' . $siteKey));
        }
    }
}
