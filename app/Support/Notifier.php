<?php
/**
 * 消息推送（告警与日报共用）
 *
 * 支持通道类型：
 *   webhook   通用 Webhook（POST JSON，可选 HMAC 签名头）
 *   feishu    飞书自定义机器人
 *   dingtalk  钉钉自定义机器人（支持加签）
 *   wecom     企业微信群机器人
 *   serverchan Server酱（sctapi.ftqq.com）
 *   bark      Bark（iOS 推送，自建/官方均可）
 *   email     邮件（系统设置的 SMTP 优先，未配置时回退 PHP mail()）
 *
 * 统一入口：Notifier::send($channels, $title, $text) → 逐通道返回结果，
 * 任一通道失败不影响其它通道，失败原因写回 notify_channels.last_status。
 */
declare(strict_types=1);

namespace Wstat\Support;

class Notifier
{
    public const TYPES = ['webhook', 'feishu', 'dingtalk', 'wecom', 'serverchan', 'bark', 'email'];

    /** 机器人关键词（钉钉/飞书/企业微信常用「关键词」安全设置，命中即放行） */
    private const KEYWORD = 'WebStats';

    /**
     * 批量发送。
     *
     * @param array $channels notify_channels 行数组（含 id/type/config/name）
     * @param string $title   标题（短）
     * @param string $text    正文（多行纯文本）
     * @return array{sent:int,failed:int,results:array<int,array{id:int,name:string,type:string,ok:bool,msg:string}>}
     */
    public static function send(array $channels, string $title, string $text): array
    {
        $sent = 0;
        $failed = 0;
        $results = [];
        foreach ($channels as $ch) {
            $id = (int) ($ch['id'] ?? 0);
            $type = (string) ($ch['type'] ?? '');
            $name = (string) ($ch['name'] ?? $type);
            $cfg = json_decode((string) ($ch['config'] ?? ''), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $body = $type === 'email' ? $text : self::KEYWORD . ' | ' . $title . "\n" . $text;
            try {
                $res = self::dispatch($type, $cfg, $title, $body);
            } catch (\Throwable $e) {
                $res = [false, get_class($e) . ': ' . $e->getMessage()];
            }
            [$ok, $msg] = $res;
            $results[] = ['id' => $id, 'name' => $name, 'type' => $type, 'ok' => $ok, 'msg' => $msg];
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
            if ($id > 0) {
                self::markStatus($id, $msg);
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
    }

    /** 单通道分发，返回 [ok, message] */
    private static function dispatch(string $type, array $cfg, string $title, string $text): array
    {
        switch ($type) {
            case 'webhook': {
                $url = trim((string) ($cfg['url'] ?? ''));
                if ($url === '') {
                    return [false, '缺少 url'];
                }
                $payload = [
                    'source' => 'WebStats',
                    'title' => $title,
                    'text' => $text,
                    'time' => date('c'),
                ];
                $headers = ['Content-Type: application/json; charset=utf-8'];
                $secret = trim((string) ($cfg['secret'] ?? ''));
                if ($secret !== '') {
                    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    $headers[] = 'X-WStat-Sign: sha256=' . hash_hmac('sha256', (string) $raw, $secret);
                }
                return self::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), $headers);
            }
            case 'feishu': {
                $url = trim((string) ($cfg['url'] ?? ''));
                if ($url === '') {
                    return [false, '缺少 webhook 地址'];
                }
                $payload = ['msg_type' => 'text', 'content' => ['text' => $text]];
                return self::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            }
            case 'dingtalk': {
                $url = trim((string) ($cfg['url'] ?? ''));
                if ($url === '') {
                    return [false, '缺少 webhook 地址'];
                }
                $secret = trim((string) ($cfg['secret'] ?? ''));
                if ($secret !== '') {
                    [$ts, $sign] = self::dingSign($secret);
                    $url .= (strpos($url, '?') === false ? '?' : '&') . "timestamp=$ts&sign=" . rawurlencode($sign);
                }
                $payload = ['msgtype' => 'text', 'text' => ['content' => $text]];
                return self::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            }
            case 'wecom': {
                $url = trim((string) ($cfg['url'] ?? ''));
                if ($url === '') {
                    return [false, '缺少 webhook 地址'];
                }
                $payload = ['msgtype' => 'text', 'text' => ['content' => $text]];
                return self::post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            }
            case 'serverchan': {
                $key = trim((string) ($cfg['key'] ?? ''));
                if ($key === '') {
                    return [false, '缺少 SendKey'];
                }
                $body = http_build_query(['title' => self::KEYWORD . ' | ' . $title, 'desp' => $text]);
                return self::post(
                    'https://sctapi.ftqq.com/' . rawurlencode($key) . '.send',
                    $body,
                    ['Content-Type: application/x-www-form-urlencoded']
                );
            }
            case 'bark': {
                $base = rtrim(trim((string) ($cfg['url'] ?? 'https://api.day.app')), '/');
                $key = trim((string) ($cfg['key'] ?? ''));
                if ($key === '') {
                    return [false, '缺少 Bark Key'];
                }
                $payload = ['title' => self::KEYWORD . ' | ' . $title, 'body' => $text, 'group' => 'WebStats'];
                return self::post($base . '/' . rawurlencode($key), json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            }
            case 'email': {
                $to = $cfg['to'] ?? '';
                if (is_string($to)) {
                    $to = array_filter(array_map('trim', explode(',', $to)));
                }
                if (!is_array($to) || !$to) {
                    return [false, '缺少收件人'];
                }
                $bad = [];
                foreach ($to as $a) {
                    if (!Util::validEmail((string) $a)) {
                        $bad[] = (string) $a;
                    }
                }
                if ($bad) {
                    return [false, '收件人格式不正确: ' . implode(',', $bad)];
                }
                // 优先走系统设置的 SMTP（系统设置 → 邮件配置）；未配置 smtp_host 时回退 mail()
                return Mailer::send(array_values(array_map('strval', $to)), self::KEYWORD . ' | ' . $title, $text);
            }
            default:
                return [false, '不支持的通道类型: ' . $type];
        }
    }

    /** POST 请求（curl），返回 [ok, message] */
    private static function post(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [false, 'curl 扩展不可用'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return [false, 'curl 初始化失败'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'WebStats-Notifier/1.0',
        ]);
        $resp = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || !is_string($resp)) {
            return [false, 'CURL ' . $errNo . ': ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return [false, "HTTP $code: " . mb_substr(trim($resp), 0, 120)];
        }
        // 部分机器人以 HTTP 200 + 业务错误码返回
        $json = json_decode($resp, true);
        if (is_array($json)) {
            foreach (['errcode', 'code'] as $k) {
                if (isset($json[$k]) && (int) $json[$k] !== 0) {
                    return [false, '业务错误 ' . $json[$k] . ': ' . mb_substr((string) ($json['errmsg'] ?? $json['message'] ?? $resp), 0, 100)];
                }
            }
            if (isset($json['StatusCode']) && (int) $json['StatusCode'] !== 0) {
                return [false, '业务错误 ' . $json['StatusCode'] . ': ' . (string) ($json['StatusMessage'] ?? '')];
            }
        }
        return [true, 'ok'];
    }

    /** 钉钉加签：返回 [timestamp, sign] */
    private static function dingSign(string $secret): array
    {
        $ts = (string) round(microtime(true) * 1000);
        $sign = base64_encode(hash_hmac('sha256', $ts . "\n" . $secret, $secret, true));
        return [$ts, $sign];
    }

    /** 记录最近一次发送结果，便于前端「渠道」列表直观看到通不通 */
    private static function markStatus(int $channelId, string $msg): void
    {
        try {
            Db::execute(
                'UPDATE notify_channels SET last_status=?, last_sent_at=? WHERE id=?',
                [mb_substr($msg, 0, 190), time(), $channelId]
            );
        } catch (\Throwable $e) {
            /* 状态回写失败不影响发送结果 */
        }
    }
}
