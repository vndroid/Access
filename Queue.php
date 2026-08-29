<?php

namespace TypechoPlugin\Access;

use Redis;
use Typecho\Config;
use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 访问日志写入队列
 *
 * 把每次访问先塞进 Redis 列表，攒够一批再用一条多值 INSERT 落库，
 * 目的有两个：
 * - 削掉每次访问的建连接开销（独立数据库模式下这一项占单次写入的六成）
 * - 把数据库连接数从「每次访问一条」降到「每批一条」，避免突发流量打满 max_connections
 *
 * 消息在 Redis 里经过三个列表：主队列 -> processing（正在写库）-> 死信（写不进去的）。
 * 中间那一步不是多余的：直接「读了再按位置裁掉」的话，生产者的裁剪一旦插进来，
 * 消费者就会裁掉别人刚写进来的消息。
 *
 * 刷库由请求顺带触发：达到条数或时间阈值时，本次请求抢到锁的那一个负责刷，
 * 并且推迟到响应发出之后执行，访客感知不到。
 * 另外控制台加载数据时会同步刷一次，命令行脚本可挂 cron 兜底。
 *
 * 没有 Redis 时整套机制不启用，写入行为与之前完全一致。
 */
final class Queue
{
    /**
     * 队列相关键的名字（不含前缀）
     *
     * 完整键名由 Cache::key() 补上带站点指纹的前缀，所以这里全部是方法而不是常量：
     * 常量在编译期定型，写死的话多个站点共用一个 Redis 就会共用同一条队列。
     */
    private const NAME = 'queue';
    private const NAME_PROCESSING = 'queue:processing';
    private const NAME_DEAD = 'queue:dead';
    private const NAME_LOCK = 'queue:lock';
    private const NAME_LAST_FLUSH = 'queue:last_flush';

    /**
     * 队首这一批「从什么时候开始就写不进去」的时间戳
     *
     * 只有把整批留下重试时才会写它，批次一旦确认掉就删。
     * 用途见 STUCK_SECONDS。
     */
    private const NAME_STUCK_SINCE = 'queue:stuck_since';

    /**
     * 加站点指纹之前用过的固定键名（旧前缀下的第一代）
     *
     * 升级之后队列会换到新键名上，这几个键里可能还压着没落库的访问日志。
     * adoptLegacy() 负责把它们接管过来，不接管就等于丢数据。
     *
     * 注意旧前缀下还有第二代（typecho_access:{指纹}:queue*，加了指纹但没换前缀），
     * 那一代按 v3.2.3 的决定**不做接管**，只在卸载清理时被保护和识别，
     * 见 isLegacyDataKey() 与 adoptLegacy() 的说明。
     */
    private const LEGACY_PREFIX = Cache::LEGACY_BASE . 'queue';

    /** 待写入队列（Redis List） */
    public static function key(): string
    {
        return Cache::key(self::NAME);
    }

    /**
     * 正在写库的一批（Redis List）
     *
     * 消费者不再「读了之后按位置裁掉」，而是用一段 Lua 把消息原子地从主队列搬到这里，
     * 写库成功再从这里清掉。中途崩掉的话数据留在这里，下一次刷库会先把它捡回来。
     */
    public static function processingKey(): string
    {
        return Cache::key(self::NAME_PROCESSING);
    }

    /**
     * 死信队列（Redis List）
     *
     * 解析不了、或者数据库明确拒绝的消息落到这里，而不是直接丢掉。
     * 队列的确认是整批 LTRIM，做不到「只确认成功的那几条」（Redis List 不支持按位置挑着删），
     * 所以退而求其次：裁掉之前先把失败的原样留一份证据，可以人工排查或改完再回放。
     */
    public static function deadKey(): string
    {
        return Cache::key(self::NAME_DEAD);
    }

    /** 刷库互斥锁 */
    private static function lockKey(): string
    {
        return Cache::key(self::NAME_LOCK);
    }

    /** 上次刷库时间 */
    private static function lastFlushKey(): string
    {
        return Cache::key(self::NAME_LAST_FLUSH);
    }

    /** 队首批次卡住的起始时间 */
    private static function stuckSinceKey(): string
    {
        return Cache::key(self::NAME_STUCK_SINCE);
    }

    /**
     * 记下「队首这批从现在开始卡住了」，已经记过就不覆盖
     *
     * @param Redis $redis
     * @return void
     */
    private static function markStuck(Redis $redis): void
    {
        try {
            # NX：第一次卡住的时间才是起点，后面每轮重试都覆盖的话永远到不了上限
            $redis->set(self::stuckSinceKey(), time(), ['nx']);
        } catch (\Throwable $e) {
            // 记不下来只影响「卡多久放行」这一个判断，不该挡住刷库本身
        }
    }

    /**
     * 队首这批已经卡了多少秒，没卡住时返回 null
     *
     * @param Redis $redis
     * @return int|null
     */
    private static function stuckFor(Redis $redis): ?int
    {
        try {
            $since = $redis->get(self::stuckSinceKey());
            if ($since === false || !is_numeric($since)) {
                return null;
            }
            return max(0, time() - (int)$since);
        } catch (\Throwable $e) {
            /*
             * 读不到就当没卡住。宁可晚一点放行也不能早放行 ——
             * 早放行等于把还能救的数据提前扔进死信。
             */
            return null;
        }
    }

    /**
     * 批次确认掉了，卡住计时清零
     *
     * @param Redis $redis
     * @return void
     */
    private static function clearStuck(Redis $redis): void
    {
        try {
            $redis->del(self::stuckSinceKey());
        } catch (\Throwable $e) {
        }
    }

