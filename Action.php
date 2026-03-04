<?php

namespace TypechoPlugin\Access;

use Typecho\Widget;
use Widget\ActionInterface;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    private ?Core $access = null;

    private function getAccess(): Core
    {
        if ($this->access === null) {
            $this->access = new Core();
        }
        return $this->access;
    }

    public function execute()
    {
    }

    public function action()
    {
    }

    public function writeLogs()
    {
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAQUAP8ALAAAAAABAAEAAAICRAEAOw==');
        $this->response->setContentType('image/gif');
        if ($this->getAccess()->config->writeType == 1) {
            $this->getAccess()->writeLogs(null, $this->request->get('u'), $this->request->get('cid'), $this->request->get('mid'));
        }
        echo $image;
    }

    public function ip()
    {
        $ip = $this->request->get('ip');
        try {
            $this->checkAuth();
            $response = Ip::find($ip);
            if ($response['status'] === "success") {
                $response = [
                    'code' => 0,
                    'data' => [
                        'status' => $response['status'],
                        'country' => $response['country'],
                        'countryCode' => $response['countryCode'],
                        'region' => $response['region'],
                        'regionName' => $response['regionName'],
                        'city' => $response['city'],
                        'zip' => $response['zip'],
                        'timezone' => $response['timezone'],
                        'query' => $response['query'],
                    ],
                ];
            } else {
                throw new \RuntimeException('解析 IP 失败');
            }
        } catch (\Exception $e) {
            try {
                $url = 'https://tools.keycdn.com/geo.json?host=';
                $request = $url . $ip;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'keycdn-tools:https://www.bing.com');
                curl_setopt($ch, CURLOPT_URL, $request);
                $result = json_decode(curl_exec($ch), true);
                if ($result['status'] === 'success') {
                    $response = [
                        'code' => 0,
                        'data' => [
                            'status' => $result['status'],
                            'country' => $result['data']['geo']['country_name'],
                            'countryCode' => $result['data']['geo']['country_code'],
                            'region' => $result['data']['geo']['region_code'],
                            'regionName' => $result['data']['geo']['region_name'],
                            'city' => $result['data']['geo']['city'],
                            'postal_code' => $result['data']['geo']['postal_code'],
                            'timezone' => $result['data']['geo']['timezone'],
                            'query' => $result['data']['geo']['ip'],
                        ],
                    ];
                }
            } catch (\Exception $e) {
                $response = [
                    'code' => 500,
                    'data' => '很抱歉，IPAPI 查询无结果，同时服务器无法连接 fallback 接口(tools.keycdn.com)',
                ];
            }
        }
        $this->response->throwJson($response);
    }

    public function deleteLogs()
    {
        try {
            $this->checkAuth();
            $data = @file_get_contents('php://input');
            $data = json_decode($data, true);
            if (!is_array($data)) {
                throw new \RuntimeException('params invalid');
            }
            $this->getAccess()->deleteLogs($data);
            $response = [
                'code' => 0,
            ];
        } catch (\Exception $e) {
            $response = [
                'code' => 100,
                'data' => $e->getMessage(),
            ];
        }

        $this->response->throwJson($response);
    }

    protected function checkAuth()
    {
        if (!$this->getAccess()->isAdmin()) {
            throw new \RuntimeException('Access Denied');
        }
    }

}
