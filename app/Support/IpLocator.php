<?php
/**
 * IP 地理解析器（驱动可插拔：none | http | xdb）
 *
 * - xdb ：ip2region xdb 离线库纯 PHP 检索，**IPv4 / IPv6 双库**：
 *           · IPv4 → `collect.geo_xdb` （默认 server/data/ip2region.xdb）
 *           · IPv6 → `collect.geo_xdb6`（默认 server/data/ip2region_v6.xdb）
 *         实现参考 ip2region 官方 v3 绑定 Searcher（Apache-2.0），改为二分检索，零依赖。
 *
 *         两库结构相同（256B 头 + 512KiB 向量索引 + 数据区 + 二分索引），差异只有两处：
 *           ① 地址宽度：IPv4 = 4 字节，IPv6 = 16 字节；
 *           ② 索引项大小：IPv4 = 14 = 4+4+2+4，IPv6 = 38 = 16+16+2+4。
 *
 *         **字节序不同，这是最容易踩的坑**：IPv4 库的 sip/eip 是**小端**（ip2region 早期实现的
 *         历史包袱），IPv6 库是**大端原样 16 字节**。所以比对方式也不同 —— IPv4 用小端整型比较，
 *         IPv6 用 16 字节二进制串 `strcmp`（memcmp 语义，无符号逐字节）比较。二者都等价于
 *         「按地址数值比大小」，但绝不能互换。
 *
 *         向量索引定位公式两族一致：header(256B) + 地址前两字节 → b1*2048 + b2*8。
 *
 * - http：接口型驱动。config collect.geo_http_url 形如 https://host/path?ip={ip}，
 *         geo_http_key 非空时自动拼 key 参数（或替换 {key} 占位）。
 *         兼容 ad_info.{nation,province,city}（腾讯）/ data.{nation,province,city}（百度）
 *         / 顶层 {nation,province,city} / ip-api {country,regionName,city} 等常见 JSON 结构。
 *         IPv6 传入 {ip} 时会先 rawurlencode（裸 `:` 出现在 URL 路径段里是非法的）。
 * - none ：不解析（采集字段保持空串）。
 *
 * 两族互相独立降级：v6 库缺失只影响 v6 访客，v4 照常解析（反之亦然）。
 * 只有一个库都没有时才整体降级 none。
 *
 * `::ffff:a.b.c.d`（IPv4-mapped，双栈套接字常见）会**改判为 IPv4** 并走 v4 库 ——
 * 它本质就是 IPv4 地址，而 v4 库的数据覆盖远好于在 v6 库里查一个 v6 段。
 *
 * 解析结果进程内按 IP 缓存（worker 常驻收益最大；FPM 每次请求内复用）。
 */
declare(strict_types=1);

namespace Wstat\Support;

class IpLocator
{
    /** 解析结果进程内缓存上限 */
    private const CACHE_MAX = 2048;

    /** 地址族（4 / 6）→ 库文件句柄 */
    private static array $fp = [4 => null, 6 => null];

    /** 地址族 → 库文件路径（首次读配置后缓存） */
    private static array $paths = [];

    /** 地址族 → 头部信息：byte_num / seg_size / file_version / ip_version */
    private static array $heads = [];

    /** 地址族 → 已确认不可用（打开或头部校验失败；不再重试，避免每次查询都白跑一次 fopen） */
    private static array $dead = [4 => false, 6 => false];

    /** 配置里的驱动（none|http|xdb），null = 尚未读取配置 */
    private static ?string $driver = null;

    /** @var array<string,array{country:string,province:string,city:string}> */
    private static array $cache = [];

    /**
     * IPv6 内网 / 保留段：直接跳过检索。
     * IPv4 侧不走这张表，用位运算快判（见 isPrivate），避免每次解析都做 CIDR 匹配。
     */
    private const V6_INTERNAL = [
        '::/128',          // 未指定地址
        '::1/128',         // 回环
        'fc00::/7',        // ULA 唯一本地地址
        'fe80::/10',       // 链路本地
        'ff00::/8',        // 组播
        '2001:db8::/32',   // 文档示例段
        '2002::/16',       // 6to4：内嵌 IPv4，地域归属不确定，按「不可解析」处理
    ];

    /* ================= 配置与可用性 ================= */

