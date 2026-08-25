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
        } catch (\Exception $e) {
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
}
