<?php
/**
 * WebStats 蜘蛛抓取上报端点（站点**服务端**调用：POST/GET 均可）
 *
 * 为什么独立一个入口而不是走 /api：
 *   - 调用方是被统计站点的服务端（也可能是浏览器兜底），需要宽松 CORS 且不需要登录态；
 *   - 鉴权只用站点 key（与 collect.php 同级别），拿到 key 就等于拥有该站点的上报权。
 *
 * 语义：命中爬虫 → 记账并返回 JSON；非爬虫 / 开关关闭 / 表缺失 → 204 无内容。
 *       接入方可以**无条件**在页面入口调用，不需要自己判断响应。
 *
 * 参数：ak=站点 key（必填）、url=被抓取路径或完整 URL、ua=爬虫 UA（缺省取本次请求 UA）
 * 返回：200 {"ok":true,"spider":"Baiduspider"} | 204（静默）
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

// 未安装：不接受上报（返回 503，接入端应当忽略）
wstat_guard_installed();

use Wstat\Controllers\SpiderController;
use Wstat\Http\Request;

$req = new Request();
(new SpiderController())->collect($req);
