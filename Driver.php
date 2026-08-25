<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 某个数据库连接实际使用的引擎
 *
 * 与 DbType 的区别：DbType 是用户在设置里选的「统计数据放哪」，
 * Driver 是某个 Typecho\Db 实例背后真正跑的引擎（主库也可能是 SQLite）。
 */
enum Driver: string
{
    case Mysql = 'mysql';

    case Sqlite = 'sqlite';

    case Pgsql = 'pgsql';

    /**
     * 从 Typecho 的适配器名判定
     */
    public static function fromAdapterName(string $adapterName): self
    {
        if (str_contains($adapterName, 'Pgsql')) {
            return self::Pgsql;
        }
        if (str_contains($adapterName, 'SQLite')) {
            return self::Sqlite;
        }
        return self::Mysql;
    }

    /**
     * 对应的建表脚本文件名
     */
    public function schemaFile(): string
    {
        return match ($this) {
            self::Mysql  => 'MySQL.sql',
            self::Sqlite => 'SQLite.sql',
            self::Pgsql  => 'PostgreSQL.sql',
        };
    }

    /**
     * 按引擎给标识符加引号
     *
     * PostgreSQL 不支持反引号；且未加引号的标识符会被折叠为小写，
     * 与 Typecho 查询构造器拼表名的方式一致，所以这里对 PG 直接不加引号。
     */
    public function quoteTable(string $table): string
    {
        return match ($this) {
            self::Pgsql => $table,
            self::Mysql, self::Sqlite => '`' . $table . '`',
        };
    }
}
