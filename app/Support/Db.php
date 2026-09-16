<?php
/**
 * 数据库访问封装（PDO 单例 + 批量写入）。
 * 说明：业务写库统一走 scripts/worker.php 的批量事务；本类同时提供直写接口作为降级路径。
 */
declare(strict_types=1);

namespace Wstat\Support;

use PDO;
use RuntimeException;

class Db
{
    private static ?PDO $pdo = null;

    private static function cfg(): array
    {
        return wstat_config('db');
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $c = self::cfg();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
        try {
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode=''",
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException('DB connect failed: ' . $e->getMessage());
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** 查询多行 */
    public static function select(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** 查询单行 */
    public static function first(string $sql, array $params = []): ?array
    {
        $rows = self::select($sql, $params);
        return $rows[0] ?? null;
    }

    /** 查询单值 */
    public static function value(string $sql, array $params = [])
    {
        $row = self::first($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /** 表列名集合（进程内缓存；SHOW COLUMNS 一次，供写入前过滤未知列） */
    private static array $tableCols = [];

    public static function tableColumns(string $table): array
    {
        if (!isset(self::$tableCols[$table])) {
            $set = [];
            foreach (self::select('SHOW COLUMNS FROM `' . $table . '`') as $r) {
                $set[(string) $r['Field']] = true;
            }
            self::$tableCols[$table] = $set;
        }
        return self::$tableCols[$table];
    }

    /** 仅保留表中真实存在的列（未跑增量迁移时自动容错，避免 INSERT 报未知列） */
    public static function filterColumns(string $table, array $row): array
    {
        $cols = self::tableColumns($table);
        return array_intersect_key($row, $cols);
    }

    /** 执行写语句，返回受影响行数 */
    public static function execute(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** 单行插入 */
    public static function insert(string $table, array $row): int
    {
        self::insertBatch($table, [$row]);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * 批量插入（多行一条 SQL，分批执行）。$rows 为空则跳过。
     * 健壮性：
     *  - 丢弃空数组/非数组行（历史或异构载荷经列过滤后可能为空），避免
     *    `(``)` 空名列 + 0 值元组触发 MySQL 1136「列数不匹配」；
     *  - 列集合取各行键的并集（保序），兼容异构事件行不丢列（如批首行无 geo、
     *    后续行带 geo 时旧实现会静默丢弃）。
     */
    public static function insertBatch(string $table, array $rows, int $chunk = 200, bool $ignore = false): int
    {
        if (empty($rows)) {
            return 0;
        }
        // 1) 只保留非空关联行
        $rows = array_values(array_filter($rows, static fn ($r) => is_array($r) && $r !== []));
        if (empty($rows)) {
            return 0;
        }
        // 2) 键并集（保持首次出现顺序）作为列集合
        $merged = [];
        foreach ($rows as $row) {
            $merged += $row;
        }
        $cols = array_keys($merged);
        $colSql = '`' . implode('`,`', $cols) . '`';
        $verb = $ignore ? 'INSERT IGNORE' : 'INSERT';
        $pdo = self::pdo();
        $n = 0;
        foreach (array_chunk($rows, $chunk) as $batch) {
            $placeholders = [];
            $flat = [];
            foreach ($batch as $row) {
                $ph = [];
                foreach ($cols as $c) {
                    $flat[] = $row[$c] ?? null;
                    $ph[] = '?';
                }
                $placeholders[] = '(' . implode(',', $ph) . ')';
            }
            $sql = sprintf('%s INTO `%s` (%s) VALUES %s', $verb, $table, $colSql, implode(',', $placeholders));
            try {
                $st = $pdo->prepare($sql);
                $st->execute($flat);
                $n += $st->rowCount();
            } catch (\PDOException $e) {
                // 带上表名/行数/列数便于定位（事件批 100 行内嵌上下文）
                throw new \PDOException(sprintf(
                    '[Db::insertBatch:%s] rows=%d cols=%d | %s',
                    $table,
                    count($batch),
                    count($cols),
                    $e->getMessage()
                ), (int) $e->getCode(), $e);
            }
        }
        return $n;
    }

    /** 单行 INSERT IGNORE */
    public static function insertIgnore(string $table, array $row): int
    {
        return self::insertBatch($table, [$row], 200, true);
    }

    /** 事务回调 */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn($pdo);
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
