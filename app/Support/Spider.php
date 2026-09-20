<?php
/**
 * 蜘蛛 / 爬虫识别与归一化。
 *
 * 与 `UaParser::isBot()` 的分工：
 *  - `isBot()` 只回答「是不是爬虫/自动化工具」（采集端过滤用，命中即丢弃，不关心是谁）；
 *  - 本类回答「是谁在爬」（蜘蛛统计用），把 UA 归一到一个稳定的产品名 + 类别，
 *    便于按天聚合、排行、看趋势。
 *
 * 设计要点：
 *  - **是否爬虫一律以 `UaParser::isBot()` 为准**，本类的规则表只负责「叫什么、属于哪一类」。
 *    这条约束很关键：采集端丢弃爬虫用的就是 `isBot()`，如果本类自己另立一套判定，
 *    就可能把真实访客认成爬虫 → 开着「爬虫过滤」时直接丢访客数据。规则表只做命名，
 *    即便多认了几个名字，也绝不会扩大「被判定为爬虫」的集合。
 *  - 规则表**顺序敏感**：越具体的片段必须排在越前面（`baiduspider-image` 先于 `baiduspider`，
 *    `googlebot-image` 先于 `googlebot`），首个命中即返回；新增规则时不要往中间乱插。
 *  - 名称一律用产品原名（Baiduspider / Googlebot …），不翻译 —— 它是标识符，翻译反而难对齐。
 *    类别（search/seo/ai/…）是稳定 key，展示文案由前端 i18n 负责。
 *  - 规则表覆盖不到的 bot（`isBot()` 为真但无规则命中）统一归为 `其它爬虫` / 类别 `other`，
 *    不会因为「不认识」而丢掉数据。
 *  - 纯函数、无 IO：自测可直接喂 UA 断言，不需要数据库或 Web 服务。
 */
declare(strict_types=1);

namespace Wstat\Support;

class Spider
{
    /** 类别 key */
    public const KIND_SEARCH  = 'search';    // 搜索引擎
    public const KIND_SEO     = 'seo';       // SEO / 竞品分析工具
    public const KIND_AI      = 'ai';        // AI 训练与检索
    public const KIND_SOCIAL  = 'social';    // 社交平台链接预览
    public const KIND_MONITOR = 'monitor';   // 可用性监控
    public const KIND_FEED    = 'feed';      // 订阅 / RSS 抓取
    public const KIND_TOOL    = 'tool';      // 自动化脚本 / HTTP 客户端
    public const KIND_OTHER   = 'other';

    /** 类别展示名（前端 i18n 未覆盖时的兜底文案） */
    public const KIND_LABELS = [
        self::KIND_SEARCH  => '搜索引擎',
        self::KIND_SEO     => 'SEO / 分析工具',
        self::KIND_AI      => 'AI 抓取',
        self::KIND_SOCIAL  => '社交预览',
        self::KIND_MONITOR => '可用性监控',
        self::KIND_FEED    => '订阅抓取',
        self::KIND_TOOL    => '自动化工具',
        self::KIND_OTHER   => '其它',
    ];

    /** 规则表未命中但确实判定为 bot 时的归一名 */
    public const FALLBACK_NAME = '其它爬虫';

