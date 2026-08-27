<?php

namespace TypechoPlugin\Access;

use Redis;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 统计缓存的键名与失效
 *
 * 缓存键长什么样只有这里知道：写缓存的是 Core，让缓存失效的是队列刷库和删日志，
 * 三边各拼一遍键名的话，改一个忘一个是迟早的事。
 */
final class Cache
{
    /**
     * 本插件所有 Redis 键的公共开头
     *
     * 只用来识别「这是 Access 写的键」，实际键名一律走 prefix()。
     */
    public const BASE = 'typecho_access:';

    /** post_pie 的 Top N 取自 pageSize，被夹在 1..50 之间 */
    private const POST_PIE_MAX = 50;

    /** 站点指纹算一次就够，一次请求里会用上几十遍 */
    private static ?string $prefix = null;

    /**
     * 键名前缀，带站点指纹
     *
     * 以前是写死的 typecho_access:，于是多个站点共用一个 Redis DB 时，
     * 队列、死信、刷库锁、统计缓存全都撞在一起 —— A 站的访问日志被 B 站
     * 消费掉写进 B 站的库，两边的刷库锁互相顶掉，缓存串着看。
     * 指纹取自插件目录（见 Health::fingerprint()）。
     *
     * @return string
     */
    public static function prefix(): string
    {
        if (self::$prefix === null) {
            self::$prefix = self::BASE . Health::fingerprint() . ':';
        }
        return self::$prefix;
    }

    /**
     * 补上前缀
     *
     * @param string $name
     * @return string
     */
    public static function key(string $name): string
    {
        return self::prefix() . $name;
    }

    /**
     * 这个键是不是「加指纹之前」留下的老键
     *
     * 老键长 typecho_access:overview:total 这样，新键中间多一段指纹。
     * 卸载清理和队列接管都要认出它们：留着的话，老队列里没落库的数据
     * 就永远没人再看一眼了。
     *
     * @param string $key 完整键名
     * @return bool
     */
    public static function isLegacyKey(string $key): bool
    {
        if (!str_starts_with($key, self::BASE)) {
            return false;
        }

        $rest = substr($key, strlen(self::BASE));
        $head = strstr($rest, ':', true);
        $head = $head === false ? $rest : $head;

        # 指纹是 12 位十六进制，第一段不长这样就说明是老键
        return preg_match('/^[0-9a-f]{12}$/', $head) !== 1;
    }

    /**
     * 一批记录覆盖了哪些日期
     *
     * @param array $rows 每行含 time 字段（Unix 时间戳）
     * @return string[] Y-m-d，已去重
     */
    public static function datesOf(array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) {
            $time = (int)($row['time'] ?? 0);
            if ($time > 0) {
                /*
                 * 日期口径必须和查询侧一致：queryDayCounts() 用的是
                 * strtotime("{$date} 00:00:00")，即服务器本地时区。
                 * 这里换成 UTC 的话，零点前后的记录会清错天。
                 */
                $dates[date('Y-m-d', $time)] = true;
            }
        }
        return array_keys($dates);
    }

    /**
     * 新数据落库（或旧数据被删）之后，让受影响的统计缓存失效
     *
     * 不做这一步的后果不是「数字晚一点更新」而是「数字长期是错的」：
     * 历史日期的缓存 TTL 长达 PAST_DAY_TTL，跨零点之后才把前一天的队列写进数据库的话，
     * 前一天的统计会带着错误数字挂到 TTL 自然过期为止。
     *
     * @param Redis $redis
     * @param array $dates 受影响的日期（Y-m-d）
     * @return int 删掉的键数量
     */
    public static function invalidate(Redis $redis, array $dates): int
    {
        if (empty($dates)) {
            return 0;
        }

        try {
            return (int)$redis->del(self::staleKeys($dates));
        } catch (\Throwable $e) {
            // 缓存没清掉不影响数据本身，最坏情况是读到旧数字
            return 0;
        }
    }

    /**
     * 受影响的缓存键清单
     *
     * @param array $dates
     * @return string[]
     */
    private static function staleKeys(array $dates): array
    {
        # 只要有数据变动，这几项必然跟着变
        $names = ['overview:total', 'referer:url', 'referer:domain'];

        /*
         * post_pie 的键名带 Top N，N = min(pageSize, 50)，改过 pageSize 就会留下多个变体。
         * 一共只有 50 个可能值，全列出来一次 DEL 掉，比 SCAN 便宜得多 ——
         * SCAN 的代价随整个 Redis 的键数量增长，而刷库是每分钟都在发生的事。
         */
        for ($n = 1; $n <= self::POST_PIE_MAX; $n++) {
            $names[] = 'overview:post_pie:top' . $n;
        }

        $months = [];
        foreach (array_unique($dates) as $date) {
            $names[] = 'overview:dayfull:' . $date;
            $names[] = 'overview:daycount:' . $date;
            $names[] = 'overview:yesterday:' . $date;
            $months[substr($date, 0, 7)] = true;
        }

        # 月度图表是按天汇总出来的，某一天变了整月都得重算
        foreach (array_keys($months) as $month) {
            $names[] = 'overview:month:' . $month;
        }

        return array_map(self::key(...), $names);
    }
}
