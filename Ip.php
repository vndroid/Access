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
     * ISO 3166-1 alpha-2 国家码转国家名（简体中文）
     *
     * @param string $code 两位国家码，如 "AU"
     * @return string 国家或地区中文名，如 "澳大利亚"
     */
    public static function iso2zh(string $code): string
    {
        if (!preg_match('/^[A-Za-z]{2}$/', $code)) {
            return '未知';
        }

        $zhName = \Locale::getDisplayRegion('-' . strtoupper($code), 'zh_CN');

        // 超过 10 个字符时截断
        if (mb_strlen($zhName, 'UTF-8') > 10) {
            $zhName = mb_substr($zhName, 0, 10, 'UTF-8');
        }

        return $zhName;
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
            return ['status' => 'failure', 'error' => 'cURL 请求失败', 'country' => null, 'region' => null, 'city' => null];
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
        if ($json['bogon'] === true) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => '', 'region' => '', 'city' => ''];
        }

        return [
            'status'      => 'success',
            'ip'          => $json['ip'],
            'country'     => self::iso2zh($json['country_code']) ?? '',
            'countryCode' => $json['geo']['country_code'] ?? '',
            'region'      => $json['geo']['region'] ?? '',
            'regionCode'  => $json['geo']['region_code'] ?? '',
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
        if ($json['bogon'] === true) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => null, 'region' => null, 'city' => null];
        }

        return [
            'status'      => 'success',
            'ip'          => $json['ip'],
            'country'     => self::iso2zh($json['country_code']) ?? '',
            'countryCode' => $json['country_code'] ?? '',
            'region'      => '',
            'regionCode'  => '',
            'city'        => '',
            'zip'         => '',
            'timezone'    => '',
            'continent'   => $json['continent'] ?? '',
            'asn'         => $json['asn'] ?? '',
            'as_name'     => $json['as_name'] ?? '',
            'query'       => $json['ip'],
        ];
    }

    /**
     * 通过 KeyCDN 接口查询（免费 fallback 接口）
     *
     * @throws Exception
     */
    private static function queryKeyCdn(string $ip): array
    {
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
            'status'      => 'success',
            'ip'          => $geo['ip'] ?? $ip,
            'country'     => $geo['country_name'] ?? '',
            'countryCode' => $geo['country_code'] ?? '',
            'region'      => $geo['region_name'] ?? '',
            'regionCode'  => $geo['region_code'] ?? '',
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

