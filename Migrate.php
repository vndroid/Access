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
        'event_id',
    ];

    /**
     * 迁移完成标记单独存一行 options，不能塞进 plugin:Access 的配置里：
     * Typecho 渲染插件设置页时会遍历已保存的每个配置键去找对应表单控件
     * （var/Widget/Plugins/Config.php: foreach ($options as $key => $val) $form->getInput($key)->value($val)），
     * 没有声明成表单项的键会触发 Undefined array key 并对 null 调用方法。
     */
    private const DONE_OPTION = 'access_migrate_done';

    /**
     * 迁移过程中写不进目标库的源行 id
     *
     * 单独记一份，是因为「完成」这个判断不能只看扫没扫到头：
     * 失败行在目标表里留下的是空洞，而续传点取的是目标表的 MAX(id)，
     * 空洞会被自然跳过。不把它们记下来的话，扫完就是 done，
     * 少掉的那几行再也没人知道。
     */
    private const FAILED_OPTION = 'access_migrate_failed';

    /** 失败 id 最多记这么多个，够定位问题即可，不让这一行无限变长 */
    private const MAX_FAILED_TRACKED = 500;

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
     *     total: int 源表总行数, pending: int 剩余行数,
     *     failed: int 累计写不进目标库的行数（大于 0 时永远不会标记为完成）
     * }
     */
    public static function ensure(Db $target, array $dbSettings, ?float $deadline = null): array
    {
        $result = ['status' => MigrateStatus::None, 'moved' => 0, 'total' => 0, 'pending' => 0, 'failed' => 0];

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

        $run = self::run($main, $target, ['deadline' => $deadline, 'fingerprint' => $fingerprint]);
        $result['moved'] = $run['moved'];
        $result['pending'] = max(0, $pending - $run['moved']);
        $result['failed'] = count(self::failures($main, $fingerprint));

        /*
         * 有行没能迁过去就绝不能打完成标记：标记一旦写下，
         * 以后每次都在 isMarked() 那里直接返回，谁也不会再回头看这张源表。
         */
        if ($run['done'] && $result['failed'] === 0) {
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
     * @param array $options batchSize / deadline / progress(已迁移, 总数, 当前 id) /
     *                        fingerprint（传入后会把失败行记进 options，供「能不能算完成」判断）
     * @return array {
     *     moved: int 实际写入行数, done: bool 是否已全部迁完（有失败行时恒为 false）,
     *     lastId: int 本次推进到的源 id, failed: int 写不进去的行数,
     *     failedIds: int[] 写不进去的源行 id,
     *     error: ?string 整批写入失败（连不上目标库等）时的说明，此时断点不推进
     * }
     */
    public static function run(Db $main, Db $target, array $options = []): array
    {
        $batchSize = max(1, (int)($options['batchSize'] ?? self::BATCH_SIZE));
        $deadline = $options['deadline'] ?? null;
        $progress = $options['progress'] ?? null;

        $maxSourceId = self::sourceMaxId($main);
        if ($maxSourceId <= 0) {
            return ['moved' => 0, 'done' => true, 'lastId' => 0, 'failed' => 0, 'failedIds' => [], 'error' => null];
        }

        # 先抬高目标表的自增起点，给迁移数据留出主键区间
        self::reserveIdRange($target, $maxSourceId);

        $total = self::sourceCount($main);
        $already = self::migratedCount($target, $maxSourceId);
        $lastId = self::resumeFrom($target, $maxSourceId);
        $moved = 0;
        $done = true;
        $error = null;

        @set_time_limit(0);

        /*
         * 源表不一定升级过：用户可能在 3.1.x 时就已经把统计数据搬到独立库，
         * 主库里只剩下待迁移的残留，Schema::ensure() 升的是目标库而不是它。
         * 按实际存在的列取数，缺 event_id 就整列略过（那部分数据本来也没有）。
         */
        $columns = self::usableColumns($main, $main->getPrefix() . 'access', self::COLUMNS);

        $fingerprint = isset($options['fingerprint']) ? (string)$options['fingerprint'] : null;
        $failedIds = [];

        while (true) {
            $rows = $main->fetchAll(
                $main->select(...$columns)
                    ->from('table.access')
                    ->where('id > ?', $lastId)
                    ->where('id <= ?', $maxSourceId)
                    ->order('id', Db::SORT_ASC)
                    ->limit($batchSize)
            );

            if (empty($rows)) {
                break;
            }

            # insertBatchDetailed 的失败下标是按位置给的，这里显式对齐，别依赖驱动的返回形态
            $rows = array_values($rows);

            $outcome = self::insertBatchDetailed($target, $rows, $columns);

            /*
             * 失败行先原地重试一次：整批 INSERT 失败会退化成逐行写，
             * 其中一部分是连接抖动、锁等待这类一次性问题，重试就过去了。
             * 真正的脏数据重试也还是失败，接着往下走。
             */
            if (!empty($outcome['failed'])) {
                $firstRound = $outcome['failed'];
                $retry = [];
                foreach ($firstRound as $i) {
                    $retry[] = $rows[$i];
                }

                $second = self::insertBatchDetailed($target, $retry, $columns);
                $outcome['written'] += $second['written'];

                # $second 的下标是 $retry 的，换算回 $rows 的下标
                $stillFailed = [];
                foreach ($second['failed'] as $j) {
                    $stillFailed[] = $firstRound[$j];
                }
                $outcome['failed'] = $stillFailed;
            }

            /*
             * 一行都没写进去、又拿不到「哪几行有问题」—— 失败发生在语句之前：
             * 连不上目标库，或者语句根本拼不出来。
             *
             * 这种情况既不能推进断点也不能继续扫。以前照样往下走，
             * 于是整张表被「扫完」了却一行都没搬过去，最后还返回 done=true，
             * 迁移完成标记一写，源表从此没人再看一眼。
             */
            if ($outcome['written'] === 0 && empty($outcome['failed'])) {
                $done = false;
                $error = '目标库写入失败（连接不可用，或语句无法构造），断点未推进，请检查后重新执行';
                break;
            }

            $moved += $outcome['written'];
            $lastId = (int)$rows[count($rows) - 1]['id'];

            if ($progress !== null) {
                $progress($already + $moved, $total, $lastId);
            }

            /*
             * 有行确实写不进去就停手，不再往下扫。
             * 以前这里只推进 checkpoint 继续跑：失败行落在新 checkpoint 之前，
             * 从此没人再看它一眼，而扫到头之后照样返回 done=true ——
             * 迁移「完成」了，数据少了一截，还没有任何痕迹。
             */
            if (!empty($outcome['failed'])) {
                foreach ($outcome['failed'] as $i) {
                    $failedIds[] = (int)$rows[$i]['id'];
                }
                $done = false;
                break;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $done = false;
                break;
            }
        }

        if (!empty($failedIds) && $fingerprint !== null) {
            self::recordFailures($main, $fingerprint, $failedIds);
        }

        return [
            'moved'     => $moved,
            'done'      => $done && empty($failedIds),
            'lastId'    => $lastId,
            'failed'    => count($failedIds),
            'failedIds' => $failedIds,
            'error'     => $error,
        ];
    }

    /**
     * 批量写入一批数据，整批失败时退化为逐行写入，避免单条脏数据毁掉整批
     *
     * @deprecated 请改用 insertBatchDetailed()。
     *   这个包装只返回写入行数，把「哪几行没写进去」丢掉了 —— 调用方于是只能
     *   在「整批重试」和「当作成功继续」之间二选一，历史上迁移正是因此
     *   把失败行永久跳了过去。新代码一律走 insertBatchDetailed()。
     *
     * @param Db $target
     * @param array $rows
     * @param array $columnList 要写入的字段，顺序固定
     * @return int 实际写入行数
     */
    public static function insertBatch(Db $target, array $rows, array $columnList): int
    {
        return self::insertBatchDetailed($target, $rows, $columnList)['written'];
    }

    /**
     * 同 insertBatch()，但额外告诉调用方「哪几行没写进去」
     *
     * 只报总数是不够的：写入队列要靠这个决定哪些消息可以确认、哪些必须留证据，
     * 只知道「1000 条里成功了 1 条」就只能在「整批重试」和「整批丢掉」之间二选一。
     *
     * @param Db $target
     * @param array $rows
     * @param array $columnList 要写入的字段，顺序固定
     * @return array{written:int,failed:int[]} failed 是 $rows 中写失败的行下标（已重新索引）
     */
    public static function insertBatchDetailed(Db $target, array $rows, array $columnList): array
    {
        $rows = array_values($rows);
        /*
         * kinds  失败行下标 => WriteErrorKind，调用方靠它决定转死信还是留着重试
         * fatal  语句还没发出去就失败了（连不上、拼不出语句），此时一行 failed 都给不出来，
         *        调用方只能看这个字段 —— 它为非 null 就说明「整批都没写，且不是数据的错」
         */
        $result = ['written' => 0, 'failed' => [], 'kinds' => [], 'fatal' => null, 'error' => null];
        if (empty($rows)) {
            return $result;
        }

        try {
            // quoteValue 依赖已经建立的连接，这里先确保连上，
            // 否则在「刷库是本次请求第一个数据库操作」的场景下会取到未初始化的 PDO
            $target->selectDb(Db::WRITE);

            $adapter = $target->getAdapter();
            $table = $target->getPrefix() . 'access';

            # 目标表还没升到 3.2.0 时把 event_id 摘掉，写入照常进行（只是这部分不幂等）
            $columnList = self::usableColumns($target, $table, $columnList);
            $columns = implode(', ', array_map($adapter->quoteColumn(...), $columnList));

            [$head, $tail] = self::ignoreClause(Database::driver($target), $adapter);

            $tuples = [];
            foreach ($rows as $row) {
                $tuples[] = self::tuple($adapter, $row, $columnList);
            }
        } catch (\Throwable $e) {
            /*
             * 连不上或拼不出语句，一条都没写进去，也没有「哪一行有问题」的信息可给。
             * 以前这里直接 return 一个空壳，调用方看到 written=0 & failed=[] 只能靠
             * alive() 去猜；而 alive() 走的是 SELECT，库只读或没有 INSERT 权限时照样成功，
             * 于是整批被当成脏数据倒进死信。现在把判定结果如实带出去。
             */
            $result['fatal'] = Database::classifyWriteError($e);
            $result['error'] = $e->getMessage();
            return $result;
        }

        try {
            $target->query("{$head} {$table} ({$columns}) VALUES " . implode(', ', $tuples) . $tail, Db::WRITE);
            $result['written'] = count($rows);
            return $result;
        } catch (\Throwable $e) {
            // 退化为逐行，逐个记下写不进去的行（例如字段超长）
        }

        foreach ($rows as $i => $row) {
            try {
                $target->query(
                    "{$head} {$table} ({$columns}) VALUES " . self::tuple($adapter, $row, $columnList) . $tail,
                    Db::WRITE
                );
                $result['written']++;
            } catch (\Throwable $e) {
                $result['failed'][] = $i;
                $result['kinds'][$i] = Database::classifyWriteError($e);
                if ($result['error'] === null) {
                    $result['error'] = $e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * 「唯一冲突就跳过」的写法，三种数据库各不相同
     *
     * 冲突有两个来源，都该静默略过而不是当成失败：
     * event_id 重复说明这条日志已经写过（队列做的是「至少一次」投递，重试必然带来重复），
     * id 重复说明迁移时目标库里已经有这一行。
     *
     * MySQL 不用 INSERT IGNORE：它连字段超长、类型不符这类数据错误一并吞掉，
     * 而写入队列正是靠「数据库明确拒绝」来把脏数据挑进死信队列的。
     *
     * @param Driver $driver
     * @param Adapter $adapter
     * @return array{0:string,1:string} [语句开头, 语句结尾]
     */
    private static function ignoreClause(Driver $driver, Adapter $adapter): array
    {
        $id = $adapter->quoteColumn('id');

        return match ($driver) {
            Driver::Mysql  => ['INSERT INTO', " ON DUPLICATE KEY UPDATE {$id} = {$id}"],
            Driver::Sqlite => ['INSERT OR IGNORE INTO', ''],
            Driver::Pgsql  => ['INSERT INTO', ' ON CONFLICT DO NOTHING'],
        };
    }

    /**
     * 每个连接只探一次列，刷库路径上不能每批都查一遍 information_schema
     *
     * 键必须是连接本身而不是「适配器名 + 表名」：run() 同时握着主库和目标库两个连接，
     * 表名都叫 typecho_access、适配器也可能相同，但一个升级过、另一个没有。
     */
    private static array $columnCache = [];

    /**
     * 去掉目标表里并不存在的列
     *
     * 升级插件文件之后如果没有重新启用（或保存过设置），表结构还停在旧版本，
     * 此时带着 event_id 去 INSERT 会整批失败 —— 那等于升级动作本身把统计写崩了。
     * 宁可这段时间少一层幂等保护，也不能让数据写不进去。
     *
     * @param Db $db
     * @param string $table 完整表名
     * @param array $columnList
     * @return array
     */
    private static function usableColumns(Db $db, string $table, array $columnList): array
    {
        if (!in_array('event_id', $columnList, true)) {
            return $columnList;
        }

        $key = spl_object_id($db) . '|' . $table;
        if (!array_key_exists($key, self::$columnCache)) {
            self::$columnCache[$key] = Database::columnExists($db, $table, 'event_id');
        }
        if (self::$columnCache[$key]) {
            return $columnList;
        }

        return array_values(array_filter($columnList, static fn($c) => $c !== 'event_id'));
    }

    /**
     * 连接探活
     *
     * 只能回答「这个连接还通不通」，**回答不了「写得进去吗」**：
     * 实测（PostgreSQL 16）库处于只读事务、或账号的 INSERT 权限被回收时，
     * 这条探活照样成功，而每一条 INSERT 都失败。所以它不能再用来判断
     * 「整批写不进去是不是因为脏数据」—— 那个判断改由 classifyWriteError() 按
     * SQLSTATE 决定，见 WriteErrorKind。这里只保留「连接层面通不通」这一个用途。
     *
     * 探活走 Db::WRITE 而不是 Db::READ：读写在 Typecho 里可以注册成不同的服务器，
     * 探到读节点活着并不说明写节点活着。
     *
     * @param Db $target
     * @return bool
     */
    public static function alive(Db $target): bool
    {
        try {
            $target->query('SELECT 1', Db::WRITE);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
        # 完成标记和失败记录是同一件事的两面，清一个就得清另一个
        self::clearFailures($main);
        self::cleanupLegacyMarker($main);
    }

    /**
     * 读出某个目标库遗留的迁移失败行
     *
     * @param Db $main
     * @param string $fingerprint
     * @return int[] 源表行 id，从未失败过时是空数组
     */
    public static function failures(Db $main, string $fingerprint): array
    {
        $all = self::readFailed($main);
        $ids = $all[$fingerprint] ?? [];
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * 记下写不进目标库的源行 id
     *
     * 累加而不是覆盖：一次迁移可能分很多轮跑完，每轮各自留下一些。
     *
     * @param Db $main
     * @param string $fingerprint
     * @param int[] $ids
     * @return void
     */
    public static function recordFailures(Db $main, string $fingerprint, array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $all = self::readFailed($main);
        $merged = array_values(array_unique(array_merge($all[$fingerprint] ?? [], array_map('intval', $ids))));
        sort($merged);

        # 只留前面这些，够定位问题就行，不让这一行无限变长
        $all[$fingerprint] = array_slice($merged, 0, self::MAX_FAILED_TRACKED);

        self::writeFailed($main, $all);
    }

    /**
     * 忘掉某个目标库的失败记录（重新开始迁移、或人工修完之后调用）
     *
     * @param Db $main
     * @param string|null $fingerprint 为 null 时清空整行
     * @return void
     */
    public static function clearFailures(Db $main, ?string $fingerprint = null): void
    {
        try {
            if ($fingerprint === null) {
                $main->query($main->delete('table.options')->where('name = ?', self::FAILED_OPTION));
                return;
            }
            $all = self::readFailed($main);
            unset($all[$fingerprint]);
            self::writeFailed($main, $all);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param Db $main
     * @return array<string, int[]>
     */
    private static function readFailed(Db $main): array
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::FAILED_OPTION)
            );
            if (empty($row['value'])) {
                return [];
            }
            $decoded = json_decode((string)$row['value'], true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param Db $main
     * @param array<string, int[]> $all
     * @return void
     */
    private static function writeFailed(Db $main, array $all): void
    {
        try {
            $all = array_filter($all, static fn($ids): bool => !empty($ids));
            $value = json_encode($all);

            $exists = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::FAILED_OPTION)
            );

            if (empty($exists)) {
                $main->query($main->insert('table.options')
                    ->rows(['name' => self::FAILED_OPTION, 'user' => 0, 'value' => $value]));
            } else {
                $main->query($main->update('table.options')
                    ->rows(['value' => $value])
                    ->where('name = ?', self::FAILED_OPTION));
            }
        } catch (\Throwable $e) {
        }
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
