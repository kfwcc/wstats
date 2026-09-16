<?php
/**
 * 认证控制器：注册 / 登录 / 当前用户 / 退出
 */
declare(strict_types=1);

namespace Wstat\Controllers;

use Wstat\Http\Request;
use Wstat\Support\Auth;
use Wstat\Support\Db;
use Wstat\Support\OpLog;
use Wstat\Support\Rds;
use Wstat\Support\Settings;
use Wstat\Support\Util;
use Wstat\Support\Verify;

class AuthController
{
    /** POST /api/auth/register */
    public function register(Request $req): void
    {
        // 管理员可全局关闭注册（系统设置 → 功能开关）
        if (!Settings::int('registration_enabled')) {
            wstat_err('注册已关闭，请联系管理员', 403, 403);
        }
        $email = trim((string) $req->input('email', ''));
        $pass = (string) $req->input('password', '');
        $nickname = trim((string) $req->input('nickname', ''));
        $emailCode = trim((string) $req->input('email_code', ''));
        $lang = $req->input('lang', 'zh-CN');
        if (!in_array($lang, ['zh-CN', 'en-US'], true)) {
            $lang = 'zh-CN';
        }
        $min = (int) wstat_config('security.pwd_min');

        if (!Util::validEmail($email)) {
            wstat_err('邮箱格式不正确', 422);
        }
        if (strlen($pass) < $min || strlen($pass) > 72) {
            wstat_err('密码长度需 ' . $min . '-72 位', 422);
        }
        if (mb_strlen($nickname) > 30) {
            wstat_err('昵称过长', 422);
        }
        if (Db::value('SELECT id FROM users WHERE email=? LIMIT 1', [$email]) !== null) {
            wstat_err('该邮箱已注册', 409);
        }
        // 邮箱验证码放最后校验（消费型）：前面任一步失败都不烧码
        if (Settings::int('email_verify_enabled') && !Verify::emailCheck($email, $emailCode)) {
            wstat_err('邮箱验证码错误或已失效，请重新获取', 422, 4223);
        }

        $now = time();
        // 首个注册账号默认为系统管理员：与安装向导（Installer::createAdmin）和
        // sql/upgrade-2026-09-12-settings.sql 的「MIN(id) 即管理员」语义保持一致。
        // 老库若无 is_admin 列，filterColumns 会丢弃该键，Settings::isAdmin 自动回退 MIN(id)。
        $isFirstUser = (int) Db::value('SELECT COUNT(*) FROM users') === 0;
        $row = [
            'email' => $email,
            'password' => password_hash($pass, PASSWORD_DEFAULT),
            'nickname' => $nickname !== '' ? $nickname : explode('@', $email)[0],
            'lang' => $lang,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($isFirstUser) {
            $row['is_admin'] = 1;
        }
        $id = Db::insert('users', Db::filterColumns('users', $row));
        wstat_json([
            'token' => Auth::issue((int) $id),
            'user' => self::shape((int) $id),
        ]);
    }

    /** POST /api/auth/login */
    public function login(Request $req): void
    {
        $email = trim((string) $req->input('email', ''));
        $pass = (string) $req->input('password', '');
        $ip = Util::clientIp();

        // 简易限流（Redis 不可用时放行，不阻断登录）
        $allowed = Rds::safe(function ($r) use ($ip) {
            $k = 'login:' . $ip;
            $n = $r->incr($k);
            if ($n === 1) {
                $r->expire($k, 600);
            }
            return $n <= (int) wstat_config('security.login_rate');
        }, true);
        if (!$allowed) {
            wstat_err('尝试过于频繁，请10分钟后再试', 429);
        }

        $u = Db::first('SELECT * FROM users WHERE email=? LIMIT 1', [$email]);
        if ($u === null || !password_verify($pass, (string) $u['password'])) {
            wstat_err('邮箱或密码错误', 401, 401);
        }
        if ((int) $u['status'] !== 1) {
            wstat_err('账号已被禁用', 403, 403);
        }
        OpLog::log((int) $u['id'], 'login');
        wstat_json(['token' => Auth::issue((int) $u['id']), 'user' => self::shape((int) $u['id'])]);
    }

    /** GET /api/auth/public（无需登录：给登录/注册页用的公共开关） */
    public function publicSettings(Request $req): void
    {
        wstat_json([
            'registration_enabled' => Settings::int('registration_enabled') === 1,
            // 注册是否需要邮箱验证码（开启时注册页会显示取码流程）
            'email_verify_enabled' => Settings::int('email_verify_enabled') === 1,
            // 服务器是否具备生成图形验证码的能力（缺 GD 时前端给出明确提示而非静默卡死）
            'captcha_ready' => Verify::available(),
            // 运行期版本号（页脚「Powered by Wstats · vX.Y.Z」里的版本片段）。
            // 来源 = app/version.php（经 config.php 读出），**不是** installed.php 的安装快照：
            // 后者不会随手工升级刷新，曾导致页脚永远显示安装那一版、更新检查永远提示有新版本。
            // 这里不给它兜底成 '1.0.0' —— 未知就如实返回（最坏是 0.0.0），
            // 假版本号比空值更坏：它会让页脚与更新检查同时说谎。
            // 品牌前缀/名称/官网链接由前端写死（frontend/src/components/PoweredBy.jsx），服务端不下发。
            'version' => (string) wstat_config('version'),
        ]);
    }

    /**
     * GET /api/auth/captcha —— 图形验证码（取邮箱验证码的前置人机校验）。
     * 返回 {id, image(dataURL), ttl}；图与答案都在服务端，id 仅是取答案的凭证。
     */
    public function captcha(Request $req): void
    {
        if (!Verify::available()) {
            wstat_err('服务器未启用 GD 扩展，无法生成图形验证码', 500, 500);
        }
        if (!Verify::captchaAllow(Util::clientIp())) {
            wstat_err('请求过于频繁，请稍后再试', 429, 429);
        }
        wstat_json(Verify::captchaIssue());
    }

    /**
     * POST /api/auth/email-code —— 发送注册邮箱验证码。
     * body: {email, captcha_id, captcha, lang?}；先过图形验证码（一次性），再按配额发信。
     */
    public function emailCode(Request $req): void
    {
        $this->sendCode($req, 'register');
    }

    /**
     * POST /api/auth/reset-code —— 发送「重置密码」邮箱验证码。
     * 与注册取码的差异：
     *   - 不看 registration_enabled（注册可关闭，但已有账号必须能自助找回密码）；
     *   - 邮箱必须已注册且账号未被禁用；
     *   - 邮箱验证码是重置的唯一凭证，不受 email_verify_enabled 开关影响，恒定启用。
     * body: {email, captcha_id, captcha, lang?}
     */
    public function resetCode(Request $req): void
    {
        $this->sendCode($req, 'reset');
    }

    /**
     * POST /api/auth/reset-password —— 凭邮箱验证码重置密码。
     * body: {email, code, password}
     * 成功后吊销该用户全部登录令牌（其它设备上的旧会话立即失效）。
     */
    public function resetPassword(Request $req): void
    {
        $email = trim((string) $req->input('email', ''));
        $code = trim((string) $req->input('code', ''));
        $pass = (string) $req->input('password', '');
        $min = (int) wstat_config('security.pwd_min');

        if (!Util::validEmail($email)) {
            wstat_err('邮箱格式不正确', 422);
        }
        if (strlen($pass) < $min || strlen($pass) > 72) {
            wstat_err('密码长度需 ' . $min . '-72 位', 422);
        }
        $u = Db::first('SELECT id,status FROM users WHERE email=? LIMIT 1', [$email]);
        if ($u === null) {
            wstat_err('该邮箱未注册', 404, 404);
        }
        if ((int) $u['status'] !== 1) {
            wstat_err('账号已被禁用，请联系管理员', 403, 403);
        }
        if ($code === '') {
            wstat_err('请输入邮箱验证码', 422, 4223);
        }
        // 验证码放最后校验（消费型）：前面任一步失败都不烧码；用途固定为 reset
        if (!Verify::emailCheck($email, $code, 'reset')) {
            wstat_err('邮箱验证码错误或已失效，请重新获取', 422, 4223);
        }

        $now = time();
        Db::execute(
            'UPDATE users SET password=?, updated_at=? WHERE id=?',
            [password_hash($pass, PASSWORD_DEFAULT), $now, (int) $u['id']]
        );
        // 改密即失效全部旧会话，避免「已重置密码但他人仍保持登录」
        $revoked = Auth::revokeAll((int) $u['id']);
        OpLog::log((int) $u['id'], 'password_reset', '通过邮箱验证码重置密码');
        wstat_json(['ok' => true, 'revoked' => $revoked]);
    }

    /**
     * 取码共用实现（$purpose: register|reset）。
     * 两类验证码的存储键互不通用（见 Support\Verify），冷却与小时配额共用，
     * 防止「换个用途」绕开限流。
     */
    private function sendCode(Request $req, string $purpose): void
    {
        $reset = $purpose === 'reset';
        if (!$reset && !Settings::int('registration_enabled')) {
            wstat_err('注册已关闭，请联系管理员', 403, 403);
        }
        $email = trim((string) $req->input('email', ''));
        $captchaId = trim((string) $req->input('captcha_id', ''));
        $captcha = trim((string) $req->input('captcha', ''));
        $lang = $req->input('lang', 'zh-CN');
        if (!in_array($lang, ['zh-CN', 'en-US'], true)) {
            $lang = 'zh-CN';
        }
        if (!Util::validEmail($email)) {
            wstat_err('邮箱格式不正确', 422);
        }
        // 图形验证码：服务器具备 GD 能力时强制（缺 GD 时此处退化为「仅邮件配额限流」，
        // 前端会同时给出提示，避免整个找回流程不可用）
        if (Verify::available()) {
            if ($captchaId === '' || $captcha === '') {
                wstat_err('请输入图片验证码', 422, 4221);
            }
            if (!Verify::captchaCheck($captchaId, $captcha)) {
                wstat_err('图片验证码错误或已失效', 422, 4222);
            }
        }
        $exists = Db::first('SELECT id,status FROM users WHERE email=? LIMIT 1', [$email]);
        if ($reset) {
            if ($exists === null) {
                wstat_err('该邮箱未注册', 404, 404);
            }
            if ((int) $exists['status'] !== 1) {
                wstat_err('账号已被禁用，请联系管理员', 403, 403);
            }
        } elseif ($exists !== null) {
            // 与注册接口同样口径：已注册的邮箱不再发码（避免被用来探测/骚扰）
            wstat_err('该邮箱已注册', 409, 409);
        }
        $r = Verify::emailSend($email, $lang, $purpose);
        if (!$r['ok']) {
            wstat_err($r['msg'], (int) $r['http'], (int) $r['http']);
        }
        wstat_json(['sent' => true, 'ttl' => (int) $r['ttl'], 'resend' => (int) $r['resend']]);
    }

    /** GET /api/auth/me */
    public function me(Request $req): void
    {
        $u = Auth::requireUser($req);
        wstat_json(self::shape((int) $u['id']));
    }

    /** POST /api/auth/logout */
    public function logout(Request $req): void
    {
        $token = $req->bearer();
        if (strlen($token) === 64) {
            $u = Auth::user($req);   // 吊销前取一次用户，用于留痕
            Auth::revoke($token);
            if ($u !== null) {
                OpLog::log((int) $u['id'], 'logout');
            }
        }
        wstat_json(null);
    }

    private static function shape(int $userId): array
    {
        $u = Db::first('SELECT id,email,nickname,lang,created_at FROM users WHERE id=?', [$userId]);
        if ($u === null) {
            return [];
        }
        $u['is_admin'] = Settings::isAdmin($userId);
        return $u;
    }
}
