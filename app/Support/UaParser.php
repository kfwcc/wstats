<?php
/**
 * 轻量 UA 解析（浏览器/系统/设备）。覆盖主流客户端，解析失败回退 'Unknown'。
 * 命中顺序：先移动端特征，再浏览器关键字。
 */
declare(strict_types=1);

namespace Wstat\Support;

class UaParser
{
    public static function parse(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['browser' => '', 'os' => '', 'device' => '', 'mobile' => false];
        }
        $u = strtolower($ua);

        /* ---- 设备 ---- */
        $device = 'desktop';
        if (strpos($u, 'bot') !== false || strpos($u, 'spider') !== false || strpos($u, 'crawler') !== false) {
            $device = 'bot';
        } elseif (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false || strpos($u, 'kindle') !== false) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|android|iphone|ipod|phone|iemobile|opera mini/i', $u)) {
            $device = 'mobile';
        }

        /* ---- 浏览器 ---- */
        $browser = 'Unknown';
        $map = [
            'edg/'            => 'Edge',
            'edgios/'         => 'Edge',
            'opr/'            => 'Opera',
            'opera'           => 'Opera',
            'micromessenger'  => 'WeChat',
            'weibo'           => 'Weibo',
            'bingbot'         => 'BingBot',
            'baiduspider'     => 'BaiduBot',
            'googlebot'       => 'GoogleBot',
            'sogou'           => 'SogouSpider',
            '360spider'       => '360Spider',
            'qqbrowser'       => 'QQBrowser',
            'ucbrowser'       => 'UCBrowser',
            'quark'           => 'Quark',
            'chrome'          => 'Chrome',
            'crios'           => 'Chrome',
            'safari'          => 'Safari',
            'firefox'         => 'Firefox',
            'fxios'           => 'Firefox',
            'msie'            => 'IE',
            'trident'         => 'IE',
            'samsungbrowser'  => 'Samsung',
        ];
        foreach ($map as $key => $name) {
            if (strpos($u, $key) !== false) {
                $browser = $name;
                break;
            }
        }
        // Firefox 出现在 Googlebot 等中顺序已处理；Safari 需排除 Chrome 内核干扰
        if ($browser === 'Safari' && (strpos($u, 'chrome/') !== false || strpos($u, 'crios') !== false)) {
            $browser = strpos($u, 'crios') !== false ? 'Chrome' : $browser;
        }

        /* ---- 操作系统 ---- */
        $os = 'Unknown';
        $osMap = [
            'windows nt 11' => 'Windows 11',
            'windows nt 10' => 'Windows 10',
            'windows nt 6.3' => 'Windows 8.1',
            'windows nt 6.1' => 'Windows 7',
            'android'        => 'Android',
            'iphone'         => 'iOS',
            'ipad'           => 'iOS',
            'ipod'           => 'iOS',
            'mac os x'       => 'macOS',
            'macintosh'      => 'macOS',
            'linux'          => 'Linux',
            'ubuntu'         => 'Ubuntu',
            'windows phone'  => 'Windows Phone',
        ];
        foreach ($osMap as $key => $name) {
            if (strpos($u, $key) !== false) {
                $os = $name;
                break;
            }
        }

        $mobile = $device === 'mobile' || $device === 'tablet';

        return ['browser' => self::clean($browser), 'os' => self::clean($os), 'device' => $device, 'mobile' => $mobile];
    }

    /** 判定是否爬虫/自动化工具（采集端过滤用；大小写不敏感，UA 为空不算） */
    public static function isBot(string $ua): bool
    {
        $ua = strtolower(trim($ua));
        if ($ua === '') {
            return false;
        }
        // 常见搜索引擎爬虫、离线工具、HTTP 客户端库、无头浏览器、压测工具
        $needles = [
            'bot', 'spider', 'crawl', 'slurp', 'curl', 'wget', 'python', 'java/',
            'okhttp', 'go-http-client', 'httpclient', 'libwww', 'http_request',
            'axios/', 'node-fetch', 'undici', 'scrapy', 'phantomjs', 'headless',
            'selenium', 'puppeteer', 'playwright', 'postman', 'insomnia',
            'lighthouse', 'apachebench', 'jmeter', 'wrk', 'loadrunner',
            'bytespider', 'petalbot', 'yisou', 'ahrefs', 'semrush', 'mj12',
            'dotbot', 'feedly', 'weborama', 'sprinklr', 'dataforseo',
        ];
        foreach ($needles as $n) {
            if (strpos($ua, $n) !== false) {
                return true;
            }
        }
        // 无 UA / 纯状态描述（如 Mozilla 空壳）不算 bot；以 IP 协议词开头的原始 HTTP 客户端判 bot
        return (bool) preg_match('#^(?:[a-z0-9]+/[0-9][0-9.]*\s*$)#', $ua);
    }

    private static function clean(string $s): string
    {
        return mb_substr($s, 0, 39);
    }
}
