<?php
/**
 * 消费 Worker（常驻进程）
 *
 * 用法：php scripts/worker.php          （建议 supervisor / nohup 守护；在项目根目录下执行）
 * 可选：php scripts/worker.php --once   （单轮拉取即退出，便于测试）
 * 可选：php scripts/worker.php --quarantine
 *       （队列键被写成别的类型时，自动 RENAME 备份该键后继续消费）
 * 开发副本（本目录在 server/ 下）路径为 php server/scripts/worker.php。
 *
 * 职责：从 Redis 队列批量消费原始事件 →
 *       1. 会话化：打开会话维护在 Redis(ssn:{sid})，空闲超时即落库 sessions
 *       2. 事件明细攒批写入 events（每批 100 行，降低写放大）
 *
 * 注意：Redis 返回 WRONGTYPE 表示某个键的数据结构被污染（键名冲突/旧版本残留/人工误写），
 *       属永久故障而非网络抖动。此时不进入 3 秒重试循环，而是打印诊断（键名/实际类型/修复命令）
 *       后退出，避免“日志一直刷、队列永不消费”的假死状态。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wstat\Support\Db;
use Wstat\Support\IpLocator;
use Wstat\Support\Rds;
use Wstat\Support\RedisClient;
use Wstat\Support\RedisGuard;
use Wstat\Support\Sessionizer;

$once = in_array('--once', $argv, true);
// 队列键被写成别的类型时，自动 RENAME 备份坏键后继续消费（默认不自动改数据）
$quarantine = in_array('--quarantine', $argv, true);

/* ---------- 单实例守卫 ----------
 * 同一个队列只允许一个「常驻」消费者。并发消费会互相覆盖数据：
 *   续接会话是「HGETALL 读 → pv+1 → HMSET 写」的非原子流程，两个 worker 同时处理
 *   同一会话的两条 pageview 时会双双读到旧 pv，写回同一个值 → 会话 pv/停留时长少计；
 *   reapIdle 也可能在另一个 worker 正写入时把会话提前关闭。
 * flock 由内核在进程退出（含崩溃/kill）时自动释放，不会留下陈旧锁。
 * --once 是单轮诊断模式，不参与守卫（允许在有常驻 worker 时临时执行）。
 */
$lockFp = null;
if (!$once) {
    $lockFile = dirname(__DIR__) . '/data/worker.lock';
    $lockFp = @fopen($lockFile, 'c');
    if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
        fwrite(
            STDERR,
            "[worker] 已有另一个 worker 正在消费同一队列（锁文件 {$lockFile}），本次启动直接退出。\n"
            . "         并发消费者会在会话哈希上产生「读改写」竞争，导致 pv / 停留时长少计，请只保留一个常驻 worker。\n"
            . "         确认是陈旧进程时可先结束它，或用 php " . wstat_rel('scripts/doctor.php') . " 查看队列状况。\n"
        );
        exit(0);
    }
    ftruncate($lockFp, 0);
    fwrite($lockFp, 'pid=' . getmypid() . ' started=' . date('c') . "\n");
    fflush($lockFp);
}

$idle = (int) wstat_config('security.session_idle');
$newWin = (int) wstat_config('security.new_visitor_win');

$flushEvents = function (array &$rows) {
    if ($rows) {
        // 过滤掉未迁移的列，保证 events 结构升级期间不中断
        $clean = [];
        foreach ($rows as $row) {
            $clean[] = Db::filterColumns('events', $row);
        }
        Db::insertBatch('events', $clean, 100);
        $rows = [];
    }
};

$rows = [];
$closed = 0;
$processed = 0;
$idleLoops = 0;
$started = microtime(true);

/**
 * 会话化：仅 pageview 驱动；perf/event/click 挂靠现有会话。
 * 任何 Redis 异常向上抛，由主循环统一分类（Redis 键类型污染不能被吞掉）。
 */
