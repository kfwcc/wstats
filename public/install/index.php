<?php
/**
 * WebStats 安装向导
 *
 * 访问：http(s)://你的域名/install/
 * 流程：环境检测 → 数据库/Redis 配置与连通测试 → 创建管理员 → 完成（写入配置并加锁）
 *
 * 特性：
 *  - 自包含单入口，无外部依赖、无 CDN，内联样式；
 *  - 已安装（存在 data/install.lock）时拒绝再次安装，防止被他人重装覆盖配置；
 *  - 建表脚本幂等（CREATE TABLE IF NOT EXISTS），失败可安全重试。
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

use WstatInstall\Installer as I;

session_name('wstat_install');
@session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    // session 不可用时退化为一次性提示（仍可安装，只是不能跨请求保留表单值）
    $GLOBALS['WSTAT_NO_SESSION'] = true;
}

/* ===================== 手动指路（install/local.php） =====================
 * 面板环境（如宝塔的 open_basedir、自定义目录结构）自动探测可能失败，
 * 允许在此显式指定项目根并生成 local.php —— 无需改动 PHP-FPM / Nginx 配置。 */
$localError = '';
$localOk = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'setroot') {
    $localError = I::saveLocalRoot((string) ($_POST['root_path'] ?? ''));
    if ($localError === '') {
        $localOk = '已写入 ' . I::installDir() . '/local.php，正在重新探测…';
    }
}

/* ===================== 目录结构诊断 =====================
 * server/ 或建表脚本定位失败时给出可操作页面，而不是 Fatal error。 */
try {
    $envRows = I::envCheck();
} catch (\Throwable $e) {
    renderBroken($e, $localError, $localOk);
    exit(0);
}

$baseUrl = I::baseUrl();
$envPass = I::envPass($envRows);

/* 目录结构缺失（后端目录 / 建表脚本）时直接给诊断页，避免「必需项不满足」这种无从下手的提示 */
foreach ($envRows as $r) {
    if ($r['group'] === '部署布局' && $r['required'] && !$r['ok']) {
        renderBroken(
            new \RuntimeException('缺少「' . $r['name'] . '」，无法继续安装（' . $r['current'] . '）。'),
            $localError,
            $localOk
        );
        exit(0);
    }
}

/* ===================== 已安装拦截 ===================== */
$statusOnly = (($_GET['action'] ?? '') === 'status');
if (I::installed() && !$statusOnly) {
    renderPage('系统已安装', function () use ($baseUrl) {
        $lock = json_decode((string) @file_get_contents(I::lockFile()), true) ?: [];
        ?>
        <div class="alert ok">
            <strong>本系统已完成安装。</strong>
            安装时间：<?= h((string) ($lock['installed_at'] ?? '未知')) ?>
            ｜版本：<?= h((string) ($lock['version'] ?? '-')) ?>
        </div>
        <p>重新安装会覆盖部署配置，请按以下方式操作：</p>
        <ol class="steps-list">
            <li>先<b>备份数据库</b>与 <code><?= h(I::dataDir()) ?>/installed.php</code>；</li>
            <li>删除安装锁文件 <code><?= h(I::dataDir()) ?>/install.lock</code>（或命令行执行
                <code>php <?= h(I::installDir()) ?>/cli.php --unlock</code>）；</li>
            <li>刷新本页重新进入向导。</li>
        </ol>
        <p class="muted">安全建议：部署完成后请删除整个 <code>install</code> 目录，避免被他人重装。</p>
        <div class="actions">
            <a class="btn primary" href="<?= h($baseUrl) ?>/">进入管理端</a>
            <a class="btn" href="?action=status">查看安装状态</a>
        </div>
        <?php
    });
    exit(0);
}