    /** 配置里的驱动（none|http|xdb），不含任何可用性判断 */
    private static function configured(): string
    {
        if (self::$driver === null) {
            $d = (string) (wstat_config('collect.geo_driver') ?? 'none');
            self::$driver = in_array($d, ['none', 'http', 'xdb'], true) ? $d : 'none';
        }
        return self::$driver;
    }

    /** 指定地址族的库文件路径 */
    private static function xdbPath(int $fam): string
    {
        if (!isset(self::$paths[$fam])) {
            if ($fam === 6) {
                $p = (string) (wstat_config('collect.geo_xdb6') ?? '');
                self::$paths[6] = $p !== '' ? $p : WSTAT_ROOT . '/data/ip2region_v6.xdb';
            } else {
                $p = (string) (wstat_config('collect.geo_xdb') ?? '');
                self::$paths[4] = $p !== '' ? $p : WSTAT_ROOT . '/data/ip2region.xdb';
            }
        }
        return self::$paths[$fam];
    }

    /**
     * 指定地址族当前能否真正解析。
     *
     * xdb 驱动下不只看「文件在不在」，还要**能打开并通过头部校验**（声明的地址族与槽位一致、
     * 数据指针宽度受支持）。否则「文件存在但放错族」时诊断会给出一片绿灯，而解析必然返回空 ——
     * 那种假绿灯比直接报错更难排查。
     */
    private static function usable(int $fam): bool
    {
        $c = self::configured();
        if ($c === 'http') {
            return true;
        }
        if ($c !== 'xdb') {
            return false;
        }
        return self::openXdb($fam) !== null;
    }

    /**
     * 生效驱动（用于诊断展示）。
     *
     * xdb 驱动下**按族独立降级**：只有两族库都不可用时才整体降级 none；
     * 单族缺失不影响另一族（这是相对早期单库实现的语义变化）。
     */
    private static function driver(): string
    {
        $c = self::configured();
        if ($c === 'xdb' && !self::usable(4) && !self::usable(6)) {
            return 'none';
        }
        return $c;
    }

    /**
     * 诊断信息：配置驱动 / 生效驱动（整体 + 按族）/ 两个库文件状态 / 目录下候选 xdb。
     * 用于 doctor / geo-doctor 脚本与后台页面的「为什么没有地域数据」提示。
     *
     * 历史键名（`xdb_path` / `xdb_exists` / `xdb_size` / `xdb_readable` / `xdb_dir`）继续指向
     * **IPv4 库**，避免老前端与老诊断页取不到值；IPv6 库用 `xdb6_*` 前缀的平行键。
     */
    public static function status(): array
    {
        $p4 = self::xdbPath(4);
        $p6 = self::xdbPath(6);
        $e4 = is_file($p4);
        $e6 = is_file($p6);

        // 目录下所有 .xdb 候选（含各自头部声明的 IP 版本，便于识别「放错文件/改错名」）
        $cands = [];
        foreach (array_values(array_unique([dirname($p4), dirname($p6)])) as $dir) {
            $list = @scandir($dir);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $f) {
                if (!is_string($f) || substr(strtolower($f), -4) !== '.xdb') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $f;
                $cands[] = [
                    'file'       => $f,
                    'size'       => (int) @filesize($full),
                    'ip_version' => self::peekIpVersion($full),
                ];
            }
        }

