<?php
/**
 * Redis 键类型守卫：WRONGTYPE 的诊断、审计与坏键隔离。
 *
 * 背景：本项目所有 Redis 键的类型是固定的（见 EXPECT 表）。一旦某个键被写成了
 * 别的类型（同库其它程序键名冲突、历史版本残留、人工误操作），Redis 会返回
 *   WRONGTYPE Operation against a key holding the wrong kind of value
 * 这不属于“网络抖动”，重试永远不会自愈，必须当成配置/数据故障来处理：
 *   - 采集端：不能让上报 500，应降级直写 MySQL（CollectController）
 *   - worker：不能 3 秒一轮无限刷日志，应打印可执行的诊断后退出（worker.php）
 *   - 运维：doctor.php 预检 + 本类 audit() 给出坏键清单与修复命令
 */
declare(strict_types=1);

namespace Wstat\Support;

class RedisGuard
{
    /**
     * 键模式 => [期望类型, 用途]。
     * 顺序即匹配优先级（'queue' 需精确匹配，其余为前缀）。
     */
    public const EXPECT = [
        'queue'   => ['list',   '采集事件队列（LPUSH / BRPOP）'],
        'today:'  => ['hash',   '今日 PV 计数（HINCRBY field=Ymd:pv）'],
        'rt:min:' => ['hash',   '分钟级 PV（HINCRBY field=YmdHi）'],
        'uv:'     => ['string', 'UV 基数 HyperLogLog（历史键：写入已停止，保留声明以兼容存量键）'],
        'ip:'     => ['string', 'IP 基数 HyperLogLog（历史键：写入已停止，保留声明以兼容存量键）'],
        'on:'     => ['zset',   '在线访客（ZADD score=ts）'],
        'ssn:'    => ['hash',   '进行中会话（HMSET / HGETALL）'],
        'live:'   => ['set',    '进行中会话集合（SADD / SMEMBERS）'],
        'v:'      => ['string', '访客首见标记（SET NX EX）'],
    ];

    /** 是否为 Redis 键类型错误（重试无效，属永久故障） */
    public static function isWrongType(\Throwable $e): bool
    {
        return stripos($e->getMessage(), 'WRONGTYPE') !== false;
    }

    /** 逻辑键名 -> 命中的模式（无命中返回 null） */
    public static function patternOf(string $key): ?string
    {
        if (isset(self::EXPECT[$key])) {
            return $key;                        // 精确名（queue）
        }
        foreach (self::EXPECT as $pat => $_) {
            if (str_ends_with($pat, ':') && str_starts_with($key, $pat)) {
                return $pat;
            }
        }
        return null;
    }

    /** 期望类型；未知键返回 null */
    public static function expectedType(string $key): ?string
    {
        $p = self::patternOf($key);
        return $p === null ? null : self::EXPECT[$p][0];
    }

    /**
     * 诊断一个键：类型、元素数、TTL、内容预览，并判断是否与期望类型一致。
     * @return array{key:string,logical:string,type:string,expected:string,size:int,ttl:int,sample:string,ok:bool,known:bool}
     */
    public static function diagnose(RedisClient $r, string $key): array
    {
        $full = $r->fullKey($key);
        $type = 'unknown';
        $size = 0;
        $ttl = 0;
        $sample = '';
        try {
            $type = $r->type($key);
            $ttl = $r->ttl($key);
            if ($type !== 'none') {
                [$size, $sample] = self::probe($r, $key, $type);
            }
        } catch (\Throwable $e) {
            $sample = '读取失败: ' . $e->getMessage();
        }
        $expected = self::expectedType($key);
        return [
            'key' => $full,
            'logical' => $key,
            'type' => $type,
            'expected' => $expected ?? '(未知键)',
            'size' => $size,
            'ttl' => $ttl,
            'sample' => $sample,
            'ok' => $expected === null ? true : ($expected === $type || $type === 'none'),
            'known' => $expected !== null,
        ];
    }

    /** 按实际类型取元素数与内容预览（截断，避免刷屏） */
    private static function probe(RedisClient $r, string $key, string $type): array
    {
        switch ($type) {
            case 'list':
                return [$r->llen($key), self::cut(implode(' | ', $r->lrange($key, 0, 2)), 300)];
            case 'hash': {
                $all = $r->hgetall($key);
                $pairs = [];
                foreach (array_slice($all, 0, 5, true) as $f => $v) {
                    $pairs[] = $f . '=' . self::cut((string) $v, 60);
                }
                return [count($all), self::cut(implode(', ', $pairs), 300)];
            }
            case 'set': {
                $m = $r->smembers($key);
                return [count($m), self::cut(implode(', ', array_slice($m, 0, 5)), 300)];
            }
            case 'zset': {
                $m = $r->zrangebyscore($key, '-inf', '+inf', true, 5);
                $pairs = [];
                foreach ($m as $member => $score) {
                    $pairs[] = $member . '=>' . $score;
                }
                return [$r->zcard($key), self::cut(implode(', ', $pairs), 300)];
            }
            default: {
                $v = (string) $r->get($key);
                return [$r->strlen($key), self::cut($v, 300)];
            }
        }
    }

