<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class UA
{
    private static array $robots = [
        'Alexa (IA Archiver)',
        'AppEngine-Google',
        'Ask',
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
        'Go-http-client',
        'Google AdSense',
        'Googlebot',
        'Heritrix',
        'Java (Often spam bot)',
        'MJ12bot',
        'MSIECrawler',
        'MSNBot',
        'Netcraft',
        'Nimbostratus-Bot',
        'Nutch',
        'OutfoxBot/YodaoBot',
        'Perl tool',
        'Presto',
        'Python-urllib',
        'python-httpx',
        'Reeder',
        'Scrapy',
        'Sogou Spider',
        'Sogou inst spider',
        'Sogou web spider',
        'Sosospider+',
        'Speedy Spider',
        'StackRambler',
        'SurveyBot',
        'TencentTraveler',
        'Tiny Tiny RSS',
        'UptimeRobot',
        'Voila',
        'WGet tools',
        'WordPress',
        'Yahoo Slurp',
        'Yahoo! Slurp',
        'Yandex bot',
        'YandexBot',
        'YisouSpider',
        'YoudaoBot',
        'aiohttp',
        'bingbot',
        'crawler',
        'gce-spider',
        'ia_archiver',
        'inoreader',
        'larbin',
        'legs',
        'lwp-trivial',
        'msnbot',
        'python-requests',
        'twiceler',
        'yacy',
        'zgrab',
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

    public function isRobot(): bool
    {
        if ($this->robotID === null) {
            if (!empty($this->ua)) {
                if (preg_match('#([a-zA-Z0-9]+\s*(?:-?bot|spider|-?client|-?User))[ /v]*([0-9.]*)#i', $this->ua, $matches)) {
                    $this->robotID = $this->robotName = $matches[1];
                    $this->robotVersion = $matches[2];
                }
                foreach (self::$robots as $val) {
                    if (str_contains($this->ual, self::filter($val))) {
                        $this->robotID = $this->robotName = $val;
                    }
                }
            }
            if ($this->robotID === null) $this->robotID = '';
            if ($this->robotName === null) $this->robotName = '';
            if ($this->robotVersion === null) $this->robotVersion = '';
        }
        return $this->robotID !== '';
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

