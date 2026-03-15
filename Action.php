<?php

namespace TypechoPlugin\Access;

use RuntimeException;
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

    public function writeLogs(): void
    {
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAQUAP8ALAAAAAABAAEAAAICRAEAOw==');
        $this->response->setContentType('image/gif');
        if ($this->getAccess()->config->writeType == 1) {
            $this->getAccess()->writeLogs(null, $this->request->get('u'), $this->request->get('cid'), $this->request->get('mid'));
        }
        echo $image;
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

    public function ipGeo(): void
    {
        $this->checkAuth();
        $ip = $this->request->get('ip');

        try {
            $result = Ip::find($ip);
            if ($result['status'] === 'success') {
                $response = [
                    'code' => 0,
                    'data' => $result,
                    'msg'  => $result['error'] ?? null,
                    'i18n' => [
                        'country' => null,
                        'region'  => null,
                        'city'    => null,
                    ],
                ];
                if (!empty($result['country'])) {
                    $response['i18n']['country'] = self::iso2zh($result['countryCode']);
                }
            } else {
                $response = [
                    'code' => 500,
                    'data' => $result['error'] ?? null,
                    'msg'  => 'ERROR',
                    'i18n' => null,
                ];
            }
        } catch (\Exception $e) {
            $response = [
                'code' => 500,
                'data' => $e->getMessage(),
                'msg'  => 'ERROR',
                'i18n' => null,
            ];
        }

        $this->response->throwJson($response);
    }

    public function deleteLogs(): void
    {
        $this->checkAuth();
        try {
            $data = @file_get_contents('php://input');
            $data = json_decode($data, true);
            if (!is_array($data)) {
                throw new RuntimeException('params invalid');
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

    /**
     * 概览页懒加载数据接口
     */
    public function overview(): void
    {
        $this->checkAuth();
        try {
            $data = $this->getAccess()->getOverviewData();
            $response = [
                'code' => 0,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $response = [
                'code' => 500,
                'data' => $e->getMessage(),
            ];
        }

        $this->response->throwJson($response);
    }

    /**
     * 日志页懒加载数据接口
     */
    public function logsParse(): void
    {
        $this->checkAuth();
        try {
            $page   = (int)$this->request->get('page', 1);
            $type   = (int)$this->request->get('type', 1);
            $filter = $this->request->get('filter', 'all');
            $filterValue = '';
            switch ($filter) {
                case 'ip':   $filterValue = $this->request->get('ip', '');   break;
                case 'post': $filterValue = $this->request->get('cid', '');  break;
                case 'path': $filterValue = $this->request->get('path', ''); break;
            }
            $data = $this->getAccess()->getLogsData($page, $type, $filter, $filterValue);
            $response = [
                'code' => 0,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $response = [
                'code' => 500,
                'data' => $e->getMessage(),
            ];
        }

        $this->response->throwJson($response);
    }

    /**
     * 鉴权：非管理员直接返回 403 并终止
     */
    protected function checkAuth(): void
    {
        if (!$this->getAccess()->isAdmin()) {
            $this->response->setStatus(403);
            $this->response->throwJson([
                'code' => 403,
                'data' => 'Access Denied',
            ]);
        }
    }

}
