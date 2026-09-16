<?php
/**
 * 固定窗口限流器（Redis 计数）
 *
 * 用途：采集端点防滥用（同站点同 IP 每分钟上报上限）。
 * 设计取舍：
 *  - 固定窗口（INCR + EXPIRE）而非滑动窗口：O(1) 成本，采集场景足够精确；
 *  - Redis 不可用时 fail-open（放行）：限流是防护措施，不能反过来成为可用性故障点；
 *  - key 由调用方拼好（含业务维度），本类只负责计数与过期。
 */
declare(strict_types=1);

namespace Wstat\Support;

class RateLimiter
{
    /**
     * 判定本次请求是否放行。
     *
     * @param string $key       业务键（自动加系统前缀，如 rl:collect:{sid}:{ip}）
     * @param int    $limit     窗口内允许次数；<=0 表示不限流（直接放行）
     * @param int    $windowSec 窗口秒数（默认 60）
     * @return bool true=放行 false=超限
     */
    public static function allow(string $key, int $limit, int $windowSec = 60): bool
    {
        if ($limit <= 0) {
            return true;
        }
        return Rds::safe(static function ($r) use ($key, $limit, $windowSec): bool {
            $k = 'rl:' . $key;
            $c = (int) $r->incr($k);
            if ($c === 1) {
                // 只有首个计数才设置过期：避免每次刷新导致窗口永不重置
                $r->expire($k, $windowSec + 30);
            }
            return $c <= $limit;
        }, true);
    }

    /** 清理计数键（测试 / 手动解封用） */
    public static function clear(string $key): void
    {
        Rds::safe(static function ($r) use ($key): void {
            $r->del('rl:' . $key);
        });
    }
}
