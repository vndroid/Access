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

    /**
     * 迁移断点：已经确认扫过的源表 id
     *
     * 以前断点是从目标表 `MAX(id) WHERE id <= 源表最大 id` 推导出来的，那有两个错：
     *
     * 1. 大表迁移被推迟（pending > AUTO_LIMIT 走 Skipped）时，插件已经开始往目标库
     *    写新日志，而目标表的 id 从 1 开始。这些新日志落在「迁移区间」里，
     *    推导出来的断点直接跳到它们的最大 id —— 源表前面那一整截被静默跳过，
     *    而且 migratedCount() 还把它们算成「已迁移」，连 pending 都是错的。
     * 2. 写失败的行在目标表里是空洞，MAX(id) 天然跳过它们，于是失败行永远不会被重读。
     *
     * 所以断点必须自己存，存在主库，按目标库指纹分组。
     */
    private const CHECKPOINT_OPTION = 'access_migrate_checkpoint';

    /** 断点/失败记录最多保留几个目标库，防止反复换库把这两行撑大 */
    private const MAX_TRACKED_TARGETS = 8;

    /**
     * 同一行最多重试几次
     *
     * 失败行现在每轮开工前都会先补写一次（见 retryFailures()）。但一直失败的行
     * 不能一直重试下去 —— 每轮都去读它、写它、再失败，迁移就再也走不完。
     * 试满这个次数后不再自动补写，但记录保留着，完成标记照样打不下去，
     * 人工修完再 --forget-failed 或者让它下一轮补上。
     */
    public const MAX_FAILED_ATTEMPTS = 3;

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

        /*
         * 下面这一串探测全部走会抛异常的版本。
         *
         * 容错版把「查询失败」和「确实为空」答成同一个值（false / 0），而这里每一个
         * 分支的出口都是 mark() —— 主库连接抖一下就足以让整张源表被永久判定为
         * 「没有历史数据」，此后 isMarked() 每次直接返回，谁也不会再回头看它。
         * 查不出来就什么都别做，下次再说。
         */
        try {
            if (!Database::tableExistsStrict($main, $main->getPrefix() . 'access')) {
                # 源表确实不存在，没什么可迁的
                self::mark($main, $fingerprint);
                return $result;
            }

            $total = self::sourceCountStrict($main);
            $result['total'] = $total;
            if ($total === 0) {
                self::mark($main, $fingerprint);
                return $result;
            }

            $maxSourceId = self::sourceMaxIdStrict($main);

            /*
             * 抬高目标表自增起点这一步必须排在 Skipped 分支**之前**。
             *
             * 以前它只在 run() 里做，而大表走的是下面那个 Skipped 分支、根本到不了
             * run()。配置这时已经保存，插件立刻开始往目标库写新日志，目标表 id 从 1 起 ——
             * 等管理员后来跑命令行迁移，这些新日志正落在迁移区间里：
             * 旧的断点推导（目标表 MAX(id)）直接跳到它们的最大 id，源表前面一整截
             * 被静默跳过；migratedCount() 还把它们算成「已迁移」，连 pending 都是错的。
             * 抬高起点之后，目标库新产生的日志一律落在源表最大 id 之上，两边不再交叠。
             */
            $reserved = self::reserveIdRange($target, $maxSourceId);

            if (self::checkpoint($main, $fingerprint) === null
                && self::foreignRowInRange($main, $target, $maxSourceId) !== null) {
                # 目标表里有外来行，迁移会静默丢数据。状态保持 None，什么标记都不打
                return $result;
            }

            $checkpoint = self::resumeFrom($main, $target, $fingerprint, $maxSourceId, $reserved);
            $pending = self::pendingCount($main, $checkpoint);
        } catch (\Throwable $e) {
            # 探测本身失败：状态保持 None，什么标记都不打
            return $result;
        }

        $result['pending'] = $pending;
        $result['failed'] = count(self::failures($main, $fingerprint));

        if ($pending === 0 && $result['failed'] === 0) {
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
        if ($total === 0) {
            return ['marked' => false, 'total' => 0, 'migrated' => 0, 'pending' => 0];
        }

        /*
         * 进度同样按断点算，不按目标表行数算 —— 目标库自己产生的新日志
         * 不是「已迁移的历史数据」，把它们算进来会显示成虚假的完成度。
         */
        /*
          * 这里不传 $reserved（默认 false）：status() 是纯展示，不该顺手去改目标表的自增起点。
          * 后果是没有断点的站点在真正跑过一次之前，进度显示成「一行没迁」——
          * 宁可把待办报多，也不能报少。跑过一次之后断点就存下来了，两边的数字自然一致。
          */
        $checkpoint = self::resumeFrom($main, $target, self::fingerprint($dbSettings), self::sourceMaxId($main));
        $pending = self::pendingCountSafe($main, $checkpoint, $total);

        return [
            'marked' => false,
            'total' => $total,
            'migrated' => max(0, $total - $pending),
            'pending' => $pending,
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

        /*
         * 断点存在主库、按目标库指纹分组，所以指纹是必需的。
         * 以前它是可选的（不传就只是不记失败行），而断点又从目标表推导，
         * 于是「不传指纹」这条路会静默地退化成没有任何持久状态的迁移。
         */
        $fingerprint = isset($options['fingerprint']) ? (string)$options['fingerprint'] : '';
        if ($fingerprint === '') {
            return self::runResult(0, false, 0, [], '缺少目标库指纹，无法记录断点，迁移未执行');
        }

        try {
            $maxSourceId = self::sourceMaxIdStrict($main);
            $total = self::sourceCountStrict($main);
        } catch (\Throwable $e) {
            # 查不出来 ≠ 源表是空的。以前这里返回 done=true，一次查询超时就宣布迁移完成
            return self::runResult(0, false, 0, [], '读取源表失败，迁移未执行：' . $e->getMessage());
        }

        if ($maxSourceId <= 0) {
            # 确实一行都没有，这才是真的没什么可迁的
            return self::runResult(0, true, 0, [], null);
        }

        /*
         * 先抬高目标表的自增起点，给迁移数据留出主键区间。
         * ensure() 在走 Skipped 分支之前也会调一次 —— 那条路根本到不了这里，
         * 而插件保存完设置就开始往目标库写新日志了。
         */
        $reserved = self::reserveIdRange($target, $maxSourceId);

        @set_time_limit(0);

        /*
         * 源表不一定升级过：用户可能在 3.1.x 时就已经把统计数据搬到独立库，
         * 主库里只剩下待迁移的残留，Schema::ensure() 升的是目标库而不是它。
         * 按实际存在的列取数，缺 event_id 就整列略过（那部分数据本来也没有）。
         */
        $columns = self::usableColumns($main, $main->getPrefix() . 'access', self::COLUMNS);

        # 上几轮写不进去的行，先补写一次再往下扫
        $retried = self::retryFailures($main, $target, $fingerprint, $columns);

        /*
         * 还没有断点 = 这是对这个目标库的第一次迁移。动手之前先确认迁移 id 区间里
         * 没有「目标库自己产生的行」—— 有的话它们占着的主键会让源表对应的行
         * 撞 ON CONFLICT 静默消失（见 foreignRowInRange() 的说明）。
         */
        if (self::checkpoint($main, $fingerprint) === null) {
            $foreign = self::foreignRowInRange($main, $target, $maxSourceId);
            if ($foreign !== null) {
                return self::runResult(0, false, 0, [], sprintf(
                    '目标表在迁移用的主键区间（id <= %d）里已经存在不是迁移来的记录（例如 id=%d），'
                    . '继续迁移会让源表中相同 id 的行撞上主键冲突后被静默丢弃。'
                    . '请先把目标表这部分记录移走或清空（确认它们不需要保留），再重新执行迁移。',
                    $maxSourceId,
                    $foreign
                ));
            }
        }

        $lastId = self::resumeFrom($main, $target, $fingerprint, $maxSourceId, $reserved);
        $already = max(0, $total - self::pendingCountSafe($main, $lastId, $total));
        $moved = $retried['written'];
        $done = true;
        $error = $retried['error'];
        $failedIds = [];

        while ($error === null) {
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
             * 语句还没发出去就整批失败：连不上目标库，或者语句根本拼不出来。
             * 既不能推进断点也不能继续扫 —— 以前照样往下走，于是整张表被「扫完」了
             * 却一行都没搬过去，最后还返回 done=true，完成标记一写，源表从此没人再看。
             */
            if ($outcome['fatal'] !== null) {
                $done = false;
                $error = sprintf(
                    '目标库写入失败（%s），断点未推进，请检查后重新执行：%s',
                    $outcome['fatal']->name,
                    (string)$outcome['error']
                );
                break;
            }

            $moved += $outcome['written'];

            /*
             * 断点推进到本批末尾，写不进去的那几行单独记进失败清单。
             *
             * 靠断点「停在失败行之前」是行不通的：那一行如果永远写不进去，
             * 迁移就永远卡在同一个位置，后面几百万行谁也别想过去。
             * 所以断点照常推进，失败行由 failures 清单负责 ——
             * 每轮开工前 retryFailures() 会补写它们，而只要清单非空，
             * 完成标记就打不下去（见 ensure() 与命令行脚本）。
             */
            $lastId = (int)$rows[count($rows) - 1]['id'];

            if (!empty($outcome['failed'])) {
                foreach ($outcome['failed'] as $i) {
                    $failedIds[] = (int)$rows[$i]['id'];
                }
                $done = false;
            }

            /*
             * 断点和失败清单必须在同一轮里落库，且失败清单先写。
             * 反过来的话，断点存下了而失败清单没存下，那几行就成了
             * 「断点已经越过、也没人记得」—— 正是这次要修掉的形态。
             */
            try {
                if (!empty($failedIds)) {
                    self::recordFailures($main, $fingerprint, $failedIds);
                    $failedIds = [];
                }
                self::saveCheckpoint($main, $fingerprint, $lastId);
            } catch (\Throwable $e) {
                $done = false;
                $error = '断点或失败记录写入主库失败，请检查后重新执行：' . $e->getMessage();
                break;
            }

            if ($progress !== null) {
                $progress($already + $moved, $total, $lastId);
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $done = false;
                break;
            }
        }

        $remaining = count(self::failureAttempts($main, $fingerprint));

        return self::runResult(
            $moved,
            $done && $remaining === 0 && $error === null,
            $lastId,
            self::failures($main, $fingerprint),
            $error
        );
    }

    /**
     * run() 的返回值形状，集中在一处以免各分支拼错
     *
     * @param int $moved
     * @param bool $done
     * @param int $lastId
     * @param int[] $failedIds
     * @param string|null $error
     * @return array
     */
    private static function runResult(int $moved, bool $done, int $lastId, array $failedIds, ?string $error): array
    {
        return [
            'moved'     => $moved,
            'done'      => $done,
            'lastId'    => $lastId,
            'failed'    => count($failedIds),
            'failedIds' => $failedIds,
            'error'     => $error,
        ];
    }

    /**
     * 已扫过的行数，纯粹用于进度显示，查不出来就当 0
     *
     * @param Db $main
     * @param int $checkpoint
     * @param int $total
     * @return int
     */
    private static function pendingCountSafe(Db $main, int $checkpoint, int $total): int
    {
        try {
            return self::pendingCount($main, $checkpoint);
        } catch (\Throwable $e) {
            return $total;
        }
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
    /**
     * 源表实际可用的列（对外用，命令行脚本补写失败行时要传给 retryFailures()）
     *
     * @param Db $main
     * @return array
     */
    public static function usableColumnsFor(Db $main): array
    {
        return self::usableColumns($main, $main->getPrefix() . 'access', self::COLUMNS);
    }

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
     *
     * **返回值必须看。** 以前这里的异常被整个吞掉，而 resumeFrom() 又会在没有断点时
     * 用目标表 MAX(id) 推导续传起点 —— 抬起点失败时目标库的新日志照样落在迁移区间里，
     * 推导出来的断点直接跳过源表前面一整截。两处合起来正好复现断点机制要修的那个洞。
     *
     * @param Db $target
     * @param int $maxSourceId
     * @return bool 是否确实抬高了（失败时调用方不能再信任 MAX(id) 推导）
     */
    private static function reserveIdRange(Db $target, int $maxSourceId): bool
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
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 源表总行数（容错版，查不出来时返回 0）
     *
     * **迁移路径不能用这个**，用 sourceCountStrict()。
     * 「查询失败」和「表是空的」在这里都返回 0，而 ensure() 见到 0 就打完成标记 ——
     * 主库一次超时就足以让整张源表被永久判定为「没有历史数据」。
     * 这个容错版只留给展示类调用：数字显示成 0 无非是不好看。
     */
    public static function sourceCount(Db $main): int
    {
        try {
            return self::sourceCountStrict($main);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 源表总行数（查不出来就抛）
     *
     * @throws \Throwable
     */
    public static function sourceCountStrict(Db $main): int
    {
        return (int)$main->fetchAll($main->select('COUNT(1) AS count')->from('table.access'))[0]['count'];
    }

    /**
     * 源表里还没扫过的行数（id > 断点）
     *
     * 取代了原来的「总行数 - 目标表迁移区间行数」：后者会把目标库自己产生的新日志
     * 算成已迁移，得出的 pending 偏小，甚至直接得出 0 而打下完成标记。
     * 改成只问源表，问题就不存在了 —— 谁扫到哪儿是断点说了算，与目标表无关。
     *
     * @throws \Throwable
     */
    public static function pendingCount(Db $main, int $checkpoint): int
    {
        return (int)$main->fetchAll(
            $main->select('COUNT(1) AS count')->from('table.access')->where('id > ?', $checkpoint)
        )[0]['count'];
    }

    /**
     * 源表最大主键（容错版，查不出来时返回 0）
     *
     * 同 sourceCount()：迁移路径请用 sourceMaxIdStrict()。
     * run() 见到 0 会直接返回 done=true，一次查询失败就等于宣布迁移完成。
     */
    public static function sourceMaxId(Db $main): int
    {
        try {
            return self::sourceMaxIdStrict($main);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 源表最大主键（查不出来就抛）
     *
     * @throws \Throwable
     */
    public static function sourceMaxIdStrict(Db $main): int
    {
        return (int)($main->fetchAll($main->select('MAX(id) AS max_id')->from('table.access'))[0]['max_id'] ?? 0);
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
     * 续传起点
     *
     * 优先用主库里存的断点。没有断点（老版本升上来、迁移做到一半）时才退回
     * 「目标表中不超过源表最大 id 的最大 id」这个旧推导 —— 它不准（见
     * CHECKPOINT_OPTION 的说明），但对「一直用旧版本、从没走过 Skipped 路径」
     * 的站点是对的，比从 0 重扫一遍强。退回一次之后就会存下断点，此后不再推导。
     *
     * @param Db $main
     * @param Db $target
     * @param string $fingerprint
     * @param int $maxSourceId
     * @return int
     */
    public static function resumeFrom(
        Db $main,
        Db $target,
        string $fingerprint,
        int $maxSourceId,
        bool $reserved = false
    ): int {
        $stored = self::checkpoint($main, $fingerprint);
        if ($stored !== null) {
            return $stored;
        }

        /*
         * 没有断点，只能推导。推导之前有两道闸门，任一不过就从 0 重扫 ——
         * 重扫的代价只是慢，而推错的代价是永久漏掉源表前面一整截。
         */

        # 闸门一：这一轮连自增起点都没抬起来，目标库的新日志还会落进迁移区间，不能推导
        if (!$reserved) {
            return 0;
        }

        try {
            $candidate = (int)($target->fetchAll(
                $target->select('MAX(id) AS max_id')->from('table.access')->where('id <= ?', $maxSourceId)
            )[0]['max_id'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }

        if ($candidate <= 0) {
            return 0;
        }

        /*
         * 闸门二：候选断点那一行，究竟是「从源库搬过来的副本」还是「目标库自己产生的日志」？
         *
         * 抬高自增起点只管得了以后，管不了以前：老版本在 Skipped 分支下没有抬起点，
         * 目标库已经从 id=1 开始写过自己的访问日志。这些行同样落在 id <= 源表最大 id 里，
         * MAX(id) 分不出它们和迁移数据。所以直接比对一行 —— 源库同 id 的那一行，
         * 内容对得上才认这个断点。两边都是主键查找，代价很小。
         */
        if (!self::looksMigrated($main, $target, $candidate)) {
            return 0;
        }

        return $candidate;
    }

    /**
     * 目标表 id=$id 的那一行，看起来是不是源表同 id 行的副本
     *
     * event_id 能对上就最确定（迁移是整行复制，标识跟着一起搬）。
     * 3.2.0 之前的存量行没有 event_id，退而比对 time + ip + path ——
     * 这三样凑巧全同又恰好落在同一个 id 上的概率可以忽略，而判错的方向是安全的：
     * 认不出来就返回 false，最坏结果是多重扫一遍。
     *
     * @param Db $main
     * @param Db $target
     * @param int $id
     * @return bool
     */
    /**
     * 目标库的迁移 id 区间里，有没有「不是迁移来的」行
     *
     * **这是整个迁移里最危险的一件事，必须在动手之前拦下来。**
     * COLUMNS 里包含 id —— 迁移是连主键一起复制的。目标库如果已经有自己产生的低 id 日志
     * （旧版本走 Skipped 分支时就是这个形态：配置已切换、迁移被推迟、前台从 id=1 开始写），
     * 那些主键就被占住了；源表对应的行撞上 ON CONFLICT DO NOTHING 被**静默丢弃**，
     * insertBatchDetailed 还会把它们计成写入成功（整批 INSERT 没报错），最后 done=true。
     * 实测：源表 50 行、目标库有 20 条自己的日志，迁完只剩 30 行，另外 20 行永久消失
     * 且被正式宣告「迁移完成」。
     *
     * 主键是复制过来的，所以不能改成让目标库自己分配 id：3.2.0 之前的存量行没有 event_id，
     * 它们的重放幂等**只靠这个主键**，去掉就等于每重跑一次多一份。
     *
     * 判定用抽样：只在「还没有断点」时做（有断点就说明这个区间是我们自己扫出来的）。
     * 抽样点取区间的最小、最大和中间若干个 —— 已知的故障形态是「从 id=1 开始的一段连续原生行」，
     * 最小 id 那一枪就能打中；取多个点只是加固。
     *
     * @param Db $main
     * @param Db $target
     * @param int $maxSourceId
     * @return int|null 撞上的那一行 id；没发现返回 null
     */
    private static function foreignRowInRange(Db $main, Db $target, int $maxSourceId): ?int
    {
        try {
            $bounds = $target->fetchRow(
                $target->select('MIN(id) AS lo', 'MAX(id) AS hi', 'COUNT(1) AS n')
                    ->from('table.access')->where('id <= ?', $maxSourceId)
            );
        } catch (\Throwable $e) {
            # 查不出来就别放行 —— 但也不能凭空报错，交给调用方按「认不出来」处理
            return null;
        }

        $count = (int)($bounds['n'] ?? 0);
        if ($count === 0) {
            return null;
        }

        $lo = (int)($bounds['lo'] ?? 0);
        $hi = (int)($bounds['hi'] ?? 0);
        if ($lo <= 0) {
            return null;
        }

        $samples = [$lo, $hi];
        $step = max(1, intdiv($hi - $lo, 8));
        for ($id = $lo + $step; $id < $hi; $id += $step) {
            $samples[] = $id;
        }

        foreach (array_unique($samples) as $id) {
            try {
                $exists = $target->fetchRow(
                    $target->select('id')->from('table.access')->where('id = ?', $id)
                );
            } catch (\Throwable $e) {
                continue;
            }
            if (empty($exists)) {
                continue;
            }
            if (!self::looksMigrated($main, $target, (int)$id)) {
                return (int)$id;
            }
        }

        return null;
    }

    private static function looksMigrated(Db $main, Db $target, int $id): bool
    {
        try {
            $src = $main->fetchRow(
                $main->select('id', 'event_id', 'time', 'ip', 'path')->from('table.access')->where('id = ?', $id)
            );
            $dst = $target->fetchRow(
                $target->select('id', 'event_id', 'time', 'ip', 'path')->from('table.access')->where('id = ?', $id)
            );
        } catch (\Throwable $e) {
            # 源表可能还没有 event_id 列（3.1.x 残留），或者连接出问题；一律当作认不出来
            return false;
        }

        if (empty($src) || empty($dst)) {
            return false;
        }

        $srcEvent = (string)($src['event_id'] ?? '');
        $dstEvent = (string)($dst['event_id'] ?? '');
        if ($srcEvent !== '' && $dstEvent !== '') {
            return $srcEvent === $dstEvent;
        }

        foreach (['time', 'ip', 'path'] as $column) {
            if ((string)($src[$column] ?? '') !== (string)($dst[$column] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 读出已存的断点，没存过返回 null
     *
     * @param Db $main
     * @param string $fingerprint
     * @return int|null
     */
    public static function checkpoint(Db $main, string $fingerprint): ?int
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::CHECKPOINT_OPTION)
            );
            if (empty($row['value'])) {
                return null;
            }
            $all = json_decode((string)$row['value'], true);
            if (!is_array($all) || !array_key_exists($fingerprint, $all)) {
                return null;
            }
            return (int)$all[$fingerprint];
        } catch (\Throwable $e) {
            /*
             * 读不到断点就当没有，退回旧推导 —— 那只会让扫描从更早的位置重来，
             * event_id 唯一索引与 ON CONFLICT 会挡掉重复写入。宁可重扫也不能跳过。
             */
            return null;
        }
    }

    /**
     * 存下断点
     *
     * **写失败必须抛**：断点没存下而调用方以为存下了，下一轮又从旧位置开始，
     * 那还只是重扫；真正致命的是「done=true 且断点没存下」——
     * 完成标记一打，源表从此没人看。所以这里的失败要能一路传到 run() 的返回值里。
     *
     * @param Db $main
     * @param string $fingerprint
     * @param int $lastId
     * @return void
     * @throws \Throwable
     */
    public static function saveCheckpoint(Db $main, string $fingerprint, int $lastId): void
    {
        $row = $main->fetchRow(
            $main->select()->from('table.options')->where('name = ?', self::CHECKPOINT_OPTION)
        );
        $all = empty($row['value']) ? [] : (json_decode((string)$row['value'], true) ?: []);
        if (!is_array($all)) {
            $all = [];
        }

        $all[$fingerprint] = $lastId;
        if (count($all) > self::MAX_TRACKED_TARGETS) {
            $all = array_slice($all, -self::MAX_TRACKED_TARGETS, null, true);
        }
        $value = json_encode($all);

        if (empty($row)) {
            $main->query($main->insert('table.options')
                ->rows(['name' => self::CHECKPOINT_OPTION, 'user' => 0, 'value' => $value]));
        } else {
            $main->query($main->update('table.options')
                ->rows(['value' => $value])
                ->where('name = ?', self::CHECKPOINT_OPTION));
        }
    }

    /**
     * 清掉断点（重新开始迁移、或换库之后调用）
     *
     * @param Db $main
     * @param string|null $fingerprint 为 null 时清空整行
     * @return void
     */
    public static function clearCheckpoint(Db $main, ?string $fingerprint = null): void
    {
        try {
            if ($fingerprint === null) {
                $main->query($main->delete('table.options')->where('name = ?', self::CHECKPOINT_OPTION));
                return;
            }
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::CHECKPOINT_OPTION)
            );
            $all = empty($row['value']) ? [] : (json_decode((string)$row['value'], true) ?: []);
            if (!is_array($all)) {
                return;
            }
            unset($all[$fingerprint]);
            $main->query($main->update('table.options')
                ->rows(['value' => json_encode($all)])
                ->where('name = ?', self::CHECKPOINT_OPTION));
        } catch (\Throwable $e) {
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
        # 完成标记、失败记录、断点是同一件事的三面，清一个就得全清
        self::clearFailures($main);
        self::clearCheckpoint($main);
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
        return array_keys(self::failureAttempts($main, $fingerprint));
    }

    /**
     * 失败行以及各自已经试了几次
     *
     * @param Db $main
     * @param string $fingerprint
     * @return array<int, int> 源行 id => 已尝试次数
     */
    public static function failureAttempts(Db $main, string $fingerprint): array
    {
        $all = self::readFailed($main);
        $entry = $all[$fingerprint] ?? [];
        return is_array($entry) ? $entry : [];
    }

    /**
     * 把之前写不进去的行再补写一次
     *
     * 每轮迁移开工前先做这件事。以前失败行根本没有重试路径：断点是从目标表
     * MAX(id) 推出来的，失败行在目标表里是空洞，MAX(id) 天然跳过它们，
     * 于是「记下来了」等于「记下来就没人管了」，只能靠人工。
     *
     * 试满 MAX_FAILED_ATTEMPTS 次的不再自动补写，但记录留着 —— 完成标记照样
     * 打不下去，这是它存在的意义。
     *
     * @param Db $main
     * @param Db $target
     * @param string $fingerprint
     * @param array $columns 源表实际可用的列
     * @return array{written:int, remaining:int, error:?string}
     */
    public static function retryFailures(Db $main, Db $target, string $fingerprint, array $columns): array
    {
        $out = ['written' => 0, 'remaining' => 0, 'error' => null];

        $attempts = self::failureAttempts($main, $fingerprint);
        if (empty($attempts)) {
            return $out;
        }

        $retryable = array_keys(array_filter(
            $attempts,
            static fn(int $n): bool => $n < self::MAX_FAILED_ATTEMPTS
        ));
        $out['remaining'] = count($attempts);

        if (empty($retryable)) {
            return $out;
        }

        try {
            $rows = $main->fetchAll(
                $main->select(...$columns)->from('table.access')
                    ->where('id IN ?', $retryable)
                    ->order('id', Db::SORT_ASC)
            );
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }

        /*
         * 源行已经不在了（被人删掉）就不该继续挂着：它永远补不回来，
         * 留在失败清单里只会让完成标记永远打不下去。当成「已处理」摘掉。
         */
        $found = array_map(static fn(array $r): int => (int)$r['id'], $rows);
        $vanished = array_values(array_diff($retryable, $found));

        $rows = array_values($rows);
        $outcome = empty($rows)
            ? ['written' => 0, 'failed' => [], 'kinds' => [], 'fatal' => null, 'error' => null]
            : self::insertBatchDetailed($target, $rows, $columns);

        if ($outcome['fatal'] !== null) {
            # 连不上／拼不出语句，这一轮谁也补不了，次数不该记在这些行头上
            $out['error'] = (string)$outcome['error'];
            return $out;
        }

        $stillFailed = [];
        foreach ($outcome['failed'] as $i) {
            $stillFailed[] = (int)$rows[$i]['id'];
        }

        $done = array_values(array_diff($found, $stillFailed));
        $out['written'] = count($done);

        try {
            self::resolveFailures($main, $fingerprint, array_merge($done, $vanished), $stillFailed);
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }

        $out['remaining'] = count(self::failureAttempts($main, $fingerprint));
        return $out;
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
        $entry = $all[$fingerprint] ?? [];

        foreach ($ids as $id) {
            $id = (int)$id;
            $entry[$id] = ($entry[$id] ?? 0) + 1;
        }
        ksort($entry);

        /*
         * 清单满了就**抛异常停手**，绝不能截断。
         *
         * 原来是 array_slice 留前 500 个（按 id 升序，即留下 id 小的）。可断点是照常
         * 推进的 —— 被截掉的那些 id 更大的失败行，既不在清单里、也已经被断点越过，
         * 从此不会再被读到。更糟的是：等清单里剩下的 500 条后来被修好清空，
         * failures() 返回空，ensure() 就会打下完成标记 —— 那批被截掉的行被正式宣告「迁完了」。
         *
         * 500 行写不进去本身就说明出了系统性问题，不该靠悄悄丢记录来「继续跑」。
         * 抛出去之后 run() 里的 saveCheckpoint() 不会执行，这批（含失败行）下轮会重读。
         */
        if (count($entry) > self::MAX_FAILED_TRACKED) {
            throw new \RuntimeException(sprintf(
                '写不进目标库的行已超过 %d 条，迁移停止（断点未推进）。'
                . '请先排查这些行为什么写不进去；修好之后用 --retry-failed 重试，'
                . '或者确认放弃它们后用 --forget-failed 清掉记录。',
                self::MAX_FAILED_TRACKED
            ));
        }
        $all[$fingerprint] = $entry;

        self::writeFailed($main, $all);
    }

    /**
     * 补写的结果落账：写成功（或源行已不存在）的摘掉，仍失败的次数加一
     *
     * @param Db $main
     * @param string $fingerprint
     * @param int[] $resolved 不必再管的 id
     * @param int[] $stillFailed 这轮又失败的 id
     * @return void
     * @throws \Throwable
     */
    private static function resolveFailures(
        Db $main,
        string $fingerprint,
        array $resolved,
        array $stillFailed
    ): void {
        $all = self::readFailed($main);
        $entry = $all[$fingerprint] ?? [];

        foreach ($resolved as $id) {
            unset($entry[(int)$id]);
        }
        foreach ($stillFailed as $id) {
            $id = (int)$id;
            $entry[$id] = ($entry[$id] ?? 0) + 1;
        }
        ksort($entry);

        $all[$fingerprint] = $entry;
        self::writeFailed($main, $all);
    }

    /**
     * 把失败行的尝试次数清零，让它们重新进入自动补写
     *
     * 试满 MAX_FAILED_ATTEMPTS 之后 retryFailures() 就不再碰它们了 —— 这对
     * 「一直是脏数据」是对的，但对「约束配错了、库满了，修好之后」就成了死路：
     * 记录还在（完成标记打不下去），却再也不会被补写。而断点早已越过这些行，
     * 直接 clearFailures() 等于永久漏掉它们。所以要有这条复位的路。
     *
     * @param Db $main
     * @param string $fingerprint
     * @return int 被复位的行数
     * @throws \Throwable
     */
    public static function resetFailureAttempts(Db $main, string $fingerprint): int
    {
        $all = self::readFailed($main);
        $entry = $all[$fingerprint] ?? [];
        if (empty($entry)) {
            return 0;
        }

        $all[$fingerprint] = array_fill_keys(array_keys($entry), 0);
        self::writeFailed($main, $all);

        return count($entry);
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
            if (!is_array($decoded)) {
                return [];
            }

            /*
             * 结构在 v3.2.3 从「id 列表」变成了「id => 已尝试次数」，
             * 升级上来的站点这一行还是老结构，就地折算成「试过一次」。
             *
             * 两种结构解码出来都是「int 键 + int 值」的数组，逐个元素是分不开的：
             * 老的 [101,205,307] 是 [0=>101,1=>205,2=>307]，
             * 新的 {"101":1,"205":2} 是 [101=>1,205=>2]。
             * 唯一可靠的判据是整体形状 —— 老结构是列表（键从 0 开始连续），
             * 而新结构的键是源行 id，自增主键从 1 起，永远不会构成以 0 开头的连续序列。
             * 按元素猜的写法会把 [101,205,307] 读成 {101:1, 1:205, 2:307}：
             * 凭空造出失败行 1 和 2，同时把 205、307 丢掉。
             */
            $out = [];
            foreach ($decoded as $fingerprint => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (array_is_list($entry)) {
                    # 老结构：值就是 id
                    $normalized = [];
                    foreach ($entry as $id) {
                        if (is_scalar($id)) {
                            $normalized[(int)$id] = 1;
                        }
                    }
                } else {
                    # 新结构：id => 次数
                    $normalized = [];
                    foreach ($entry as $id => $attempts) {
                        if (is_scalar($attempts)) {
                            $normalized[(int)$id] = max(1, (int)$attempts);
                        }
                    }
                }

                ksort($normalized);
                $out[$fingerprint] = $normalized;
            }
            return $out;
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
        /*
         * **不吞异常。**
         *
         * 这一行记的是「有行没迁过去」，而它是唯一挡住完成标记的东西：
         * ensure() 和命令行脚本都要先看 failures() 为空才 mark()。
         * 写失败却当成写成功的话，下一轮 failures() 读到空 → 完成标记打下去 →
         * isMarked() 此后每次直接返回 → 那几行数据谁也不会再想起来。
         * 记不下来就必须让调用方知道，宁可这轮报错重来。
         */
        $all = array_filter($all, static fn($entry): bool => !empty($entry));
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
