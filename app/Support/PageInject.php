<?php
/**
 * 「外部代码」注入器：把管理员在系统设置里粘贴的第三方统计 / 在线客服等脚本，
 * 注入到本站每个页面的 HTML 中（SPA 回退响应）。
 *
 * 语义区分（2026-09-15 澄清）：
 * - inject_code（本类）：进入**本站**页面 DOM 的外部代码，用于统计本站自身访问、挂在线客服等；
 *   仅系统管理员可配置（它在页面上执行任意 JS，权限必须收敛）。
 * - sdk_snippet：给**被统计站点**站长粘贴的接入代码模板，本站从不渲染它，无注入面。
 *
 * 纯函数便于自测；唯一调用点是 server/public/index.php 的 SPA 回退分支。
 */
declare(strict_types=1);

namespace Wstat\Support;

final class PageInject
{
    /**
     * 把 $code 注入 $html 并返回结果。
     * 注入点优先级：</head> 前（第三方统计/客服的标准挂载位置）→ </body> 前 → 文档末尾兜底。
     * $code 为空（未配置）时原样返回。
     */
    public static function apply(string $html, string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return $html;
        }
        $snippet = "<!-- wstat:external-code -->\n" . $code . "\n";
        $p = stripos($html, '</head>');
        if ($p !== false) {
            return substr($html, 0, $p) . $snippet . substr($html, $p);
        }
        $p = stripos($html, '</body>');
        if ($p !== false) {
            return substr($html, 0, $p) . $snippet . substr($html, $p);
        }
        return $html . $snippet;
    }
}
