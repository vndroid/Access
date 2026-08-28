<?php

namespace TypechoPlugin\Access;

use Redis;
use RuntimeException;
use Typecho\Config;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 外部服务的可用性探测与熔断
 *
 * 解决的问题：Redis 配了但不可达时，`Redis::connect()` 会一直等到超时。
 * 端口没人监听会立刻被拒绝（几毫秒，无所谓），但防火墙丢包、DNS 黑洞、
 * 容器网络不通这类「不响应」的情况会等满整个超时，而 Core 是在
 * Widget\Archive::beforeRender 里构造的，等于**每个前台请求都白等一次**。
 *
 * 光把失败记在对象里没用：PHP 是 shared-nothing 的，每个请求从干净状态开始，
 * 下一个访客根本不知道上一个刚白等过。要打破这个循环，状态必须活在请求之外。
 * Redis 自己出局（它就是挂掉的那个），数据库不合适（正是想让这些请求变便宜），
 * 于是：
 *
 *   - 装了 APCu 就用 APCu：共享内存，微秒级，自带 TTL，正好对应熔断窗口；
 *   - 否则退回临时目录里的标记文件，用 mtime 当时间戳。
 *
 * 两者都不可用时（没 APCu 且临时目录不可写）就没有熔断，只剩下缩短后的
 * 连接超时兜底 —— 功能照常，只是每次请求多花 CONNECT_TIMEOUT 秒。
 *
 * APCu 是非强制依赖，插件不会因为缺它而拒绝启用。
 */
final class Health
{
    /** Redis 不可达的熔断标记名 */
    public const REDIS = 'redis-down';

    /** 熔断窗口：这段时间内不再尝试连接，直接降级 */
    public const WINDOW = 30;

    /**
     * Redis 连接超时（秒）
     *
     * 原来是 3 秒，对「不响应」的地址意味着每个前台请求白等 3 秒。
     * 压到 0.5 秒后单次代价可以接受，熔断再把重复的代价也抹掉。
     */
    public const CONNECT_TIMEOUT = 0.5;

    /**
     * Redis 读写超时（秒）
     *
     * 连接超时管的是「连不上」，这个管的是「连上了却不回话」—— 后者更阴险：
     * TCP 握手成功，PING / LRANGE / LTRIM / SCAN 就地卡住，
     * 而 phpredis 默认没有读超时，于是一直等到 PHP 自己的执行时限为止。
     * 防火墙半开连接、Redis 被大 key 阻塞、容器网络单向不通，都会走到这一步。
     */
    public const READ_TIMEOUT = 3.0;

    /**
     * 命令行下的超时（秒）
     *
     * cron 不在乎多等一会儿，宁可宽松些也别因为网络抖一下就整轮失败；
     * 但仍然必须有上限，否则一个卡住的 Redis 能让 cron 任务永远挂着。
     */
    public const CLI_CONNECT_TIMEOUT = 3.0;
    public const CLI_READ_TIMEOUT = 5.0;

    /** 标记文件所在目录，null 表示还没解析过 */
    private static ?string $dir = null;

    /**
     * 熔断是否生效中
     *
     * @param string $name
     * @return bool
     */
    public static function tripped(string $name): bool
    {
        if (self::apcu()) {
            return (bool)apcu_fetch(self::cacheKey($name));
        }

        $file = self::markerFile($name);
        if ($file === null || !is_file($file)) {
            return false;
        }

        $at = @filemtime($file);
        if ($at === false) {
            return false;
        }

        # 过期的标记顺手清掉，省得越积越多
        if (time() - $at >= self::WINDOW) {
            @unlink($file);
            return false;
        }

        return true;
    }

    /**
     * 记一次失败，进入熔断
     *
     * @param string $name
     * @return void
     */
    public static function trip(string $name): void
    {
        if (self::apcu()) {
            @apcu_store(self::cacheKey($name), 1, self::WINDOW);
            return;
        }

        $file = self::markerFile($name);
        if ($file !== null) {
            @touch($file);
            @chmod($file, 0600);
        }
    }

