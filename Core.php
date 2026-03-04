<?php

namespace TypechoPlugin\Access;

use Redis;
use Typecho\Cookie;
use Typecho\Db;
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
    protected Db $db;
    protected Request $request;
    protected Response $response;

    /** Redis 缓存实例，未启用时为 null */
    protected ?Redis $redis = null;

    /** Redis 缓存键前缀 */
    private const CACHE_PREFIX = 'typecho_access:';

    public UA $ua;
    public $config;
    public string $action;
    public string $title;
    public array $logs = [];
    public array $overview = [];
    public array $referer = [];

    /**
     * 构造函数，根据不同类型的请求，计算不同的数据并渲染输出
     *
     * @access public
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
        $this->db = Db::get();
        $this->config = Options::alloc()->plugin('Access');
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
                $this->parseOverview();
                $this->parseReferer();
                break;
            case 'logs':
            default:
                $this->action = 'logs';
                $this->title = _t('访问日志');
                $this->parseLogs();
                break;
        }
    }

    /**
     * 生成详细访问日志数据，提供给页面渲染使用
     *
     * @access protected
     * @return void
     */
    protected function parseLogs()
    {
        $type = $this->request->get('type', 1);
        $filter = $this->request->get('filter', 'all');
        $pagenum = $this->request->get('page', 1);
        $offset = (max((int)$pagenum, 1) - 1) * $this->config->pageSize;
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
            default:
                break;
        }
        switch ($filter) {
            case 'ip':
                $ip = $this->request->get('ip', '');
                $ip = bindec(decbin(ip2long($ip)));
                $query->where('ip = ?', $ip);
                $qcount->where('ip = ?', $ip);
                break;
            case 'post':
                $cid = $this->request->get('cid', '');
                $query->where('content_id = ?', $cid);
                $qcount->where('content_id = ?', $cid);
                break;
            case 'path':
                $path = $this->request->get('path', '');
                $query->where('path = ?', $path);
                $qcount->where('path = ?', $path);
                break;
        }
        $list = $this->db->fetchAll($query);
        foreach ($list as &$row) {
            // 优先使用已存储的 browser_id/robot_id，避免重复解析 UA
            if (!empty($row['robot']) && $row['robot'] == 1) {
                $name = $row['robot_id'] ?? '';
                $version = $row['robot_version'] ?? '';
            } else {
                $name = $row['browser_id'] ?? '';
                $version = $row['browser_version'] ?? '';
            }
            // 仅在存储字段为空时才回退到 UA 解析
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
        }
        $this->logs['list'] = $this->htmlEncode($this->urlDecode($list));

        $this->logs['rows'] = $this->db->fetchAll($qcount)[0]['count'];

        $filter = $this->request->get('filter', 'all');
        $filterOptions = $this->request->get($filter);
        $filterArr = [
            'filter' => $filter,
            $filter => $filterOptions
        ];

        $page = new Page($this->config->pageSize, $this->logs['rows'], $pagenum, 10, array_merge($filterArr, [
            'panel' => Plugin::$panel,
            'action' => 'logs',
            'type' => $type,
        ]));
        $this->logs['page'] = $page->show();

        $this->logs['cidList'] = $this->db->fetchAll($this->db->select('DISTINCT content_id as cid, COUNT(1) as count, table.contents.title as title')
                ->from('table.access')
                ->join('table.contents', 'table.access.content_id = table.contents.cid')
                ->where('table.access.content_id IS NOT NULL')
                ->where('table.contents.type = ?', 'post')
                ->group('table.access.content_id')
                ->group('table.contents.title')
                ->order('count', Db::SORT_DESC));
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

        if (!isset($this->config->enableRedis) || $this->config->enableRedis != '1') {
            return;
        }

        try {
            $redis = new Redis();
            $host = $this->config->redisHost ?: '127.0.0.1';
            $port = (int)($this->config->redisPort ?: 6379);

            if (!$redis->connect($host, $port, 3)) {
                return;
            }

            $password = $this->config->redisPassword ?? '';
            if ($password !== '') {
                $redis->auth($password);
            }

            $redis->ping();
            $this->redis = $redis;
        } catch (\Exception $e) {
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
            $data = $this->redis->get(self::CACHE_PREFIX . $key);
            if ($data === false) {
                return null;
            }
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 写入 Redis 缓存
     *
     * @access protected
     * @param string $key 缓存键名
     * @param array $data 缓存数据
     * @return void
     */
    protected function setCache(string $key, array $data): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $ttl = (int)($this->config->redisTtl ?: 300);
            $this->redis->setex(
                self::CACHE_PREFIX . $key,
                $ttl,
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Exception $e) {
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
        // 尝试从 Redis 缓存读取
        $cached = $this->getCache('referer');
        if ($cached !== null) {
            $this->referer = $cached;
            return;
        }

        // 缓存未命中，从数据库查询
        $this->referer['url'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint AS value, COUNT(1) as count')
                ->from('table.access')->where("entrypoint <> ''")->group('entrypoint')
                ->order('count', Db::SORT_DESC)->limit($this->config->pageSize));
        $this->referer['domain'] = $this->db->fetchAll($this->db->select('DISTINCT entrypoint_domain AS value, COUNT(1) as count')
                ->from('table.access')->where("entrypoint_domain <> ''")->group('entrypoint_domain')
                ->order('count', Db::SORT_DESC)->limit($this->config->pageSize));
        $this->referer = $this->htmlEncode($this->urlDecode($this->referer));

        // 写入 Redis 缓存
        $this->setCache('referer', $this->referer);
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
     *
     * @access protected
     * @return void
     */
    protected function parseOverview(): void
    {
        $types = ['today', 'yesterday', 'month'];
        # 分类分时段统计数据
        foreach ($types as $type) {
            if ($type == 'today' || $type == 'yesterday') {
                if ($type == 'today')
                    $time = date("Y-m-d");
                else
                    $time = date("Y-m-d", strtotime('-1 day'));
                $this->overview[$type]['time'] = $time;

                # 按小时统计数据
                for ($hour = 0; $hour < 24; $hour++) {
                    $start = strtotime(date("{$time} {$hour}:00:00"));
                    $end = strtotime(date("{$time} {$hour}:59:59"));
                    $subQuery = $this->db->select('DISTINCT ip')->from('table.access')
                        ->where("time >= ? AND time <= ?", $start, $end);
                    if (method_exists($subQuery, 'prepare')) {
                        $subQuery = $subQuery->prepare($subQuery);
                    }
                    $this->overview[$type]['ip']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('(' . $subQuery . ') AS tmp'))[0]['count'];
                    $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')
                        ->where("time >= ? AND time <= ?", $start, $end);
                    if (method_exists($subQuery, 'prepare')) {
                        $subQuery = $subQuery->prepare($subQuery);
                    }
                    $this->overview[$type]['uv']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('(' . $subQuery . ') AS tmp'))[0]['count'];
                    $this->overview[$type]['pv']['detail'][$hour] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('table.access')->where('time >= ? AND time <= ?', $start, $end))[0]['count'];
                }

                # 统计当天总数据
                $start = strtotime(date("{$time} 00:00:00"));
                $end = strtotime(date("{$time} 23:59:59"));

                $subQuery = $this->db->select('DISTINCT ip')->from('table.access')->where("time >= ? AND time <= ?", $start, $end);
                if (method_exists($subQuery, 'prepare')) {
                    $subQuery = $subQuery->prepare($subQuery);
                }
                $this->overview[$type]['ip']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')->from('(' . $subQuery . ') AS tmp'))[0]['count'];

                $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')->where("time >= ? AND time <= ?", $start, $end);
                if (method_exists($subQuery, 'prepare')) {
                    $subQuery = $subQuery->prepare($subQuery);
                }
                $this->overview[$type]['uv']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')->from('(' . $subQuery . ') AS tmp'))[0]['count'];

                $this->overview[$type]['pv']['count'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                    ->from('table.access')
                    ->where("time >= ? AND time <= ?", $start, $end)
                )[0]['count'];
            } elseif ($type == 'month') {
                $year = date('Y');
                $month = date("m");
                $monthDays = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
                $this->overview[$type]['time'] = $month;

                # 按天统计数据
                for ($day = 1; $day <= $monthDays; $day++) {
                    $start = strtotime(date("{$year}-{$month}-{$day} 00:00:00"));
                    $end = strtotime(date("{$year}-{$month}-{$day} 23:59:59"));

                    $subQuery = $this->db->select('DISTINCT ip')->from('table.access')
                        ->where('time >= ? AND time <= ?', $start, $end);
                    if (method_exists($subQuery, 'prepare')) {
                        $subQuery = $subQuery->prepare($subQuery);
                    }
                    $this->overview[$type]['ip']['detail'][$day-1] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('(' . $subQuery . ') AS tmp'))[0]['count'];
                    $subQuery = $this->db->select('DISTINCT ip,ua')->from('table.access')
                        ->where('time >= ? AND time <= ?', $start, $end);
                    if (method_exists($subQuery, 'prepare')) {
                        $subQuery = $subQuery->prepare($subQuery);
                    }
                    $this->overview[$type]['uv']['detail'][$day-1] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('(' . $subQuery . ') AS tmp'))[0]['count'];
                    $this->overview[$type]['pv']['detail'][$day-1] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
                        ->from('table.access')->where('time >= ? AND time <= ?', $start, $end))[0]['count'];
                }
            }
        }

        # 统计总计数据
        $this->overview['total']['ip'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $this->db->select('DISTINCT ip')->from('table.access') . ') AS tmp'))[0]['count'];
        $this->overview['total']['uv'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('(' . $this->db->select('DISTINCT ip,ua')->from('table.access') . ') AS tmp'))[0]['count'];
        $this->overview['total']['pv'] = (int)$this->db->fetchAll($this->db->select('COUNT(1) AS count')
            ->from('table.access'))[0]['count'];

        # 输出用于图表的Json
        $this->overview['chart_data'] = $this->makeChartJson();
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
        foreach ($ids as $id) {
            $this->db->query($this->db->delete('table.access')
                    ->where('id = ?', $id)
            );
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
        ];

        try {
            $this->db->query($this->db->insert('table.access')->rows($rows));
        } catch (\Exception $e) {
        }
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
        $db = Db::get();
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
        $len = trim(strlen($long));
        if ($len == 38) {
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

