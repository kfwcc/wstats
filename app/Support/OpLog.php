<?php
/**
 * 用户操作日志（个人中心「我的日志」/ 用户管理审计）
 *
 * 原则：
 *  - 只追加、不修改；detail 是给人看的一句话（≤500 字符，超长截断）；
 *  - 表缺失（老库未跑增量 SQL）时**静默跳过**，绝不因日志写入失败阻断业务主流程
 *    （登录/改密等调用方不应感知本类的存在）；
 *  - IP 取值统一走 Util::clientIp()（手动指定来源，见系统设置 → 真实 IP 采集）。
 */
declare(strict_types=1);

namespace Wstat\Support;

class OpLog
{
    /** 全部动作枚举（新动作必须加在这里，前端按 key 翻译） */
    public const ACTIONS = [
        'login',              // 登录成功
        'logout',             // 退出登录
        'profile_update',     // 修改个人资料
        'password_change',    // 本人修改密码
        'password_reset',     // 通过邮箱验证码重置密码
        'user_create',        // 管理员创建用户
        'user_update',        // 管理员更新用户
        'user_disable',       // 管理员禁用用户
        'user_enable',        // 管理员启用用户
        'user_delete',        // 管理员删除用户
        'user_reset_password',// 管理员重置用户密码（记到被操作者名下）
    ];

    private static ?bool $tableOk = null;

    /** 上一次探测失败的原因（供「我的操作日志」页面诊断展示） */
    private static string $lastErr = '';

    /**
     * user_op_logs 表是否可用（进程内缓存一次）。
     *
     * 探测走 `SHOW COLUMNS`（即 Db::tableColumns），**不用 information_schema**：
     *   · 共享主机 / 云数据库常见「查不了 information_schema」或 `DATABASE()` 取空，
     *     用它判表会把「表明明建好了」误判成缺表，页面一直提示去执行 SQL（真实故障）；
     *   · SHOW COLUMNS 是直接对着目标表发问 —— 表在就成功、不在就抛 1146，
     *     结论与后续真正的读写行为完全一致，不存在探测与使用两套口径。
     * 同时要求三个必需列存在：表建错结构（例如手工改了列名）也算不可用，
     * 否则探测通过而写入静默失败，问题会更难查。
     */
    public static function available(): bool
    {
        if (self::$tableOk === null) {
            try {
                $cols = Db::tableColumns('user_op_logs');
                self::$tableOk = isset($cols['user_id'], $cols['action'], $cols['created_at']);
                if (!self::$tableOk) {
                    self::$lastErr = '表存在，但缺少必需列：user_id / action / created_at';
                }
            } catch (\Throwable $e) {
                self::$tableOk = false;
                self::$lastErr = $e->getMessage();
            }
        }
        return self::$tableOk;
    }

    /** 当前连接实际选中的数据库名（诊断用；取不到返回空串） */
    public static function dbName(): string
    {
        try {
            return (string) Db::value('SELECT DATABASE()');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 建表语句（表缺失时原样发给前端，让用户复制到**当前库**执行，避免手抄出错） */
    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `user_op_logs` (\n"
            . "  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `user_id`    INT UNSIGNED    NOT NULL,\n"
            . "  `action`     VARCHAR(40)     NOT NULL,\n"
            . "  `detail`     VARCHAR(500)    NOT NULL DEFAULT '',\n"
            . "  `ip`         VARCHAR(45)     NOT NULL DEFAULT '',\n"
            . "  `ua`         VARCHAR(255)    NOT NULL DEFAULT '',\n"
            . "  `created_at` INT UNSIGNED    NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  KEY `idx_user_time` (`user_id`, `created_at`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    }

    /** 重置进程内缓存（测试用例切换库后调用） */
    public static function resetCache(): void
    {
        self::$tableOk = null;
        self::$lastErr = '';
    }

    /**
     * 记一条操作日志。任何失败都静默返回 false，不影响主流程。
     * @param int    $userId 归属用户（管理员操作时=被操作者；同时想给操作者留痕就再调一次）
     * @param string $action self::ACTIONS 之一
     * @param string $detail 人读的一句话说明（可为空）
     */
    public static function log(int $userId, string $action, string $detail = ''): bool
    {
        if ($userId <= 0 || !in_array($action, self::ACTIONS, true) || !self::available()) {
            return false;
        }
        try {
            Db::insert('user_op_logs', Db::filterColumns('user_op_logs', [
                'user_id'    => $userId,
                'action'     => $action,
                'detail'     => Util::cut(trim($detail), 500),
                'ip'         => Util::clientIp(),
                'ua'         => Util::cut((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
                'created_at' => time(),
            ]));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 某用户的日志分页（倒序）。表缺失时降级为空集并带 logs_table=false 标记，
     * 前端据此提示「请先执行增量升级 SQL」而非显示一个永远空白的列表。
     *
     * logs_table=false 时额外返回诊断三件套，让用户自己就能定位（不必来问「我明明执行了」）：
     *   db  —— 服务端**当前连的是哪个库**（把 SQL 执行到了别的库是最常见的原因）；
     *   err —— 探测/查询失败的真实原因（如 1146 Table doesn't exist）；
     *   sql —— 建表语句原文，前端可一键复制，保证用户执行的就是代码里这一份。
     * @return array{items:array,total:int,page:int,page_size:int,logs_table:bool,db:string,err:string,sql:string}
     */
    public static function page(int $userId, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(5, $pageSize));
        $out = [
            'items' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize,
            'logs_table' => true, 'db' => self::dbName(), 'err' => '', 'sql' => '',
        ];
        if ($userId <= 0 || !self::available()) {
            return self::degrade($out, self::$lastErr);
        }
        try {
            $total = (int) Db::value('SELECT COUNT(*) FROM user_op_logs WHERE user_id=?', [$userId]);
            $items = [];
            if ($total > 0) {
                $offset = ($page - 1) * $pageSize;
                $items = Db::select(
                    'SELECT id,action,detail,ip,ua,created_at FROM user_op_logs
                     WHERE user_id=? ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
                    [$userId]
                );
            }
            $out['total'] = $total;
            $out['items'] = $items;
            return $out;
        } catch (\Throwable $e) {
            return self::degrade($out, $e->getMessage());
        }
    }

    /** 降级结果：标记缺表并补齐诊断信息（db 已在 page() 初始化时填好） */
    private static function degrade(array $out, string $err): array
    {
        $out['logs_table'] = false;
        $out['err']        = Util::cut($err, 300);
        $out['sql']        = self::ddl();
        return $out;
    }
}
