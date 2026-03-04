<?php

namespace TypechoPlugin\Access;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 地址解析类
 */
class Ip
{
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

        $token = Options::alloc()->plugin('Access')->ipInfoToken ?? '';

        if ($token !== '') {
            $result = self::queryIpInfo($nip, $token);
        } else {
            $result = self::queryKeycdn($nip);
        }

        if ($result['status'] === 'success') {
            self::$cached[$nip] = $result;
        }

        return $result;
    }

    /**
     * 通过 ipinfo.io core 接口查询
     */
    private static function queryIpInfo(string $ip, string $token): array
    {
        $url = 'https://api.ipinfo.io/lookup/' . $ip . '?token=' . $token;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4685.0 Safari/537.36');
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            return ['status' => 'failure', 'country' => null, 'city' => null];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ip'])) {
            return ['status' => 'failure', 'country' => null, 'city' => null];
        }

        return [
            'status'      => 'success',
            'ip'          => $json['ip'],
            'country'     => $json['geo']['country'] ?? '',
            'countryCode' => $json['geo']['country_code'] ?? '',
            'region'      => $json['geo']['region_code'] ?? '',
            'regionName'  => $json['geo']['region'] ?? '',
            'city'        => $json['geo']['city'] ?? '',
            'zip'         => $json['geo']['postal_code'] ?? '',
            'timezone'    => $json['geo']['timezone'] ?? '',
            'continent'   => $json['geo']['continent'] ?? '',
            'asn'         => $json['as']['asn'] ?? '',
            'as_name'     => $json['as']['name'] ?? '',
            'query'       => $json['ip'],
        ];
    }

    /**
     * 通过 keycdn 接口查询（免费 fallback）
     */
    private static function queryKeycdn(string $ip): array
    {
        $url = 'https://tools.keycdn.com/geo.json?host=' . $ip;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'keycdn-tools:https://www.bing.com');
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            return ['status' => 'failure', 'country' => null, 'city' => null];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            return ['status' => 'failure', 'country' => null, 'city' => null];
        }

        $geo = $json['data']['geo'] ?? [];

        return [
            'status'      => 'success',
            'ip'          => $geo['ip'] ?? $ip,
            'country'     => $geo['country_name'] ?? '',
            'countryCode' => $geo['country_code'] ?? '',
            'region'      => $geo['region_code'] ?? '',
            'regionName'  => $geo['region_name'] ?? '',
            'city'        => $geo['city'] ?? '',
            'zip'         => $geo['postal_code'] ?? '',
            'timezone'    => $geo['timezone'] ?? '',
            'continent'   => $geo['continent_name'] ?? '',
            'asn'         => $geo['asn'] ?? '',
            'as_name'     => $geo['as_org'] ?? '',
            'query'       => $geo['ip'] ?? $ip,
        ];
    }
}

