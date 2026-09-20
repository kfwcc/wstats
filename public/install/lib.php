<?php
/**
 * WebStats 安装核心库
 *
 * 被 Web 向导（install/index.php）与命令行安装（install/cli.php）共用。
 * 设计要点：
 *  1. 自包含：不依赖主应用 bootstrap，可在「无数据库 / 未安装」状态下运行；
 *     仅在需要时读取 server/config.php 的默认值。
 *  2. 幂等：建表脚本全部 CREATE TABLE IF NOT EXISTS，绝不含 DROP/TRUNCATE，
 *     重复执行或对已有库执行都安全。
 *  3. 无外部依赖：不依赖 Composer，不依赖 phpredis（Redis 测试走内置 RESP 客户端）。
 */
declare(strict_types=1);

namespace WstatInstall;

final class Installer
{
    /**
     * 出厂版本号 —— **全项目唯一手改处**（安装向导页脚直接用它）。
     *
     * 改完必须执行 `php scripts/write-version.php`，把 server/app/version.php 同步成同一个值
     * （运行期页脚署名旁边的版本号与在线更新检查都读那份，见 docs/deploy.md）。
     * 不要期望在 config.php 或 data/installed.php 里写版本号能生效：
     *   · config.php 的版本值由 app/version.php 明文提供（config.php 内不再写死版本号）；
     *   · data/installed.php 的 version 是「安装当刻的快照」，合并配置时被显式忽略 ——
     *     它在手工覆盖代码 / 目录迁移下永不刷新，曾经导致页脚永远 v1.0.0、更新检查永远提示有更新。
     */
    public const VERSION    = '1.0.14';
    public const MIN_PHP    = '7.4.0';
    public const SESSION_KEY = 'wstat_install';

    /** 路径探测缓存（resetPathCache() 可清空） */
    private static ?array $overrideCache = null;
    private static ?string $serverCache = null;
    private static ?string $rootCache = null;
    private static ?string $sqlCache = null;

    /* ===================== 路径与状态 =====================
     * 部署形态差异很大（开发副本 / Web 根内 / 面板自定义目录 / open_basedir 受限），
     * 因此这里所有文件系统探测都遵守两条铁律：
     *   1. 先做 open_basedir 字符串级判定，越界路径一律不碰（否则 PHP 会刷 Warning）；
     *   2. 「后端目录」与「建表脚本位置」分开探测，互不牵连
     *      （sql/ 放在项目根、Web 根、乃至网站同级目录都应能识别）。
     */

    /** 统一路径分隔符与末尾斜杠（保留 Windows 盘符） */
    public static function norm(string $p): string
    {
        $p = str_replace('\\', '/', trim($p));
        if ($p !== '/' && substr($p, -1) === '/') {
            $p = rtrim($p, '/');
        }
        return $p;
    }

