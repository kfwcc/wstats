<?php
/**
 * 系统设置（sys_settings 键值表）+ 管理员判定。
 *
 * 设计要点：
 * - 全部读取带优雅降级：sys_settings 表不存在时返回默认值（老库未跑增量脚本不影响运行）；
 * - users.is_admin 列不存在时回退「id=1 为管理员」（与首个注册账号一致）；
 * - 写入走白名单，禁止任意键写入。
 *
 * 键清单（DEFAULTS 即白名单来源）：
 *   registration_enabled  1/0  是否开放注册
 *   collect_enabled       1/0  采集总开关（关闭后 SDK 上报被拒）
 *   alert_enabled         1/0  告警/日报推送总开关（cron 侧生效）
 *   smtp_host / smtp_port / smtp_secure(ssl|tls|none) / smtp_username / smtp_password
 *   smtp_from_name / smtp_from_email            邮件发信配置（email 渠道与测试邮件共用）
 *   retention_days        ''   事件明细保留天数；空=跟随 config.php 的 collect.event_retention
 *   bot_filter_enabled    1/0  爬虫过滤开关（开启时采集端丢弃 UA 命中的爬虫/HTTP 客户端）
 *   email_verify_enabled  1/0  注册需邮箱验证码（取码前还须通过图形验证码，见 Support\Verify）
 *   screen_password       ''   数据大屏访问密码；空=不启用（大屏链接仅靠只读 token 保护）
 *   sdk_snippet           ''   自定义「站点接入代码」模板；空=用内置默认 SDK_SNIPPET_DEFAULT
 *                              （占位符 {site_key} / {host}，{origin} 是 {host} 的别名）
 *   inject_code           ''   「外部代码」：管理员粘贴的第三方统计 / 在线客服等脚本，
 *                              由 server/public/index.php 注入本站每个页面 </head> 前（见 Support\PageInject）
 *   ip_source             'remote_addr'  访客真实 IP 的取值来源（**手动指定，程序不做任何自动判断**）：
 *                              x_forwarded_for | x_real_ip | remote_addr | custom
 *                              见 Support\Util::IP_SOURCES 与 ipTrace()
 *   ip_source_header      ''   ip_source=custom 时取哪个请求头（如 CF-Connecting-IP）
 */
declare(strict_types=1);

namespace Wstat\Support;

use Wstat\Http\Request;

class Settings
{
    public const TABLE = 'sys_settings';

    /** @var bool|null sys_settings 表是否存在（进程内缓存） */
    private static ?bool $tableOk = null;

    /** @var bool|null users.is_admin 列是否存在（进程内缓存） */
    private static ?bool $adminColOk = null;

    public const DEFAULTS = [
        'registration_enabled' => '1',
        'collect_enabled'      => '1',
        'alert_enabled'        => '1',
        'retention_days'       => '',
        'bot_filter_enabled'   => '1',
        'email_verify_enabled' => '1',
        'screen_password'      => '',
        'sdk_snippet'          => '',
        'inject_code'          => '',
        'smtp_host'            => '',
        'smtp_port'            => '465',
        'smtp_secure'          => 'ssl',
        'smtp_username'        => '',
        'smtp_password'        => '',
        'smtp_from_name'       => 'WebStats',
        'smtp_from_email'      => '',
    ];

    /** 设置项白名单（与 DEFAULTS 键一致） */
    public const KEYS = [
        'registration_enabled', 'collect_enabled', 'alert_enabled', 'retention_days',
        'bot_filter_enabled', 'email_verify_enabled', 'screen_password', 'sdk_snippet', 'inject_code',
        'ip_source', 'ip_source_header',
        'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password',
        'smtp_from_name', 'smtp_from_email',
    ];

    /**
     * 内置的「站点接入代码」模板（管理员未自定义时使用）。
     * 占位符：{site_key}=站点 key，{host}=本站地址（含协议、无尾斜杠），{origin}=同 {host}。
     */
    public const SDK_SNIPPET_DEFAULT = '<script async defer src="{host}/sdk/wstat.js" data-site-key="{site_key}" data-host="{host}"></script>';

    /** 自定义模板的单次提交长度上限（多行代码，放宽到 4000 字符） */
    public const SDK_SNIPPET_MAX = 4000;

    /** 「外部代码」单次提交长度上限（第三方统计 / 在线客服脚本可能较长，放宽到 8000 字符） */
    public const INJECT_CODE_MAX = 8000;

    private static function tableExists(): bool
    {
        if (self::$tableOk === null) {
            try {
                $row = Db::first(
                    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1',
                    [self::TABLE]
                );
                self::$tableOk = $row !== null;
            } catch (\Throwable $e) {
                self::$tableOk = false;
            }
        }
        return self::$tableOk;
    }

    private static function adminColumnExists(): bool
    {
        if (self::$adminColOk === null) {
            try {
                // tableColumns() 返回「键=列名」，必须用 array_key_exists 判断
                self::$adminColOk = array_key_exists('is_admin', Db::tableColumns('users'));
            } catch (\Throwable $e) {
                self::$adminColOk = false;
            }
        }
        return self::$adminColOk;
    }