        return [
            'configured'   => (string) (wstat_config('collect.geo_driver') ?? 'none'),
            'effective'    => self::driver(),
            'effective_v4' => self::usable(4) ? self::configured() : 'none',
            'effective_v6' => self::usable(6) ? self::configured() : 'none',
            // 历史键名 → IPv4 库
            'xdb_path'     => $p4,
            'xdb_exists'   => $e4,
            'xdb_size'     => $e4 ? (int) @filesize($p4) : 0,
            'xdb_readable' => $e4 && is_readable($p4),
            'xdb_dir'      => dirname($p4),
            // IPv6 库
            'xdb6_path'     => $p6,
            'xdb6_exists'   => $e6,
            'xdb6_size'     => $e6 ? (int) @filesize($p6) : 0,
            'xdb6_readable' => $e6 && is_readable($p6),
            'xdb_candidates' => $cands,
        ];
    }

    /** 只读前 20 字节判断库文件声明的 IP 版本；读不出返回 0 */
    private static function peekIpVersion(string $file): int
    {
        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return 0;
        }
        $h = fread($fp, 20);
        fclose($fp);
        if (!is_string($h) || strlen($h) !== 20) {
            return 0;
        }
        $hd = unpack('vversion/vpolicy/Vcreated/Vsiptr/Veiptr/vipver/vptrbytes', $h);
        $ver = (int) ($hd['version'] ?? 0);
        if ($ver === 2) {
            return 4;                 // v2 结构只有 IPv4
        }
        return $ver === 3 ? (int) ($hd['ipver'] ?? 0) : 0;
    }

    /* ================= 地址族判定 ================= */

    /** 地址族：4 / 6；非法地址返回 0 */
    public static function family(string $ip): int
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 0;
        }
        if (strpos($ip, ':') !== false) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 0;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 4 : 0;
    }

    /**
     * `::ffff:a.b.c.d`（IPv4-mapped）→ 点分 IPv4 串；非该形式返回空串。
     *
     * 只认 `::ffff:0:0/96` 这一段：它才是双栈套接字实际吐出来的形式。
     * IPv4-compatible（`::a.b.c.d`，RFC 4291 已废弃）不处理 —— 它会和 `::` / `::1` 撞车，
     * 硬拆反而把「未指定地址」变成 `0.0.0.0` 这类噪音。
     */
    private static function mappedV4(string $ip): string
    {
        $b = @inet_pton($ip);
        if ($b === false || strlen($b) !== 16) {
            return '';
        }
        if (substr($b, 0, 12) !== str_repeat("\x00", 10) . "\xff\xff") {
            return '';
        }
        $n = unpack('N', substr($b, 12, 4));
        return long2ip((int) ($n[1] ?? 0));
    }

    /** 探活：清空缓存后按当前驱动解析固定公网 IP（诊断用） */
    public static function probe(string $ip = '114.114.114.114'): array
    {
        self::$fp = [4 => null, 6 => null];
        self::$heads = [];
        self::$dead = [4 => false, 6 => false];
        self::$driver = null;
        self::$paths = [];
        self::$cache = [];
        return self::resolve($ip);
    }

    /** 空结果 */
    private static function emptyGeo(): array
    {
        return ['country' => '', 'province' => '', 'city' => ''];
    }

    /** 解析 IP，返回 ['country'=>,'province'=>,'city'=>]（无法识别时为空串） */
    public static function resolve(string $ip): array
    {
        $ip = trim($ip);
        $fam = self::family($ip);
        if ($fam === 0) {
            return self::emptyGeo();
        }
        // IPv4-mapped → 改判 IPv4 走 v4 库（本质是 v4 地址，v4 库覆盖更好）
        if ($fam === 6) {
            $v4 = self::mappedV4($ip);
            if ($v4 !== '') {
                $ip = $v4;
                $fam = 4;
            }
        }
        // 内网 / 保留地址直接跳过，避免无效检索
        if (self::isPrivate($ip, $fam)) {
            return self::emptyGeo();
        }
        if (self::driver() === 'none') {
            return self::emptyGeo();
        }
        if (isset(self::$cache[$ip])) {
            return self::$cache[$ip];
        }
        $geo = self::configured() === 'http' ? self::httpLookup($ip) : self::xdbLookup($ip, $fam);
        if (count(self::$cache) >= self::CACHE_MAX) {
            self::$cache = [];
        }
        self::$cache[$ip] = $geo;
        return $geo;
    }

    /** 给事件数组补齐地理字段（仅在为空时解析；返回新数组） */
    public static function enrich(array $evt): array
    {
        $ip = (string) ($evt['ip'] ?? '');
        $need = ($evt['country'] ?? '') === '' && ($evt['province'] ?? '') === '' && ($evt['city'] ?? '') === '';
        if ($need && $ip !== '') {
            $geo = self::resolve($ip);
            if (($geo['country'] ?? '') !== '' || ($geo['province'] ?? '') !== '' || ($geo['city'] ?? '') !== '') {
                $evt['country'] = (string) $geo['country'];
                $evt['province'] = (string) $geo['province'];
                $evt['city'] = (string) $geo['city'];
            }
        }
        return $evt;
    }

    /* ================= xdb 离线库 ================= */

    /**
     * 按地址族检索。
     *
     * 两族共用同一套流程（向量索引 → 二分索引 → 数据区），只有「地址键」与「索引项布局」不同，
     * 故把差异收敛到 addrKey() / recKey() / keyCmp() 三个函数里，检索逻辑只写一份。
     */
    private static function xdbLookup(string $ip, int $fam): array
    {
        $fp = self::openXdb($fam);
        if ($fp === null) {
            return self::emptyGeo();
        }
        $head = self::$heads[$fam] ?? null;
        if ($head === null) {
            return self::emptyGeo();
        }
        $byteNum = (int) $head['byte_num'];      // 4 / 16
        $segSize = (int) $head['seg_size'];      // 14 / 38

        $probe = self::addrKey($ip, $fam);
        if ($probe === null) {
            return self::emptyGeo();
        }

        // 1) 向量索引定位段索引区间：header 256B + b1*256*8 + b2*8
        //    两族都用地址的**前两个字节**（v4 = 最高两字节；v6 = 头两字节），公式一致
        $b1 = self::addrByte($ip, $fam, 0);
        $b2 = self::addrByte($ip, $fam, 1);
        if (fseek($fp, 256 + $b1 * 2048 + $b2 * 8) !== 0) {
            return self::emptyGeo();
        }
        $vec = fread($fp, 8);
        if ($vec === false || strlen($vec) !== 8) {
            return self::emptyGeo();
        }
        $u = unpack('Vsptr/Veptr', $vec);
        $sPtr = (int) ($u['sptr'] ?? 0);
        $ePtr = (int) ($u['eptr'] ?? 0);
        if ($sPtr === 0 || $ePtr === 0 || $ePtr <= $sPtr) {
            return self::emptyGeo();
        }

        // 2) 段索引二分
        $lo = 0;
        $hi = (int) (($ePtr - $sPtr) / $segSize);
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            if (fseek($fp, $sPtr + $mid * $segSize) !== 0) {
                break;
            }
            $rec = fread($fp, $segSize);
            if ($rec === false || strlen($rec) !== $segSize) {
                break;
            }
            $sip = self::recKey($rec, 0, $byteNum, $fam);
            $eip = self::recKey($rec, $byteNum, $byteNum, $fam);
            if (self::keyCmp($fam, $probe, $sip) < 0) {
                $hi = $mid - 1;
            } elseif (self::keyCmp($fam, $probe, $eip) > 0) {
                $lo = $mid + 1;
            } else {
                // 3) 命中：读数据区（数据长度 2B + 数据指针 4B，均小端）
                $r = unpack('vlen/Vptr', substr($rec, $byteNum * 2, 6));
                $len = (int) ($r['len'] ?? 0);
                $ptr = (int) ($r['ptr'] ?? 0);
                if ($len <= 0 || $ptr <= 0) {
                    return self::emptyGeo();
                }
                if (fseek($fp, $ptr) !== 0) {
                    return self::emptyGeo();
                }
                $region = fread($fp, $len);
                if ($region === false || strlen($region) !== $len) {
                    return self::emptyGeo();
                }
                return self::regionToGeo($region);
            }
        }
        return self::emptyGeo();
    }

    /**
     * 查询地址的「可比较键」：v4 = 无符号 32 位整数；v6 = 16 字节大端二进制串。
     * 非法地址返回 null。
     */
    private static function addrKey(string $ip, int $fam)
    {
        if ($fam === 4) {
            $n = ip2long($ip);
            return $n === false ? null : (int) (sprintf('%u', $n) & 0xFFFFFFFF);
        }
        $b = @inet_pton($ip);
        return ($b === false || strlen($b) !== 16) ? null : $b;
    }

    /** 取地址第 $i 个字节（0 起，大端语义） */
    private static function addrByte(string $ip, int $fam, int $i): int
    {
        if ($fam === 4) {
            $n = (int) (sprintf('%u', (int) ip2long($ip)) & 0xFFFFFFFF);
            return ($n >> (24 - $i * 8)) & 0xFF;
        }
        $b = (string) @inet_pton($ip);
        return ord($b[$i] ?? "\x00");
    }

    /**
     * 从索引记录里取地址键。
     *
     * **字节序是两族的根本差异**：IPv4 库的 sip/eip 以小端存储（ip2region 早期实现的历史包袱，
     * 官方 v4 比较器还要把字节倒着读一轮），IPv6 库是大端原样 16 字节。这里各自解成
     * 「数值可比」的形式 —— v4 用小端 int，v6 用原样字节串。
     */
    private static function recKey(string $rec, int $at, int $bytes, int $fam)
    {
        if ($fam === 4) {
            $u = unpack('V', substr($rec, $at, 4));
            return (int) ($u[1] ?? 0);
        }
        return substr($rec, $at, $bytes);
    }

    /** 两个地址键比较：<0 / 0 / >0。IPv4 比整数，IPv6 比字节串 */
    private static function keyCmp(int $fam, $a, $b): int
    {
        if ($fam === 4) {
            return $a === $b ? 0 : ((int) $a < (int) $b ? -1 : 1);
        }
        // strcmp 是 memcmp 语义（无符号逐字节），正好等价于 128 位地址的数值比较；
        // 注意不能用 PHP 的 `<` 比二进制串 —— 若串恰好是「数字形式」会退化成数值比较
        $c = strcmp((string) $a, (string) $b);
        return $c === 0 ? 0 : ($c < 0 ? -1 : 1);
    }

    /**
     * 打开并校验指定族的库文件。
     *
     * 头部校验会**核对库文件声明的 IP 版本与所在槽位是否一致** —— 有人把 v6 库改名成
     * ip2region.xdb（或两个配置指到同一个文件）时，会在这里被挡下并标记该族不可用，
     * 而不是拿着 4 字节的地址去解析 38 字节的索引项（那样只会读到垃圾数据）。
     */
    private static function openXdb(int $fam)
    {
        if (self::$fp[$fam] !== null && is_resource(self::$fp[$fam])) {
            return self::$fp[$fam];
        }
        if (!empty(self::$dead[$fam])) {
            return null;
        }
        $path = self::xdbPath($fam);
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            self::$dead[$fam] = true;
            return null;
        }
        $h = fread($fp, 20);
        if ($h === false || strlen($h) !== 20) {
            fclose($fp);
            self::$dead[$fam] = true;
            return null;
        }
        $hd = unpack('vversion/vpolicy/Vcreated/Vsiptr/Veiptr/vipver/vptrbytes', $h);
        $ver = (int) ($hd['version'] ?? 0);
        $ipVer = (int) ($hd['ipver'] ?? 0);
        $ptrBytes = (int) ($hd['ptrbytes'] ?? 0);
        $fileFam = $ver === 2 ? 4 : ($ver === 3 ? $ipVer : 0);
        if ($fileFam !== $fam) {
            fclose($fp);
            self::$dead[$fam] = true;
            return null;
        }
        // 数据指针宽度：当前实现按 4 字节解析索引项与数据区（现有库都是 4）。
        // 若将来官方把指针扩到 8 字节，索引项布局会整体改变 —— 那时必须**拒绝**而不是
        // 硬着头皮按 4 字节读，否则读到的是错位数据、且返回的是看似正常的错误地域。
        // v2 老结构没有该字段，按 4 处理。
        if ($ver === 3 && $ptrBytes !== 4) {
            fclose($fp);
            self::$dead[$fam] = true;
            return null;
        }
        self::$heads[$fam] = [
            'file_version' => $ver,
            'ip_version'   => $ipVer,
            'ptr_bytes'    => $ptrBytes,
            'byte_num'     => $fam === 4 ? 4 : 16,
            'seg_size'     => $fam === 4 ? 14 : 38,
        ];
        self::$fp[$fam] = $fp;
        return $fp;
    }

    /** "国家|省份|城市|运营商" → 三字段（'0'/'内网IP' 等占位置空） */
    private static function regionToGeo(string $region): array
    {
        $p = explode('|', $region);
        return [
            'country' => self::clean($p[0] ?? ''),
            'province' => self::clean($p[1] ?? ''),
            'city' => self::clean($p[2] ?? ''),
        ];
    }

    private static function clean(string $s): string
    {
        $s = trim($s);
        if ($s === '0' || $s === '' || $s === '内网IP' || $s === '局域网' || strcasecmp($s, 'N/A') === 0) {
            return '';
        }
        return self::cut($s, 64);
    }

    private static function cut(string $s, int $len): string
    {
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) : $s;
    }

    /**
     * 内网 / 保留地址判定（跳过检索）。
     *
     * IPv4 走位运算快判；IPv6 复用 `Util::ipInList()` 的 CIDR 匹配
     * （同一套二进制前缀比较，与 Cloudflare 段判定共用实现，避免另写一份易错的 v6 前缀逻辑）。
     */
    private static function isPrivate(string $ip, int $fam): bool
    {
        if ($fam === 6) {
            return Util::ipInList($ip, self::V6_INTERNAL);
        }
        $n = ip2long($ip);
        if ($n === false) {
            return true;
        }
        $n = (int) sprintf('%u', $n) & 0xFFFFFFFF;
        if (($n >> 24) === 10 || ($n >> 24) === 127) {
            return true;
        }
        if ((($n >> 16) & 0xFFF0) === 0xAC10) {       // 172.16.0.0/12
            return true;
        }
        if (($n >> 16) === 0xC0A8) {                  // 192.168.0.0/16
            return true;
        }
        if (($n >> 16) === 0xA9FE) {                  // 169.254.0.0/16 link-local
            return true;
        }
        return $n === 0 || $n === 0xFFFFFFFF;
    }

    /* ================= http 驱动 ================= */

    private static function httpLookup(string $ip): array
    {
        $empty = self::emptyGeo();
        $url = (string) (wstat_config('collect.geo_http_url') ?? '');
        if ($url === '') {
            return $empty;
        }
        // IPv6 里裸 `:` 出现在 URL 路径段是非法的（会被解析成端口/协议分隔），先编码；
        // 对 IPv4 是恒等变换，不改变既有行为
        $slot = strpos($ip, ':') !== false ? rawurlencode($ip) : $ip;
        $url = str_replace('{ip}', $slot, $url);
        $key = (string) (wstat_config('collect.geo_http_key') ?? '');
        if ($key !== '') {
            if (strpos($url, '{key}') !== false) {
                $url = str_replace('{key}', rawurlencode($key), $url);
            } else {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . rawurlencode($key);
            }
        }

        // Redis 缓存 30 天（worker 常驻进程场景可跨进程命中；失败静默）
        $r = null;
        try {
            $r = Rds::get();
            if ($r !== null) {
                $cached = $r->get('geo:http:' . $ip);
                if (is_string($cached) && $cached !== '') {
                    $j = json_decode($cached, true);
                    if (is_array($j)) {
                        return $j + $empty;
                    }
                }
            }
        } catch (\Throwable $e) {
            $r = null;
        }

        $raw = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 1.2, 'ignore_errors' => true, 'method' => 'GET'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
        $geo = $empty;
        if (is_string($raw)) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $geo = self::extractHttpGeo($json);
            }
        }
        try {
            if ($r !== null) {
                $r->set('geo:http:' . $ip, json_encode($geo, JSON_UNESCAPED_UNICODE), 30 * 86400);
            }
        } catch (\Throwable $e) {
            /* ignore */
        }
        return $geo;
    }

    /** 兼容常见地理 JSON 结构 */
    private static function extractHttpGeo(array $json): array
    {
        $nodes = [];
        if (isset($json['data']) && is_array($json['data'])) {
            $nodes[] = $json['data'];
        }
        if (isset($json['ad_info']) && is_array($json['ad_info'])) {
            $nodes[] = $json['ad_info'];
        }
        $nodes[] = $json;

        foreach ($nodes as $node) {
            $c = (string) ($node['nation'] ?? $node['country'] ?? $node['countryName'] ?? '');
            $p = (string) ($node['province'] ?? $node['regionName'] ?? '');
            $ci = (string) ($node['city'] ?? '');
            if (isset($node['status']) && (string) $node['status'] !== 'success') {
                continue;   // ip-api 失败体
            }
            if ($c !== '' || $p !== '' || $ci !== '') {
                return [
                    'country' => self::clean($c),
                    'province' => self::clean($p),
                    'city' => self::clean($ci),
                ];
            }
        }
        return self::emptyGeo();
    }
}
