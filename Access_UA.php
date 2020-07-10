<?php
if (!defined('__ACCESS_PLUGIN_ROOT__')) {
	throw new Exception('Boostrap file not found');
}

class Access_UA {
	private static $robots = array(
		'DuckDuckBot',              // DuckDuckGo
		'DuckDuckGo-Favicons-Bot',  // DuckDuckGo Favicons
		'gce-spider',               // Google App Engine Software
		'YisouSpider',              // 宜搜
		'YandexBot',                // Yandex
		'UptimeRobot',              // UptimeRobot
		'Nimbostratus-Bot',
		'Presto',                   // SQL 注入程序
		'TencentTraveler',
		'Baiduspider',
		'BaiduGame',
		'Googlebot',
		'BingPreview',              // 必应预览
		'msnbot',
		'Sosospider+',
		'Sogou web spider',
		'Sogou inst spider',
		'ia_archiver',              // Alexa Bot
		'Yahoo! Slurp',
		'YoudaoBot',
		'Yahoo Slurp',
		'MSNBot',
		'Java (Often spam bot)',
		'BaiDuSpider',
		'Voila',
		'Yandex bot',
		'BSpider',
		'twiceler',
		'Sogou Spider',
		'Speedy Spider',
		'Google AdSense',
		'Heritrix',
		'Python-urllib',
		'Alexa (IA Archiver)',
		'Ask',
		'Exabot',
		'Custo',
		'OutfoxBot/YodaoBot',
		'yacy',
		'SurveyBot',
		'legs',
		'lwp-trivial',
		'Nutch',
		'StackRambler',
		'The web archive (IA Archiver)',
		'Perl tool',
		'MJ12bot',
		'Netcraft',
		'MSIECrawler',
		'WGet tools',
		'larbin',
		'Fish search',
		'crawler',
		'bingbot',
		'Go-http-client',
		'zgrab',
		'WordPress',
		'Scrapy',
	);

	private $ua;
	private $ual;
	
	private $osID = null;
	private $osName	= null;
	private $osVersion = null;
	
	private $robotID = null;
	private $robotName = null;
	private $robotVersion = null;
	
	private $browserID = null;
	private $browserName = null;
	private $browserVersion = null;

	function __construct($ua) {
		$this->ua = $ua;
		$this->ual = $this->filter($ua);
	}

	public static function filter($str) {
		return self::removeSpace(strtolower($str));
	}

	protected static function removeSpace($str) {
		return preg_replace('/\s+/', '', $str);
	}

	/**
	 * 获取完整UA信息
	 *
	 * @access public
	 * @return string
	 */
	public function getUA() {
		return $this->ua;
	}

	/**
	 * 获取是否是爬虫
	 *
	 * @access public
	 * @return bool
	 */
	public function isRobot() {
		if ($this->robotID === null) {
			if (!empty($this->ua)) {
				if (preg_match('#([a-zA-Z0-9]+\s*(?:-?bot|spider|-?client))[ /v]*([0-9.]*)#i', $this->ua, $matches)) {
					$this->robotID = $this->robotName = $matches[1];
					$this->robotVersion = $matches[2];
				}
				foreach (self::$robots as $val) {
					if (strpos($this->ual, $this->filter($val)) !== false) {
						$this->robotID = $this->robotName = $val;
					}
				}
			}
			if ($this->robotID		== null) $this->robotID			= '';
			if ($this->robotName	== null) $this->robotName		= '';
			if ($this->robotVersion	== null) $this->robotVersion	= '';
		}
		return $this->robotID !== '';
	}

	/**
	 * 获取爬虫ID
	 *
	 * @access public
	 * @return string
	 */
	public function getRobotID() {
		return $this->isRobot() ? $this->robotID : '';
	}

	/**
	 * 获取爬虫版本
	 *
	 * @access public
	 * @return string
	 */
	public function getRobotVersion() {
		return $this->isRobot() ? $this->robotVersion : '';
	}

