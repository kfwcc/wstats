<?php
/**
 * WebStats 一键诊断（排查“装了统计脚本但没有数据”）
 * 用法：php scripts/doctor.php     （在项目根目录下执行；开发副本为 php server/scripts/doctor.php）
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wstat\Support\Db;
use Wstat\Support\Rds;
use Wstat\Support\RedisGuard;

$ok = 0;
$warn = 0;
$err = 0;

function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function mark(bool $good, string $msg): void {
    global $ok, $warn, $err;
    if ($good) { $ok++; line("  [OK]   $msg"); }
    else { $warn++; line("  [!!]   $msg"); }
}

line("========== WebStats Doctor ==========");

/* 1) PHP 环境 */
line("\n[1] PHP 环境");
mark(version_compare(PHP_VERSION, '8.0', '>='), 'PHP ' . PHP_VERSION);
mark(extension_loaded('pdo_mysql'), 'pdo_mysql');
mark(extension_loaded('curl'), 'curl（站点文件验证需要）');

/* 2) MySQL */
line("\n[2] MySQL");
$db = wstat_config('db');
try {
    $pdo = Db::pdo(); // 触发连接
    mark(true, "已连接 {$db['host']}:{$db['port']}/{$db['name']}");
    $tables = [];
    foreach (Db::select('SHOW TABLES') as $r) {
        $tables[] = (string) array_values($r)[0];
    }
    foreach (['sites', 'auth_tokens', 'events', 'sessions', 'site_daily'] as $tb) {
        mark(in_array($tb, $tables, true), "表 $tb 存在");
    }
    // 归因扩展列（来源分析/广告追踪需要）
    $cols = [];
    foreach (Db::select('SHOW COLUMNS FROM events') as $r) {
        $cols[] = (string) $r['Field'];
    }
    $need = ['utm_source', 'utm_medium', 'click_source', 'ref_host'];
    $missing = array_values(array_filter($need, fn ($c) => !in_array($c, $cols, true)));
    if ($missing) {
        mark(false, 'events 缺少归因列: ' . implode(',', $missing) . ' → 执行: mysql -uroot -p < sql/upgrade-2026-09-09-sources.sql');
    } else {
        mark(true, 'events 归因列齐全（utm_source/utm_medium/click_source/ref_host）');
    }
    // 操作日志表（老库需跑增量 SQL；缺表时个人中心「我的操作日志」会明确提示建表）
    if (!in_array('user_op_logs', $tables, true)) {
        mark(false, "user_op_logs 缺失 → 在**当前库 {$db['name']}** 执行: "
            . 'mysql -u<user> -p ' . $db['name'] . ' < sql/upgrade-2026-09-16-profile-users.sql'
            . '（已建过的话请确认 SQL 是不是执行到了别的库）');
    } else {
        mark(true, 'user_op_logs 存在（登录/改密/用户管理操作可留痕）');
    }
    // 分区边界
    $p = Db::first(
        "SELECT PARTITION_NAME pn, PARTITION_DESCRIPTION pd FROM information_schema.PARTITIONS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events'
         ORDER BY PARTITION_ORDINAL_POSITION DESC LIMIT 1"
    );
    if ($p) {
        mark(true, "events 最新分区 {$p['pn']} 上界 {$p['pd']}（未来需跑 cron partition）");
    } else {
        mark(false, 'events 无分区（建表未用 sql/install.sql，或分区占位标记未展开？）');
    }
} catch (\Throwable $e) {
    mark(false, 'MySQL 连接失败: ' . $e->getMessage() . '（检查 config.php 的 WSTAT_DB_*）');
    line("\n诊断中止：数据库不可用则无法继续。");
    exit(1);
}

