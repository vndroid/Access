<?php
if (!defined('__ACCESS_PLUGIN_ROOT__')) {
    throw new RuntimeException('Bootstrap File Not Found');
}

/**
 * 地址解析类
 * Class Access_Ip
 */
class Access_Ip
{
    private static $ip = NULL;

    private static $fp = NULL;

    private static $cached = array();

    public static function find($ip)
    {
        if (empty($ip) === TRUE) {
            return 'N/A';
        }

        $nip = gethostbyname($ip);
        $ipdot = explode('.', $nip);

        if ($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4) {
            return 'N/A';
        }

        if (isset(self::$cached[$nip]) === true) {
            return self::$cached[$nip];
        }

        if (self::$fp === NULL) {
            self::init();
        }

        $url = 'http://ip-api.com/json/';
        $request = $url . $nip;
        $ch = curl_init();
        #curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        #curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $request);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4685.0 Safari/537.36');
        $result = json_decode(curl_exec($ch), true);
        return array($result['country'] . ' ' . $result['city']);
    }

    private static function init(): void
    {
        if (self::$fp === NULL) {
            self::$ip = new self();
            if (!function_exists('curl_init')) {
                throw new RuntimeException('当前主机不支持 cURL ，请检查环境！');
            }
        }
    }

    public function __destruct()
    {
        if (self::$fp !== NULL) {
            fclose(self::$fp);

            self::$fp = NULL;
        }
    }
}