    /** open_basedir 允许的前缀列表（空数组 = 未限制） */
    public static function basedirs(): array
    {
        $raw = trim((string) ini_get('open_basedir'));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(PATH_SEPARATOR, $raw) as $p) {
            $p = self::norm($p);
            if ($p !== '') {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * 路径是否允许访问。
     * open_basedir 越界会让 is_dir()/is_file()/realpath() 抛出 Warning 并返回 false，
     * 所以所有探测前必须先过这里，避免部署页面上满屏「open_basedir restriction」。
     */
    public static function ok(string $path): bool
    {
        $dirs = self::basedirs();
        if (!$dirs) {
            return true;
        }
        $p = self::norm($path);
        if (self::inBasedir($p, $dirs)) {
            return true;
        }
        // 路径含 ../ 或软链时再归一一次（越界时用 @ 抑制 Warning）
        $real = @realpath($p);
        return $real !== false && self::inBasedir(self::norm($real), $dirs);
    }

    private static function inBasedir(string $p, array $dirs): bool
    {
        foreach ($dirs as $d) {
            if ($p === $d || strpos($p, $d . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /** open_basedir 安全的目录判定 */
    public static function isDir(string $p): bool
    {
        return self::ok($p) && @is_dir($p);
    }

    /** open_basedir 安全的文件判定 */
    public static function isFile(string $p): bool
    {
        return self::ok($p) && @is_file($p);
    }

    /** open_basedir 安全的文件读取 */
    public static function readFile(string $p): string
    {
        if (!self::isFile($p)) {
            return '';
        }
        $s = @file_get_contents($p);
        return $s === false ? '' : $s;
    }

    /** 安装器自身所在目录 */
    public static function installDir(): string
    {
        return self::norm(__DIR__);
    }

    /** Web 根目录（CLI 或无法判断时为空串） */
    public static function webRoot(): string
    {
        return self::norm((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    }

    /**
     * 安装器目录内的零配置覆盖文件（install/local.php）。
     * 宝塔/Plesk 等面板默认 open_basedir 与自定义目录结构下，自动探测可能失败，
     * 此时可在安装器目录放一个 local.php：「<?php return ['root' => '/www/wwwroot/xxx'];」
     * —— 纯文件方式，无需改动 PHP-FPM / Nginx 配置。
     *
     * @return array{root?:string,server_dir?:string,sql_file?:string}
     */
    public static function localOverrides(): array
    {
        if (self::$overrideCache !== null) {
            return self::$overrideCache;
        }
        $file = self::installDir() . '/local.php';
        if (!self::isFile($file)) {
            return self::$overrideCache = [];
        }
        $v = @include $file;
        return self::$overrideCache = is_array($v) ? $v : [];
    }

    /** 清空路径探测缓存（写入 local.php 后必须调用，否则同一请求内仍是旧结果） */
    public static function resetPathCache(): void
    {
        self::$overrideCache = null;
        self::$serverCache = null;
        self::$rootCache = null;
        self::$sqlCache = null;
    }

    /** 是否存在零配置覆盖文件 */
    public static function hasLocalOverride(): bool
    {
        return self::localOverrides() !== [];
    }

    /**
     * 写入 install/local.php 显式指定项目根（诊断页「手动指路」用）。
     * @return string 空串=成功，否则为错误说明
     */
    public static function saveLocalRoot(string $root): string
    {
        $root = self::norm($root);
        if ($root === '' || $root[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $root)) {
            return '请填写绝对路径，例如 /www/wwwroot/你的站点';
        }
        $cands = [$root, $root . '/server', dirname($root), dirname($root) . '/server'];
        $server = '';
        $blocked = false;
        foreach ($cands as $c) {
            if (!self::ok($c)) {
                $blocked = true;
                continue;
            }
            if (self::isFile($c . '/app/bootstrap.php')) {
                $server = $c;
                break;
            }
        }
        if ($server === '') {
            if ($blocked) {
                $ob = trim((string) ini_get('open_basedir'));
                return '该路径不在 open_basedir 允许范围内，PHP 无法访问。'
                    . '当前 open_basedir = ' . ($ob !== '' ? $ob : '（未限制）')
                    . '。请把站点目录设为该路径或其上级目录后重试。';
            }
            return '该路径下没有找到后端程序：期望 ' . $root . '/app/bootstrap.php 存在'
                . '（v1.0.1+ 统一布局：包根含 app/ 、public/ 、config.php；'
                . '也可填 Web 根 public 的上级目录，或直接填写 app/ 的上级目录本身）';
        }
        $file = self::installDir() . '/local.php';
        $code = "<?php\n"
            . "/**\n"
            . " * 安装器本地覆盖（由安装向导「手动指路」生成，也可手工编辑）\n"
            . " * root       项目根目录（统一布局即包根；≤1.0.0 旧包为含 server/ 的目录）\n"
            . " * server_dir 也可直接指定后端目录本身（含 app/bootstrap.php）\n"
            . " * sql_file   建表脚本绝对路径（可选）\n"
            . " * 删除本文件即恢复自动探测。\n"
            . " */\n"
            . "return [\n"
            . "    'server_dir' => " . var_export($server, true) . ",\n"
            . "];\n";
        if (@file_put_contents($file, $code) === false) {
            return '写入失败：' . $file . '（请给安装器目录写权限，或手工创建该文件）';
        }
        self::resetPathCache();   // 立即按新路径重新探测
        return '';
    }

    /**
     * 候选「项目根目录」（去重，只保留 open_basedir 内可访问的）。
     * 顺序：显式环境变量 → 安装器祖先目录 → Web 根祖先目录。
     * install/ 可能位于 <root>/、<root>/server/public/、<webroot>/ 等多种位置。
     */
    public static function candidates(): array
    {
        $list = [];
        $push = static function ($p) use (&$list): void {
            if (is_string($p) && trim($p) !== '') {
                $list[] = $p;
            }
        };

        // 1) 显式指定（面板 / 自定义目录 / 与站点分离部署时最可靠）
        foreach (['WSTAT_ROOT', 'WSTAT_SERVER_DIR'] as $k) {
            $v = getenv($k);
            if ($v === false || $v === '') {
                $v = $_SERVER[$k] ?? '';
            }
            $push(is_string($v) ? $v : '');
        }
        if (defined('WSTAT_ROOT') && is_string(WSTAT_ROOT)) {
            $push(WSTAT_ROOT);
        }
        // 1.5) 安装器目录的 local.php 覆盖（无需改服务器配置）
        $local = self::localOverrides();
        if (!empty($local['server_dir'])) {
            $push((string) $local['server_dir']);
        }
        if (!empty($local['root'])) {
            $push((string) $local['root']);
            $push(self::norm((string) $local['root']) . '/server');
        }

        // 2) 安装器所在目录及其各级祖先
        $d = self::norm(dirname(__DIR__));
        for ($i = 0; $i < 6; $i++) {
            $push($d);
            $parent = dirname($d);
            if ($parent === $d || $parent === '') {
                break;
            }
            $d = $parent;
        }

        // 3) Web 根及其各级祖先
        $doc = self::webRoot();
        if ($doc !== '') {
            $d = $doc;
            for ($i = 0; $i < 4; $i++) {
                $push($d);
                $parent = dirname($d);
                if ($parent === $d || $parent === '') {
                    break;
                }
                $d = $parent;
            }
        }

        $out = [];
        foreach ($list as $p) {
            $p = self::norm($p);
            if ($p !== '' && self::ok($p)) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * 后端目录（含 app/bootstrap.php 的 server/）。
     * 与建表脚本位置解耦：sql/ 缺失或挪位不影响后端定位。
     */
    public static function serverDir(): string
    {
        if (self::$serverCache !== null) {
            return self::$serverCache;
        }
        foreach (self::candidates() as $c) {
            if (self::isFile($c . '/app/bootstrap.php')) {
                return self::$serverCache = $c;                     // $c 本身即 server/
            }
            if (self::isFile($c . '/server/app/bootstrap.php')) {
                return self::$serverCache = $c . '/server';
            }
        }
        // 弱匹配：只要求目录结构（bootstrap.php 被裁剪时仍可用）
        foreach (self::candidates() as $c) {
            if (self::isDir($c . '/app/Support')) {
                return self::$serverCache = $c;
            }
            if (self::isDir($c . '/server/app')) {
                return self::$serverCache = $c . '/server';
            }
        }
        throw new \RuntimeException('未找到后端程序目录（应包含 app/bootstrap.php，统一布局即包根）');
    }

    /** 项目根目录（不一定含 sql/，SQL 位置单独探测）。
     *  统一布局（v1.0.1+，发布包默认）：包根 = app/ + config.php + data/ + scripts/ + public/ 所在目录，
     *    即后端目录本身就是项目根（Web 根是它的 public/ 子目录）→ 根不能再上移一层；
     *  旧嵌套布局（≤v1.0.0）：后端目录名为 server/（Web 根 = server/public）→ 项目根取父目录；
     *  旧扁平布局（宝塔包，无 server 层、无 public/ 子目录）→ 后端目录本身就是根。
     */
    public static function root(): string
    {
        if (self::$rootCache === null) {
            $srv = self::norm(self::serverDir());
            // 统一布局的特征：同一层同时有 config.php 与 public/，且目录名不是 server
            $unified = basename($srv) !== 'server'
                && self::isFile($srv . '/config.php')
                && self::isDir($srv . '/public');
            // 旧嵌套布局的特征：目录名是 server，或「上层还有一层」且下层是 public/（无 config.php 同层）
            $nested = !$unified && (basename($srv) === 'server' || self::isDir($srv . '/public'));
            self::$rootCache = $nested ? self::norm(dirname($srv)) : $srv;
        }
        return self::$rootCache;
    }

    public static function dataDir(): string
    {
        return self::serverDir() . '/data';
    }

    public static function configFile(): string
    {
        return self::dataDir() . '/installed.php';
    }

    public static function lockFile(): string
    {
        return self::dataDir() . '/install.lock';
    }

    /**
     * 部署布局报告（不抛异常，供诊断页与自检展示）。
     * @return array<int,array{name:string,value:string,hint:string,required:bool}>
     */
    public static function layout(): array
    {
        $rows = [];
        $ob = trim((string) ini_get('open_basedir'));
        $rows[] = [
            'name' => 'open_basedir', 'value' => $ob !== '' ? $ob : '（未限制）',
            'hint' => 'PHP 只允许访问这些目录；项目文件必须位于其中，否则无法读取/写入',
            'required' => false,
        ];
        $doc = self::webRoot();
        $rows[] = [
            'name' => 'Web 根目录', 'value' => $doc !== '' ? $doc : '（CLI 场景未知）',
            'hint' => '站点 Nginx/Apache 的 root 指向的目录（DOCUMENT_ROOT）',
            'required' => false,
        ];
        $rows[] = [
            'name' => 'local.php 覆盖', 'value' => self::hasLocalOverride() ? self::installDir() . '/local.php' : '未使用（自动探测）',
            'hint' => '自动探测失败时可在安装器目录写 local.php 指定 server_dir / sql_file',
            'required' => false,
        ];
        $rows[] = [
            'name' => '安装器目录', 'value' => self::installDir(),
            'hint' => 'install/ 所在位置，可为 <项目根>/install 或 <Web根>/install',
            'required' => false,
        ];
        try {
            $rows[] = ['name' => '项目根目录', 'value' => self::root(), 'hint' => '含 app/ 、config.php 、public/ 的目录（v1.0.1+ 统一布局：项目根=后端目录；旧包为 server/ 的父目录）', 'required' => false];
        } catch (\Throwable $e) {
            $rows[] = ['name' => '项目根目录', 'value' => '未定位', 'hint' => '需保证后端目录（含 app/bootstrap.php）已上传', 'required' => true];
        }
        try {
            $rows[] = ['name' => '后端目录', 'value' => self::serverDir(), 'hint' => '必须包含 app/bootstrap.php（统一布局即项目根；旧包为 server/）', 'required' => true];
        } catch (\Throwable $e) {
            $rows[] = ['name' => '后端目录', 'value' => '未定位', 'hint' => '请上传完整发布包（后端程序、sql/ 与 install/）', 'required' => true];
        }
        try {
            $rows[] = ['name' => '建表脚本', 'value' => self::sqlFile(), 'hint' => '全量建表 SQL', 'required' => true];
        } catch (\Throwable $e) {
            $rows[] = ['name' => '建表脚本', 'value' => '未找到', 'hint' => '可放在 项目根/sql、Web根/sql、网站同级/sql 或 install/sql', 'required' => true];
        }
        try {
            $dataDir = self::dataDir();
            $rows[] = [
                'name' => '数据目录', 'value' => $dataDir,
                'hint' => '安装后生成 installed.php / install.lock，需可写',
                'required' => true,
            ];
        } catch (\Throwable $e) {
            /* 后端目录未定位时无需重复报错 */
        }
        return $rows;
    }

    /**
     * 候选路径逐条探测（诊断页用）：标出是否越界、里面有什么。
     * @return array<int,array{0:string,1:string}>
     */
    public static function probeReport(int $limit = 10): array
    {
        $seen = [];
        foreach (self::candidates() as $c) {
            $seen[$c] = true;
        }
        // 被 open_basedir 拦掉的祖先也要列出，便于看出「文件放高了/放低了」
        $d = self::norm(dirname(__DIR__));
        for ($i = 0; $i < 6; $i++) {
            $seen[$d] = true;
            $parent = dirname($d);
            if ($parent === $d || $parent === '') {
                break;
            }
            $d = $parent;
        }
        $rows = [];
        foreach (array_slice(array_keys($seen), 0, $limit) as $c) {
            if (!self::ok($c)) {
                $rows[] = [$c, '越界：open_basedir 不允许访问（已跳过）'];
                continue;
            }
            $marks = [];
            if (self::isFile($c . '/app/bootstrap.php')) {
                $marks[] = self::isFile($c . '/index.php') || self::isFile($c . '/collect.php')
                    ? 'app/bootstrap.php + 入口文件（扁平布局：Web 根=后端目录）'
                    : 'app/bootstrap.php（即 server/ 本身）';
            }
            if (self::isFile($c . '/server/app/bootstrap.php')) {
                $marks[] = 'server/app/bootstrap.php';
            } elseif (self::isDir($c . '/server')) {
                $marks[] = 'server/（缺 app/bootstrap.php）';
            }
            foreach (['/sql/install.sql', '/install.sql'] as $rel) {
                if (self::isFile($c . $rel)) {
                    $marks[] = ltrim($rel, '/');
                }
            }
            $rows[] = [$c, $marks ? ('含 ' . implode('、', array_unique($marks))) : '无相关目录'];
        }
        return $rows;
    }

    /**
     * 是否已完成安装。
     * 判定：安装锁存在，或（installed.php 存在且数据库连接信息齐全）
     * —— 后者兼容「手工部署 / 环境变量注入」等未经向导的部署，避免被误判为未安装而暴露安装页。
     */
    public static function installed(): bool
    {
        try {
            if (self::isFile(self::lockFile())) {
                return true;
            }
            if (!self::isFile(self::configFile())) {
                return false;
            }
            $cfg = self::config();
        } catch (\Throwable $e) {
            return false;   // 目录结构异常时按「未安装」处理，由向导给出诊断
        }
        return ($cfg['db']['name'] ?? '') !== '' && ($cfg['db']['user'] ?? '') !== '';
    }

    /** 读取 server/config.php 的有效配置（未安装时即出厂默认值） */
    public static function config(): array
    {
        $server = self::serverDir();
        if (!defined('WSTAT_ROOT')) {
            define('WSTAT_ROOT', $server);
        }
        $file = $server . '/config.php';
        if (!self::isFile($file)) {
            return [];
        }
        $cfg = require $file;
        return is_array($cfg) ? $cfg : [];
    }

    /* ===================== 环境检测 ===================== */

    /**
     * @return array<int,array{group:string,name:string,current:string,ok:bool,required:bool,hint:string}>
     */
    public static function envCheck(): array
    {
        $rows = [];

        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $rows[] = [
            'group' => '运行环境', 'name' => 'PHP 版本', 'current' => PHP_VERSION,
            'ok' => $phpOk, 'required' => true,
            'hint' => '需 >= ' . self::MIN_PHP . '（推荐 8.0+，本项目无 Composer 依赖）',
        ];
        $sapi = PHP_SAPI;
        $rows[] = [
            'group' => '运行环境', 'name' => '运行方式', 'current' => $sapi,
            'ok' => true, 'required' => false,
            'hint' => 'cli=命令行安装；fpm/fpm-fcgi/apache2handler=Web 安装',
        ];

        // 必需扩展
        $need = [
            'pdo'      => 'PDO 基础扩展（数据库访问）',
            'pdo_mysql'=> 'MySQL 驱动（Db 类使用 PDO::MYSQL_ATTR_INIT_COMMAND）',
            'json'     => 'JSON 编解码（采集载荷、漏斗步骤）',
            'mbstring' => '多字节字符串（昵称/标题长度校验）',
        ];
        foreach ($need as $ext => $why) {
            $rows[] = [
                'group' => 'PHP 扩展', 'name' => $ext,
                'current' => extension_loaded($ext) ? '已安装' : '未安装',
                'ok' => extension_loaded($ext), 'required' => true, 'hint' => $why,
            ];
        }
        // 可选扩展
        $opt = [
            'openssl' => 'HTTPS 站点验证（file_get_contents https）',
            'curl'    => 'HTTP 型 IP 地理接口 / 可选加速',
            'gd'      => '图形验证码（注册邮箱验证码前置人机校验，缺失时该功能不可用）',
            'zlib'    => 'gzip 压缩响应',
            'zip'     => '发布包打包脚本（build-release.php）',
        ];
        foreach ($opt as $ext => $why) {
            $rows[] = [
                'group' => 'PHP 扩展（可选）', 'name' => $ext,
                'current' => extension_loaded($ext) ? '已安装' : '未安装',
                'ok' => true, 'required' => false, 'hint' => $why,
            ];
        }

        // 部署布局：目录实际落位（部署报错时这一组最能说明问题）
        foreach (self::layout() as $r) {
            $v = (string) $r['value'];
            $missing = strpos($v, '未定位') !== false || strpos($v, '未找到') !== false;
            $rows[] = [
                'group' => '部署布局', 'name' => $r['name'], 'current' => $v,
                'ok' => !$missing, 'required' => (bool) $r['required'], 'hint' => $r['hint'],
            ];
        }

        // 后端程序落在 Web 根内 → 需在 Web 服务器层屏蔽内部目录直访（防 installed.php 泄露）。
        // 统一布局（v1.0.1+）下 public/ 才是 Web 根，后端在其外部，因此这里不会命中。
        try {
            $web = self::webRoot();
            $srv = self::serverDir();
            $flat = self::norm($srv) === self::norm($web);
            // 统一布局里 srv 是 web 的上一级 → 不算「位于 Web 根内」
            $inside = !$flat && $web !== '' && $srv !== ''
                && strpos(self::norm($srv) . '/', self::norm($web) . '/') === 0;
            if ($flat || $inside) {
                $rows[] = [
                    'group' => '安全提示',
                    'name' => $flat ? '后端目录=Web 根（旧扁平布局）' : '后端目录位于 Web 根内',
                    'current' => '需屏蔽直访',
                    'ok' => true, 'required' => false,
                    'hint' => $flat
                        ? '旧宝塔扁平包（≤1.0.0）务必屏蔽内部目录（Nginx: location ^~ /app/ { deny all; } 对 /app/ /scripts/ /data/ 生效；data/ 内含数据库凭据 installed.php）'
                        : '建议把站点根目录指向 <包根>/public（统一布局），或至少禁止访问后端目录',
                ];
            }
        } catch (\Throwable $e) {
            /* 目录未定位时该提示无意义 */
        }

        // 目录写权限
        $dirs = [];
        try {
            $srv = self::serverDir();
            $dirs[self::dataDir()] = '安装配置与离线 IP 库所在目录';
            $dirs[$srv]            = '写入 config 相关文件';
            if (self::isDir($srv . '/public')) {
                $dirs[$srv . '/public'] = '前端构建产物与 SDK 对外提供';
            } else {
                $web = self::webRoot();
                if ($web !== '' && self::isDir($web)) {
                    $dirs[$web] = '前端构建产物与 SDK 对外提供（Web 根）';
                }
            }
        } catch (\Throwable $e) {
            /* 后端目录未定位：layout 组已给出「后端目录未定位」红项 */
        }
        foreach ($dirs as $dir => $why) {
            $exists = self::isDir($dir);
            $writable = $exists && @is_writable($dir);
            $rows[] = [
                'group' => '目录权限', 'name' => self::short($dir), 'current' => $exists ? ($writable ? '可写' : '不可写') : '不存在',
                'ok' => $writable, 'required' => true, 'hint' => $why . '（Linux 建议 chown -R www:www 或 chmod 755）',
            ];
        }

        // 函数可用性
        $funcs = ['random_bytes' => '生成站点 key / 验证令牌'];
        foreach ($funcs as $fn => $why) {
            $ok = function_exists($fn);
            $rows[] = ['group' => '必需函数', 'name' => $fn . '()', 'current' => $ok ? '可用' : '被禁用', 'ok' => $ok, 'required' => true, 'hint' => $why];
        }

        // 离线 IP 库（IPv4 / IPv6 两份，互相独立：缺一份只影响对应地址族的访客）
        $libs = [
            ['ip2region.xdb',    'IPv4 离线 IP 地理库'],
            ['ip2region_v6.xdb', 'IPv6 离线 IP 地理库（缺失时 IPv6 访客无地域，IPv4 不受影响）'],
        ];
        foreach ($libs as [$libFile, $libHint]) {
            try {
                $xdb = self::serverDir() . '/data/' . $libFile;
                $cur = self::isFile($xdb) ? ('已就绪 ' . round((int) @filesize($xdb) / 1048576, 1) . 'MB') : '缺失';
            } catch (\Throwable $e) {
                $cur = '未知';
            }
            $rows[] = [
                'group' => '可选组件', 'name' => $libFile,
                'current' => $cur, 'ok' => true, 'required' => false,
                'hint' => $libHint . '；缺失时把配置 collect.geo_driver 设为 none 或跑 scripts/fetch-geo.php 下载',
            ];
        }

        return $rows;
    }

    public static function envPass(array $rows): bool
    {
        foreach ($rows as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    private static function short(string $path): string
    {
        try {
            $root = self::root();
        } catch (\Throwable $e) {
            return self::norm($path);
        }
        return str_replace($root, '', self::norm($path)) ?: $path;
    }

    /* ===================== SQL ===================== */

    /**
     * 建表脚本候选位置（按优先级）。
     * 支持 sql/ 与项目根同级、与 Web 根同级、与「网站目录」同级，以及随安装器分发的副本。
     */
    public static function sqlCandidates(): array
    {
        $cands = [];
        $env = getenv('WSTAT_SQL_FILE');
        if ($env === false || $env === '') {
            $env = $_SERVER['WSTAT_SQL_FILE'] ?? '';
        }
        if (is_string($env) && trim($env) !== '') {
            $cands[] = self::norm($env);
        }
        $local = self::localOverrides();
        if (!empty($local['sql_file'])) {
            $cands[] = self::norm((string) $local['sql_file']);
        }

        $dirs = [];
        $addDir = static function ($d) use (&$dirs): void {
            $d = is_string($d) ? trim($d) : '';
            if ($d !== '' && $d !== '.' && $d !== '/') {
                $dirs[] = $d;
            }
        };
        try {
            $root = self::root();
            $addDir($root);
            $addDir(self::norm(dirname($root)));      // sql 与「网站/项目」同级（上一级）
        } catch (\Throwable $e) {
            /* 后端目录未定位时仍列出其余候选，便于自查 */
        }
        try {
            $addDir(self::serverDir());
        } catch (\Throwable $e) {
        }
        $addDir(self::installDir());
        $addDir(self::norm(dirname(self::installDir())));
        $web = self::webRoot();
        if ($web !== '') {
            $addDir($web);
            $addDir(self::norm(dirname($web)));
        }

        foreach (array_values(array_unique($dirs)) as $d) {
            if (!self::ok($d)) {
                continue;
            }
            $cands[] = $d . '/sql/install.sql';
            $cands[] = $d . '/install.sql';
        }
        return array_values(array_unique($cands));
    }

    /** 定位建表脚本；找不到时错误信息里列出全部候选位置 */
    public static function sqlFile(): string
    {
        if (self::$sqlCache !== null) {
            return self::$sqlCache;
        }
        foreach (self::sqlCandidates() as $p) {
            if (self::isFile($p)) {
                return self::$sqlCache = $p;
            }
        }
        throw new \RuntimeException(
            "未找到建表脚本 sql/install.sql。已尝试以下位置：\n  " . implode("\n  ", self::sqlCandidates())
            . "\n可把 sql/ 放在项目根、Web 根或网站同级目录，或用环境变量 WSTAT_SQL_FILE 指定绝对路径。"
        );
    }

    /** events 分区定义：上界从「当月-back」到「当月+forward」的每月 1 号 */
    public static function partitions(int $back = 1, int $forward = 5): string
    {
        $base = new \DateTimeImmutable('first day of this month');
        $d = $base->modify('-' . $back . ' months');
        $end = $base->modify('+' . $forward . ' months');
        $lines = [];
        while ($d <= $end) {
            $lines[] = sprintf('  PARTITION p%s VALUES LESS THAN (TO_DAYS(\'%s\'))', $d->format('Ym'), $d->format('Y-m-01'));
            $d = $d->modify('+1 month');
        }
        return implode(",\n", $lines);
    }

    /**
     * 读取 install.sql 并把分区占位标记展开为真实分区定义。
     * 标记使用 __PARTITIONS__（不使用花括号，避免与注释文本冲突）。
     */
    public static function buildSql(): string
    {
        $sql = self::readFile(self::sqlFile());
        $marker = '__PARTITIONS__';
        $pos = strpos($sql, $marker);
        if ($pos === false) {
            throw new \RuntimeException('sql/install.sql 缺少 ' . $marker . ' 分区占位标记，脚本可能已损坏');
        }
        return substr($sql, 0, $pos) . self::partitions() . substr($sql, $pos + strlen($marker));
    }

    /**
     * 拆分 SQL 语句：跳过 -- 行注释，尊重引号内的分号。
     * @return string[]
     */
    public static function splitSql(string $sql): array
    {
        $stmts = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        $strCh = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if (!$inStr && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($inStr) {
                if ($ch === '\\') {
                    $buf .= $ch . ($sql[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($ch === $strCh) {
                    $inStr = false;
                }
                $buf .= $ch;
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inStr = true;
                $strCh = $ch;
                $buf .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($buf) !== '') {
                    $stmts[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $stmts[] = trim($buf);
        }
        return $stmts;
    }

    /* ===================== 数据库 ===================== */

    /** 建立 PDO 连接；$withDb=false 时不指定库名（用于建库/测连接） */
    public static function pdo(array $db, bool $withDb = true): \PDO
    {
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $name = (string) ($db['name'] ?? '');
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($withDb && $name !== '') {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }
        return new \PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 8,
        ]);
    }

    /** 服务器信息与库是否存在 */
    public static function inspectServer(array $db): array
    {
        $pdo = self::pdo($db, false);
        $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $name = (string) ($db['name'] ?? '');
        $exists = false;
        if ($name !== '') {
            $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $st->execute([$name]);
            $exists = (bool) $st->fetchColumn();
        }
        return ['version' => $ver, 'db_exists' => $exists, 'db_name' => $name];
    }

    public static function createDatabase(array $db): void
    {
        $name = (string) ($db['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('数据库名只能包含字母、数字、下划线，长度 1-64');
        }
        $pdo = self::pdo($db, false);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /**
     * 执行全量建表脚本。
     * @return array{executed:int,tables:array<string,bool>,partition_count:int}
     */
    public static function runSchema(array $db): array
    {
        $pdo = self::pdo($db, true);
        $stmts = self::splitSql(self::buildSql());
        $executed = 0;
        foreach ($stmts as $s) {
            $pdo->exec($s);
            $executed++;
        }
        return [
            'executed' => $executed,
            'tables'   => self::tableState($pdo),
            'partition_count' => self::partitionCount($pdo),
        ];
    }

    /** 已存在的目标表 */
    public static function tableState(\PDO $pdo): array
    {
        $want = ['users', 'sites', 'auth_tokens', 'events', 'sessions', 'site_daily', 'funnels'];
        $out = [];
        $st = $pdo->query('SHOW TABLES');
        $have = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as $r) {
            $have[(string) $r[0]] = true;
        }
        foreach ($want as $t) {
            $out[$t] = isset($have[$t]);
        }
        return $out;
    }

    public static function partitionCount(\PDO $pdo): int
    {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='events' AND PARTITION_NAME IS NOT NULL");
        return (int) $st->fetchColumn();
    }

    /**
     * 创建系统管理员账号。
     *
     * is_admin=1 必须**显式写入**：users.is_admin 的列默认值是 0（普通用户），
     * 早期版本这里漏了它 → 全新安装后创建的第一个账号不是管理员，进不了「系统设置」
     * （Settings::isAdmin 只在「列不存在」时才回退 MIN(id)；列存在但值不为 1 就是普通用户）。
     * 邮箱已存在时同理补一次提权，保证「安装向导产出的那个账号一定是管理员」。
     */
    public static function createAdmin(array $db, string $email, string $pass, string $nickname, string $lang = 'zh-CN'): array
    {
        $pdo = self::pdo($db, true);
        $hasAdminCol = self::hasColumn($pdo, 'users', 'is_admin');
        $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $exist = $st->fetchColumn();
        if ($exist) {
            if ($hasAdminCol) {
                $pdo->prepare('UPDATE users SET is_admin=1, updated_at=? WHERE id=?')->execute([time(), (int) $exist]);
                return ['created' => false, 'user_id' => (int) $exist, 'reason' => '该邮箱已存在，已确保其为系统管理员'];
            }
            return ['created' => false, 'user_id' => (int) $exist, 'reason' => '该邮箱已存在，未重复创建'];
        }
        $now = time();
        if ($hasAdminCol) {
            $ins = $pdo->prepare('INSERT INTO users (email,password,nickname,lang,status,is_admin,created_at,updated_at) VALUES (?,?,?,?,1,1,?,?)');
        } else {
            // 老库（users 表无 is_admin 列）：Settings::isAdmin 回退「MIN(id) 即管理员」，
            // 首个账号天然满足，无需也无法写入该列。
            $ins = $pdo->prepare('INSERT INTO users (email,password,nickname,lang,status,created_at,updated_at) VALUES (?,?,?,?,1,?,?)');
        }
        $ins->execute([
            $email,
            password_hash($pass, PASSWORD_DEFAULT),
            $nickname !== '' ? $nickname : explode('@', $email)[0],
            in_array($lang, ['zh-CN', 'en-US'], true) ? $lang : 'zh-CN',
            $now,
            $now,
        ]);
        return ['created' => true, 'user_id' => (int) $pdo->lastInsertId(), 'reason' => 'ok'];
    }

    /** 表里是否有某列（老库缺列时用于降级，避免 SQL 直接报错） */
    public static function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $st->execute([$table, $column]);
            return (int) $st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ===================== Redis ===================== */

    /**
     * 用项目自带 RESP 客户端测试 Redis（无需 phpredis）。
     * @return array{ok:bool,msg:string,version:string,latency_ms:float}
     */
    public static function testRedis(array $cfg): array
    {
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 6379);
        $auth = (string) ($cfg['auth'] ?? '');
        $dbIdx = (int) ($cfg['db'] ?? 0);
        $timeout = 2.0;

        $t0 = microtime(true);
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'msg' => "无法连接 {$host}:{$port}（{$errstr}）", 'version' => '', 'latency_ms' => 0];
        }
        stream_set_timeout($fp, (int) ceil($timeout));

        $cmd = function (array $args) use ($fp) {
            $out = '*' . count($args) . "\r\n";
            foreach ($args as $a) {
                $out .= '$' . strlen((string) $a) . "\r\n" . $a . "\r\n";
            }
            fwrite($fp, $out);
            return self::readReply($fp);
        };

        try {
            if ($auth !== '') {
                $r = $cmd(['AUTH', $auth]);
                if (is_string($r) && stripos($r, 'ERR') === 0) {
                    fclose($fp);
                    return ['ok' => false, 'msg' => '认证失败：' . $r, 'version' => '', 'latency_ms' => 0];
                }
            }
            if ($dbIdx > 0) {
                $cmd(['SELECT', (string) $dbIdx]);
            }
            $pong = $cmd(['PING']);
            $info = $cmd(['INFO', 'server']);
            $ver = '';
            if (is_string($info) && preg_match('/redis_version:([0-9.]+)/', $info, $m)) {
                $ver = $m[1];
            }
            fclose($fp);
            if (!is_string($pong) || stripos($pong, 'PONG') === false) {
                return ['ok' => false, 'msg' => 'PING 未返回 PONG：' . var_export($pong, true), 'version' => $ver, 'latency_ms' => 0];
            }
            return [
                'ok' => true,
                'msg' => "连接成功（DB{$dbIdx}）",
                'version' => $ver,
                'latency_ms' => round((microtime(true) - $t0) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            @fclose($fp);
            return ['ok' => false, 'msg' => 'Redis 通信异常：' . $e->getMessage(), 'version' => '', 'latency_ms' => 0];
        }
    }

    /** 极简 RESP 应答解析（仅安装自检所需类型） */
    private static function readReply($fp)
    {
        $line = fgets($fp);
        if ($line === false) {
            return null;
        }
        $type = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");
        switch ($type) {
            case '+':
            case ':':
                return $body;
            case '-':
                return 'ERR ' . $body;
            case '$':
                $len = (int) $body;
                if ($len < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $len + 2) {
                    $chunk = fread($fp, $len + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*':
                $n = (int) $body;
                $items = [];
                for ($i = 0; $i < $n; $i++) {
                    $items[] = self::readReply($fp);
                }
                return $items;
            default:
                return $body;
        }
    }

    /* ===================== 写配置 / 加锁 ===================== */

    public static function writable(string $file): bool
    {
        $dir = dirname($file);
        return self::isDir($dir) && @is_writable($dir);
    }

    /**
     * 写入 data/installed.php
     * @param array $db    db 配置
     * @param array $redis redis 配置
     * @param array $extra 额外覆盖项（debug/timezone/security.allow_register...）
     */
    public static function writeConfig(array $db, array $redis, array $extra = []): string
    {
        $file = self::configFile();
        if (!self::isDir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) {
            throw new \RuntimeException('无法创建目录 ' . dirname($file));
        }
        $payload = array_merge([
            'installed_at' => time(),
            // ⚠️ 这是「安装当时的版本快照」（语义：本库是哪一版装的），
            // **不参与版本判定** —— config.php 合并配置时会显式忽略这个键，
            // 生效版本一律取自 app/version.php（明文版本号）。
            // 原因：手工覆盖代码 / 目录迁移都不会刷新本文件，若拿它当版本来源，
            // 页脚会永远显示旧版本、更新检查会永远提示有新版本。
            'version'      => self::VERSION,
            'debug'        => false,
            'timezone'     => $extra['timezone'] ?? 'Asia/Shanghai',
            'db' => [
                'host' => (string) $db['host'],
                'port' => (int) $db['port'],
                'name' => (string) $db['name'],
                'user' => (string) $db['user'],
                'pass' => (string) $db['pass'],
            ],
            'redis' => [
                'enabled' => (bool) ($redis['required'] ?? true),   // 跳过 Redis = 无 Redis 模式（数据直写数据库）
                'host' => (string) $redis['host'],
                'port' => (int) $redis['port'],
                'auth' => (string) ($redis['auth'] ?? ''),
                'db'   => (int) ($redis['db'] ?? 0),
                'required' => (bool) ($redis['required'] ?? false),
            ],
        ], $extra);

        $code = "<?php\n"
            . "/**\n"
            . " * 部署配置（由安装向导生成于 " . date('Y-m-d H:i:s') . "）\n"
            . " * 安全提示：本文件含敏感凭据，请勿提交到代码仓库、勿放入可公开下载的发布包。\n"
            . " * 环境变量 WSTAT_DB_* / WSTAT_REDIS_* 可覆盖此处配置。\n"
            . " */\n"
            . "return " . var_export($payload, true) . ";\n";

        if (@file_put_contents($file, $code) === false) {
            throw new \RuntimeException('写入失败：' . $file . '（请检查目录写权限）');
        }
        @chmod($file, 0640);
        return $file;
    }

    /** 写安装锁 */
    public static function lock(): void
    {
        $info = [
            'installed_at' => date('Y-m-d H:i:s'),
            'version'      => self::VERSION,
            'php'          => PHP_VERSION,
        ];
        if (@file_put_contents(self::lockFile(), json_encode($info, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            throw new \RuntimeException('无法写入安装锁 ' . self::lockFile());
        }
    }

    /** 解除安装锁（用于重装/迁移） */
    public static function unlock(): void
    {
        $file = self::lockFile();
        if (self::isFile($file)) {
            @unlink($file);
        }
    }

    /* ===================== 杂项 ===================== */

    /** 生成站点 key（16 位大写字母数字，与 sites.site_key VARCHAR(16) 匹配） */
    public static function siteKey(int $len = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /** 生成验证令牌（32 位十六进制） */
    public static function verifyToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 该 PHP 是否运行在 HTTPS 下（用于生成引导链接） */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** 推断站点根 URL（安装器自身所在地址的上层） */
    public static function baseUrl(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'http://127.0.0.1';
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $scheme = self::isHttps() ? 'https' : 'http';
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $dir = preg_replace('#/install$#', '', $dir);
        return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
    }

    /** 安装完成后的待办清单（页面与 CLI 共用） */
    public static function checklist(string $baseUrl, string $phpBin = 'php'): array
    {
        $root = str_replace('\\', '/', self::root());
        $srv  = str_replace('\\', '/', self::serverDir());
        // 脚本目录相对项目根：统一布局（v1.0.1+，后端目录=项目根）是 scripts/；旧嵌套布局是 server/scripts/
        $rel  = self::norm($srv) === self::norm($root) ? 'scripts' : 'server/scripts';
        $log  = self::norm($srv) === self::norm($root) ? 'data/worker.log' : 'server/worker.log';
        return [
            '删除或改名 install 目录（防止他人重装）' => 'rm -rf ' . str_replace('\\', '/', self::installDir()),
            '常驻消费进程（必须，负责会话化与明细落库）' => "cd {$root} && nohup {$phpBin} {$rel}/worker.php >> {$log} 2>&1 &",
            '每 1 分钟：回收空闲会话' => "{$phpBin} {$root}/{$rel}/cron.php session",
            '每小时：生成每日汇总'    => "{$phpBin} {$root}/{$rel}/cron.php rollup",
            '每天：预建未来分区'      => "{$phpBin} {$root}/{$rel}/cron.php partition",
            '每天：清理过期明细'      => "{$phpBin} {$root}/{$rel}/cron.php clean",
            '自检：数据链路一键检查'  => "{$phpBin} {$root}/{$rel}/doctor.php",
            '管理端入口'              => $baseUrl . '/',
            '采集接口（SDK 上报地址）'=> $baseUrl . '/collect.php',
            'SDK 文件（对外提供）'    => $baseUrl . '/sdk/wstat.js',
        ];
    }

    /** 站点接入代码片段 */
    public static function sdkSnippet(string $baseUrl, string $siteKey = 'YOUR_SITE_KEY'): string
    {
        return '<script async src="' . $baseUrl . '/sdk/wstat.js" data-site-key="' . $siteKey . '" data-host="' . $baseUrl . '"></script>';
    }
}
