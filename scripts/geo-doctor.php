<?php
/**
 * IP 地理归属专项诊断：php scripts/geo-doctor.php [site_id]
 * （在项目根目录下执行；开发副本为 php server/scripts/geo-doctor.php）
 *
 * 逐项检查：配置驱动 → xdb 库文件（存在/大小/可读/目录候选）→ 实际解析探活
 *          → events / sessions 的地理字段覆盖率 → 给出结论与修复建议。
 */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Wstat\Support\Db;
use Wstat\Support\IpLocator;

function gline(string $s = ''): void
{
    echo $s, "\n";
}
function gok(string $s): void
{
    gline('  [OK]   ' . $s);
}
function gwarn(string $s): void
{
    gline('  [WARN] ' . $s);
}
function gbad(string $s): void
{
    gline('  [FAIL] ' . $s);
}

gline('========== IP 地理归属诊断 (geo-doctor) ==========');
gline('时间: ' . date('Y-m-d H:i:s'));

/* 1) 驱动与库文件 */
gline("\n[1] 配置与离线库文件");
$st = IpLocator::status();
gline('  config collect.geo_driver = ' . $st['configured']);
gline('  config collect.geo_xdb    = ' . $st['xdb_path'] . '   (IPv4)');
gline('  config collect.geo_xdb6   = ' . $st['xdb6_path'] . '   (IPv6)');
gline('  生效驱动 effective        = ' . $st['effective']
    . '   (IPv4: ' . $st['effective_v4'] . ', IPv6: ' . $st['effective_v6'] . ')');
gline('  data 目录                 = ' . $st['xdb_dir']);

/**
 * 单个库文件的体检：存在 / 体积 / 声明的地址族是否与槽位一致 / 可读。
 *
 * 「声明的地址族」这一项专治最隐蔽的一种坏法：把 v6 库改名成 ip2region.xdb（或两个配置项
 * 指到同一个文件）。那时文件存在、体积也正常，但用 4 字节地址去解析 38 字节的索引项只会
 * 读到垃圾 —— 这里直接读头部 ipVersion 字段对质，明确报出来而不是静默返回空。
 *
 * 体积门槛取 1MB：正常库都是 MB 级，几百 KB 基本是下载被截断的残文件。
 */
$checkLib = static function (int $fam) use ($st): bool {
    $isV4 = $fam === 4;
    $tag = $isV4 ? 'IPv4' : 'IPv6';
    $pathK   = $isV4 ? 'xdb_path' : 'xdb6_path';
    $existsK = $isV4 ? 'xdb_exists' : 'xdb6_exists';
    $sizeK   = $isV4 ? 'xdb_size' : 'xdb6_size';
    $readK   = $isV4 ? 'xdb_readable' : 'xdb6_readable';
    $name    = $isV4 ? 'ip2region.xdb' : 'ip2region_v6.xdb';

    if (empty($st[$existsK])) {
        gbad("[{$tag}] 库文件不存在：" . $st[$pathK]);
        gline('         → 执行 php ' . wstat_rel('scripts/fetch-geo.php') . ' 下载'
            . '（IPv6 库缺失只影响 IPv6 访客，IPv4 不受影响）');
        return false;
    }
    $size = (int) $st[$sizeK];
    if ($size <= 1024 * 1024) {
        gbad("[{$tag}] 库文件存在但体积异常（" . number_format($size) . " 字节，正常为 MB 级）→ 很可能下载失败/被截断，请重新下载");
        return false;
    }
    $fileFam = 0;
    foreach ((array) $st['xdb_candidates'] as $c) {
        if (($c['file'] ?? '') === basename((string) $st[$pathK])) {
            $fileFam = (int) ($c['ip_version'] ?? 0);
            break;
        }
    }
    if ($fileFam !== 0 && $fileFam !== $fam) {
        $gbad("[{$tag}] 库文件声明的是 IPv{$fileFam}，与所在槽位不符 → 解析会全部落空，请换成 {$name}");
        return false;
    }
    gok("[{$tag}] 库文件就绪，大小 " . number_format($size) . ' 字节（约 ' . round($size / 1048576, 1) . ' MB）');
    if (empty($st[$readK])) {
        $gbad("[{$tag}] 库文件不可读（PHP 进程权限不足），请 chmod 644 或调整属主");
        return false;
    }
    return true;
};