    /**
     * UA 片段（小写）=> [归一化名称, 类别]。**顺序敏感，具体者在前。**
     * @var array<string, array{0:string,1:string}>
     */
    private const RULES = [
        // ---- 搜索引擎：百度 ----
        'baiduspider-image'  => ['Baiduspider', self::KIND_SEARCH],
        'baiduspider-video'  => ['Baiduspider', self::KIND_SEARCH],
        'baiduspider-news'   => ['Baiduspider', self::KIND_SEARCH],
        'baiduspider-mobile' => ['Baiduspider', self::KIND_SEARCH],
        'baiduspider-render' => ['Baiduspider', self::KIND_SEARCH],
        'baiduspider'        => ['Baiduspider', self::KIND_SEARCH],
        'baidu.com/search'   => ['Baiduspider', self::KIND_SEARCH],
        // ---- 搜索引擎：Google（含图片/视频/新闻/广告/检查工具）----
        'googlebot-image'    => ['Googlebot', self::KIND_SEARCH],
        'googlebot-video'    => ['Googlebot', self::KIND_SEARCH],
        'googlebot-news'     => ['Googlebot', self::KIND_SEARCH],
        'storebot-google'    => ['Googlebot', self::KIND_SEARCH],
        'google-inspectiontool' => ['Googlebot', self::KIND_SEARCH],
        'google-read-aloud'  => ['Googlebot', self::KIND_SEARCH],
        'googleother'        => ['Googlebot', self::KIND_SEARCH],
        'adsbot-google'      => ['Googlebot', self::KIND_SEARCH],
        'googlebot'          => ['Googlebot', self::KIND_SEARCH],
        // ---- 搜索引擎：必应 / MSN ----
        'bingbot'            => ['Bingbot', self::KIND_SEARCH],
        'bingpreview'        => ['Bingbot', self::KIND_SEARCH],
        'adidxbot'           => ['Bingbot', self::KIND_SEARCH],
        'msnbot'             => ['Bingbot', self::KIND_SEARCH],
        // ---- 搜索引擎：国内其它 ----
        'sogou web spider'   => ['Sogou Spider', self::KIND_SEARCH],
        'sogou inst spider'  => ['Sogou Spider', self::KIND_SEARCH],
        'sogou spider'       => ['Sogou Spider', self::KIND_SEARCH],
        'sogou orion'        => ['Sogou Spider', self::KIND_SEARCH],
        'sogou'              => ['Sogou Spider', self::KIND_SEARCH],
        '360spider'          => ['360Spider', self::KIND_SEARCH],
        'haosouspider'       => ['360Spider', self::KIND_SEARCH],
        'so.com'             => ['360Spider', self::KIND_SEARCH],
        'yisouspider'        => ['YisouSpider', self::KIND_SEARCH],   // 神马
        'yisou'              => ['YisouSpider', self::KIND_SEARCH],
        'bytespider'         => ['Bytespider', self::KIND_SEARCH],    // 字节
        'toutiaospider'      => ['Bytespider', self::KIND_SEARCH],
        'petalbot'           => ['PetalBot', self::KIND_SEARCH],      // 华为花瓣
        'chinaso'            => ['ChinaSo', self::KIND_SEARCH],
        // ---- 搜索引擎：海外其它 ----
        'yandexbot'          => ['YandexBot', self::KIND_SEARCH],
        'yandex'             => ['YandexBot', self::KIND_SEARCH],
        'duckduckbot'        => ['DuckDuckBot', self::KIND_SEARCH],
        'duckduckgo'         => ['DuckDuckBot', self::KIND_SEARCH],
        'applebot'           => ['Applebot', self::KIND_SEARCH],
        'yahoo! slurp'       => ['Yahoo! Slurp', self::KIND_SEARCH],
        'naver'              => ['Naver', self::KIND_SEARCH],
        'seznambot'          => ['SeznamBot', self::KIND_SEARCH],
        'ecosia'             => ['Ecosia', self::KIND_SEARCH],
        'bravebot'           => ['BraveBot', self::KIND_SEARCH],
        'startmebot'         => ['Startpage', self::KIND_SEARCH],
        // ---- AI 训练 / 检索 ----
        'gptbot'             => ['GPTBot', self::KIND_AI],
        'oai-searchbot'      => ['OAI-SearchBot', self::KIND_AI],
        'chatgpt-user'       => ['ChatGPT-User', self::KIND_AI],
        'claudebot'          => ['ClaudeBot', self::KIND_AI],
        'claude-web'         => ['ClaudeBot', self::KIND_AI],
        'anthropic-ai'       => ['ClaudeBot', self::KIND_AI],
        'perplexitybot'      => ['PerplexityBot', self::KIND_AI],
        'google-extended'    => ['Google-Extended', self::KIND_AI],
        'ccbot'              => ['CCBot', self::KIND_AI],
        'amazonbot'          => ['Amazonbot', self::KIND_AI],
        'meta-externalagent' => ['Meta-ExternalAgent', self::KIND_AI],
        'applebot-extended'  => ['Applebot-Extended', self::KIND_AI],
        'youbot'             => ['YouBot', self::KIND_AI],
        'cohere-ai'          => ['Cohere', self::KIND_AI],
        'diffbot'            => ['Diffbot', self::KIND_AI],
        // ---- SEO / 竞品分析 ----
        'ahrefsbot'          => ['AhrefsBot', self::KIND_SEO],
        'semrushbot'         => ['SemrushBot', self::KIND_SEO],
        'mj12bot'            => ['MJ12bot', self::KIND_SEO],
        'dotbot'             => ['DotBot', self::KIND_SEO],
        'dataforseo'         => ['DataForSEO', self::KIND_SEO],
        'blexbot'            => ['BLEXBot', self::KIND_SEO],
        'screaming frog'     => ['Screaming Frog', self::KIND_SEO],
        'serpstatbot'        => ['SerpstatBot', self::KIND_SEO],
        'linkdexbot'         => ['LinkdexBot', self::KIND_SEO],
        'megaindex'          => ['MegaIndex', self::KIND_SEO],
        'seokicks'           => ['Seokicks', self::KIND_SEO],
        'siteauditbot'       => ['SiteAuditBot', self::KIND_SEO],
        // ---- 社交平台预览 ----
        'facebookexternalhit' => ['FacebookBot', self::KIND_SOCIAL],
        'facebookcatalog'    => ['FacebookBot', self::KIND_SOCIAL],
        'meta-externalfetcher' => ['FacebookBot', self::KIND_SOCIAL],
        'twitterbot'         => ['Twitterbot', self::KIND_SOCIAL],
        'linkedinbot'        => ['LinkedInBot', self::KIND_SOCIAL],
        'telegrambot'        => ['TelegramBot', self::KIND_SOCIAL],
        'whatsapp'           => ['WhatsApp', self::KIND_SOCIAL],
        'slackbot'           => ['Slackbot', self::KIND_SOCIAL],
        'slack-imgproxy'     => ['Slackbot', self::KIND_SOCIAL],
        'discordbot'         => ['Discordbot', self::KIND_SOCIAL],
        'pinterest'          => ['Pinterestbot', self::KIND_SOCIAL],
        'skypeuripreview'    => ['SkypeBot', self::KIND_SOCIAL],
        'dingtalk'           => ['DingTalkBot', self::KIND_SOCIAL],
        'wechat'             => ['WeChatBot', self::KIND_SOCIAL],
        // ---- 可用性监控 ----
        'uptimerobot'        => ['UptimeRobot', self::KIND_MONITOR],
        'pingdom'            => ['Pingdom', self::KIND_MONITOR],
        'statuscake'         => ['StatusCake', self::KIND_MONITOR],
        'site24x7'           => ['Site24x7', self::KIND_MONITOR],
        'newrelicpinger'     => ['NewRelic', self::KIND_MONITOR],
        'nagios'             => ['Nagios', self::KIND_MONITOR],
        'zabbix'             => ['Zabbix', self::KIND_MONITOR],
        'datadog'            => ['Datadog', self::KIND_MONITOR],
        'betteruptime'       => ['BetterUptime', self::KIND_MONITOR],
        'checkly'            => ['Checkly', self::KIND_MONITOR],
        // ---- 订阅 / RSS ----
        'feedfetcher-google' => ['FeedFetcher', self::KIND_FEED],
        'feedfetcher'        => ['FeedFetcher', self::KIND_FEED],
        'feedly'             => ['Feedly', self::KIND_FEED],
        'rssowl'             => ['RSSOwl', self::KIND_FEED],
        'superfeedr'         => ['Superfeedr', self::KIND_FEED],
        'simplepie'          => ['SimplePie', self::KIND_FEED],
        'inoreader'          => ['Inoreader', self::KIND_FEED],
        // ---- 自动化 / HTTP 客户端（采集端过滤的另一半，这里也记账）----
        'python-requests'    => ['Requests', self::KIND_TOOL],
        'python-urllib'      => ['urllib', self::KIND_TOOL],
        'aiohttp'            => ['aiohttp', self::KIND_TOOL],
        'httpx'              => ['httpx', self::KIND_TOOL],
        'scrapy'             => ['Scrapy', self::KIND_TOOL],
        'go-http-client'     => ['Go HTTP', self::KIND_TOOL],
        'okhttp'             => ['OkHttp', self::KIND_TOOL],
        'java/'              => ['Java HTTP', self::KIND_TOOL],
        'apache-httpclient'  => ['Java HTTP', self::KIND_TOOL],
        'axios/'             => ['axios', self::KIND_TOOL],
        'node-fetch'         => ['node-fetch', self::KIND_TOOL],
        'undici'             => ['undici', self::KIND_TOOL],
        'curl/'              => ['curl', self::KIND_TOOL],
        'wget'               => ['Wget', self::KIND_TOOL],
        'libwww-perl'        => ['libwww-perl', self::KIND_TOOL],
        'postman'            => ['Postman', self::KIND_TOOL],
        'insomnia'           => ['Insomnia', self::KIND_TOOL],
        'headlesschrome'     => ['Headless Chrome', self::KIND_TOOL],
        'phantomjs'          => ['PhantomJS', self::KIND_TOOL],
        'puppeteer'          => ['Puppeteer', self::KIND_TOOL],
        'playwright'         => ['Playwright', self::KIND_TOOL],
        'selenium'           => ['Selenium', self::KIND_TOOL],
        'lighthouse'         => ['Lighthouse', self::KIND_TOOL],
        'apachebench'        => ['ApacheBench', self::KIND_TOOL],
        'jmeter'             => ['JMeter', self::KIND_TOOL],
        'loadrunner'         => ['LoadRunner', self::KIND_TOOL],
        'wrk'                => ['wrk', self::KIND_TOOL],
    ];

