<?php
/**
 * WebStats 命令行安装（宝塔 / SSH / 自动化部署场景）
 *
 * 用法：
 *   交互式：  php install/cli.php
 *   一键式：  php install/cli.php --db-name=webstats --db-user=root --db-pass=xxx \
 *                --admin-email=admin@example.com --admin-pass=Admin@12345 \
 *                [--db-host=127.0.0.1] [--db-port=3306] [--create-db] \
 *                [--redis-host=127.0.0.1] [--redis-port=6379] [--redis-auth=] [--redis-db=0] [--skip-redis] \
 *                [--debug] [--allow-register=0]
 *   其它：    php install/cli.php --check          仅环境自检
 *             php install/cli.php --status         查看安装状态（含目录布局探测）
 *             php install/cli.php --unlock         解除安装锁（重装/迁移用）
 *   目录覆盖：加 --root=/www/wwwroot/你的站点 指定项目根目录
 *             （等价于在安装器目录写 local.php；面板环境 open_basedir 受限时很常用）
 *             加 --sql=/path/to/install.sql 指定建表脚本位置
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

use WstatInstall\Installer as I;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("本文件仅限命令行执行。\n");
}

/* ---------- 参数解析 ---------- */
$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m)) {
        $opt[strtolower($m[1])] = $m[2] ?? '1';
    }
}
$has = static function (string $k) use ($opt): bool {
    return array_key_exists($k, $opt);
};

$out = static function (string $s = ''): void {
    fwrite(STDOUT, $s . "\n");
};
$err = static function (string $s): void {
    fwrite(STDERR, $s . "\n");
};

/* ---------- 目录结构覆盖（面板 / 自定义目录时用） ---------- */
if ($has('root') && $opt['root'] !== '1') {
    putenv('WSTAT_ROOT=' . $opt['root']);
    $_SERVER['WSTAT_ROOT'] = $opt['root'];
}
if ($has('sql') && $opt['sql'] !== '1') {
    putenv('WSTAT_SQL_FILE=' . $opt['sql']);
    $_SERVER['WSTAT_SQL_FILE'] = $opt['sql'];
}

/* ---------- 环境自检 ---------- */
try {
    $rows = I::envCheck();
} catch (\Throwable $ex) {
    $err('✗ ' . $ex->getMessage());
    $err('');
    $err('部署布局探测结果：');
    foreach (I::probeReport() as $r) {
        $err('  ' . $r[0] . "\n      → " . $r[1]);
    }
    $err('');
    $err('可用 --root=/www/wwwroot/你的站点 显式指定项目根，或 --sql=/path/to/install.sql 指定建表脚本。');
    exit(1);
}
if ($has('check')) {
    $out('WebStats 环境自检');
    $out(str_repeat('-', 68));
    $group = '';
    foreach ($rows as $r) {
        if ($r['group'] !== $group) {
            $group = $r['group'];
            $out("\n[" . $group . ']');
        }
        $flag = $r['ok'] ? '✓' : ($r['required'] ? '✗' : '!');
        $out(sprintf('  %s %-22s %-18s %s', $flag, $r['name'], $r['current'], $r['hint']));
    }
    $out('');
    $out(I::envPass($rows) ? '结论：必需项全部通过，可执行安装。' : '结论：存在未满足的必需项，请先处理后重试。');
    exit(I::envPass($rows) ? 0 : 1);
}

/* ---------- 安装状态 / 解锁 ---------- */
if ($has('status')) {
    $lock = json_decode(I::readFile(I::lockFile()), true) ?: [];
    $cfg = I::config();
    $out('安装状态：' . (I::installed() ? '已安装' : '未安装'));
    $out('安装时间：' . ($lock['installed_at'] ?? '-'));
    $out('版本    ：' . ($lock['version'] ?? I::VERSION));
    $out('数据库  ：' . ($cfg['db']['name'] ?? '-') . ' @ ' . ($cfg['db']['host'] ?? '-') . ':' . ($cfg['db']['port'] ?? '-'));
    $out('Redis   ：' . ($cfg['redis']['host'] ?? '-') . ':' . ($cfg['redis']['port'] ?? '-') . ' / DB' . ($cfg['redis']['db'] ?? '-'));
    $out('配置文件：' . I::configFile() . (I::isFile(I::configFile()) ? '（存在）' : '（缺失）'));
    $out('锁文件  ：' . I::lockFile() . (I::isFile(I::lockFile()) ? '（存在）' : '（缺失）'));
    $out('');
    $out('部署布局：');
    foreach (I::layout() as $r) {
        $out('  ' . $r['name'] . '：' . $r['value']);
    }
    exit(0);
}

