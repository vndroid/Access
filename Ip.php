<?php

namespace TypechoPlugin\Access;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 地址解析类
 */
class Ip
{
    private static ?self $ip = null;

    private static $fp = null;

    private static array $cached = [];

    public static function find(string $ip): array
    {
        if (empty($ip)) {
            return [
                "status" => "failure",
                "country" => null,
                "city" => null,
            ];
        }

        $nip = gethostbyname($ip);
        $ipdot = explode('.', $nip);

        if ($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4) {
            return [
                "status" => "failure",
                "country" => null,
                "city" => null,
            ];
        }

        if (isset(self::$cached[$nip])) {
            return self::$cached[$nip];
        }

        if (self::$fp === null) {
            self::init();
        }

        $url = 'http://ip-api.com/json/';
        $request = $url . $nip;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $request);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4685.0 Safari/537.36');
        $result = json_decode(curl_exec($ch), true);
        return [
            'status' => $result['status'],
            'country' => $result['country'],
            'countryCode' => $result['countryCode'],
            'region' => $result['region'],
            'regionName' => $result['regionName'],
            'city' => $result['city'],
            'zip' => $result['zip'],
            'timezone' => $result['timezone'],
            'query' => $result['query'],
        ];
    }

    private static function init(): void
    {
        if (self::$fp === null) {
            self::$ip = new self();
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('当前主机不支持 cURL ，请检查环境！');
            }
        }
    }

    public function __destruct()
    {
        if (self::$fp !== null) {
            fclose(self::$fp);
            self::$fp = null;
        }
    }
}

