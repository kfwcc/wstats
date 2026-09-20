<?php
/**
 * WebStats 入口：管理端 API + 前端单页回退 + 未安装引导
 *
 * Nginx 建议：
 *   location / { try_files $uri $uri/ /index.php$is_args$args; }   # 非 API 也交给这里
 *   location ^~ /api/ { try_files $uri /index.php$is_args$args; }
 * 这样「未安装时访问首页」会由服务端直接 302 到安装向导，无需依赖前端 JS。
 */
declare(strict_types=1);

use Wstat\Controllers\AlertController;
use Wstat\Controllers\AuthController;
use Wstat\Controllers\BrandController;
use Wstat\Controllers\CollectController;
use Wstat\Controllers\ExportController;
use Wstat\Controllers\OpenController;
use Wstat\Controllers\ProfileController;
use Wstat\Controllers\SiteController;
use Wstat\Controllers\SettingController;
use Wstat\Controllers\SpiderController;
use Wstat\Controllers\StatsController;
use Wstat\Controllers\TokenController;
use Wstat\Controllers\UserController;
use Wstat\Http\Request;
use Wstat\Http\Router;
use Wstat\Support\PageInject;
use Wstat\Support\Settings;

// 引导文件定位：
//   统一布局（v1.0.1+，发布包默认）：index.php 在 <项目根>/public/，引导在 <项目根>/app/（dirname(__DIR__)）；
//   旧扁平包（≤1.0.0 宝塔版）：Web 根=后端目录本身，引导在同目录 app/ 下（兜底分支）。
// @ 抑制 open_basedir 越界探测的 Warning（上级目录常被面板禁访）。
$wstatBoot = dirname(__DIR__) . '/app/bootstrap.php';
if (!@is_file($wstatBoot)) {
    $wstatBoot = __DIR__ . '/app/bootstrap.php';
}
// require_once：开发服 router.php 对非 API 路径（如 /brand/*）已先 require_once 引导做安装检查，
// 这里若用 require 会二次声明 wstat_config() 直接 Fatal。引导文件本就只该加载一次。
require_once $wstatBoot;

/* ---------- 非 API 请求：未安装引导 + 前端单页回退 ---------- */
$wstatPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$wstatPath = is_string($wstatPath) && $wstatPath !== '' ? $wstatPath : '/';

if (strpos($wstatPath, '/api') !== 0 && strpos($wstatPath, '/open') !== 0) {
    // ---- 品牌图公开下发（/brand/logo、/brand/icon）：必须先于 SPA 回退分流 ----
    // 否则会被下面的前端单页回退当成路由、返回 index.html。未设置时 BrandController 返回 404，
    // 前端据此回落内置默认图标。
    if ($wstatPath === '/brand/logo' || $wstatPath === '/brand/icon') {
        wstat_guard_installed();
        $brand = new BrandController();
        if ($wstatPath === '/brand/logo') {
            $brand->logo(new Request());
        } else {
            $brand->icon(new Request());
        }
        exit(0);
    }
    if (!wstat_installed()) {
        // 安装向导本身不可用时给出明确说明，避免与「首页→向导」互相跳转成死循环
        if (strpos($wstatPath, '/install') === 0) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "未找到安装向导（public/install/index.php）。\n"
                . "请上传发布包中的 install 目录（统一布局为 public/install）后重试；\n"
                . "若已安装完成，请确认 data/install.lock 存在（或 data/installed.php 已填好库信息）。\n";
            exit(0);
        }
        header('Location: ' . wstat_install_url(), true, 302);
        exit(0);
    }
    $wstatIndex = __DIR__ . '/index.html';
    if (is_file($wstatIndex)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        // 外部代码注入（第三方统计 / 在线客服，管理员在系统设置配置）：仅作用于本站页面 HTML。
        // 注意：nginx 需保持 index index.php index.html（index.php 在前），否则 / 会被静态
        // index.html 直接命中、绕过本注入；SPA 内部路由全部经此回退，不受影响。
        $wstatHtml = (string) file_get_contents($wstatIndex);
        echo PageInject::apply($wstatHtml, Settings::get('inject_code'));
        exit(0);
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "未找到前端产物 index.html。\n请确认站点根目录指向 <包根>/public（含前端构建产物），详见 docs/deploy.md。\n";
    exit(0);
}

// 未安装：返回 503 + 安装引导（前端据此跳转安装向导），避免直接抛出 500 让部署者无从下手
wstat_guard_installed();

