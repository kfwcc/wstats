<?php
/**
 * 注册验证：图形验证码（GD 生成 PNG）+ 邮箱验证码。
 *
 * 两道校验的关系（注册两步）：
 *   1) 取「邮箱验证码」前必须先通过图形验证码 —— 挡住脚本批量刷邮件（也保护 SMTP 配额）；
 *   2) 提交注册时必须携带正确的邮箱验证码 —— 证明该邮箱真实可达。
 *
 * 用途（purpose）分 register / reset 两种，各自独立存储键（mailcode: / mailreset:），
 * 互不通用 —— 注册验证码不能拿来重置密码，反之亦然；冷却与小时配额则共用，防止换用途绕开限流。
 *
 * 存储：server/data/verify/ 下的 JSON 小文件（flock 读改写、exp 过期、惰性 GC）。
 * 为什么不用 Redis：本系统支持「无 Redis 模式」（安装向导可跳过 Redis），注册验证是低频路径，
 * 文件存储在两种模式下行为一致，也避免给 RedisGuard 的键类型表新增键。
 * 前提是单机部署 —— 本系统已假定单机（worker.lock 同为本地文件），多机横向扩展时需换共享存储。
 *
 * 安全要点：
 * - 邮箱验证码 6 位数字，TTL 10 分钟，最多试 5 次，命中即销毁（一次性）；
 * - 图形验证码 4 位（剔除 0/O/1/I 等易混字符），TTL 5 分钟，校验后即销毁；
 * - 按邮箱 + 按 IP 双向小时配额，另有 60 秒重发冷却；
 * - 只有邮件真正投递成功才写冷却/配额，SMTP 故障不会把用户锁死；
 * - 验证码只写进邮件正文，任何 HTTP 接口都不回显。
 */
declare(strict_types=1);

namespace Wstat\Support;

final class Verify
{
    /** 图形验证码有效期（秒） */
    public const CAPTCHA_TTL = 300;
    /** 邮箱验证码有效期（秒） */
    public const CODE_TTL = 600;
    /** 同邮箱两次发送的最小间隔（秒） */
    public const RESEND_AFTER = 60;
    /** 单条邮箱验证码最多可尝试次数 */
    public const CODE_TRIES = 5;
    /** 同邮箱每小时最多发送次数 */
    public const EMAIL_HOURLY = 5;
    /** 同 IP 每小时最多发送次数 */
    public const IP_HOURLY = 20;
    /** 同 IP 每小时最多索取图形验证码张数（防脚本刷盘） */
    public const CAPTCHA_HOURLY = 200;

    /** 易混字符已剔除：无 0/O/1/I/L */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /* ================= 图形验证码 ================= */

    /** 服务器是否具备生成图形验证码的能力（缺 GD 时注册页给出明确提示） */
    public static function available(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagepng')
            && function_exists('imagestring')
            && function_exists('imageline');
    }

    /**
     * 生成一张图形验证码。
     * @return array{id:string,image:string,ttl:int} image 为可直接放进 <img src> 的 data URL
     */
    public static function captchaIssue(): array
    {
        $code = self::randomCode(4);
        $id = Util::randHex(16);
        self::put('captcha:' . $id, ['code' => $code], self::CAPTCHA_TTL);
        return ['id' => $id, 'image' => self::png($code), 'ttl' => self::CAPTCHA_TTL];
    }

    /**
     * 校验图形验证码。**一次性**：无论对错都作废当前这张图，
     * 前端在收到失败后应重新拉一张（否则用户会反复撞同一张已失效的图）。
     */
    public static function captchaCheck(string $id, string $input): bool
    {
        $id = strtolower(trim($id));
        if ($id === '' || preg_match('/^[0-9a-f]{8,64}$/', $id) !== 1) {
            return false;
        }
        $key = 'captcha:' . $id;
        $rec = self::read($key);
        self::forget($key);
        if ($rec === null) {
            return false;
        }
        $want = strtoupper((string) ($rec['data']['code'] ?? ''));
        $got = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        return $want !== '' && hash_equals($want, $got);
    }

