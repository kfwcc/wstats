<?php
/**
 * WebStats 采集端点入口  (任何站点跨域上报：POST JSON / GET 查询参数)
 * 说明：采集端允许任意 Origin，故此处独立入口并直接输出最小 CORS 头，不经过管理端路由。
 */
declare(strict_types=1);

// 引导文件定位：统一布局（public 的上一级即项目根）优先，旧扁平包（Web 根=后端目录）兜底。
// @ 抑制 open_basedir 越界探测的 Warning。
$wstatBoot = dirname(__DIR__) . '/app/bootstrap.php';
if (!@is_file($wstatBoot)) {
    $wstatBoot = __DIR__ . '/app/bootstrap.php';
}
require $wstatBoot;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// 未安装：不接受采集（返回 503，SDK 端静默忽略）
wstat_guard_installed();

use Wstat\Controllers\CollectController;
use Wstat\Http\Request;

$req = new Request();
(new CollectController())->handle($req);
