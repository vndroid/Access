<?php

namespace TypechoPlugin\Access;

use Typecho\Db;
use Typecho\Db\Exception as DbException;
use Typecho\Plugin as TypechoPlugin;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Plugin\PluginInterface;
use Typecho\Request;
use Typecho\Response;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Password;
use Utils\Helper;
use Widget\Notice;
use Widget\Options;
use Widget\Plugins\Edit;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 图表式访问统计插件 for Typecho
 *
 * @package Access
 * @author Vex
 * @version 3.1.1
 * @link https://github.com/vndroid/Access
 */
class Plugin implements PluginInterface
{
    public static string $panel = 'Access/page/console.php';

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @return string
     * @throws DbException
     * @throws PluginException
     */
    public static function activate(): string
    {
        if (PHP_VERSION_ID < 80200) {
            throw new PluginException(_t('本插件需要 PHP 8.2 或更高版本，当前为 %s', PHP_VERSION));
        }
        if (!extension_loaded('curl')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 cURL 扩展'));
        }
        if (!extension_loaded('intl')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 intl 扩展'));
        }
        $msg = self::install();
        Helper::addPanel(1, self::$panel, _t('访问统计'), _t('统计控制台'), 'subscriber');
        Helper::addRoute('access_ip_geo', '/access/geo.json', '\TypechoPlugin\Access\Action', 'ipGeo');
        Helper::addRoute('access_track_flag', '/access/track/flag.gif', '\TypechoPlugin\Access\Action', 'writeLogs');
        Helper::addRoute('access_logs_delete', '/access/logs/delete.json', '\TypechoPlugin\Access\Action', 'deleteLogs');
        Helper::addRoute('access_logs_overview', '/access/overview.json', '\TypechoPlugin\Access\Action', 'overview');
        Helper::addRoute('access_logs_details', '/access/logs/get.json', '\TypechoPlugin\Access\Action', 'logsParse');
        Helper::addRoute('access_migrate', '/access/migrate.json', '\TypechoPlugin\Access\Action', 'migrate');
        TypechoPlugin::factory('\Widget\Archive')->beforeRender = [__CLASS__, 'backend'];
        TypechoPlugin::factory('\Widget\Archive')->footer = [__CLASS__, 'frontend'];
        TypechoPlugin::factory('admin/footer.php')->end = [__CLASS__, 'adminFooter'];
        return _t($msg);
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @return string
     * @throws DbException
     * @throws PluginException
 */
    public static function deactivate(): string
    {
        $cleanFlag = false;
        $config = Options::alloc()->plugin(basename(__DIR__));

        // 先把写入队列里积压的数据落库，避免随缓存一起被清掉
        try {
            (new Core())->flushQueue();
        } catch (\Throwable $e) {
        }

        // 如果 Redis 缓存为启用状态，删除所有缓存键
        if (isset($config->redisCache) && $config->redisCache == '1' && extension_loaded('redis')) {
            self::clearRedisCache($config);
        }

        if ($config->isDrop == 1) {
            // 数据表可能位于独立数据库中，这里要按插件实际使用的连接来删
            $db = Database::get();
            $table = Database::driver($db)->quoteTable($db->getPrefix() . 'access');
            $db->query("DROP TABLE IF EXISTS {$table}", Db::WRITE);
            $cleanFlag = true;
        }
        // 迁移标记存在独立的 options 行，禁用时一并清理
        try {
            Migrate::clearMark(Database::main());
        } catch (\Throwable $e) {
        }

        Helper::removePanel(1, self::$panel);
        Helper::removeRoute('access_ip_geo');
        Helper::removeRoute('access_track_flag');
        Helper::removeRoute('access_logs_delete');
        Helper::removeRoute('access_logs_overview');
        Helper::removeRoute('access_logs_details');
        Helper::removeRoute('access_migrate');

        return _t($cleanFlag ? '插件已禁用，数据表已清除' : '插件已禁用，数据表已保留');
    }

    /**
     * 清除 Redis 中所有 Access 插件的缓存键
     *
     * @param mixed $config 插件配置
     * @return void
     */
    private static function clearRedisCache($config): void
    {
        try {
            $redis = new \Redis();
            $host = $config->redisHost ?: '127.0.0.1';
            $port = (int)($config->redisPort ?: 6379);

            if (!$redis->connect($host, $port, 3)) {
                return;
            }

            $password = $config->redisAuth ?? '';
            if ($password !== '') {
                $redis->auth($password);
            }

            $redis->ping();

            // 使用 SCAN 迭代删除所有匹配前缀的键，避免 KEYS 阻塞
            $prefix = 'typecho_access:*';
            $iterator = null;
            while (($keys = $redis->scan($iterator, $prefix, 100)) !== false) {
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }

            $redis->close();
        } catch (\Throwable $e) {
            // 清除失败不影响禁用流程
        }
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form): void
    {
        # 3.1.0 早期版本把迁移标记写进了插件配置，Typecho 渲染设置页时会拿它去找
        # 同名表单控件从而报 Undefined array key，这里顺手清掉
        try {
            Migrate::cleanupLegacyMarker(Database::main());
        } catch (\Throwable $e) {
        }

        $pageSize = new Text(
            'pageSize', null, '20',
            '分页数量', '每页显示的日志数量'
        );
        $isDrop = new Radio(
            'isDrop', [
                '0' => '否',
                '1' => '是',
            ], '0', '数据清理', '在禁用插件时，同时删除数据库中历史数据（无法恢复）谨慎修改。'
        );
        $writeType = new Radio(
            'writeType', [
                '0' => '前端',
                '1' => '后端',
            ], '1', '统计类型', '日志写入类型（若选择为前端方式，如果使用了 PJAX，请在 PJAX 相关事件中调用 window.Access.track() 方法），若写入速度较慢可选择前端写入日志。'
        );
        $isPaid = new Radio(
            'isPaid', [
                '0' => 'Lite',
                '1' => 'Core',
            ], '0', 'IPinfo 接口类型', '默认使用 Lite（免费版），字段相比 Core（付费版）少'
        );
        $isToken = new Text(
            'isToken', null, '',
            'IPinfo 接口令牌', '接口调用令牌，请前往 <a href="https://ipinfo.io/dashboard" target="_blank">IPinfo</a> 面板获取'
        );
        $socks5Host = new Text(
            'socks5Host', null, '',
            'SOCKS5 代理地址', '格式为[主机:端口]，留空则不使用代理'
        );
        $socks5Auth = new Text(
            'socks5Auth', null, '',
            'SOCKS5 代理认证', '格式为 [用户名:密码]，留空则不使用认证'
        );
        $redisCache = new Radio(
            'redisCache', [
                '0' => '禁用',
                '1' => '启用',
            ], '0', '缓存加速',
            '启用后来源统计等慢查询结果会缓存至 Redis，提高访问速度'
        );
        $redisHost = new Text(
            'redisHost', null, '127.0.0.1',
            'Redis 地址', 'Redis 服务地址，默认为 127.0.0.1'
        );
        $redisPort = new Text(
            'redisPort', null, '6379',
            'Redis 端口', 'Redis 服务端口，默认为 6379'
        );
        $redisAuth = new Text(
            'redisAuth', null, '',
            'Redis 认证', 'Redis 服务密码，默认留空无密码'
        );
        $writeQueue = new Radio(
            'writeQueue', [
                '1' => '自动',
                '0' => '禁用',
            ], '1', '写入队列',
            '在上面启用了「缓存加速」（即配置了 Redis）时，访问日志先写入 Redis 队列，'
            . '攒够一批再一次性入库，可以显著降低突发流量下的数据库连接数与写入压力。'
            . '未配置 Redis 时本项无效，写入行为与之前一致。'
        );
        $queueFlushSize = new Text(
            'queueFlushSize', null, '500',
            '队列刷新条数', '队列积压达到该条数时触发一次入库，默认 500'
        );
        $queueFlushInterval = new Text(
            'queueFlushInterval', null, '60',
            '队列刷新间隔', '距上次入库超过该秒数也会触发入库，避免低流量站点数据长时间滞留，默认 60'
        );
        $dbType = new Select(
            'dbType', DbType::options(), DbType::Follow->value, '统计数据库',
            '统计数据存放的位置。选择“跟随 Typecho”即与博客共用一个库；'
            . '选择其它类型则使用下方独立配置的数据库，保存设置时会自动建表，'
            . '并把主库中已有的统计数据迁移过去。历史数据超过 '
            . Migrate::AUTO_LIMIT . ' 条时不在保存设置时直接迁移，'
            . '改为在统计控制台用进度条分批完成，也可以执行命令行脚本 '
            . '<code>tools/migrate.php</code>，两者都支持断点续传。'
        );
        $dbHost = new Text(
            'dbHost', null, '127.0.0.1',
            '统计数据库地址', '独立数据库的主机名或 IP，MySQL 也可填写 unix socket 路径。仅在上方选择了独立数据库时生效'
        );
        $dbPort = new Text(
            'dbPort', null, '',
            '统计数据库端口', '留空则按类型使用默认端口（MySQL 3306，PostgreSQL 5432）'
        );
        $dbUser = new Text(
            'dbUser', null, '',
            '统计数据库用户名', '连接独立数据库使用的用户名'
        );
        $dbPass = new Password(
            'dbPass', null, '',
            '统计数据库密码', '连接独立数据库使用的密码，留空表示无密码'
        );
        $dbName = new Text(
            'dbName', null, '',
            '统计数据库名称', '独立数据库的库名，选择独立数据库时必填（需要预先创建好，插件只负责建表）'
        );
        $dbPrefix = new Text(
            'dbPrefix', null, 'typecho_',
            '统计数据表前缀', '独立数据库中数据表的前缀，最终表名为 [前缀]access'
        );
        $dbCharset = new Text(
            'dbCharset', null, '',
            '统计数据库字符集', '留空则按类型使用默认值（MySQL utf8mb4，PostgreSQL utf8）'
        );
        $form->addInput($pageSize);
        $form->addInput($isDrop);
        $form->addInput($writeType);
        $form->addInput($isPaid);
        $form->addInput($isToken);
        $form->addInput($socks5Host);
        $form->addInput($socks5Auth);
        $form->addInput($redisCache);
        $form->addInput($redisHost);
        $form->addInput($redisPort->addRule('isInteger', _t('端口必须为纯数字')));
        $form->addInput($redisAuth);
        $form->addInput($writeQueue);
        $form->addInput($queueFlushSize->addRule('isInteger', _t('刷新条数必须为纯数字')));
        $form->addInput($queueFlushInterval->addRule('isInteger', _t('刷新间隔必须为纯数字')));
        $form->addInput($dbType);
        $form->addInput($dbHost);
        $form->addInput($dbPort);
        $form->addInput($dbUser);
        $form->addInput($dbPass);
        $form->addInput($dbName);
        $form->addInput($dbPrefix);
        $form->addInput($dbCharset);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     * @return void
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 自定义配置处理，保存前校验 Redis 扩展与独立数据库连接
     *
     * @param array $settings 配置值
     * @param bool $isInit 是否为初始化
     * @return void
     * @throws DbException
     */
    public static function configHandle(array $settings, bool $isInit): void
    {
        # 插件启用时的初始化调用，此时 activate() 已经建过表，直接落库即可
        if ($isInit) {
            Edit::configPlugin('Access', $settings);
            return;
        }

        if (isset($settings['redisCache']) && $settings['redisCache'] == '1' && !extension_loaded('redis')) {
            self::goBack(_t('启用 Redis 缓存失败：PHP 未安装 redis 扩展，请先安装扩展后再启用'), 'error');
        }

        # 校验独立数据库配置，连不上就不保存，避免把插件配坏
        $dbSettings = Database::settings($settings);
        if ($dbSettings['type']->isExternal()) {
            $error = Database::test($dbSettings);
            if ($error !== null) {
                self::goBack(_t('统计数据库连接失败，配置未保存：%s', $error), 'error');
            }
        }

        Edit::configPlugin('Access', $settings);

        # 配置保存后，按新的数据库设置建表并迁移历史数据
        try {
            $msg = self::install($settings);
        } catch (\Throwable $e) {
            self::goBack(_t('插件设置已经保存，但初始化数据表失败：%s', $e->getMessage()), 'error');
        }

        self::goBack(_t('插件设置已经保存。%s', $msg), 'success');
    }

    /**
     * 设置提示信息并返回来源页面
     *
     * respond() 会结束整个请求（sandbox 模式下以异常形式），所以这里永不返回
     *
     * @param string $message
     * @param string $type
     * @return never
     */
    private static function goBack(string $message, string $type = 'notice'): never
    {
        Notice::alloc()->set($message, $type);
        $referer = Request::getInstance()->getReferer();
        Response::getInstance()
            ->setStatus(302)
            ->setHeader('Location', $referer ?: '/')
            ->respond();
    }

    /**
     * 初始化以及升级插件数据库，如初始化失败,直接抛出异常
     *
     * @param array|null $settings 保存配置时传入的新配置，为 null 时使用已保存的配置
     * @return string
     * @throws DbException
     * @throws PluginException
     */
    public static function install(?array $settings = null): string
    {
        if (!str_ends_with(trim(__DIR__, '/\\'), 'Access')) {
            throw new PluginException(_t('插件目录名必须为 Access，且首字母大写，请检查插件目录名是否正确'));
        }

        $external = Database::isExternal($settings);
        try {
            $db = Database::get($settings);
        } catch (\Throwable $e) {
            throw new PluginException(_t('无法连接到配置的统计数据库，错误信息：%s。', $e->getMessage()));
        }

        $driver = Database::driver($db);
        $prefix = $db->getPrefix();

        $configLink = '<a href="' . Helper::options()->adminUrl('options-plugin.php?config=Access', true) . '">'
            . _t('前往设置') . '</a>';
        $where = $external
            ? _t('（独立数据库 %s，表 %saccess）', strtoupper($driver->value), $prefix)
            : '';

        try {
            $created = false;
            if (!Database::tableExists($db, $prefix . 'access')) {
                $scripts = file_get_contents(
                    __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/Access/sql/' . $driver->schemaFile()
                );
                $scripts = str_replace('typecho_', $prefix, $scripts);
                $scripts = str_replace('%charset%', 'utf8mb4', $scripts);
                foreach (explode(';', $scripts) as $script) {
                    $script = trim($script);
                    if ($script !== '' && strtoupper($script) !== 'COMMIT') {
                        $db->query($script, Db::WRITE);
                    }
                }
                $created = true;
                $msg = _t('成功创建数据表%s，插件启用成功，', $where) . $configLink;
            } else {
                $msg = _t('数据表已经存在%s，插件启用成功，', $where) . $configLink;
            }

            if ($external) {
                # 把主库里已有的统计数据搬过去；每次进来都检查，
                # 上次被超时截断的迁移会在这里自动接着做完
                $migration = Migrate::ensure(
                    $db,
                    Database::settings($settings),
                    microtime(true) + Migrate::AUTO_DEADLINE
                );
                $note = self::migrationNotice($migration, $created, $where);
                if ($note !== null) {
                    $msg = $note . $configLink;
                }
            } else {
                # 处理旧版本 access_log 残留数据（仅存在于主库）
                if (self::upgradeLegacyTable($db, $prefix)) {
                    $msg = _t('检测到旧版数据残留，已更新数据表，插件启用成功，') . $configLink;
                }
            }

            return $msg;
        } catch (PluginException $e) {
            throw $e;
        } catch (DbException $e) {
            throw new PluginException(_t('数据表建立失败，插件启用失败，错误信息：%s。', $e->getMessage()));
        } catch (\Throwable $e) {
            throw new PluginException($e->getMessage());
        }
    }

    /**
     * 迁移旧版 access_log 表的数据
     *
     * @param Db $db
     * @param string $prefix
     * @return bool 是否发生了迁移
     * @throws PluginException
     */
    private static function upgradeLegacyTable(Db $db, string $prefix): bool
    {
        if (!Database::tableExists($db, $prefix . 'access_log')) {
            return false;
        }

        $rows = $db->fetchAll($db->select()->from('table.access_log'));
        @set_time_limit(1800);
        foreach ($rows as $row) {
            $ua = new UA($row['ua']);
            $row['browser_id'] = $ua->getBrowserID();
            $row['browser_version'] = $ua->getBrowserVersion();
            $row['os_id'] = $ua->getOSID();
            $row['os_version'] = $ua->getOSVersion();
            $row['path'] = parse_url($row['url'], PHP_URL_PATH);
            $row['query_string'] = parse_url($row['url'], PHP_URL_QUERY);
            $row['ip'] = (string)bindec(decbin((int)ip2long($row['ip'])));
            $row['entrypoint'] = $row['referer'];
            $row['entrypoint_domain'] = $row['referer_domain'];
            $row['time'] = $row['date'];
            $row['robot'] = $ua->isRobot() ? 1 : 0;
            $row['robot_id'] = $ua->getRobotID();
            $row['robot_version'] = $ua->getRobotVersion();
            unset($row['date']);
            try {
                $db->query($db->insert('table.access')->rows($row));
            } catch (DbException $e) {
                if ($e->getCode() != 23000) {
                    throw new PluginException(_t('导入旧版数据失败，插件启用失败，错误信息：%s。', $e->getMessage()));
                }
            }
        }

        $legacy = Database::driver($db)->quoteTable($prefix . 'access_log');
        $db->query("DROP TABLE {$legacy}", Db::WRITE);

        return true;
    }

    /**
     * 根据迁移结果生成后台提示文案
     *
     * @param array $migration Migrate::ensure() 的返回值
     * @param bool $created 本次是否新建了数据表
     * @param string $where 数据库位置描述
     * @return string|null 为 null 表示沿用默认提示
     */
    private static function migrationNotice(array $migration, bool $created, string $where): ?string
    {
        $script = 'php ' . trim(__TYPECHO_PLUGIN_DIR__, '/') . '/Access/tools/migrate.php';
        $console = Helper::options()->adminUrl('extending.php?panel=' . urlencode(self::$panel), true);

        return match ($migration['status']) {
            MigrateStatus::Done => _t(
                '%s，已迁移 %s 条历史数据，插件启用成功，',
                $created ? _t('成功创建数据表%s', $where) : _t('数据表已经存在%s', $where),
                $migration['moved']
            ),

            MigrateStatus::Partial => _t(
                '数据表已就绪%s，本次迁移了 %s 条，还剩 %s 条未迁移。'
                . '请前往 <a href="%s">统计控制台</a> 用进度条继续迁移，'
                . '或在网站根目录执行 <code>%s</code>。两种方式都支持断点续传。',
                $where,
                $migration['moved'],
                $migration['pending'],
                $console,
                $script
            ),

            MigrateStatus::Skipped => _t(
                '数据表已就绪%s。主库中有 %s 条历史数据待迁移，数量较大未在保存设置时直接执行，'
                . '请前往 <a href="%s">统计控制台</a> 点击“开始迁移”，'
                . '或在网站根目录执行 <code>%s</code>。若不需要历史数据可忽略此提示。',
                $where,
                $migration['pending'],
                $console,
                $script
            ),

            MigrateStatus::None, MigrateStatus::Already => null,
        };
    }

    /**
     * 获取后端统计，该统计方法可以统计到一切访问
     *
     * @param $archive
     * @return void
     * @throws PluginException
     */
    public static function backend($archive): void
    {
        // 统计失败（例如独立数据库暂时不可用）不应该影响博客本身的访问
        try {
            $config = Options::alloc()->plugin('Access');

            if ($config->writeType == 1) {
                $access = new Core();
                $access->writeLogs($archive);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * 获取前端统计，该方法要求客户端必须渲染网页，所以不能统计 RSS 等直接抓取页面的方式
     *
     * @param $archive
     * @return void
     * @throws PluginException
     */
    public static function frontend($archive): void
    {
        try {
            $config = Options::alloc()->plugin('Access');
        } catch (\Throwable $e) {
            return;
        }
        if ($config->writeType == 0) {
            $index = rtrim(Helper::options()->index, '/');
            $access = new Core();
            $parsedArchive = $access->parseArchive($archive);
            echo "<script type=\"text/javascript\">(function(w){var t=function(){var i=new Image();i.src='{$index}/access/track/flag.gif?u='+location.pathname+location.search+location.hash+'&cid={$parsedArchive['content_id']}&mid={$parsedArchive['meta_id']}&rand='+new Date().getTime()};t();var a={};a.track=t;w.Access=a})(this);</script>";
        }
    }

    /**
     * 后台页脚
     *
     * @return void
     */
    public static function adminFooter(): void
    {
        $url = $_SERVER['PHP_SELF'];
        $filename = substr($url, strrpos($url, '/') + 1);
        if ($filename === 'index.php') {
            echo '<script>
$(document).ready(function() {
  $("#start-link").append("<li><a href=\"';
            Helper::options()->adminUrl('extending.php?panel=' . self::$panel);
            echo '\">' . _t('访问统计') . '</a></li>");
});
</script>';
        }
    }
}
