<?php
/**
 * 拉取 ip2region xdb 离线库（**IPv4 + IPv6 双库**）。
 *
 * 用法（在项目根目录下执行）：
 *   php scripts/fetch-geo.php            # 两个库都拉（缺哪个补哪个，已存在则跳过）
 *   php scripts/fetch-geo.php --v4       # 只拉 IPv4 库
 *   php scripts/fetch-geo.php --v6       # 只拉 IPv6 库
 *   php scripts/fetch-geo.php --force    # 已存在也重新下载
 *
 * 产物：data/ip2region.xdb（IPv4，约 10.6MB）、data/ip2region_v6.xdb（IPv6）
 * 源：GitHub raw → jsDelivr → Gitee 依次回退；全部失败时提示手动下载。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$argvList = array_slice($argv, 1);
$only4 = in_array('--v4', $argvList, true);
$only6 = in_array('--v6', $argvList, true);
$force = in_array('--force', $argvList, true);
$want4 = (!$only6) || $only4;
$want6 = (!$only4) || $only6;

/** 库定义：[族, 目标文件, 官方源文件名, 探针 IP] */
$libs = [
    [
        'fam'  => 4,
        'dest' => $root . '/data/ip2region.xdb',
        'src'  => 'ip2region_v4.xdb',
        'probe' => '114.114.114.114',
    ],
    [
        'fam'  => 6,
        'dest' => $root . '/data/ip2region_v6.xdb',
        'src'  => 'ip2region_v6.xdb',
        'probe' => '240e:3b7:3272:d8d0:db09:c067:8d59:539e',
    ],
];

require_once $root . '/app/bootstrap.php';

$fail = 0;
foreach ($libs as $lib) {
    $fam = (int) $lib['fam'];
    if (($fam === 4 && !$want4) || ($fam === 6 && !$want6)) {
        continue;
    }
    $dest = (string) $lib['dest'];
    $tag = 'v' . $fam;
    fwrite(STDOUT, "\n[geo-{$tag}] 目标文件 {$dest}\n");

    if (!$force && is_file($dest) && filesize($dest) > 1_000_000) {
        fwrite(STDOUT, "[geo-{$tag}] 已存在（" . number_format((int) filesize($dest)) . " 字节），跳过下载（--force 可强制重下）\n");
    } else {
        $urls = [
            'https://raw.githubusercontent.com/lionsoul2014/ip2region/master/data/' . $lib['src'],
            'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/' . $lib['src'],
            'https://gitee.com/lionsoul/ip2region/raw/master/data/' . $lib['src'],
        ];
        $saved = false;
        foreach ($urls as $url) {
            fwrite(STDOUT, "[geo-{$tag}] downloading {$url}\n");
            $bin = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 300, 'ignore_errors' => true, 'follow_location' => 1],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]));
            if (is_string($bin) && strlen($bin) > 1_000_000) {
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, $bin);
                fwrite(STDOUT, '[geo-' . $tag . '] saved ' . number_format(strlen($bin)) . " bytes\n");
                $saved = true;
                break;
            }
            fwrite(STDERR, "[geo-{$tag}] source unavailable, try next...\n");
        }
        if (!$saved) {
            fwrite(STDERR, "[geo-{$tag}] FAILED. 请手动下载 {$lib['src']} 并保存为 {$dest}\n");
            $fail++;
            continue;
        }
    }

    // 下载后自检：库文件声明的 IP 版本是否与槽位一致 + 实际解析探活
    $st = \Wstat\Support\IpLocator::status();
    $keyE = $fam === 4 ? 'xdb_exists' : 'xdb6_exists';
    $keyS = $fam === 4 ? 'xdb_size' : 'xdb6_size';
    if (empty($st[$keyE])) {
        fwrite(STDERR, "[geo-{$tag}] 文件不存在或不可用：{$dest}\n");
        $fail++;
        continue;
    }
    $probe = \Wstat\Support\IpLocator::probe((string) $lib['probe']);
    $txt = trim(($probe['country'] ?? '') . ' ' . ($probe['province'] ?? '') . ' ' . ($probe['city'] ?? ''));
    fwrite(
        STDOUT,
        '[geo-' . $tag . '] size=' . number_format((int) $st[$keyS]) . ' bytes, driver='
        . $st['effective'] . ' (v' . $fam . ' 专属: ' . ($fam === 4 ? $st['effective_v4'] : $st['effective_v6']) . ")\n"
    );
    if ($txt !== '') {
        fwrite(STDOUT, "[geo-{$tag}] probe {$lib['probe']} => {$txt}  ✅ 库可用\n");
    } else {
        fwrite(STDERR, "[geo-{$tag}] 文件已写入但解析探活失败：可能下载被截断、放错了地址族，"
            . "或驱动被设为 none（见 php scripts/geo-doctor.php）\n");
        $fail++;
    }
}

if ($fail > 0) {
    fwrite(STDERR, "\n[geo] 有 {$fail} 个库未就绪\n");
    exit(1);
}
fwrite(STDOUT, "\n[geo] 全部就绪\n");
exit(0);