$sessionize = function (RedisClient $r, array $raw, int $now, int &$closed) use ($idle, $newWin): void {
    $type = (string) ($raw['type'] ?? '');
    $sessionId = (string) ($raw['session_id'] ?? '');
    $visitorId = (string) ($raw['visitor_id'] ?? '');
    if ($sessionId === '') {
        return;
    }
    if ($type === 'pageview') {
        $hash = $r->hgetall(Sessionizer::HASH_KEY . $sessionId);
        if ($hash && (int) ($hash['last_ts'] ?? 0) > 0 && $now - (int) $hash['last_ts'] > $idle) {
            // 会话超时：关闭旧会话，开启新会话
            if (Sessionizer::close($r, $sessionId)) {
                $closed++;
            }
            $hash = [];
        }
        if (!$hash) {
            $isNew = 0;
            if ($visitorId !== '') {
                // SET NX EX：不存在则写入并视为新访客
                $set = $r->setnx('v:' . $visitorId, '1', $newWin);
                $isNew = $set ? 1 : 0;
            }
            $r->hmset(Sessionizer::HASH_KEY . $sessionId, [
                'site_id' => (string) ($raw['site_id'] ?? ''),
                'session_id' => $sessionId,
                'visitor_id' => $visitorId,
                'end_user' => (string) ($raw['end_user'] ?? ''),
                'first_ts' => (string) $now,
                'last_ts' => (string) $now,
                'pv' => '1',
                'url' => (string) ($raw['url'] ?? ''),
                'title' => (string) ($raw['title'] ?? ''),
                'entry_url' => (string) ($raw['url'] ?? ''),
                'exit_url' => (string) ($raw['url'] ?? ''),
                'src_json' => json_encode([
                    'type' => $raw['source'] ?? 'direct',
                    'medium' => $raw['medium'] ?? '',
                    'campaign' => $raw['campaign'] ?? '',
                    'content' => $raw['content'] ?? '',
                    'term' => $raw['term'] ?? '',
                    'click_id' => $raw['click_id'] ?? '',
                ], JSON_UNESCAPED_UNICODE),
                'is_new' => (string) $isNew,
                'browser' => (string) ($raw['browser'] ?? ''),
                'os' => (string) ($raw['os'] ?? ''),
                'device' => (string) ($raw['device'] ?? ''),
                'screen' => (string) ($raw['screen'] ?? ''),
                'lang' => (string) ($raw['lang'] ?? ''),
                'ip' => (string) ($raw['ip'] ?? ''),
                'country' => (string) ($raw['country'] ?? ''),
                'province' => (string) ($raw['province'] ?? ''),
                'city' => (string) ($raw['city'] ?? ''),
            ]);
        } else {
            // 续接会话（老会话缺 IP 时顺带补齐，便于列表/详情展示；end_user 首个携带值优先）
            $r->hmset(Sessionizer::HASH_KEY . $sessionId, [
                'last_ts' => (string) $now,
                'pv' => (string) ((int) ($hash['pv'] ?? 1) + 1),
                'url' => (string) ($raw['url'] ?? ''),
                'title' => (string) ($raw['title'] ?? ''),
                'exit_url' => (string) ($raw['url'] ?? ''),
                'ip' => (string) ($hash['ip'] ?? $raw['ip'] ?? ''),
                'end_user' => ((string) ($hash['end_user'] ?? '') !== '')
                    ? (string) $hash['end_user']
                    : (string) ($raw['end_user'] ?? ''),
            ]);
        }
    } elseif ($type !== 'hb' && $r->exists(Sessionizer::HASH_KEY . $sessionId)) {
        // 行为/性能事件：刷新会话最后活跃时间
        $r->hmset(Sessionizer::HASH_KEY . $sessionId, ['last_ts' => (string) $now]);
    }
};

