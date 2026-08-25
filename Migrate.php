<?php

namespace TypechoPlugin\Access;

use Typecho\Db;
use Typecho\Db\Adapter;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 把 Typecho 主库中的历史统计数据迁移到独立数据库
 *
 * 设计要点：
 * - keyset 分页（WHERE id > ?）而不是 OFFSET，避免大表上每批越翻越慢
 * - 批量 INSERT，一条语句写入多行，减少往返次数
 * - 可断点续传：源表主键原样保留到目标表，续传位置 = 目标表中不超过源表最大 id 的最大 id
 * - 迁移开始前先把目标表的自增起点抬到源表最大 id 之上，
 *   这样迁移期间站点新产生的日志不会和迁移中的主键撞车
 * - 后台保存设置时只自动迁移小表，超过阈值改由命令行脚本执行，避免被 Web 超时截断
 */
final class Migrate
{
    /** 后台自动迁移的行数上限，超过则提示改用命令行脚本 */
    public const AUTO_LIMIT = 50000;

    /** 每批处理的行数 */
    public const BATCH_SIZE = 1000;

    /** 后台自动迁移的时间预算（秒），超时则中断并提示继续 */
    public const AUTO_DEADLINE = 20;

    /** 需要迁移的字段，顺序固定，避免两边表结构差异导致列错位 */
    public const COLUMNS = [
        'id', 'ua', 'browser_id', 'browser_version', 'os_id', 'os_version',
        'url', 'path', 'query_string', 'ip', 'entrypoint', 'entrypoint_domain',
        'referer', 'referer_domain', 'time', 'content_id', 'meta_id',
        'robot', 'robot_id', 'robot_version',
    ];

    /**
     * 迁移完成标记单独存一行 options，不能塞进 plugin:Access 的配置里：
     * Typecho 渲染插件设置页时会遍历已保存的每个配置键去找对应表单控件
     * （var/Widget/Plugins/Config.php: foreach ($options as $key => $val) $form->getInput($key)->value($val)），
     * 没有声明成表单项的键会触发 Undefined array key 并对 null 调用方法。
     */
    private const DONE_OPTION = 'access_migrate_done';

    /** 3.1.0 早期版本曾经把标记写在插件配置里，保留用于兼容与清理 */
    private const LEGACY_DONE_KEY = 'dbMigrateDone';

    /**
     * 保证目标库的数据已经就位，需要时执行迁移
     *
     * @param Db $target 独立数据库
     * @param array $dbSettings Database::settings() 的结果
     * @param float|null $deadline 时间预算（microtime 时间戳），为 null 表示不限时
     * @return array {
     *     status: MigrateStatus, moved: int 已迁移行数,
     *     total: int 源表总行数, pending: int 剩余行数
     * }
     */
    public static function ensure(Db $target, array $dbSettings, ?float $deadline = null): array
    {
        $result = ['status' => MigrateStatus::None, 'moved' => 0, 'total' => 0, 'pending' => 0];

        try {
            $main = Database::main();
        } catch (\Throwable $e) {
            return $result;
        }

        $fingerprint = self::fingerprint($dbSettings);
        if (self::isMarked($main, $fingerprint)) {
            $result['status'] = MigrateStatus::Already;
            return $result;
        }

        if (!Database::tableExists($main, $main->getPrefix() . 'access')) {
            self::mark($main, $fingerprint);
            return $result;
        }

        $total = self::sourceCount($main);
        $result['total'] = $total;
        if ($total === 0) {
            self::mark($main, $fingerprint);
            return $result;
        }

        $maxSourceId = self::sourceMaxId($main);
        $pending = max(0, $total - self::migratedCount($target, $maxSourceId));
        $result['pending'] = $pending;

        if ($pending === 0) {
            self::mark($main, $fingerprint);
            $result['status'] = MigrateStatus::Already;
            return $result;
        }

        # 数据量过大时不在 Web 请求里做，交给命令行脚本
        if ($pending > self::AUTO_LIMIT) {
            $result['status'] = MigrateStatus::Skipped;
            return $result;
        }

        $run = self::run($main, $target, ['deadline' => $deadline]);
        $result['moved'] = $run['moved'];
        $result['pending'] = max(0, $pending - $run['moved']);

        if ($run['done']) {
            self::mark($main, $fingerprint);
            $result['status'] = MigrateStatus::Done;
        } else {
            $result['status'] = MigrateStatus::Partial;
        }

        return $result;
    }

    /**
     * 查询迁移进度
     *
     * @param Db $main 主库
     * @param Db $target 目标库
     * @param array $dbSettings
     * @return array {marked: bool, total: int, migrated: int, pending: int}
     */
    public static function status(Db $main, Db $target, array $dbSettings): array
    {
        if (self::isMarked($main, self::fingerprint($dbSettings))) {
            return ['marked' => true, 'total' => 0, 'migrated' => 0, 'pending' => 0];
        }

        if (!Database::tableExists($main, $main->getPrefix() . 'access')) {
            return ['marked' => false, 'total' => 0, 'migrated' => 0, 'pending' => 0];
        }

        $total = self::sourceCount($main);
        $migrated = $total > 0 ? self::migratedCount($target, self::sourceMaxId($main)) : 0;

        return [
            'marked' => false,
            'total' => $total,
            'migrated' => min($migrated, $total),
            'pending' => max(0, $total - $migrated),
        ];
    }

