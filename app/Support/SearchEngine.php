<?php
/**
 * 搜索引擎来路关键词解析。
 *
 * 从入口 referrer 的 host + query 中识别搜索引擎并提取搜索词。
 * 引擎匹配名单与 Referrer::SEARCH 保持一致（classify 判定 type=search 后才调用，
 * 因此这里不再重复判定是否搜索引擎，只负责「是哪个引擎 + 关键词是什么」）。
 *
 * 已知边界（无法在服务端绕过）：
 *  - 浏览器默认 Referrer-Policy（strict-origin-when-cross-origin）跨站只送 origin，
 *    不带 query → 提取不到关键词（存空，报表归入「未提供」）。站点若希望拿到搜索词，
 *    可自行设置 Referrer-Policy: no-referrer-when-downgrade（或 unsafe-url，不建议）。
 *  - Google 全站加密（几乎总是「未提供」）、百度 link?url= 跳转包装同样拿不到词。
 *  - 部分老链接用 GBK 编码参数，做一次 GBK→UTF-8 兜底转码。
 */
declare(strict_types=1);

namespace Wstat\Support;

class SearchEngine
{
    /** 引擎 host 片段 → 关键词参数名（按 Referrer::SEARCH 同样的 strpos 包含匹配） */
    private const PARAMS = [
        'baidu.com'      => ['wd', 'word'],
        'google.'        => ['q'],
        'bing.com'       => ['q'],
        'so.com'         => ['q'],
        'sogou.com'      => ['query'],
        'sm.cn'          => ['q'],
        'yahoo.'         => ['p', 'q'],
        'yandex.'        => ['text'],
        'duckduckgo.com' => ['q'],
        'ecosia.org'     => ['q'],
        'ask.com'        => ['q'],
        'yisou.com'      => ['q'],
        'toutiao.com'    => ['keyword'],
        'chinaso.com'    => ['q'],
        'petalsearch.com' => ['q'],
    ];

    /**
     * 从 referrer 提取 [engine(品牌名), kw(搜索词)]；解析不出返回 ['', '']。
     * @return array{engine:string, kw:string}
     */
    public static function parse(string $refUrl): array
    {
        $refUrl = trim($refUrl);
        if ($refUrl === '' || $refUrl[0] === '/') {
            return ['engine' => '', 'kw' => ''];   // 站内相对路径不是外链来源
        }
        $host = Referrer::hostOf($refUrl);
        if ($host === '') {
            return ['engine' => '', 'kw' => ''];
        }
        $keys = null;
        foreach (self::PARAMS as $frag => $params) {
            if (strpos($host, $frag) !== false) {
                $keys = $params;
                break;
            }
        }
        if ($keys === null) {
            return ['engine' => '', 'kw' => ''];
        }
        $query = (string) (parse_url($refUrl, PHP_URL_QUERY) ?? '');
        // hash 路由里带参数的落地页兜底：#/s?wd=xx（实际搜索引擎少见，防御性支持）
        if ($query === '' && ($pos = strpos($refUrl, '?')) !== false) {
            $query = substr($refUrl, $pos + 1);
        }
        $kw = self::pick($query, $keys);
        return ['engine' => Referrer::brand($host), 'kw' => $kw];
    }

    /** 便捷方法：仅取关键词（供采集热路径直接入库） */
    public static function kw(string $refUrl): string
    {
        return self::parse($refUrl)['kw'];
    }

    /** 按 $keys 顺序取第一个非空参数并解码 */
    private static function pick(string $query, array $keys): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $q);
        foreach ($keys as $k) {
            $v = isset($q[$k]) && is_string($q[$k]) ? trim($q[$k]) : '';
            if ($v !== '') {
                return self::normalize($v);
            }
        }
        return '';
    }

    /** 清洗 + 编码兜底：控制字符剔除、长度截断、GBK 转 UTF-8 */
    private static function normalize(string $v): string
    {
        // parse_str 已做一次 urldecode，但「+」在原始参数里可能代表空格之外的场景——保持 parse_str 语义即可
        $v = preg_replace('/[\x00-\x1F\x7F]+/u', '', $v) ?? '';
        // 非 UTF-8（常见：百度老参数 GBK）→ 尝试转码，失败则置空（避免乱码入库）
        if (!mb_check_encoding($v, 'UTF-8')) {
            $conv = function_exists('iconv') ? @iconv('GBK', 'UTF-8//IGNORE', $v) : false;
            $v = is_string($conv) && $conv !== '' ? $conv : '';
        }
        if ($v === '') {
            return '';
        }
        return mb_strlen($v) > 200 ? mb_substr($v, 0, 200) : $v;
    }
}
