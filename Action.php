<?php
require_once __DIR__ . '/Access_Bootstrap.php';

class Access_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $access;

    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);

        $this->access = new Access_Core();
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
        if ($this->access->config->writeType == 1) {
            $this->access->writeLogs(null, $this->request->u, $this->request->cid, $this->request->mid);
        }
        echo $image;
    }

    public function ip()
    {
        $ip = $this->request->get('ip');
        try {
            $this->checkAuth();
            $response = Access_Ip::find($ip);
            if ($response['status'] === "success") {
                $response = array(
                    'code' => 0,
                    'data' => array(
                        'status' => $response['status'],
                        'country' => $response['country'],
                        'countryCode' => $response['countryCode'],
                        'region' => $response['region'],
                        'regionName' => $response['regionName'],
                        'city' => $response['city'],
                        'zip' => $response['zip'],
                        'timezone' => $response['timezone'],
                        'query' => $response['query'],
                    ),
                );
            } else {
                throw new RuntimeException('解析 IP 失败');
            }
        } catch (Exception $e) {
            try {
                $url = 'https://tools.keycdn.com/geo.json?host=';
                $request = $url . $ip;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'keycdn-tools:https://www.bing.com');
                curl_setopt($ch, CURLOPT_URL, $request);
                $result = json_decode(curl_exec($ch), true);
                if ($result['status'] === 'success') {
                    $response = array(
                        'code' => 0,
                        'data' => array(
                            'status' => $result['status'],
                            'country' => $result['data']['geo']['country_name'],
                            'countryCode' => $result['data']['geo']['country_code'],
                            'region' => $result['data']['geo']['region_code'],
                            'regionName' => $result['data']['geo']['region_name'],
                            'city' => $result['data']['geo']['city'],
                            'postal_code' => $result['data']['geo']['postal_code'],
                            'timezone' => $result['data']['geo']['timezone'],
                            'query' => $result['data']['geo']['ip'],
                        ),
                    );
                }
            } catch (Exception $e) {
                $response = array(
                    'code' => 500,
                    'data' => '很抱歉，IPAPI 查询无结果，同时服务器无法连接 fallback 接口(tools.keycdn.com)',
                );
            }
        }
        $this->response->throwJson($response);
    }

    public function deleteLogs()
    {
        try {
            $this->checkAuth();
            $data = @file_get_contents('php://input');
            $data = Json::decode($data, true);
            if (!is_array($data)) {
                throw new RuntimeException('params invalid');
            }
            $this->access->deleteLogs($data);
            $response = array(
                'code' => 0,
            );

        } catch (Exception $e) {
            $response = array(
                'code' => 100,
                'data' => $e->getMessage(),
            );
        }

        $this->response->throwJson($response);
    }

    protected function checkAuth()
    {
        if (!$this->access->isAdmin()) {
            throw new RuntimeException('Access Denied');
        }
    }

}
