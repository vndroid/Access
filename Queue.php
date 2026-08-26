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

    /** 刷库互斥锁 */
    private const LOCK_KEY = 'typecho_access:queue:lock';

    /** 上次刷库时间 */
    private const LAST_FLUSH_KEY = 'typecho_access:queue:last_flush';

    /** 锁的存活时间（秒），防止刷库进程挂掉后死锁 */
    private const LOCK_TTL = 60;

    /** 队列长度硬上限，超出后丢弃最旧的记录，避免数据库长时间不可用时撑爆 Redis */
    public const MAX_LENGTH = 200000;

    /** 单次刷库最多处理多少条，防止一次请求耗时过长 */
    public const FLUSH_LIMIT = 5000;

    /**
     * 单次刷库的墙钟上限（秒）
     *
     * 条数上限挡不住「每条都很慢」的情况：数据库变慢、批量 INSERT 反复退化成逐行时，
     * 5000 条也可能跑上好几分钟，而刷库锁的存活时间只有 LOCK_TTL 秒。
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
     * 队列长度
     *
     * @param Redis $redis
     * @return int
     */
    public static function length(Redis $redis): int
    {
        try {
            return (int)$redis->lLen(self::KEY);
        } catch (\Throwable $e) {
            return 0;
        }
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
     * @param Redis $redis
     * @return bool
     */
    public static function acquireLock(Redis $redis): bool
    {
        try {
            return (bool)$redis->set(self::LOCK_KEY, (string)getmypid(), ['nx', 'ex' => self::LOCK_TTL]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 释放刷库锁
     *
     * @param Redis $redis
     * @return void
     */
    public static function releaseLock(Redis $redis): void
    {
        try {
            $redis->del(self::LOCK_KEY);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 把队列里的数据落库
     *
     * 先读后删：整批一条都写不进去时保留队列不裁剪，等下次重试，宁可重复也不丢数据；
     * 数据库是通的、只有个别行写不进去（脏数据）时才连同它们一起裁掉，避免永远堵住队列。
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
     * @return array{attempted:int,written:int,invalid:int,rejected:int,stopped:string,error:?string}
     *         attempted 取走并裁掉的消息数；written 实际入库行数；
     *         invalid  JSON 解析失败被丢弃的条数；rejected 数据库逐行重试仍写不进去被丢弃的条数；
     *         恒有 invalid + rejected === attempted - written。
     *         stopped 为结束原因：
     *           empty    队列已取空（正常结束）
     *           limit    达到条数上限，队列中仍有积压
     *           deadline 达到时间上限，队列中仍有积压
     *           db       数据库不可用，本批已保留在队列中等待重试
     *           error    Redis 或其它异常，详见 error
     */
    public static function flush(Redis $redis, Db $db, int $limit = 0, ?float $deadline = null): array
    {
        $limit = $limit > 0 ? $limit : self::FLUSH_LIMIT;
        $deadline = $deadline ?? (microtime(true) + self::FLUSH_DEADLINE);
        $batchSize = Migrate::BATCH_SIZE;

        $result = [
            'attempted' => 0,
            'written'   => 0,
            'invalid'   => 0,
            'rejected'  => 0,
            'stopped'   => 'empty',
            'error'     => null,
        ];

        try {
            while ($result['attempted'] < $limit) {
                if (microtime(true) >= $deadline) {
                    $result['stopped'] = 'deadline';
                    break;
                }

                $take = min($batchSize, $limit - $result['attempted']);
                $items = $redis->lRange(self::KEY, 0, $take - 1);
                if (empty($items)) {
                    $result['stopped'] = 'empty';
                    break;
                }

                $rows = [];
                foreach ($items as $item) {
                    $row = json_decode($item, true);
                    if (is_array($row)) {
                        $rows[] = self::normalize($row);
                    }
                }

                // insertBatch 不抛异常，只返回实际写入行数，必须按返回值判断成败
                $ok = empty($rows) ? 0 : Migrate::insertBatch($db, $rows, self::COLUMNS);

                if ($ok === 0 && !empty($rows)) {
                    // 一条都没写进去，基本可以断定是数据库不可用；
                    // 保留队列不裁剪，等下一次刷库重试，宁可重复也不丢数据
                    $result['stopped'] = 'db';
                    $result['error'] = sprintf(
                        '数据库写入失败，本批 %d 条已保留在队列中等待重试',
                        count($rows)
                    );
                    break;
                }

                // 走到这里说明数据库是通的：写不进去的个别行属于脏数据，
                // 裁掉避免它们永远堵住队列
                $redis->lTrim(self::KEY, count($items), -1);

                $result['attempted'] += count($items);
                $result['written']   += $ok;
                $result['invalid']   += count($items) - count($rows);
                $result['rejected']  += count($rows) - $ok;

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

        return $result;
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
