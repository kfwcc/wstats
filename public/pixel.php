<?php
/**
 * WebStats 图片信标入口（1×1 GIF 图片统计）
 *
 * 典型用法（邮件 / EDM 打开率、第三方页面埋点、AMP、无 JS 环境兜底）：
 *   <img src="https://<统计域名>/pixel.php?ak=<站点key>&url=<页面地址>&ref=<来源>"
 *        width="1" height="1" alt="" style="display:none">
 *
 * 与 /collect.php 的关系：完全复用同一个采集控制器（同一套参数、同一套口径与限流），
 * 唯一差别是响应体 —— 这里恒为 1×1 透明 GIF（成功 / 失败 / 未安装都回 GIF），
 * 保证图片在任何情况下都能渲染，且不向访问者泄露「站点是否启用 / 是否已验证」这类采集状态。
 *
 * 能力边界（图片模式是**降级接入**，接之前请知悉）：
 *   · 拿不到屏幕分辨率 / 语言 / 停留时长 / 滚动 / SPA 路由 / 自动事件（出链·下载·站内搜索）；
 *   · `url` 必填：留空则 events.url 为空串，页面明细 / 入口页 / 退出页都不归位；
 *   · `uid` / `sid` 必须自备 —— 服务端既不生成、也不做 UA+IP+日期 近似：
 *       缺 `uid` → events.visitor_id 为空串，而 UV 口径是 COUNT(DISTINCT visitor_id)
 *                  （MySQL 忽略 NULL，但**空串算作一个值**）→ 当天全部图片请求被算成
 *                  同一个访客，UV 恒为 1；
 *       缺 `sid` → worker 的 sessionize 要求 session_id 非空，直接跳过 → **不产生会话**，
 *                  会话数 / 停留时长 / 跳出率 / 入口退出页全缺，实时在线也不计入。
 *     两者合法性均为 8–40 位 `[A-Za-z0-9_-]`（不合法即被丢弃成空串）。邮件场景请用发送系统
 *     能替换的访客标识（如收件人 ID 的哈希）填 `uid`，同一次投递共用同一个 `sid`。
 *   · 事件信息要靠 URL 拼参数（t=event&d[n]=... 仍可用，但仅限服务端能拿到的信息）。
 * 需要完整口径（会话时长、滚动、热力图等）请仍用 SDK：/sdk/wstat.js。
 */
declare(strict_types=1);

// 引导定位：统一布局（public 上一级即包根）优先，旧扁平包兜底；@ 抑制 open_basedir 越界告警。
$wstatBoot = dirname(__DIR__) . '/app/bootstrap.php';
if (!@is_file($wstatBoot)) {
    $wstatBoot = __DIR__ . '/app/bootstrap.php';
}
$booted = false;
if (@is_file($wstatBoot)) {
    require_once $wstatBoot;
    $booted = true;
}

/**
 * 输出 1×1 透明 GIF 后结束。
 * 优先取控制器的常量（单一来源）；引导不可用时退回同值字面量 ——
 * 此时类加载器不可用，但「图片必须能渲染」的承诺不能打折。
 */
$emit = static function (): void {
    $b64 = class_exists(\Wstat\Controllers\CollectController::class)
        ? \Wstat\Controllers\CollectController::PIXEL_GIF_B64
        : 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: image/gif');
        // 信标必须每次真实回源：被缓存住就等于丢掉后续打开/点击的计数
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
    echo base64_decode($b64);
};

// 未安装 / 引导不可用：仍回 GIF（不计采集），不暴露服务端状态
if (!$booted || !wstat_installed()) {
    $emit();
    exit(0);
}

(new Wstat\Controllers\CollectController('gif'))->handle(new Wstat\Http\Request());
