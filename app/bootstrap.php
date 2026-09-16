<?php
/**
 * 网站统计系统 - 引导文件
 * 轻量自研框架：无 Composer 依赖，PSR-4 风格简易自动加载。
 */
declare(strict_types=1);

define('WSTAT_ROOT', dirname(__DIR__));          // 项目根（统一布局）/ 开发副本下的 server/
define('WSTAT_APP', WSTAT_ROOT . '/app');

error_reporting(E_ALL);

/** 配置读取 */
function wstat_config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require WSTAT_ROOT . '/config.php';
    }
    if ($key === null) {
        return $cfg;
    }
    $node = $cfg;
    foreach (explode('.', $key) as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) {
            return null;
        }
        $node = $node[$seg];
    }
    return $node;
}

/** 简易自动加载：Wstat\前缀 映射到 app/ 目录 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Wstat\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = WSTAT_APP . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set((string) wstat_config('timezone'));

/* ---------- 全局异常/错误处理：CLI 明文输出，Web 输出 JSON 500（debug 带详情） ---------- */
set_exception_handler(function (Throwable $e): void {
    if (PHP_SAPI === 'cli') {
        // 常驻脚本（worker/cron）崩溃时输出明文诊断，避免误导性的 JSON 混进日志
        fwrite(STDERR, '[fatal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
        exit(255);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $debug = (bool) wstat_config('debug');
    echo json_encode([
        'code' => 500,
        'msg'  => $debug ? ('Internal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) : 'Internal Error',
        'data' => null,
    ], JSON_UNESCAPED_UNICODE);
});

if ((bool) wstat_config('debug')) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

/** 简便输出 JSON 响应 */
function wstat_json($data = null, int $http = 200, int $code = 0, string $msg = 'ok'): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    // 统计接口全部是实时数据：必须显式禁用缓存。
    // 只给 Content-Type 时响应没有新鲜度信息，浏览器会按「启发式缓存」处理，
    // 中间代理（nginx proxy_cache / CDN）更会无条件缓存 → 面板出现「数据半天不更新」。
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit(0);
}

/** 错误响应 */
function wstat_err(string $msg, int $http = 400, int $code = 1, $data = null): void
{
    wstat_json($data, $http, $code, $msg);
}

/** 读取 HTTP 原始请求体（兼容 json / form）；超过 64KB 直接丢弃（防超大 body 消耗内存，本系统无此量级的合法业务） */
function wstat_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '' || strlen($raw) > 65536) {
        return $_POST ?: [];
    }
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: [];
}

/* ---------- 安装状态守卫 ---------- */

/** 系统是否已完成安装（配置里有可用的数据库连接信息即可运行） */
function wstat_installed(): bool
{
    return (bool) wstat_config('installed');
}

/** 安装向导地址（用于引导跳转；反代场景可自行修正） */
function wstat_install_url(): string
{
    return '/install/';
}

/**
 * 把「相对项目根的路径」换算成可直接复制执行的写法。
 * 统一布局（v1.0.1+ 发布包）：WSTAT_ROOT 就是项目根 → scripts/worker.php
 * 开发副本 / ≤1.0.0 嵌套布局：WSTAT_ROOT 是 <项目根>/server → server/scripts/worker.php
 * 诊断信息里一律用它，避免把「命令路径」写死成某一种布局（用户复制执行失败最难排查）。
 */
function wstat_rel(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $root = str_replace('\\', '/', WSTAT_ROOT);
    return basename($root) === 'server' ? 'server/' . $path : $path;
}

/**
 * 未安装时中断请求：API/采集入口调用。
 * 返回 503 而非 500，便于前端识别并引导到安装向导。
 */
function wstat_guard_installed(): void
{
    if (wstat_installed()) {
        return;
    }
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 503,
        'msg'  => '系统尚未安装，请先访问安装向导完成初始化',
        'data' => ['install_url' => wstat_install_url(), 'installed' => false],
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}