    /**
     * 执行迁移
     *
     * @param Db $main 主库
     * @param Db $target 目标库
     * @param array $options batchSize / deadline / progress(已迁移, 总数, 当前 id)
     * @return array {moved: int, done: bool, lastId: int}
     */
    public static function run(Db $main, Db $target, array $options = []): array
    {
        $batchSize = max(1, (int)($options['batchSize'] ?? self::BATCH_SIZE));
        $deadline = $options['deadline'] ?? null;
        $progress = $options['progress'] ?? null;

        $maxSourceId = self::sourceMaxId($main);
        if ($maxSourceId <= 0) {
            return ['moved' => 0, 'done' => true, 'lastId' => 0];
        }

        # 先抬高目标表的自增起点，给迁移数据留出主键区间
        self::reserveIdRange($target, $maxSourceId);

        $total = self::sourceCount($main);
        $already = self::migratedCount($target, $maxSourceId);
        $lastId = self::resumeFrom($target, $maxSourceId);
        $moved = 0;
        $done = true;

        @set_time_limit(0);

        while (true) {
            $rows = $main->fetchAll(
                $main->select(...self::COLUMNS)
                    ->from('table.access')
                    ->where('id > ?', $lastId)
                    ->where('id <= ?', $maxSourceId)
                    ->order('id', Db::SORT_ASC)
                    ->limit($batchSize)
            );

            if (empty($rows)) {
                break;
            }

            $moved += self::insertBatch($target, $rows, self::COLUMNS);
            $lastId = (int)$rows[count($rows) - 1]['id'];

            if ($progress !== null) {
                $progress($already + $moved, $total, $lastId);
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $done = false;
                break;
            }
        }

        return ['moved' => $moved, 'done' => $done, 'lastId' => $lastId];
    }

    /**
     * 批量写入一批数据，整批失败时退化为逐行写入，避免单条脏数据毁掉整批
     * 迁移和写入队列共用这一段
     *
     * @param Db $target
     * @param array $rows
     * @param array $columnList 要写入的字段，顺序固定
     * @return int 实际写入行数
     */
    public static function insertBatch(Db $target, array $rows, array $columnList): int
    {
        if (empty($rows)) {
            return 0;
        }

        try {
            // quoteValue 依赖已经建立的连接，这里先确保连上，
            // 否则在「刷库是本次请求第一个数据库操作」的场景下会取到未初始化的 PDO
            $target->selectDb(Db::WRITE);

            $adapter = $target->getAdapter();
            $table = $target->getPrefix() . 'access';
            $columns = implode(', ', array_map($adapter->quoteColumn(...), $columnList));

            $tuples = [];
            foreach ($rows as $row) {
                $tuples[] = self::tuple($adapter, $row, $columnList);
            }
        } catch (\Throwable $e) {
            // 连不上或拼不出语句，一条都没写进去
            return 0;
        }

        try {
            $target->query("INSERT INTO {$table} ({$columns}) VALUES " . implode(', ', $tuples), Db::WRITE);
            return count($rows);
        } catch (\Throwable $e) {
            // 退化为逐行，跳过写不进去的行（例如主键冲突）
        }

        $written = 0;
        foreach ($rows as $row) {
            try {
                $target->query(
                    "INSERT INTO {$table} ({$columns}) VALUES " . self::tuple($adapter, $row, $columnList),
                    Db::WRITE
                );
                $written++;
            } catch (\Throwable $e) {
            }
        }

        return $written;
    }

    /**
     * 按固定列顺序拼出一行的值
     *
     * @param Adapter $adapter
     * @param array $row
     * @param array $columnList
     * @return string
     */
    private static function tuple(Adapter $adapter, array $row, array $columnList): string
    {
        $values = [];
        foreach ($columnList as $column) {
            $value = $row[$column] ?? null;
            $values[] = $value === null ? 'NULL' : $adapter->quoteValue($value);
        }
        return '(' . implode(', ', $values) . ')';
    }

