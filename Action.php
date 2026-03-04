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
                ];
            } else {
                $response = [
                    'code' => 500,
                    'data' => $result['error'] ?? 'IP 查询失败',
                ];
            }
        } catch (\Exception $e) {
            $response = [
                'code' => 500,
                'data' => 'IP 查询异常：' . $e->getMessage(),
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