$ok4 = $checkLib(4);
$ok6 = $checkLib(6);

if ($ok4 && !$ok6) {
    $gwarn('IPv6 库缺失：IPv6 访客解析不出地域（IPv4 正常）。补齐后 v6 访客即有地域。');
} elseif (!$ok4 && $ok6) {
    $gwarn('IPv4 库缺失：IPv4 访客解析不出地域（IPv6 正常）。');
} elseif (!$ok4 && !$ok6) {
    if (!empty($st['xdb_candidates'])) {
        $names = array_map(
            static fn ($c) => $c['file'] . '（' . number_format((int) $c['size']) . ' 字节'
                . (!empty($c['ip_version']) ? '，声明 IPv' . $c['ip_version'] : '') . '）',
            (array) $st['xdb_candidates']
        );
        gwarn('但同目录发现了其它 xdb 文件：' . implode(', ', $names));
        gline('         → IPv4 库文件名必须为 ip2region.xdb、IPv6 库为 ip2region_v6.xdb，');
        gline('           或在 config 里把 collect.geo_xdb / collect.geo_xdb6 指到实际文件名');
    } else {
        gline('         → 官方库下载目录: https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/');
        gline('         → 或执行: php ' . wstat_rel('scripts/fetch-geo.php'));
    }
}
if ($st['configured'] !== 'xdb' && $st['configured'] !== 'http') {
    gwarn('驱动不是 xdb/http（当前 ' . $st['configured'] . '）→ 采集不会解析地域。改 config 后需重载 PHP-FPM 并重启 worker');
}

/* 2) 解析探活 */
gline("\n[2] 解析探活（固定公网 IP，IPv4 / IPv6 各一组）");
$probes = [
    '114.114.114.114' => $ok4,
    '223.5.5.5'       => $ok4,
    '8.8.8.8'         => $ok4,
    '101.226.103.106' => $ok4,
    '240e:3b7:3272:d8d0:db09:c067:8d59:539e' => $ok6,
    '2400:3200:baba::1'                      => $ok6,
    '2606:4700:4700::1111'                   => $ok6,
];
$pass = 0;
$passV4 = 0;
$passV6 = 0;
foreach ($probes as $ip => $enabled) {
    if (!$enabled) {
        gline("  [SKIP] {$ip} → 该地址族的库未就绪");
        continue;
    }
    $g = IpLocator::probe((string) $ip);
    $txt = trim(($g['country'] ?? '') . ' ' . ($g['province'] ?? '') . ' ' . ($g['city'] ?? ''));
    if ($txt !== '') {
        $pass++;
        if (IpLocator::family((string) $ip) === 4) {
            $passV4++;
        } else {
            $passV6++;
        }
        gok($ip . ' → ' . $txt);
    } else {
        gbad($ip . ' → 解析为空');
    }
}
if ($pass === 0) {
    gline('  → 全部解析失败：库文件损坏 / 驱动不可用 / 文件权限问题，按上面 [1] 处理');
} elseif ($ok4 && $passV4 === 0) {
    gline('  → IPv4 全部解析失败：IPv4 库可能损坏或放错文件（见 [1]）');
} elseif ($ok6 && $passV6 === 0) {
    gline('  → IPv6 全部解析失败：IPv6 库可能损坏，或那个文件其实不是 v6 库（见 [1]）');
}

