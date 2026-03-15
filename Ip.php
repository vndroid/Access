<?php

namespace TypechoPlugin\Access;

use Typecho\Plugin\Exception;
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

    /**
     * @throws Exception
     */
    public static function find(string $ip): array
    {
        if (empty($ip)) {
            return [
                "status" => "failure",
                "country" => null,
                "region" => null,
                "city" => null,
            ];
        }

        $nip = gethostbyname($ip);
        $ipdot = explode('.', $nip);

        if ($ipdot[0] < 0 || $ipdot[0] > 255 || count($ipdot) !== 4) {
            return [
                "status" => "failure",
                "country" => null,
                "region" => null,
                "city" => null,
            ];
        }

        if (isset(self::$cached[$nip])) {
            return self::$cached[$nip];
        }

        $config = Options::alloc()->plugin(basename(__DIR__));
        $token = $config->isToken ?? '';

        if ($token !== '') {
            $isPaid = $config->isPaid ?? '0';
            if ($isPaid === '1') {
                $result = self::queryIpInfoCore($nip, $token);
            } else {
                $result = self::queryIpInfoLite($nip, $token);
            }
        } else {
            $result = self::queryKeyCdn($nip);
        }

        if ($result['status'] === 'success') {
            self::$cached[$nip] = $result;
        }

        return $result;
    }

    /**
     * 通过 IPinfo Core 接口查询地址详情
     *
     * @throws Exception
     */
    private static function queryIpInfoCore(string $ip, string $token): array
    {
        $config = Options::alloc()->plugin(basename(__DIR__));
        $url = 'https://api.ipinfo.io/lookup/' . $ip . '?token=' . $token;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4685.0 Safari/537.36');
        $proxy = $config->socks5Host ?? '';
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            $auth = $config->socks5Auth ?? '';
            if ($auth !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
            }
        }
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 'failure', 'error' => '请求返回失败', 'country' => null, 'region' => null, 'city' => null];
        }

        if ($httpCode !== 200) {
            $err = json_decode($body, true);
            $msg = $err['error'] ?? ('HTTP ' . $httpCode);
            return ['status' => 'failure', 'error' => $msg, 'country' => null, 'region' => null, 'city' => null];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ip'])) {
            return ['status' => 'failure', 'error' => '响应数据异常', 'country' => '', 'region' => '', 'city' => ''];
        }
        if (isset($json['bogon']) && $json['bogon'] === true) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => '', 'region' => '', 'city' => ''];
        }

        return [
            'status'        => 'success',
            'ip'            => $json['ip'],
            'city'          => $json['geo']['city'] ?? '',
            'region'        => $json['geo']['region'] ?? '',
            'regionCode'    => $json['geo']['region_code'] ?? '',
            'country'       => $json['geo']['country'] ?? '',
            'countryCode'   => $json['geo']['country_code'] ?? '',
            'continent'     => $json['geo']['continent'] ?? '',
            'continentCode' => $json['geo']['continent_code'] ?? '',
            'latitude'      => $json['geo']['latitude'] ?? '',
            'longitude'     => $json['geo']['longitude'] ?? '',
            'timezone'      => $json['geo']['timezone'] ?? '',
            'postalCode'    => $json['geo']['postal_code'] ?? '',
            'asn'           => $json['as']['asn'] ?? '',
            'asName'        => $json['as']['name'] ?? '',
            'asDomain'      => $json['as']['domain'] ?? '',
            'asType'        => $json['as']['type'] ?? '',
            'isAnonymous'   => $json['is_anonymous'] ?? '',
            'isAnycast'     => $json['is_anycast'] ?? '',
            'isHosting'     => $json['is_hosting'] ?? '',
            'isMobile'      => $json['is_mobile'] ?? '',
            'isSatellite'   => $json['is_satellite'] ?? '',
        ];
    }

    /**
     * 通过 IPinfo Lite 接口查询（免费版）
     * @throws Exception
     */
    private static function queryIpInfoLite(string $ip, string $token): array
    {
        $config = Options::alloc()->plugin(basename(__DIR__));
        $url = 'https://api.ipinfo.io/lite/' . $ip . '?token=' . $token;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4685.0 Safari/537.36');
        $proxy = $config->socks5Host ?? '';
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            $auth = $config->socks5Auth ?? '';
            if ($auth !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
            }
        }
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 'failure', 'error' => 'cURL 请求失败', 'country' => null, 'region' => null, 'city' => null];
        }

        if ($httpCode !== 200) {
            $err = json_decode($body, true);
            $msg = $err['error'] ?? ('HTTP ' . $httpCode);
            return ['status' => 'failure', 'error' => $msg, 'country' => null, 'region' => null, 'city' => null];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['ip'])) {
            return ['status' => 'failure', 'error' => '响应数据异常', 'country' => null, 'region' => null, 'city' => null];
        }
        if (isset($json['bogon']) && $json['bogon'] === true) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => null, 'region' => null, 'city' => null];
        }

        return [
            'status'        => 'success',
            'ip'            => $json['ip'],
            'asn'           => $json['asn'] ?? '',
            'as_name'       => $json['as_name'] ?? '',
            'as_domain'     => $json['as_domain'] ?? '',
            'countryCode'   => $json['country_code'] ?? '',
            'country'       => $json['country'] ?? '',
            'continentCode' => $json['continent_code'] ?? '',
            'continent'     => $json['continent'] ?? '',
        ];
    }

    /**
     * 通过 KeyCDN 接口查询（免费 fallback 接口）
     *
     * @throws Exception
     */
    private static function queryKeyCdn(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => null, 'region' => null, 'city' => null];
        }

        $config = Options::alloc()->plugin(basename(__DIR__));
        $url = 'https://tools.keycdn.com/geo.json?host=' . $ip;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'keycdn-tools:https://www.bing.com');
        $proxy = $config->socks5Host ?? '';
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            $auth = $config->socks5Auth ?? '';
            if ($auth !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $auth);
            }
        }
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 'failure', 'error' => 'cURL 请求失败', 'country' => null, 'region' => null, 'city' => null];
        }

        if ($httpCode !== 200) {
            $err = json_decode($body, true);
            $msg = $err['error'] ?? ('HTTP ' . $httpCode);
            return ['status' => 'failure', 'error' => $msg, 'country' => null, 'region' => null, 'city' => null];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            return ['status' => 'failure', 'error' => '响应数据异常', 'country' => null, 'region' => null, 'city' => null];
        }

        $geo = $json['data']['geo'] ?? [];

        return [
            'status'        => 'success',
            'host'          => $geo['host'] ?? null,
            'ip'            => $geo['ip'] ?? $ip,
            'asn'           => $geo['asn'] ?? null,
            'isp'           => $geo['isp'] ?? null,
            'country'       => $geo['country_name'] ?? null,
            'countryCode'   => $geo['country_code'] ?? null,
            'region'        => $geo['region_name'] ?? null,
            'regionCode'    => $geo['region_code'] ?? null,
            'city'          => $geo['city'] ?? null,
            'zip'           => $geo['postal_code'] ?? null,
            'continent'     => $geo['continent_name'] ?? null,
            'continentCode' => $geo['continent_code'] ?? null,
            'latitude'      => $geo['latitude'] ?? null,
            'longitude'     => $geo['longitude'] ?? null,
            'metroCode'     => $geo['metro_code'] ?? null,
            'timezone'      => $geo['timezone'] ?? null,
            'datetime'      => $geo['datetime'] ?? null,
        ];
    }
}

