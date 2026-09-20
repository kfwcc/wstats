<?php
/**
 * 品牌图公开下发（无需登录）：GET /brand/logo、GET /brand/icon。
 *
 * - 文件存 data/brand/（升级永不覆盖的持久目录），文件名记录在 sys_settings
 *   的 brand_logo / brand_icon（空=未设置 → 404，前端回落内置默认图标）；
 * - 路径在 index.php 的 SPA 回退**之前**分流（图片不是前端路由）；
 * - 安全：realpath 限定在 brand 目录内；Content-Type 按扩展名给出并禁用嗅探；
 *   SVG 里可能内嵌脚本，统一加 `Content-Security-Policy: default-src 'none'`，
 *   即使被直接打开也不会执行任何脚本（本就只应作为 <img> 引用）；
 * - 缓存：文件名即版本（上传/复位会改变设置里的文件名，前端 URL 随之变化），
 *   因此可以放心给长缓存；Etag/304 兜底同文件名的修改。
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Settings;

class BrandController
{
    public function logo(Request $req): void
    {
        $this->serve('brand_logo');
    }

    public function icon(Request $req): void
    {
        $this->serve('brand_icon');
    }

    private function serve(string $key): void
    {
        $name = basename(trim(Settings::get($key)));
        $dir = Settings::brandDir();
        $path = $name !== '' ? $dir . '/' . $name : '';
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'not set';
            exit(0);
        }
        // 越界兜底（文件名来自系统设置，正常不可能越界；防御性校验）
        $real = realpath($path);
        if ($real === false || strpos($real, realpath($dir) . DIRECTORY_SEPARATOR) !== 0) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'not set';
            exit(0);
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $types = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'webp' => 'image/webp',
        ];
        $mime = $types[$ext] ?? 'application/octet-stream';

        $mtime = (int) @filemtime($real);
        $size = (int) @filesize($real);
        $etag = '"' . md5($name . '|' . $mtime . '|' . $size) . '"';

        header('Content-Type: ' . $mime);
        // 显式声明 200：CLI（自测夹具）下不调用 http_response_code 时读不到状态码
        http_response_code(200);
        header('X-Content-Type-Options: nosniff');
        // SVG 可能内嵌脚本：一律禁止其中任何外部资源/脚本（只作为图片引用是安全的）
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: no-cache');
        header('ETag: ' . $etag);

        $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && $inm === $etag) {
            http_response_code(304);
            exit(0);
        }

        header('Content-Length: ' . (string) $size);
        $fp = @fopen($real, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit(0);
        }
        fpassthru($fp);
        fclose($fp);
        exit(0);
    }
}