/* 3) 数据覆盖率 */
gline("\n[3] 数据地理字段覆盖率");
$sites = [];
try {
    $sites = Db::select('SELECT id,name,domain FROM sites ORDER BY id');
} catch (\Throwable $e) {
    gbad('站点查询失败: ' . $e->getMessage());
}
$argSite = isset($argv[1]) ? (int) $argv[1] : 0;
if ($argSite > 0) {
    $sites = array_values(array_filter($sites, fn ($s) => (int) $s['id'] === $argSite));
}
if (empty($sites)) {
    gwarn('没有站点记录（或指定 site_id 不存在）');
}
$sinceDay = gmdate('Y-m-d', time() - 7 * 86400);
foreach ($sites as $s) {
    $sid = (int) $s['id'];
    gline("\n  --- 站点 #{$sid} {$s['name']} ({$s['domain']}) ---");
    try {
        $evAll = (int) Db::value('SELECT COUNT(*) FROM events WHERE site_id=?', [$sid]);
        $evGeo = (int) Db::value("SELECT COUNT(*) FROM events WHERE site_id=? AND country<>''", [$sid]);
        $ev7 = (int) Db::value('SELECT COUNT(*) FROM events WHERE site_id=? AND `day`>=?', [$sid, $sinceDay]);
        $evLoop = (int) Db::value(
            "SELECT COUNT(*) FROM events WHERE site_id=? AND ip IN ('127.0.0.1','::1')",
            [$sid]
        );
        $ssAll = (int) Db::value('SELECT COUNT(*) FROM sessions WHERE site_id=?', [$sid]);
        $ssGeo = (int) Db::value("SELECT COUNT(*) FROM sessions WHERE site_id=? AND country<>''", [$sid]);
        $ss7 = (int) Db::value('SELECT COUNT(*) FROM sessions WHERE site_id=? AND start_ts>=?', [$sid, time() - 7 * 86400]);

        gline("    events   总计 {$evAll}，其中有地域 {$evGeo}；近 7 天事件 {$ev7}；回环 IP {$evLoop}");
        gline("    sessions 总计 {$ssAll}，其中有地域 {$ssGeo}；近 7 天会话 {$ss7}");

        if ($evAll === 0 && $ssAll === 0) {
            gwarn('该站点还没有任何数据，先跑通采集再看地域');
        } elseif ($evLoop > 0 && $evLoop === $evAll) {
            gbad('全部事件的 IP 都是 127.0.0.1 —— 说明采集端取到的是本机反代地址，不是访客地址');
            gline('         → 站点走 Nginx/PHP-FPM（宝塔典型）时真实 IP 通常就在 REMOTE_ADDR；');
            gline('           取不到访客地址时，到「系统设置 → 真实 IP 采集」手动选一个能显示你真实地址的来源');
            gline('         → 套了 CDN：选 X-Forwarded-For；若该头仍是节点地址，选「自定义头」填 CF-Connecting-IP');
            gline('         → 修复后新访问才会带正确 IP/地域（历史数据不回溯）');
        } elseif ($evGeo === 0 && $ssGeo === 0) {
            if ($pass === 0) {
                gbad('所有数据都无地域，且解析探活也失败 → 按 [1][2] 修复库/驱动');
            } elseif ($ev7 === 0) {
                gwarn('近 7 天没有新事件：现有数据都是启用地理之前采集的，新访问才会带地域');
            } else {
                gwarn('近 7 天有新事件但仍无地域：检查采集侧 IP 是否为空/内网（本地或反代未传 X-Forwarded-For）');
            }
        } else {
            gok('地理字段已有数据，地域页应可正常展示');
        }
    } catch (\Throwable $e) {
        gbad('统计失败: ' . $e->getMessage());
    }
}

gline("\n[4] 结论速查");
gline('  · 库文件路径不对/改名 → IPv4 放 ' . $st['xdb_path'] . '（config collect.geo_xdb）；');
gline('                           IPv6 放 ' . $st['xdb6_path'] . '（config collect.geo_xdb6）');
gline('  · 下载中断导致文件损坏 → 重新 php ' . wstat_rel('scripts/fetch-geo.php'));
gline('  · 驱动为 none → config 设 collect.geo_driver=xdb，重载 PHP-FPM + 重启 worker');
gline('  · 库正常但数据为空 → 老数据不回溯，只有新访问会带地域；反代站点注意真实 IP 头');
gline('  · 只有 IPv6 访客没地域 → 缺 ip2region_v6.xdb，跑一次 fetch-geo.php 补齐（不影响 IPv4）');
gline('========== 诊断结束 ==========');
