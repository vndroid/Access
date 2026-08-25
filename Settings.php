<?php

namespace TypechoPlugin\Access;

use Typecho\Config;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件配置的文件化：启用时从 config/current.yaml 载入，禁用时写回同一个文件
 *
 * 用途是让插件配置可以随代码一起部署：容器重建、站点迁移之后，
 * 把 config/current.yaml 放回去，启用插件即可恢复全部设置，
 * 不必再到后台一项项填。
 *
 * 语义按「文件即事实」处理：文件里写了的项按文件走，没写的项用默认值，
 * 也就是说加载一次相当于把插件配置整体替换成文件描述的样子。
 * 这与「禁用时导出、启用时导入」的往返用法是一致的。
 *
 * YAML 只支持够用的一个子集（见 parse()），不依赖任何扩展；
 * 装了 PHP 的 yaml 扩展时优先用它解析，两者对本文件格式的结果一致。
 */
final class Settings
{
    /**
     * 全部配置项及其默认值
     *
     * 必须与 Plugin::config() 里各表单控件的名字和默认值保持一致：
     * 这里是「文件里没写的项用什么」的唯一依据。
     */
    public const DEFAULTS = [
        'pageSize' => '20',
        'isDrop' => '0',
        'writeType' => '1',
        'isPaid' => '0',
        'isToken' => '',
        'socks5Host' => '',
        'socks5Auth' => '',
        'redisCache' => '0',
        'redisHost' => '127.0.0.1',
        'redisPort' => '6379',
        'redisAuth' => '',
        'writeQueue' => '1',
        'queueFlushSize' => '500',
        'queueFlushInterval' => '60',
        'dbType' => 'follow',
        'dbHost' => '127.0.0.1',
        'dbPort' => '',
        'dbUser' => '',
        'dbPass' => '',
        'dbName' => '',
        'dbPrefix' => 'typecho_',
        'dbCharset' => '',
    ];

    /** 顶层的 YAML 键名 */
    public const ROOT_KEY = 'access';

    /**
     * 配置文件路径
     *
     * @return string
     */
    public static function file(): string
    {
        return __DIR__ . '/config/current.yaml';
    }

    /**
     * 从配置文件读取设置
     *
     * @param string|null $path 默认为 config/current.yaml
     * @return array|null 读到的设置（已补全默认值）；文件不存在时返回 null
     * @throws \RuntimeException 文件存在但读不了或解析不出内容
     */
    public static function load(?string $path = null): ?array
    {
        $path = $path ?? self::file();

        if (!is_file($path)) {
            return null;
        }
        if (!is_readable($path)) {
            throw new \RuntimeException(_t('配置文件 %s 不可读，请检查文件权限', $path));
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(_t('配置文件 %s 读取失败', $path));
        }

        $parsed = self::parse($raw);
        if ($parsed === null) {
            throw new \RuntimeException(_t('配置文件 %s 解析失败，请检查 YAML 格式', $path));
        }

        # 只认识 DEFAULTS 里的键，其余忽略；没写的项用默认值
        $settings = self::DEFAULTS;
        $known = 0;
        foreach ($parsed as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $settings[$key] = $value;
                $known++;
            }
        }

        if ($known === 0) {
            throw new \RuntimeException(_t(
                '配置文件 %s 里没有任何可识别的配置项，请确认顶层为 %s:',
                $path,
                self::ROOT_KEY
            ));
        }

