<?php
/**
 * Redis 访问器（单例）。任何一处都可能在 Redis 不可用时优雅降级。
 */
declare(strict_types=1);

namespace Wstat\Support;

use RuntimeException;

class Rds
{
    private static ?RedisClient $client = null;
    private static bool $healthy = true;

    /**
     * 无 Redis 模式判定：redis.enabled=false（安装向导「跳过 Redis」写入）时
     * 系统进入「数据直写数据库」模式 —— get()/safe() 恒返回 null/fallback，
     * 采集直写 events/sessions，统计侧全部走数据库聚合。
     */
    public static function enabled(): bool
    {
        $c = wstat_config('redis');
        if (!is_array($c)) {
            return true;
        }
        // 显式 enabled 键优先；历史配置（无该键）按启用处理
        return array_key_exists('enabled', $c) ? (bool) $c['enabled'] : true;
    }

    /** @return RedisClient|null 连接失败返回 null（调用方降级）；无 Redis 模式恒为 null */
    public static function get(): ?RedisClient
    {
        if (!self::$healthy || !self::enabled()) {
            return null;
        }
        if (self::$client === null) {
            try {
                $client = new RedisClient(wstat_config('redis'));
                // ping 需返回 true；NOAUTH/WRONGPASS/拒连等一律视为不可用 → 降级
                if ($client->ping() !== true) {
                    self::$healthy = false;
                    error_log('[wstat] Redis unavailable: ping failed (auth required? wrong password?)');
                    return null;
                }
                self::$client = $client;
            } catch (RuntimeException $e) {
                self::$healthy = false;
                self::$client = null;
                error_log('[wstat] Redis unavailable: ' . $e->getMessage());
                return null;
            }
        }
        return self::$client;
    }

    /** 执行一段需要 Redis 的回调；异常时降级返回 $fallback */
    public static function safe(callable $fn, $fallback = null)
    {
        $r = self::get();
        if ($r === null) {
            return $fallback;
        }
        try {
            return $fn($r);
        } catch (RuntimeException $e) {
            self::$healthy = false;
            self::$client = null;
            error_log('[wstat] Redis error: ' . $e->getMessage());
            return $fallback;
        }
    }

    /** 测试用：重置健康状态 */
    public static function reset(): void
    {
        self::$client = null;
        self::$healthy = true;
    }
}