    /**
     * 服务恢复了，立刻解除熔断
     *
     * @param string $name
     * @return void
     */
    public static function clear(string $name): void
    {
        if (self::apcu()) {
            @apcu_delete(self::cacheKey($name));
            return;
        }

        $file = self::markerFile($name);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 当前用的是哪种存储，便于在提示与测试里说明
     *
     * @return string apcu / file / none
     */
    public static function backend(): string
    {
        if (self::apcu()) {
            return 'apcu';
        }
        return self::markerFile(self::REDIS) === null ? 'none' : 'file';
    }

    /**
     * 统一建立 Redis 连接
     *
     * 全插件只有这一处 new Redis()：超时设置散在四个地方时，
     * 总会漏掉一两个 —— 事实上原来 tools/flush-queue.php 就是漏网的那个。
     *
     * @param string $host
     * @param int $port
     * @param string $auth 密码，空字符串表示不需要认证
     * @param float|null $connectTimeout 连接超时（秒），null 用 CONNECT_TIMEOUT
     * @param float|null $readTimeout 读写超时（秒），null 用 READ_TIMEOUT
     * @return Redis 已完成认证并 PING 通的连接
     * @throws RuntimeException 连不上
     * @throws \RedisException 认证或 PING 失败
     */
    public static function connect(
        string $host,
        int $port,
        string $auth = '',
        ?float $connectTimeout = null,
        ?float $readTimeout = null
    ): Redis {
        $connectTimeout = $connectTimeout ?? self::CONNECT_TIMEOUT;
        $readTimeout = $readTimeout ?? self::READ_TIMEOUT;

        $redis = new Redis();

        /*
         * connect() 的第 6 个参数就是读超时。第 5 个是重连间隔，这里给 0：
         * 本类已经有熔断了，再让驱动在请求里偷偷重试只会把等待成倍放大。
         */
        # phpredis 连接失败时既可能返回 false，也可能直接抛 RedisException，
        # 这里统一成 RuntimeException，并且把「连的是谁」补进消息里 ——
        # 光一句 "Connection refused" 在后台提示里没法定位
        try {
            $ok = @$redis->connect($host, $port, $connectTimeout, null, 0, $readTimeout);
        } catch (\Throwable $e) {
            throw new RuntimeException(_t('无法连接 Redis %s:%d（%s）', $host, $port, $e->getMessage()));
        }
        if (!$ok) {
            throw new RuntimeException(_t('无法连接 Redis %s:%d', $host, $port));
        }

        # 连上之后再设一次：这个值对后续的惰性重连同样生效
        $redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);

        if ($auth !== '') {
            $redis->auth($auth);
        }
        $redis->ping();

        return $redis;
    }

    /**
     * 探测 Redis 是否可用
     *
     * 成功时解除熔断，失败时进入熔断 —— 于是「启用插件」这一次探测
     * 就能让接下来的前台请求直接走降级路径，不必每个访客各撞一次超时。
     *
     * @param array|Config|null $config 插件配置
     * @return string|null null 表示可用；未配置 Redis 时也返回 null（没什么可探测的）
     */
    public static function probeRedis(array|Config|null $config = null): ?string
    {
        $target = self::redisTarget($config);
        if ($target === null) {
            return null;
        }

        if (!extension_loaded('redis')) {
            return _t('PHP 未安装 redis 扩展');
        }

        try {
            $redis = self::connect($target['host'], $target['port'], $target['auth']);
            $redis->close();
        } catch (\Throwable $e) {
            self::trip(self::REDIS);
            return $e->getMessage();
        }

        self::clear(self::REDIS);
        return null;
    }

    /**
     * 从配置里取出 Redis 连接目标
     *
     * @param array|Config|null $config
     * @return array{host: string, port: int, auth: string}|null 未启用缓存加速时返回 null
     */
    public static function redisTarget(array|Config|null $config = null): ?array
    {
        $pick = static function (string $key) use ($config): string {
            if (is_array($config) || $config instanceof \ArrayAccess) {
                return (string)($config[$key] ?? '');
            }
            if (is_object($config)) {
                return (string)($config->$key ?? '');
            }
            return '';
        };

        if ($config === null || $pick('redisCache') !== '1') {
            return null;
        }

        return [
            'host' => $pick('redisHost') ?: '127.0.0.1',
            'port' => (int)($pick('redisPort') ?: 6379),
            'auth' => $pick('redisAuth'),
        ];
    }

    /**
     * APCu 是否可用
     *
     * 装了扩展还不够，还得真的启用了：CLI 下 apcu.enable_cli 默认为 0，
     * 此时函数存在但存不进东西，必须落回标记文件，否则命令行脚本
     * 会以为自己写了熔断标记而实际什么都没发生。
     *
     * @return bool
     */
    private static function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * APCu 的键名
     *
     * 带上插件目录的指纹，同一台机器上的多个站点互不干扰。
     *
     * @param string $name
     * @return string
     */
    private static function cacheKey(string $name): string
    {
        return Cache::key($name);
    }

    /**
     * 标记文件路径，目录不可用时返回 null
     *
     * @param string $name
     * @return string|null
     */
    private static function markerFile(string $name): ?string
    {
        $dir = self::dir();
        return $dir === null ? null : $dir . '/' . $name;
    }

    /**
     * 标记文件所在目录
     *
     * 放在临时目录下自己的子目录里并限制为 0700：临时目录通常全局可写，
     * 直接用可预测的文件名会给同机器上的其他用户留下预先建符号链接的机会。
     *
     * @return string|null 不可用时返回 null，此时相当于没有熔断
     */
    private static function dir(): ?string
    {
        if (self::$dir !== null) {
            return self::$dir === '' ? null : self::$dir;
        }

        self::$dir = '';
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/typecho-access-' . self::fingerprint();

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
            return null;
        }
        # 目录若不属于当前进程，说明被别人抢先建了，不碰
        if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
            return null;
        }

        self::$dir = $dir;
        return $dir;
    }

    /**
     * 站点指纹，用插件目录路径生成
     *
     * 同一台机器上的多个 Typecho 各有各的插件目录，这个值就能把它们分开。
     * Redis 键名也用它（见 Cache::prefix()）：路径是少数几个「在 Web 请求、
     * 命令行脚本、cron 里都能一模一样地算出来」的东西 —— 换成站点地址或
     * 数据库配置的话，命令行下取不到 Options，算出来的指纹会和前台对不上，
     * 那就成了两条各刷各的队列。
     *
     * @return string 12 位十六进制
     */
    public static function fingerprint(): string
    {
        return substr(md5(__DIR__), 0, 12);
    }
}