    /**
     * 生成可直接贴给用户的诊断文本（含修复命令）。
     * 只读，不改动任何键。
     */
    public static function describe(array $d, string $title = 'Redis 键类型错误（WRONGTYPE）'): string
    {
        $db = (int) (wstat_config('redis.db') ?? 0);
        $L = [];
        $L[] = '';
        $L[] = '==================================================================';
        $L[] = "[FATAL] $title";
        $L[] = '------------------------------------------------------------------';
        $L[] = '  key      = ' . $d['key'];
        $L[] = '  实际类型 = ' . $d['type'] . '   （期望 ' . $d['expected'] . '）';
        $L[] = '  元素数   = ' . $d['size'];
        $L[] = '  TTL      = ' . ($d['ttl'] < 0 ? '无过期' : $d['ttl'] . 's')
            . ($d['ttl'] === -2 ? '（键已不存在）' : '');
        if ($d['sample'] !== '') {
            $L[] = '  内容预览 = ' . $d['sample'];
        }
        $L[] = '';
        $L[] = '  含义：该键已存在但不是本项目期望的数据结构，Redis 拒绝了本次操作。';
        $L[] = '        这不是网络抖动，重试不会自愈；worker 会一直刷这条错误且队列永不消费。';
        $L[] = '  常见原因：';
        $L[] = '    1) 同一个 Redis 库被别的程序/站点共用，且键名相同（尤其 redis.prefix 为空时）；';
        $L[] = '    2) 之前跑过键结构不同的旧版本（如哈希队列），升级后残留；';
        $L[] = '    3) 人工用 redis-cli / 面板工具往同名键写过值。';
        $L[] = '';
        $L[] = '  排查（不改动数据）：';
        $L[] = "    redis-cli -n $db TYPE {$d['key']}";
        $L[] = "    redis-cli -n $db OBJECT ENCODING {$d['key']}";
        $L[] = '';
        $L[] = '  修复（确认内容与其它程序有关就先备份，不要直接删）：';
        $L[] = "    redis-cli -n $db RENAME {$d['key']} {$d['key']}:bad_" . date('YmdHis');
        $L[] = "    # 或确认无用后直接删除： redis-cli -n $db DEL {$d['key']}";
        $L[] = '    然后重启 worker。也可直接执行（自动备份坏键后继续消费）：';
        $L[] = '      php ' . wstat_rel('scripts/worker.php') . ' --quarantine';
        $L[] = '  如需彻底避免再次冲突，把 config.php 的 redis.prefix 改成站点专属前缀（如 wstat_xxx:）。';
        $L[] = '==================================================================';
        return implode("\n", $L) . "\n";
    }

    /**
     * 隔离坏键：RENAME 到 {key}:bad_{时间戳}（保留原数据，便于事后确认归属）。
     * 成功返回备份键名，失败返回 null。
     */
    public static function quarantine(RedisClient $r, string $key): ?string
    {
        $bak = $key . ':bad_' . date('YmdHis');
        try {
            if (!$r->rename($key, $bak)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $r->fullKey($bak);
    }

    /**
     * 键类型审计：按 EXPECT 表逐模式抽样检查，返回坏键清单。
     * @param int $cap 每个模式最多抽样多少个键
     * @return array{checked:int,bad:array<string,array{type:string,expected:string,logical:string}>}
     */
    public static function audit(RedisClient $r, int $cap = 200): array
    {
        $checked = 0;
        $bad = [];
        foreach (self::EXPECT as $pat => [$want, $_]) {
            $isPrefix = str_ends_with($pat, ':');
            $cmd = $isPrefix ? $pat . '*' : $pat;
            $cursor = 0;
            $seen = 0;
            do {
                $keys = $r->scanKeys($cmd, $cursor, 200);
                foreach ($keys as $k) {
                    if ($seen++ >= $cap) {
                        break 2;
                    }
                    // 前缀模式下 'v:xxx' 不能被 'v:' 之外的规则误判
                    if ($isPrefix && !str_starts_with($k, $pat)) {
                        continue;
                    }
                    $checked++;
                    $t = $r->type($k);
                    if ($t !== $want && $t !== 'none') {
                        $bad[$r->fullKey($k)] = [
                            'logical' => $k,
                            'type' => $t,
                            'expected' => $want,
                        ];
                    }
                }
            } while ($cursor !== 0);
        }
        return ['checked' => $checked, 'bad' => $bad];
    }

    /** 生成坏键修复命令（审计输出用） */
    public static function fixHint(string $fullKey): string
    {
        $db = (int) (wstat_config('redis.db') ?? 0);
        return "redis-cli -n $db RENAME $fullKey $fullKey:bad_" . date('Ymd');
    }

    /**
     * 审计结果的可读输出（含逐键修复命令）。
     * 用于不可定位到单个键的 WRONGTYPE（如 reap/sessionize 内部命令）。
     */
    public static function describeAudit(array $a, string $title = 'Redis 键类型审计'): string
    {
        $L = [];
        $L[] = '';
        $L[] = '==================================================================';
        $L[] = "[FATAL] $title";
        $L[] = '------------------------------------------------------------------';
        if (empty($a['bad'])) {
            $L[] = "  未发现类型异常键（已检查 {$a['checked']} 个）。";
            $L[] = '  若错误出现在 ssn:/v: 等按会话生成的键上，可用 redis-cli KEYS "wstat:ssn:*" 抽查。';
            $L[] = '==================================================================';
            return implode("\n", $L) . "\n";
        }
        $L[] = '  发现 ' . count($a['bad']) . " 个键类型与预期不符（已检查 {$a['checked']} 个）：";
        foreach ($a['bad'] as $k => $info) {
            $L[] = sprintf('    %-52s 实际=%-7s 期望=%s', $k, $info['type'], $info['expected']);
        }
        $L[] = '';
        $L[] = '  修复（逐个确认后执行，RENAME 会把原数据保留在新键名下）：';
        foreach (array_keys($a['bad']) as $k) {
            $L[] = '    ' . self::fixHint($k);
        }
        $L[] = '  或在 worker 启动时加 --quarantine 自动隔离队列键后继续。';
        $L[] = '  根因多为：同库共用且键名前缀冲突（redis.prefix）／旧版本残留／人工误写。';
        $L[] = '==================================================================';
        return implode("\n", $L) . "\n";
    }

    private static function cut(string $s, int $len): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
