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
                'status'  => 'failure',
                'error'   => 'IP地址为空',
                'country' => null,
                'region'  => null,
                'city'    => null,
            ];
        }

        $nip = self::canonical($ip);
        if ($nip === null) {
            return [
                'status'  => 'failure',
                'error'   => 'IP地址不合法',
                'country' => null,
                'region'  => null,
                'city'    => null,
            ];
        }

        if (isset(self::$cached[$nip])) {
            return self::$cached[$nip];
        }

        $config = Options::alloc()->plugin(basename(__DIR__));
        $token = $config->isToken ?? '';

        if ($token !== '') {
            $result = self::queryIpInfo($nip, $token);
        } else {
            $result = self::queryKeyCdn($nip);
        }

        if ($result['status'] === 'success') {
            self::$cached[$nip] = $result;
        }

        return $result;
    }

    /**
     * 把传进来的地址规整成可以直接拿去查询的形式；不是 IP 就返回 null
     *
     * 以前是 gethostbyname() 之后按「.」拆成四段来判合法：
     * - 所有 IPv6 地址（控制台里已经能正确显示）一律被判成「IP地址不合法」；
     * - 传主机名进来会被拿去做一次 DNS 解析，再用解析结果去查地理位置 ——
     *   这个接口只该查 IP，不该替调用方解析任意域名。
     *
     * IPv4 映射的 IPv6（::ffff:1.2.3.4）按其中的 IPv4 查，两个上游对它的支持都不稳定。
     */
    private static function canonical(string $ip): ?string
    {
        $ip = trim($ip);
        # 允许带方括号的写法（[2001:db8::1]），控制台复制出来的地址可能是这种
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }
        # 统一成压缩的小写形式，缓存命中和上游请求都用同一个写法
        return (string)inet_ntop($packed);
    }

    /**
     * 通过 IPinfo 接口查询地址详情
     * 接口分为 Lite 和 Core 两种，分别为免费、付费接口
     *
     * @throws Exception
     */
    private static function queryIpInfo(string $ip, string $token): array
    {
        $config = Options::alloc()->plugin(basename(__DIR__));
        $isPaid = $config->isPaid ?? '0';
        if ($isPaid === '1') {
            $officialUrl = 'https://api.ipinfo.io/lookup/';
        } else {
            $officialUrl = 'https://api.ipinfo.io/lite/';
        }
        $url = $officialUrl . rawurlencode($ip) . '?token=' . rawurlencode($token);
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
            return ['status' => 'failure', 'error' => '响应数据异常', 'country' => null, 'region' => null, 'city' => null];
        }
        if (isset($json['bogon']) && $json['bogon'] === true) {
            return ['status' => 'success', 'error' => '保留地址区段', 'country' => null, 'region' => null, 'city' => null];
        }

        if ($isPaid === '1') {
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
        } elseif ($isPaid === '0') {
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
        } else {
            return ['status' => 'failure', 'error' => '未知错误', 'country' => null, 'region' => null, 'city' => null];
        }
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
        $url = 'https://tools.keycdn.com/geo.json?host=' . rawurlencode($ip);
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
            return ['status' => 'failure', 'error' => '请求返回失败', 'country' => null, 'region' => null, 'city' => null];
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