/* 3) Redis */
line("\n[3] Redis");
$r = null;
try { $r = Rds::get(); } catch (\Throwable $e) { /* ignore */ }
if ($r === null) {
    // 探明具体原因：直接发 PING 拿原始错误（区分 NOAUTH/WRONGPASS/拒连/超时）
    $why = '未知';
    try {
        $probe = new \Wstat\Support\RedisClient(wstat_config('redis'));
        $pong = $probe->raw(['PING']);
        $why = $pong === 'PONG' ? 'PING 通过但应用层判定不可用（异常）' : 'PING 返回异常: ' . var_export($pong, true);
    } catch (\Throwable $e) {
        $why = $e->getMessage();
    }
    mark(false, 'Redis 不可用 → 采集降级直写 MySQL（可收数据但无实时/队列/会话聚合）。原因: ' . $why
        . '。若含 NOAUTH 或 WRONGPASS：请把 Redis 密码填入环境变量 WSTAT_REDIS_AUTH（或 config.php 的 redis.auth），重启 php-fpm 与 worker');
} else {
    mark(true, 'Redis 已连接（实时计数/队列在线）');
    try {
        // 队列键：先查类型再看长度。类型被污染时 LLEN 会直接抛 WRONGTYPE，
        // 那正是 worker 报 “WRONGTYPE Operation against a key holding the wrong kind of value” 的根因。
        $qt = $r->type('queue');
        $qk = $r->fullKey('queue');
        if ($qt === 'none') {
            mark(true, "采集队列为空（key=$qk 不存在，无积压）");
        } elseif ($qt === 'list') {
            $qlen = (int) $r->llen('queue');
            mark($qlen < 500, "采集队列积压 $qlen 条" . ($qlen > 0 ? ' → 队列有数据但 events 表还没有，说明 worker 未消费。请启动: php ' . wstat_rel('scripts/worker.php') . '（supervisor 守护）' : ''));
        } else {
            // ⚠️ 必须写 {$qt}：PHP 标识符允许 0x80-0xFF 字节，全角逗号紧跟 $var 时会被
            // 吃进变量名（这里原本解析成 $qt，期望 → 未定义变量），诊断行会变成「实际==list」。
            mark(false, "队列键 {$qk} 类型错误：实际={$qt}，期望=list");
            line('        → worker 会持续刷 “WRONGTYPE ... wrong kind of value” 且队列永不消费（不是网络抖动，重试不会自愈）');
            line('        → 修复（RENAME 会把原数据保留在新键名下，先确认它是不是别的程序在用）：');
            line('             ' . RedisGuard::fixHint($qk));
            line('             ' . "redis-cli -n " . (int) (wstat_config('redis.db') ?? 0) . " DEL $qk   # 确认无用后再删");
            line('        → 或启动时自动隔离：php ' . wstat_rel('scripts/worker.php') . ' --quarantine');
        }
    } catch (\Throwable $e) {
        mark(false, 'Redis 读取异常: ' . $e->getMessage());
    }

    // 全键类型审计：一次性找出所有被写坏类型的键（today:/rt:min:/uv:/ip:/on:/ssn:/live:/v:）
    try {
        $a = RedisGuard::audit($r, 200);
        if (empty($a['bad'])) {
            mark(true, "键类型审计通过（抽样 {$a['checked']} 个键，类型均正确）");
        } else {
            mark(false, '键类型审计发现 ' . count($a['bad']) . " 个异常键（会导致 WRONGTYPE / 实时数缺失）：");
            foreach ($a['bad'] as $k => $info) {
                line(sprintf('        %-56s 实际=%-7s 期望=%s', $k, $info['type'], $info['expected']));
            }
            foreach (array_keys($a['bad']) as $k) {
                line('        修复: ' . RedisGuard::fixHint($k));
            }
            line('        根因多为：同一 Redis 库被别的程序/站点共用且键名冲突（redis.prefix 为空时最易发生）、旧版本残留、人工误写。');
        }
    } catch (\Throwable $e) {
        mark(false, '键类型审计失败: ' . $e->getMessage());
    }
}

/* 4) 站点与数据 */
line("\n[4] 站点 & 数据");
$sites = Db::select(
    'SELECT s.id,s.name,s.domain,s.status,s.verified_at,s.site_key,
            (SELECT COUNT(*) FROM events e WHERE e.site_id=s.id AND e.day>=CURDATE()) today_events,
            (SELECT COUNT(*) FROM sessions ss WHERE ss.site_id=s.id AND ss.start_ts>=UNIX_TIMESTAMP()-7*86400) week_sessions
     FROM sites s ORDER BY id'
);
if (!$sites) {
    mark(false, '还没有任何站点 → 先在「站点管理」创建站点并完成文件验证');
}
foreach ($sites as $s) {
    $ver = (int) $s['verified_at'] > 0 ? '已验证' : '未验证';
    line("  站点 #{$s['id']} {$s['name']} ({$s['domain']}) [{$ver} / status={$s['status']}] 今日事件(UTC)={$s['today_events']} 近7日会话={$s['week_sessions']}");
    if ((int) $s['status'] !== 1) {
        mark(false, "站点 #{$s['id']} 已被停用(status=0)，不会接收上报");
    }
    if ((int) $s['verified_at'] === 0) {
        mark(false, "站点 #{$s['id']} 未验证 → 采集端默认拒绝(403)。完成文件验证，或本地联调设置 WSTAT_ALLOW_UNVERIFIED=1");
    }
}

