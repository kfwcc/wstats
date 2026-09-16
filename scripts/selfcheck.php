<?php
/**
 * 离线自检脚本（无需 MySQL/Redis，验证纯逻辑模块）
 * 用法：php scripts/selfcheck.php     （在项目根目录下执行；开发副本为 php server/scripts/selfcheck.php）
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Wstat\Support\RedisClient;
use Wstat\Support\Referrer;
use Wstat\Support\UaParser;
use Wstat\Support\Sessionizer;
use Wstat\Support\Util;

$pass = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name  $detail\n";
    }
}

/* ============ 1. RESP 协议解析 ============ */
function respStream(string $resp)
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $resp);
    rewind($fp);
    return $fp;
}

echo "\n[1] RESP parser\n";
$fp = respStream("+OK\r\n");
check('simple string', RedisClient::parseReply($fp) === 'OK');
fclose($fp);

$fp = respStream(":42\r\n");
check('integer', RedisClient::parseReply($fp) === 42);
fclose($fp);

$fp = respStream("$-1\r\n");
check('bulk null', RedisClient::parseReply($fp) === null);
fclose($fp);

$fp = respStream("$5\r\nhello\r\n");
check('bulk string', RedisClient::parseReply($fp) === 'hello');
fclose($fp);

$fp = respStream("*-1\r\n");
check('array null', RedisClient::parseReply($fp) === null);
fclose($fp);

$fp = respStream("*3\r\n:1\r\n$3\r\nfoo\r\n$-1\r\n");
check('array', RedisClient::parseReply($fp) === [1, 'foo', null]);
fclose($fp);

$fp = respStream("-ERR unknown command\r\n");
$err = false;
try {
    RedisClient::parseReply($fp);
} catch (RuntimeException $e) {
    $err = true;
}
check('error throws', $err);
fclose($fp);

