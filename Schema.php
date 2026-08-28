<?php

namespace TypechoPlugin\Access;

use Typecho\Db;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 统计表的结构版本与升级
 *
 * 插件版本每次发布都会动，表结构却很少变，两者一一对应只会让绝大多数版本
 * 白跑一遍升级检查。所以这里的 VERSION 是「当前表结构定型于哪个插件版本」：
 * 表结构变了就把它改成那次发布的版本号，没变就一直保持不动。
 *
 * 版本号存在 Typecho 主库的 options 里，而不是统计表自己身上：
 * 统计表可能位于独立数据库，读它就得先连上；而这里恰恰要在连接之前
 * 知道该不该升级。键值按「统计库指纹」分别记录，于是在多个统计库之间
 * 来回切换时，每个库的结构版本都能对上号。
 */
final class Schema
{
    /**
     * 当前表结构的版本
     *
     * 3.2.0：新增 event_id 列与其唯一索引（写入幂等）
     * 3.2.1：MySQL 的 ip 列扩到 IP_LENGTH 个字符
     * 3.2.2：给 PostgreSQL 的 ip 列固定 n_distinct 估计
     */
    public const VERSION = '3.2.2';

    /**
     * ip 列需要的字符数
     *
     * 注意这一列存的**不是地址文本**，而是地址的十进制整数表示
     * （见 Core::ip62long()，IPv4 走 ip2long，两者都落成十进制字符串）。
     * 所以上限取决于 2^128-1 = 340282366920938463463374607431768211455，共 39 位数字，
     * 与「完整展开的 IPv6 文本长 39 字符」只是碰巧同为 39 —— 别按文本长度去推它，
     * 那条路会得出「IPv4-mapped 完整形式 45 字符，应该用 45」，对这个 schema 是错的。
     *
     * 原来的 38 会把十进制表示恰好 39 位的地址截掉末位。截掉末位等于除以 10，
     * 存进去的是另一个看起来合法的地址。受影响的是 fc00::/7、fe80::/10、ff00::/8；
     * 2000::/3 全球单播是 38 位，一直没事 —— 所以这个问题长期没被发现。
     */
    private const IP_LENGTH = 39;

    /**
     * 升级步骤：目标版本 => 处理方法
     *
     * 存量库的版本号低于某一项时就执行它，按数组顺序依次进行。
     */
    private const STEPS = [
        '3.2.0' => 'toV320',
        '3.2.1' => 'toV321',
        '3.2.2' => 'toV322',
    ];

    /**
     * 告诉 PostgreSQL：ip 列大约有这么高比例的不同值
     *
     * 负数表示「占表行数的比例」，会随表增长自动缩放；正数是绝对条数。
     *
     * 为什么必须手工指定：ANALYZE 是从三万行样本里外推 n_distinct 的，
     * 对高基数列出了名地不准。实测一张 314 万行的表，真实不同 ip 有 45 万个
     * （14.3%），ANALYZE 估成 22,878（0.73%）—— 低估 20 倍。
     *
     * 后果不是「差一点」，是选错计划：规划器按两万多组去规划哈希表，
     * 实际要装四十几万组，超出 work_mem 后 HashAggregate 落盘重分区，
     * 一条本该一秒的查询跑了 643 秒。估对之后规划器改走
     * 「按 (ip, ua) 索引顺序相邻去重」，不建哈希表，耗时降到 1 秒。
     *
     * 取值方向比精度重要得多，两个方向的代价完全不对等：
     *   估低了 —— 哈希表装不下，落盘，慢几百倍
     *   估高了 —— 多读一点索引，慢一点点，有上界
     * 所以宁可往高了取。0.1 对访问日志类的表是个偏保守的中间值；
     * 想调准可以自己量一次再改：
     *   SELECT count(DISTINCT ip)::float / count(*) FROM [前缀]access;
     */
    private const IP_N_DISTINCT = '-0.1';