if ($statusOnly) {
    $lock = json_decode((string) @file_get_contents(I::lockFile()), true) ?: [];
    $cfg = I::config();
    renderPage('安装状态', function () use ($lock, $cfg, $baseUrl) {
        ?>
        <table class="tbl">
            <tr><th>安装状态</th><td><?= I::installed() ? '<b class="ok-text">已安装</b>' : '<b class="warn-text">未安装</b>' ?></td></tr>
            <tr><th>安装时间</th><td><?= h((string) ($lock['installed_at'] ?? '-')) ?></td></tr>
            <tr><th>版本</th><td><?= h((string) ($lock['version'] ?? I::VERSION)) ?></td></tr>
            <tr><th>数据库</th><td><?= h((string) ($cfg['db']['name'] ?? '-')) ?> @ <?= h((string) ($cfg['db']['host'] ?? '-')) ?>:<?= (int) ($cfg['db']['port'] ?? 0) ?></td></tr>
            <tr><th>Redis</th><td><?= h((string) ($cfg['redis']['host'] ?? '-')) ?>:<?= (int) ($cfg['redis']['port'] ?? 0) ?> / DB<?= (int) ($cfg['redis']['db'] ?? 0) ?></td></tr>
            <tr><th>调试模式</th><td><?= !empty($cfg['debug']) ? '开启（生产建议关闭）' : '关闭' ?></td></tr>
        </table>
        <div class="actions"><a class="btn" href="<?= h($baseUrl) ?>/">进入管理端</a></div>
        <?php
    });
    exit(0);
}

/* ===================== 表单处理 ===================== */
$errors = [];
$notice = '';
$step = (string) ($_POST['step'] ?? $_GET['step'] ?? '1');

/** 读取表单里的数据库/Redis 参数 */
function readDbForm(): array
{
    return [
        'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
        'port' => (int) ($_POST['db_port'] ?? 3306) ?: 3306,
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];
}

