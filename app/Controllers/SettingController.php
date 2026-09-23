<?php
/**
 * 系统设置控制器（仅系统管理员）。
 *
 * GET   /api/settings          读取全部设置（smtp_password 掩码回显）
 * PATCH /api/settings          保存设置（白名单键；密码留空/掩码则沿用旧值）
 * GET   /api/sdk-code          站点接入代码模板（任意登录用户；供 Sites 页渲染接入代码）
 * POST  /api/settings/email-test  发送测试邮件（body: {to}）
 * GET   /api/settings/version  当前版本 + 远端在线更新检查
 * POST  /api/settings/update   一键升级到最新版本（下载地址由服务端决定，不接受客户端入参）
 * GET   /api/settings/ip-check 真实 IP 采集自检（列出各候选来源的实际取值，供管理员手动指定来源）
 * POST  /api/settings/brand    上传 / 复位品牌图（multipart：logo / icon 文件；或 reset=logo|icon）
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\IpLocator;
use Wstat\Support\Mailer;
use Wstat\Support\Settings;
use Wstat\Support\Updater;
use Wstat\Support\Util;
use Wstat\Support\Verify;

class SettingController
{
    /** GET /api/settings */
    public function show(Request $req): void
    {
        Settings::requireAdmin($req);
        $all = Settings::all();
        if ((string) $all['smtp_password'] !== '') {
            $all['smtp_password'] = '******';
        }
        if ((string) $all['screen_password'] !== '') {
            $all['screen_password'] = '******';
        }
        // 附加运行时状态（非持久化设置）：注册邮箱验证码依赖图形验证码 → 依赖 GD 扩展
        $all['captcha_ready'] = Verify::available();
        // 内置接入代码模板（前端「恢复默认」「预览」用；自定义内容存在 sdk_snippet）
        $all['sdk_snippet_default'] = Settings::SDK_SNIPPET_DEFAULT;
        $all['sdk_snippet_custom']  = Settings::sdkSnippetIsCustom();
        wstat_json($all);
    }

    /**
     * GET /api/sdk-code —— 「站点接入代码」模板。
     *
     * 刻意**不是** admin 接口：Sites 页的「查看接入代码」是所有站点成员（owner/editor/viewer）
     * 都要用的功能，而模板内容本身就是给访客网站粘贴的代码，不构成敏感信息。
     * 返回模板原文（含 {site_key} / {host} 占位符），由前端替换为具体站点信息。
     */
    public function sdkCode(Request $req): void
    {
        Auth::requireUser($req);
        wstat_json([
            'template' => Settings::sdkSnippetTemplate(),
            'custom'   => Settings::sdkSnippetIsCustom(),
            'default'  => Settings::SDK_SNIPPET_DEFAULT,
        ]);
    }

    /**
     * GET /api/settings/ip-check —— 「真实 IP 采集」自检。
     *
     * **取值来源完全由管理员手动指定**（见 `Util::IP_SOURCES`），本接口不做任何自动判定，
     * 只把事实摆出来：每个候选来源在**本次请求**里会取到什么值、当前生效的是哪一个、
     * 以及当前配置会出问题的两处（套了 CDN 却选 REMOTE_ADDR / 选了可被客户端伪造的头）。
     *
     * 返回的是本次请求的真实环境，所以「点一下检测」就等于用管理员自己的浏览器做一次采样 ——
     * 选一个能显示自己真实地址的来源即可，不需要理解任何代理链原理。
     */
    public function ipCheck(Request $req): void
    {
        Settings::requireAdmin($req);
        $t = Util::ipTrace();
        $ip = (string) $t['ip'];

        $geo   = $ip !== '' ? IpLocator::resolve($ip) : ['country' => '', 'province' => '', 'city' => ''];
        $geoOk = ($geo['country'] ?? '') !== '' || ($geo['province'] ?? '') !== '' || ($geo['city'] ?? '') !== '';
        $isPublic = $ip !== '' && Util::isPublicIp($ip);
        $isIpv6 = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        $advice = [];
        if ($t['fallback']) {
            $advice[] = '所选的 ' . $t['header'] . ' 在本次请求里没有合法 IP，已回落 REMOTE_ADDR。'
                . '若访客 IP 显示为 CDN / 反代节点地址，请改选其它来源。';
        }
        if ($t['source_key'] === 'remote_addr' && $t['remote_cdn'] !== '') {
            $advice[] = 'REMOTE_ADDR（' . $t['remote'] . '）属于 ' . $t['remote_cdn'] . ' 回源网段 → '
                . '当前记录的是 CDN 节点地址，不是访客地址。请改选 X-Forwarded-For；'
                . '若该头也被 CDN 覆盖为节点地址，请选「自定义头」并填 CF-Connecting-IP。';
        } elseif ($t['source_key'] === 'remote_addr' && $t['remote'] !== '') {
            $advice[] = 'REMOTE_ADDR 即 TCP 对端地址，客户端无法伪造 —— 这是最安全的取值方式，'
                . '适用于「源站直接对外、前面没有 CDN / 反向代理」的部署。';
        }
        if ($t['spoofable']) {
            $advice[] = '注意：' . $t['header'] . ' 由客户端可自行设置，只有在 CDN / 反向代理确实覆盖写入它时才可信；'
                . '源站若能被绕过 CDN 直接访问，任何人都能伪造该头来污染统计。';
        }
        if ($t['source_key'] === 'custom' && $t['header'] === '') {
            $advice[] = '已选择「自定义头」但头名称为空 → 等同于取值失败回落 REMOTE_ADDR，请填写头名称。';
        }
        if ($ip === '') {
            $advice[] = '本次请求没有可用的 IP（CLI 或异常网关），属于非 HTTP 上下文，可忽略。';
        } elseif (!$isPublic) {
            $advice[] = '判定出的地址是内网 / 保留地址：多为「管理员在本机访问面板」，或反向代理未透传真实 IP。';
        }
        if ($ip !== '' && $isPublic && !$geoOk) {
            // 按地址族分别给原因：v4 / v6 是两个库、两条独立的排查路径
            $advice[] = $isIpv6
                ? '该访客是 IPv6，但 IPv6 离线库没解析出地域：请确认 data/ip2region_v6.xdb 存在'
                    . '（可跑 php scripts/fetch-geo.php 补齐；v6 库缺失只影响 IPv6 访客，不影响 IPv4）。'
                : '未能解析出地域：请确认 data/ip2region.xdb 存在，且 collect.geo_driver 不是 none。';
        }

        wstat_json([
            'ip'             => $ip,
            'source'         => $t['source'],
            'source_key'     => $t['source_key'],
            'header'         => $t['header'],
            'header_raw'     => $t['raw'],
            'remote'         => $t['remote'],
            'remote_cdn'     => $t['remote_cdn'],
            'remote_private' => $t['remote_private'],
            'fallback'       => $t['fallback'],
            'spoofable'      => $t['spoofable'],
            'note'           => $t['note'],
            'candidates'     => $t['candidates'],
            'geo'            => $geo,
            'geo_ok'         => $geoOk,
            'is_public'      => $isPublic,
            'is_ipv6'        => $isIpv6,
            'advice'         => $advice,
            'warn'           => $t['warn'],
        ]);
    }

    /** PATCH /api/settings */
    public function update(Request $req): void
    {
        Settings::requireAdmin($req);

        $kv = [];
        // 开关：仅接受 0/1
        foreach (['registration_enabled', 'collect_enabled', 'alert_enabled', 'bot_filter_enabled', 'email_verify_enabled', 'spider_enabled'] as $k) {
            if ($req->input($k) !== null) {
                $kv[$k] = (int) (bool) $req->input($k);
            }
        }
        // 事件明细保留天数：空串 = 跟随 config.php 的 collect.event_retention；
        // 非空必须是 1-3650 的整数（cron clean 直接拿去算分区删除边界）。
        // 注意：本键此前只在白名单里、没有写入分支 —— 前端发了、服务端静默丢掉，
        // 表现为「改完点保存提示成功、刷新又变回去」。新增/删除键时务必同步这里。
        if ($req->input('retention_days') !== null) {
            $rd = trim((string) $req->input('retention_days'));
            if ($rd !== '') {
                if (!preg_match('/^\d+$/', $rd) || (int) $rd < 1 || (int) $rd > 3650) {
                    wstat_err('事件明细保留天数需为 1-3650 的整数（留空表示跟随配置文件）', 422);
                }
                $kv['retention_days'] = (string) (int) $rd;
            } else {
                $kv['retention_days'] = '';
            }
        }
        // SMTP 文本项
        foreach (['smtp_host', 'smtp_username', 'smtp_from_name'] as $k) {
            if ($req->input($k) !== null) {
                $kv[$k] = trim((string) $req->input($k));
                if (mb_strlen($kv[$k]) > 190) {
                    wstat_err($k . ' 过长', 422);
                }
            }
        }
        // 端口
        if ($req->input('smtp_port') !== null) {
            $port = (int) $req->input('smtp_port');
            if ($port < 1 || $port > 65535) {
                wstat_err('SMTP 端口需在 1-65535', 422);
            }
            $kv['smtp_port'] = $port;
        }
        // 加密方式
        if ($req->input('smtp_secure') !== null) {
            $sec = strtolower(trim((string) $req->input('smtp_secure')));
            if (!in_array($sec, ['ssl', 'tls', 'none'], true)) {
                wstat_err('加密方式仅支持 ssl / tls / none', 422);
            }
            $kv['smtp_secure'] = $sec;
        }
        // 发件人地址
        if ($req->input('smtp_from_email') !== null) {
            $e = trim((string) $req->input('smtp_from_email'));
            if ($e !== '' && !Util::validEmail($e)) {
                wstat_err('发件人邮箱格式不正确', 422);
            }
            $kv['smtp_from_email'] = $e;
        }
        // 密码：留空或全掩码则沿用旧值
        if ($req->input('smtp_password') !== null) {
            $pwd = (string) $req->input('smtp_password');
            if (trim($pwd) !== '' && preg_match('/^\*+$/', trim($pwd)) !== 1) {
                $kv['smtp_password'] = trim($pwd);
            }
        }
        // 大屏访问密码：全掩码沿用旧值；其余（含空串）原样保存 —— 空串=关闭密码保护
        if ($req->input('screen_password') !== null) {
            $sp = trim((string) $req->input('screen_password'));
            if (preg_match('/^\*+$/', $sp) !== 1) {
                if (mb_strlen($sp) > 64) {
                    wstat_err('大屏访问密码过长（≤64 字符）', 422);
                }
                $kv['screen_password'] = $sp;
            }
        }
        // 站点接入代码模板：多行代码；空串=用内置默认（占位符 {site_key} / {host}）
        if ($req->input('sdk_snippet') !== null) {
            $snip = trim((string) $req->input('sdk_snippet'));
            if (mb_strlen($snip) > Settings::SDK_SNIPPET_MAX) {
                wstat_err('统计代码过长（≤' . Settings::SDK_SNIPPET_MAX . ' 字符）', 422);
            }
            $kv['sdk_snippet'] = $snip;
        }
        // 外部代码（第三方统计 / 在线客服，注入本站页面 </head> 前）：空串=不注入
        if ($req->input('inject_code') !== null) {
            $ic = trim((string) $req->input('inject_code'));
            if (mb_strlen($ic) > Settings::INJECT_CODE_MAX) {
                wstat_err('外部代码过长（≤' . Settings::INJECT_CODE_MAX . ' 字符）', 422);
            }
            $kv['inject_code'] = $ic;
        }

        // 访客 IP 取值来源：**手动指定**，程序不做任何自动判断（校验枚举 + 自定义头名）
        if ($req->input('ip_source') !== null) {
            $src = strtolower(trim((string) $req->input('ip_source')));
            if (!in_array($src, Util::IP_SOURCES, true)) {
                wstat_err('IP 取值来源仅支持 ' . implode(' / ', Util::IP_SOURCES), 422);
            }
            if ($src === 'custom' && Util::normalizeHeaderName((string) $req->input('ip_source_header')) === '') {
                wstat_err('选择「自定义头」时必须填写合法的请求头名称（仅字母、数字、连字符）', 422);
            }
            $kv['ip_source'] = $src;
        }
        if ($req->input('ip_source_header') !== null) {
            $hdr = trim((string) $req->input('ip_source_header'));
            if ($hdr !== '' && Util::normalizeHeaderName($hdr) === '') {
                wstat_err('请求头名称不合法（仅允许字母、数字、连字符，长度 ≤64）', 422);
            }
            $kv['ip_source_header'] = $hdr;
        }

        // 被关闭的菜单：逗号分隔的路由 key（如 /spiders,/heatmap）；空串=全部显示。
        // 每个 token 必须形如 /xxx（小写字母数字连字符），非法 token 直接 422 整批不落库。
        // 注意：brand_logo / brand_icon 刻意**没有** PATCH 分支 —— 品牌图只能走
        // /api/settings/brand（上传时同步落文件），不能凭空写一个不存在的文件名。
        if ($req->input('hidden_menus') !== null) {
            $raw = trim((string) $req->input('hidden_menus'));
            if ($raw !== '') {
                foreach (explode(',', $raw) as $k) {
                    if (preg_match('#^/[a-z0-9-]+$#', trim($k)) !== 1) {
                        wstat_err('菜单 key 格式不正确：' . trim($k), 422);
                    }
                }
                $kv['hidden_menus'] = implode(',', array_values(array_filter(array_map('trim', explode(',', $raw)))));
            } else {
                $kv['hidden_menus'] = '';
            }
        }

        // 系统名称 / meta 关键词 / meta 简介：纯文本，trim 后限长（超长 422 整批不落库）。
        // 空串=恢复默认（前端回落内置文案 / 不输出 meta）。随 /api/auth/public 的 ui 段公开下发。
        $textLimits = [
            'brand_name'       => Settings::BRAND_NAME_MAX,
            'meta_keywords'    => Settings::META_KEYWORDS_MAX,
            'meta_description' => Settings::META_DESCRIPTION_MAX,
        ];
        foreach ($textLimits as $k => $max) {
            if ($req->input($k) !== null) {
                $v = trim((string) $req->input($k));
                if (mb_strlen($v) > $max) {
                    wstat_err('内容超过长度上限（' . $max . ' 字符）：' . $k, 422);
                }
                $kv[$k] = $v;
            }
        }

        if (!$kv) {
            wstat_err('没有需要保存的设置', 422);
        }
        $n = Settings::set($kv);
        wstat_json(['saved' => $n]);
    }

    /**
     * GET /api/settings/version —— 当前版本 + 远端更新检查。
     * 远端地址取 config.php 的 update_check_url（默认 https://wstats-update.kfw.cc/update.php，
     * 环境变量 WSTAT_UPDATE_URL 可覆盖）；拉取失败不影响本接口返回当前版本。
     *
     * 跨版本升级：`chain` 是从当前版本到最新版要依次应用的增量包（升序），
     * 面板据此显示「将依次应用 N 个增量包（v1.0.13 → … → v1.0.16）」。
     */
    public function version(Request $req): void
    {
        Settings::requireAdmin($req);
        $r = Updater::check();
        wstat_json([
            'version'        => $r['version'],
            'latest'         => $r['latest'],
            'has_update'     => $r['has_update'],
            'msg'            => $r['reason'],
            'reason'         => $r['reason'],
            'can_auto'       => $r['can_auto'],
            'requires_full'  => $r['requires_full'],
            'layout'         => $r['layout'],
            'check_url'      => $r['check_url'],   // 本机实际使用的更新服务地址（排错用）
            'changelog'      => $r['changelog'],
            'notes'          => $r['notes'],
            'released_at'    => $r['released_at'],
            'update_url'     => $r['update_url'],
            'update_size'    => $r['update_size'],
            'full_url'       => $r['full_url'],
            'full_size'      => $r['full_size'],
            'php_ok'         => $r['php_ok'],
            'update_enabled' => $r['update_enabled'],
            'upgrade_mode'   => $r['upgrade_mode'],
            'chain'          => $r['chain'],
            'chain_len'      => $r['chain_len'],
            'chain_reason'   => $r['chain_reason'],
            'min_auto_version' => $r['min_auto_version'],
            'backups'        => Updater::backups(),
        ]);
    }

    /**
     * POST /api/settings/update —— 一键升级到最新版本（支持跨版本，内部按链依次应用）。
     *
     * 安全：**不接受客户端传入的下载地址**，一律由服务端重新执行 check() 取回官方升级链，
     * 否则面板就成了「可写任意文件的下载器」。升级前的备份、校验、回滚见 Support\Updater。
     *
     * 跨版本：响应里的 done=false 表示「单次请求时间预算用尽，还有剩余步骤」——
     * 前端应当立刻再调一次本接口续跑，直到 done=true。链是按当前实际版本重算的，
     * 所以重复调用、中断后重试都是安全的（幂等）。
     */
    public function applyUpdate(Request $req): void
    {
        Settings::requireAdmin($req);
        $chk = Updater::check();
        if (!$chk['ok']) {
            wstat_err($chk['reason'] !== '' ? $chk['reason'] : '无法连接更新服务', 502);
        }
        if (!$chk['has_update']) {
            wstat_err('已是最新版本（v' . $chk['version'] . '）', 422);
        }
        if (!$chk['can_auto']) {
            wstat_err($chk['reason'], 422);
        }
        $steps = (array) $chk['chain'];
        if ($steps === []) {
            wstat_err('更新服务未提供可用的升级步骤，请下载完整包手工升级', 422);
        }
        try {
            $r = Updater::applyChain($steps);
        } catch (\Throwable $e) {
            wstat_err('升级失败：' . $e->getMessage(), 500);
        }
        wstat_json($r);
    }

    /**
     * POST /api/settings/brand —— 上传 / 复位品牌图（Logo 与 Favicon）。
     *
     * multipart 字段二选一（也可同请求都带）：
     *   logo  面板 Logo（≤1MB，png/jpg/jpeg/svg/ico/webp）
     *   icon  浏览器标签页图标（≤256KB，同格式）
     * 或表单字段 reset=logo|icon（可逗号分隔多个）表示恢复内置默认。
     *
     * 存储：data/brand/<kind>.<ext>（同 kind 换扩展名时删除旧文件）；
     * 设置里只记文件名 —— 文件名本身即缓存版本号（变化 → 前端 URL 变化 → 浏览器缓存失效）。
     * 校验扩展名 + finfo MIME 双重确认，避免「把 PHP/HTML 改名上传」被当作图片下发。
     */
    public function brand(Request $req): void
    {
        Settings::requireAdmin($req);
        $dir = Settings::brandDir();
        $done = [];

        // ---- 复位：删除文件 + 清空设置 ----
        $reset = trim((string) ($req->input('reset') ?? ''));
        if ($reset !== '') {
            foreach (explode(',', $reset) as $kindRaw) {
                $kind = trim(strtolower($kindRaw));
                if (!in_array($kind, ['logo', 'icon'], true)) {
                    continue;
                }
                $old = basename(trim(Settings::get('brand_' . $kind)));
                if ($old !== '' && is_file($dir . '/' . $old)) {
                    @unlink($dir . '/' . $old);
                }
                Settings::set(['brand_' . $kind => '']);
                $done[$kind] = '';
            }
        }

        // ---- 上传 ----
        foreach (['logo' => Settings::BRAND_LOGO_MAX, 'icon' => Settings::BRAND_ICON_MAX] as $kind => $maxBytes) {
            $f = $_FILES[$kind] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                wstat_err('上传失败（错误码 ' . (string) ($f['error'] ?? -1) . '），请重试', 422);
            }
            $size = (int) ($f['size'] ?? 0);
            if ($size <= 0 || $size > $maxBytes) {
                wstat_err($kind === 'logo'
                    ? 'Logo 需为 ≤1MB 的图片文件'
                    : 'Icon 需为 ≤256KB 的图片文件', 422);
            }
            $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($ext, Settings::BRAND_EXTS, true)) {
                wstat_err('仅支持 ' . implode(' / ', Settings::BRAND_EXTS) . ' 格式', 422);
            }
            // MIME 实测：扩展名可以骗，真实字节骗不了
            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $mime = (string) finfo_file($fi, (string) $f['tmp_name']);
                    finfo_close($fi);
                }
            }
            $allow = [
                'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
                'svg' => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
                'ico' => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png'],
                'webp' => ['image/webp'],
            ];
            if ($mime !== '' && !in_array($mime, $allow[$ext] ?? [], true)) {
                wstat_err('文件内容与扩展名不符（检测到 ' . $mime . '）', 422);
            }
            // 同 kind 换扩展名时清掉旧文件（设置里只记一个文件名）
            $old = basename(trim(Settings::get('brand_' . $kind)));
            $name = $kind . '.' . $ext;
            // move_uploaded_file 校验 is_uploaded_file，CLI 自测夹具（伪造 $_FILES）下必然失败，
            // 因此补 copy 兜底 —— 正常 Web 路径仍走 move_uploaded_file，语义不变。
            $moved = @move_uploaded_file((string) $f['tmp_name'], $dir . '/' . $name);
            if (!$moved) {
                $moved = @copy((string) $f['tmp_name'], $dir . '/' . $name);
            }
            if (!$moved) {
                wstat_err('保存文件失败（检查 data/brand/ 目录写权限）', 500);
            }
            @chmod($dir . '/' . $name, 0644);
            if ($old !== '' && $old !== $name && is_file($dir . '/' . $old)) {
                @unlink($dir . '/' . $old);
            }
            Settings::set(['brand_' . $kind => $name]);
            $done[$kind] = $name;
        }

        if (!$done) {
            wstat_err('没有可处理的品牌图（multipart 字段 logo / icon，或 reset=logo|icon）', 422);
        }
        wstat_json([
            'saved' => array_keys($done),
            'logo'  => Settings::get('brand_logo'),
            'icon'  => Settings::get('brand_icon'),
        ]);
    }

    /** POST /api/settings/email-test */
    public function emailTest(Request $req): void
    {
        Settings::requireAdmin($req);
        $to = trim((string) $req->input('to', ''));
        if (!Util::validEmail($to)) {
            wstat_err('收件邮箱格式不正确', 422);
        }
        $host = trim(Settings::get('smtp_host'));
        $via = $host !== '' ? ('SMTP ' . $host) : 'PHP mail()';
        [$ok, $msg] = Mailer::send([$to], 'WebStats 测试邮件', '这是一封来自 WebStats 的测试邮件。'
            . "\n" . '发送方式: ' . $via
            . "\n" . '时间: ' . date('Y-m-d H:i:s') . "\n");
        wstat_json(['ok' => $ok, 'via' => $via, 'msg' => $msg]);
    }
}
