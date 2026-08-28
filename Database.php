<?php

namespace TypechoPlugin\Access;

use Typecho\Config;
use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件数据库连接管理
 *
 * 默认跟随 Typecho 自身使用的数据库；也可以在插件设置中单独配置一个
 * MySQL / PostgreSQL 连接，把访问日志写到另一个库里，与 Typecho 主库解耦。
 *
 * 注意：这里刻意不调用 Typecho\Db::set()，独立连接只在插件内部使用，
 * 不会影响 Typecho 本身以及其它插件。
 */
final class Database
{
    /** 已建立的独立连接，按配置指纹缓存，避免一次请求内重复连接 */
    private static array $pool = [];

    /**
     * 读取插件配置，未启用或未配置时返回 null
     *
     * @return Config|null
     */
    public static function pluginConfig(): ?Config
    {
        try {
            return Options::alloc()->plugin('Access');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 归一化数据库设置
     *
     * @param array|Config|null $source 配置来源，可以是保存配置时的 settings 数组、
     *                                   Typecho\Config 对象，为 null 时读取已保存的插件配置
     * @return array
     */
    public static function settings(array|Config|null $source = null): array
    {
        if ($source === null) {
            $source = self::pluginConfig();
        }

        $pick = static function (string $key, string $default = '') use ($source): string {
            $value = null;
            if (is_array($source) || $source instanceof \ArrayAccess) {
                $value = $source[$key] ?? null;
            } elseif (is_object($source)) {
                $value = $source->$key ?? null;
            }
            if ($value === null) {
                return $default;
            }
            $value = trim((string)$value);
            return $value === '' ? $default : $value;
        };

        $type = DbType::parse($pick('dbType', DbType::Follow->value));
        $port = $pick('dbPort', '');

        return [
            'type'     => $type,
            'host'     => $pick('dbHost', '127.0.0.1'),
            'port'     => $port === '' ? $type->defaultPort() : (int)$port,
            'user'     => $pick('dbUser', ''),
            'password' => $pick('dbPass', ''),
            'database' => $pick('dbName', ''),
            'prefix'   => $pick('dbPrefix', 'typecho_'),
            'charset'  => $pick('dbCharset', $type->defaultCharset()),
        ];
    }

    /**
     * 是否配置了独立数据库
     *
     * @param array|Config|null $source
     * @return bool
     */
    public static function isExternal(array|Config|null $source = null): bool
    {
        $settings = self::normalized($source);
        return $settings['type']->isExternal() && $settings['database'] !== '';
    }

    /**
     * 获取插件应当使用的数据库实例
     *
     * @param array|Config|null $source
     * @return Db
     * @throws \Typecho\Db\Exception
     */
    public static function get(array|Config|null $source = null): Db
    {
        $settings = self::normalized($source);

        if (!self::isExternal($settings)) {
            return self::main();
        }

        $key = md5(serialize($settings));
        if (!isset(self::$pool[$key])) {
            self::$pool[$key] = self::connect($settings);
        }

        return self::$pool[$key];
    }

    /**
     * Typecho 自身使用的数据库
     *
     * @return Db
     * @throws \Typecho\Db\Exception
     */
    public static function main(): Db
    {
        return Db::get();
    }

    /**
     * 按配置建立一个独立连接（惰性连接，此处不会真正握手）
     *
     * @param array $settings
     * @return Db
     * @throws \Typecho\Db\Exception
     */
    public static function connect(array $settings): Db
    {
        $db = new Db($settings['type']->adapter(), $settings['prefix']);
        $db->addServer([
            'host'     => $settings['host'],
            'port'     => $settings['port'],
            'user'     => $settings['user'],
            'password' => $settings['password'],
            'charset'  => $settings['charset'],
            'database' => $settings['database'],
        ], Db::READ | Db::WRITE);

        return $db;
    }

    /**
     * 测试独立数据库配置是否可用
     *
     * @param array $settings
     * @return string|null 可用时返回 null，否则返回错误信息
     */
    public static function test(array $settings): ?string
    {
        if (!$settings['type']->isExternal()) {
            return _t('未知的数据库类型');
        }
        if ($settings['database'] === '') {
            return _t('请填写数据库名称');
        }

        $extension = $settings['type']->extension();
        if (!extension_loaded($extension)) {
            return _t('当前 PHP 环境缺失 %s 扩展', $extension);
        }

        try {
            $db = self::connect($settings);
            $db->fetchRow($db->query('SELECT 1 AS access_probe', Db::READ));
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * 获取某个数据库实例的驱动类型
     *
     * @param Db $db
     * @return Driver
     */
    public static function driver(Db $db): Driver
    {
        return Driver::fromAdapterName($db->getAdapterName());
    }

    /**
     * 把各种形态的配置来源统一成归一化数组
     * 已经归一化过的（'type' 是 DbType 实例）直接返回
     *
     * @param array|Config|null $source
     * @return array
     */
    private static function normalized(array|Config|null $source): array
    {
        return is_array($source) && ($source['type'] ?? null) instanceof DbType
            ? $source
            : self::settings($source);
    }

    /**
     * 把异常翻译成对使用者有意义的说明
     *
     * Typecho 的 PDO 适配器（var/Typecho/Db/Adapter/Pdo.php）在捕获 PDOException 后写的是
     * `throw new SQLException($e->getMessage(), $e->getCode())`，而 PDOException::getCode()
     * 返回的是 SQLSTATE 字符串。PostgreSQL 有一部分 SQLSTATE 含字母（最常见的是 42P01
     * 「表不存在」），传给要求 int 的 Exception 构造函数会先抛 TypeError，
     * 真正的数据库错误信息就此丢失。这里识别出这种情况后主动探测一次，给出可操作的结论。
     *
     * @param \Throwable $e
     * @param Db|null $db 出问题的连接，为 null 时按当前配置解析
     * @return string
     */
    public static function explainError(\Throwable $e, ?Db $db = null): string
    {
        $message = $e->getMessage();

        if (!$e instanceof \TypeError || !str_contains($message, 'Exception::__construct()')) {
            return $message;
        }

        $hint = _t('数据库报错，但当前 Typecho 版本的 PDO 适配器把原始错误信息丢失了。');

        try {
            $settings = self::settings();
            if ($settings['type']->isExternal()) {
                $error = self::test($settings);
                if ($error !== null) {
                    return $hint . _t('探测：无法连接统计数据库 —— %s', $error);
                }
            }

            $db = $db ?? self::get();
            $table = $db->getPrefix() . 'access';

            if (!self::tableExists($db, $table)) {
                return $hint . _t(
                    '探测：统计数据库中找不到数据表 %s。请到插件设置页重新保存一次设置以建表，'
                    . '并确认「统计数据表前缀」与实际表名一致。',
                    $table
                );
            }

            return $hint . _t(
                '探测：数据表 %s 存在且可连接，请检查该表字段是否完整（可对照 sql/PostgreSQL.sql），'
                . '以及连接账号是否有足够权限。',
                $table
            );
        } catch (\Throwable $probe) {
            return $hint . _t('探测本身也失败了：%s', $probe->getMessage());
        }
    }

    /**
     * 判断数据表是否存在（兼容三种数据库）
     *
     * @param Db $db
     * @param string $table 完整表名（含前缀）
     * @return bool
     */
    public static function tableExists(Db $db, string $table): bool
    {
        try {
            $sql = match (self::driver($db)) {
                // current_schemas(false) 即当前 search_path，与未加限定的 CREATE TABLE 落点一致
                Driver::Pgsql => "SELECT tablename FROM pg_catalog.pg_tables
                                  WHERE schemaname = ANY (current_schemas(false)) AND tablename = '{$table}'",
                Driver::Sqlite => "SELECT name FROM sqlite_master WHERE TYPE='table' AND name='{$table}'",
                Driver::Mysql => "SHOW TABLES LIKE '{$table}'",
            };
            return !empty($db->fetchRow($db->query($sql, Db::READ)));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 某一列是否存在
     *
     * 表结构升级要靠它判断该不该执行 ALTER：重复启用插件是常态，
     * 直接 ALTER 会因为「列已存在」报错。
     *
     * @param Db $db
     * @param string $table 完整表名（含前缀）
     * @param string $column
     * @return bool
     */
    public static function columnExists(Db $db, string $table, string $column): bool
    {
        try {
            $driver = self::driver($db);

            if ($driver === Driver::Sqlite) {
                # SQLite 没有 information_schema，只能把表结构列出来找
                $rows = $db->fetchAll($db->query("PRAGMA table_info(`{$table}`)", Db::READ));
                foreach ($rows as $row) {
                    if (isset($row['name']) && strcasecmp((string)$row['name'], $column) === 0) {
                        return true;
                    }
                }
                return false;
            }

            $sql = match ($driver) {
                Driver::Pgsql => "SELECT column_name FROM information_schema.columns
                                  WHERE table_schema = ANY (current_schemas(false))
                                    AND table_name = '{$table}' AND column_name = '{$column}'",
                Driver::Mysql => "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'",
                Driver::Sqlite => '',
            };
            return !empty($db->fetchRow($db->query($sql, Db::READ)));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 某一列声明的最大字符数
     *
     * 结构升级要靠它判断「这一列到底用不用改」。MySQL 改 CHAR 长度只能走
     * ALGORITHM=COPY —— 整表连同全部索引一起重建、期间阻塞写入，
     * 而升级跑在保存设置的那个 Web 请求里。不先探一下就发 ALTER 的话，
     * 已经够宽的表每次保存设置都要白白重建一遍。
     *
     * @param Db $db
     * @param string $table 完整表名（含前缀）
     * @param string $column
     * @return int|null 判定不了时返回 null：SQLite 根本不强制字符长度，
     *                  其余情况是探测本身失败。调用方应把 null 当作「不知道」，
     *                  而不是「没有限制」或「限制为 0」
     */
    public static function columnLength(Db $db, string $table, string $column): ?int
    {
        try {
            $driver = self::driver($db);

            if ($driver === Driver::Sqlite) {
                # SQLite 的 char(N)/varchar(N) 只是标注，不会截断也不会报错
                return null;
            }

            if ($driver === Driver::Mysql) {
                # 用 SHOW COLUMNS 而不是 information_schema：不必操心当前 schema 是哪个
                $row = $db->fetchRow($db->query(
                    "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'",
                    Db::READ
                ));
                if (empty($row['Type']) || !preg_match('/\((\d+)\)/', (string)$row['Type'], $m)) {
                    return null;
                }
                return (int)$m[1];
            }

            $row = $db->fetchRow($db->query(
                "SELECT character_maximum_length AS len FROM information_schema.columns
                  WHERE table_schema = ANY (current_schemas(false))
                    AND table_name = '{$table}' AND column_name = '{$column}'",
                Db::READ
            ));

            return isset($row['len']) && $row['len'] !== null ? (int)$row['len'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 读出 PostgreSQL 某一列上设置的 per-column 选项（attoptions）
     *
     * 目前只用来查 n_distinct。这类设置不影响正确性、只影响规划器选什么计划，
     * 丢了不会报错也不会有任何征兆 —— 只是某条查询悄悄慢上几百倍。
     * 正因为它是静默的，才需要能探测、能在每次启用时校验。
     *
     * @param Db $db
     * @param string $table 完整表名（含前缀）
     * @param string $column
     * @param string $option 选项名，例如 n_distinct
     * @return string|null 没设置、非 PostgreSQL、或探测失败时返回 null
     */
    public static function columnOption(Db $db, string $table, string $column, string $option): ?string
    {
        try {
            if (self::driver($db) !== Driver::Pgsql) {
                return null;
            }

            $row = $db->fetchRow($db->query(
                "SELECT array_to_string(a.attoptions, ',') AS opts
                   FROM pg_attribute a
                   JOIN pg_class c ON c.oid = a.attrelid
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relname = '{$table}'
                    AND n.nspname = ANY (current_schemas(false))
                    AND a.attname = '{$column}'
                    AND NOT a.attisdropped",
                Db::READ
            ));

            if (empty($row['opts'])) {
                return null;
            }

            # attoptions 形如 n_distinct=-0.1,n_distinct_inherited=-0.1
            return preg_match('/(?:^|,)' . preg_quote($option, '/') . '=([^,]+)/', (string)$row['opts'], $m)
                ? $m[1]
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 某一列上是否存在「单列唯一索引」
     *
     * 按索引名去找是不够的：新建表时索引名来自 sql/*.sql（MySQL 里叫 uk_event_id），
     * 存量表升级时的索引名却是 Schema 自己拼的 {表名}_event_id，两条路各叫各的。
     * 真正要确认的从来不是名字，而是「这一列上有没有唯一约束」——
     * 没有它，队列重放就会把同一条访问日志重复计入统计。
     *
     * 只认单列唯一索引：复合唯一索引 (event_id, x) 不能保证 event_id 本身唯一。
     *
     * @param Db $db
     * @param string $table 完整表名（含前缀）
     * @param string $column
     * @return bool 探测本身失败时返回 false（宁可误报缺失去重建，也不能漏报）
     */
    public static function uniqueIndexOn(Db $db, string $table, string $column): bool
    {
        try {
            $driver = self::driver($db);

            if ($driver === Driver::Sqlite) {
                # SQLite 要两级 PRAGMA：先列出索引，再逐个看它盖住哪些列
                $indexes = $db->fetchAll($db->query("PRAGMA index_list(`{$table}`)", Db::READ));
                foreach ($indexes as $index) {
                    if (empty($index['unique']) || empty($index['name'])) {
                        continue;
                    }
                    $columns = $db->fetchAll(
                        $db->query("PRAGMA index_info(`{$index['name']}`)", Db::READ)
                    );
                    if (count($columns) === 1
                        && isset($columns[0]['name'])
                        && strcasecmp((string)$columns[0]['name'], $column) === 0
                    ) {
                        return true;
                    }
                }
                return false;
            }

            if ($driver === Driver::Mysql) {
                # Non_unique=0 即唯一索引；Seq_in_index=1 排除掉「这一列只是复合索引的第二段」
                $rows = $db->fetchAll($db->query(
                    "SHOW INDEX FROM `{$table}` WHERE Non_unique = 0 AND Seq_in_index = 1",
                    Db::READ
                ));
                $names = [];
                foreach ($rows as $row) {
                    if (isset($row['Column_name'], $row['Key_name'])
                        && strcasecmp((string)$row['Column_name'], $column) === 0
                    ) {
                        $names[(string)$row['Key_name']] = true;
                    }
                }
                if (empty($names)) {
                    return false;
                }
                # 再确认这些索引确实只有一列
                $all = $db->fetchAll($db->query("SHOW INDEX FROM `{$table}`", Db::READ));
                $width = [];
                foreach ($all as $row) {
                    $key = (string)($row['Key_name'] ?? '');
                    $width[$key] = ($width[$key] ?? 0) + 1;
                }
                foreach (array_keys($names) as $name) {
                    if (($width[$name] ?? 0) === 1) {
                        return true;
                    }
                }
                return false;
            }

            /*
             * PostgreSQL：indisunique 是唯一性，indnatts = 1 限定单列，
             * indkey[0] 指向被索引的那一列。索引名不参与判断。
             */
            $sql = "SELECT i.relname
                      FROM pg_index x
                      JOIN pg_class i ON i.oid = x.indexrelid
                      JOIN pg_class t ON t.oid = x.indrelid
                      JOIN pg_namespace n ON n.oid = t.relnamespace
                      JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.indkey[0]
                     WHERE t.relname = '{$table}'
                       AND n.nspname = ANY (current_schemas(false))
                       AND x.indisunique
                       AND x.indnatts = 1
                       AND a.attname = '{$column}'";

            return !empty($db->fetchRow($db->query($sql, Db::READ)));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 从异常里取出 SQLSTATE
     *
     * 实测（PHP 8.4 + Typecho 的 Pdo 适配器 + PostgreSQL 16）：适配器把
     * PDOException 的 code 原样传进 Typecho\Exception，而后者是直接给属性赋值、
     * 不走 parent::__construct，所以含字母的 SQLSTATE（42P01、22P02）也完好无损地
     * 以**字符串**形式留在 getCode() 里。但别只依赖它 —— 换个适配器或换个 PHP 版本
     * 就未必了，消息里的 SQLSTATE[xxxxx] 前缀是 PDO 一定会写的，作为兜底。
     *
     * @param \Throwable $e
     * @return string 五位 SQLSTATE，取不到时返回空串
     */
    public static function sqlState(\Throwable $e): string
    {
        $code = (string)$e->getCode();
        if (preg_match('/^[0-9A-Za-z]{5}$/', $code) === 1) {
            return strtoupper($code);
        }

        if (preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $e->getMessage(), $m) === 1) {
            return strtoupper($m[1]);
        }

        return '';
    }

    /**
     * 写入失败是数据的错还是环境的错
     *
     * 这个判断决定一条写不进去的消息是转死信还是留着重试，判错的代价严重不对称
     * （见 WriteErrorKind 的说明），所以只有明确属于「数据错」的 SQLSTATE 类别
     * 才返回 Data，其余一律往「留着」的方向倒。
     *
     * @param \Throwable $e
     * @return WriteErrorKind
     */
    public static function classifyWriteError(\Throwable $e): WriteErrorKind
    {
        $state = self::sqlState($e);
        if ($state === '') {
            return WriteErrorKind::Unknown;
        }

        $class = substr($state, 0, 2);

        /*
         * 22 数据异常（字段超长 22001、类型不符 22P02、除零 22012 …）
         * 23 完整性约束冲突（唯一键 23505、非空 23502、外键 23503 …）
         * 这两类换多少次时间重试都是同样的结果，确实是这一行本身写不进去。
         */
        if ($class === '22' || $class === '23') {
            return WriteErrorKind::Data;
        }

        /*
         * 明确属于环境的：
         * 08 连接异常          25006 只读事务（备库 / default_transaction_read_only）
         * 40 事务回滚（死锁、序列化失败，重试就好）
         * 42 语法或访问规则 —— 表不存在 42P01、列不存在 42703、权限不足 42501。
         *    这一类不是「这一行」的错，而是整套结构或权限出了问题，对每一行都一样。
         *    归成 Data 的话，第一次结构没升级好就会把整个队列倒进死信。
         * 53 资源不足（磁盘满 53100、内存不足 53200）
         * 54 超出系统限制      55 对象状态不对
         * 57 管理员介入（57P01 关库、57014 语句被取消）
         * 58 系统层错误（IO 错误）
         * XX PostgreSQL 内部错误（数据损坏）
         * HY MySQL 的大杂烩，只读 1290、连接断开 2006/2013、磁盘满 1021 都在这儿
         */
        $environment = ['08', '25', '40', '42', '53', '54', '55', '57', '58', 'XX', 'HY'];
        if (in_array($class, $environment, true)) {
            return WriteErrorKind::Environment;
        }

        return WriteErrorKind::Unknown;
    }
}