    /** 生成 PNG（GD 内置位图字体 + 放大 + 随机旋转 + 干扰，无需外部字体文件） */
    private static function png(string $code): string
    {
        $w = 156;
        $h = 52;
        $im = imagecreatetruecolor($w, $h);
        // 每张图随机一个柔和底色；字形瓦片用同色底，旋转后拼贴不会露出方形边界
        $pools = [[242, 246, 254], [246, 243, 253], [240, 250, 245], [253, 246, 238]];
        [$br, $bg, $bb] = $pools[random_int(0, count($pools) - 1)];
        $canvasBg = imagecolorallocate($im, $br, $bg, $bb);
        imagefilledrectangle($im, 0, 0, $w, $h, $canvasBg);

        $n = strlen($code);
        $step = (int) (($w - 14) / $n);
        for ($i = 0; $i < $n; $i++) {
            // 1) 小画布画单字（font 5 约 9x15）
            $sw = 11;
            $sh = 17;
            $tmp = imagecreatetruecolor($sw, $sh);
            $tbg = imagecolorallocate($tmp, $br, $bg, $bb);
            imagefilledrectangle($tmp, 0, 0, $sw, $sh, $tbg);
            $ink = imagecolorallocate($tmp, random_int(20, 90), random_int(20, 90), random_int(70, 160));
            imagestring($tmp, 5, 1, 1, $code[$i], $ink);

            // 2) 放大 2 倍：resampled 带插值，比原始位图字号更易读，也更抗模板匹配
            $gw = $sw * 2;
            $gh = $sh * 2;
            $big = imagecreatetruecolor($gw, $gh);
            $bbg = imagecolorallocate($big, $br, $bg, $bb);
            imagefilledrectangle($big, 0, 0, $gw, $gh, $bbg);
            imagecopyresampled($big, $tmp, 0, 0, 0, 0, $gw, $gh, $sw, $sh);
            imagedestroy($tmp);

            // 3) 随机旋转（imagerotate 属核心 GD，异常时退回未旋转瓦片）
            $tile = $big;
            if (function_exists('imagerotate')) {
                $rot = @imagerotate($big, (float) random_int(-24, 24), $bbg);
                if ($rot !== false) {
                    $tile = $rot;
                }
            }

            // 4) 拼到画布（限制在图内，避免旋转后越界被截断）
            $tw = imagesx($tile);
            $th = imagesy($tile);
            $x = max(0, min($w - $tw, 7 + $i * $step + random_int(0, 3)));
            $y = max(0, min($h - $th, (int) (($h - $th) / 2) + random_int(-3, 3)));
            imagecopy($im, $tile, $x, $y, 0, 0, $tw, $th);
            if ($tile !== $big) {
                imagedestroy($tile);
            }
            imagedestroy($big);
        }

        // 5) 干扰：弧线 + 斜线 + 噪点
        for ($k = 0; $k < 2; $k++) {
            $c = imagecolorallocate($im, random_int(130, 200), random_int(130, 200), random_int(130, 200));
            imagearc(
                $im,
                random_int(0, $w),
                random_int(0, $h),
                random_int(50, 150),
                random_int(24, 64),
                random_int(0, 360),
                random_int(0, 360),
                $c
            );
        }
        for ($k = 0; $k < 3; $k++) {
            $c = imagecolorallocate($im, random_int(120, 190), random_int(120, 190), random_int(120, 190));
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        for ($k = 0; $k < 130; $k++) {
            $c = imagecolorallocate($im, random_int(100, 215), random_int(100, 215), random_int(100, 215));
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        $bc = imagecolorallocate($im, random_int(150, 205), random_int(150, 205), random_int(200, 245));
        imagerectangle($im, 0, 0, $w - 1, $h - 1, $bc);

        ob_start();
        imagepng($im);
        $bin = (string) ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($bin);
    }

    /* ================= 邮箱验证码 ================= */

    /**
     * 生成并发送邮箱验证码（调用方需先校验图形验证码）。
     * @param string $purpose register（注册）| reset（重置密码）；决定存储键与邮件文案
     * @return array{ok:bool,http:int,msg:string,ttl?:int,resend?:int}
     */
    public static function emailSend(string $email, string $lang = 'zh-CN', string $purpose = 'register'): array
    {
        $purpose = self::purpose($purpose);
        $ek = self::emailKey($email);

        $left = self::ttlLeft('cooldown:' . $ek);
        if ($left > 0) {
            return ['ok' => false, 'http' => 429, 'msg' => '发送过于频繁，请 ' . $left . ' 秒后再试'];
        }
        if (self::count('mailhour:' . $ek) >= self::EMAIL_HOURLY) {
            return ['ok' => false, 'http' => 429, 'msg' => '该邮箱 1 小时内发送次数已达上限，请稍后再试'];
        }
        $ip = Util::clientIp();
        if (self::count('iphour:' . sha1($ip)) >= self::IP_HOURLY) {
            return ['ok' => false, 'http' => 429, 'msg' => '当前 IP 发送次数过多，请稍后再试'];
        }

        $code = (string) random_int(100000, 999999);
        $key = self::codeKey($email, $purpose);
        self::put($key, ['code' => $code, 'tries' => 0], self::CODE_TTL);

        [$subject, $body] = self::mailText($code, $lang, $purpose);
        [$ok, $smtpMsg] = Mailer::send([$email], $subject, $body);
        if (!$ok) {
            // 投递失败：作废验证码，且不写冷却/配额，避免 SMTP 故障把用户彻底锁死
            self::forget($key);
            return ['ok' => false, 'http' => 500, 'msg' => '邮件发送失败：' . $smtpMsg];
        }

        self::bump('mailhour:' . $ek, 3600);
        self::bump('iphour:' . sha1($ip), 3600);
        self::put('cooldown:' . $ek, ['n' => 1], self::RESEND_AFTER);

        return ['ok' => true, 'http' => 200, 'msg' => '', 'ttl' => self::CODE_TTL, 'resend' => self::RESEND_AFTER];
    }

    /**
     * 校验邮箱验证码（成功即销毁；连错 5 次也销毁，需重新获取）。
     * $purpose 必须与发送时一致，否则取不到记录（用途隔离）。
     */
    public static function emailCheck(string $email, string $input, string $purpose = 'register'): bool
    {
        $key = self::codeKey($email, $purpose);
        $rec = self::read($key);
        if ($rec === null) {
            return false;
        }
        $want = (string) ($rec['data']['code'] ?? '');
        $got = (string) preg_replace('/\D/', '', $input);
        if ($want === '' || !hash_equals($want, $got)) {
            $tries = (int) ($rec['data']['tries'] ?? 0) + 1;
            if ($tries >= self::CODE_TRIES) {
                self::forget($key);
            } else {
                self::put($key, ['code' => $want, 'tries' => $tries], max(1, (int) $rec['exp'] - time()));
            }
            return false;
        }
        self::forget($key);
        return true;
    }

    /**
     * 仅供 CLI 自测/运维排查。任何 HTTP 请求（非 cli SAPI）恒返回空串，
     * 保证验证码不会经接口泄露。
     */
    public static function debugPeekCode(string $email, string $purpose = 'register'): string
    {
        if (PHP_SAPI !== 'cli') {
            return '';
        }
        $rec = self::read(self::codeKey($email, $purpose));
        return $rec === null ? '' : (string) ($rec['data']['code'] ?? '');
    }

    /** 同 IP 索取图形验证码的限流计数（超过上限返回 false，调用方回 429） */
    public static function captchaAllow(string $ip): bool
    {
        return self::bump('captchahour:' . sha1($ip), 3600) <= self::CAPTCHA_HOURLY;
    }

    private static function emailKey(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    /** 用途白名单归位（未知值一律当注册处理，杜绝用入参拼存储键） */
    private static function purpose(string $purpose): string
    {
        return $purpose === 'reset' ? 'reset' : 'register';
    }

    /** 验证码存储键：注册 mailcode:{ek} / 重置 mailreset:{ek}，两者互不通用 */
    private static function codeKey(string $email, string $purpose): string
    {
        return (self::purpose($purpose) === 'reset' ? 'mailreset:' : 'mailcode:') . self::emailKey($email);
    }

    /** @return array{0:string,1:string} [主题, 正文] */
    private static function mailText(string $code, string $lang, string $purpose = 'register'): array
    {
        $brand = trim(Settings::get('smtp_from_name'));
        if ($brand === '') {
            $brand = 'WebStats';
        }
        $min = (int) round(self::CODE_TTL / 60);
        $reset = self::purpose($purpose) === 'reset';
        if ($lang === 'en-US') {
            return [
                $reset ? '[' . $brand . '] Password reset code' : '[' . $brand . '] Email verification code',
                'Your ' . ($reset ? 'password reset' : 'verification') . ' code is: ' . $code . "\n\n"
                . 'It expires in ' . $min . " minutes. Please do not share it with anyone.\n"
                . "If you did not request this, you can safely ignore this email.\n",
            ];
        }
        return [
            $reset ? '【' . $brand . '】密码重置验证码' : '【' . $brand . '】邮箱验证码',
            '您的' . ($reset ? '密码重置' : '') . '验证码是：' . $code . "\n\n"
            . '有效期 ' . $min . " 分钟，请勿泄露给他人。\n"
            . "如果不是您本人操作，请忽略本邮件。\n",
        ];
    }

    private static function randomCode(int $len): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /* ================= 文件型短时存储 ================= */

    private static function dir(): string
    {
        return WSTAT_ROOT . '/data/verify';
    }

    private static function ensureDir(): bool
    {
        $d = self::dir();
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return is_dir($d) && is_writable($d);
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.json';
    }

    /** @return array{exp:int,data:array}|null */
    private static function read(string $key): ?array
    {
        $f = self::file($key);
        if (!is_file($f)) {
            return null;
        }
        $raw = @file_get_contents($f);
        if ($raw === false || $raw === '') {
            @unlink($f);
            return null;
        }
        $rec = json_decode($raw, true);
        if (!is_array($rec) || !isset($rec['exp'])) {
            @unlink($f);
            return null;
        }
        if ((int) $rec['exp'] <= time()) {
            @unlink($f);
            return null;
        }
        return ['exp' => (int) $rec['exp'], 'data' => is_array($rec['data'] ?? null) ? $rec['data'] : []];
    }

    private static function put(string $key, array $data, int $ttl): void
    {
        if (!self::ensureDir()) {
            return;
        }
        $rec = ['exp' => time() + max(1, $ttl), 'data' => $data];
        @file_put_contents(self::file($key), (string) json_encode($rec), LOCK_EX);
        self::gc();
    }

    private static function forget(string $key): void
    {
        @unlink(self::file($key));
    }

    private static function ttlLeft(string $key): int
    {
        $rec = self::read($key);
        return $rec === null ? 0 : max(0, $rec['exp'] - time());
    }

    private static function count(string $key): int
    {
        $rec = self::read($key);
        return $rec === null ? 0 : (int) ($rec['data']['n'] ?? 0);
    }

    /** 原子自增一个带 TTL 的计数器（flock 保护，多进程安全），返回自增后的值 */
    private static function bump(string $key, int $ttl): int
    {
        if (!self::ensureDir()) {
            return 1;
        }
        $fh = @fopen(self::file($key), 'c+');
        if ($fh === false) {
            return 1;
        }
        @flock($fh, LOCK_EX);
        $raw = (string) stream_get_contents($fh);
        $rec = $raw !== '' ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($rec) || (int) ($rec['exp'] ?? 0) <= $now) {
            $rec = ['exp' => $now + $ttl, 'data' => ['n' => 0]];
        }
        $n = (int) ($rec['data']['n'] ?? 0) + 1;
        $rec['data']['n'] = $n;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($rec));
        fflush($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);
        self::gc();
        return $n;
    }

    /**
     * 惰性回收：以约 1/40 的概率触发，清掉过期记录与超过 1 天未更新的残留文件。
     * 最长 TTL 为 1 小时，故「1 天未更新」必定是垃圾。
     */
    private static function gc(bool $force = false): void
    {
        if (!$force && random_int(1, 40) !== 1) {
            return;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }
        $files = @glob($dir . '/*.json');
        if (!is_array($files)) {
            return;
        }
        $now = time();
        foreach ($files as $f) {
            $raw = @file_get_contents($f);
            $rec = $raw === false ? null : json_decode((string) $raw, true);
            $exp = is_array($rec) ? (int) ($rec['exp'] ?? 0) : 0;
            $mtime = (int) @filemtime($f);
            if ($exp <= $now || $mtime < $now - 86400) {
                @unlink($f);
            }
        }
    }
}