/* 4b) 数据链路快照：events 明细 vs 汇总，直接定位“有明细无看板” */
line("\n[4b] 数据链路快照（近 7 天，按站点/日期/类型）");
foreach ($sites as $s) {
    $sid = (int) $s['id'];
    line("  ── 站点 #{$sid} {$s['name']} ──");
    try {
        $ev = Db::select(
            "SELECT `day` d, type, COUNT(*) c FROM events
             WHERE site_id=? AND `day` >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 7 DAY), '%Y-%m-%d')
             GROUP BY `day`, type ORDER BY d, type",
            [$sid]
        );
        if (!$ev) {
            mark(false, 'events 近 7 天无该站点数据（确认上报 ak 是否等于该站点 site_key；或脚本未上报成功）');
        } else {
            $hasPv = false;
            foreach ($ev as $r) {
                if ($r['type'] === 'pageview') {
                    $hasPv = true;
                }
                line("    events {$r['d']}  type={$r['type']}  rows={$r['c']}");
            }
            if (!$hasPv) {
                mark(false, '近 7 天只有非 pageview 事件（perf/click/scroll…），没有 pageview → 看板 PV 恒为 0。'
                    . '页面加载时的 pageview 上报疑似失败（常见于当时 Redis 假健康导致 500，或 SDK 未在 <head> 正常执行）。'
                    . '修复 Redis 认证后重开页面即可，也可用下方 curl 直接验证采集链路');
            }
        }
        // 站点级采集自测命令（真实 ak，方便复制执行后重跑 doctor 验证）
        $ak = (string) ($s['site_key'] ?? '');
        line('    └ 自测命令（把 {统计域名} 换成你的部署域名后执行，会写入 1 条 pageview + 1 个会话）:');
        line("      curl -X POST 'http://{统计域名}/collect.php' -H 'Content-Type: text/plain' -d '{\"v\":1,\"ak\":\"{$ak}\",\"t\":\"pageview\",\"uid\":\"UTest0001\",\"sid\":\"STest0001\",\"url\":\"/\",\"title\":\"t\",\"tz\":480,\"lang\":\"zh-CN\",\"scr\":\"1920x1080\"}'");
        $sd = Db::first(
            "SELECT COUNT(*) row7, COALESCE(SUM(pv),0) pv7, COALESCE(SUM(uv),0) uv7,
                    COALESCE(SUM(visits),0) v7
             FROM site_daily WHERE site_id=? AND `day` >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 7 DAY), '%Y-%m-%d')",
            [$sid]
        );
        line("    site_daily 近7天: 行数={$sd['row7']} pv={$sd['pv7']} uv={$sd['uv7']} visits={$sd['v7']}"
            . ($sd['row7'] === 0 ? '（空 → 概览接口会按 events 自动回填，或先跑 cron rollup）'
                : ((int) $sd['pv7'] === 0 && !empty($ev) ? ' ← 汇总疑似 0 值行，概览已加入自动校正' : '')));
        // sessions 明细快照：有 pageview 但 sessions 空 → 区分“Redis 进行中”与“降级写失败”
        $ssc = (int) $s['week_sessions'];
        if ($ssc === 0) {
            if ($r !== null) {
                line('    sessions 近7天(已回收): 0 行 ← Redis 模式下会话先驻留内存、空闲 30 分钟才落库；'
                    . '进行中的访问已实时计入概览与“会话列表(LIVE)”顶部，刷新页面即可看到。'
                    . '若 30 分钟后仍无行且列表无 LIVE，请查 php error_log 的 degradedSession/insertIgnore 错误');
            } else {
                line('    sessions 近7天: 0 行'
                    . (!empty($ev) ? ' ← events 有 pageview 但无会话行：降级直写应在采集时自动建会话；Redis 模式需 worker+cron。'
                        . '请查看 php error_log 是否含 [wstat] degradedSession failed' : ''));
            }
        } else {
            line("    sessions 近7天: $ssc 行（最近 5 条，旧→新）");
            $ss = Db::select(
                'SELECT session_id,visitor_id,pageviews,duration,bounce,start_ts FROM sessions
                 WHERE site_id=? ORDER BY id DESC LIMIT 5',
                [$sid]
            );
            foreach (array_reverse($ss) as $x) {
                line("      sess {$x['session_id']} uid={$x['visitor_id']} pv={$x['pageviews']} dur={$x['duration']}s bounce={$x['bounce']} @"
                    . gmdate('m-d H:i', (int) $x['start_ts']));
            }
        }
    } catch (\Throwable $e) {
        mark(false, '快照查询失败: ' . $e->getMessage());
    }
}
if (count($sites) > 1) {
    line('  ※ 若快照显示某站点有 events 而面板所选站点无 → 请确认顶部站点选择器选中的就是该站点');
}
line('  ※ 判定规则：看板 PV=site_daily(回填自 events type=pageview)；visits/跳出/时长=sessions(需 worker 或降级直写)；今日实时=Redis。');

