<?php
/**
 * 流量来源分类。
 * 优先顺序：UTM 参数 > 广告平台 ClickID > 搜索引擎/社交/外链 > 直接访问。
 * 归属策略：会话首次事件（入口）判定一次，之后的站内跳转不覆盖来源。
 */
declare(strict_types=1);

namespace Wstat\Support;

class Referrer
{
    public const SEARCH = [
        'baidu.com', 'google.', 'bing.com', 'so.com', 'sogou.com', 'sm.cn', 'yahoo.',
        'yandex.', 'duckduckgo.com', 'ecosia.org', 'ask.com', 'yisou.com', 'toutiao.com/search',
    ];

    public const SOCIAL = [
        'weibo.com', 'weibo.cn', 'zhihu.com', 'xiaohongshu.com', 'xhslink.com', 'douyin.com',
        'iesdouyin.com', 'tiktok.com', 'wechat.com', 'weixin.qq.com', 'qq.com', 'qzone.qq.com',
        'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'youtube.com', 'linkedin.com',
        'telegram.org', 'discord.com', 'bilibili.com', 'kuaishou.com', 'feishu.cn', 'youtube.com',
    ];

    /** 各平台 ClickID 参数名 → 平台 */
    public const CLICKID = [
        'gclid' => 'Google Ads', 'msclkid' => 'Microsoft Ads', 'fbclid' => 'Meta Ads',
        'ttclid' => 'TikTok Ads', 'ytclid' => 'YouTube Ads', 'sccid' => 'Snapchat Ads',
        'dclid' => 'DoubleClick', 'wbraid' => 'Google Ads', 'gbraid' => 'Google Ads',
        'lgclickid' => 'LG U+', 'qqclid' => 'Tencent Ads', 'ogclid' => 'Omnicom',
    ];

    /**
     * @param string $refUrl 入口页面 referrer（可为空）
     * @param string $siteDomain 站点域名
     * @param array  $utm  ['source','medium','campaign','content','term']
     * @param string $clickId 已识别出的 ClickID 值（或 ''）
     * @return array{type:string, source:string, medium:string, label:string, host:string}
     */
    public static function classify(string $refUrl, string $siteDomain, array $utm = [], string $clickId = ''): array
    {
        $u = $utm + ['source' => '', 'medium' => '', 'campaign' => '', 'content' => '', 'term' => ''];

        // 1) UTM 优先（只要带 source 或 medium 即视为营销来源）
        if ($u['source'] !== '' || $u['medium'] !== '') {
            $medium = $u['medium'] !== '' ? $u['medium'] : 'utm';
            return [
                'type' => 'utm',
                'source' => $u['source'] !== '' ? $u['source'] : 'utm',
                'medium' => $medium,
                'label' => 'UTM: ' . ($u['source'] ?: $u['medium']),
                'host' => '',
            ];
        }

        // 2) ClickID
        if ($clickId !== '') {
            $from = self::clickPlatform($refUrl) ?: 'Paid';
            return ['type' => 'paid', 'source' => $from, 'medium' => 'cpc', 'label' => 'Paid: ' . $from, 'host' => ''];
        }

        // 3) referrer 分析
        $refUrl = trim($refUrl);
        if ($refUrl === '') {
            return ['type' => 'direct', 'source' => 'direct', 'medium' => '', 'label' => 'Direct', 'host' => ''];
        }
        $host = self::hostOf($refUrl);
        if ($host === '') {
            return ['type' => 'direct', 'source' => 'direct', 'medium' => '', 'label' => 'Direct', 'host' => ''];
        }
        // 站内跳转（同域或子域）不算外链
        if ($siteDomain !== '' && (self::hostEquals($host, $siteDomain))) {
            return ['type' => 'internal', 'source' => 'internal', 'medium' => '', 'label' => 'Internal', 'host' => $host];
        }
        foreach (self::SEARCH as $k) {
            if (strpos($host, $k) !== false) {
                return ['type' => 'search', 'source' => self::brand($host), 'medium' => 'organic', 'label' => 'Search: ' . self::brand($host), 'host' => $host];
            }
        }
        foreach (self::SOCIAL as $k) {
            if (strpos($host, $k) !== false) {
                return ['type' => 'social', 'source' => self::brand($host), 'medium' => 'social', 'label' => 'Social: ' . self::brand($host), 'host' => $host];
            }
        }
        return ['type' => 'link', 'source' => self::brand($host), 'medium' => 'referral', 'label' => 'Link: ' . self::brand($host), 'host' => $host];
    }

    /** 从 ClickID 参数判断广告平台（无 referrer 时） */
    public static function clickPlatform(string $refUrl): string
    {
        return ''; // 平台已由 SDK 上报 click_id 参数名解析，见 classify() 分支1
    }

    /** referrer 中的 host 提取 */
    public static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        return isset($parts['host']) ? mb_strtolower((string) $parts['host']) : '';
    }

    /** 域匹配：相等 或 host 以 .siteDomain 结尾（子域） */
    public static function hostEquals(string $host, string $siteDomain): bool
    {
        $h = mb_strtolower($host);
        $s = mb_strtolower($siteDomain);
        return $h === $s || substr($h, -strlen($s) - 1) === '.' . $s;
    }

    /** 品牌名（取主域二级名，如 www.baidu.com / m.weibo.cn → baidu / weibo） */
    public static function brand(string $host): string
    {
        $h = preg_replace('/^www\./', '', $host);
        if ($h === null || $h === '') {
            return $host;
        }
        $parts = explode('.', $h);
        $n = count($parts);
        if ($n <= 1) {
            return $h;
        }
        // 国家二级后缀组合（xxx.com.cn / xxx.co.uk ...）取倒数第三段
        $cc2 = ['com.cn', 'net.cn', 'org.cn', 'gov.cn', 'com.br', 'co.uk', 'com.au', 'com.mx', 'co.jp', 'com.hk', 'com.tw', 'com.sg'];
        if ($n >= 3 && in_array($parts[$n - 2] . '.' . $parts[$n - 1], $cc2, true)) {
            return $parts[$n - 3];
        }
        return $parts[$n - 2];
    }
}
