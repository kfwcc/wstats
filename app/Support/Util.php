<?php
/**
 * 通用工具类
 */
declare(strict_types=1);

namespace Wstat\Support;

class Util
{
    /** 密码学安全随机 hex */
    public static function randHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** 随机 base62 短串（site_key 等公开标识） */
    public static function randBase62(int $len = 10): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $n = strlen($chars);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, $n - 1)];
        }
        return $out;
    }

    public static function validEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** 域名校验：允许域名 / IPv4 / localhost（不含协议路径） */
    public static function validDomain(string $domain): bool
    {
        $d = trim($domain);
        if ($d === '') {
            return false;
        }
        if (strlen($d) > 190) {
            return false;
        }
        if ($d === 'localhost' || filter_var($d, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d);
    }

    /** 合法时区名 */
    public static function validTz(string $tz): bool
    {
        return in_array($tz, timezone_identifiers_list(), true);
    }

    /** 站点时区相对 UTC 的偏移秒数（服务端兜底，SDK 会随包上报 tz 分钟数） */
    public static function tzOffsetSec(string $tz): int
    {
        try {
            $d = new \DateTimeImmutable('now', new \DateTimeZone($tz));
            return $d->getOffset();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /** ts + 客户端分钟偏移 => 站点本地日期 'Y-m-d' */
    public static function localDay(int $ts, int $tzMinutes): string
    {
        return gmdate('Y-m-d', $ts + $tzMinutes * 60);
    }

    /** 将站点本地日期区间换算为 UTC 时间戳边界 [startTs, endTsExclusive) */
    public static function dateRangeToTs(string $start, string $end, string $tz): array
    {
        $tzo = new \DateTimeZone($tz);
        $s = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tzo);
        $e = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $tzo);
        if ($s === false || $e === false || $s > $e) {
            return [0, 0];
        }
        $startTs = $s->getTimestamp();
        $endTs = $e->modify('+1 day')->getTimestamp();
        return [$startTs, $endTs];
    }

    /** 按天补齐序列：返回 ['day'=>Y-m-d,...] 由闭包填充 */
    public static function dailySeries(int $startTs, int $endTsExclusive, string $tz, callable $fill): array
    {
        $offset = self::tzOffsetSec($tz);
        $out = [];
        $day = gmdate('Y-m-d', $startTs + $offset);
        $lastDay = gmdate('Y-m-d', ($endTsExclusive - 1) + $offset);
        $t = $startTs;
        while (true) {
            $d = gmdate('Y-m-d', $t + $offset);
            $out[$d] = $fill($d);
            if ($d >= $lastDay) {
                break;
            }
            $t += 86400;
        }
        return $out;
    }

    /** 限长字符串 */
    public static function cut(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) : $s;
    }

    /** 请求体 JSON 安全解码 */
    public static function jsonArr(string $raw): ?array
    {
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /**
     * Cloudflare 官方回源 IP 段（来源 https://www.cloudflare.com/ips-v4 、 https://www.cloudflare.com/ips-v6）。
     *
     * 1.0.8 起**不参与** IP 取值决策（取值来源由管理员手动指定，见 IP_SOURCES），
     * 只用于诊断提示：当 REMOTE_ADDR 命中这里、而管理员又选了 `remote_addr` 时，
     * 说明记录到的是 CF 边缘节点地址，管理端会明确提示改选来源。
     * Cloudflare 会调整这些网段，升级版本时同步刷新即可。
     */
    public const CF_RANGES = [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * 可选的 IP 取值来源（**全部由管理员手动指定，程序不做任何自动判断**）。
     *
     * - `x_forwarded_for` 取 `X-Forwarded-For` 最右侧地址（最近一跳代理追加的值）；
     * - `x_real_ip`       取 `X-Real-IP` 最右侧地址；
     * - `remote_addr`     直接取 `REMOTE_ADDR`（TCP 对端，客户端无法伪造）；
     * - `custom`          取 `collect.ip_source_header` / 设置项 `ip_source_header` 指定的头。
     *
     * 为什么 X-Forwarded-For / X-Real-IP 一律取**最右**：代理（Cloudflare、Nginx）都是把真实客户端
     * 地址**追加**到末尾，客户端自带的伪造值只能残留在左侧。取最左 = 直接采信攻击者的输入，
     * 这正是「统计里出现保留地址 / 组播地址」的根因。即便取最右，这两类头在「源站可被直连」时
     * 仍可被完整伪造 —— UI 与文档都明确标注了这一点，请优先选 `remote_addr`。
     */
    public const IP_SOURCES = ['x_forwarded_for', 'x_real_ip', 'remote_addr', 'custom'];

    /** 内置来源 → 请求头名（custom 由配置决定，不在此列） */
    private const IP_SOURCE_HEADERS = [
        'x_forwarded_for' => 'X-Forwarded-For',
        'x_real_ip'       => 'X-Real-IP',
        'remote_addr'     => '',
    ];

    /**
     * 客户端 IP（严格按管理员指定的来源取值，无任何自动推断）。
     *
     * 命中规则：
     * 1. 配置了 `remote_addr` → 直接返回 REMOTE_ADDR；
     * 2. 配置了某个头但本次请求里没有合法 IP（头缺失 / 值非法）→ **回落 REMOTE_ADDR**，
     *    并在诊断里标记 `fallback=true`（宁可记到代理地址，也不能把访客记成空）；
     * 3. 其余情况返回该头里最右侧的合法 IP。
     */
    public static function clientIp(): string
    {
        return self::ipTrace()['ip'];
    }

    /**
     * IP 取值过程的「全链路」诊断（只读 $_SERVER + 设置，不写任何状态）。
     *
     * 返回判定过程而非只返回结果，用途：
     * - 管理端「真实 IP 采集」卡片：列出每个候选来源**当前请求会取到什么值**，让管理员挑一个
     *   能显示自己真实地址的来源（这就是「手动指定」的交互基础）；
     * - 纯函数自测：显式传入 $source / $custom 即可在不起 Web 服务的前提下断言各分支。
     *
     * @param string|null $source 强制来源（null = 读设置，见 ipSourceSetting()）
     * @param string|null $custom 强制自定义头名（null = 读设置）
     * @return array{ip:string,remote:string,source_key:string,source:string,header:string,raw:string,
     *               value:string,fallback:bool,spoofable:bool,remote_cdn:string,remote_private:bool,
     *               candidates:array<int,array<string,mixed>>,note:string,warn:array<int,string>}
     */
    public static function ipTrace(?string $source = null, ?string $custom = null): array
    {
        $remote = self::normalizeIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $key    = $source;
        // 只在「没显式指定来源」时读设置：既避免自测触发数据库读取，也保证显式传参就是最终结果
        if ($key === null || ($key === 'custom' && $custom === null)) {
            [$s, $c] = self::ipSourceSetting();
            if ($key === null) {
                $key = $s;
            }
            if ($custom === null) {
                $custom = $c;
            }
        }
        $custom = $custom === null ? '' : $custom;
        if (!in_array($key, self::IP_SOURCES, true)) {
            $key = 'remote_addr';
        }

        $header = $key === 'custom' ? self::normalizeHeaderName($custom) : (self::IP_SOURCE_HEADERS[$key] ?? '');
        // 自定义框里填了 REMOTE_ADDR → 等价于直接选 REMOTE_ADDR（避免出现一个永远取不到值的死配置）
        if ($key === 'custom' && strcasecmp($header, 'REMOTE_ADDR') === 0) {
            $key = 'remote_addr';
            $header = '';
        }
        $raw = $header !== ''
            ? (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] ?? '')
            : '';

        $out = [
            'ip'             => $remote,
            'remote'         => $remote,
            'source_key'     => $key,
            'source'         => $header !== '' ? $header : 'REMOTE_ADDR',
            'header'         => $header,
            'raw'            => self::cut(trim($raw), 300),
            'value'          => '',
            'fallback'       => false,
            'spoofable'      => $header !== '',
            'remote_cdn'     => $remote !== '' && self::isCloudflare($remote) ? 'Cloudflare' : '',
            'remote_private' => $remote !== '' && !self::isPublicIp($remote),
            'candidates'     => self::ipCandidates($key, $custom),
            'note'           => '',
            'warn'           => [],
        ];

        if ($key === 'remote_addr') {
            $out['note'] = '取值来源：REMOTE_ADDR（TCP 对端地址，客户端无法伪造）。';
        } elseif ($header === '') {
            // 选了「自定义头」但头名为空/非法 → 读不到任何头，按取值失败处理并回落
            $out['fallback'] = true;
            $out['note'] = '已选择「自定义头」但头名称为空或不合法 → 已回落 REMOTE_ADDR。'
                . '请填写合法的请求头名称（如 CF-Connecting-IP）。';
        } else {
            $picked = self::lastIpFromList($raw);
            if ($picked === '') {
                $out['fallback'] = true;
                $out['note'] = '来源 ' . $header . ' 本次没有合法 IP → 已回落 REMOTE_ADDR。'
                    . '若访客 IP 显示为 CDN / 反代节点地址，说明该头未被透传，请改选其它来源。';
            } else {
                $out['ip'] = $picked;
                $out['value'] = $picked;
                $out['note'] = '取值来源：' . $header . '，取该头**最右侧**的合法 IP。';
            }
        }

        if ($out['spoofable']) {
            $out['warn'][] = $header . ' 是客户端可自行设置的头：源站若能被绕过 CDN 直接访问，'
                . '任何人都能伪造它来污染统计与限流。请确认该头在 CDN / 反代侧被覆盖写入后再使用。';
        }
        if ($out['source_key'] === 'remote_addr' && $out['remote_cdn'] !== '') {
            $out['warn'][] = 'REMOTE_ADDR 属于 ' . $out['remote_cdn'] . ' 回源网段 → 记录到的是 CDN 节点地址，'
                . '请改选 X-Forwarded-For，或选「自定义头」并填 CF-Connecting-IP。';
        }
        if ($out['fallback']) {
            $out['warn'][] = '所选的 ' . $header . ' 本次没有合法 IP，已回落 REMOTE_ADDR。';
        }
        return $out;
    }

    /**
     * 全部候选来源**在当前请求**下会取到的值。
     *
     * 管理端据此列出「选这项 → 会记录成 X.X.X.X」，管理员挑一个能显示自己真实地址的即可，
     * 无需理解任何代理链原理。
     *
     * @return array<int,array{key:string,label:string,header:string,raw:string,value:string,
     *                         ok:bool,spoofable:bool,current:bool}>
     */
    public static function ipCandidates(string $current = '', string $custom = ''): array
    {
        if ($current === '' && $custom === '') {
            [$current, $custom] = self::ipSourceSetting();
        }
        $defs = [
            ['x_forwarded_for', 'X-Forwarded-For', 'X-Forwarded-For'],
            ['x_real_ip',       'X-Real-IP',       'X-Real-IP'],
            ['remote_addr',     'REMOTE_ADDR',     ''],
            ['custom',          trim($custom) !== '' ? trim($custom) : '自定义头', $custom],
        ];
        $out = [];
        foreach ($defs as [$k, $label, $h]) {
            if ($k === 'custom') {
                $h = self::normalizeHeaderName($h);
            }
            if ($k === 'remote_addr') {
                $raw = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $val = self::normalizeIp($raw);
            } else {
                $raw = $h === '' ? '' : (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '');
                $val = self::lastIpFromList($raw);
            }
            $out[] = [
                'key'       => $k,
                'label'     => $label,
                'header'    => $h,
                'raw'       => self::cut(trim($raw), 300),
                'value'     => $val,
                'ok'        => $val !== '',
                'spoofable' => $k !== 'remote_addr',
                'current'   => $k === $current,
            ];
        }
        return $out;
    }

    /**
     * 当前生效的来源设置：`sys_settings`（管理端保存，最高优先级）→ `config.php` 默认值 → 内置默认。
     * 返回 [source, customHeader]；source 一定落在 self::IP_SOURCES 内。
     */
    public static function ipSourceSetting(): array
    {
        $src = '';
        $hdr = '';
        try {
            $s = Settings::ipSource();
            $src = (string) ($s['source'] ?? '');
            $hdr = (string) ($s['header'] ?? '');
        } catch (\Throwable $e) {
            $src = '';
        }
        if ($src === '') {
            $cfg = wstat_config('collect.ip_source');
            $src = is_string($cfg) ? trim($cfg) : '';
            $ch = wstat_config('collect.ip_source_header');
            if ($hdr === '' && is_string($ch)) {
                $hdr = trim($ch);
            }
        }
        if (!in_array($src, self::IP_SOURCES, true)) {
            $src = 'remote_addr';
        }
        return [$src, self::normalizeHeaderName($hdr)];
    }

    /** 单个 IP 归一化：去空白、::1 → 127.0.0.1、非法返回空串、限长 45 */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '::1') {
            return '127.0.0.1';
        }
        if ($ip === '' || strlen($ip) > 45 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        return $ip;
    }

    /**
     * 从（可能是逗号分隔的）头值里取**最右侧**的合法 IP，没有则返回空串。
     *
     * 取最右是刻意的：Cloudflare / Nginx 都把真实客户端地址**追加**到末尾，
     * 只有最右那个值是由离本站最近的一跳写入的；左侧可能是攻击者自带的伪造值
     * （旧实现取最左，正是「统计里出现 239.x 组播地址」的根因）。
     */
    public static function lastIpFromList(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $parts = explode(',', $raw);
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $ip = self::normalizeIp($parts[$i]);
            if ($ip !== '') {
                return $ip;
            }
        }
        return '';
    }

    /** 头名规范化：只允许 RFC 7230 token 字符，非法一律返回空串（头名不参与 SQL/HTML，仍按不可信处理） */
    public static function normalizeHeaderName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64) {
            return '';
        }
        // 下划线是合法 token 字符（也兼容 REMOTE_ADDR 这类写法）；必须字母数字开头，杜绝 CRLF / 空格注入
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) === 1 ? $name : '';
    }

    /** 是否公网可路由地址（排除内网 / 保留段） */
    public static function isPublicIp(string $ip): bool
    {
        return $ip !== '' && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * 是否 Cloudflare 边缘节点地址（官方回源段内）。
     *
     * 1.0.8 起**不再**参与取值决策（来源由管理员手动指定，见 IP_SOURCES），
     * 仅用于管理端诊断与告警：「你选了 REMOTE_ADDR，但它其实是 CF 节点地址」。
     */
    public static function isCloudflare(string $ip): bool
    {
        return self::ipInList($ip, self::CF_RANGES);
    }

    /**
     * IP 是否命中列表（支持精确 IP 与 CIDR，IPv4 / IPv6 均可）。
     *
     * 原实现用 ip2long —— 对 IPv6 恒返回 false，意味着站点一旦走 IPv6 回源（Cloudflare、
     * 运营商 IPv6），「可信代理」判定会**静默失效**：REMOTE_ADDR 明明在 CF 段内却匹配不上，
     * 于是真实 IP 取不到。这里统一改为 inet_pton 的字节级前缀比较。
     */
    public static function ipInList(string $ip, array $list): bool
    {
        $ip = trim($ip);
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($list as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if (strpos($rule, '/') === false) {
                // 精确匹配：按二进制比较，兼容 IPv6 的压缩/大小写写法
                if ($rule === $ip || @inet_pton($rule) === $packed) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $rule, 2);
            $netPacked = @inet_pton(trim((string) $net));
            $bits = (int) $bits;
            // 族不一致（v4 规则不用于 v6 地址）或前缀长度越界 → 跳过该规则
            if ($netPacked === false || strlen($netPacked) !== strlen($packed)
                || $bits < 0 || $bits > strlen($packed) * 8) {
                continue;
            }
            if (self::prefixEqual($packed, $netPacked, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** 两个同族二进制地址的前 $bits 位是否相同 */
    private static function prefixEqual(string $a, string $b, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
    }
}