/* ---------- 顶层兜底：未被内层捕获的异常统一分类，绝不让全局 JSON 异常处理器杀死常驻进程 ---------- */
while (true) {
try {
while (true) {
    $r = Rds::get();
    if ($r === null) {
        // Redis 不可用（含认证缺失/错误）：不退出崩溃循环，等待恢复后自动重连
        fwrite(STDERR, '[worker] Redis unavailable, retry in 3s...' . "\n");
        if ($once) {
            exit(1);
        }
        sleep(3);
        continue;
    }
    try {
        $got = $r->brpop('queue', 2);
    } catch (\Throwable $e) {
        // WRONGTYPE（键类型被污染）属永久故障：重试不会自愈，必须明确报错而不是 3 秒一轮刷屏。
        if (RedisGuard::isWrongType($e)) {
            // 先用全新连接复核：长连接流错位也会产生伪 WRONGTYPE，且旧连接上连 TYPE 都读不准。
            // 新连接复核 queue=list/none → 判定为连接异常，重连后继续消费（数据无损）。
            Rds::reset();
            $r2 = Rds::get();
            $real = ($r2 !== null) ? $r2->type('queue') : '?';
            if ($real === 'list' || $real === 'none') {
                fwrite(STDERR, '[worker] brpop WRONGTYPE 但新连接复核 queue=' . $real . '（疑似连接流错位，非真污染），重连后继续消费。' . "\n");
                if ($once) {
                    exit(1);
                }
                continue;
            }
            fwrite(STDERR, '[worker] 新连接复核 queue=' . $real . '，确认键类型污染：' . "\n");
            fwrite(STDERR, RedisGuard::describe(RedisGuard::diagnose($r2 ?? $r, 'queue')));
            if ($quarantine) {
                $bak = RedisGuard::quarantine($r2 ?? $r, 'queue');
                if ($bak !== null) {
                    fwrite(STDERR, "[worker] 已将坏键备份为 {$bak}，队列重建后继续消费。\n");
                    Rds::reset();
                    continue;
                }
                fwrite(STDERR, "[worker] 隔离坏键失败（键可能已被删除或无权重命名），请手工处理后重启。\n");
            }
            exit(1);
        }
        fwrite(STDERR, '[worker] Redis error: ' . $e->getMessage() . ', retry in 3s...' . "\n");
        Rds::reset();
        if ($once) {
            exit(1);
        }
        sleep(3);
        continue;
    }

    if ($got === null) {
        $flushEvents($rows);
        if ($once) {
            break;
        }
        // 看门狗：连接流错位时 brpop/消费「看起来正常」但会话化会静默失败，
        // 空闲时 PING 自检，回复不是 +PONG 立即重连（每 3 个空闲轮询查一次，开销可忽略）。
        if (($idleLoops % 3) === 0 && !$r->ping()) {
            fwrite(STDERR, '[worker] ping watchdog: Redis 连接流异常（疑似错位），重连后继续。' . "\n");
            Rds::reset();
            continue;
        }
        // 队列空闲时定期兜底回收空闲会话（不依赖 crontab 也能落库 sessions）
        if (++$idleLoops >= 15) {
            $idleLoops = 0;
            try {
                [$c, $o] = Sessionizer::reapIdle($r);
                if ($c > 0 || $o > 0) {
                    fwrite(STDOUT, "[worker] reaped closed=$c offline=$o\n");
                }
            } catch (\Throwable $e) {
                if (RedisGuard::isWrongType($e)) {
                    // ssn:/on: 等键被污染：reap 属兜底逻辑，不退出，但必须把坏键清单报出来
                    fwrite(STDERR, RedisGuard::describeAudit(RedisGuard::audit($r), 'Redis 键类型错误（会话回收阶段）'));
                } else {
                    fwrite(STDERR, '[worker] reap error: ' . $e->getMessage() . "\n");
                }
            }
        }
        continue;
    }

    // 兼容两种载荷形态：当前 brpop 返回 JSON 字符串；历史版本可能已解码为数组
    // （直接对数组做 (string) 强转会触发 "Array to string conversion" 且事件被丢弃）
    $val = $got[1];
    if (is_array($val)) {
        $raw = $val;
    } else {
        $raw = json_decode((string) $val, true);
        if (!is_array($raw)) {
            fwrite(STDERR, '[worker] skip invalid payload: ' . substr((string) $val, 0, 200) . "\n");
            continue;
        }
    }
    $processed++;

    // ---- IP 归属补齐（老队列载荷采集时未解析；已带 geo 的新载荷跳过） ----
    $raw = IpLocator::enrich($raw);

    /* ---------- 会话化（仅 pageview 驱动；perf/event/click 挂靠现有会话） ---------- */
    $type = (string) ($raw['type'] ?? '');
    $now = (int) ($raw['ts'] ?? time());
    try {
        $sessionize($r, $raw, $now, $closed);
    } catch (\Throwable $e) {
        if (RedisGuard::isWrongType($e)) {
            // ssn:/v:/live: 等键被污染：无法定位单个键 → 直接审计出坏键清单
            fwrite(STDERR, RedisGuard::describeAudit(RedisGuard::audit($r), 'Redis 键类型错误（会话化阶段）'));
            fwrite(STDERR, '[worker] 提示：' . $e->getMessage() . "\n");
            exit(1);
        }
        // 其它 Redis 异常：本次事件仍落库，不中断消费
        fwrite(STDERR, '[worker] sessionize error: ' . $e->getMessage() . "\n");
    }

    /* ---------- 攒批落库（hb 不落库） ---------- */
    if ($type !== 'hb') {
        $rows[] = $raw;
    }
    if (count($rows) >= 100) {
        $flushEvents($rows);
    }

    /* 心跳：定期汇报 */
    if ($processed % 500 === 0) {
        $sec = max(0.001, microtime(true) - $started);
        fwrite(STDOUT, sprintf("[worker] processed=%d closed=%d rate=%.0f/s\n", $processed, $closed, $processed / $sec));
    }

    if ($once) {
        $flushEvents($rows);
        break;
    }
}
break; // 内层 while 正常退出（--once）
} catch (\Throwable $e) {
    if (RedisGuard::isWrongType($e)) {
        // 键类型污染属永久故障：顶层兜底也要打印坏键清单后退出，重试不会自愈
        $r2 = Rds::get();
        if ($r2 !== null) {
            fwrite(STDERR, RedisGuard::describeAudit(RedisGuard::audit($r2), 'Redis 键类型错误（顶层兜底捕获）'));
        }
        fwrite(STDERR, '[worker] ' . $e->getMessage() . "\n");
        exit(1);
    }
    // 其它异常（含 DB 抖动）：未落库批次保留在 $rows，重连后重试
    fwrite(STDERR, '[worker] top-level error: ' . $e->getMessage() . ', retry in 3s...' . "\n");
    Rds::reset();
    if ($once) {
        exit(1);
    }
    sleep(3);
}
}

fwrite(STDOUT, "[worker] done. processed={$processed} closed={$closed}\n");
exit(0);
