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
 * @version 3.0.0
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
        if (!extension_loaded('curl')) {
            throw new PluginException('需要 PHP 环境支持 cURL 扩展');
        }
        $msg = self::install();
        Helper::addPanel(1, self::$panel, _t('访问统计'), _t('统计控制台'), 'subscriber');
        Helper::addRoute('access_ip_geo', '/access/geo.json', '\TypechoPlugin\Access\Action', 'ipGeo');
        Helper::addRoute('access_track_flag', '/access/track/flag.gif', '\TypechoPlugin\Access\Action', 'writeLogs');
        Helper::addRoute('access_logs_delete', '/access/logs/delete.json', '\TypechoPlugin\Access\Action', 'deleteLogs');
        Helper::addRoute('access_logs_overview', '/access/overview.json', '\TypechoPlugin\Access\Action', 'overview');
        Helper::addRoute('access_logs_details', '/access/logs/get.json', '\TypechoPlugin\Access\Action', 'logsParse');
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

        // 如果 Redis 缓存为启用状态，删除所有缓存键
        if (isset($config->redisCache) && $config->redisCache == '1' && extension_loaded('redis')) {
            self::clearRedisCache($config);
        }

        if ($config->isDrop == 1) {
            $db = Db::get();
            $db->query("DROP TABLE `{$db->getPrefix()}access`", Db::WRITE);
            $cleanFlag = true;
        }
        Helper::removePanel(1, self::$panel);
        Helper::removeRoute('access_ip_geo');
        Helper::removeRoute('access_track_flag');
        Helper::removeRoute('access_logs_delete');
        Helper::removeRoute('access_logs_overview');
        Helper::removeRoute('access_logs_details');

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
        } catch (\Exception $e) {
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
        $isOversea = new Radio(
            'isOversea', [
                '0' => '中国大陆',
                '1' => '其他国家或地区',
            ], '1', '部署地点', '访客 IP 归属地判断接口种类，中国大陆接口在海外机器可能无法使用，请根据实际情况进行选择'
        );
        $ipInfoToken = new Text(
            'ipInfoToken', null, '',
            'IPinfo 接口令牌', 'IP 归属地查询接口令牌，请前往 <a href="https://ipinfo.io" target="_blank">ipinfo.io</a> 获取'
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
        $form->addInput($pageSize);
        $form->addInput($isDrop);
        $form->addInput($writeType);
        $form->addInput($isOversea);
        $form->addInput($ipInfoToken);
        $form->addInput($socks5Host);
        $form->addInput($socks5Auth);
        $form->addInput($redisCache);
        $form->addInput($redisHost);
        $form->addInput($redisPort->addRule('isInteger', _t('端口必须为纯数字')));
        $form->addInput($redisAuth);
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
     * 自定义配置处理，保存前校验 Redis 扩展
     *
     * @param array $settings 配置值
     * @param bool $isInit 是否为初始化
     * @return void
     * @throws DbException
     */
    public static function configHandle(array $settings, bool $isInit): void
    {
        if (!$isInit && isset($settings['redisCache']) && $settings['redisCache'] == '1') {
            if (!extension_loaded('redis')) {
                Notice::alloc()->set(_t('启用 Redis 缓存失败：PHP 未安装 redis 扩展，请先安装扩展后再启用'), 'error');
                $referer = Request::getInstance()->getReferer();
                Response::getInstance()
                    ->setStatus(302)
                    ->setHeader('Location', $referer ?: '/')
                    ->respond();
            }
        }

        Edit::configPlugin('Access', $settings);
    }

    /**
     * 初始化以及升级插件数据库，如初始化失败,直接抛出异常
     *
     * @return string
     * @throws DbException
     * @throws PluginException
     */
    public static function install(): string
    {
        if (!str_ends_with(trim(__DIR__, '/\\'), 'Access')) {
            throw new PluginException(_t('插件目录名必须为 Access，且首字母大写，请检查插件目录名是否正确'));
        }
        $db = Db::get();
        $adapterName = $db->getAdapterName();
        $msg = '';

        if (str_contains($adapterName, 'Mysql')) {
            $prefix = $db->getPrefix();
            $scripts = file_get_contents(__TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/Access/sql/MySQL.sql');
            $scripts = str_replace('typecho_', $prefix, $scripts);
            $scripts = str_replace('%charset%', 'utf8mb4', $scripts);
            $scripts = explode(';', $scripts);
            try {
                $configLink = '<a href="' . Helper::options()->adminUrl('options-plugin.php?config=Access', true) . '">' . _t('前往设置') . '</a>';
                # 初始化数据库如果不存在
                if (!$db->fetchRow($db->query("SHOW TABLES LIKE '{$prefix}access';", Db::READ))) {
                    foreach ($scripts as $script) {
                        $script = trim($script);
                        if ($script) {
                            $db->query($script, Db::WRITE);
                        }
                    }
                    $msg = _t('成功创建数据表，插件启用成功，') . $configLink;
                }
                # 处理旧版本数据
                if ($db->fetchRow($db->query("SHOW TABLES LIKE '{$prefix}access_log';", Db::READ))) {
                    $rows = $db->fetchAll($db->select()->from('table.access_log'));
                    set_time_limit(1800);
                    foreach ($rows as $row) {
                        $ua = new UA($row['ua']);
                        $row['browser_id'] = $ua->getBrowserID();
                        $row['browser_version'] = $ua->getBrowserVersion();
                        $row['os_id'] = $ua->getOSID();
                        $row['os_version'] = $ua->getOSVersion();
                        $row['path'] = parse_url($row['url'], PHP_URL_PATH);
                        $row['query_string'] = parse_url($row['url'], PHP_URL_QUERY);
                        $row['ip'] = bindec(decbin(ip2long($row['ip'])));
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
                    $db->query("DROP TABLE `{$prefix}access_log`;", Db::WRITE);
                    $msg = _t('检测到旧版数据残留，已更新数据表，插件启用成功，') . $configLink;
                }
                # 如果已经存在新版数据则跳过
                if ($db->fetchRow($db->query("SHOW TABLES LIKE '{$prefix}access';", Db::READ))) {
                    $msg = _t('数据表已存在，插件启用成功，') . $configLink;
                }
                return $msg;
            } catch (DbException $e) {
                throw new PluginException(_t('数据表建立失败，插件启用失败，错误信息：%s。', $e->getMessage()));
            } catch (\Exception $e) {
                throw new PluginException($e->getMessage());
            }
        } elseif (str_contains($adapterName, 'SQLite')) {
            $prefix = $db->getPrefix();
            $scripts = file_get_contents(__TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__ . '/Access/sql/SQLite.sql');
            $scripts = str_replace('typecho_', $prefix, $scripts);
            $scripts = explode(';', $scripts);
            try {
                $configLink = '<a href="' . Helper::options()->adminUrl('options-plugin.php?config=Access', true) . '">' . _t('前往设置') . '</a>';
                # 初始化数据库如果不存在
                if (!$db->fetchRow($db->query("SELECT name FROM sqlite_master WHERE TYPE='table' AND name='{$prefix}access';", Db::READ))) {
                    foreach ($scripts as $script) {
                        $script = trim($script);
                        if ($script) {
                            $db->query($script, Db::WRITE);
                        }
                    }
                    $msg = _t('成功创建数据表，插件启用成功，') . $configLink;
                } else {
                    $msg = _t('数据表已经存在，插件启用成功，') . $configLink;
                }
                return $msg;
            } catch (DbException $e) {
                throw new PluginException(_t('数据表建立失败，插件启用失败，错误信息：%s。', $e->getMessage()));
            } catch (\Exception $e) {
                throw new PluginException($e->getMessage());
            }
        } else {
            throw new PluginException(_t('当前适配器为%s，目前只支持 MySQL 和 SQLite', $adapterName));
        }
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
        $config = Options::alloc()->plugin('Access');

        if ($config->writeType == 1) {
            $access = new Core();
            $access->writeLogs($archive);
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
        $config = Options::alloc()->plugin('Access');
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
