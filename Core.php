<?php

namespace TypechoPlugin\Access;

use Redis;
use Typecho\Config;
use Typecho\Cookie;
use Typecho\Db;
use Typecho\Db\Exception as DbException;
use Typecho\I18n;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Request;
use Typecho\Response;
use Utils\Helper;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Core
{
    /** 统计数据所在的数据库，可能是 Typecho 主库，也可能是独立配置的库 */
    protected readonly Db $db;

    /** Typecho 主库，用于读取文章标题等内容信息 */
    protected readonly Db $mainDb;

    protected readonly Request $request;
    protected readonly Response $response;

    /** Redis 缓存实例，未启用时为 null */
    protected ?Redis $redis = null;

    /** 本次请求是否已经安排过刷库，避免重复注册 */
    protected bool $flushScheduled = false;

    /** Redis 缓存键前缀 */

    /** 历史日期的统计不会再变化，缓存 40 天足够覆盖当月图表 */
    private const PAST_DAY_TTL = 3456000;

    /** 匿名埋点接口每个 IP 每分钟允许的次数 */
    private const TRACK_RATE_LIMIT = 60;

    public readonly UA $ua;
    public readonly Config $config;
    public readonly string $action;
    public readonly string $title;
    public array $logs = [];
    public array $overview = [];
    public array $referer = [];
    public array $postPie = [];

    /**
     * 构造函数，根据不同类型的请求，计算不同的数据并渲染输出
     *
     * @access public
     * @throws PluginException
     * @throws DbException
     */
    public function __construct()
    {
        # Load language pack
        if (I18n::getLang() !== 'zh_CN') {
            $file = __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ .
            '/Access/lang/' . I18n::getLang() . '.mo';
            file_exists($file) && I18n::addLang($file);
        }
        # Init variables
        $this->config = Options::alloc()->plugin('Access');
        $this->mainDb = Db::get();
        $this->db = Database::get($this->config);
        $this->request = Request::getInstance();
        $this->response = Response::getInstance();
        if ($this->config->pageSize == null || $this->config->isDrop == null) {
            throw new PluginException(_t('请先设置插件！'));
        }
        $this->ua = new UA($this->request->getAgent());
        $this->initRedis();
        switch ($this->request->get('action')) {
            case 'overview':
                $this->action = 'overview';
                $this->title = _t('访问概览');
                break;
            case 'logs':
            default:
                $this->action = 'logs';
                $this->title = _t('访问日志');
                break;
        }
    }

    /**
     * 获取概览页全部数据（供 AJAX 接口调用）
     *
     * @access public
     * @return array
     * @throws DbException
     */
    public function getOverviewData(): array
    {
        # 先把队列里积压的写进去，否则控制台看到的数字会偏低
        $this->flushQueue();

        $this->parseOverview();
        $this->parseReferer();
        $this->parsePostPie();

        return [
            'overview' => [
                'today'     => $this->overview['today'],
                'yesterday' => $this->overview['yesterday'],
                'total'     => $this->overview['total'],
            ],
            'referer'    => $this->referer,
            'chart_data' => json_decode($this->overview['chart_data'], true),
            'post_pie'   => $this->postPie,
        ];
    }

    /**
     * 分段获取概览数据
     *
     * 数据量大时一次算完整个概览会超出 Web 超时（300 万行实测本地就要 6 秒），
     * 而首次加载失败又会导致缓存永远建不起来，形成死循环。
     * 这里把概览拆成几块分别请求，每块都足够小；算完的部分会写进缓存，
     * 于是即使某一块要跑好几次也能逐步推进。
     *
     * @access public
     * @param string $section today / yesterday / month / total / referer / pie
     * @param float|null $deadline 时间预算（microtime 时间戳），仅 month 分段使用
     * @return array
     * @throws DbException
     */
    public function getOverviewSection(string $section, ?float $deadline = null): array
    {
        # 队列只在第一段刷一次就够，避免每段都刷
        if ($section === 'today') {
            $this->flushQueue();
        }

        return match ($section) {
            'today' => [
                'done' => true,
                'today' => $this->chartOf($this->queryDayOverview(date('Y-m-d')), 'day'),
            ],
            'yesterday' => [
                'done' => true,
                'yesterday' => $this->chartOf($this->cachedDayOverview(date('Y-m-d', strtotime('-1 day'))), 'day'),
            ],
            'total' => [
                'done' => true,
                'total' => $this->cachedTotalOverview(),
            ],
            'month' => $this->monthSection($deadline),
            'referer' => [
                'done' => true,
                'referer' => $this->refererData(),
            ],
            'pie' => [
                'done' => true,
                'post_pie' => $this->postPieData(),
            ],
            default => throw new \InvalidArgumentException('unknown section: ' . $section),
        };
    }

    /**
     * 概览分段的顺序，控制台按这个顺序逐个请求
     *
     * @return string[]
     */
    public static function overviewSections(): array
    {
        return ['today', 'yesterday', 'total', 'referer', 'pie', 'month'];
    }

    /**
     * 补上图表需要的标题与横轴
     *
     * @access protected
     * @param array $data
     * @param string $kind day / month
     * @return array
     */
    protected function chartOf(array $data, string $kind): array
    {
        $data['sub_title'] = 'Generate By AccessPlugin';
        $count = count($data['ip']['detail'] ?? []);
        if ($kind === 'day') {
            $data['xAxis'] = range(0, $count);
            $data['title'] = _t('%s 统计', $data['time']);
        } else {
            $data['xAxis'] = range(1, max(1, $count));
            $data['title'] = _t('%s 月统计', $data['time']);
        }
        return $data;
    }

    /**
     * 带缓存的单日概览（含小时明细）
     * 历史日期的数据不会再变，可以长期缓存
     *
     * @access protected
     * @param string $date
     * @return array
     * @throws DbException
     */
    protected function cachedDayOverview(string $date): array
    {
        $key = 'overview:dayfull:' . $date;
        $cached = $this->getCache($key);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->queryDayOverview($date);
        if ($date !== date('Y-m-d')) {
            $this->setCache($key, $data, self::PAST_DAY_TTL);
        }
        return $data;
    }

    /**
     * 带缓存的单日汇总（只要 ip/uv/pv 三个数，供月图表使用）
     *
     * @access protected
     * @param string $date
     * @return array
     * @throws DbException
     */
    protected function cachedDayCounts(string $date): array
    {
        $key = 'overview:daycount:' . $date;
        $cached = $this->getCache($key);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->queryDayCounts($date);
        if ($date !== date('Y-m-d')) {
            $this->setCache($key, $data, self::PAST_DAY_TTL);
        }
        return $data;
    }

    /**
     * 带缓存的总计
     *
     * @access protected
     * @return array
     * @throws DbException
     */
    protected function cachedTotalOverview(): array
    {
        $cached = $this->getCache('overview:total');
        if ($cached !== null) {
            return $cached;
        }
        $data = $this->queryTotalOverview();
        $this->setCache('overview:total', $data);
        return $data;
    }

    /**
     * 当月图表分段
     *
     * 逐天计算并逐天缓存。有 Redis 时支持时间预算：跑不完就先返回已完成的部分，
     * 下次请求从缓存里直接拿到已算好的天数继续往下推进。
     * 没有 Redis 时无处存放中间结果，只能一次算完。
     *
     * @access protected
     * @param float|null $deadline
     * @return array
     * @throws DbException
     */
    protected function monthSection(?float $deadline): array
    {
        $year = date('Y');
        $month = date('m');
        $monthDays = (int)date('t');
        $today = (int)date('j');

        $result = ['time' => $month];
        $ready = 0;
        $computed = 0;
        $done = true;

        for ($day = 1; $day <= $monthDays; $day++) {
            if ($day > $today) {
                # 未来的日期直接补 0，不用查库
                $result['ip']['detail'][$day - 1] = 0;
                $result['uv']['detail'][$day - 1] = 0;
                $result['pv']['detail'][$day - 1] = 0;
                $ready++;
                continue;
            }

            $date = sprintf('%s-%s-%02d', $year, $month, $day);
            $cached = $this->getCache('overview:daycount:' . $date);
            $counts = $cached ?? $this->cachedDayCounts($date);
            if ($cached === null) {
                $computed++;
            }

            $result['ip']['detail'][$day - 1] = $counts['ip'];
            $result['uv']['detail'][$day - 1] = $counts['uv'];
            $result['pv']['detail'][$day - 1] = $counts['pv'];
            $ready++;

            /*
             * 只有能把中间结果缓存下来时，中断才有意义。
             * 另外必须至少真算出了一天才允许中断：否则时间预算在「回放缓存」的阶段
             * 就耗尽的话，每次请求都停在同一天，前端会一直请求却毫无进展。
             */
            if ($computed > 0 && $this->redis !== null && $deadline !== null
                && $day < $today && microtime(true) >= $deadline) {
                $done = false;
                break;
            }
        }

        if (!$done) {
            return ['done' => false, 'progress' => $ready, 'total_days' => $monthDays];
        }

        return [
            'done' => true,
            'progress' => $ready,
            'total_days' => $monthDays,
            'month' => $this->chartOf($result, 'month'),
        ];
    }

    /**
     * 来源统计数据（供分段接口使用）
     *
     * @access protected
     * @return array
     * @throws DbException
     */
    protected function refererData(): array
    {
        $this->parseReferer();
        return $this->referer;
    }

    /**
     * 文章饼图数据（供分段接口使用）
     *
     * @access protected
     * @return array
     * @throws DbException
     */
    protected function postPieData(): array
    {
        $this->parsePostPie();
        return $this->postPie;
    }

    /**
     * 生成文章访问量饼图数据（Top N）
     *
     * @return void
     * @throws DbException
     */
    protected function parsePostPie(): void
    {
        $limit = (int)$this->config->pageSize;
        $limit = $limit > 0 ? min($limit, 50) : 20;

        $cacheKey = 'overview:post_pie:top' . $limit;
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            $this->postPie = $cached;
            return;
        }

        // 统计库与内容库可能不是同一个库，无法 JOIN，改为两次查询后在 PHP 中合并
        $counts = $this->fetchContentCounts();
        $meta = $this->fetchContentMeta(array_column($counts, 'cid'));

        $series = [];
        foreach ($counts as $item) {
            $cid = $item['cid'];
            $info = $meta[$cid] ?? null;

            // 只统计文章；页面、附件等不计入，已被删除的内容（查不到）仍然保留
            if ($info !== null && $info['type'] !== 'post') {
                continue;
            }

            $title = $info === null ? '' : trim($info['title']);
            if ($title === '') {
                $title = _t('已删除文章 #%d', $cid);
            }

            $series[] = [
                'cid' => $cid,
                'name' => $title,
                'y' => $item['count'],
            ];

            if (count($series) >= $limit) {
                break;
            }
        }

        $this->postPie = $series;
        $this->setCache($cacheKey, $this->postPie);
    }

    /**
     * 从统计库中取出各内容的访问次数，按次数降序
     *
     * @access protected
     * @return array [['cid' => int, 'count' => int], ...]
     * @throws DbException
     */
    protected function fetchContentCounts(): array
    {
        $rows = $this->db->fetchAll(
            $this->db->select('content_id AS cid', 'COUNT(1) AS count')
                ->from('table.access')
                ->where('content_id IS NOT NULL')
                ->where('content_id <> ?', 0)
                ->group('content_id')
                ->order('count', Db::SORT_DESC)
                // 次数相同时各数据库的返回顺序不一致，补一个稳定的次级排序
                ->order('content_id', Db::SORT_ASC)
        );

        $result = [];
        foreach ($rows as $row) {
            $cid = (int)($row['cid'] ?? 0);
            if ($cid > 0) {
                $result[] = ['cid' => $cid, 'count' => (int)($row['count'] ?? 0)];
            }
        }

        return $result;
    }

    /**
     * 到 Typecho 主库按 cid 批量取内容标题与类型
     *
     * @access protected
     * @param array $cids
     * @return array cid => ['title' => string, 'type' => string]
     * @throws DbException
     */
    protected function fetchContentMeta(array $cids): array
    {
        $cids = array_values(array_unique(array_filter(array_map('intval', $cids))));
        $meta = [];

        foreach (array_chunk($cids, 200) as $chunk) {
            $rows = $this->mainDb->fetchAll(
                $this->mainDb->select('cid', 'title', 'type')
                    ->from('table.contents')
                    ->where('cid IN ?', $chunk)
            );
            foreach ($rows as $row) {
                $meta[(int)$row['cid']] = [
                    'title' => (string)($row['title'] ?? ''),
                    'type' => (string)($row['type'] ?? ''),
                ];
            }
        }

        return $meta;
    }

    /**
     * 获取日志页全部数据（供 AJAX 接口调用）
     *
     * @access public
     * @param int $page 页码
     * @param int $type 类型 1=人类 2=爬虫 3=全部
     * @param string $filter 筛选类型 all/ip/post/path
     * @param string $filterValue 筛选值
     * @return array
     * @throws DbException
     */
    public function getLogsData(int $page, int $type, string $filter, string $filterValue): array
    {
        # 先把队列里积压的写进去，否则最新的访问不会出现在列表里
        $this->flushQueue();

        $offset = (max($page, 1) - 1) * $this->config->pageSize;
        $query = $this->db->select()->from('table.access')
            ->order('time', Db::SORT_DESC)
            ->offset($offset)->limit($this->config->pageSize);
        $qcount = $this->db->select('count(1) AS count')->from('table.access');

        switch ($type) {
            case 1:
                $query->where('robot = ?', 0);
                $qcount->where('robot = ?', 0);
                break;
            case 2:
                $query->where('robot = ?', 1);
                $qcount->where('robot = ?', 1);
                break;
        }

        switch ($filter) {
            case 'ip':
                if (filter_var($filterValue, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $ip = $this->ip62long($filterValue);
                } else {
                    $ip = (string)bindec(decbin((int)ip2long($filterValue)));
                }
                $query->where('ip = ?', $ip);
                $qcount->where('ip = ?', $ip);
                break;
            case 'post':
                // PostgreSQL 对整型列不接受空串等非数字字面量，这里统一转成整型
                $cid = (int)$filterValue;
                $query->where('content_id = ?', $cid);
                $qcount->where('content_id = ?', $cid);
                break;
            case 'path':
                $query->where('path = ?', $filterValue);
                $qcount->where('path = ?', $filterValue);
                break;
        }

        $list = $this->db->fetchAll($query);
        foreach ($list as &$row) {
            if (!empty($row['robot']) && $row['robot'] == 1) {
                $name = $row['robot_id'] ?? '';
                $version = $row['robot_version'] ?? '';
            } else {
                $name = $row['browser_id'] ?? '';
                $version = $row['browser_version'] ?? '';
            }
            if ($name === '' && !empty($row['ua'])) {
                $ua = new UA($row['ua']);
                if ($ua->isRobot()) {
                    $name = $ua->getRobotID();
                    $version = $ua->getRobotVersion();
                } else {
                    $name = $ua->getBrowserName();
                    $version = $ua->getBrowserVersion();
                }
            }
            if ($name == '') {
                $row['display_name'] = _t('Unknown');
            } elseif ($version == '') {
                $row['display_name'] = $name;
            } else {
                $row['display_name'] = $name . ' / ' . $version;
            }
            // 转换 IP 以便前端直接使用
            $row['ip_display'] = $this->long2ip($row['ip']);
        }
        $list = $this->htmlEncode($this->urlDecode($list));
        $rows = (int)$this->db->fetchAll($qcount)[0]['count'];

        $filterArr = ['filter' => $filter];
        if ($filter !== 'all') {
            $filterArr[$filter] = $filterValue;
        }
        $pageObj = new Page($this->config->pageSize, $rows, $page, 10, array_merge($filterArr, [
            'panel' => Plugin::$panel,
            'action' => 'logs',
            'type' => $type,
        ]));

        // 统计库与内容库可能不是同一个库，无法 JOIN，改为两次查询后在 PHP 中合并
        $counts = $this->fetchContentCounts();
        $meta = $this->fetchContentMeta(array_column($counts, 'cid'));
        $cidList = [];
        foreach ($counts as $item) {
            $info = $meta[$item['cid']] ?? null;
            // 对应原来的 INNER JOIN 语义：已删除的内容不出现在筛选下拉框里
            if ($info === null || $info['type'] !== 'post') {
                continue;
            }
            $cidList[] = [
                'cid' => $item['cid'],
                'count' => $item['count'],
                'title' => $info['title'],
            ];
        }

        return [
            'list'    => $list,
            'rows'    => $rows,
            'page'    => $pageObj->show(),
            'cidList' => $cidList,
        ];
    }

    /**
     * 初始化 Redis 连接
     *
     * @access protected
     * @return void
     */
    protected function initRedis(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        if (!isset($this->config->redisCache) || $this->config->redisCache != '1') {
            return;
        }

        /*
         * 上次连接失败后的熔断窗口内直接降级，不再尝试。
         * Redis 不可达时连接会一直等到超时，而本类是在每个前台请求里构造的，
         * 没有这道闸门的话每个访客都要白等一次。
         */
        if (Health::tripped(Health::REDIS)) {
            return;
        }

        try {
            # 连接超时、读写超时统一在 Health::connect() 里设置
            $this->redis = Health::connect(
                $this->config->redisHost ?: '127.0.0.1',
                (int)($this->config->redisPort ?: 6379),
                (string)($this->config->redisAuth ?? '')
            );
            # 连上了就立刻解除熔断，Redis 恢复后不用等窗口自然过期
            Health::clear(Health::REDIS);
        } catch (\Throwable $e) {
            Health::trip(Health::REDIS);
            $this->redis = null;
        }
    }

    /**
     * 从 Redis 获取缓存数据
     *
     * @access protected
     * @param string $key 缓存键名
     * @return array|null 缓存数据，未命中返回 null
     */
    protected function getCache(string $key): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $data = $this->redis->get(Cache::key($key));
            if ($data === false) {
                return null;
            }
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 写入 Redis 缓存
     * 默认 TTL 为距明天 0 点的剩余秒数，可显式指定（历史日期的统计永不变化，可以长期缓存）
     *
     * @access protected
     * @param string $key 缓存键名
     * @param array $data 缓存数据
     * @param int|null $ttl 自定义存活秒数
     * @return void
     */
    protected function setCache(string $key, array $data, ?int $ttl = null): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $ttl = $ttl ?? (86400 - (time() - strtotime(date("Y-m-d 00:00:00"))));
            $this->redis->setex(
                Cache::key($key),
                max($ttl, 1),
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            // 写入失败静默忽略，不影响主流程
        }
    }

    /**
     * 生成来源统计数据，提供给页面渲染使用
     * 优先从 Redis 缓存读取，缓存未命中时查询数据库并回填缓存
     *
     * @access protected
     * @return void
     */
    protected function parseReferer()
    {
        // ── 来源 URL ──
        $cachedUrl = $this->getCache('referer:url');
        if ($cachedUrl !== null) {
            $this->referer['url'] = $cachedUrl;
        } else {
            $this->referer['url'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint AS value, COUNT(1) as count')
                ->from('table.access')->where("entrypoint <> ''")->group('entrypoint')
                ->order('count', Db::SORT_DESC)->limit($this->config->pageSize));
            $this->referer['url'] = $this->htmlEncode($this->urlDecode($this->referer['url']));
            $this->setCache('referer:url', $this->referer['url']);
        }

        // ── 来源域名 ──
        $cachedDomain = $this->getCache('referer:domain');
        if ($cachedDomain !== null) {
            $this->referer['domain'] = $cachedDomain;
        } else {
            $this->referer['domain'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint_domain AS value, COUNT(1) as count')
                ->from('table.access')->where("entrypoint_domain <> ''")->group('entrypoint_domain')
                ->order('count', Db::SORT_DESC)->limit($this->config->pageSize));
            $this->referer['domain'] = $this->htmlEncode($this->urlDecode($this->referer['domain']));
            $this->setCache('referer:domain', $this->referer['domain']);
        }
    }

    /**
     * 生成用于图标的 JSON 数据
     *
     * @access protected
     * @return string
     */
    protected function makeChartJson(): string
    {
        $chart = [];
        foreach ($this->overview as $type => $val) {
            $val['sub_title'] = 'Generate By AccessPlugin';
            if ($type == 'today' || $type == 'yesterday') {
                $val['xAxis'] = range(0, count($val['ip']['detail']));
                $val['title'] = _t('%s 统计', $val['time']);
            } elseif ($type == 'month') {
                $val['xAxis'] = range(1, count($val['ip']['detail']));
                $val['title'] = _t('%s 月统计', $val['time']);
            }
            $chart[$type] = $val;
        }
        return json_encode($chart, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 生成总览数据，提供给页面渲染使用
     * "昨日"、"总计"、"当月" 优先从 Redis 缓存读取；"今日" 始终实时查询
     *
     * @access protected
     * @return void
     */
    protected function parseOverview(): void
    {
        // ── 今日数据：始终实时查询，保证准确性 ──
        $this->overview['today'] = $this->queryDayOverview(date("Y-m-d"));

        // ── 昨日数据：缓存键包含日期，跨天自动失效 ──
        $yesterdayDate = date("Y-m-d", strtotime('-1 day'));
        $yesterdayCacheKey = 'overview:yesterday:' . $yesterdayDate;
        $cached = $this->getCache($yesterdayCacheKey);
        if ($cached !== null) {
            $this->overview['yesterday'] = $cached;
        } else {
            $this->overview['yesterday'] = $this->queryDayOverview($yesterdayDate);
            $this->setCache($yesterdayCacheKey, $this->overview['yesterday']);
        }

        // ── 当月数据：按 TTL 缓存 ──
        $monthCacheKey = 'overview:month:' . date('Y-m');
        $cached = $this->getCache($monthCacheKey);
        if ($cached !== null) {
            $this->overview['month'] = $cached;
        } else {
            $this->overview['month'] = $this->queryMonthOverview();
            $this->setCache($monthCacheKey, $this->overview['month']);
        }

        // ── 总计数据：按 TTL 缓存 ──
        $totalCacheKey = 'overview:total';
        $cached = $this->getCache($totalCacheKey);
        if ($cached !== null) {
            $this->overview['total'] = $cached;
        } else {
            $this->overview['total'] = $this->queryTotalOverview();
            $this->setCache($totalCacheKey, $this->overview['total']);
        }

        # 输出用于图表的Json
        $this->overview['chart_data'] = $this->makeChartJson();
    }

    /**
     * 查询某一天的访问概览（按小时维度 + 当日汇总）
     *
     * @access protected
     * @param string $date 日期，格式 Y-m-d
     * @return array
     */
    protected function queryDayOverview(string $date): array
    {
        $result = ['time' => $date];
        $dayStart = strtotime("{$date} 00:00:00");
        $dayEnd   = strtotime("{$date} 23:59:59");

        # 按小时统计明细
        for ($hour = 0; $hour < 24; $hour++) {
            $start = strtotime("{$date} {$hour}:00:00");
            $end   = strtotime("{$date} {$hour}:59:59");

            $subQuery = $this->db->select('DISTINCT ip')->from('table.access')
                ->where("time >= ? AND time <= ?", $start, $end);
            if (method_exists($subQuery, 'prepare')) {
                $subQuery = $subQuery->prepare($subQuery);
            }
            $result['ip']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

            $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')
                ->where("time >= ? AND time <= ?", $start, $end);
            if (method_exists($subQuery, 'prepare')) {
                $subQuery = $subQuery->prepare($subQuery);
            }
            $result['uv']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

            $result['pv']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                ->from('table.access')->where('time >= ? AND time <= ?', $start, $end))[0]['count'];
        }

        # 当日汇总
        $subQuery = $this->db->select('DISTINCT ip')->from('table.access')
            ->where("time >= ? AND time <= ?", $dayStart, $dayEnd);
        if (method_exists($subQuery, 'prepare')) {
            $subQuery = $subQuery->prepare($subQuery);
        }
        $result['ip']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

        $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')
            ->where("time >= ? AND time <= ?", $dayStart, $dayEnd);
        if (method_exists($subQuery, 'prepare')) {
            $subQuery = $subQuery->prepare($subQuery);
        }
        $result['uv']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

        $result['pv']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('table.access')
            ->where("time >= ? AND time <= ?", $dayStart, $dayEnd)
        )[0]['count'];

        return $result;
    }

    /**
     * 查询当月访问概览（按天维度）
     *
     * @access protected
     * @return array
     */
    protected function queryMonthOverview(): array
    {
        $year  = date('Y');
        $month = date('m');
        // 用 date('t') 而不是 cal_days_in_month()，后者需要 calendar 扩展，结果完全一致
        $monthDays = (int)date('t', mktime(0, 0, 0, (int)$month, 1, (int)$year));
        $result = ['time' => $month];

        for ($day = 1; $day <= $monthDays; $day++) {
            $counts = $this->queryDayCounts(sprintf('%s-%s-%02d', $year, $month, $day));
            $result['ip']['detail'][$day - 1] = $counts['ip'];
            $result['uv']['detail'][$day - 1] = $counts['uv'];
            $result['pv']['detail'][$day - 1] = $counts['pv'];
        }

        return $result;
    }

    /**
     * 查询某一天的汇总（只有 ip / uv / pv 三个数，不含小时明细）
     *
     * 这是概览里可以独立缓存的最小单元：历史日期算过一次就不会再变，
     * 月图表按天拼装，因此某一天算不完也能在下一次请求继续。
     *
     * @access protected
     * @param string $date 日期，格式 Y-m-d
     * @return array {ip: int, uv: int, pv: int}
     * @throws DbException
     */
    protected function queryDayCounts(string $date): array
    {
        $start = strtotime("{$date} 00:00:00");
        $end   = strtotime("{$date} 23:59:59");

        $subQuery = $this->db->select('DISTINCT ip')->from('table.access')
            ->where('time >= ? AND time <= ?', $start, $end);
        if (method_exists($subQuery, 'prepare')) {
            $subQuery = $subQuery->prepare($subQuery);
        }
        $ip = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

        $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')
            ->where('time >= ? AND time <= ?', $start, $end);
        if (method_exists($subQuery, 'prepare')) {
            $subQuery = $subQuery->prepare($subQuery);
        }
        $uv = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $subQuery . ') AS tmp'))[0]['count'];

        $pv = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('table.access')->where('time >= ? AND time <= ?', $start, $end))[0]['count'];

        return ['ip' => $ip, 'uv' => $uv, 'pv' => $pv];
    }

    /**
     * 查询总计访问概览
     *
     * @access protected
     * @return array
     */
    protected function queryTotalOverview(): array
    {
        $result = [];

        $result['ip'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $this->db->select('DISTINCT ip')->from('table.access') . ') AS tmp'))[0]['count'];
        $result['uv'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $this->db->select('DISTINCT ip,ua')->from('table.access') . ') AS tmp'))[0]['count'];
        $result['pv'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('table.access'))[0]['count'];

        return $result;
    }

    /**
     * 编码数组中的字符串为 HTML 实体
     *
     * @param array|string $data 将要被编码的数据
     * @param bool $valuesOnly 是否只编码数组数值
     * @param string $charset 字符串编码方式
     * @return array|string 编码后的数据
     */
    protected function htmlEncode($data, bool $valuesOnly = true, string $charset = 'UTF-8')
    {
        if (is_array($data)) {
            $d = [];
            foreach ($data as $key => $value) {
                if (!$valuesOnly) {
                    $key = $this->htmlEncode($key, $valuesOnly, $charset);
                }
                $d[$key] = $this->htmlEncode($value, $valuesOnly, $charset);
            }
            $data = $d;
        } elseif (is_string($data)) {
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, $charset);
        }
        return $data;
    }

    /**
     * 解析所有 URL 编码过的字符
     *
     * @param array|string $data 将要被解码的数据
     * @param bool $valuesOnly 是否只解码数组数值
     * @return array|string 解码后的数据
     */
    protected function urlDecode($data, bool $valuesOnly = true)
    {
        if (is_array($data)) {
            $d = [];
            foreach ($data as $key => $value) {
                if (!$valuesOnly) {
                    $key = $this->urlDecode($key, $valuesOnly);
                }
                $d[$key] = $this->urlDecode($value, $valuesOnly);
            }
            $data = $d;
        } elseif (is_string($data)) {
            $data = urldecode($data);
        }
        return $data;
    }

    /**
     * 判断是否是管理员登录状态
     *
     * @access public
     * @return bool
     */
    public function isAdmin(): bool
    {
        $hasLogin = User::alloc()->hasLogin();
        if (!$hasLogin) {
            return false;
        }
        return User::alloc()->pass('administrator', true);
    }

    /**
     * 删除记录
     *
     * @access public
     * @param array $ids
     * @return void
     */
    public function deleteLogs(array $ids)
    {
        // PostgreSQL 对整型列不接受非数字字面量，这里统一转成整型
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        /*
         * 删之前先把这些记录的日期取出来：删完就查不到了，
         * 而统计缓存必须按日期失效，否则后台还会一直显示删除前的数字。
         */
        $dates = [];
        if ($this->redis !== null) {
            try {
                $rows = $this->db->fetchAll(
                    $this->db->select('time')->from('table.access')
                        ->where('id IN (' . implode(',', $ids) . ')')
                );
                $dates = Cache::datesOf($rows);
            } catch (\Throwable $e) {
            }
        }

        foreach ($ids as $id) {
            $this->db->query($this->db->delete('table.access')->where('id = ?', $id));
        }

        if ($this->redis !== null) {
            Cache::invalidate($this->redis, $dates);
        }
    }

    /**
     * 获取首次进入网站时的来源
     *
     * @access public
     * @return string
     */
    public function getEntryPoint(): string
    {
        $entrypoint = $this->request->getReferer();
        if ($entrypoint == null) {
            $entrypoint = Cookie::get('__typecho_access_entrypoint') ?: '';
        }
        if (parse_url($entrypoint, PHP_URL_HOST) == parse_url(Helper::options()->siteUrl, PHP_URL_HOST)) {
            $entrypoint = '';
        }
        if ($entrypoint != null) {
            Cookie::set('__typecho_access_entrypoint', $entrypoint);
        }
        return $entrypoint;
    }

    /**
     * IPv6 地址转长字符串
     *
     * @param string $ipv6
     * @return string
     */
    public function ip62long(string $ipv6): string
    {
        $ip_n = inet_pton($ipv6);
        $bits = 15;
        $ipv6long = '';
        while ($bits >= 0) {
            $bin = sprintf("%08b", (ord($ip_n[$bits])));
            $ipv6long = $bin . $ipv6long;
            $bits--;
        }
        return gmp_strval(gmp_init($ipv6long, 2), 10);
    }

    /**
     * 长字符还原 IPv6
     *
     * @param string $ipv6long
     * @return false|string
     */
    public function long2ip6(string $ipv6long)
    {
        $bin = gmp_strval(gmp_init($ipv6long, 10), 2);
        if (strlen($bin) < 128) {
            $pad = 128 - strlen($bin);
            for ($i = 1; $i <= $pad; $i++) {
                $bin = "0" . $bin;
            }
        }
        $ipv6 = '';
        $bits = 0;
        while ($bits <= 7) {
            $bin_part = substr($bin, ($bits * 16), 16);
            $ipv6 .= dechex(bindec($bin_part)) . ":";
            $bits++;
        }
        return inet_ntop(inet_pton(substr($ipv6, 0, -1)));
    }

    /**
     * 记录当前访问（管理员登录不会记录）
     *
     * @access public
     * @return void
     */
    public function writeLogs($archive = null, $url = null, $content_id = null, $meta_id = null)
    {
        if ($this->isAdmin()) {
            return;
        }
        if ($url == null) {
            $url = $this->request->getServer('REQUEST_URI');
        }
        $ip = $this->request->getIp();
        if ($ip == null) {
            $ip = '0.0.0.0';
        }
        // 判断 IP 类型
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = bindec(decbin(ip2long($ip)));
        } else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip = $this->ip62long($ip);
        }

        $entrypoint = $this->getEntryPoint();
        $referer = $this->request->getReferer();
        if (empty($referer)) {
            $referer = '';
        }
        $time = Helper::options()->gmtTime + (Helper::options()->timezone - Helper::options()->serverTimezone);

        if ($archive != null) {
            $parsedArchive = $this->parseArchive($archive);
            $content_id = $parsedArchive['content_id'];
            $meta_id = $parsedArchive['meta_id'];
        } else {
            $content_id = is_numeric($content_id) ? $content_id : null;
            $meta_id = is_numeric($meta_id) ? $meta_id : null;
        }

        $rows = [
            'ua' => $this->ua->getUA(),
            'browser_id' => $this->ua->getBrowserID(),
            'browser_version' => $this->ua->getBrowserVersion(),
            'os_id' => $this->ua->getOSID(),
            'os_version' => $this->ua->getOSVersion(),
            'url' => $url,
            'path' => parse_url($url, PHP_URL_PATH),
            'query_string' => parse_url($url, PHP_URL_QUERY),
            'ip' => $ip,
            'referer' => $referer,
            'referer_domain' => parse_url($referer, PHP_URL_HOST),
            'entrypoint' => $entrypoint,
            'entrypoint_domain' => parse_url($entrypoint, PHP_URL_HOST),
            'time' => $time,
            'content_id' => $content_id,
            'meta_id' => $meta_id,
            'robot' => $this->ua->isRobot() ? 1 : 0,
            'robot_id' => $this->ua->getRobotID(),
            'robot_version' => $this->ua->getRobotVersion(),
            # 在这里生成、随消息一起入队；重试时沿用同一个值，落库才是幂等的。
            # 放到落库时再生成的话，每次重试都是一条「新」记录，唯一索引形同虚设
            'event_id' => Queue::newEventId(),
        ];

        /*
         * 所有字段都来自访客可控的请求头和查询串，先按表结构裁到合法范围。
         * 直写这条路也必须过一遍：Redis 不可用时每次访问都走它，
         * 只在入队时截断的话，恰恰是最脆弱的降级路径没有防护。
         */
        $rows = Queue::normalize($rows);

        # 优先入队，攒批后统一落库；Redis 不可用或入队失败时退回直写
        if (Queue::isEnabled($this->redis, $this->config) && Queue::push($this->redis, $rows)) {
            $this->scheduleFlush();
            return;
        }

        try {
            $this->db->query($this->db->insert('table.access')->rows($rows));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 匿名埋点接口的频率闸门
     *
     * /access/track/flag.gif 无需登录即可调用，不限速的话一个脚本就能
     * 无限灌入日志，既污染统计也放大写入压力。
     * 计数放在 Redis 里；没有 Redis 时无处计数，那就不限（宁可不拦，也不误伤）。
     *
     * @access public
     * @return bool 允许记录返回 true
     */
    public function allowTracking(): bool
    {
        if ($this->redis === null) {
            return true;
        }

        try {
            $ip = $this->request->getIp() ?: '0.0.0.0';
            # 按分钟分桶，键名自带时间戳，过期即天然滚动
            $key = Cache::key('rate:' . date('YmdHi') . ':' . md5($ip));
            $hits = (int)$this->redis->incr($key);
            if ($hits === 1) {
                $this->redis->expire($key, 120);
            }
            return $hits <= self::TRACK_RATE_LIMIT;
        } catch (\Throwable $e) {
            // 限流自己出问题不该把正常统计一起拦下来
            return true;
        }
    }

    /**
     * 达到阈值时安排一次刷库
     *
     * 刷库推迟到响应发出之后执行，承担这次刷库的访客感知不到延迟。
     * 注意不能在这里直接调用 fastcgi_finish_request：writeLogs 是在页面渲染前
     * 由 Widget\Archive::beforeRender 调用的，此时提前结束响应会让页面内容丢失，
     * 所以统一放到 shutdown 阶段。
     *
     * @access protected
     * @return void
     */
    protected function scheduleFlush(): void
    {
        if ($this->flushScheduled || $this->redis === null) {
            return;
        }

        $size = (int)($this->config->queueFlushSize ?? 0);
        $interval = (int)($this->config->queueFlushInterval ?? 0);
        $size = $size > 0 ? $size : 100;
        $interval = $interval > 0 ? $interval : 60;

        if (!Queue::isDue($this->redis, $size, $interval)) {
            return;
        }

        $this->flushScheduled = true;
        $redis = $this->redis;
        $db = $this->db;

        register_shutdown_function(static function () use ($redis, $db) {
            // 页面此时已经输出完毕，先把响应交给访客再慢慢写库
            if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @set_time_limit(0);

            /*
             * 锁在这里才抢，而不是在上面的调度阶段。
             * scheduleFlush() 跑在 beforeRender 里，离真正刷库隔着整个页面渲染和输出，
             * 提前抢锁等于把渲染时间也算进锁的存活期 —— 慢页面上锁很可能在刷到一半时过期，
             * 于是队列上同时出现两个消费者。
             * 代价只是并发请求会多做几次 SET NX，抢不到的直接收工。
             */
            $token = Queue::acquireLock($redis);
            if ($token === null) {
                // 已经有别的请求在刷了
                return;
            }
            try {
                Queue::flush($redis, $db, 0, null, $token);
            } finally {
                Queue::releaseLock($redis, $token);
            }
        });
    }

    /**
     * 同步刷一次队列，供控制台和命令行使用
     *
     * @access public
     * @return int 写入行数
     */
    public function flushQueue(): int
    {
        if (!Queue::isEnabled($this->redis, $this->config)) {
            return 0;
        }
        $token = Queue::acquireLock($this->redis);
        if ($token === null) {
            return 0;
        }
        try {
            # 这里只关心写入行数；完整的 attempted/invalid/rejected/stopped 见 Queue::flush()
            return Queue::flush($this->redis, $this->db, 0, null, $token)['written'];
        } finally {
            Queue::releaseLock($this->redis, $token);
        }
    }

    /**
     * 尽力把队列刷干净，并如实回报最终状态
     *
     * 给「停用插件」这类一次性场景用：那里需要的不是「刷了一次」，而是
     * 「到底还剩没剩」—— flushQueue() 单次最多 FLUSH_LIMIT 条、抢不到锁就返回 0，
     * 拿它的返回值当作「已经刷完了」是靠不住的。
     *
     * @access public
     * @param float $budget 时间预算（秒）
     * @return array{written:int,pending:?int,dead:int,clean:bool,error:?string}
     *         pending 为剩余积压（null 表示读不到）；dead 为死信队列当前长度；
     *         clean 为 true 才代表「Redis 里确实没有未落库的数据了」
     */
    public function drainQueue(float $budget = 25.0): array
    {
        $out = ['written' => 0, 'pending' => null, 'dead' => 0, 'clean' => false, 'error' => null];

        if (!isset($this->config->redisCache) || $this->config->redisCache != '1') {
            # 压根没启用 Redis，自然不存在积压
            $out['pending'] = 0;
            $out['clean'] = true;
            return $out;
        }

        if ($this->redis === null) {
            # 启用了却连不上：既刷不了也确认不了，绝不能当成「干净」
            $out['error'] = 'Redis 不可用，无法确认队列状态';
            return $out;
        }

        if (!Queue::isEnabled($this->redis, $this->config)) {
            $out['pending'] = Queue::tryLength($this->redis);
            $out['dead'] = Queue::deadLength($this->redis);
            # 写入队列被关掉了，但之前攒下的消息可能还在
            $out['clean'] = $out['pending'] === 0 && $out['dead'] === 0;
            return $out;
        }

        $deadline = microtime(true) + max(1.0, $budget);

        # 别的请求可能正在刷，短暂重试而不是立刻放弃
        $token = null;
        while (microtime(true) < $deadline) {
            $token = Queue::acquireLock($this->redis);
            if ($token !== null) {
                break;
            }
            usleep(200000);
        }
        if ($token === null) {
            $out['pending'] = Queue::tryLength($this->redis);
            $out['dead'] = Queue::deadLength($this->redis);
            $out['error'] = '另一个进程正在刷库，未能取得刷库锁';
            return $out;
        }

        try {
            $drained = Queue::drain($this->redis, $this->db, $token, $deadline);
            $out['written'] = $drained['written'];
            $out['error'] = $drained['error'];
        } finally {
            Queue::releaseLock($this->redis, $token);
        }

        $out['pending'] = Queue::tryLength($this->redis);
        $out['dead'] = Queue::deadLength($this->redis);
        $out['clean'] = $out['error'] === null && $out['pending'] === 0 && $out['dead'] === 0;

        return $out;
    }

    /**
     * 队列积压条数
     *
     * @access public
     * @return int
     */
    public function queueLength(): int
    {
        return $this->redis === null ? 0 : Queue::length($this->redis);
    }

    /**
     * 重新刷数据库，当遇到一些算法变更时可能需要用到
     *
     * @access public
     * @return void
     * @throws PluginException
     */
    public static function rewriteLogs()
    {
        $db = Database::get();
        $rows = $db->fetchAll($db->select()->from('table.access'));
        foreach ($rows as $row) {
            $ua = new UA($row['ua']);
            $row['browser_id'] = $ua->getBrowserID();
            $row['browser_version'] = $ua->getBrowserVersion();
            $row['os_id'] = $ua->getOSID();
            $row['os_version'] = $ua->getOSVersion();
            $row['robot'] = $ua->isRobot() ? 1 : 0;
            $row['robot_id'] = $ua->getRobotID();
            $row['robot_version'] = $ua->getRobotVersion();
            try {
                $db->query($db->update('table.access')->rows($row)->where('id = ?', $row['id']));
            } catch (Db\Exception $e) {
                throw new PluginException(_t('刷新数据库失败：%s。', $e->getMessage()));
            }
        }
    }

    /**
     * 解析archive对象
     *
     * @access public
     * @param $archive
     * @return array
     */
    public function parseArchive($archive): array
    {
        $content_id = null;
        $meta_id = null;
        if ($archive->is('index')) {
            $meta_id = 0;
        } elseif ($archive->is('post') || $archive->is('page')) {
            $content_id = $archive->cid;
        } elseif ($archive->is('tag')) {
            if (is_array($archive->tags) && !empty($archive->tags)) {
                $meta_id = $archive->tags[0]['mid'];
            }
        } elseif ($archive->is('category')) {
            if (is_array($archive->categories) && !empty($archive->categories)) {
                $meta_id = $archive->categories[0]['mid'];
            }
        }

        return [
            'content_id' => $content_id,
            'meta_id' => $meta_id,
        ];
    }

    /**
     * 长整型转 IP 地址
     *
     * @param string $long
     * @return false|string
     */
    public function long2ip($long)
    {
        // 不同数据库返回的类型不一致（PostgreSQL 的 bpchar 还会补空格），先归一化为纯数字字符串
        $long = trim((string)$long);
        if ($long === '' || !ctype_digit($long)) {
            return false;
        }
        // 超过 IPv4 上限（4294967295）的一律按 IPv6 处理，不再依赖字段长度
        if (strlen($long) > 10 || (float)$long > 4294967295.0) {
            return $this->long2ip6($long);
        }
        if ($long < 0 || $long > 4294967295) return false;
        $ip = "";
        for ($i = 3; $i >= 0; $i--) {
            $ip .= (int)($long / pow(256, $i));
            $long -= (int)($long / pow(256, $i)) * pow(256, $i);
            if ($i > 0) $ip .= ".";
        }
        return $ip;
    }
}