    /** 名称 → 类别（由 RULES 反推，用于按已落库的名称还原类别） */
    private static ?array $nameKind = null;

    /**
     * 识别 UA。
     *
     * **「是不是爬虫」只由 `UaParser::isBot()` 决定**，规则表只负责取名 —— 这样本类
     * 永远不会比采集端过滤认得更宽，也就不会出现「被本类认成爬虫的其实是真实访客」。
     *
     * @return array{bot:bool,name:string,kind:string,fallback:bool}
     *         bot=false 时 name/kind 为空串 —— 调用方据此「不记账」。
     */
    public static function detect(string $ua): array
    {
        if (!UaParser::isBot($ua)) {
            return ['bot' => false, 'name' => '', 'kind' => '', 'fallback' => false];
        }
        $low = strtolower(trim($ua));
        foreach (self::RULES as $needle => [$name, $kind]) {
            if (strpos($low, $needle) !== false) {
                return ['bot' => true, 'name' => $name, 'kind' => $kind, 'fallback' => false];
            }
        }
        // 是爬虫但规则表没覆盖：不丢数据，归入「其它爬虫」
        return ['bot' => true, 'name' => self::FALLBACK_NAME, 'kind' => self::KIND_OTHER, 'fallback' => true];
    }

    /** 仅取归一化名称；非爬虫返回 '' */
    public static function name(string $ua): string
    {
        return self::detect($ua)['name'];
    }

    /** 仅取类别；非爬虫返回 '' */
    public static function kind(string $ua): string
    {
        return self::detect($ua)['kind'];
    }

    /** 按已落库的名称还原类别（名称不在表内 → other） */
    public static function kindOfName(string $name): string
    {
        if (self::$nameKind === null) {
            $map = [];
            foreach (self::RULES as [$n, $k]) {
                $map[$n] = $k;
            }
            $map[self::FALLBACK_NAME] = self::KIND_OTHER;
            self::$nameKind = $map;
        }
        return self::$nameKind[$name] ?? self::KIND_OTHER;
    }

    /** 类别展示名（兜底；正常走前端 i18n） */
    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? $kind;
    }
}