    /**
     * 锁的存活时间（秒），防止刷库进程挂掉后死锁
     *
     * 刷库期间会周期性续租，所以这个值不需要覆盖整次刷库的耗时，
     * 只要能覆盖「两次续租之间」即可；取值越小，持锁进程被杀之后锁自然释放得越快。
     */
    private const LOCK_TTL = 30;

    /** 续租间隔（秒）：刷库过程中每隔这么久把锁的存活时间顶回 LOCK_TTL */
    private const LOCK_RENEW_INTERVAL = 10;

    /** 队列长度硬上限，超出后丢弃最旧的记录，避免数据库长时间不可用时撑爆 Redis */
    public const MAX_LENGTH = 200000;

    /** 死信队列长度上限，超出后同样丢弃最旧的，避免脏数据把 Redis 撑爆 */
    public const DEAD_MAX_LENGTH = 10000;

    /**
     * 队首批次卡住多久之后，把写不进去的行强行转进死信（秒）
     *
     * 写入失败按 WriteErrorKind 分类之后，除了明确的数据错，其余一律留着重试 ——
     * 这是对的，但也意味着一条谁也认不出的错误可以永远占着队首，后面的消息
     * 全部堵死，直到队列涨到 MAX_LENGTH 开始丢最旧的。那还是丢数据，只是换了个位置。
     *
     * 所以给「留着重试」加一个上界。取一整天是因为要盖过真实故障的修复时间：
     * 磁盘满、权限配错、备库没切回来，这些通常几小时内有人处理；
     * 取短了（比如几分钟）就等于把一次运维故障变成一次数据丢失，那正是要防的事。
     */
    public const STUCK_SECONDS = 86400;

    /** 单次刷库最多处理多少条，防止一次请求耗时过长 */
    public const FLUSH_LIMIT = 5000;

    /**
     * 单次刷库的墙钟上限（秒）
     *
     * 条数上限挡不住「每条都很慢」的情况：数据库变慢、批量 INSERT 反复退化成逐行时，
     * 5000 条也可能跑上好几分钟。锁虽然会续租，但一次刷库无限期占着队列本身就不健康。
     * 这里再加一道时间闸门，超时就收工，剩下的留给下一次。
     */
    public const FLUSH_DEADLINE = 20;

    /**
     * 各列允许的最大字符数，与 sql/*.sql 里的 varchar 长度一一对应
     *
     * 不截断的话，一条超长 UA 或 URL 会让整批 INSERT 失败，
     * 然后退化成 1000 次逐行 INSERT —— 一条脏数据放大成一千次数据库往返。
     * 而这个入口是匿名可达的。
     *
     * ip 的 39 单独说一句：这一列存的**不是地址文本**，而是地址的十进制整数表示
     * （见 Core::ip62long()）。上限来自 2^128-1 恰好是 39 位十进制数字，
     * 和「完整展开的 IPv6 文本长 39 字符」只是碰巧同为 39，别按文本长度去推。
     * 这里截断的后果也和别的列不同：其余列截掉尾巴只是信息变短，
     * 而十进制数截掉末位等于除以 10 —— 存进去的是另一个看起来合法的地址。
     */
    private const LIMITS = [
        'ua' => 255,
        'browser_id' => 32, 'browser_version' => 32,
        'os_id' => 32, 'os_version' => 32,
        'url' => 255, 'path' => 255, 'query_string' => 255,
        'ip' => 39,
        'entrypoint' => 255, 'entrypoint_domain' => 100,
        'referer' => 255, 'referer_domain' => 100,
        'robot_id' => 32, 'robot_version' => 32,
        'event_id' => 32,
    ];

    /** 这几列是 int unsigned，超出范围的值一律记为 null */
    private const ID_COLUMNS = ['content_id', 'meta_id'];

    /** int unsigned 的上界 */
    private const UNSIGNED_INT_MAX = 4294967295;

    /**
     * 单条消息的字节上限
     *
     * normalize() 按列宽截断之后正常数据远到不了这个量级，
     * 这道防线只为拦住结构本身就异常的输入。
     */
    public const MAX_PAYLOAD_BYTES = 8192;

    /** 入队字段，顺序固定；与 Migrate::COLUMNS 相同但不含自增主键 */
    public const COLUMNS = [
        'ua', 'browser_id', 'browser_version', 'os_id', 'os_version',
        'url', 'path', 'query_string', 'ip', 'entrypoint', 'entrypoint_domain',
        'referer', 'referer_domain', 'time', 'content_id', 'meta_id',
        'robot', 'robot_id', 'robot_version',
        'event_id',
    ];

    /**
     * 生成一条访问日志的唯一标识
     *
     * 队列做不到「恰好一次」：写库成功之后、从 processing 里确认之前进程被杀，
     * 下一轮会把同一批再写一遍。有了这个标识，重复的那次会被唯一索引挡下来，
     * 于是「至少一次」的投递变成了「恰好一次」的结果。
     *
     * 前 16 位十六进制是毫秒时间戳左移后补 16 位随机数，后 16 位纯随机：
     * 时间在前使得标识按毫秒聚簇，唯一索引的写入集中在 B+ 树右端附近，
     * 不会像纯随机标识那样每次插入都落到全表的随机页上。同一毫秒内仍是随机顺序。
     *
     * @return string 32 个十六进制字符
     */
    public static function newEventId(): string
    {
        $ms = (int)(microtime(true) * 1000);
        return bin2hex(pack('J', ($ms << 16) | random_int(0, 0xFFFF)))
            . bin2hex(random_bytes(8));
    }

    /**
     * 是否启用写入队列
     * Redis 不可用时返回 false，调用方退回直写
     *
     * @param Redis|null $redis
     * @param Config|array|null $config 插件配置
     * @return bool
     */
    public static function isEnabled(?Redis $redis, Config|array|null $config): bool
    {
        if ($redis === null) {
            return false;
        }
        // 未显式关闭即为启用（Redis 已连上就说明用户配置过）
        return !isset($config->writeQueue) || $config->writeQueue != '0';
    }

