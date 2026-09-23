<?php
/**
 * 全局过滤器（Filter / Segments 的编译层）
 *
 * 把前端的一组条件编译成 SQL 片段 + 绑定参数。**占位符与参数由本类一次性产出**，
 * 调用方只做「把 sql 拼到 WHERE 后面、把 args 顺序展开」，不得再自行往序列里插常量字面量 ——
 * v1.0.13 出过一次事故：占位符序列中间被塞进常量字面量，导致整段参数右移
 * （`entry_url` 变成 '0'、`pageviews` 变 0），而「占位符数 == 参数数」的自检**同时多一**、
 * 永远通过。本类是那次教训的固化：**条件与参数只在同一处生成**。
 *
 * 支持维度（8 维）：
 *   path      页面 URL（`sessions` 表上落到 `entry_url`，入口页语义）
 *   source    渠道（direct / search / social / link / paid / utm / internal / other）
 *   ref_host  外链主机（**仅 events 有**：sessions 表不存来路主机，编译时跳过并回报）
 *   country / province / device / browser / os
 *
 * 支持操作符：eq / neq / contains / ncontains / prefix / in
 * 请求参数格式：`f=[{"f":"path","o":"eq","v":"/pricing"},{"f":"device","o":"in","v":["mobile","tablet"]}]`
 * （URL 编码的 JSON；`v` 在 `in` 时是数组，其余是字符串）
 */
declare(strict_types=1);

namespace Wstat\Support;

class Filter
{
    /** 条件数上限（防构造超长 SQL） */
    public const MAX_CONDITIONS = 8;
    /** 单值长度上限 */
    private const MAX_VALUE_LEN = 200;
    /** in 操作符的取值个数上限 */
    private const MAX_IN_VALUES = 50;

    /**
     * 与 `StatsController::URL_PATH_EXPR` / `OpenController::URL_PATH_EXPR` 同源。
     * 三处各自私有维护（跨控制器），**改一处必须三处同时改**，否则「按页面过滤」与
     * 「页面明细」两处的路径口径会分叉。
     */
    public const URL_PATH_EXPR = "IF(LOCATE('//', url) > 0, IF(LOCATE('/', url, LOCATE('//', url) + 2) > 0, SUBSTRING(url, LOCATE('/', url, LOCATE('//', url) + 2)), '/'), url)";

    /**
     * 维度白名单：前端 key => [events 列表达式, sessions 列表达式]。
     * 空串表示该表不支持这个维度。列名一律来自本表常量（白名单），因此拼接进 SQL 是安全的；
     * 用户提供的**值永远走参数绑定**。
     */
    private const COLS = [
        'path'     => ['events' => '%PATH%', 'sessions' => 'entry_url'],
        'source'   => ['events' => 'source',   'sessions' => 'source'],
        'ref_host' => ['events' => 'ref_host', 'sessions' => ''],
        'country'  => ['events' => 'country',  'sessions' => 'country'],
        'province' => ['events' => 'province', 'sessions' => 'province'],
        'device'   => ['events' => 'device',   'sessions' => 'device'],
        'browser'  => ['events' => 'browser',  'sessions' => 'browser'],
        'os'       => ['events' => 'os',       'sessions' => 'os'],
    ];

    private const OPS = ['eq', 'neq', 'contains', 'ncontains', 'prefix', 'in'];

    /** 各维度的展示名（用于把条件渲染成人话，例如「页面 = /pricing」） */
    private const LABELS = [
        'path' => '页面', 'source' => '来源渠道', 'ref_host' => '外链主机',
        'country' => '国家', 'province' => '省份', 'device' => '设备',
        'browser' => '浏览器', 'os' => '操作系统',
    ];