/* 5) 常见原因提示 */
line("\n[5] 若上面都正常仍无数据，逐项核对");
line("  ① 统计脚本能否加载：浏览器直接打开 {你的统计域名}/sdk/wstat.js 应返回 JS（404=没部署到 public/sdk/）");
line("  ② 安装代码 data-site-key 与 data-host 是否正确、页面 <head> 已插入");
line("  ③ 页面与统计域名协议一致（http/https 混用会被浏览器拦截上报）");
line("  ④ Redis 队列积压时 events 表暂无数据 → 必须启动 worker.php（生产用 supervisor 守护）");
line('     若 worker 日志出现 “WRONGTYPE Operation against a key holding the wrong kind of value” → 某个 Redis 键被写成了别的类型（键名冲突/旧版本残留/人工误写），重试不会自愈。先跑本脚本第 [3] 节的「键类型审计」拿到坏键清单，RENAME 备份后重启 worker；详见 docs/deploy.md「七、运维排查」');
line("  ⑤ 数据要进概览还需 site_daily：crontab 每5分钟跑 cron.php rollup（缺失天接口会自动回填）");
line("  ⑥ 采集接口自测：curl -X POST 'http://{统计域名}/collect.php' -H 'Content-Type: text/plain' -d '{\"v\":1,\"ak\":\"{站点标识}\",\"t\":\"pageview\",\"uid\":\"UTest0001\",\"sid\":\"STest0001\",\"url\":\"/\",\"title\":\"t\",\"tz\":480,\"lang\":\"zh-CN\",\"scr\":\"1920x1080\"}'");
line('     若返回 405 Method Not Allowed → nginx 没把请求交给 PHP，而是当静态文件直出了：删除站点配置里的 location = /collect.php { try_files $uri =404; }（精确匹配会抢在 location ~ \.php$ 之前），详见 docs/deploy.md「七、运维排查」');
line('     备用通道（.php 路径被 WAF/CDN 拦截时用）：POST /api/collect，参数与 /collect.php 完全一致');
line("  ⑦ 有 PV 但缺 visits/跳出率/时长 → sessions 未生成：Redis 可用时会话先存 Redis，需 worker 持续运行 + cron session 空闲回收");
line("  ⑧ 手动立即补数：php " . wstat_rel('scripts/cron.php') . " session rollup （随后刷新概览页）");
line("  ⑨ 地域页无数据：跑 php " . wstat_rel('scripts/geo-doctor.php') . " 一键诊断（库文件/驱动/真实 IP/覆盖率）");
line("     最常见两种：库文件缺失或改名（IPv4 需 " . wstat_rel('data/ip2region.xdb') . "、IPv6 需 " . wstat_rel('data/ip2region_v6.xdb') . "）；宝塔 Nginx+PHP-FPM 下 REMOTE_ADDR=127.0.0.1 未取代理头");

line("\n========== 结果：OK=$ok  注意=$warn ==========");
exit($warn > 0 ? 2 : 0);
