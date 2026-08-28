<?php

namespace TypechoPlugin\Access;

use RuntimeException;
use Typecho\Widget;
use Widget\ActionInterface;
use Widget\Options;
use Widget\Security;

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
        if ($this->getAccess()->config->writeType == 0 && $this->getAccess()->allowTracking()) {
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
        $this->checkAuth(false);
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
        $this->checkAuth(false);
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
        $this->checkAuth(false);
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
                $fingerprint = Migrate::fingerprint($dbSettings);
                $status = Migrate::status($main, $target, $dbSettings);
                $moved = 0;
                $runError = null;

                if (!$status['marked'] && $status['pending'] > 0 && !$this->request->get('probe')) {
                    $seconds = (int)$this->request->get('seconds', 3);
                    $seconds = max(1, min($seconds, 10));

                    $run = Migrate::run($main, $target, [
                        'deadline'    => microtime(true) + $seconds,
                        'fingerprint' => $fingerprint,
                    ]);
                    $moved = $run['moved'];
                    $runError = $run['error'];
                    $status = Migrate::status($main, $target, $dbSettings);
                }

                # 有行迁不过去就不算完成：pending 是按行数算的，它永远到不了 0
                $stuck = Migrate::failures($main, $fingerprint);
                $done = $status['marked'] || ($status['pending'] === 0 && empty($stuck));
                if ($done) {
                    Migrate::mark($main, $fingerprint);
                }

                $data = [
                    'external' => true,
                    'done' => $done,
                    'total' => $status['total'],
                    'migrated' => $done ? $status['total'] : $status['migrated'],
                    'pending' => $status['pending'],
                    'moved' => $moved,
                    'failed' => count($stuck),
                    /*
                     * 这一轮一条都没推进，而且原因是「有行写不进去」或「整批写入失败」——
                     * 再请求多少次也是原地打转。前端要靠这个标志收工，
                     * 否则进度条会卡在同一个百分比上无限轮询。
                     */
                    'blocked' => !$done && $moved === 0 && (!empty($stuck) || $runError !== null),
                    'reason' => $runError,
                ];
            }

            $response = ['code' => 0, 'data' => $data];
        } catch (\Throwable $e) {
            $response = ['code' => 500, 'data' => $e->getMessage()];
        }

        $this->response->throwJson($response);
    }

    /**
     * 鉴权：非管理员、或没带上有效 token 的请求，直接返回 403 并终止
     *
     * 光有 isAdmin() 不够 —— 那只回答「你是不是管理员」，回答不了
     * 「这次请求是不是你自己发起的」。删除日志和迁移都是写操作，
     * 管理员在登录状态下访问任意一个第三方页面，页面里一个
     * <img src="…/access/migrate.json"> 或一个自动提交的表单就能替他发出去。
     *
     * token 用 Typecho 自己的 Widget\Security 生成，值里含站点密钥、当前用户的
     * authCode 和 uid，别人算不出来 —— CSRF 要的就是这一条。
     *
     * 但**不绑 referer**，而是绑一个固定作用域字符串。Security::protect() 绑的是
     * referer，那在这里会坏两次：控制台用 history.pushState 切 Tab，渲染时算出的
     * token 和切完 Tab 之后 XHR 带的 referer 对不上；而浏览器或站点的
     * Referrer-Policy 一旦把 referer 剥掉，接口对那些用户直接全废。
     * 固定作用域没有这两个问题，安全性质不变。
     *
     * 也不直接调 protect()：它失败时是 goBack() 跳转，对一个 JSON 接口来说
     * 前端拿到的会是一段 HTML 而不是错误码。
     *
     * @param bool $write 写操作额外要求 POST；只读接口传 false
     */
    /**
     * 接口 token 的固定作用域
     *
     * 换个字面量就等于让所有已发出的页面上的 token 立即失效，别随手改。
     */
    private const TOKEN_SCOPE = 'plugin:access:api';

    /**
     * 生成本用户的接口 token，控制台渲染和接口校验共用这一处
     *
     * @return string
     */
    public static function token(): string
    {
        return Security::alloc()->getToken(self::TOKEN_SCOPE);
    }

    protected function checkAuth(bool $write = true): void
    {
        if (!$this->getAccess()->isAdmin()) {
            $this->response->setStatus(403);
            $this->response->throwJson([
                'code' => 403,
                'data' => 'Access Denied',
            ]);
        }

        /*
         * 写操作必须是 POST。CSRF 里最省事的那条路（<img>、<script>、跨站链接）
         * 只能发出 GET，挡掉 GET 就把它们一并挡掉了，token 只是第二道。
         * migrate 接口以前就是 GET 写操作。
         */
        if ($write && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->response->setStatus(405);
            $this->response->throwJson([
                'code' => 405,
                'data' => 'Method Not Allowed: write endpoints require POST',
            ]);
        }

        $token = (string)$this->request->get('_', '');
        if ($token === '' || !hash_equals(self::token(), $token)) {
            $this->response->setStatus(403);
            $this->response->throwJson([
                'code' => 403,
                'data' => 'Invalid or missing token',
            ]);
        }
    }

}