if ($has('unlock')) {
    I::unlock();
    $out('已解除安装锁：' . I::lockFile());
    $out('提示：重新安装会覆盖 ' . I::configFile() . '，请先备份。');
    exit(0);
}

/* ---------- 已安装拦截 ---------- */
if (I::installed() && !$has('force')) {
    $err('系统已安装。如需重新安装：php install/cli.php --unlock 后再执行本命令（会覆盖部署配置）。');
    exit(1);
}

if (!I::envPass($rows)) {
    $err('环境检测未通过，请先执行：php install/cli.php --check');
    exit(1);
}

/* ---------- 收集参数（缺省则交互询问） ---------- */
$cfg = I::config();
$interactive = !$has('no-interactive') && (count($opt) === 0 || $has('interactive'));

$ask = static function (string $label, string $default = '', bool $secret = false) use ($out, $interactive): string {
    if (!$interactive) {
        return $default;
    }
    $suffix = $default !== '' ? " [{$default}]" : '';
    if ($secret) {
        // 隐藏输入（Windows/POSIX 通用降级：readline 不可用时明文回显）
        $out($label . $suffix . '：');
        if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false) {
            @shell_exec('stty -echo');
            $v = trim((string) fgets(STDIN));
            @shell_exec('stty echo');
            $out('');
            return $v !== '' ? $v : $default;
        }
        $v = trim((string) fgets(STDIN));
        return $v !== '' ? $v : $default;
    }
    $out($label . $suffix . '：');
    $v = trim((string) fgets(STDIN));
    return $v !== '' ? $v : $default;
};

$pick = static function (string $key, string $default) use ($opt, $ask, $has): string {
    if (array_key_exists($key, $opt)) {
        return (string) $opt[$key];
    }
    return $ask($key, $default);
};

$out('');
$out('=== WebStats 安装向导（命令行） ===');
$out('提示：直接回车使用方括号中的默认值。');
$out('');

$db = [
    'host' => $pick('db-host', (string) ($cfg['db']['host'] ?: '127.0.0.1')),
    'port' => (int) $pick('db-port', (string) ($cfg['db']['port'] ?: 3306)),
    'name' => $pick('db-name', (string) ($cfg['db']['name'] ?? '')),
    'user' => $pick('db-user', (string) ($cfg['db']['user'] ?? '')),
    'pass' => array_key_exists('db-pass', $opt) ? (string) $opt['db-pass'] : $ask('数据库密码', (string) ($cfg['db']['pass'] ?? ''), true),
];
$createDb = $has('create-db');
if ($interactive && !$has('create-db')) {
    $ans = $ask('库不存在时自动创建？(y/n)', 'y');
    $createDb = in_array(strtolower($ans), ['y', 'yes', '1', '是'], true);
}

$skipRedis = $has('skip-redis');
if ($interactive && !$has('skip-redis')) {
    $ans = $ask('跳过 Redis（降级模式）？(y/n)', 'n');
    $skipRedis = in_array(strtolower($ans), ['y', 'yes', '1', '是'], true);
}

$redis = [
    'host' => $pick('redis-host', (string) ($cfg['redis']['host'] ?: '127.0.0.1')),
    'port' => (int) $pick('redis-port', (string) ($cfg['redis']['port'] ?: 6379)),
    'auth' => array_key_exists('redis-auth', $opt) ? (string) $opt['redis-auth'] : $ask('Redis 密码（无则回车）', (string) ($cfg['redis']['auth'] ?? ''), true),
    'db'   => (int) $pick('redis-db', (string) ($cfg['redis']['db'] ?? 0)),
];

