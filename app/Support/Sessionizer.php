<?php
/**
 * 会话聚合器：将 Redis 中的“打开会话(session hash)”转为 sessions 表行
 * worker 与 cron 共用，保证行为一致。
 */
declare(strict_types=1);

namespace Wstat\Support;

class Sessionizer
{
    public const HASH_KEY = 'ssn:';          // ssn:{session_id}
    public const LIVE_KEY = 'live:';         // live:{site_id}:{Ymd UTC}  进行中会话集合(当日实时可见)

    /** 由 session hash 生成落库行；数据不全返回 null */
    public static function buildRow(array $h): ?array
    {
        $first = (int) ($h['first_ts'] ?? 0);
        $last = (int) ($h['last_ts'] ?? 0);
        if ($first === 0 || $last === 0) {
            return null;
        }
        $pv = max(1, (int) ($h['pv'] ?? 1));
        $src = isset($h['src_json']) ? json_decode($h['src_json'], true) : [];
        $src = is_array($src) ? $src : [];
        return [
            'site_id' => (int) ($h['site_id'] ?? 0),
            'session_id' => (string) ($h['session_id'] ?? ''),
            'visitor_id' => (string) ($h['visitor_id'] ?? ''),
            'end_user' => (string) ($h['end_user'] ?? ''),
            'start_ts' => $first,
            'end_ts' => $last,
            'pageviews' => $pv,
            'duration' => max(0, $last - $first),
            'bounce' => $pv <= 1 ? 1 : 0,
            'is_new' => (int) ($h['is_new'] ?? 0),
            'entry_url' => (string) ($h['entry_url'] ?? ''),
            'exit_url' => (string) ($h['exit_url'] ?? ''),
            'source' => (string) ($src['type'] ?? ($h['source'] ?? 'direct')),
            'medium' => (string) ($src['medium'] ?? ''),
            'campaign' => (string) ($src['campaign'] ?? ''),
            'content' => (string) ($src['content'] ?? ''),
            'term' => (string) ($src['term'] ?? ''),
            'click_id' => (string) ($src['click_id'] ?? ''),
            'browser' => (string) ($h['browser'] ?? ''),
            'os' => (string) ($h['os'] ?? ''),
            'device' => (string) ($h['device'] ?? ''),
            'screen' => (string) ($h['screen'] ?? ''),
            'lang' => (string) ($h['lang'] ?? ''),
            'ip' => (string) ($h['ip'] ?? ''),
            'country' => (string) ($h['country'] ?? ''),
            'province' => (string) ($h['province'] ?? ''),
            'city' => (string) ($h['city'] ?? ''),
            'created_at' => time(),
        ];
    }

    /** 关闭一个会话（落库 + 移除实时集合 + 删 hash），返回是否成功 */
    public static function close(RedisClient $r, string $sessionId): bool
    {
        $hash = $r->hgetall(self::HASH_KEY . $sessionId);
        if (empty($hash)) {
            return false;
        }
        $row = self::buildRow($hash);
        $ok = false;
        if ($row !== null && (int) $row['site_id'] > 0) {
            // 列过滤：sessions 表结构升级（如新增 ip）期间未跑迁移也允许 insert，跳过未知列
            Db::insertIgnore('sessions', Db::filterColumns('sessions', $row));
            self::unmarkLive($r, $row);
            $ok = true;
        }
        $r->del(self::HASH_KEY . $sessionId);
        return $ok;
    }

    /* ================= 进行中会话（实时可见） ================= */

    /** live 集合 key（按 UTC 日分桶） */
    public static function liveKey(int $siteId, string $ymdUtc): string
    {
        return self::LIVE_KEY . $siteId . ':' . $ymdUtc;
    }

    /** pageview 到达时标记会话进行中（幂等；新成员才设置 TTL 减少写放大） */
    public static function markLive(RedisClient $r, int $siteId, string $sessionId, int $ts): void
    {
        if ($sessionId === '') {
            return;
        }
        $k = self::liveKey($siteId, gmdate('Ymd', $ts));
        $added = $r->sadd($k, $sessionId);
        if ($added === 1) {
            $r->expire($k, 4 * 86400);
        }
    }

    /** 会话关闭落库后，从其出现的 live 桶移除（防与 sessions 重复计数） */
    private static function unmarkLive(RedisClient $r, array $row): void
    {
        $sessId = (string) ($row['session_id'] ?? '');
        $siteId = (int) ($row['site_id'] ?? 0);
        if ($sessId === '' || $siteId <= 0) {
            return;
        }
        $r->srem(self::liveKey($siteId, gmdate('Ymd', (int) ($row['start_ts'] ?? 0))), $sessId);
        $r->srem(self::liveKey($siteId, gmdate('Ymd', (int) ($row['end_ts'] ?? 0))), $sessId);
    }

    /**
     * 某站点在 [ts0, ts1) 区间内“进行中”的会话行（含 start_ts 落区间过滤）。
     * 返回与 sessions 表同构的行（未落库，供概览/会话列表实时合并）。
     */
    public static function liveRows(RedisClient $r, int $siteId, int $ts0, int $ts1): array
    {
        $out = [];
        $seen = [];
        $cursor = 0;
        do {
            $keys = $r->scanKeys(self::LIVE_KEY . $siteId . ':*', $cursor, 200);
            foreach ($keys as $k) {
                $members = $r->smembers($k);
                if (!is_array($members)) {
                    continue;
                }
                foreach ($members as $sessId) {
                    if (isset($seen[$sessId])) {
                        continue;
                    }
                    $hash = $r->hgetall(self::HASH_KEY . $sessId);
                    if (empty($hash)) {
                        continue;   // 残留成员（hash 已清）忽略
                    }
                    $row = self::buildRow($hash);
                    if ($row === null || (int) $row['site_id'] !== $siteId) {
                        continue;
                    }
                    $seen[$sessId] = 1;
                    if ((int) $row['start_ts'] >= $ts0 && (int) $row['start_ts'] < $ts1) {
                        $out[] = $row;
                    }
                }
            }
        } while ($cursor !== 0);
        // 按开始时间倒序（与 sessions 列表一致）
        usort($out, fn ($a, $b) => (int) $b['start_ts'] - (int) $a['start_ts']);
        return $out;
    }

    /**
     * 回收空闲超时的 ssn hash（落库）并清理在线集合离线成员。
     * worker 与 cron 共用，避免依赖 crontab 才能落库。
     * 返回 [closed, offline]。
     */
    public static function reapIdle(RedisClient $r): array
    {
        $idle = (int) wstat_config('security.session_idle');
        $now = time();
        $closed = 0;
        $cursor = 0;
        do {
            $keys = $r->scanKeys(self::HASH_KEY . '*', $cursor, 300);
            foreach ($keys as $k) {
                $hash = $r->hgetall($k);
                $last = (int) ($hash['last_ts'] ?? 0);
                if ($last > 0 && $now - $last > $idle) {
                    if (self::close($r, substr($k, strlen(self::HASH_KEY)))) {
                        $closed++;
                    }
                }
            }
        } while ($cursor !== 0);

        $offline = 0;
        $cursor = 0;
        do {
            $keys = $r->scanKeys('on:*', $cursor, 300);
            foreach ($keys as $k) {
                $offline += $r->zremrangebyscore($k, '-inf', (string) ($now - 300));
            }
        } while ($cursor !== 0);
        return [$closed, $offline];
    }
}