    /**
     * 解析请求里的条件（JSON 字符串或已解码数组）。
     * 非法项一律**静默丢弃**（维度不在白名单、操作符不认识、值为空）——
     * 过滤器是「用户可随手改 URL」的入口，宁可少过滤也不要 500。
     */
    public static function parse($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (count($out) >= self::MAX_CONDITIONS) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $f = trim((string) ($item['f'] ?? ''));
            $o = trim((string) ($item['o'] ?? 'eq'));
            if (!isset(self::COLS[$f]) || !in_array($o, self::OPS, true)) {
                continue;
            }
            $v = $item['v'] ?? '';

            if (is_array($v)) {
                if ($o !== 'in') {
                    continue;
                }
                $vals = [];
                foreach ($v as $one) {
                    if (!is_scalar($one)) {
                        continue;
                    }
                    $one = self::cut((string) $one);
                    if ($one !== '') {
                        $vals[] = $one;
                    }
                    if (count($vals) >= self::MAX_IN_VALUES) {
                        break;
                    }
                }
                if (empty($vals)) {
                    continue;
                }
                $out[] = ['f' => $f, 'o' => $o, 'v' => array_values(array_unique($vals))];
            } else {
                if ($o === 'in' || !is_scalar($v)) {
                    continue;
                }
                $s = self::cut((string) $v);
                if ($s === '') {
                    continue;
                }
                $out[] = ['f' => $f, 'o' => $o, 'v' => $s];
            }
        }
        return $out;
    }

    /** 是否存在有效条件（调用方据此决定「要不要放弃预聚合快路径」） */
    public static function active(array $filters): bool
    {
        return count($filters) > 0;
    }

    /**
     * 编译成 SQL 片段。
     *
     * @param array  $filters self::parse() 的产物
     * @param string $table   'events' | 'sessions'
     * @return array{sql:string,args:array<int,string>,skipped:array<int,string>,count:int}
     *         sql 为空串表示「没有可用条件」（可能因为全部被跳过），调用方应回退到无过滤逻辑。
     */
    public static function sql(array $filters, string $table = 'events'): array
    {
        $t = $table === 'sessions' ? 'sessions' : 'events';
        $where = [];
        $args = [];
        $skipped = [];

        foreach ($filters as $c) {
            $f = (string) ($c['f'] ?? '');
            $o = (string) ($c['o'] ?? '');
            $col = self::COLS[$f][$t] ?? '';

            // 该表没有这一维（如 sessions 上的 ref_host）：跳过而不是报错 ——
            // 同一组过滤器要能同时用在 events 与 sessions 两类端点上。
            if ($col === '') {
                $skipped[] = $f;
                continue;
            }
            if ($col === '%PATH%') {
                $col = self::URL_PATH_EXPR;
            }

            switch ($o) {
                case 'eq':
                    $where[] = '(' . $col . ') = ?';
                    $args[] = (string) $c['v'];
                    break;
                case 'neq':
                    $where[] = '(' . $col . ') <> ?';
                    $args[] = (string) $c['v'];
                    break;
                case 'contains':
                    $where[] = '(' . $col . ') LIKE ?';
                    $args[] = '%' . self::esc((string) $c['v']) . '%';
                    break;
                case 'ncontains':
                    $where[] = '(' . $col . ') NOT LIKE ?';
                    $args[] = '%' . self::esc((string) $c['v']) . '%';
                    break;
                case 'prefix':
                    $where[] = '(' . $col . ') LIKE ?';
                    $args[] = self::esc((string) $c['v']) . '%';
                    break;
                case 'in':
                    $vals = [];
                    foreach ((array) $c['v'] as $one) {
                        $one = (string) $one;
                        if ($one !== '') {
                            $vals[] = $one;
                        }
                    }
                    if (empty($vals)) {
                        break;
                    }
                    $ph = implode(',', array_fill(0, count($vals), '?'));
                    $where[] = '(' . $col . ') IN (' . $ph . ')';
                    foreach ($vals as $one) {
                        $args[] = $one;
                    }
                    break;
                default:
                    // parse() 已挡住未知操作符，这里只是防御
                    $skipped[] = $f;
                    break;
            }
        }

        return [
            'sql'     => $where === [] ? '' : implode(' AND ', $where),
            'args'    => $args,
            'skipped' => array_values(array_unique($skipped)),
            'count'   => count($where),
        ];
    }

    /** 把条件渲染成人话（前端 tooltip / 分享页标题用），例：`页面 = /pricing · 设备 = 移动` */
    public static function label(array $filters): string
    {
        $opText = [
            'eq' => '=', 'neq' => '≠', 'contains' => '包含', 'ncontains' => '不含',
            'prefix' => '开头是', 'in' => '属于',
        ];
        $parts = [];
        foreach ($filters as $c) {
            $f = (string) ($c['f'] ?? '');
            $o = (string) ($c['o'] ?? '');
            if (!isset(self::COLS[$f])) {
                continue;
            }
            $v = $c['v'] ?? '';
            $v = is_array($v) ? implode(' / ', array_map('strval', $v)) : (string) $v;
            $parts[] = (self::LABELS[$f] ?? $f) . ' ' . ($opText[$o] ?? $o) . ' ' . $v;
        }
        return implode(' · ', $parts);
    }

    /** 解析后的条件是否命中某个维度（前端 UI 判重用） */
    public static function has(array $filters, string $dim): bool
    {
        foreach ($filters as $c) {
            if ((string) ($c['f'] ?? '') === $dim) {
                return true;
            }
        }
        return false;
    }

    private static function cut(string $s): string
    {
        $s = trim($s);
        return mb_strlen($s) > self::MAX_VALUE_LEN ? mb_substr($s, 0, self::MAX_VALUE_LEN) : $s;
    }

    /** LIKE 通配符转义（MySQL 默认转义符为反斜杠） */
    private static function esc(string $s): string
    {
        return addcslashes($s, "\\%_");
    }
}