/* ============ 2. UA 解析 ============ */
echo "\n[2] UA parser\n";
$ua = UaParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36');
check('chrome/win10', $ua['browser'] === 'Chrome' && $ua['os'] === 'Windows 10' && $ua['device'] === 'desktop', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
check('safari/ios-mobile', $ua['browser'] === 'Safari' && $ua['os'] === 'iOS' && $ua['device'] === 'mobile', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Chrome/119.0 Mobile Safari/537.36');
check('chrome/android', $ua['browser'] === 'Chrome' && $ua['os'] === 'Android' && $ua['device'] === 'mobile', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
check('googlebot', $ua['browser'] === 'GoogleBot' && $ua['device'] === 'bot', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1');
check('ipad tablet', $ua['os'] === 'iOS' && $ua['device'] === 'tablet', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');
check('chrome/macos', $ua['browser'] === 'Chrome' && $ua['os'] === 'macOS', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0.0.0');
check('edge', $ua['browser'] === 'Edge', json_encode($ua));

$ua = UaParser::parse('Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/90.0 Mobile Safari/537.36 MicroMessenger/8.0.0');
check('wechat', $ua['browser'] === 'WeChat', json_encode($ua));

/* ============ 3. 来源分类 ============ */
echo "\n[3] Referrer classify\n";
$r = Referrer::classify('', 'example.com');
check('direct', $r['type'] === 'direct', json_encode($r));

$r = Referrer::classify('', 'example.com', ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'summer']);
check('utm', $r['type'] === 'utm' && $r['source'] === 'google' && $r['medium'] === 'cpc', json_encode($r));

$r = Referrer::classify('https://example.com/other', 'example.com');
check('internal', $r['type'] === 'internal', json_encode($r));

$r = Referrer::classify('https://blog.example.com/x', 'example.com');
check('subdomain internal', $r['type'] === 'internal', json_encode($r));

$r = Referrer::classify('https://www.baidu.com/s?wd=x', 'example.com');
check('baidu search', $r['type'] === 'search' && $r['source'] === 'baidu', json_encode($r));

$r = Referrer::classify('https://m.weibo.cn/u/123', 'example.com');
check('weibo social', $r['type'] === 'social' && $r['source'] === 'weibo', json_encode($r));

$r = Referrer::classify('https://news.ycombinator.com/item?id=1', 'example.com');
check('hn link', $r['type'] === 'link' && $r['source'] === 'ycombinator', json_encode($r));

$r = Referrer::classify('https://www.zhihu.com/question/1', 'example.com');
check('zhihu social', $r['type'] === 'social' && $r['source'] === 'zhihu', json_encode($r));

/* ============ 4. Util ============ */
echo "\n[4] Util\n";
check('domain ok', Util::validDomain('www.example.com') && Util::validDomain('127.0.0.1') && Util::validDomain('localhost'));
check('domain bad', !Util::validDomain('http://x.com/a') && !Util::validDomain('') && !Util::validDomain('a..b'));
check('email', Util::validEmail('a@b.com') && !Util::validEmail('a@'));
$tz = Util::tzOffsetSec('Asia/Shanghai');
check('tz +8', $tz === 8 * 3600, "got $tz");
check('localDay', Util::localDay(1725000000, 480) === gmdate('Y-m-d', 1725000000 + 480 * 60));
[$s0, $s1] = Util::dateRangeToTs('2026-09-01', '2026-09-09', 'Asia/Shanghai');
check('range', $s1 - $s0 === 9 * 86400, "diff=" . ($s1 - $s0));
$k1 = Util::randBase62(10);
$k2 = Util::randBase62(10);
check('base62', strlen($k1) === 10 && $k1 !== $k2);
check('hex', strlen(Util::randHex(16)) === 32);

// 访客 IP（**手动指定来源**，1.0.8 起取消一切自动判断）：
// 严格按指定来源取值；REMOTE_ADDR 忽略任何代理头；XFF / X-Real-IP 取最右；缺失则回落 REMOTE_ADDR。
// 注意：这里显式传来源，避免断言依赖管理端已保存的设置值。
$savedServer = $_SERVER;
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'];
check('ip remote_addr', Util::ipTrace('remote_addr', '')['ip'] === '203.0.113.9', Util::ipTrace('remote_addr', '')['ip']);
check('ip xff right-most', Util::ipTrace('x_forwarded_for', '')['ip'] === '1.2.3.4');
check('ip custom cf', Util::ipTrace('custom', 'CF-Connecting-IP')['ip'] === '5.6.7.8');
$_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '1.2.3.4, 223.5.5.5'];
$ipTrace = Util::ipTrace('x_real_ip', '');
check('ip xrealip right-most', $ipTrace['ip'] === '223.5.5.5', $ipTrace['ip']);
check('ip spoofable flag', $ipTrace['spoofable'] === true && $ipTrace['remote_private'] === true);
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];
$ipFb = Util::ipTrace('x_forwarded_for', '');
check('ip fallback', $ipFb['ip'] === '203.0.113.9' && $ipFb['fallback'] === true);
check('ipCandidate count', count(Util::ipCandidates('remote_addr', '')) === 4);
check('ipInList cidr', Util::ipInList('10.1.2.3', ['10.0.0.0/8']) && !Util::ipInList('192.168.1.1', ['10.0.0.0/8']));
// IPv6 CIDR（原 ip2long 实现对 IPv6 恒 false）
check('ipInList ipv6', Util::ipInList('2606:4700:1234::1', ['2606:4700::/32']) && !Util::ipInList('2606:4701::1', ['2606:4700::/32']));
check('isCloudflare', Util::isCloudflare('172.71.10.5') && !Util::isCloudflare('1.1.1.1'));
$_SERVER = $savedServer;


echo "\n==============================\n";
echo "RESULT: $pass passed, $fail failed\n";



/* ============ 5. Sessionizer（纯逻辑，无需 Redis） ============ */
echo "\n[5] Sessionizer\n";
$hash = [
    'site_id' => '1', 'session_id' => 'SABC123', 'visitor_id' => 'UXYZ789',
    'first_ts' => '1700000000', 'last_ts' => '1700000120',
    'pv' => '3', 'url' => '/a', 'entry_url' => '/a', 'exit_url' => '/b',
    'src_json' => json_encode(['type' => 'search', 'medium' => 'organic']),
    'is_new' => '1', 'browser' => 'Chrome', 'os' => 'Windows', 'device' => 'desktop',
];
$row = \Wstat\Support\Sessionizer::buildRow($hash);
check('buildRow pv', $row !== null && (int) $row['pageviews'] === 3 && (int) $row['duration'] === 120);
check('buildRow bounce0', (int) ($row['bounce'] ?? 1) === 0, 'pv>1 非跳出');
check('buildRow src', ($row['source'] ?? '') === 'search' && ($row['medium'] ?? '') === 'organic');
$hash2 = ['first_ts' => '0', 'last_ts' => '0'];
check('buildRow null', \Wstat\Support\Sessionizer::buildRow($hash2) === null, '缺时间返回 null');
$single = $hash; $single['pv'] = '1';
$r1 = \Wstat\Support\Sessionizer::buildRow($single);
check('bounce single', (int) ($r1['bounce'] ?? 0) === 1, 'pv=1 跳出');
/* ============ 汇总 ============ */
echo "\n==============================\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