$adminEmail = $pick('admin-email', '');
$adminPass  = array_key_exists('admin-pass', $opt) ? (string) $opt['admin-pass'] : $ask('管理员密码', '', true);
$adminNick  = $pick('admin-nick', '');
$debug      = $has('debug');

/* ---------- 校验 ---------- */
$fail = static function (string $msg) use ($err): void {
    $err('✗ ' . $msg);
    exit(1);
};
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['name'])) {
    $fail('数据库名非法（只允许字母、数字、下划线，1-64 位）');
}
if ($db['user'] === '') {
    $fail('数据库用户名不能为空');
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    $fail('管理员邮箱格式不正确');
}
if (strlen($adminPass) < 8 || strlen($adminPass) > 72) {
    $fail('管理员密码长度需 8-72 位');
}

/* ---------- 执行 ---------- */
$out('');
$out('[1/5] 连接 MySQL ...');
try {
    $info = I::inspectServer($db);
    $out('      OK，服务器版本 ' . $info['version']);
} catch (\Throwable $e) {
    $fail('MySQL 连接失败：' . $e->getMessage());
}

$out('[2/5] 准备数据库 `' . $db['name'] . '` ...');
try {
    if (!$info['db_exists']) {
        if (!$createDb) {
            $fail('数据库不存在且未指定 --create-db');
        }
        I::createDatabase($db);
        $out('      已创建');
    } else {
        $out('      已存在，沿用');
    }
} catch (\Throwable $e) {
    $fail('创建数据库失败：' . $e->getMessage());
}

$out('[3/5] 执行建表脚本（幂等，不会删除已有数据）...');
try {
    $schema = I::runSchema($db);
    $missing = array_keys(array_filter($schema['tables'], static fn($ok) => !$ok));
    $out('      执行 ' . $schema['executed'] . ' 条语句，events 分区 ' . $schema['partition_count'] . ' 个');
    if ($missing) {
        $fail('以下表创建失败：' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    $fail('建表失败：' . $e->getMessage());
}

if (!$skipRedis) {
    $out('[4/5] 测试 Redis ...');
    $r = I::testRedis($redis);
    if ($r['ok']) {
        $out('      OK，版本 ' . $r['version'] . '（' . $r['latency_ms'] . 'ms）');
    } else {
        $err('      ! Redis 连接失败：' . $r['msg']);
        $err('      ! 已自动切换为降级模式（实时/会话功能受限）。可稍后修正 ' . I::configFile() . '。');
        $skipRedis = true;
    }
} else {
    $out('[4/5] 已跳过 Redis（降级模式）');
}

$out('[5/5] 创建管理员并写入配置 ...');
try {
    $admin = I::createAdmin($db, $adminEmail, $adminPass, $adminNick);
    $file = I::writeConfig($db, $redis + ['required' => !$skipRedis], [
        'debug' => $debug,
        'security' => ['allow_register' => $has('allow-register') ? (bool) ((int) $opt['allow-register']) : false],
    ]);
    I::lock();
    $out('      管理员：' . $adminEmail . '（' . ($admin['created'] ? '已创建' : $admin['reason']) . '）');
    $out('      配置：' . $file);
    $out('      锁文件：' . I::lockFile());
} catch (\Throwable $e) {
    $fail('写入配置失败：' . $e->getMessage());
}

$out('');
$out('✔ 安装完成！');
$out('');
$out('接下来必须执行（建议加入 crontab / 宝塔计划任务）：');
foreach (I::checklist(I::baseUrl(), PHP_BINARY) as $title => $cmd) {
    $out('  · ' . $title);
    $out('      ' . $cmd);
}
$out('');
$out('安全提醒：安装完成后请删除 install 目录。');
$out('');
$out('站点接入代码（把 YOUR_SITE_KEY 换成后台创建的站点 key）：');
$out('  ' . I::sdkSnippet(I::baseUrl()));
exit(0);
