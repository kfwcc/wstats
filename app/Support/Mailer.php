<?php
/**
 * SMTP 邮件发送（零依赖 socket 实现）。
 *
 * 读取 sys_settings 的 smtp_* 配置；未配置 smtp_host 时回退 PHP mail()。
 * 支持 ssl(465) / STARTTLS(587) / 明文(25)，认证 AUTH LOGIN（可选）。
 * 返回 [bool ok, string message]，供 Notifier 与「发送测试邮件」共用。
 */
declare(strict_types=1);

namespace Wstat\Support;

class Mailer
{
    /**
     * @param array<int,string> $to 已校验格式的收件人列表
     */
    public static function send(array $to, string $subject, string $text): array
    {
        if (!$to) {
            return [false, '缺少收件人'];
        }
        $host = trim(Settings::get('smtp_host'));
        if ($host === '') {
            return self::sendByMail($to, $subject, $text);
        }
        try {
            return self::sendBySmtp($to, $subject, $text);
        } catch (\Throwable $e) {
            return [false, 'SMTP: ' . $e->getMessage()];
        }
    }

    /** 回退路径：PHP mail()（需主机配置 MTA） */
    private static function sendByMail(array $to, string $subject, string $text): array
    {
        $fromEmail = self::fromEmail();
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = implode("\r\n", [
            'From: ' . self::fromName() . " <{$fromEmail}>",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'MIME-Version: 1.0',
        ]);
        $ok = @mail(implode(',', $to), $subjectEnc, chunk_split(base64_encode($text)), $headers);
        return [(bool) $ok, $ok ? '已通过 mail() 提交发送' : 'mail() 投递失败（未配置 SMTP 且主机无 MTA）'];
    }

    private static function sendBySmtp(array $to, string $subject, string $text): array
    {
        $secure = strtolower(Settings::get('smtp_secure'));
        $port = (int) Settings::get('smtp_port');
        if (!in_array($port, [25, 465, 587, 2525], true)) {
            $port = $secure === 'ssl' ? 465 : ($secure === 'tls' ? 587 : 25);
        }
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $host = trim(Settings::get('smtp_host'));

        $sock = @fsockopen($transport . $host, $port, $errno, $errstr, 10);
        if ($sock === false) {
            return [false, "连接失败: {$errstr}({$errno})"];
        }
        stream_set_timeout($sock, 10);

        $resp = self::read($sock);
        if ($resp[0] !== 2) {
            fclose($sock);
            return [false, '握手失败: ' . $resp[1]];
        }

        self::cmd($sock, 'EHLO ' . self::heloName());

        if ($secure === 'tls') {
            $r = self::cmd($sock, 'STARTTLS');
            if ($r[0] !== 2) {
                fclose($sock);
                return [false, 'STARTTLS 被拒绝: ' . $r[1]];
            }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock);
                return [false, 'TLS 协商失败'];
            }
            self::cmd($sock, 'EHLO ' . self::heloName());
        }

        $user = Settings::get('smtp_username');
        $pass = Settings::get('smtp_password');
        if ($user !== '') {
            $r = self::cmd($sock, 'AUTH LOGIN');
            if ($r[0] !== 3) {
                fclose($sock);
                return [false, '服务器不支持 AUTH LOGIN: ' . $r[1]];
            }
            $r = self::cmd($sock, base64_encode($user));
            if ($r[0] !== 3) {
                fclose($sock);
                return [false, 'SMTP 用户名被拒: ' . $r[1]];
            }
            $r = self::cmd($sock, base64_encode($pass));
            if ($r[0] !== 2) {
                fclose($sock);
                return [false, 'SMTP 认证失败（检查用户名/密码/授权码）: ' . $r[1]];
            }
        }

        $from = self::fromEmail();
        $r = self::cmd($sock, 'MAIL FROM:<' . $from . '>');
        if ($r[0] !== 2) {
            fclose($sock);
            return [false, 'MAIL FROM 被拒: ' . $r[1]];
        }
        foreach ($to as $rcpt) {
            $r = self::cmd($sock, 'RCPT TO:<' . $rcpt . '>');
            if ($r[0] !== 2) {
                fclose($sock);
                return [false, 'RCPT 被拒（' . $rcpt . '）: ' . $r[1]];
            }
        }

        $body = self::buildBody($from, $to, $subject, $text);
        $r = self::cmd($sock, 'DATA');
        if ($r[0] !== 3) {
            fclose($sock);
            return [false, 'DATA 被拒: ' . $r[1]];
        }
        // 按 SMTP 规范把行首的单独「.」转义
        $body = preg_replace('/^\./m', '..', $body);
        fwrite($sock, $body . "\r\n.\r\n");
        $resp = self::read($sock);
        fclose($sock);
        if ($resp[0] !== 2) {
            return [false, '正文被拒: ' . $resp[1]];
        }
        return [true, 'SMTP 已接受（' . count($to) . ' 个收件人）'];
    }

    private static function buildBody(string $from, array $to, string $subject, string $text): string
    {
        $headers = [
            'From: =?UTF-8?B?' . base64_encode(self::fromName()) . '?= <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::heloName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text));
    }

    /** 读一条 SMTP 响应（可能多行 250-xxx），返回 [code, text]；code 归一为 1/2/3 档 */
    private static function read($sock): array
    {
        $data = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($data, 0, 3);
        return [$code === 0 ? 0 : ($code < 300 ? 2 : ($code < 400 ? 3 : 1)), trim($data)];
    }

    /** 发命令并返回响应（[档位, 原文]），由调用方校验档位 */
    private static function cmd($sock, string $cmd): array
    {
        fwrite($sock, $cmd . "\r\n");
        return self::read($sock);
    }

    private static function fromEmail(): string
    {
        $e = trim(Settings::get('smtp_from_email'));
        if ($e !== '' && Util::validEmail($e)) {
            return $e;
        }
        $u = Settings::get('smtp_username');
        if ($u !== '' && Util::validEmail($u)) {
            return $u;
        }
        return 'no-reply@' . self::heloName();
    }

    private static function fromName(): string
    {
        $n = trim(Settings::get('smtp_from_name'));
        return $n !== '' ? $n : 'WebStats';
    }

    private static function heloName(): string
    {
        $host = strtolower($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            $host = 'localhost';
        }
        return preg_replace('/[^a-z0-9.\-]/', '', $host) ?: 'localhost';
    }
}