$router = new Router();
$router->group('/api', function (Router $r) {
    // ---- 健康检查（无需鉴权；未安装时上面已 503 引导安装）----
    $r->get('/health', function () {
        wstat_json([
            'installed' => true,
            'version'   => (string) wstat_config('version'),
            'time'      => date('c'),
        ]);
    });

    // ---- 采集上报：备用通道（参数与 /collect.php 完全一致） ----
    // 官方接入仍用 /collect.php；此通道供「.php 路径被 CDN / WAF 拦截」或伪静态不便修改的环境使用，
    // 因为它同样经过 nginx 的 location ^~ /api/ → index.php，无需单独放行 .php。
    $r->post('/collect', [new CollectController(), 'handle']);

    // ---- 认证 ----
    $auth = new AuthController();
    $r->get('/auth/public', [$auth, 'publicSettings']);   // 公共开关（登录/注册页用，无需登录）
    $r->get('/auth/captcha', [$auth, 'captcha']);         // 图形验证码（注册取邮箱验证码前置）
    $r->post('/auth/email-code', [$auth, 'emailCode']);   // 发送注册邮箱验证码（需先过图形验证码）
    $r->post('/auth/register', [$auth, 'register']);
    // ---- 忘记密码：取码 + 凭码重置（均无需登录） ----
    $r->post('/auth/reset-code', [$auth, 'resetCode']);   // 发送重置密码验证码（邮箱须已注册）
    $r->post('/auth/reset-password', [$auth, 'resetPassword']);
    $r->post('/auth/login', [$auth, 'login']);
    $r->get('/auth/me', [$auth, 'me']);
    $r->post('/auth/logout', [$auth, 'logout']);

    // ---- 个人中心（登录态即可） ----
    $pf = new ProfileController();
    $r->get('/profile', [$pf, 'show']);
    $r->patch('/profile', [$pf, 'update']);
    $r->post('/profile/password', [$pf, 'password']);
    $r->get('/profile/logs', [$pf, 'logs']);

    // ---- 用户管理（仅系统管理员） ----
    $us = new UserController();
    $r->get('/users', [$us, 'index']);
    $r->post('/users', [$us, 'store']);
    $r->patch('/users/{id}', [$us, 'update']);
    $r->post('/users/{id}/status', [$us, 'status']);
    $r->delete('/users/{id}', [$us, 'destroy']);
    $r->get('/users/{id}/sites', [$us, 'sites']);

    // ---- 系统设置（仅系统管理员） ----
    $sys = new SettingController();
    $r->get('/settings', [$sys, 'show']);
    $r->patch('/settings', [$sys, 'update']);
    $r->post('/settings/email-test', [$sys, 'emailTest']);
    // 品牌图上传 / 复位（multipart；文件落 data/brand/，见 SettingController::brand()）
    $r->post('/settings/brand', [$sys, 'brand']);
    $r->get('/settings/version', [$sys, 'version']);
    $r->post('/settings/update', [$sys, 'applyUpdate']);   // 一键升级（地址由服务端决定）
    // 真实 IP 采集自检（套了 CDN / 反代后访客 IP 不对时用来定位，见控制器注释）
    $r->get('/settings/ip-check', [$sys, 'ipCheck']);
    // 接入代码模板：非 admin —— 站点成员在 Sites 页都要看（模板本身不敏感，见控制器注释）
    $r->get('/sdk-code', [$sys, 'sdkCode']);

    // ---- 站点 ----
    $site = new SiteController();
    $r->get('/sites', [$site, 'index']);
    $r->post('/sites', [$site, 'store']);
    $r->get('/sites/{id}', [$site, 'show']);
    $r->patch('/sites/{id}', [$site, 'update']);
    $r->delete('/sites/{id}', [$site, 'destroy']);
    $r->get('/sites/{id}/verify-file', [$site, 'verifyFile']);
    $r->post('/sites/{id}/verify', [$site, 'verify']);
    $r->get('/sites/{id}/sdk-check', [$site, 'sdkCheck']);
    // ---- 站点协作成员（仅所有者可管理） ----
    $r->get('/sites/{id}/members', [$site, 'members']);
    $r->post('/sites/{id}/members', [$site, 'memberAdd']);
    $r->patch('/sites/{id}/members/{mid}', [$site, 'memberUpdate']);
    $r->delete('/sites/{id}/members/{mid}', [$site, 'memberRemove']);
    // ---- 我被授权的站点（只读/可编辑协作） ----
    $r->get('/members/sites', [$site, 'sharedWithMe']);

    // ---- 统计 ----
    $st = new StatsController();
    $r->get('/stats/overview', [$st, 'overview']);
    $r->get('/stats/sources', [$st, 'sources']);
    $r->get('/stats/engines', [$st, 'engines']);   // 搜索引擎统计（引擎/趋势/落地页/关键词）
    $r->get('/stats/sessions', [$st, 'sessions']);
    $r->get('/stats/sessions/{id}', [$st, 'sessionDetail']);
    $r->get('/stats/iptrace', [$st, 'iptrace']);

    // ---- 蜘蛛爬虫统计（只读；记账走 /spider.php 与采集端，与访客口径完全隔离） ----
    $sp = new SpiderController();
    $r->get('/stats/spiders', [$sp, 'stats']);

    $r->get('/stats/online', [$st, 'online']);
    $r->get('/stats/all-sites', [$st, 'allSites']);
    $r->get('/stats/auto-events', [$st, 'autoEvents']);
    $r->get('/stats/performance', [$st, 'performance']);
    $r->get('/stats/realtime', [$st, 'realtime']);
    $r->get('/stats/geo', [$st, 'geo']);
    $r->get('/stats/analysis', [$st, 'analysis']);
    $r->get('/stats/page', [$st, 'pageDetail']);
    $r->get('/stats/audience', [$st, 'audience']);
    $r->get('/stats/journey', [$st, 'journey']);
    $r->get('/stats/heatmap', [$st, 'heatmap']);
    $r->get('/stats/ads', [$st, 'ads']);
    $r->get('/stats/funnels', [$st, 'funnels']);
    $r->post('/stats/funnels', [$st, 'funnelSave']);
    $r->patch('/stats/funnels/{id}', [$st, 'funnelUpdate']);
    $r->delete('/stats/funnels/{id}', [$st, 'funnelDelete']);
    $r->get('/stats/funnels/{id}/data', [$st, 'funnelData']);
    $r->get('/stats/goals', [$st, 'goals']);
    $r->post('/stats/goals', [$st, 'goalSave']);
    $r->patch('/stats/goals/{id}', [$st, 'goalUpdate']);
    $r->delete('/stats/goals/{id}', [$st, 'goalDelete']);
    $r->get('/stats/goals/{id}/data', [$st, 'goalData']);
    $r->get('/screen/token', [$st, 'screenToken']);
    $r->get('/screen/data', [$st, 'screenData']);

    // ---- 数据导出（CSV；只读权限即可） ----
    $ex = new ExportController();
    $r->get('/export/{kind}', [$ex, 'handle']);

    // ---- 流量异常告警 / 日报推送 ----
    // 渠道 / 规则 / 订阅均为「按用户」的个人配置；规则绑定站点时要求至少可编辑（编辑器/所有者）。
    $al = new AlertController();
    $r->get('/alerts/summary', [$al, 'summary']);
    $r->get('/alerts/channels', [$al, 'channelIndex']);
    $r->post('/alerts/channels', [$al, 'channelStore']);
    $r->patch('/alerts/channels/{id}', [$al, 'channelUpdate']);
    $r->delete('/alerts/channels/{id}', [$al, 'channelDelete']);
    $r->post('/alerts/channels/{id}/test', [$al, 'channelTest']);
    $r->get('/alerts/rules', [$al, 'ruleIndex']);
    $r->post('/alerts/rules', [$al, 'ruleStore']);
    $r->patch('/alerts/rules/{id}', [$al, 'ruleUpdate']);
    $r->delete('/alerts/rules/{id}', [$al, 'ruleDelete']);
    $r->get('/alerts/logs', [$al, 'logs']);
    $r->get('/alerts/reports', [$al, 'reportIndex']);
    $r->post('/alerts/reports', [$al, 'reportStore']);
    $r->patch('/alerts/reports/{id}', [$al, 'reportUpdate']);
    $r->delete('/alerts/reports/{id}', [$al, 'reportDelete']);

    // ---- 开放 API 令牌管理（登录态；令牌仅本人可见可用） ----
    $tk = new TokenController();
    $r->get('/tokens', [$tk, 'index']);
    $r->post('/tokens', [$tk, 'store']);
    $r->delete('/tokens/{id}', [$tk, 'destroy']);
});

// ---- 开放 API（PAT 鉴权，只读白名单；独立于 /api 前缀便于第三方接入） ----
$open = new OpenController();
$router->group('/open/v1', function (Router $r) use ($open) {
    $r->get('/overview', [$open, 'overview']);
    $r->get('/pages', [$open, 'pages']);
    $r->get('/online', [$open, 'online']);
});

$req = new Request();
$router->dispatch($req);
