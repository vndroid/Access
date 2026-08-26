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
 * 刷库由请求顺带触发：达到条数或时间阈值时，本次请求抢到锁的那一个负责刷，
 * 并且推迟到响应发出之后执行，访客感知不到。
 * 另外控制台加载数据时会同步刷一次，命令行脚本可挂 cron 兜底。
 *
 * 没有 Redis 时整套机制不启用，写入行为与之前完全一致。
 */
final class Queue
{
    /** 待写入队列（Redis List） */
    public const KEY = 'typecho_access:queue';

    /**
     * 死信队列（Redis List）
     *
     * 解析不了、或者数据库明确拒绝的消息落到这里，而不是直接丢掉。
     * 队列的确认是整批 LTRIM，做不到「只确认成功的那几条」（Redis List 不支持按位置挑着删），
     * 所以退而求其次：裁掉之前先把失败的原样留一份证据，可以人工排查或改完再回放。
     */
    public const DEAD_KEY = 'typecho_access:queue:dead';

    /** 刷库互斥锁 */
    private const LOCK_KEY = 'typecho_access:queue:lock';

    /** 上次刷库时间 */
    private const LAST_FLUSH_KEY = 'typecho_access:queue:last_flush';

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

    /** 入队字段，顺序固定；与 Migrate::COLUMNS 相同但不含自增主键 */
    public const COLUMNS = [
        'ua', 'browser_id', 'browser_version', 'os_id', 'os_version',
        'url', 'path', 'query_string', 'ip', 'entrypoint', 'entrypoint_domain',
        'referer', 'referer_domain', 'time', 'content_id', 'meta_id',
        'robot', 'robot_id', 'robot_version',
    ];

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

            $length = $redis->rPush(self::KEY, $payload);
            if ($length === false) {
                return false;
            }

            // 超出硬上限时丢掉最旧的部分，保留最新的 MAX_LENGTH 条
            if ($length > self::MAX_LENGTH) {
                $redis->lTrim(self::KEY, -self::MAX_LENGTH, -1);
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
     * 队列长度，Redis 出错时返回 null
     *
     * 把故障伪装成 0 会让「队列为空」和「Redis 挂了」变成同一个返回值，
     * 于是定时任务打印「队列为空，无需刷库」然后以成功退出，故障被彻底掩盖。
     *
     * @param Redis $redis
     * @return int|null
     */
    public static function tryLength(Redis $redis): ?int
    {
        try {
            $length = $redis->lLen(self::KEY);
            return $length === false ? null : (int)$length;
        } catch (\Throwable $e) {
            return null;
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
            return (int)$redis->lLen(self::DEAD_KEY);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 把写不进去的消息挪进死信队列
     *
     * 必须在裁剪主队列之前调用：中途挂掉的话，宁可这批消息重复处理一次，
     * 也不能出现「主队列已裁掉、死信里又没有」的空档。
     *
     * @param Redis $redis
     * @param array $entries 每项为 ['reason' => string, 'payload' => string]
     * @return int 实际入队条数
     */
    private static function pushDead(Redis $redis, array $entries): int
    {
        if (empty($entries)) {
            return 0;
        }

        $payloads = [];
        $at = time();
        foreach ($entries as $entry) {
            $encoded = json_encode([
                'at'      => $at,
                'reason'  => $entry['reason'],
                'payload' => $entry['payload'],
            ], JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $payloads[] = $encoded;
            }
        }
        if (empty($payloads)) {
            return 0;
        }

        $length = $redis->rPush(self::DEAD_KEY, ...$payloads);
        if ($length !== false && $length > self::DEAD_MAX_LENGTH) {
            $redis->lTrim(self::DEAD_KEY, -self::DEAD_MAX_LENGTH, -1);
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

            $last = (int)$redis->get(self::LAST_FLUSH_KEY);
            if ($last <= 0) {
                // 没有记录过，先打上时间戳，下一轮再按间隔判断
                $redis->set(self::LAST_FLUSH_KEY, time());
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
            $ok = $redis->set(self::LOCK_KEY, $token, ['nx', 'ex' => self::LOCK_TTL]);
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
            return (int)$redis->eval($script, [self::LOCK_KEY, $token, self::LOCK_TTL], 1) === 1;
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
            $redis->eval($script, [self::LOCK_KEY, $token], 1);
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
     *           lock     刷库锁已经不在自己手上，为避免多消费者并发而主动停手
     *           error    Redis 或其它异常，详见 error
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
                $items = $redis->lRange(self::KEY, 0, $take - 1);
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

                // insertBatchDetailed 不抛异常，返回写入行数和失败行下标，必须按返回值判断成败
                $outcome = empty($rows)
                    ? ['written' => 0, 'failed' => []]
                    : Migrate::insertBatchDetailed($db, $rows, self::COLUMNS);
                $ok = $outcome['written'];

                /*
                 * 一条都没写进去有两种原因，光看失败数分不出来，必须探一次活：
                 * 数据库挂了 —— 队列原样留着等下次重试；
                 * 整批都是脏数据 —— 留着只会永远堵住队列，转进死信。
                 * 之前这里一律当成前者，于是「队列尾部只剩一条脏数据」会让整个队列永久卡死。
                 */
                if ($ok === 0 && !empty($rows) && !Migrate::alive($db)) {
                    $result['stopped'] = 'db';
                    $result['error'] = sprintf(
                        '数据库不可用，本批 %d 条已保留在队列中等待重试',
                        count($rows)
                    );
                    break;
                }

                /*
                 * 走到这里数据库是通的，这一批可以确认掉了。
                 * 但确认的前提是「没写进去的那些留下了证据」：Redis List 只能整批 LTRIM，
                 * 做不到只确认成功的几条，所以先把失败的原样搬进死信队列再裁剪。
                 * 顺序不能反 —— 反了就是老问题：一批 1000 条只成功 1 条，另外 999 条无声消失。
                 */
                $rejectedRows = array_fill_keys($outcome['failed'], true);
                $rowOfItem = array_flip($rowItem);
                $dead = [];
                foreach ($items as $i => $item) {
                    if (!isset($rowOfItem[$i])) {
                        $dead[] = ['reason' => 'invalid-json', 'payload' => $item];
                    } elseif (isset($rejectedRows[$rowOfItem[$i]])) {
                        $dead[] = ['reason' => 'db-rejected', 'payload' => $item];
                    }
                }

                $result['dead'] += self::pushDead($redis, $dead);
                $redis->lTrim(self::KEY, count($items), -1);

                # 只有真的写进去的行才会影响统计，被拒的不算
                if ($ok > 0) {
                    $written = empty($outcome['failed'])
                        ? $rows
                        : array_values(array_diff_key($rows, $rejectedRows));
                    foreach (Cache::datesOf($written) as $date) {
                        $dates[$date] = true;
                    }
                }

                $result['attempted'] += count($items);
                $result['written']   += $ok;
                $result['invalid']   += count($items) - count($rows);
                $result['rejected']  += count($outcome['failed']);

                if (count($items) < $take) {
                    // 队列里已经没有更多消息了
                    $result['stopped'] = 'empty';
                    break;
                }

                $result['stopped'] = 'limit';
            }

            $redis->set(self::LAST_FLUSH_KEY, time());
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
        return str_starts_with($key, self::KEY);
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
     * 补齐字段并保证顺序，避免不同版本的记录结构不一致导致列错位
     *
     * @param array $row
     * @return array
     */
    private static function normalize(array $row): array
    {
        $normalized = [];
        foreach (self::COLUMNS as $column) {
            $normalized[$column] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        return $normalized;
    }
}
