<?php
/**
 * 蜘蛛抓取记账（聚合表 spider_hits 的唯一读写入口）。
 *
 * 为什么用「聚合 upsert」而不是明细表：
 *   爬虫会反复抓同一个 URL，明细表会迅速膨胀（且几乎全是重复行）。这里按
 *   (site_id, day, spider, url) 建主键，命中即 `hits+1`，一行的信息量足够支撑
 *   「谁在爬 / 爬了多少 / 爬了哪些页面 / 什么时候爬的」四类报表。
 *
 * 写入时机（两条链路，互为兜底）：
 *   1. 服务端接入（推荐）：站点在疑似爬虫请求上 POST `/spider.php`，可捕获**不执行 JS**
 *      的爬虫（绝大多数字节流爬虫都不执行 JS，这是主流覆盖方式）；
 *   2. 采集端兜底：爬虫若真的执行了 SDK 的 JS（Googlebot / 移动版 Baiduspider 常见），
 *      采集端识别为 bot 时同样记一笔 —— 站长零接入也有数据。
 *
 * 表缺失（老库没跑增量 SQL）时全部静默返回 false，不影响页面与采集。
 */
declare(strict_types=1);

namespace Wstat\Support;

class SpiderLog
{
    public const TABLE = 'spider_hits';

    /** 被爬 URL 落库长度上限（与表结构一致） */
    public const URL_MAX = 255;

    private static ?bool $tableOk = null;

    private static function tableExists(): bool
    {
        if (self::$tableOk === null) {
            try {
                self::$tableOk = array_key_exists('url', Db::tableColumns(self::TABLE));
            } catch (\Throwable $e) {
                self::$tableOk = false;
            }
        }
        return self::$tableOk;
    }

    /** 安装 / 升级后清缓存 */
    public static function resetCache(): void
    {
        self::$tableOk = null;
    }

    /** 是否允许记账：表存在 + 蜘蛛统计开关为开 */
    public static function ready(): bool
    {
        return self::tableExists() && Settings::int('spider_enabled') === 1;
    }

    /**
     * 记一次抓取（聚合自增）。
     *
     * @param int    $siteId 站点 id
     * @param string $day    站点本地日期 Y-m-d
     * @param string $spider 归一化爬虫名（见 Support\Spider）
     * @param string $url    被抓取地址（已由 cleanUrl 清洗）
     * @param int    $bytes  响应字节（拿不到传 0）
     * @param int    $status 响应状态码（拿不到传 0）
     * @param int    $ts     抓取时间（unix；0=当前）
     * @return bool 是否成功记账
     */
    public static function hit(
        int $siteId,
        string $day,
        string $spider,
        string $url,
        int $bytes = 0,
        int $status = 0,
        int $ts = 0
    ): bool {
        if ($siteId <= 0 || $spider === '' || $day === '') {
            return false;
        }
        if (!self::ready()) {
            return false;
        }
        $ts = $ts > 0 ? $ts : time();
        if (mb_strlen($url) > self::URL_MAX) {
            $url = mb_substr($url, 0, self::URL_MAX);
        }
        // 值全部走占位符（含常量 1）—— 占位符序列里绝不夹字面量，避免整段右移。
        // url 同时存原文与 md5：主键用 md5（utf8mb4 下 VARCHAR(255) 进主键在老 MariaDB 上会超 767 字节上限）。
        $args = [
            $siteId, $day, $spider, md5($url), $url,
            1, max(0, $bytes), max(0, $status), $ts, $ts,
        ];
        $sql = 'INSERT INTO ' . self::TABLE . '
                  (site_id,`day`,spider,url_md5,url,hits,bytes,status,first_ts,last_ts)
                VALUES (?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  hits   = hits + VALUES(hits),
                  bytes  = bytes + VALUES(bytes),
                  status = VALUES(status),
                  url    = VALUES(url),
                  last_ts = VALUES(last_ts)';
        try {
            Db::execute($sql, $args);
            return true;
        } catch (\PDOException $e) {
            if (Db::isConnLost($e)) {
                // 常驻进程（worker/cron）里连接可能已被服务端掐断，重连后重试一次
                Db::reconnect();
                try {
                    Db::execute($sql, $args);
                    return true;
                } catch (\Throwable $e2) {
                    self::$tableOk = null;
                    return false;
                }
            }
            self::$tableOk = null;   // 表可能被删/改名，下次重探
            return false;
        } catch (\Throwable $e) {
            self::$tableOk = null;
            return false;
        }
    }

    /**
     * 清洗被抓取地址：统一成「路径?查询」，去掉协议/主机/锚点，超长截断。
     *
     * 爬虫上报的来源有两类：站点给的是 `REQUEST_URI`（已是路径形态），
     * 也有人直接上报完整 URL —— 两种都要能落成同一形态，否则同一个页面会分裂成两行。
     */
    public static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }
        if ($url[0] === '/' || strpos($url, '://') === false) {
            // 已经是相对路径形态（可能带 ?query#hash）
            $path = $url;
        } else {
            $p = parse_url($url);
            if (is_array($p)) {
                $path = (string) ($p['path'] ?? '/');
                if (!empty($p['query'])) {
                    $path .= '?' . $p['query'];
                }
            } else {
                $path = $url;
            }
        }
        // 去掉锚点（同页面不同锚点对爬虫是同一资源）
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $path = substr($path, 0, $hash);
        }
        // 控制字符剔除（防御脏数据/换行注入列表渲染）
        $path = (string) (preg_replace('/[\x00-\x1F\x7F]+/', '', $path) ?? '');
        if ($path === '') {
            return '/';
        }
        if (mb_strlen($path) > self::URL_MAX) {
            $path = mb_substr($path, 0, self::URL_MAX);
        }
        return $path;
    }
}