    /**
     * 版本号存在独立的 options 行里
     *
     * 不能塞进 plugin:Access 的配置：Typecho 渲染插件设置页时会遍历已保存的每个键
     * 去找同名表单控件，没有声明成表单项的键会触发 Undefined array key。
     * 这和 Migrate 的迁移标记是同一个坑。
     */
    private const OPTION = 'access_schema_version';

    /**
     * 「这个统计库缺幂等保护」的标记
     *
     * event_id 唯一索引是队列「至少一次投递」变成「恰好一次结果」的唯一依靠。
     * 它建不起来（权限不足、存量数据里已有重复值）时，升级流程原来只是把原因
     * 写进一句后台提示，插件照常启用、队列照常跑 —— 而队列一旦重放
     * （进程被杀、processing 捡回、命令行重跑），同一条访问日志就会被重复计入统计，
     * 且没有任何迹象。这个标记让写入侧能直接看到这件事并降级为数据库直写。
     */
    private const DEGRADED_OPTION = 'access_schema_degraded';

    /** 最多记住几个统计库的版本，避免反复换库时这一行无限变长 */
    private const MAX_TRACKED = 8;

    /**
     * 确保目标库的表结构是当前版本，需要时执行升级
     *
     * @param Db $target 统计数据所在的库
     * @param string $fingerprint 统计库指纹（Migrate::fingerprint() 的结果）
     * @param bool $justCreated 本次是否刚建过表（新建的表天然就是最新结构）
     * @param Db|null $main Typecho 主库（版本号记在那里），null 表示自行获取
     * @return array{from:?string,to:string,applied:string[],repaired:string[],error:?string}
     *         applied  本次执行了哪些升级步骤
     *         repaired 版本号已是最新、但实地校验发现缺失并补上的结构项
     */
    public static function ensure(Db $target, string $fingerprint, bool $justCreated, ?Db $main = null): array
    {
        $result = self::runEnsure($target, $fingerprint, $justCreated, $main);

        /*
         * 不管上面走的是哪条分支（跳过、升级、修复、失败），最后都实地确认一次
         * 幂等保护到底在不在，并把结论记下来给写入侧看。
         * 版本号说「升过了」不等于索引真的建起来了 —— 这正是当初加 gaps() 的理由，
         * 这里只是把同一个教训延伸到「建不起来之后怎么办」。
         */
        $result['critical'] = false;
        try {
            $table = $target->getPrefix() . 'access';
            $result['critical'] = !Database::uniqueIndexOn($target, $table, 'event_id');
            self::markDegraded($main ?? Database::main(), $fingerprint, $result['critical']);
        } catch (\Throwable $e) {
            // 判定不了就不改标记，保持上一次的结论
        }

        return $result;
    }

    /**
     * 这个统计库是不是缺幂等保护（缺 event_id 唯一索引）
     *
     * 写入侧每条日志都要问一次，所以按请求缓存，并优先走 Typecho 已经加载好的
     * options（它启动时就把 user=0 的整张表读进内存了），读不到再退回直接查询。
     *
     * @param string $fingerprint
     * @param Db|null $main
     * @return bool
     */
    public static function isDegraded(string $fingerprint, ?Db $main = null): bool
    {
        if (array_key_exists($fingerprint, self::$degradedCache)) {
            return self::$degradedCache[$fingerprint];
        }

        $value = null;
        try {
            $value = Helper::options()->{self::DEGRADED_OPTION} ?? null;
        } catch (\Throwable $e) {
            // 命令行下没有 Options，往下走直接查询
        }

        if ($value === null) {
            try {
                $row = ($main ?? Database::main())->fetchRow(
                    ($main ?? Database::main())->select()->from('table.options')
                        ->where('name = ?', self::DEGRADED_OPTION)
                );
                $value = $row['value'] ?? '';
            } catch (\Throwable $e) {
                /*
                 * 读不出来就当**没有**降级。
                 * 反过来（读不到就降级）会让一次主库抖动把所有站点的写入队列关掉，
                 * 而缺索引的后果只是统计数字可能重复 —— 前者的代价大得多。
                 */
                return self::$degradedCache[$fingerprint] = false;
            }
        }

        $all = json_decode((string)$value, true);
        $degraded = is_array($all) && !empty($all[$fingerprint]);

        return self::$degradedCache[$fingerprint] = $degraded;
    }

