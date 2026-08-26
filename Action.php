<?php

namespace TypechoPlugin\Access;

use RuntimeException;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    /*
     * 注意：这里一律 catch \Throwable 而不是 \Exception。
     * Typecho 的 PDO 适配器把 PDOException 的 SQLSTATE 当成 int 传给 Exception 构造函数，
     * 而 PostgreSQL 的部分 SQLSTATE 含字母（如 42P01 表不存在），会先抛出 TypeError。
     * TypeError 属于 \Error，只 catch \Exception 会让它逃逸成 PHP 致命错误，
     * 响应体为空，前端只能看到「无法获取」而拿不到真正的错误信息。
     */

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
        // writeType: 0 = 前端（本接口负责写），1 = 后端（Plugin::backend 已经写过了）。
        // 这里只在前端模式下写日志：后端模式下该埋点脚本根本不会被输出，
        // 若仍照写就等于给匿名请求开了一个可以重复灌数据的口子。
        if ($this->getAccess()->config->writeType == 0) {
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
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
            $section = (string)$this->request->get('section', '');

            if ($section === '') {
                # 不带 section 时保持旧行为，一次返回全部
                $data = $this->getAccess()->getOverviewData();
            } else {
                $seconds = (int)$this->request->get('seconds', 5);
                $seconds = max(1, min($seconds, 15));
                $data = $this->getAccess()->getOverviewSection($section, microtime(true) + $seconds);
            }

            $response = [
                'code' => 0,
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            $response = [
                'code' => 500,
                'data' => Database::explainError($e),
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
        } catch (\Throwable $e) {
            $response = [
                'code' => 500,
                'data' => Database::explainError($e),
            ];
        }

        $this->response->throwJson($response);
    }

    /**
     * 历史数据迁移接口（后台分片调用）
     *
     * 每次请求只跑一小段时间就返回，由前端反复调用直到完成，
     * 这样既不会被 Web 服务器超时截断，也不需要服务器的 SSH 权限。
     * 传 probe=1 只查询进度，不做任何写入。
     */
    public function migrate(): void
    {
        $this->checkAuth();

        // throwJson 只在最后调用一次：它内部会终止流程（sandbox 模式下以异常形式），
        // 放在 try 里会被下面的 catch 吞掉
        try {
            $config = Options::alloc()->plugin('Access');
            $dbSettings = Database::settings($config);

            if (!Database::isExternal($dbSettings)) {
                # 跟随主库时没有迁移这回事
                $data = [
                    'external' => false, 'done' => true,
                    'total' => 0, 'migrated' => 0, 'pending' => 0, 'moved' => 0,
                ];
            } else {
                $main = Database::main();
                $target = Database::get($dbSettings);
                $status = Migrate::status($main, $target, $dbSettings);
                $moved = 0;

                if (!$status['marked'] && $status['pending'] > 0 && !$this->request->get('probe')) {
                    $seconds = (int)$this->request->get('seconds', 3);
                    $seconds = max(1, min($seconds, 10));

                    $run = Migrate::run($main, $target, ['deadline' => microtime(true) + $seconds]);
                    $moved = $run['moved'];
                    $status = Migrate::status($main, $target, $dbSettings);
                }

                $done = $status['marked'] || $status['pending'] === 0;
                if ($done) {
                    Migrate::mark($main, Migrate::fingerprint($dbSettings));
                }

                $data = [
                    'external' => true,
                    'done' => $done,
                    'total' => $status['total'],
                    'migrated' => $done ? $status['total'] : $status['migrated'],
                    'pending' => $status['pending'],
                    'moved' => $moved,
                ];
            }

            $response = ['code' => 0, 'data' => $data];
        } catch (\Throwable $e) {
            $response = ['code' => 500, 'data' => $e->getMessage()];
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
