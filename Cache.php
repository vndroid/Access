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
     * plugin:{插件名}: 是本站所有插件共用的命名规范（参见 Accelerate 的
     * plugin:accelerate:），一眼能看出键属于哪个插件，也方便按前缀整体巡检。
     */
    public const BASE = 'plugin:access:';

    /**
     * 换成新规范前缀之前用过的开头
     *
     * v3.2.3 以前所有键都长 typecho_access:... 这样，前后有两代：
     *   - 加站点指纹之前：typecho_access:overview:total
     *   - 加了指纹、还没换前缀：typecho_access:{指纹}:overview:total
     * 只在卸载清理时用到（见 isLegacyKey()）—— 不认出它们的话，
     * 这两代键会在 Redis 里留到天荒地老，谁也不知道是什么东西。
     */
    public const LEGACY_BASE = 'typecho_access:';

    /** 站点指纹算一次就够，一次请求里会用上几十遍 */
    private static ?string $prefix = null;

    /**
     * 键名前缀，带站点指纹
     *
     * 以前是写死的 LEGACY_BASE，于是多个站点共用一个 Redis DB 时，
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
     * 这个键是不是旧前缀留下的、且该由本站点处理的老键
     *
     * 旧前缀 LEGACY_BASE 下有两代键：
     *   - 加指纹之前：typecho_access:overview:total（当年就不分站点，混用的）
     *   - 加了指纹、还没换前缀：typecho_access:{指纹}:overview:total
     * 卸载清理要把两代一并认出来，否则它们会永远留在 Redis 里。
     *
     * 但**不能**把「以旧前缀开头」当成充分条件：同一个 Redis 上别的站点的
     * typecho_access:{别人的指纹}:... 也满足这个条件，认下来就等于删别人的队列 ——
     * 当初加指纹要修的正是这个毛病，别在换前缀时把它放回来。
     * 所以带指纹的那一代必须逐字比对本站点指纹，只有分不出站点的第一代才无条件认下。
     *
     * @param string $key 完整键名
     * @return bool
     */
    public static function isLegacyKey(string $key): bool
    {
        if (!str_starts_with($key, self::LEGACY_BASE)) {
            return false;
        }

        $rest = substr($key, strlen(self::LEGACY_BASE));
        $head = strstr($rest, ':', true);
        $head = $head === false ? $rest : $head;

        # 指纹是 12 位十六进制，第一段不长这样说明是加指纹之前的第一代键
        if (preg_match('/^[0-9a-f]{12}$/', $head) !== 1) {
            return true;
        }

        # 带指纹的第二代，只认本站点自己的，别人的原样放过
        return $head === Health::fingerprint();
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
        /*
         * 这里只列「按日期算的」键。
         *
         * 全表聚合（overview:total、referer:*、overview:post_pie:*）以前也在这个
         * 名单里，那是一个会自我拆台的设计：控制台按 today → yesterday → total →
         * referer → pie → month 的顺序逐段请求，而第一段 today 会同步刷一次队列，
         * 刷完就走到这里，把后面三段要用的缓存全删掉 —— 于是每打开一次控制台，
         * 最贵的四个全表聚合都要从头算一遍，缓存等于不存在。
         * 实测 DISTINCT ip 单次 228 秒，占了整个库 75% 的时间，控制台稳定 504。
         *
         * 这些数字本来也不需要即时精确：新写入的几条访问对「总计」是个零头，
         * 差几分钟无所谓。改为各自带 TTL + 陈旧优先（见 Core::cachedAggregate()），
         * 由读取方决定什么时候刷新，而不是由写入方粗暴地删掉。
         *
         * 按日期的键必须留着：跨零点之后才把前一天的队列写进数据库时，
         * 前一天的统计会带着错误数字挂到 TTL 自然过期为止（最长 40 天）。
         * 当初加失效逻辑就是为了这个。
         */
        $names = [];
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