    /**
     * 把目标表的自增起点抬到源表最大 id 之上
     * 失败不阻断迁移，最坏情况是迁移期间产生的少量新日志主键冲突后被跳过
     *
     * @param Db $target
     * @param int $maxSourceId
     * @return void
     */
    private static function reserveIdRange(Db $target, int $maxSourceId): void
    {
        $table = $target->getPrefix() . 'access';

        try {
            match (Database::driver($target)) {
                // 取序列现值与源表最大 id 的较大者，避免把已经领先的序列改小
                Driver::Pgsql => $target->query(
                    "SELECT setval(pg_get_serial_sequence('{$table}', 'id'),
                            GREATEST({$maxSourceId}, COALESCE((SELECT MAX(id) FROM {$table}), 0)), true)",
                    Db::READ
                ),
                // 小于当前最大值时 MySQL 会忽略该设置，是安全的
                Driver::Mysql => $target->query(
                    "ALTER TABLE `{$table}` AUTO_INCREMENT = " . ($maxSourceId + 1),
                    Db::WRITE
                ),
                Driver::Sqlite => $target->query(
                    "INSERT OR REPLACE INTO sqlite_sequence(name, seq)
                     VALUES('{$table}', MAX({$maxSourceId}, COALESCE((SELECT MAX(id) FROM `{$table}`), 0)))",
                    Db::WRITE
                ),
            };
        } catch (\Throwable $e) {
        }
    }

    /**
     * 源表总行数
     */
    public static function sourceCount(Db $main): int
    {
        try {
            return (int)$main->fetchAll($main->select('COUNT(1) AS count')->from('table.access'))[0]['count'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 源表最大主键
     */
    public static function sourceMaxId(Db $main): int
    {
        try {
            return (int)($main->fetchAll($main->select('MAX(id) AS max_id')->from('table.access'))[0]['max_id'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 目标表中属于迁移区间（id <= 源表最大 id）的行数
     */
    public static function migratedCount(Db $target, int $maxSourceId): int
    {
        try {
            return (int)$target->fetchAll(
                $target->select('COUNT(1) AS count')->from('table.access')->where('id <= ?', $maxSourceId)
            )[0]['count'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 续传起点：目标表中不超过源表最大 id 的最大 id
     */
    public static function resumeFrom(Db $target, int $maxSourceId): int
    {
        try {
            return (int)($target->fetchAll(
                $target->select('MAX(id) AS max_id')->from('table.access')->where('id <= ?', $maxSourceId)
            )[0]['max_id'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 目标库指纹，目标库换了地方就需要重新迁移
     */
    public static function fingerprint(array $dbSettings): string
    {
        return md5(implode('|', [
            ($dbSettings['type'] ?? DbType::Follow)->value,
            $dbSettings['host'] ?? '',
            $dbSettings['port'] ?? '',
            $dbSettings['database'] ?? '',
            $dbSettings['prefix'] ?? '',
        ]));
    }

    /**
     * 是否已经标记为迁移完成
     */
    public static function isMarked(Db $main, string $fingerprint): bool
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::DONE_OPTION)
            );
            if (!empty($row) && (string)$row['value'] === $fingerprint) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        # 兼容早期版本写在插件配置里的标记：命中就顺手搬到独立行并清掉旧键
        $settings = self::readPluginOptions($main);
        if (isset($settings[self::LEGACY_DONE_KEY]) && $settings[self::LEGACY_DONE_KEY] === $fingerprint) {
            self::mark($main, $fingerprint);
            return true;
        }

        self::cleanupLegacyMarker($main);
        return false;
    }

    /**
     * 标记迁移完成
     * 直接操作 options 表，这样命令行脚本不必依赖后台 Widget 栈
     */
    public static function mark(Db $main, string $fingerprint): void
    {
        try {
            $exists = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::DONE_OPTION)
            );

            if (empty($exists)) {
                $main->query($main->insert('table.options')
                    ->rows(['name' => self::DONE_OPTION, 'user' => 0, 'value' => $fingerprint]));
            } else {
                $main->query($main->update('table.options')
                    ->rows(['value' => $fingerprint])
                    ->where('name = ?', self::DONE_OPTION));
            }
        } catch (\Throwable $e) {
        }

        self::cleanupLegacyMarker($main);
    }

    /**
     * 删除迁移完成标记
     */
    public static function clearMark(Db $main): void
    {
        try {
            $main->query($main->delete('table.options')->where('name = ?', self::DONE_OPTION));
        } catch (\Throwable $e) {
        }
        self::cleanupLegacyMarker($main);
    }

    /**
     * 清掉早期版本残留在插件配置里的标记键
     *
     * 留着它会让插件设置页每次渲染都报 Undefined array key，
     * 因为它没有对应的表单控件。
     *
     * @param Db $main
     * @return bool 是否确实清理了
     */
    public static function cleanupLegacyMarker(Db $main): bool
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', 'plugin:Access')
            );
            if (empty($row)) {
                return false;
            }

            $settings = json_decode($row['value'], true);
            if (!is_array($settings) || !array_key_exists(self::LEGACY_DONE_KEY, $settings)) {
                return false;
            }

            unset($settings[self::LEGACY_DONE_KEY]);
            $main->query(
                $main->update('table.options')
                    ->rows(['value' => json_encode($settings)])
                    ->where('name = ?', 'plugin:Access')
                    ->where('user = ?', $row['user'])
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 直接从 options 表读插件配置（命令行环境下可用）
     */
    public static function readPluginOptions(Db $main): array
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', 'plugin:Access')
            );
            if (empty($row)) {
                return [];
            }
            return json_decode($row['value'], true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
