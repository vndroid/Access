<?php

namespace TypechoPlugin\Access;

use Typecho\Db;

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
     */
    public const VERSION = '3.2.0';

    /**
     * 升级步骤：目标版本 => 处理方法
     *
     * 存量库的版本号低于某一项时就执行它，按数组顺序依次进行。
     */
    private const STEPS = [
        '3.2.0' => 'toV320',
    ];

    /**
     * 版本号存在独立的 options 行里
     *
     * 不能塞进 plugin:Access 的配置：Typecho 渲染插件设置页时会遍历已保存的每个键
     * 去找同名表单控件，没有声明成表单项的键会触发 Undefined array key。
     * 这和 Migrate 的迁移标记是同一个坑。
     */
    private const OPTION = 'access_schema_version';

    /** 最多记住几个统计库的版本，避免反复换库时这一行无限变长 */
    private const MAX_TRACKED = 8;

    /**
     * 确保目标库的表结构是当前版本，需要时执行升级
     *
     * @param Db $target 统计数据所在的库
     * @param string $fingerprint 统计库指纹（Migrate::fingerprint() 的结果）
     * @param bool $justCreated 本次是否刚建过表（新建的表天然就是最新结构）
     * @param Db|null $main Typecho 主库（版本号记在那里），null 表示自行获取
     * @return array{from:?string,to:string,applied:string[],error:?string}
     */
    public static function ensure(Db $target, string $fingerprint, bool $justCreated, ?Db $main = null): array
    {
        $result = ['from' => null, 'to' => self::VERSION, 'applied' => [], 'error' => null];

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

        if ($stored === self::VERSION) {
            return $result;
        }

        $table = $target->getPrefix() . 'access';
        $driver = Database::driver($target);

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
        }

        /*
         * 建索引单独 try：MySQL 没有 CREATE INDEX IF NOT EXISTS，
         * 而「列已经加上、索引也建过」是重复启用时的正常状态，不该当成失败。
         */
        try {
            $index = $driver === Driver::Pgsql ? $table . '_event_id' : '`' . $table . '_event_id`';
            $column = $driver === Driver::Pgsql ? '"event_id"' : '`event_id`';
            $target->query("CREATE UNIQUE INDEX {$index} ON {$quoted} ({$column})", Db::WRITE);
        } catch (\Throwable $e) {
        }
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