    /**
     * 入队
     *
     * @param Redis $redis
     * @param array $row
     * @return bool 入队成功返回 true，失败由调用方退回直写
     */
    public static function push(Redis $redis, array $row): bool
    {
        try {
            $payload = json_encode(self::normalize($row), JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return false;
            }

            /*
             * 截断之后还能超限，说明这条记录的结构本身就不对（例如字段被塞成了数组）。
             * 不入队，调用方会退回直写；直写同样会失败，但至少不会把异常数据
             * 塞进队列去拖累整批 INSERT。
             */
            if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
                return false;
            }

            $length = $redis->rPush(self::key(), $payload);
            if ($length === false) {
                return false;
            }

            // 超出硬上限时丢掉最旧的部分，保留最新的 MAX_LENGTH 条
            if ($length > self::MAX_LENGTH) {
                $redis->lTrim(self::key(), -self::MAX_LENGTH, -1);
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 队列长度，Redis 出错时按 0 处理
     *
     * 只在「拿不到长度就当没积压」无所谓的地方用（例如后台面板上的一个数字）。
     * 需要区分「队列为空」和「Redis 故障」的调用方一律用 tryLength()。
     *
     * @param Redis $redis
     * @return int
     */
    public static function length(Redis $redis): int
    {
        return self::tryLength($redis) ?? 0;
    }

    /**
     * 还没进数据库的消息条数，Redis 出错时返回 null
     *
     * 把故障伪装成 0 会让「队列为空」和「Redis 挂了」变成同一个返回值，
     * 于是定时任务打印「队列为空，无需刷库」然后以成功退出，故障被彻底掩盖。
     *
     * 含 processing：那批已经离开主队列但还没落库，对「还剩多少没写」这个问题
     * 它和主队列里的消息没有区别，漏算会让停用插件时误判成「已经刷干净了」。
     *
     * @param Redis $redis
     * @return int|null
     */
    public static function tryLength(Redis $redis): ?int
    {
        try {
            $queue = $redis->lLen(self::key());
            $processing = $redis->lLen(self::processingKey());
            if ($queue === false || $processing === false) {
                return null;
            }
            return (int)$queue + (int)$processing;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 正在写库（或上次没确认完）的条数
     *
     * 正常情况下要么是 0，要么是一个批次的大小；长期居高不下说明刷库一直在失败。
     *
     * @param Redis $redis
     * @return int
     */
    public static function processingLength(Redis $redis): int
    {
        try {
            return (int)$redis->lLen(self::processingKey());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 死信队列长度
     *
     * @param Redis $redis
     * @return int
     */
    public static function deadLength(Redis $redis): int
    {
        try {
            return (int)$redis->lLen(self::deadKey());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 原子地从主队列取走一批，并暂存到 processing
     *
     * 这里必须是一段 Lua，而不是 LRANGE + LTRIM 两条命令：
     * 生产者在队列超过 MAX_LENGTH 时也会 LTRIM 裁掉队首，
     * 一旦它插在消费者的「读」和「裁」之间，消费者按旧位置裁掉的
     * 就是新写进来、还没落库的消息 —— 无声丢数据。
     * Redis 执行脚本期间不处理别的命令，读和裁之间就没有缝可插了。
     *
     * 脚本内部的顺序是「先写 processing，后裁主队列」，不能反过来。
     * Lua 脚本只保证不被别的命令打断，不保证出错回滚：先裁后写的话，
     * 一旦 RPUSH 中途失败（processing 键类型不对等），消息就同时不在主队列
     * 也不在 processing —— 无声丢失。反过来最坏只是主队列里留下副本，
     * 下一轮重新取到，由 event_id 的唯一索引挡掉重复。
     *
     * @param Redis $redis
     * @param int $count 最多取多少条
     * @return array 取到的原始消息，顺序与队列一致
     * @throws \RuntimeException 脚本执行失败时抛出，绝不能当成「队列已空」
     */
    private static function claim(Redis $redis, int $count): array
    {
        $script = <<<'LUA'
local items = redis.call('LRANGE', KEYS[1], 0, ARGV[1] - 1)
if #items == 0 then
    return items
end
for i = 1, #items do
    redis.call('RPUSH', KEYS[2], items[i])
end
redis.call('LTRIM', KEYS[1], #items, -1)
return items
LUA;

        $items = $redis->eval($script, [self::key(), self::processingKey(), $count], 2);

        /*
         * eval 出错时 phpredis 返回 false。以前这里 `is_array($items) ? $items : []`
         * 把失败翻译成空数组，flush() 于是判定 stopped=empty 正常收工、退出码 0 ——
         * 一条丢数据的路径连告警都没有。失败必须显式抛出。
         */
        if ($items === false) {
            $error = $redis->getLastError();
            $redis->clearLastError();
            throw new \RuntimeException('从队列取数失败：' . ($error !== null && $error !== '' ? $error : '未知错误'));
        }

        return is_array($items) ? $items : [];
    }

    /**
     * 上一次没能确认的那批
     *
     * 消费者取走消息之后、写库成功之前挂掉，数据就停在 processing 里。
     * 每轮开工前先把它捡回来，否则新取的一批会和它混在一起，没法分别确认。
     *
     * @param Redis $redis
     * @return array
     */
    private static function leftover(Redis $redis): array
    {
        $items = $redis->lRange(self::processingKey(), 0, -1);

        /*
         * lRange 失败返回 false，**绝不能当成「processing 是空的」**。
         *
         * 当成空的话流程会转去 claim() 取新一批，而 claim 的 Lua 是 RPUSH 到
         * processing 尾部 —— 于是 processing 里前面压着上一批（还没落库），
         * 后面接着新一批（马上要落库）。确认那一步是
         * lTrim(processing, count($items), -1)，按**位置**从头部裁掉 count 个，
         * 砍掉的正是前面那批还没落库的消息，留下的反而是已经落库的。
         * 砍错批次，直接丢数据。所以这里必须抛出去，让本轮刷库停手。
         */
        if ($items === false) {
            $error = $redis->getLastError();
            $redis->clearLastError();
            throw new \RuntimeException(
                '读取 processing 失败：' . ($error !== null && $error !== '' ? $error : '未知错误')
            );
        }

        return is_array($items) ? $items : [];
    }

    /**
     * 把写不进去的消息挪进死信队列
     *
     * 必须在裁剪主队列之前调用：中途挂掉的话，宁可这批消息重复处理一次，
     * 也不能出现「主队列已裁掉、死信里又没有」的空档。
     *
     * 写不进死信队列时抛异常而不是返回条数：调用方拿到条数之后会无条件
     * 裁掉 processing，于是「没进死信、也没进数据库」的消息被无声删除 ——
     * 这正是死信队列本身要防的事。抛出去让整批留在 processing 等下次重试。
     *
     * @param Redis $redis
     * @param array $entries 每项为 ['reason' => string, 'payload' => string]
     * @return int 实际入队条数
     * @throws \RuntimeException 死信队列写入失败时抛出，调用方不得确认本批
     */
    private static function pushDead(Redis $redis, array $entries): int
    {
        if (empty($entries)) {
            return 0;
        }

        $payloads = [];
        $at = time();
        foreach ($entries as $entry) {
            /*
             * payload 是访客可控数据，可能不是合法 UTF-8，默认参数下 json_encode 会返回 false。
             * 以前这里静默跳过，可这条消息随后照样被裁掉 —— 又是一条无声丢失。
             * 先让非法字节被替换掉（内容仍可辨认），再退到 base64 保留原始字节。
             */
            $encoded = json_encode([
                'at'      => $at,
                'reason'  => $entry['reason'],
                'payload' => $entry['payload'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false) {
                $encoded = json_encode([
                    'at'          => $at,
                    'reason'      => $entry['reason'] . '+unencodable',
                    'payload_b64' => base64_encode((string)$entry['payload']),
                ]);
            }

            if ($encoded === false) {
                # 连 base64 都编码不出来说明结构本身异常，不能当作「已处理」放行
                throw new \RuntimeException('死信队列消息无法编码，本批不予确认');
            }

            $payloads[] = $encoded;
        }

        $length = $redis->rPush(self::deadKey(), ...$payloads);
        if ($length === false) {
            throw new \RuntimeException(sprintf(
                '死信队列写入失败（%d 条），本批保留在 processing 中等待重试',
                count($payloads)
            ));
        }

        if ($length > self::DEAD_MAX_LENGTH) {
            $redis->lTrim(self::deadKey(), -self::DEAD_MAX_LENGTH, -1);
        }

        return count($payloads);
    }

    /**
     * 判断是否到了该刷库的时候
     *
     * @param Redis $redis
     * @param int $size 条数阈值
     * @param int $interval 时间阈值（秒）
     * @return bool
     */
    public static function isDue(Redis $redis, int $size, int $interval): bool
    {
        try {
            $length = self::length($redis);
            if ($length <= 0) {
                return false;
            }
            if ($length >= max(1, $size)) {
                return true;
            }

            $last = (int)$redis->get(self::lastFlushKey());
            if ($last <= 0) {
                // 没有记录过，先打上时间戳，下一轮再按间隔判断
                $redis->set(self::lastFlushKey(), time());
                return false;
            }

            return (time() - $last) >= max(1, $interval);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 抢刷库锁，拿到的请求才执行刷库
     *
     * 锁值是一次性随机 token 而不是 PID：PID 会复用，多机部署时更是会重复，
     * 拿它当身份标识意味着「我」和「别人」根本分不开。
     *
     * @param Redis $redis
     * @return string|null 抢到返回本次持有的 token，没抢到返回 null
     */
    public static function acquireLock(Redis $redis): ?string
    {
        try {
            $token = bin2hex(random_bytes(16));
            $ok = $redis->set(self::lockKey(), $token, ['nx', 'ex' => self::LOCK_TTL]);
            return $ok ? $token : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 续租：确认锁还在自己手上，然后把存活时间顶回 LOCK_TTL
     *
     * 返回 false 意味着锁已经不属于自己了（过期后被别人抢走，或被误删），
     * 此时调用方必须立刻停手：继续刷下去就会和新的持有者同时读写、裁剪同一个队列。
     *
     * @param Redis $redis
     * @param string $token acquireLock() 返回的 token
     * @return bool
     */
    public static function renewLock(Redis $redis, string $token): bool
    {
        try {
            // 比较和续期必须是原子的，否则「比较通过 → 锁过期 → 续期」会把别人的锁续走
            $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("expire", KEYS[1], ARGV[2])
else
    return 0
end
LUA;
            return (int)$redis->eval($script, [self::lockKey(), $token, self::LOCK_TTL], 1) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 释放刷库锁
     *
     * 只删自己的锁：直接 DEL 的话，一个超时的旧消费者收尾时会把新消费者刚拿到的锁删掉，
     * 于是第三个请求又能抢到锁，队列上同时出现多个消费者。
     *
     * @param Redis $redis
     * @param string|null $token acquireLock() 返回的 token；null 表示没拿到锁，什么都不用做
     * @return void
     */
    public static function releaseLock(Redis $redis, ?string $token): void
    {
        if ($token === null) {
            return;
        }
        try {
            $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;
            $redis->eval($script, [self::lockKey(), $token], 1);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 把队列里的数据落库
     *
     * 先读后删：数据库不可用时保留队列不裁剪，等下次重试，宁可重复也不丢数据；
     * 数据库是通的时候整批确认，写不进去的个别行先搬进死信队列再裁剪 —— 
     * 它们留在主队列里只会永远堵着，但直接丢掉就是无声的数据丢失。
     *
     * 上限按「从队列取走的条数」(attempted) 计算，而不是按写入成功数：
     * 否则一批消息大部分写失败时 written 不增长，循环会继续吃下去，
     * 极端情况（整个队列都是坏 JSON）下单次调用会一路处理完 MAX_LENGTH 条，
     * 彻底失去时间边界。
     *
     * @param Redis $redis
     * @param Db $db 统计数据所在的数据库
     * @param int $limit 本次最多处理多少条，0 表示用默认上限
     * @param float|null $deadline 墙钟截止时间（microtime 时间戳），null 表示用默认预算
     * @param string|null $token 刷库锁的 token；传入后会在批次之间续租，一旦发现锁已易主立即停手
     * @return array{attempted:int,written:int,invalid:int,rejected:int,dead:int,stopped:string,error:?string}
     *         attempted 取走并裁掉的消息数；written 实际入库行数；
     *         invalid  JSON 解析失败的条数；rejected 数据库逐行重试仍写不进去的条数；
     *         dead     已转入死信队列的条数（invalid + rejected 都会进死信，不再无声丢弃）；
     *         恒有 invalid + rejected === attempted - written。
     *         stopped 为结束原因：
     *           empty    队列已取空（正常结束）
     *           limit    达到条数上限，队列中仍有积压
     *           deadline 达到时间上限，队列中仍有积压
     *           db       数据库不可用，本批已保留在队列中等待重试
     *           lock     刷库锁已经不在自己手上，为避免多消费者并发而主动停手；
     *                    未确认的那一批不计入 attempted/written，下次刷库会重放它
     *           error    Redis 或其它异常（含死信写入失败、claim 脚本出错），详见 error；
     *                    未确认的批次同样留在 processing 里等下次重放
     */
    public static function flush(
        Redis $redis,
        Db $db,
        int $limit = 0,
        ?float $deadline = null,
        ?string $token = null
    ): array {
        $limit = $limit > 0 ? $limit : self::FLUSH_LIMIT;
        $deadline = $deadline ?? (microtime(true) + self::FLUSH_DEADLINE);
        $batchSize = Migrate::BATCH_SIZE;
        $renewAt = microtime(true) + self::LOCK_RENEW_INTERVAL;
        $dates = [];      // 本次写进数据库的记录覆盖了哪些日期，收尾时按它失效缓存

        $result = [
            'attempted' => 0,
            'written'   => 0,
            'invalid'   => 0,
            'rejected'  => 0,
            'dead'      => 0,
            'invalidated' => 0,
            'stopped'   => 'empty',
            'error'     => null,
        ];

        try {
            while ($result['attempted'] < $limit) {
                $now = microtime(true);

                if ($now >= $deadline) {
                    $result['stopped'] = 'deadline';
                    break;
                }

                // 续租放在取数据之前：确认锁还在自己手上，再动队列
                if ($token !== null && $now >= $renewAt) {
                    if (!self::renewLock($redis, $token)) {
                        $result['stopped'] = 'lock';
                        $result['error'] = '刷库锁已易主，为避免与另一个消费者同时裁剪队列而停止';
                        break;
                    }
                    $renewAt = $now + self::LOCK_RENEW_INTERVAL;
                }

                $take = min($batchSize, $limit - $result['attempted']);

                # 先把上一轮没确认完的捡回来，没有残余才去主队列取新的
                $items = self::leftover($redis);
                $fromLeftover = !empty($items);
                if (!$fromLeftover) {
                    $items = self::claim($redis, $take);
                }
                if (empty($items)) {
                    $result['stopped'] = 'empty';
                    break;
                }

                $rows = [];
                $rowItem = [];          // $rows 下标 => $items 下标，失败行要靠它找回原始消息
                foreach ($items as $i => $item) {
                    $row = json_decode($item, true);
                    if (is_array($row)) {
                        $rowItem[] = $i;
                        $rows[] = self::normalize($row);
                    }
                }

                // insertBatchDetailed 不抛异常，返回写入行数、失败行下标和每个失败的归类
                $outcome = empty($rows)
                    ? ['written' => 0, 'failed' => [], 'kinds' => [], 'fatal' => null, 'error' => null]
                    : Migrate::insertBatchDetailed($db, $rows, self::COLUMNS);
                $ok = $outcome['written'];

                /*
                 * 语句还没发出去就整批失败（连不上、拼不出语句）—— 一行 failed 都拿不到，
                 * 没有任何依据说这批数据有问题，原样留着。
                 */
                if ($outcome['fatal'] !== null) {
                    self::markStuck($redis);
                    $result['stopped'] = 'db';
                    $result['error'] = sprintf(
                        '目标库写入失败（%s），本批 %d 条已保留在队列中等待重试：%s',
                        $outcome['fatal']->name,
                        count($items),
                        (string)$outcome['error']
                    );
                    break;
                }

                /*
                 * 把失败行分成两堆：明确是这一行的错（转死信），和其余（留着重试）。
                 *
                 * 以前这里不分：只要 alive() 说数据库还活着，所有失败行一律 db-rejected
                 * 进死信。而 alive() 走的是 SELECT —— 实测库处于只读事务、或账号的
                 * INSERT 权限被回收时它照样成功，磁盘写满也一样。于是「每一条 INSERT
                 * 都失败」被判成「每一条都是脏数据」，整个队列被倒进死信，
                 * 死信满 DEAD_MAX_LENGTH 之后从最旧的开始丢 —— 静默的大规模数据丢失。
                 * 判据改成 SQLSTATE，见 Database::classifyWriteError() 与 WriteErrorKind。
                 */
                $rejected = [];     // 下标 => true，明确的脏数据
                $retry = [];        // 下标 => WriteErrorKind，留着重试的
                foreach ($outcome['failed'] as $i) {
                    $kind = $outcome['kinds'][$i] ?? WriteErrorKind::Unknown;
                    if ($kind->shouldRetry()) {
                        $retry[$i] = $kind;
                    } else {
                        $rejected[$i] = true;
                    }
                }

                /*
                 * 缓存失效的登记必须放在下面任何一个 break 之前：这些行已经躺在数据库里了，
                 * 后面无论因为什么原因没能确认这一批，缓存该失效的照样得失效。
                 * 数据变了而缓存没变，比刷库失败本身更难发现。
                 */
                if ($ok > 0) {
                    $failedRows = array_fill_keys($outcome['failed'], true);
                    $written = empty($outcome['failed'])
                        ? $rows
                        : array_values(array_diff_key($rows, $failedRows));
                    foreach (Cache::datesOf($written) as $date) {
                        $dates[$date] = true;
                    }
                }

                /*
                 * 有行要留着重试就不能确认这一批：Redis List 只能按位置整批 LTRIM，
                 * 做不到「只确认成功的那几条」。整批留下，下轮重放 ——
                 * 已经写进去的那部分由 event_id 唯一索引挡掉重复。
                 *
                 * 唯一的例外是这批已经卡了太久（STUCK_SECONDS）：那说明谁也没来修，
                 * 再留下去就是让它堵到队列涨满、从最旧的开始丢。到点了就放行，
                 * 把这些行按各自的归类转进死信，至少留下证据而不是无声消失。
                 */
                if (!empty($retry)) {
                    $stuckFor = self::stuckFor($redis);

                    if ($stuckFor === null || $stuckFor < self::STUCK_SECONDS) {
                        self::markStuck($redis);
                        $kinds = array_unique(array_map(static fn($k) => $k->name, $retry));
                        $result['stopped'] = 'db';
                        $result['error'] = sprintf(
                            '本批 %d 条中有 %d 条写入失败且判定为环境问题（%s），'
                            . '整批已保留在队列中等待重试，已卡住 %d 秒（上限 %d 秒）：%s',
                            count($items),
                            count($retry),
                            implode('/', $kinds),
                            (int)($stuckFor ?? 0),
                            self::STUCK_SECONDS,
                            (string)$outcome['error']
                        );
                        break;
                    }

                    # 卡过头了，放行：这些行转死信，本批照常确认
                    $result['error'] = sprintf(
                        '本批 %d 条已卡住 %d 秒（超过 %d 秒上限），其中 %d 条写不进去的已转入死信队列',
                        count($items),
                        (int)$stuckFor,
                        self::STUCK_SECONDS,
                        count($retry)
                    );
                }

                $rejectedRows = $rejected + array_fill_keys(array_keys($retry), true);

                /*
                 * 动 processing 之前最后一次确认锁还在自己手上。
                 *
                 * 循环顶部那次续租挡不住这个场景：整批 INSERT 失败会退化成
                 * 逐行写，一批 BATCH_SIZE 条就是上千次数据库往返，慢库上足以超过
                 * LOCK_TTL。锁一过期，另一个消费者拿到锁、读到同一个 processing，
                 * 两边再各自按 count($items) 做位置 LTRIM —— 后动手的那次砍掉的
                 * 就是对方刚取走、还没落库的消息。
                 *
                 * 停手的代价只是这批留在 processing 下轮重放，event_id 的唯一索引
                 * 会挡掉重复写入；继续往下走的代价是无声丢数据，两者不对等。
                 */
                if ($token !== null && !self::renewLock($redis, $token)) {
                    $result['stopped'] = 'lock';
                    $result['error'] = sprintf(
                        '写库期间刷库锁已易主，本批 %d 条（已写入 %d 行）保留在 processing 中未确认，'
                        . '下次刷库会重放，重复部分由 event_id 唯一索引挡下',
                        count($items),
                        $ok
                    );
                    break;
                }
                $renewAt = microtime(true) + self::LOCK_RENEW_INTERVAL;

                /*
                 * 走到这里数据库是通的、锁也还在手上，这一批可以确认掉了。
                 * 但确认的前提是「没写进去的那些留下了证据」：Redis List 只能整批 LTRIM，
                 * 做不到只确认成功的几条，所以先把失败的原样搬进死信队列再裁剪。
                 * 顺序不能反 —— 反了就是老问题：一批 1000 条只成功 1 条，另外 999 条无声消失。
                 * pushDead() 写不进去会抛异常，异常会跳过下面的 LTRIM，这批原样留着。
                 */
                $rowOfItem = array_flip($rowItem);
                $dead = [];
                foreach ($items as $i => $item) {
                    if (!isset($rowOfItem[$i])) {
                        $dead[] = ['reason' => 'invalid-json', 'payload' => $item];
                        continue;
                    }
                    $row = $rowOfItem[$i];
                    if (!isset($rejectedRows[$row])) {
                        continue;
                    }
                    # 原因按归类记：db-rejected 是脏数据，db-environment/db-unknown 是卡过头才放行的
                    $kind = $retry[$row] ?? WriteErrorKind::Data;
                    $dead[] = ['reason' => $kind->reason(), 'payload' => $item];
                }

                $result['dead'] += self::pushDead($redis, $dead);

                /*
                 * 确认：这批已经有归宿（进了数据库或死信），从 processing 清掉。
                 * lTrim 失败必须当场停手 —— 当成成功的话，这批会在下一轮被
                 * leftover() 再捡一次，而 attempted/written 已经按成功计过数了，
                 * 调用方（flush-queue.php 的退出码、后台提示）会读到一个假的成功。
                 */
                if ($redis->lTrim(self::processingKey(), count($items), -1) === false) {
                    $error = $redis->getLastError();
                    $redis->clearLastError();
                    throw new \RuntimeException(
                        '确认批次失败（LTRIM processing）：'
                        . ($error !== null && $error !== '' ? $error : '未知错误')
                    );
                }

                # 这批确认掉了，卡住计时清零
                self::clearStuck($redis);

                $result['attempted'] += count($items);
                $result['written']   += $ok;
                $result['invalid']   += count($items) - count($rows);
                $result['rejected']  += count($outcome['failed']);

                # 残余批次的条数和 $take 无关，不能拿它推断队列空了
                if (!$fromLeftover && count($items) < $take) {
                    // 队列里已经没有更多消息了
                    $result['stopped'] = 'empty';
                    break;
                }

                $result['stopped'] = 'limit';
            }

            $redis->set(self::lastFlushKey(), time());
        } catch (\Throwable $e) {
            // 刷库中断不影响调用方，未裁剪的数据仍在队列里
            $result['stopped'] = 'error';
            $result['error'] = $e->getMessage();
        }

        /*
         * 放在 try 外面：中途出错时前面几批可能已经写进去了，那部分的缓存同样得失效。
         * 数据已经变了而缓存还是旧的，比刷库失败本身更难发现。
         */
        $result['invalidated'] = Cache::invalidate($redis, array_keys($dates));

        return $result;
    }

    /**
     * 这个 Redis 键装的是数据，还是可以随时重算的缓存？
     *
     * 队列、死信、刷库锁、上次刷库时间都属于前者：缓存删了会重算，这些删了就没了。
     * 判断逻辑放在这里而不是调用方，是为了以后新增队列相关的键时不用四处改。
     *
     * @param string $key 完整键名
     * @return bool
     */
    public static function isDataKey(string $key): bool
    {
        return str_starts_with($key, self::key());
    }

    /**
     * 这个键是不是旧前缀（LEGACY_BASE）下的队列数据键
     *
     * 卸载清理时要和 isDataKey() 一起看：老键里同样可能压着没落库的数据，
     * 只认新键名的话，清理会把它们当普通缓存删掉 —— 那是静默丢数据。
     *
     * 旧前缀下两代都要认：
     *   - typecho_access:queue、:processing、:dead、:lock、:last_flush
     *   - typecho_access:{12 位指纹}:queue...
     * 第二代不比对是不是本站点的指纹：这个函数只用来「拦住别删」，
     * 而调用方（Plugin::clearRedisCache）传进来的键已经过 Cache::isLegacyKey()
     * 筛过一道，别的站点的键根本走不到这里；宁可多拦也不能漏拦。
     *
     * @param string $key 完整键名
     * @return bool
     */
    public static function isLegacyDataKey(string $key): bool
    {
        # 第一代：加指纹之前的固定键名
        if (str_starts_with($key, self::LEGACY_PREFIX)) {
            return true;
        }

        # 第二代：加了指纹、还没换前缀
        $pattern = '/^' . preg_quote(Cache::LEGACY_BASE, '/') . '[0-9a-f]{12}:' . self::NAME . '/';
        return preg_match($pattern, $key) === 1;
    }

    /**
     * 把加指纹之前遗留的队列接管到当前站点的键名下
     *
     * 升级到带指纹的键名之后，老队列里没落库的访问日志会突然「没人消费」：
     * 生产者写新键、消费者读新键，老键就那么放着，直到有人手动清 Redis。
     * 所以升级后第一次保存设置（或跑一次 flush-queue）时把它们改名接过来。
     *
     * 只在「新键还不存在」时接管：新键已经有数据说明这个站点早就在用新键名了，
     * 这时候合并两条队列的顺序没有意义，宁可原样留着让人工处理。
     *
     * 多个站点共用一个 Redis 且都还在用老键名时，谁先接管谁拿走整条队列 ——
     * 这正是老键名本身的毛病，接管只是把它固定下来，不会让情况更糟。
     *
     * 只接管「加指纹之前」的第一代键。v3.2.3 把前缀从 typecho_access: 换成
     * plugin:access: 时，旧前缀下带指纹的第二代（typecho_access:{指纹}:queue*）
     * 按明确决定**不接管**，直接切到新键上：换前缀前请先把队列刷干净
     * （tools/flush-queue.php，或在后台禁用一次插件），否则那批消息会滞留在
     * 旧键上无人消费。真要接管的话，加进下面的 $moves 即可，逻辑是现成的。
     *
     * @param Redis $redis
     * @return array{adopted:string[],skipped:string[]} 接管了哪些、因新键已存在而跳过哪些
     */
    public static function adoptLegacy(Redis $redis): array
    {
        $out = ['adopted' => [], 'skipped' => []];

        $moves = [
            self::LEGACY_PREFIX                       => self::key(),
            self::LEGACY_PREFIX . ':processing'       => self::processingKey(),
            self::LEGACY_PREFIX . ':dead'             => self::deadKey(),
        ];

        try {
            foreach ($moves as $legacy => $current) {
                # 理论上不会相等（新键必带指纹），相等就说明指纹没生效，别动
                if ($legacy === $current || !$redis->exists($legacy)) {
                    continue;
                }
                if ($redis->exists($current)) {
                    $out['skipped'][] = $legacy;
                    continue;
                }
                if ($redis->rename($legacy, $current)) {
                    $out['adopted'][] = $legacy;
                }
            }

            # 锁和刷库时间戳是状态不是数据，重新算就有，不值得接管
            $redis->del(self::LEGACY_PREFIX . ':lock', self::LEGACY_PREFIX . ':last_flush');
        } catch (\Throwable $e) {
            // 接管失败不该挡住别的事；老键原样留着，下次还有机会
        }

        return $out;
    }

    /**
     * 反复刷库直到队列取空、时间用尽或出错为止
     *
     * flush() 单次上限是 FLUSH_LIMIT 条，「刷了一次」不等于「刷完了」。
     * 需要「尽量刷干净」语义的场景（比如停用插件）用这个。
     * 调用方负责持锁：token 会一路传下去续租。
     *
     * @param Redis $redis
     * @param Db $db
     * @param string $token 刷库锁的 token
     * @param float $deadline 墙钟截止时间（microtime 时间戳）
     * @return array{written:int,rounds:int,stopped:string,error:?string}
     */
    public static function drain(Redis $redis, Db $db, string $token, float $deadline): array
    {
        $out = ['written' => 0, 'rounds' => 0, 'stopped' => 'empty', 'error' => null];

        while (microtime(true) < $deadline) {
            $round = self::flush($redis, $db, self::FLUSH_LIMIT, $deadline, $token);
            $out['written'] += $round['written'];
            $out['rounds']++;
            $out['stopped'] = $round['stopped'];

            // 只有「本轮撞到条数上限」才说明队列里还有，值得再来一轮
            if ($round['stopped'] !== 'limit') {
                if (in_array($round['stopped'], ['db', 'lock', 'error'], true)) {
                    $out['error'] = $round['error'];
                }
                break;
            }
        }

        return $out;
    }

    /**
     * 补齐字段、保证顺序，并把超出列宽的值裁到合法范围
     *
     * 补齐顺序是为了避免不同版本的记录结构不一致导致列错位；
     * 截断是因为这些值全部来自访客可控的请求头和查询串 ——
     * 不设上限的话，一条超长 UA 就能让整批 INSERT 失败并退化成上千次逐行写入。
     *
     * 入队和直写两条路都要过这里，所以是 public。
     *
     * @param array $row
     * @return array
     */
    public static function normalize(array $row): array
    {
        $normalized = [];
        foreach (self::COLUMNS as $column) {
            $value = array_key_exists($column, $row) ? $row[$column] : null;

            /*
             * 数组/对象没有合理的字符串形式，(string) 转换只会得到 "Array" 外加一条
             * PHP warning，而这条路径是匿名请求走的 —— 直接当作「没有这个值」。
             */
            if ($value !== null && !is_scalar($value)) {
                $value = null;
            }

            if ($value !== null && isset(self::LIMITS[$column])) {
                $value = (string)$value;

                /*
                 * 主机名不区分大小写（RFC 3986 §3.2.2），统一小写之后再入库。
                 * 不做的话 wave.com 和 WAVE.COM 在 GROUP BY 里是两个来源，
                 * 同一个站点被拆成好几条，Top N 的名次也跟着失真。
                 *
                 * 只动 scheme 和主机名两段：path 和 query 是区分大小写的，
                 * 一起小写会把 /Article/Foo 变成另一个地址。
                 */
                if (in_array($column, self::HOST_COLUMNS, true)) {
                    $value = strtolower($value);
                } elseif (in_array($column, self::URL_COLUMNS, true)) {
                    $value = self::normalizeUrl($value);
                }

                # varchar(N) 数的是字符不是字节，所以用 mb_ 系列
                if (mb_strlen($value, 'UTF-8') > self::LIMITS[$column]) {
                    $value = mb_substr($value, 0, self::LIMITS[$column], 'UTF-8');
                }
            } elseif ($value !== null && in_array($column, self::ID_COLUMNS, true)) {
                $value = self::clampId($value);
            }

            $normalized[$column] = $value;
        }
        return $normalized;
    }

    /**
     * 只含主机名的列：整列小写
     */
    private const HOST_COLUMNS = ['referer_domain', 'entrypoint_domain'];

    /**
     * 完整 URL 的列：只把 scheme 和主机名小写，其余原样
     *
     * 控制台的「来源」Top N 是按 entrypoint 整串分组的，
     * 只规范化 *_domain 两列的话，HTTPS://WAVE.COM/x 和 https://wave.com/x
     * 在 URL 那一栏照样会被拆成两条。
     */
    private const URL_COLUMNS = ['referer', 'entrypoint'];

    /**
     * 把 URL 里不区分大小写的部分统一成小写
     *
     * RFC 3986 规定 scheme（§3.1）和 host（§3.2.2）不区分大小写，其余部分区分。
     * 所以这里只动这两段：
     *   HTTPS://WAVE.COM/Article/Foo?Q=1  →  https://wave.com/Article/Foo?Q=1
     * 用户信息（user:pass@）也原样保留 —— 密码是区分大小写的。
     *
     * strtolower() 而不是 mb_strtolower()：PHP 8.2 起前者只映射 ASCII 的 A-Z，
     * 正好对应 DNS 的大小写规则；后者会去动非 ASCII 字符，
     * 而国际化域名的大小写折叠是 IDNA 的事，不该在这里顺手做。
     *
     * @param string $url
     * @return string
     */
    public static function normalizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return (string)preg_replace_callback(
            '~^([a-zA-Z][a-zA-Z0-9+.\-]*://)([^/?#@]*@)?([^/?#:]*)~',
            static fn(array $m): string => strtolower($m[1]) . ($m[2] ?? '') . strtolower($m[3] ?? ''),
            $url,
            1
        );
    }

    /**
     * 把 cid / mid 收进 int unsigned 的范围，越界或非数字一律当作「没有」
     *
     * is_numeric() 拦不住 '1e50'、'0x1A'、' 12 ' 这类值，
     * 它们能一路走到 INSERT 才报错。
     *
     * @param mixed $value
     * @return int|null
     */
    private static function clampId(mixed $value): ?int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $id = (int)$value;
        } else {
            return null;
        }
        return ($id < 0 || $id > self::UNSIGNED_INT_MAX) ? null : $id;
    }
}
