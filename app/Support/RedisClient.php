<?php
/**
 * 自研纯 PHP Redis 客户端（RESP2 协议）。
 * 零扩展依赖：不要求 phpredis / predis。通过 TCP socket 直连。
 * 支持本系统所需命令；解析器可离线单测（php://temp 模拟流）。
 */
declare(strict_types=1);

namespace Wstat\Support;

use RuntimeException;

class RedisClient
{
    /** @var resource|null */
    private $fp = null;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'host' => '127.0.0.1', 'port' => 6379, 'auth' => null,
            'db' => 0, 'prefix' => '', 'timeout' => 2.0,
        ];
    }

    /** 连接（惰性）。失败抛出，供上层降级处理。 */
    private function connect(): void
    {
        if (is_resource($this->fp)) {
            return;
        }
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->cfg['host'], $this->cfg['port']),
            $errno, $errstr, (float) $this->cfg['timeout']
        );
        if ($fp === false) {
            throw new RuntimeException('Redis connect failed: ' . $errstr);
        }
        stream_set_timeout($fp, (int) ceil((float) $this->cfg['timeout']));
        $this->fp = $fp;
        if (!empty($this->cfg['auth'])) {
            $this->raw(['AUTH', $this->cfg['auth']]);
        }
        if ((int) $this->cfg['db'] > 0) {
            $this->raw(['SELECT', (string) $this->cfg['db']]);
        }
    }

    /* ================= RESP 编解码 ================= */

    /** 发送命令并返回解析结果（+OK→true, :n→int, $-1→null, *-1→null） */
    public function raw(array $args)
    {
        $this->connect();
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $s = (string) $a;
            $out .= '$' . strlen($s) . "\r\n" . $s . "\r\n";
        }
        fwrite($this->fp, $out);
        return self::parseReply($this->fp);
    }

    /**
     * 解析一个 RESP 回复。
     * @param resource $stream 任何可 fgets/fread 的流（socket 或 php://temp，便于离线测试）
     */
    public static function parseReply($stream)
    {
        $line = self::readLine($stream);
        if ($line === null || $line === false) {
            return null;                        // 超时 / 断开
        }
        $type = $line[0] ?? '';
        $body = substr($line, 1);
        switch ($type) {
            case '+':
                return $body;
            case '-':
                throw new RuntimeException('Redis error: ' . $body);
            case ':':
                return (int) $body;
            case '$': {
                $len = (int) $body;
                if ($len === -1) {
                    return null;
                }
                $buf = '';
                while (strlen($buf) < $len + 2) {
                    $chunk = fread($stream, $len + 2 - strlen($buf));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $buf .= $chunk;
                }
                return substr($buf, 0, $len);
            }
            case '*': {
                $count = (int) $body;
                if ($count === -1) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $arr[] = self::parseReply($stream);
                }
                return $arr;
            }
            default:
                throw new RuntimeException('Unknown RESP type: ' . $type);
        }
    }

    private static function readLine($stream)
    {
        $line = fgets($stream);
        if ($line === false) {
            return false;
        }
        return rtrim($line, "\r\n");
    }

    /** 读一个整块（key 名用） */
    private function withPrefix(string $key): string
    {
        return $this->cfg['prefix'] . $key;
    }

    /** 带前缀的真实键名（诊断输出用） */
    public function fullKey(string $key): string
    {
        return $this->withPrefix($key);
    }

    /* ================= 命令封装 ================= */

    public function ping(): bool
    {
        try {
            return $this->raw(['PING']) === 'PONG';
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public function get(string $key)
    {
        return $this->raw(['GET', $this->withPrefix($key)]);
    }

    public function set(string $key, string $val, ?int $ttl = null): bool
    {
        $args = ['SET', $this->withPrefix($key), $val];
        if ($ttl !== null) {
            $args[] = 'EX';
            $args[] = (string) $ttl;
        }
        return $this->raw($args) === 'OK';
    }

    public function setnx(string $key, string $val, ?int $ttl = null): bool
    {
        $args = ['SET', $this->withPrefix($key), $val, 'NX'];
        if ($ttl !== null) {
            $args[] = 'EX';
            $args[] = (string) $ttl;
        }
        $r = $this->raw($args);
        return $r === 'OK';
    }

    public function del(string ...$keys): int
    {
        $args = ['DEL'];
        foreach ($keys as $k) {
            $args[] = $this->withPrefix($k);
        }
        return (int) $this->raw($args);
    }

    public function exists(string $key): bool
    {
        return (int) $this->raw(['EXISTS', $this->withPrefix($key)]) > 0;
    }

    /* ================= 元信息 / 诊断 =================
     * （键类型审计、WRONGTYPE 排查、坏键隔离都需要；不参与业务热路径） */

    /** 键类型：none|string|list|set|zset|hash|stream */
    public function type(string $key): string
    {
        $t = $this->raw(['TYPE', $this->withPrefix($key)]);
        return is_string($t) ? $t : 'unknown';
    }

    /** 剩余生存秒数：-1 无过期 / -2 键不存在 */
    public function ttl(string $key): int
    {
        return (int) $this->raw(['TTL', $this->withPrefix($key)]);
    }

    /** 重命名键（隔离坏键用）；源键不存在返回 false */
    public function rename(string $key, string $newKey): bool
    {
        $r = $this->raw(['RENAME', $this->withPrefix($key), $this->withPrefix($newKey)]);
        return $r === 'OK';
    }

    public function hlen(string $key): int
    {
        return (int) $this->raw(['HLEN', $this->withPrefix($key)]);
    }

    public function scard(string $key): int
    {
        return (int) $this->raw(['SCARD', $this->withPrefix($key)]);
    }

    public function zcard(string $key): int
    {
        return (int) $this->raw(['ZCARD', $this->withPrefix($key)]);
    }

    public function strlen(string $key): int
    {
        return (int) $this->raw(['STRLEN', $this->withPrefix($key)]);
    }

    /** 列表区间读取（坏键预览用） */
    public function lrange(string $key, int $start = 0, int $stop = -1): array
    {
        $raw = $this->raw(['LRANGE', $this->withPrefix($key), (string) $start, (string) $stop]);
        return is_array($raw) ? $raw : [];
    }

    /* ================= Set ================= */

    public function sadd(string $key, string ...$vals): int
    {
        return (int) $this->raw(array_merge(['SADD', $this->withPrefix($key)], $vals));
    }

    public function srem(string $key, string ...$vals): int
    {
        return (int) $this->raw(array_merge(['SREM', $this->withPrefix($key)], $vals));
    }

    public function smembers(string $key): array
    {
        $a = $this->raw(['SMEMBERS', $this->withPrefix($key)]);
        return is_array($a) ? $a : [];
    }

    public function expire(string $key, int $sec): bool
    {
        return (bool) $this->raw(['EXPIRE', $this->withPrefix($key), (string) $sec]);
    }

    public function incr(string $key): int
    {
        return (int) $this->raw(['INCR', $this->withPrefix($key)]);
    }

    public function hset(string $key, string $field, string $val): bool
    {
        return (int) $this->raw(['HSET', $this->withPrefix($key), $field, $val]) >= 0;
    }

    public function hmset(string $key, array $map): bool
    {
        if (empty($map)) {
            return true;
        }
        $args = ['HMSET', $this->withPrefix($key)];
        foreach ($map as $f => $v) {
            $args[] = (string) $f;
            $args[] = (string) $v;
        }
        return $this->raw($args) === 'OK';
    }

    public function hmget(string $key, array $fields): array
    {
        if (empty($fields)) {
            return [];
        }
        $args = ['HMGET', $this->withPrefix($key)];
        foreach ($fields as $f) {
            $args[] = (string) $f;
        }
        $r = $this->raw($args);
        return is_array($r) ? $r : [];
    }

    public function hincrby(string $key, string $field, int $n = 1): int
    {
        return (int) $this->raw(['HINCRBY', $this->withPrefix($key), $field, (string) $n]);
    }

    public function hget(string $key, string $field)
    {
        return $this->raw(['HGET', $this->withPrefix($key), $field]);
    }

    public function hgetall(string $key): array
    {
        $raw = $this->raw(['HGETALL', $this->withPrefix($key)]);
        if (!is_array($raw)) {
            return [];
        }
        $map = [];
        for ($i = 0; $i + 1 < count($raw); $i += 2) {
            $map[$raw[$i]] = $raw[$i + 1];
        }
        return $map;
    }

    public function hdel(string $key, string ...$fields): int
    {
        $args = ['HDEL', $this->withPrefix($key)];
        foreach ($fields as $f) {
            $args[] = $f;
        }
        return (int) $this->raw($args);
    }

    public function lpush(string $key, string $val): int
    {
        return (int) $this->raw(['LPUSH', $this->withPrefix($key), $val]);
    }

    public function llen(string $key): int
    {
        return (int) $this->raw(['LLEN', $this->withPrefix($key)]);
    }

    /** @return [listName, value] | null */
    public function brpop(string $key, int $timeoutSec): ?array
    {
        // BRPOP 会阻塞 timeoutSec 才回复：读超时必须明显大于阻塞时长。
        // 否则 PHP 读超时先到 → parseReply 返回 null（假「队列空」），而 Redis 的回复
        // 滞留在缓冲区 → 后续每条命令读到的是上一条的回复（流错位），
        // 表现为消费照常、落库照常，但 hgetall/hmset 等全部静默失败。
        $this->connect();
        stream_set_timeout($this->fp, $timeoutSec + 5);
        $r = $this->raw(['BRPOP', $this->withPrefix($key), (string) $timeoutSec]);
        stream_set_timeout($this->fp, (int) ceil((float) $this->cfg['timeout']));
        if (!is_array($r) || count($r) !== 2) {
            return null;
        }
        return [$r[0], $r[1]];
    }

    /**
     * HyperLogLog（PFADD）。注意：2026-09-15 起产品**不再用 HLL 统计 UV / 独立 IP** ——
     * 估算在极小基数下会多估（出现过「概览 IP 数 4 / IP 地域页 3」的口径不一致），
     * 现在统一走 events 明细精确去重（StatsController::exactUniq / todayExact）。
     * 方法保留以便扩展其它允许近似的场景，采集链路不再调用它。
     */
    public function pfadd(string $key, string ...$vals): bool
    {
        $args = ['PFADD', $this->withPrefix($key)];
        foreach ($vals as $v) {
            $args[] = $v;
        }
        return (int) $this->raw($args) > 0;
    }

    /** HyperLogLog 基数估算（PFCOUNT）；UV / 独立 IP 统计已不使用，见 pfadd() 注释 */
    public function pfcount(string $key): int
    {
        return (int) $this->raw(['PFCOUNT', $this->withPrefix($key)]);
    }

    public function zadd(string $key, float $score, string $member): bool
    {
        return (bool) $this->raw(['ZADD', $this->withPrefix($key), (string) $score, $member]);
    }

    public function zincrby(string $key, float $incr, string $member): float
    {
        return (float) $this->raw(['ZINCRBY', $this->withPrefix($key), (string) $incr, $member]);
    }

    public function zrem(string $key, string ...$members): int
    {
        $args = ['ZREM', $this->withPrefix($key)];
        foreach ($members as $m) {
            $args[] = $m;
        }
        return (int) $this->raw($args);
    }

    public function zscore(string $key, string $member): ?float
    {
        $r = $this->raw(['ZSCORE', $this->withPrefix($key), $member]);
        return $r === null ? null : (float) $r;
    }

    public function zcount(string $key, string $min, string $max): int
    {
        return (int) $this->raw(['ZCOUNT', $this->withPrefix($key), $min, $max]);
    }

    /** zrangebyscore，withscores=true 返回 [member=>score,...] */
    public function zrangebyscore(string $key, string $min, string $max, bool $withscores = false, int $limit = 0, int $offset = 0): array
    {
        $args = ['ZRANGEBYSCORE', $this->withPrefix($key), $min, $max];
        if ($limit > 0) {
            $args[] = 'LIMIT';
            $args[] = (string) $offset;
            $args[] = (string) $limit;
        }
        if ($withscores) {
            $args[] = 'WITHSCORES';
        }
        $raw = $this->raw($args);
        if (!is_array($raw)) {
            return [];
        }
        if (!$withscores) {
            return $raw;
        }
        $map = [];
        for ($i = 0; $i + 1 < count($raw); $i += 2) {
            $map[$raw[$i]] = (float) $raw[$i + 1];
        }
        return $map;
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return (int) $this->raw(['ZREMRANGEBYSCORE', $this->withPrefix($key), $min, $max]);
    }

    /** SCAN 遍历（服务端游标）。返回 [cursor, keys[]] */
    public function scan(string $pattern, int $count = 200): array
    {
        $this->connect();
        // 前缀追加到 pattern 开头
        $full = $this->withPrefix($pattern);
        // 使用游标式迭代：调用方循环调用，传入 $cursor
        throw new \LogicException('use scanNext() instead');
    }

    /** 迭代式 SCAN。$cursor 引用传递，返回匹配 key 数组（已去前缀） */
    public function scanKeys(string $pattern, int &$cursor, int $count = 200): array
    {
        $this->connect();
        $full = $this->withPrefix($pattern);
        $raw = $this->raw(['SCAN', (string) $cursor, 'MATCH', $full, 'COUNT', (string) $count]);
        if (!is_array($raw) || count($raw) !== 2) {
            return [];
        }
        $cursor = (int) $raw[0];
        $keys = [];
        $prefix = $this->cfg['prefix'];
        foreach ((array) $raw[1] as $k) {
            if ($prefix !== '' && strncmp($k, $prefix, strlen($prefix)) === 0) {
                $k = substr($k, strlen($prefix));
            }
            $keys[] = $k;
        }
        return $keys;
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->fp = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