        return $settings;
    }

    /**
     * 把当前设置写入配置文件
     *
     * 失败时只返回 false，不抛异常：禁用插件不该因为写不了文件而失败。
     *
     * @param array|Config $settings
     * @param string|null $path
     * @return bool
     */
    public static function save(array|Config $settings, ?string $path = null): bool
    {
        $path = $path ?? self::file();
        $dir = dirname($path);

        try {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return false;
            }

            self::protect($dir);

            $body = self::emit(self::normalize($settings));

            # 先写临时文件再改名，避免写到一半被读到半截内容
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
                return false;
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
            @chmod($path, 0600);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 把插件配置对象或数组归一化成「键 => 字符串」
     *
     * @param array|Config $source
     * @return array
     */
    public static function normalize(array|Config $source): array
    {
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = null;
            if (is_array($source)) {
                $value = $source[$key] ?? null;
            } elseif ($source instanceof Config) {
                $value = $source->$key ?? null;
            }
            $result[$key] = $value === null ? $default : self::text($value);
        }
        return $result;
    }

    /**
     * 解析 YAML
     *
     * 支持的子集（足够表达本插件的配置，不依赖任何扩展）：
     *   - 顶层 `access:` 段，段内每行一个 `键: 值`（也接受没有顶层段的平铺写法）
     *   - 值可以是裸标量、单引号或双引号字符串、空
     *   - `#` 起的注释、空行、文档分隔符 `---` / `...` 会被跳过
     *   - true/false/yes/no/on/off 归一为 '1' / '0'，null / ~ 归一为空串
     * 不支持列表、多行标量、锚点等，本插件的配置也用不到。
     *
     * @param string $raw
     * @return array|null 解析不出任何键值对时返回 null
     */
    public static function parse(string $raw): ?array
    {
        # 装了 yaml 扩展就用它，对格式更宽容；结果再走一遍同样的归一化
        if (function_exists('yaml_parse')) {
            $doc = @yaml_parse($raw);
            if (is_array($doc)) {
                $section = $doc[self::ROOT_KEY] ?? null;
                $flat = is_array($section) ? $section : $doc;
                $result = [];
                foreach ($flat as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        # 扩展已经把 true / 1 / "1" 解析成了对应的 PHP 类型，
                        # 这里只做类型到字符串的折算，不再对字符串内容做二次解释
                        $result[(string)$key] = self::text($value);
                    }
                }
                if (!empty($result)) {
                    return $result;
                }
            }
            # 扩展没解析出东西时不直接判失败，继续走下面的内置解析
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $result = [];
        $section = null;      // 当前顶层段名，null 表示还没遇到段
        $seenSection = false;

        foreach (explode("\n", $raw) as $line) {
            $line = self::stripComment($line);
            if (trim($line) === '' || preg_match('/^\s*(---|\.\.\.)\s*$/', $line)) {
                continue;
            }

            if (!preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_.\-]*)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }

            [, $indent, $key, $value] = $m;
            $value = rtrim($value);

            # 顶层且没有值的行是段名
            if ($indent === '' && $value === '') {
                $section = $key;
                $seenSection = true;
                continue;
            }

            # 有顶层段时只认 access: 段里的项，避免把别的段的同名键读进来
            if ($seenSection && $section !== self::ROOT_KEY) {
                continue;
            }

            [$text, $quoted] = self::unquote($value);
            # 带引号的一律当字面量，`dbName: "off"` 就是字符串 off 而不是布尔假
            $result[$key] = $quoted ? $text : self::bare($text);
        }

        return empty($result) ? null : $result;
    }

    /**
     * 生成 YAML 文本
     *
     * @param array $settings 已归一化的设置
     * @return string
     */
    public static function emit(array $settings): string
    {
        $lines = [
            '# Access 插件配置',
            '#',
            '# 禁用插件时自动写出，启用插件时自动读入。',
            '# 文件里写了的项按文件走，没写的项使用插件默认值。',
            '#',
            '# 注意：本文件含有数据库密码、Redis 密码、接口令牌等敏感信息，',
            '# 权限已设为 0600，请勿提交到公开仓库，也不要放到可被下载的位置。',
            '#',
            '# 生成时间：' . date('Y-m-d H:i:s'),
            '',
            self::ROOT_KEY . ':',
        ];

        foreach (self::DEFAULTS as $key => $default) {
            $value = (string)($settings[$key] ?? $default);
            # 纯数字不加引号，其余一律双引号，这样读回来一定还是原样
            $literal = preg_match('/^-?\d+$/', $value) === 1 ? $value : self::quote($value);
            $lines[] = '  ' . $key . ': ' . $literal;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 在配置目录下放置阻止 Web 直接访问的文件
     *
     * Nginx 下这些文件不起作用，需要自行在站点配置里拒绝 /usr/plugins/Access/config/。
     *
     * @param string $dir
     * @return void
     */
    private static function protect(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
            );
        }

        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * 去掉行内注释（引号里的 # 不算注释）
     *
     * @param string $line
     * @return string
     */
    private static function stripComment(string $line): string
    {
        $out = '';
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote !== null) {
                $out .= $char;
                if ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $out .= $line[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $out .= $char;
                continue;
            }

            # 只有行首或前面是空白的 # 才算注释起点
            if ($char === '#' && ($out === '' || preg_match('/\s$/', $out))) {
                break;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * 去掉标量外层的引号并处理转义
     *
     * @param string $value
     * @return array{0: string, 1: bool} 值本身，以及它原本是否带引号
     */
    private static function unquote(string $value): array
    {
        $value = trim($value);
        $length = strlen($value);
        if ($length < 2) {
            return [$value, false];
        }

        $first = $value[0];
        $last = $value[$length - 1];

        if ($first === "'" && $last === "'") {
            # 单引号里只有 '' 表示一个单引号
            return [str_replace("''", "'", substr($value, 1, -1)), true];
        }

        if ($first === '"' && $last === '"') {
            $inner = substr($value, 1, -1);
            return [strtr($inner, [
                '\\n' => "\n",
                '\\t' => "\t",
                '\\"' => '"',
                '\\\\' => '\\',
            ]), true];
        }

        return [$value, false];
    }

    /**
     * 生成双引号字符串
     *
     * @param string $value
     * @return string
     */
    private static function quote(string $value): string
    {
        return '"' . strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\t" => '\\t',
        ]) . '"';
    }

    /**
     * 把任意标量折算成插件配置使用的字符串
     *
     * 插件的配置值一律以字符串保存（表单控件本来也只产生字符串）。
     *
     * @param mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return trim((string)$value);
    }

    /**
     * 解释不带引号的裸标量
     *
     * 于是 `redisCache: true`、`redisCache: 1`、`redisCache: \"1\"` 三种写法等价。
     * 带引号的值不会走到这里，`dbName: \"off\"` 仍然是字符串 off。
     *
     * @param string $text
     * @return string
     */
    private static function bare(string $text): string
    {
        return match (strtolower($text)) {
            'true', 'yes', 'on' => '1',
            'false', 'no', 'off' => '0',
            'null', '~' => '',
            default => $text,
        };
    }
}
