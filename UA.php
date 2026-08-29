<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class UA
{
    /** UA 为空或只是个占位符时记的名字 */
    public const NO_UA = '(no user-agent)';

    /** 浏览器和操作系统都认不出来时记的名字 */
    public const UNIDENTIFIED = '(unidentified)';

    /**
     * 已知的命令行工具 / HTTP 客户端 / 扫描器：匹配串 => 记进 robot_id 的名字
     *
     * 单独一张表而不是塞进 $robots，是因为这些要**排在名单前面**判：
     * 名单里有 Custo、Ask 这种短词，遇到长文本 UA 容易先命中并盖掉真正的名字。
     * 实测 Palo Alto Expanse 那条长 UA 就被 Custo 命中过（来自 customers 一词）。
     */
    private static array $tools = [
        'curl'               => 'curl',
        'Wget'               => 'Wget',
        'libwww-perl'        => 'libwww-perl',
        'lwp-trivial'        => 'lwp-trivial',
        'python-requests'    => 'python-requests',
        'python-urllib'      => 'python-urllib',
        'python-httpx'       => 'python-httpx',
        'aiohttp'            => 'aiohttp',
        'httpx'              => 'httpx',
        'Go-http-client'     => 'Go-http-client',
        'okhttp'             => 'okhttp',
        'axios'              => 'axios',
        'node-fetch'         => 'node-fetch',
        'GuzzleHttp'         => 'GuzzleHttp',
        'Apache-HttpClient'  => 'Apache-HttpClient',
        'RestSharp'          => 'RestSharp',
        'PostmanRuntime'     => 'PostmanRuntime',
        'Java'               => 'Java',
        'Scrapy'             => 'Scrapy',
        'HTTrack'            => 'HTTrack',
        'WinHttp'            => 'WinHttp',
        'masscan'            => 'masscan',
        'zgrab'              => 'zgrab',
        'zmap'               => 'zmap',
        'Nmap'               => 'Nmap',
        'Nuclei'             => 'Nuclei',
        'sqlmap'             => 'sqlmap',
        'Nikto'              => 'Nikto',
        'WPScan'             => 'WPScan',
        'CensysInspect'      => 'CensysInspect',
        'InternetMeasurement' => 'InternetMeasurement',
        'Expanse'            => 'Expanse',
        'HeadlessChrome'     => 'HeadlessChrome',
        'PhantomJS'          => 'PhantomJS',
    ];

    /**
     * 收录的爬虫名单，按词边界匹配（见 wordPattern()）
     *
     * 移除过的条目，别再加回来：
     *   Presto           —— Opera 12 的排版引擎，真实浏览器
     *   TencentTraveler  —— 腾讯 TT 浏览器，真实浏览器
     *   Ask              —— 三个字母，会命中 task、Basketball 之类；换成 AskJeeves / Teoma
     * 括号里写说明的条目（Alexa (IA Archiver)、Java (Often spam bot)）也删了：
     * 那些括号是给人看的注释，却被当成匹配串的一部分，永远匹配不到任何真实 UA。
     * 命令行工具挪进了 $tools。
     */
    private static array $robots = [
        'AppEngine-Google',
        'AskJeeves',
        'BSpider',
        'BaiDuSpider',
        'Baiduspider',
        'BingPreview',
        'ChatGPT-User',
        'Custo',
        'DNSPod-Monitor',
        'DuckDuckBot',
        'DuckDuckGo-Favicons-Bot',
        'Exabot',
        'Fish search',
        'GigaExplorator',
        'Google AdSense',
        'Googlebot',
        'Heritrix',
        'MJ12bot',
        'MSIECrawler',
        'MSNBot',
        'Netcraft',
        'Nimbostratus-Bot',
        'Nutch',
        'OutfoxBot/YodaoBot',
        'Reeder',
        'Sogou Spider',
        'Sogou inst spider',
        'Sogou web spider',
        'Sosospider',
        'Speedy Spider',
        'StackRambler',
        'SurveyBot',
        'Teoma',
        'Tiny Tiny RSS',
        'UptimeRobot',
        'Voila',
        'WordPress',
        'Yahoo Slurp',
        'Yahoo! Slurp',
        'Yandex bot',
        'YandexBot',
        'YisouSpider',
        'YoudaoBot',
        'bingbot',
        'crawler',
        'gce-spider',
        'ia_archiver',
        'inoreader',
        'larbin',
        'legs',
        'msnbot',
        'twiceler',
        'yacy',
    ];

    private string $ua;
    private string $ual;

    private ?string $osID = null;
    private ?string $osName = null;
    private ?string $osVersion = null;

    private ?string $robotID = null;
    private ?string $robotName = null;
    private ?string $robotVersion = null;

    private ?string $browserID = null;
    private ?string $browserName = null;
    private ?string $browserVersion = null;

    public function __construct(?string $ua)
    {
        $this->ua = $ua ?? '';
        $this->ual = self::filter($ua);
    }

    public static function filter(?string $str): string
    {
        return self::removeSpace(strtolower($str ?: ""));
    }

    protected static function removeSpace(string $str): string
    {
        return preg_replace('/\s+/', '', $str);
    }

    public function getUA(): string
    {
        return $this->ua;
    }

    /**
     * 这次访问是不是机器人
     *
     * 判定顺序从「最确定」到「最兜底」，任一命中即为机器人：
     *   1. 已知工具名（curl / wget / masscan / python-requests …），按词边界匹配
     *   2. 收录的爬虫名单，按词边界匹配
     *   3. 名字里带 bot / spider / client / User 的（原有正则，名单没收录时兜底）
     *   4. UA 里写了 URL 或邮箱 —— 爬虫界的长期惯例是把联系方式写进 UA，
     *      真实浏览器几乎不会这么做
     *   5. UA 为空或只是个占位符 —— 真实浏览器一定会发 UA
     *   6. **兜底：既认不出浏览器、也认不出操作系统**
     *
     * 第 6 条是这次补上的关键一条。以前前五条（当时只有两条）都不命中就直接算人类，
     * 于是像
     *   Hello from Palo Alto Networks, find out more about our scans in https://…
     * 这样的扫描器、以及 curl / wget / 空 UA，全都被记成了真人访问。
     * 「认不出来」这件事本身就是信息：真实浏览器的 UA 一定能给出浏览器或操作系统特征，
     * 两样都给不出的，绝大多数是脚本。宁可把小众浏览器误记成机器人，
     * 也好过让扫描器混在人类流量里把统计数字撑起来 —— 前者看一眼 UA 就能发现，
     * 后者会安静地让每一个数字都偏高。
     *
     * @return bool
     */
    public function isRobot(): bool
    {
        if ($this->robotID === null) {
            $this->detectRobot();
        }
        return $this->robotID !== '';
    }

    /**
     * 跑一遍判定，把结果写进 robotID / robotName / robotVersion
     *
     * @return void
     */
    private function detectRobot(): void
    {
        $this->robotID = $this->robotName = $this->robotVersion = '';

        $ua = trim($this->ua);

        # 5. 空 UA 或占位符（"-"、"."、"unknown" 这类），真实浏览器不会这样
        if ($ua === '' || preg_match('/^[\s\-._~+*?]*$/', $ua) || strcasecmp($ua, 'unknown') === 0) {
            $this->robotID = $this->robotName = self::NO_UA;
            return;
        }

        # 1. 已知工具：最确定，也最该排在名单前面（名单里有短词，容易被长文本误命中）
        foreach (self::$tools as $needle => $label) {
            if (preg_match(self::wordPattern($needle), $ua)) {
                $this->robotID = $this->robotName = $label;
                # 工具名后面常跟 /版本
                if (preg_match('#' . preg_quote($needle, '#') . '[/ v]*([0-9][0-9._]*)#i', $ua, $v)) {
                    $this->robotVersion = rtrim($v[1], '.');
                }
                return;
            }
        }

        /*
         * 2. 收录的爬虫名单。
         *
         * 排在下面那条通用正则**前面**：正则抓的是「紧挨着 bot/spider 的那一个词」，
         * 名字常常只截到一半 —— Sogou web spider/4.0 会被记成 "web spider"。
         * 名单给的是完整名字，能匹配上就优先用它。
         * （名单现在按词边界匹配，不再有 Custo 命中 customers 那类问题，可以放心提前。）
         */
        foreach (self::$robots as $val) {
            if (preg_match(self::wordPattern($val), $ua)) {
                $this->robotID = $this->robotName = $val;
                if (preg_match('#' . preg_quote($val, '#') . '[/ v]*([0-9][0-9._]*)#i', $ua, $v)) {
                    $this->robotVersion = rtrim($v[1], '.');
                }
                return;
            }
        }

        # 3. 名字里带 bot / spider / client / User 的，名单没收录时兜一下
        if (preg_match('#([a-zA-Z0-9]+\s*(?:-?bot|spider|-?client|-?User))[ /v]*([0-9.]*)#i', $ua, $matches)) {
            $this->robotID = $this->robotName = $matches[1];
            $this->robotVersion = $matches[2];
            return;
        }

        /*
         * 4. UA 里带 URL 或邮箱。
         *
         * 「把联系方式写进 UA」是爬虫二十多年的惯例（+http://… 这种写法），
         * 目的就是让站长知道是谁在抓、去哪儿投诉。真实浏览器没有这个需要。
         * 名字取 URL 的主机名 / 邮箱的域名 —— 它正好标识了运营方，
         * 比记一个「未知」有用得多，同一家扫描器的记录也能聚到一起。
         */
        if (preg_match('#https?://([^\s/;,)\]<>"\']+)#i', $ua, $m)) {
            $this->robotID = $this->robotName = self::hostLabel($m[1]);
            return;
        }
        if (preg_match('/[\w.+-]+@([\w-]+(?:\.[\w-]+)+)/', $ua, $m)) {
            $this->robotID = $this->robotName = self::hostLabel($m[1]);
            return;
        }

        /*
         * 6. 兜底：浏览器和操作系统都认不出来。
         *
         * 放在最后，因为它最容易误伤 —— 覆盖率完全取决于 parseBrowser() / parseOS()
         * 认得全不全。新增小众浏览器的识别规则，会自动减少这一类的误判。
         */
        if (!$this->parseBrowser() && !$this->parseOS()) {
            $this->robotID = $this->robotName = self::UNIDENTIFIED;
        }
    }

    /**
     * 把一个名字编成「带词边界」的正则
     *
     * 原来是 str_contains(去空格小写的 UA, 去空格小写的名字)，没有边界，实测误判：
     *   - 名单里的 Custo 命中了长文本 UA 里的 customers
     *   - Ask 会命中任何含 ask 的串（task、Basketball…）
     * 前后各加一个「不能紧挨字母数字」的断言就没有这个问题；
     * 名字里的空格放宽成 \s*，这样 "Sogou web spider" 不同写法都能对上。
     *
     * @param string $needle
     * @return string
     */
    private static function wordPattern(string $needle): string
    {
        $parts = preg_split('/\s+/', trim($needle));
        $body = implode('\s*', array_map(static fn(string $p): string => preg_quote($p, '/'), $parts));
        return '/(?<![a-z0-9])' . $body . '(?![a-z0-9])/i';
    }

    /**
     * 主机名 / 域名收拾成适合当标识的样子
     *
     * @param string $host
     * @return string
     */
    private static function hostLabel(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $host = preg_replace('/^www\./', '', $host);
        # robot_id 列是 varchar(32)，太长的从右边留（右边是更有辨识度的主域名）
        return strlen($host) > 32 ? substr($host, -32) : $host;
    }

    public function getRobotID(): string
    {
        return $this->isRobot() ? $this->robotID : '';
    }

    public function getRobotVersion(): string
    {
        return $this->isRobot() ? $this->robotVersion : '';
    }

    private function parseOS(): bool
    {
        if ($this->osID === null) {
            if (preg_match('/Windows NT 6.0/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = 'Vista';
            } elseif (preg_match('/Windows NT 6.1/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = '7';
            } elseif (preg_match('/Windows NT 6.2/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = '8';
            } elseif (preg_match('/Windows NT 6.3/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = '8.1';
            } elseif (preg_match('/Windows NT 10.0/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = '10';
            } elseif (preg_match('/Windows NT 5.0/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = '2000';
            } elseif (preg_match('/Windows NT 5.1/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                $this->osVersion = 'XP';
            } elseif (preg_match('/Windows NT 5.2/i', $this->ua)) {
                $this->osID = $this->osName = 'Windows';
                if (preg_match('/Win64/i', $this->ua)) {
                    $this->osVersion = 'XP (64 bit)';
                } else {
                    $this->osVersion = '2003';
                }
            } elseif (preg_match('/Android ([0-9.]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'Android';
                $this->osVersion = $matches[1];
            } elseif (preg_match('/iPhone OS ([_0-9]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'iPhone OS';
                $this->osVersion = str_replace('_', '.', $matches[1]);
            } elseif (preg_match('/iPad; CPU OS ([_0-9]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'iPad OS';
                $this->osVersion = str_replace('_', '.', $matches[1]);
            } elseif (preg_match('/Mac OS X ([0-9_]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'Mac OS X';
                $this->osVersion = str_replace('_', '.', $matches[1]);
            } elseif (preg_match('/Linux/i', $this->ua)) {
                $this->osID = $this->osName = 'Linux';
                $this->osVersion = '';
            } elseif (preg_match('/Ubuntu/i', $this->ua)) {
                $this->osID = $this->osName = 'Ubuntu';
                $this->osVersion = '';
            } elseif (preg_match('/CrOS i686 ([a-zA-Z0-9.]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'Chrome OS';
                $this->osVersion = 'i686 ' . substr($matches[1], 0, 4);
            } elseif (preg_match('/CrOS x86_64 ([a-zA-Z0-9.]+)/i', $this->ua, $matches)) {
                $this->osID = $this->osName = 'Chrome OS';
                $this->osVersion = 'x86_64 ' . substr($matches[1], 0, 4);
            } else {
                $this->osID = '';
                $this->osName = '';
                $this->osVersion = '';
            }
        }
        return $this->osID !== '' || $this->osName !== '';
    }

    public function getOSID(): string
    {
        return $this->parseOS() ? $this->osID : '';
    }

    public function getOSName(): string
    {
        return $this->parseOS() ? $this->osName : '';
    }

    public function getOSVersion(): string
    {
        return $this->parseOS() ? $this->osVersion : '';
    }

    private function parseBrowser(): bool
    {
        if ($this->browserName === null) {
            if (preg_match('#SE 2([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'SE2';
                $this->browserName = '搜狗浏览器 2';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Mb2345Browser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = '2345Browser';
                $this->browserName = '2345Browser';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#SogouMobileBrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'SogouMobileBrowser';
                $this->browserName = '搜狗浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#baiduboxapp/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'BaiduBoxApp';
                $this->browserName = '手机百度';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#LieBaoFast/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'LieBaoFast';
                $this->browserName = '猎豹浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#baidubrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'BaiduBrowser';
                $this->browserName = '百度浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#MicroMessenger/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'WeChat';
                $this->browserName = 'WeChat';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#OPRGX/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Opera GX';
                $this->browserName = 'Opera GX';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#FxiOS/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'FxiOS';
                $this->browserName = 'Firefox Focus';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#2345Explorer/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = '2345E';
                $this->browserName = '2345Explorer';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#OPR/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'OPR';
                $this->browserName = 'Opera';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#OPT/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'OPT';
                $this->browserName = 'Opera Touch';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Vivaldi/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Vivaldi';
                $this->browserName = 'Vivaldi';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#(MQQBrowser|QQBrowser)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'QQBrowser';
                $this->browserName = 'QQ浏览器';
                $this->browserVersion = $matches[2];
            } elseif (preg_match('#QQ/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'MobileQQ';
                $this->browserName = '手机QQ';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Thunder/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'ThunderX';
                $this->browserName = '迅雷X';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Qiyu/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Qiyu';
                $this->browserName = '旗鱼浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#YaBrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Yandex';
                $this->browserName = 'Yandex';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#UCTurbo/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'UCTurbo';
                $this->browserName = 'UCTurbo';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#(UCBrowser|UBrowser|UCWEB)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'UCBrowser';
                $this->browserName = 'UC浏览器';
                $this->browserVersion = $matches[2];
            } elseif (preg_match('#MailMaster/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'MailMaster';
                $this->browserName = '网易邮箱大师';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Quark/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Quark';
                $this->browserName = 'Quark';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#SamsungBrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'SamsungBrowser';
                $this->browserName = '三星浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#SogouSearch.*/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'SogouSearch';
                $this->browserName = '搜狗搜索';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Maxthon([\s/])([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'Maxthon';
                $this->browserName = 'Maxthon';
                $this->browserVersion = $matches[2];
            } elseif (preg_match('#XiaoMi/MiuiBrowser/([0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = 'MiuiBrowser';
                $this->browserName = '小米浏览器';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#(Edg|Edge|EdgA|EdgiOS)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = $this->browserName = 'Edge';
                $this->browserVersion = $matches[2];
            } elseif (preg_match('#Chrome/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = $this->browserName = 'Chrome';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Safari/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = $this->browserName = 'Safari';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#MSIE ([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = $this->browserName = 'Internet Explorer';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#Trident#', $this->ua)) {
                $this->browserID = $this->browserName = 'Internet Explorer';
                $this->browserVersion = '11';
            } elseif (preg_match('#(Firefox|Fenix|Phoenix|Firebird|BonEcho|GranParadiso|Minefield|Iceweasel)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                $this->browserID = $this->browserName = 'Firefox';
                $this->browserVersion = $matches[2];
            } else {
                $this->browserID = '';
                $this->browserName = '';
                $this->browserVersion = '';
            }
        }
        return $this->browserID !== '' || $this->browserName !== '';
    }

    public function getBrowserID(): string
    {
        return $this->parseBrowser() ? $this->browserID : '';
    }

    public function getBrowserName(): string
    {
        return $this->parseBrowser() ? $this->browserName : '';
    }

    public function getBrowserVersion(): string
    {
        return $this->parseBrowser() ? $this->browserVersion : '';
    }
}