function readRedisForm(): array
{
    return [
        'host' => trim((string) ($_POST['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
        'port' => (int) ($_POST['redis_port'] ?? 6379) ?: 6379,
        'auth' => (string) ($_POST['redis_auth'] ?? ''),
        'db'   => (int) ($_POST['redis_db'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    /* ---- 步骤 2：测试数据库与 Redis ---- */
    if ($action === 'test') {
        $db = readDbForm();
        $redis = readRedisForm();
        $skipRedis = !empty($_POST['skip_redis']);
        $createDb = !empty($_POST['create_db']);

        if ($db['name'] === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['name'])) {
            $errors[] = '数据库名必填，且只能包含字母、数字、下划线（1-64 位）';
        }
        if ($db['user'] === '') {
            $errors[] = '数据库用户名必填';
        }

        if (!$errors) {
            try {
                $info = I::inspectServer($db);
                if (!$info['db_exists']) {
                    if ($createDb) {
                        I::createDatabase($db);
                        $notice .= "已创建数据库 `{$db['name']}`。";
                    } else {
                        $errors[] = "数据库 `{$db['name']}` 不存在。请先在宝塔/命令行创建，或勾选「库不存在时自动创建」。";
                    }
                }
                if (!$errors) {
                    $pdo = I::pdo($db, true);
                    $pdo->query('SELECT 1');
                    $notice .= 'MySQL 连接成功（服务器版本 ' . h($info['version']) . '）。';
                }
            } catch (\Throwable $e) {
                $errors[] = 'MySQL 连接失败：' . $e->getMessage();
            }
        }

        $redisResult = null;
        if (!$errors && !$skipRedis) {
            $redisResult = I::testRedis($redis);
            if (!$redisResult['ok']) {
                $errors[] = 'Redis 连接失败：' . $redisResult['msg'] . '（也可勾选「无 Redis 模式」，数据直接写入数据库）';
            } else {
                $notice .= 'Redis 连接成功（版本 ' . h($redisResult['version']) . '，' . $redisResult['latency_ms'] . 'ms）。';
            }
        }

        if (!$errors) {
            $_SESSION['db'] = $db;
            $_SESSION['redis'] = $redis + ['required' => !$skipRedis];
            $_SESSION['redis_skipped'] = $skipRedis;
            $step = '3';
        }
    }

    /* ---- 步骤 3：执行安装 ---- */
    if ($action === 'install') {
        $db = $_SESSION['db'] ?? null;
        $redis = $_SESSION['redis'] ?? null;
        if (!is_array($db) || !is_array($redis)) {
            $errors[] = '安装会话已过期，请返回上一步重新填写（若浏览器禁用了 Cookie，请开启后重试）';
            $step = '2';
        }

        $email = trim((string) ($_POST['admin_email'] ?? ''));
        $pass  = (string) ($_POST['admin_pass'] ?? '');
        $pass2 = (string) ($_POST['admin_pass2'] ?? '');
        $nick  = trim((string) ($_POST['admin_nick'] ?? ''));
        $debug = !empty($_POST['debug']);
        $allowRegister = !empty($_POST['allow_register']);

        if (!$errors) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = '管理员邮箱格式不正确';
            }
            if (strlen($pass) < 8 || strlen($pass) > 72) {
                $errors[] = '管理员密码长度需 8-72 位';
            } elseif ($pass !== $pass2) {
                $errors[] = '两次输入的密码不一致';
            }
        }

        if (!$errors) {
            try {
                // 1) 建表
                $schema = I::runSchema($db);
                // 2) 管理员
                $admin = I::createAdmin($db, $email, $pass, $nick);
                // 3) 写配置
                $cfgFile = I::writeConfig($db, $redis, [
                    'debug'    => $debug,
                    'timezone' => 'Asia/Shanghai',
                    'security' => ['allow_register' => $allowRegister],
                ]);
                // 4) 加锁
                I::lock();

                $_SESSION[I::SESSION_KEY . '_done'] = [
                    'tables'  => $schema['tables'],
                    'executed' => $schema['executed'],
                    'partitions' => $schema['partition_count'],
                    'admin'   => $admin,
                    'email'   => $email,
                    'cfg'     => $cfgFile,
                ];
                $step = '4';
            } catch (\Throwable $e) {
                $errors[] = '安装失败：' . $e->getMessage();
            }
        }
    }
}

/* ---- 渲染 ---- */
if ($step === '4') {
    $done = $_SESSION[I::SESSION_KEY . '_done'] ?? null;
    if (!$done) {
        header('Location: ?action=status');
        exit;
    }
    $checklist = I::checklist($baseUrl);
    renderPage('安装完成', function () use ($done, $checklist, $baseUrl) {
        ?>
        <div class="alert ok">
            <strong>安装成功！</strong>数据库结构已就绪，部署配置已写入。
        </div>

        <table class="tbl">
            <tr><th>管理员账号</th><td><?= h((string) $done['email']) ?>（<?= $done['admin']['created'] ? '已创建' : h((string) $done['admin']['reason']) ?>）</td></tr>
            <tr><th>执行语句</th><td><?= (int) $done['executed'] ?> 条</td></tr>
            <tr><th>数据表</th><td>
                <?php foreach ($done['tables'] as $t => $ok): ?>
                    <span class="pill <?= $ok ? 'ok' : 'bad' ?>"><?= h($t) ?></span>
                <?php endforeach; ?>
            </td></tr>
            <tr><th>events 分区数</th><td><?= (int) $done['partitions'] ?></td></tr>
            <tr><th>配置文件</th><td><?= h((string) $done['cfg']) ?></td></tr>
        </table>

        <h3>接下来必须做的事</h3>
        <p class="muted">以下命令复制到服务器执行（宝塔面板可做成「计划任务」与「守护进程」）：</p>
        <ul class="cmd-list">
            <?php foreach ($checklist as $title => $cmd): ?>
                <li>
                    <div class="cmd-title"><?= h($title) ?></div>
                    <code class="cmd"><?= h($cmd) ?></code>
                </li>
            <?php endforeach; ?>
        </ul>

        <h3>站点接入代码</h3>
        <p class="muted">登录后台创建站点后，把站点 key 填进下面的代码，粘贴到被统计网站 <code>&lt;head&gt;</code> 中：</p>
        <code class="cmd"><?= h(I::sdkSnippet($baseUrl)) ?></code>

        <div class="alert warn">
            <strong>安全提醒：</strong>请立即删除 <code>install</code> 目录，否则他人可重新安装并覆盖你的配置。
        </div>

        <div class="actions">
            <a class="btn primary" href="<?= h($baseUrl) ?>/">进入管理端登录</a>
            <a class="btn" href="?action=status">安装状态</a>
        </div>
        <?php
    });
    exit(0);
}

if ($step === '3') {
    $db = $_SESSION['db'] ?? [];
    $redisSkipped = !empty($_SESSION['redis_skipped']);
    renderPage('创建管理员', function () use ($errors, $notice, $db, $redisSkipped) {
        foreach ($errors as $e) {
            echo '<div class="alert err">' . h($e) . '</div>';
        }
        if ($notice !== '') {
            echo '<div class="alert ok">' . $notice . '</div>';
        }
        ?>
        <p class="muted">即将在数据库 <b><?= h((string) ($db['name'] ?? '')) ?></b> 中创建管理员账号并写回部署配置。
            该账号自动获得<b>系统管理员</b>权限（可访问「系统设置」），也是本系统的第一个账号。</p>
        <?php if ($redisSkipped): ?>
            <div class="alert warn">已选择跳过 Redis：实时访客、在线数、会话与停留时长将退化为直写模式（功能受限）。</div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="action" value="install">
            <div class="grid">
                <label>管理员邮箱 <span class="req">*</span>
                    <input type="email" name="admin_email" required placeholder="admin@example.com" autocomplete="off">
                </label>
                <label>管理员昵称
                    <input type="text" name="admin_nick" placeholder="留空则取邮箱前缀" autocomplete="off">
                </label>
                <label>登录密码 <span class="req">*</span>
                    <input type="password" name="admin_pass" required minlength="8" placeholder="至少 8 位，建议字母+数字+符号" autocomplete="new-password">
                </label>
                <label>确认密码 <span class="req">*</span>
                    <input type="password" name="admin_pass2" required minlength="8" autocomplete="new-password">
                </label>
            </div>
            <div class="opts">
                <label class="chk"><input type="checkbox" name="debug" value="1"> 开启调试模式（输出详细错误，仅建议内网/排障时开启）</label>
                <label class="chk"><input type="checkbox" name="allow_register" value="1" checked> 允许自助注册（关闭后仅能后台开通账号）</label>
            </div>
            <div class="actions">
                <a class="btn" href="?step=2">上一步</a>
                <button class="btn primary" type="submit">开始安装</button>
            </div>
        </form>
        <?php
    });
    exit(0);
}

if ($step === '2') {
    $db = $_SESSION['db'] ?? I::config();
    $dbDefaults = [
        'host' => (string) ($db['db']['host'] ?? $db['host'] ?? '127.0.0.1'),
        'port' => (int) ($db['db']['port'] ?? $db['port'] ?? 3306),
        'name' => (string) ($db['db']['name'] ?? $db['name'] ?? ''),
        'user' => (string) ($db['db']['user'] ?? $db['user'] ?? ''),
    ];
    $redisDefaults = [
        'host' => (string) ($db['redis']['host'] ?? '127.0.0.1'),
        'port' => (int) ($db['redis']['port'] ?? 6379),
        'db'   => (int) ($db['redis']['db'] ?? 0),
    ];
    renderPage('数据库与 Redis', function () use ($errors, $notice, $dbDefaults, $redisDefaults) {
        foreach ($errors as $e) {
            echo '<div class="alert err">' . h($e) . '</div>';
        }
        if ($notice !== '') {
            echo '<div class="alert ok">' . $notice . '</div>';
        }
        ?>
        <form method="post">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="action" value="test">

            <h3>MySQL 数据库</h3>
            <div class="grid">
                <label>主机 <input type="text" name="db_host" value="<?= h($dbDefaults['host']) ?>" required></label>
                <label>端口 <input type="number" name="db_port" value="<?= (int) $dbDefaults['port'] ?>" min="1" max="65535" required></label>
                <label>数据库名 <input type="text" name="db_name" value="<?= h($dbDefaults['name']) ?>" placeholder="如 webstats" required></label>
                <label>用户名 <input type="text" name="db_user" value="<?= h($dbDefaults['user']) ?>" required autocomplete="off"></label>
                <label class="span2">密码 <input type="password" name="db_pass" autocomplete="new-password" placeholder="数据库密码"></label>
            </div>
            <div class="opts">
                <label class="chk"><input type="checkbox" name="create_db" value="1" checked> 库不存在时自动创建</label>
            </div>

            <h3>Redis <span class="muted">（推荐，用于实时计数 / 队列 / 在线会话）</span></h3>
            <div class="grid">
                <label>主机 <input type="text" name="redis_host" value="<?= h($redisDefaults['host']) ?>"></label>
                <label>端口 <input type="number" name="redis_port" value="<?= (int) $redisDefaults['port'] ?>" min="1" max="65535"></label>
                <label>密码 <input type="password" name="redis_auth" autocomplete="new-password" placeholder="无则留空"></label>
                <label>库序号 <input type="number" name="redis_db" value="<?= (int) $redisDefaults['db'] ?>" min="0" max="15"></label>
            </div>
            <div class="opts">
                <label class="chk"><input type="checkbox" name="skip_redis" value="1"> 无 Redis 模式（数据直写数据库，无需部署 Redis 与 worker）</label>
            </div>
            <p class="muted">内置纯 PHP RESP 客户端，无需安装 phpredis 扩展。</p>

            <div class="actions">
                <a class="btn" href="?step=1">上一步</a>
                <button class="btn primary" type="submit">测试连接并继续</button>
            </div>
        </form>
        <?php
    });
    exit(0);
}

/* 默认：步骤 1 环境检测 —— 默认只展开「必须项」；可选扩展/运行方式/安全提示折叠，避免首屏一屏表格 */
renderPage('环境检测', function () use ($envRows, $envPass, $localOk) {
    $req = array_values(array_filter($envRows, static fn (array $r): bool => (bool) $r['required']));
    $opt = array_values(array_filter($envRows, static fn (array $r): bool => !$r['required']));
    $reqOk = count(array_filter($req, static fn (array $r): bool => (bool) $r['ok']));
    $reqFail = array_values(array_filter($req, static fn (array $r): bool => !$r['ok']));
    $optFail = array_values(array_filter($opt, static fn (array $r): bool => !$r['ok']));

    /** 单行渲染（必须项未过=「不满足」，折叠区里的可选未过=「注意」） */
    $row = static function (array $r): string {
        $flag = $r['ok']
            ? '<span class="pill ok">通过</span>'
            : '<span class="pill bad">' . ($r['required'] ? '不满足' : '注意') . '</span>';
        return '<tr><td><span class="muted">' . h((string) $r['group']) . '</span> · ' . h((string) $r['name']) . '</td>'
            . '<td>' . $flag . ' <span class="muted">' . h((string) $r['current']) . '</span></td>'
            . '<td class="muted">' . h((string) $r['hint']) . '</td></tr>';
    };

    if ($localOk !== '') {
        echo '<div class="alert ok">' . h($localOk) . '</div>';
    }
    if (!$envPass) {
        echo '<div class="alert err"><strong>必须项未满足：</strong>'
            . h(implode('、', array_map(static fn (array $r): string => (string) $r['name'], $reqFail)))
            . '。请先在服务器上处理后再继续。</div>';
    } else {
        echo '<div class="alert ok">环境检测通过（必须项 ' . $reqOk . '/' . count($req) . ' 全部满足），可以开始安装。</div>';
    }

    echo '<h3>必须项（' . $reqOk . '/' . count($req) . '）</h3>';
    echo '<table class="tbl"><tr><th style="width:34%">项目</th><th style="width:24%">当前</th><th>说明</th></tr>';
    foreach ($req as $r) {
        echo $row($r);
    }
    echo '</table>';

    if ($optFail) {
        echo '<div class="alert warn">以下可选组件未就绪（不影响安装，按需处理）：'
            . h(implode('、', array_map(static fn (array $r): string => (string) $r['name'], $optFail))) . '</div>';
    }

    echo '<details class="more"><summary>查看其余检测项（可选扩展 / 运行方式 / 安全提示，共 ' . count($opt) . ' 项）</summary>';
    echo '<table class="tbl"><tr><th style="width:34%">项目</th><th style="width:24%">当前</th><th>说明</th></tr>';
    foreach ($opt as $r) {
        echo $row($r);
    }
    echo '</table></details>';
    ?>
    <div class="actions">
        <?php if ($envPass): ?>
            <a class="btn primary" href="?step=2">下一步：数据库配置</a>
        <?php else: ?>
            <a class="btn" href="?step=1">重新检测</a>
        <?php endif; ?>
    </div>
    <?php
});

/* ===================== 页面骨架 ===================== */

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 目录结构异常时的诊断页（server/ 或 sql/ 定位失败）。
 * 关键：给出「探测到哪、缺什么、怎么放」三件事，而不是抛 Fatal error。
 */
function renderBroken(\Throwable $e, string $localError = '', string $localOk = ''): void
{
    renderPage('目录结构诊断', function () use ($e, $localError, $localOk) {
        ?>
        <div class="alert err">
            <strong>安装程序无法定位项目文件。</strong><br>
            <?= h($e->getMessage()) ?>
        </div>
        <?php if ($localOk !== ''): ?>
            <div class="alert ok"><?= h($localOk) ?></div>
        <?php endif; ?>
        <?php if ($localError !== ''): ?>
            <div class="alert err"><?= h($localError) ?></div>
        <?php endif; ?>

        <h3>探测结果（逐级向上）</h3>
        <table class="tbl">
            <tr><th style="width:58%">路径</th><th>内容</th></tr>
            <?php foreach (I::probeReport() as $r): ?>
                <tr><td><code><?= h($r[0]) ?></code></td><td class="muted"><?= h($r[1]) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">判定依据：后端目录必须包含 <code>app/bootstrap.php</code>；
           建表脚本名为 <code>install.sql</code>，可位于 <code>sql/</code> 目录下。</p>

        <h3>受支持的目录布局</h3>
        <table class="tbl">
            <tr><th style="width:26%">布局</th><th>目录结构</th></tr>
            <tr>
                <td>A. 统一布局<br><span class="muted">v1.0.1+ 发布包（推荐）</span></td>
                <td><code>/www/wwwroot/你的站点/&nbsp;&nbsp;← 网站目录（宝塔）<br>
                    ├── app/&nbsp;config.php&nbsp;data/&nbsp;scripts/&nbsp;&nbsp;后端（在 Web 根之外，无需屏蔽）<br>
                    ├── public/&nbsp;&nbsp;← <b>运行目录 / Web 根</b>（index.php、collect.php、前端产物、install/）<br>
                    └── sql/&nbsp;&nbsp;建表脚本</code></td>
            </tr>
            <tr>
                <td>B. 旧标准包<br><span class="muted">≤1.0.0，Web 根 = server/public</span></td>
                <td><code>/www/wwwroot/你的站点/<br>
                    ├── server/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;后端（app、config.php、data、scripts）<br>
                    │&nbsp;&nbsp;&nbsp;└── public/&nbsp;&nbsp;← Web 根（含 install/、入口、采集）<br>
                    └── sql/&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;建表脚本（与网站同级）</code></td>
            </tr>
            <tr>
                <td>C. 旧宝塔扁平包<br><span class="muted">≤1.0.0（已废弃），运行目录=站点目录本身</span></td>
                <td><code>/www/wwwroot/你的站点/&nbsp;&nbsp;← 站点目录即 Web 根<br>
                    ├── index.php / collect.php / app/ / config.php / data/ / scripts/<br>
                    ├── install/&nbsp;&nbsp;安装向导（装完删除）<br>
                    └── sql/&nbsp;&nbsp;建表脚本<br>
                    这种布局必须自行屏蔽后端目录直访：<br>
                    <code>location ^~ /app/ { deny all; }</code>（data / scripts / sql 同理），
                    否则 <code>data/installed.php</code> 可被直接下载。<br>
                    <b>建议迁移到统一布局</b>（把运行目录改为 <code>/public</code>，见 docs/deploy.md §2）。</code></td>
            </tr>
            <tr>
                <td>D. 安装器自带<br><span class="muted">无需项目根</span></td>
                <td>把 <code>sql/install.sql</code> 放到安装器目录下：
                    <code>&lt;Web根&gt;/install/sql/install.sql</code>（发布包已自带一份）</td>
            </tr>
        </table>

        <h3>方式一：填一下路径即可（推荐）</h3>
        <p class="muted">填写<b>项目根目录</b>（含 <code>app/</code> 与 <code>config.php</code> 的目录；
            统一布局即包根，旧包为 <code>server/</code> 的上级目录），系统会生成
            <code>&lt;Web根&gt;/install/local.php</code>，随后无需任何服务器配置即可继续安装。</p>
        <form method="post">
            <input type="hidden" name="action" value="setroot">
            <div class="grid">
                <label>项目根目录 <span class="req">*</span>
                    <input type="text" name="root_path" required
                           placeholder="例如 /www/wwwroot/webstats">
                </label>
            </div>
            <div class="actions">
                <button class="btn primary" type="submit">保存并重新探测</button>
            </div>
        </form>

        <h3>方式二：命令行检查</h3>
        <ul class="cmd-list">
            <li>
                <span class="cmd-title">带路径自检（推荐先跑这个）</span>
                <code class="cmd">php <?= h(I::installDir()) ?>/cli.php --check --root=/www/wwwroot/你的站点</code>
            </li>
            <li>
                <span class="cmd-title">命令行安装（会自动写入 local.php 之外的全部配置）</span>
                <code class="cmd">php <?= h(I::installDir()) ?>/cli.php --root=/www/wwwroot/你的站点</code>
            </li>
        </ul>

        <h3>方式三：环境变量（改服务器配置）</h3>
        <p class="muted">Nginx 站点配置的 PHP 段加入 <code>fastcgi_param WSTAT_ROOT /www/wwwroot/你的站点;</code>，
            或宝塔 PHP-FPM 池配置加入 <code>env[WSTAT_ROOT] = /www/wwwroot/你的站点</code>，然后重启 PHP。
            建表脚本位置可用 <code>WSTAT_SQL_FILE</code> 指定。</p>
        <?php
    });
}

function renderPage(string $title, callable $body): void
{
    $step = (int) ($_GET['step'] ?? $_POST['step'] ?? 1);
    $steps = ['环境检测', '数据库配置', '创建管理员', '完成'];
    $cur = max(1, min(4, $step));
    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> - WebStats 安装向导</title>
<style>
:root{
  --bg:#f5f7fa; --card:#fff; --line:#e3e8ef; --text:#1f2937; --muted:#6b7280;
  --primary:#2563eb; --primary-d:#1d4ed8; --ok:#0f9d58; --ok-bg:#e8f6ee;
  --err:#dc2626; --err-bg:#fdecec; --warn:#b45309; --warn-bg:#fff6e5;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:960px;margin:0 auto;padding:28px 18px 60px}
.head{display:flex;align-items:center;gap:12px;margin-bottom:18px}
.logo{width:38px;height:38px;border-radius:9px;background:var(--primary);color:#fff;
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}
.head h1{font-size:19px;margin:0}
.head .sub{color:var(--muted);font-size:12px}
.steps{display:flex;gap:8px;margin:16px 0 18px;flex-wrap:wrap}
.steps div{flex:1;min-width:120px;padding:9px 12px;background:var(--card);border:1px solid var(--line);
  border-radius:8px;color:var(--muted);font-size:13px}
.steps div.active{border-color:var(--primary);color:var(--primary);font-weight:600;background:#eff6ff}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:22px 22px 26px}
h3{font-size:15px;margin:22px 0 10px;padding-left:9px;border-left:3px solid var(--primary)}
h3:first-child{margin-top:4px}
.tbl{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px}
.tbl th,.tbl td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}
.tbl th{background:#fafbfc;font-weight:600;color:#374151}
.pill{display:inline-block;padding:1px 7px;border-radius:10px;font-size:12px;line-height:1.6}
.pill.ok{background:var(--ok-bg);color:var(--ok)}
.pill.bad{background:var(--err-bg);color:var(--err)}
.muted{color:var(--muted)}
.ok-text{color:var(--ok)} .warn-text{color:var(--warn)}
.alert{padding:11px 14px;border-radius:8px;margin:0 0 14px;font-size:13px}
.alert.ok{background:var(--ok-bg);color:#0b6b3f;border:1px solid #bfe6d0}
.alert.err{background:var(--err-bg);color:#991b1b;border:1px solid #f5c6c6}
.alert.warn{background:var(--warn-bg);color:#8a5300;border:1px solid #f2ddb0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;margin-bottom:6px}
.grid .span2{grid-column:1 / -1}
label{display:flex;flex-direction:column;gap:5px;font-size:13px;color:#374151}
input[type=text],input[type=password],input[type=email],input[type=number]{
  width:100%;padding:9px 11px;border:1px solid #d3dae3;border-radius:7px;font-size:14px;background:#fff;color:var(--text)}
input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.chk{flex-direction:row;align-items:center;gap:8px;display:flex}
.chk input{width:auto}
details.more{margin-top:16px;border:1px solid var(--line);border-radius:8px;padding:10px 12px;background:#fafbfc}
details.more>summary{cursor:pointer;font-size:13px;color:#374151;font-weight:600}
details.more[open]>summary{margin-bottom:8px}
@media (max-width:560px){.grid{grid-template-columns:1fr}}
.opts{margin:10px 0 4px;display:flex;flex-direction:column;gap:8px}
.req{color:var(--err)}
.actions{display:flex;gap:10px;margin-top:22px;flex-wrap:wrap}
.btn{display:inline-block;padding:9px 18px;border-radius:8px;border:1px solid var(--line);
  background:#fff;color:var(--text);text-decoration:none;font-size:14px;cursor:pointer}
.btn:hover{border-color:#c3ccd8}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.primary:hover{background:var(--primary-d)}
code{background:#f3f5f8;padding:1px 5px;border-radius:4px;font-size:12.5px;
  font-family:ui-monospace,SFMono-Regular,Consolas,Menlo,monospace}
.cmd{display:block;background:#0f172a;color:#e2e8f0;padding:9px 12px;border-radius:7px;
  margin-top:5px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.cmd-list{list-style:none;padding:0;margin:0}
.cmd-list li{margin-bottom:12px}
.cmd-title{font-size:13px;color:#374151;font-weight:600}
.steps-list{color:var(--muted);font-size:13px;padding-left:20px}
.foot{margin-top:16px;color:#9aa3af;font-size:12px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div class="logo">W</div>
        <div>
            <h1>WebStats 网站统计系统 · 安装向导</h1>
            <div class="sub">v<?= h(I::VERSION) ?> ｜ 原生 PHP + MySQL + Redis，零第三方依赖</div>
        </div>
    </div>
    <?php if (in_array($title, ['环境检测', '数据库与 Redis', '创建管理员', '安装完成'], true)): ?>
    <div class="steps">
        <?php foreach ($steps as $i => $name): ?>
            <div class="<?= ($i + 1) === $cur ? 'active' : '' ?>">
                <?= ($i + 1) ?>. <?= h($name) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="card"><?php $body(); ?></div>
    <div class="foot">
        <div>WebStats Installer · 请勿将本页面暴露在公网过久，安装完成后立即删除 install 目录</div>
        <div style="margin-top:6px">
            Powered by
            <a href="https://wstats.kfw.cc" target="_blank" rel="noopener noreferrer" style="color:#2a5cff;font-weight:600;text-decoration:none">Wstats</a>
            · v<?= h(I::VERSION) ?>
        </div>
    </div>
</div>
</body>
</html><?php
}