    /** 读取全部设置（合并默认值；表缺失时仅返回默认值） */
    public static function all(): array
    {
        $out = self::DEFAULTS;
        if (!self::tableExists()) {
            return $out;
        }
        try {
            $rows = Db::select('SELECT name,value FROM ' . self::TABLE);
            foreach ($rows as $r) {
                if (array_key_exists((string) $r['name'], $out)) {
                    $out[(string) $r['name']] = (string) $r['value'];
                }
            }
        } catch (\Throwable $e) {
            self::$tableOk = null; // 下次重探
        }
        return $out;
    }

    /** 读单键（带默认值） */
    public static function get(string $key): string
    {
        if (!self::tableExists() || !array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key] ?? '';
        }
        try {
            $v = Db::value('SELECT value FROM ' . self::TABLE . ' WHERE name=? LIMIT 1', [$key]);
            return $v === null ? self::DEFAULTS[$key] : (string) $v;
        } catch (\Throwable $e) {
            self::$tableOk = null;
            return self::DEFAULTS[$key];
        }
    }

    /** 读单键并转 int（开关用） */
    public static function int(string $key): int
    {
        return (int) self::get($key) === 1 ? 1 : 0;
    }

    /**
     * 「站点接入代码」模板：管理员自定义优先，留空则回落到内置默认。
     *
     * 模板是纯文本，展示时由前端替换占位符；服务端从不 eval / 输出到页面 DOM，
     * 因此不存在注入风险（它本来就是「给访客网站粘贴的代码」）。
     */
    public static function sdkSnippetTemplate(): string
    {
        $tpl = trim(self::get('sdk_snippet'));
        return $tpl !== '' ? $tpl : self::SDK_SNIPPET_DEFAULT;
    }

    /** 管理员是否自定义过接入代码（用于前端提示/复位按钮） */
    public static function sdkSnippetIsCustom(): bool
    {
        return trim(self::get('sdk_snippet')) !== '';
    }

    /**
     * 访客 IP 取值来源（**手动指定**，程序不做任何自动判断）。
     *
     * 一次查询取回两个键，避免在采集热路径上多打一次数据库往返。
     * 表缺失 / 查询失败 / 从未保存过 → 返回空串，由 `Util::ipSourceSetting()` 回落到
     * `config.php` 的 `collect.ip_source` 默认值（再不行就是 remote_addr）。
     *
     * @return array{source:string,header:string}
     */
    public static function ipSource(): array
    {
        $out = ['source' => '', 'header' => ''];
        if (!self::tableExists()) {
            return $out;
        }
        try {
            $rows = Db::select(
                'SELECT name,value FROM ' . self::TABLE . " WHERE name IN ('ip_source','ip_source_header')"
            );
            foreach ($rows as $r) {
                $n = (string) $r['name'];
                if ($n === 'ip_source') {
                    $out['source'] = trim((string) $r['value']);
                } elseif ($n === 'ip_source_header') {
                    $out['header'] = trim((string) $r['value']);
                }
            }
        } catch (\Throwable $e) {
            self::$tableOk = null;
        }
        return $out;
    }

    /**
     * 用站点信息渲染接入代码（占位符替换）。
     * 抽成服务端方法是为了自测能直接断言替换结果，不必起浏览器。
     */
    public static function renderSnippet(string $siteKey, string $host, ?string $template = null): string
    {
        $host = rtrim($host, '/');
        $tpl = $template ?? self::sdkSnippetTemplate();
        return str_replace(['{site_key}', '{host}', '{origin}'], [$siteKey, $host, $host], $tpl);
    }

    /**
     * 批量写入（仅白名单键；值转 string）。
     * @param array<string,string|int> $kv
     * @return int 实际写入键数
     */
    public static function set(array $kv): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        $n = 0;
        $now = time();
        foreach ($kv as $k => $v) {
            if (!in_array((string) $k, self::KEYS, true)) {
                continue;
            }
            try {
                Db::execute(
                    'INSERT INTO ' . self::TABLE . ' (name,value,updated_at) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)',
                    [(string) $k, (string) $v, $now]
                );
                $n++;
            } catch (\Throwable $e) {
                self::$tableOk = null;
            }
        }
        return $n;
    }

    /** 管理员判定：is_admin=1；列不存在时回退 id=1 */
    public static function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            if (self::adminColumnExists()) {
                return (int) Db::value('SELECT is_admin FROM users WHERE id=? LIMIT 1', [$userId]) === 1;
            }
            $min = Db::value('SELECT MIN(id) FROM users');
            return $userId === (int) $min;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 管理员门卫：非管理员 403 */
    public static function requireAdmin(Request $req): array
    {
        $u = Auth::requireUser($req);
        if (!self::isAdmin((int) $u['id'])) {
            wstat_err('仅系统管理员可操作', 403, 403);
        }
        return $u;
    }

    /** 重置进程内探测缓存（安装/升级后调用） */
    public static function resetCache(): void
    {
        self::$tableOk = null;
        self::$adminColOk = null;
    }
}