    /**
     * 记下/清掉某个统计库的降级标记
     *
     * @param Db $main
     * @param string $fingerprint
     * @param bool $degraded
     * @return void
     */
    private static function markDegraded(Db $main, string $fingerprint, bool $degraded): void
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::DEGRADED_OPTION)
            );
            $all = empty($row['value']) ? [] : (json_decode((string)$row['value'], true) ?: []);
            if (!is_array($all)) {
                $all = [];
            }

            if ($degraded) {
                $all[$fingerprint] = 1;
            } else {
                unset($all[$fingerprint]);
            }

            $value = json_encode($all);
            if (empty($row)) {
                if ($degraded) {
                    $main->query($main->insert('table.options')
                        ->rows(['name' => self::DEGRADED_OPTION, 'user' => 0, 'value' => $value]));
                }
            } else {
                $main->query($main->update('table.options')
                    ->rows(['value' => $value])
                    ->where('name = ?', self::DEGRADED_OPTION));
            }

            self::$degradedCache[$fingerprint] = $degraded;
        } catch (\Throwable $e) {
            // 记不下来不该挡住启用流程；下次保存设置还会再判一次
        }
    }

    /** @var array<string,bool> 按请求缓存降级判定 */
    private static array $degradedCache = [];

    /**
     * @param Db $target
     * @param string $fingerprint
     * @param bool $justCreated
     * @param Db|null $main
     * @return array
     */
    private static function runEnsure(Db $target, string $fingerprint, bool $justCreated, ?Db $main = null): array
    {
        $result = ['from' => null, 'to' => self::VERSION, 'applied' => [], 'repaired' => [], 'error' => null];

        try {
            $main = $main ?? Database::main();
        } catch (\Throwable $e) {
            # 连不上主库就无处记录版本号，此时宁可什么都不做也不能改表结构：
            # 改了却没记下来，下次启用会当成还没升级再来一遍
            $result['error'] = $e->getMessage();
            return $result;
        }

        $stored = self::stored($main, $fingerprint);
        $result['from'] = $stored;

        if ($justCreated) {
            # 刚照最新脚本建的表，不需要也不能跑升级步骤
            self::remember($main, $fingerprint, self::VERSION);
            $result['from'] = self::VERSION;
            return $result;
        }

        $table = $target->getPrefix() . 'access';
        $driver = Database::driver($target);

        if ($stored === self::VERSION) {
            /*
             * 版本号对得上不代表结构真的到位。
             * 历史上建索引的异常被无条件吞掉，版本号照记 —— 于是「有 event_id 列、
             * 没有唯一索引、版本却是 3.2.0」会变成永久状态，此后每次启用都在这里直接返回，
             * 再也没人发现幂等保护其实从来没生效过。
             * 版本号只用来跳过升级步骤，不能用来跳过校验。
             */
            $gaps = self::gaps($target, $table);
            if (empty($gaps)) {
                return $result;
            }

            try {
                /*
                 * 每个升级步骤都写成幂等的（先探测、够了就返回），
                 * 所以修复直接把它们按顺序重跑一遍，不必去猜是哪一步没做完。
                 * 以后新增步骤只要保持幂等，这里不用动。
                 */
                @set_time_limit(0);
                foreach (self::STEPS as $method) {
                    self::$method($target, $driver, $table);
                }
                $result['repaired'] = $gaps;
            } catch (\Throwable $e) {
                # 修不好就把版本号退回去，下次启用还会再试，而不是永远假装已经升级过
                self::forget($main, $fingerprint);
                $result['error'] = sprintf(
                    '表结构校验发现缺失（%s），自动修复失败：%s',
                    implode('、', $gaps),
                    $e->getMessage()
                );
            }
            return $result;
        }

        # 建索引、改列宽在大表上都要跑很久，别让执行时限把升级砍在半路
        @set_time_limit(0);

        foreach (self::STEPS as $version => $method) {
            if ($stored !== null && version_compare($stored, $version, '>=')) {
                continue;
            }
            try {
                self::$method($target, $driver, $table);
                $result['applied'][] = $version;
            } catch (\Throwable $e) {
                # 升到一半失败：已完成的步骤保留，版本号停在最后一个成功的位置，
                # 下次启用会从这里接着来，不会把做过的再做一遍
                $result['error'] = $e->getMessage();
                if (!empty($result['applied'])) {
                    self::remember($main, $fingerprint, (string)end($result['applied']));
                }
                return $result;
            }
        }

        self::remember($main, $fingerprint, self::VERSION);
        return $result;
    }

    /**
     * 3.2.0：加上 event_id 列和它的唯一索引
     *
     * 存量行的 event_id 保持 NULL —— 三种数据库的唯一索引都允许多个 NULL，
     * 所以老数据不会互相冲突，只是那部分不具备幂等能力。
     *
     * @param Db $target
     * @param Driver $driver
     * @param string $table
     * @return void
     */
    private static function toV320(Db $target, Driver $driver, string $table): void
    {
        $quoted = $driver->quoteTable($table);

        if (!Database::columnExists($target, $table, 'event_id')) {
            $type = $driver === Driver::Pgsql ? 'varchar(32)' : 'char(32)';
            $column = $driver === Driver::Pgsql ? '"event_id"' : '`event_id`';
            $target->query("ALTER TABLE {$quoted} ADD COLUMN {$column} {$type} DEFAULT NULL", Db::WRITE);

            if (!Database::columnExists($target, $table, 'event_id')) {
                throw new \RuntimeException('ALTER TABLE 没有报错，但 event_id 列仍然不存在');
            }
        }

        # 已经有单列唯一索引就什么都不用做（重复启用时的正常状态）
        if (Database::uniqueIndexOn($target, $table, 'event_id')) {
            return;
        }

        /*
         * MySQL 没有 CREATE INDEX IF NOT EXISTS，所以这里仍然要 try，
         * 但只能容忍「索引其实已经存在」这一种情况 —— 由建完之后的复核来判定。
         * 以前是无条件吞掉：权限不足、存量数据有重复值都会被当成成功，
         * 版本号照记 3.2.0，幂等保护实际从未生效。
         */
        $index = $driver === Driver::Pgsql ? $table . '_event_id' : '`' . $table . '_event_id`';
        $column = $driver === Driver::Pgsql ? '"event_id"' : '`event_id`';

        $failure = null;
        try {
            $target->query("CREATE UNIQUE INDEX {$index} ON {$quoted} ({$column})", Db::WRITE);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        if (Database::uniqueIndexOn($target, $table, 'event_id')) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'event_id 唯一索引创建失败：%s。缺少这个索引，队列重放会把同一条访问日志重复计入统计。'
            . '常见原因是数据库账号没有建索引的权限，或存量数据里已经存在重复的 event_id。',
            $failure !== null ? $failure->getMessage() : '语句执行后索引仍不存在'
        ), 0, $failure);
    }

    /**
     * 3.2.1：把 MySQL 存量表的 ip 列扩到 IP_LENGTH（原来是 38，会截断一部分 IPv6）
     *
     * PostgreSQL 的建表脚本一直是 varchar(39)，SQLite 不执行字符长度限制，
     * 因此只有 MySQL 存量表需要 ALTER。
     *
     * @param Db $target
     * @param Driver $driver
     * @param string $table
     * @return void
     */
    private static function toV321(Db $target, Driver $driver, string $table): void
    {
        if ($driver !== Driver::Mysql) {
            return;
        }

        /*
         * 先探测再改，和 toV320 一个路子，原因在这里更重：
         * MySQL 改 CHAR 长度只能走 ALGORITHM=COPY（只有 VARCHAR 加长支持 INPLACE），
         * 会把整表连同全部索引一起重建，期间阻塞写入 —— 而这段代码跑在
         * 「保存插件设置」的那个 Web 请求里。
         *
         * 已经够宽就直接返回。这一步不是可有可无的优化：ALTER 失败时 ensure()
         * 只把版本号记到上一步，下次保存设置会从头再来一遍；没有这道探测的话，
         * 大表上就是每保存一次设置就重建一次表，而且永远收敛不了。
         */
        $length = Database::columnLength($target, $table, 'ip');
        if ($length !== null && $length >= self::IP_LENGTH) {
            return;
        }

        # 大表上这条语句要跑很久，别让 PHP 的执行时限在重建到一半时把它砍掉
        @set_time_limit(0);

        $quoted = $driver->quoteTable($table);
        $target->query(
            "ALTER TABLE {$quoted} MODIFY COLUMN `ip` char(" . self::IP_LENGTH . ") DEFAULT '0' COMMENT 'IP'",
            Db::WRITE
        );

        # 复核一次，别让「语句没报错但列宽没变」蒙混过关（和 toV320 建完索引要复核同理）
        $after = Database::columnLength($target, $table, 'ip');
        if ($after !== null && $after < self::IP_LENGTH) {
            throw new \RuntimeException(sprintf(
                'ip 列扩宽失败：ALTER 执行后仍是 %d 个字符，需要 %d。请检查数据库账号是否有 ALTER 权限',
                $after,
                self::IP_LENGTH
            ));
        }
    }

    /**
     * 3.2.2：给 PostgreSQL 的 ip 列固定 n_distinct 估计
     *
     * 只对 PostgreSQL 有意义：MySQL 和 SQLite 没有这个 per-column 选项。
     *
     * @param Db $target
     * @param Driver $driver
     * @param string $table
     * @return void
     */
    private static function toV322(Db $target, Driver $driver, string $table): void
    {
        if ($driver !== Driver::Pgsql) {
            return;
        }

        # 已经设过就别动，也别白跑一次 ANALYZE
        if (Database::columnOption($target, $table, 'ip', 'n_distinct') !== null) {
            return;
        }

        $quoted = $driver->quoteTable($table);

        # 纯元数据改动，不重写表，不锁
        $target->query(
            "ALTER TABLE {$quoted} ALTER COLUMN \"ip\" SET (n_distinct = " . self::IP_N_DISTINCT . ")",
            Db::WRITE
        );

        /*
         * 这一步不能省：attoptions 只是「下次 ANALYZE 请按这个值来」，
         * 规划器真正读的是 pg_statistic 里的 stadistinct。不跑 ANALYZE 的话
         * 设置写进去了却毫无效果 —— 而且没有任何报错，最难查的那种。
         */
        $target->query("ANALYZE {$quoted}", Db::WRITE);

        if (Database::columnOption($target, $table, 'ip', 'n_distinct') === null) {
            throw new \RuntimeException(
                'ip 列的 n_distinct 设置失败：语句执行后仍读不到该选项，请检查数据库账号是否为表的属主'
            );
        }
    }

    /**
     * 关键结构里缺了什么
     *
     * 只查「少了会出错」的那几项，而不是全表比对：这是每次启用都要跑的路径。
     *
     * @param Db $target
     * @param string $table 完整表名（含前缀）
     * @return string[] 缺失项的中文描述，全都在就是空数组
     */
    private static function gaps(Db $target, string $table): array
    {
        $gaps = [];

        if (!Database::columnExists($target, $table, 'event_id')) {
            $gaps[] = 'event_id 列';
            # 列都没有，索引不用查了
            return $gaps;
        }

        if (!Database::uniqueIndexOn($target, $table, 'event_id')) {
            $gaps[] = 'event_id 唯一索引';
        }

        /*
         * ip 列宽（3.2.1）。窄了不会报错，只会把 fe80::/fc00:: 这类地址的
         * 十进制表示截掉末位 —— 存成另一个看起来合法的地址，事后无从分辨。
         * 正因为它是静默的，才更该纳入每次启用的实地校验。
         */
        $ipLength = Database::columnLength($target, $table, 'ip');
        if ($ipLength !== null && $ipLength < self::IP_LENGTH) {
            $gaps[] = sprintf('ip 列宽（当前 %d，需要 %d）', $ipLength, self::IP_LENGTH);
        }

        /*
         * ip 列的 n_distinct（3.2.2，仅 PostgreSQL）。
         * 它比列宽更容易悄悄丢：重建表、换统计库、跑一次迁移都会退回默认，
         * 而且不影响正确性 —— 只是概览的「总计」从一秒变成十分钟，
         * 没有任何报错可循。所以每次启用都实地看一眼。
         */
        if (Database::driver($target) === Driver::Pgsql
            && Database::columnOption($target, $table, 'ip', 'n_distinct') === null
        ) {
            $gaps[] = 'ip 列的 n_distinct 估计';
        }

        return $gaps;
    }

    /**
     * 读出某个统计库记录在案的结构版本
     *
     * @param Db $main Typecho 主库
     * @param string $fingerprint
     * @return string|null 从未记录过时返回 null
     */
    public static function stored(Db $main, string $fingerprint): ?string
    {
        foreach (self::readAll($main) as $key => $version) {
            if ($key === $fingerprint) {
                return (string)$version;
            }
        }
        return null;
    }

    /**
     * 记下某个统计库的结构版本
     *
     * @param Db $main
     * @param string $fingerprint
     * @param string $version
     * @return void
     */
    public static function remember(Db $main, string $fingerprint, string $version): void
    {
        $all = self::readAll($main);
        unset($all[$fingerprint]);
        $all[$fingerprint] = $version;

        # 只保留最近使用的几个，防止反复换库时这一行无限变长
        if (count($all) > self::MAX_TRACKED) {
            $all = array_slice($all, -self::MAX_TRACKED, null, true);
        }

        self::write($main, $all);
    }

    /**
     * 忘掉某个统计库的结构版本（删表之后调用）
     *
     * @param Db $main
     * @param string|null $fingerprint 为 null 时清空整行
     * @return void
     */
    public static function forget(Db $main, ?string $fingerprint = null): void
    {
        try {
            if ($fingerprint === null) {
                $main->query($main->delete('table.options')->where('name = ?', self::OPTION));
                return;
            }
            $all = self::readAll($main);
            unset($all[$fingerprint]);
            self::write($main, $all);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param Db $main
     * @return array<string, string>
     */
    private static function readAll(Db $main): array
    {
        try {
            $row = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::OPTION)
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
     * @param array<string, string> $all
     * @return void
     */
    private static function write(Db $main, array $all): void
    {
        try {
            $value = json_encode($all, JSON_UNESCAPED_UNICODE);
            $exists = $main->fetchRow(
                $main->select()->from('table.options')->where('name = ?', self::OPTION)
            );

            if (empty($exists)) {
                $main->query($main->insert('table.options')
                    ->rows(['name' => self::OPTION, 'user' => 0, 'value' => $value]));
            } else {
                $main->query($main->update('table.options')
                    ->rows(['value' => $value])
                    ->where('name = ?', self::OPTION));
            }
        } catch (\Throwable $e) {
        }
    }
}