	/**
	 * 解析操作系统信息
	 *
	 * @access private
	 * @return bool
	 */
	private function parseOS() {
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
			} elseif (preg_match('/Linux/i', $this->ua, $matches)) {
				$this->osID = $this->osName = 'Linux';
				$this->osVersion = '';
			} elseif (preg_match('/Ubuntu/i', $this->ua, $matches)) {
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

	/**
	 * 获取操作系统ID
	 *
	 * @access public
	 * @return string
	 */
	public function getOSID() {
		return $this->parseOS() ? $this->osID : '';
	}

	/**
	 * 获取操作系统名字
	 *
	 * @access public
	 * @return string
	 */
	public function getOSName() {
		return $this->parseOS() ? $this->osName : '';
	}

	/**
	 * 获取操作系统版本号
	 *
	 * @access public
	 * @return string
	 */
	public function getOSVersion() {
		return $this->parseOS() ? $this->osVersion : '';
	}

	/**
	 * 解析浏览器信息
	 *
	 * @access private
	 * @return bool
	 */
	private function parseBrowser() {
		if ($this->browserName === null) {
			if (preg_match('#SE 2([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Sogou Browser PC
				$this->browserID = 'SE2';
				$this->browserName = '搜狗浏览器 2';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#SogouMobileBrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Sogou Browser Android
				$this->browserID = 'SogouMobileBrowser';
				$this->browserName = '搜狗浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#baiduboxapp/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// BaiduApp Android / iOS
				$this->browserID = 'BaiduBoxApp';
				$this->browserName = '手机百度';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#baidubrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Baidu Browser Android
				$this->browserID = 'BaiduBrowser';
				$this->browserName = '百度浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#OPRGX/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Opera Gaming Browser PC
				$this->browserID = 'Opera GX';
				$this->browserName = 'Opera GX';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#FxiOS/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                // Firefox Focus
                $this->browserID = 'FxiOS';
                $this->browserName = 'Firefox Focus';
                $this->browserVersion = $matches[1];
            } elseif (preg_match('#2345Explorer/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// 2345Explorer Browser PC
				$this->browserID = '2345Explorer';
				$this->browserName = '2345Explorer';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#OPR/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Opera Android / iOS
				$this->browserID = 'Opera';
				$this->browserName = 'Opera';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#OPT/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Opera Touch Android / iOS
				$this->browserID = 'Opera Touch';
				$this->browserName = 'Opera Touch';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#Vivaldi/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Vivaldi macOS / PC
				$this->browserID = 'Vivaldi';
				$this->browserName = 'Vivaldi';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#(MQQBrowser|QQBrowser)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// QQ Browser Android / PC & macOS
				$this->browserID = 'QQBrowser';
				$this->browserName = 'QQ浏览器';
				$this->browserVersion = $matches[2];
			} elseif (preg_match('#QQ/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// MobileQQ Android
				$this->browserID = 'MobileQQ';
				$this->browserName = '手机QQ';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#Thunder/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// ThunderX PC
				$this->browserID = 'ThunderX';
				$this->browserName = '迅雷X';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#360 Alitephone Browser \(.*?\/([\d+\.]+[\d+])\)#i', $this->ua, $matches)) {
				// 360 Quick Android
				$this->browserID = '360Quick';
				$this->browserName = '极速浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#Qiyu/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Qiyu Android / iOS / PC
				$this->browserID = 'Qiyu';
				$this->browserName = '旗鱼浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#UCTurbo/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// UC Turbo Google Play version
				$this->browserID = 'UCTurbo';
				$this->browserName = 'UCTurbo';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#(UCBrowser|UBrowser|UCWEB)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// UCMobile Android / Symbian & BlackBerry and older version
				$this->browserID = 'UCMobile';
				$this->browserName = 'UC浏览器';
				$this->browserVersion = $matches[2];
            } elseif (preg_match('#MailMaster/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
                // Netease MailMaster Client
                $this->browserID = 'MailMaster';
                $this->browserName = '网易邮箱大师';
                $this->browserVersion = $matches[1];
			} elseif (preg_match('#Quark/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Quark Android / iOS
				$this->browserID = 'Quark';
				$this->browserName = 'Quark';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#SamsungBrowser/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Samsung Phone Browser Android
				$this->browserID = 'SamsungBrowser';
				$this->browserName = '三星浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#SogouSearch.*/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// SogouSearch Android
				$this->browserID = 'SogouSearch';
				$this->browserName = '搜狗搜索';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#Maxthon( |\/)([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Maxthon Android
				$this->browserID = 'Maxthon';
				$this->browserName = '遨游浏览器';
				$this->browserVersion = $matches[2];
			} elseif (preg_match('#XiaoMi/MiuiBrowser/([0-9.]+)#i', $this->ua, $matches)) {
				// MIUI Browser
				$this->browserID = 'MiuiBrowser';
				$this->browserName = '小米浏览器';
				$this->browserVersion = $matches[1];
			} elseif (preg_match('#(Edg|Edge|EdgA|EdgiOS)/([a-zA-Z0-9.]+)#i', $this->ua, $matches)) {
				// Edge Chromium / EdgeHTML / Android / iOS 
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
			} elseif (preg_match('#Trident#', $this->ua, $matches)) {
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

	/**
	 * 获取浏览器ID
	 *
	 * @access public
	 * @return string
	 */
	public function getBrowserID() {
		return $this->parseBrowser() ? $this->browserID : '';
	}

	/**
	 * 获取浏览器名字
	 *
	 * @access public
	 * @return string
	 */
	public function getBrowserName() {
		return $this->parseBrowser() ? $this->browserName : '';
	}

	/**
	 * 获取浏览器版本号
	 *
	 * @access public
	 * @return string
	 */
	public function getBrowserVersion() {
		return $this->parseBrowser() ? $this->browserVersion : '';
	}
}
