<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 统计数据存放位置的类型（对应插件设置里的「统计数据库」）
 *
 * 取值会原样存进插件配置，所以枚举值必须与历史配置里的字符串保持一致。
 */
enum DbType: string
{
    /** 跟随 Typecho 主库 */
    case Follow = 'follow';

    case Mysql = 'mysql';

    case Pgsql = 'pgsql';

    /**
     * 从配置里的字符串解析，无法识别时回落到「跟随主库」
     */
    public static function parse(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string)$value))) ?? self::Follow;
    }

    /**
     * 是否是独立数据库
     */
    public function isExternal(): bool
    {
        return $this !== self::Follow;
    }

    /**
     * 对应的 Typecho 数据库适配器名
     */
    public function adapter(): string
    {
        return match ($this) {
            self::Mysql => 'Pdo_Mysql',
            self::Pgsql => 'Pdo_Pgsql',
            self::Follow => '',
        };
    }

    /**
     * 默认端口
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::Mysql => 3306,
            self::Pgsql => 5432,
            self::Follow => 0,
        };
    }

    /**
     * 默认字符集
     */
    public function defaultCharset(): string
    {
        return match ($this) {
            self::Pgsql => 'utf8',
            self::Mysql, self::Follow => 'utf8mb4',
        };
    }

    /**
     * 连接所需的 PDO 扩展名
     */
    public function extension(): string
    {
        return match ($this) {
            self::Mysql => 'pdo_mysql',
            self::Pgsql => 'pdo_pgsql',
            self::Follow => '',
        };
    }

    /**
     * 配置面板下拉框用的选项
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Follow->value => '跟随 Typecho（默认）',
            self::Mysql->value  => 'MySQL / MariaDB',
            self::Pgsql->value  => 'PostgreSQL',
        ];
    }
}
