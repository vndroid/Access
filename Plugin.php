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
 * @version 3.2.3
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
            throw new PluginException(_t('本插件需要满足 PHP 8.2+ 环境，当前为 %s', PHP_VERSION));
        }
        if (!extension_loaded('curl')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 cURL 扩展'));
        }
        if (!extension_loaded('intl')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 intl 扩展'));
        }
        /*
         * mbstring：Queue::normalize() 按字符数截断各列（varchar(N) 数的是字符不是字节），
         * 用的是 mb_strlen()/mb_substr()。缺了它，每一条访问日志的入队都会致命错误。
         */
        if (!extension_loaded('mbstring')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 mbstring 扩展'));
        }
        /*
         * GMP：IPv6 地址要在 128 位整数和点分表示之间来回换算，超出 PHP 原生整型范围，
         * Core 里走的是 gmp_init()/gmp_strval()。只在 IPv6 访客到来时才用到，
         * 所以缺了它平时看不出问题 —— 正因如此更该在启用时就拦下来。
         */
        if (!extension_loaded('gmp')) {
            throw new PluginException(_t('检测到当前 PHP 环境缺失 GMP 扩展（用于 IPv6 地址解析）'));
        }
        /*
         * event_id 的前 16 位十六进制是「毫秒时间戳左移 16 位」，
         * 32 位 PHP 上这个移位会溢出，生成的标识失去按毫秒聚簇的前缀，唯一索引的写入
         * 会退化成全表随机页写入。要求 64 位不是洁癖，是这个设计的前提。
         * 写入队列的事件标识依赖 64 位整数运算。
         */
        if (PHP_INT_SIZE < 8) {
            throw new PluginException(_t('仅支持 64 位 PHP 环境'));
        }
        # 有 config/current.yaml 就以它为准。注意这里只读文件，
        # 把设置数组直接交给 install()——Options 组件在一次请求里只读一遍插件配置，
        # 就算先写进 options 表它也看不到，建表仍会用旧的数据库设置。
        [$applied, $configNote] = self::readFileConfig();

        $msg = self::install($applied);

        # 建表成功之后才把文件里的配置落库：
        # 否则一份指向连不上的数据库的配置文件会在启用失败的同时覆盖掉原有配置
        if ($applied !== null) {
            try {
                Edit::configPlugin(basename(__DIR__), $applied);
            } catch (\Throwable $e) {
                $configNote = _t('配置文件已生效但未能保存到数据库（%s）。', $e->getMessage());
            }
        }

        if ($configNote !== '') {
            $msg = $configNote . $msg;
        }

        # 配置里启用了 Redis 就在这里探测一次
        $msg .= self::probeRedis($applied);

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

        // 把当前配置写回 config/current.yaml，下次启用时自动恢复。
        // 写失败（目录只读等）不影响禁用流程。
        // 注意这只保住了「配置」，保不住 Redis 队列里的访客数据，那是下面一步的事。
        $saved = Settings::save($config);

        /*
         * 先尽力把写入队列刷干净，并且拿到确切结果。
         *
         * 这里以前调的是 flushQueue()：单次最多 FLUSH_LIMIT 条、抢不到锁直接返回 0、
         * 返回值还被丢掉，于是「刷过一次」被当成了「刷完了」，
         * 紧接着 clearRedisCache() 把本插件的键一把梭删掉 ——
         * 队列里没落库的访客数据就这么无声消失了。
         * 现在改成 drainQueue()：循环刷到取空为止，并如实回报还剩没剩。
         */
        $drain = ['written' => 0, 'pending' => null, 'dead' => 0, 'clean' => false, 'error' => null];
        try {
            $drain = (new Core())->drainQueue();
        } catch (\Throwable $e) {
            $drain['error'] = $e->getMessage();
        }

        /*
         * isDrop=1 是用户明确要求连历史数据一起删，队列里的自然也在其中；
         * 否则只要 Redis 里还留着没落库的消息，就把队列（含死信）原样保留，
         * 下次启用插件时会接着写入。
         */
        $keepQueue = $config->isDrop != 1 && !$drain['clean'];

        // 如果 Redis 缓存为启用状态，删除缓存键
        if (isset($config->redisCache) && $config->redisCache == '1' && extension_loaded('redis')) {
            self::clearRedisCache($config, $keepQueue);
        }

        $dropNote = '';
        if ($config->isDrop == 1) {
            try {
                // 数据表可能位于独立数据库中，这里要按插件实际使用的连接来删
                $db = Database::get();
                $table = Database::driver($db)->quoteTable($db->getPrefix() . 'access');
                $db->query("DROP TABLE IF EXISTS {$table}", Db::WRITE);
                $cleanFlag = true;
                # 表都删了，记着的结构版本也该一起清掉，否则下次建表会被当成「已经是最新的」
                Schema::forget(Database::main(), Migrate::fingerprint(Database::settings($config)));
            } catch (\Throwable $e) {
                // 数据库暂时连不上不该让插件卡在「停用不掉」的状态。
                // 这条提示显示在后台通知条里，原始异常可能很长，截断后再拼
                $reason = mb_strimwidth(trim($e->getMessage()), 0, 40, '…', 'UTF-8');
                $dropNote = sprintf('，但数据表未能删除（%s）', $reason);
            }
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

        $msg = $cleanFlag ? '插件已禁用，数据表已清除' : '插件已禁用，数据表已保留';
        $msg .= $dropNote;
        $msg .= $saved
            ? '，当前配置已写入至 current.yaml 中'
            : '，配置因目录或文件不可写未能写入';
        $msg .= self::drainNote($drain, $keepQueue);

        return _t($msg);
    }

    /**
     * 清除 Redis 中 Access 插件的缓存键
     *
     * @param mixed $config 插件配置
     * @param bool $keepQueue 为 true 时跳过队列相关的键（队列、死信、锁、刷库时间戳）
     * @return void
     */
    private static function clearRedisCache($config, bool $keepQueue = false): void
    {
        try {
            $redis = Health::connect(
                $config->redisHost ?: '127.0.0.1',
                (int)($config->redisPort ?: 6379),
                (string)($config->redisAuth ?? '')
            );

            /*
             * 使用 SCAN 迭代删除所有匹配前缀的键，避免 KEYS 阻塞。
             *
             * 要扫两个前缀：新规范的 plugin:access:*，以及 v3.2.3 之前的
             * typecho_access:*。只扫新前缀的话，换前缀之前留下的键会永远残留。
             *
             * 两个前缀下都混着别的站点的键，所以只删两类：本站点指纹下的键，
             * 以及旧前缀里加指纹之前留下的第一代老键（判定见 Cache::isLegacyKey()）。
             * 同一个 Redis 上别的站点的键必须原样放过 —— 以前键名不带指纹，
             * 这里的「一把梭删掉」就是在删别人的队列。
             */
            $mine = Cache::prefix();
            foreach ([Cache::BASE . '*', Cache::LEGACY_BASE . '*'] as $pattern) {
                $iterator = null;
                while (($keys = $redis->scan($iterator, $pattern, 100)) !== false) {
                    $keys = array_values(array_filter($keys, static function ($key) use ($mine) {
                        $key = (string)$key;
                        return str_starts_with($key, $mine) || Cache::isLegacyKey($key);
                    }));

                    if ($keepQueue) {
                        /*
                         * 队列、死信、锁、刷库时间戳装的是还没落库的数据和它的处理状态，
                         * 不是缓存。缓存删了会重算，这些删了就没了。
                         */
                        $keys = array_values(array_filter(
                            $keys,
                            static fn($key) => !Queue::isDataKey((string)$key)
                                && !Queue::isLegacyDataKey((string)$key)
                        ));
                    }

                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                }
            }

            $redis->close();
        } catch (\Throwable $e) {
            // 清除失败不影响禁用流程
        }
    }

    /**
     * 把队列的收尾情况说清楚
     *
     * 这条提示显示在后台的通知条里，位置很窄，所以只讲两件事：
     * 还剩多少没落库、它们去哪了。原始异常之类的细节一概不进这里。
     *
     * @param array $drain Core::drainQueue() 的返回值
     * @param bool $keepQueue 是否保留了队列
     * @return string
     */
    private static function drainNote(array $drain, bool $keepQueue): string
    {
        if ($drain['clean']) {
            return $drain['written'] > 0
                ? sprintf('，刷入积压 %s 条', number_format($drain['written']))
                : '';
        }

        if ($drain['pending'] === null) {
            return $keepQueue ? '，Redis 状态未知，队列已保留' : '，Redis 状态未知';
        }

        # 待写入和死信都是「没进数据库的数据」，通知条里合成一个数字就够了，
        # 死信的明细留给命令行工具
        $left = (int)$drain['pending'] + (int)$drain['dead'];
        if ($left === 0) {
            return '，队列状态未确认';
        }

        return $keepQueue
            ? sprintf('，另有 %s 条未落库已保留', number_format($left))
            : sprintf('，另有 %s 条未落库已清除', number_format($left));
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
            ], '1', '写入缓冲',
            '在启用了「缓存加速」时，访问日志先写入 Redis 队列，'
            . '可以显著降低突发流量下的数据库连接数与写入压力，'
            . '未配置 Redis 时本项自动禁用，写入行为与之前一致。'
        );
        $queueSwitch = new Radio(
            'queueSwitch', [
                'safe'  => '安全',
                'force' => '强制切换一次',
            ], 'safe', '队列归属变更',
            '改动 Redis 地址、写入缓冲开关或统计数据库，都会让已经攒在旧队列里的消息失去归属。'
            . '「安全」会先用旧配置把队列刷干净，刷不干净就拒绝保存，'
            . '避免旧数据写错库或永远留在旧 Redis 里。'
            . '确实要丢下这些消息时选「强制切换一次」，本次保存生效后自动复位为「安全」。'
        );
        $queueFlushSize = new Text(
            'queueFlushSize', null, '100',
            '队列刷新条数', '队列积压达到该条数时触发一次入库，默认为 100 条'
        );
        $queueFlushInterval = new Text(
            'queueFlushInterval', null, '60',
            '队列刷新间隔', '距上次入库超过阈值时间也会触发入库，避免低流量站点数据长时间滞留，默认 60 秒'
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
        $form->addInput($queueSwitch);
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
        /*
         * 插件启用时的初始化调用，此时 activate() 已经建过表。
         *
         * 注意这里传进来的 $settings 是「表单控件的默认值」，不是任何人填过的东西：
         * Typecho 在 activate() 返回之后会再走一遍
         *     $form = new Form(); $class::config($form);
         *     $class::configHandle($form->getValues(), true);
         * （见 var/Widget/Plugins/Edit.php::activate）
         * 照单全收的话，activate() 里刚从 config/current.yaml 写进去的配置
         * 会在这一步被整个覆盖回默认值 —— 表建在了文件指定的库里，
         * 配置却是默认的，表现就是「提示读到了配置文件，但设置没生效」。
         *
         * 所以这一步以配置文件为准，没有配置文件时才用传进来的默认值。
         */
        if ($isInit) {
            try {
                $fromFile = Settings::load();
            } catch (\Throwable $e) {
                $fromFile = null;
            }

            Edit::configPlugin('Access', $fromFile ?? $settings);
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

        /*
         * 接管必须排在 drain 前面。
         *
         * 升级到带站点指纹的键名之后，第一次保存设置时老队列还压在旧键名上：
         * 这时 drain 看的是新键名（空的），会得出「干净」的结论放行保存，
         * 而接管一旦发生在保存之后，那批本属于旧库的消息就被刷进新库了。
         * 接管用的是「旧配置」的 Redis —— 老队列在那儿，不在新配的 Redis 上。
         */
        $adoptNote = '';
        try {
            $adoptNote = self::adoptLegacyQueue(Options::alloc()->plugin('Access'));
        } catch (\Throwable $e) {
            // 读不到旧配置说明还没配过，也就没有老队列
        }

        /*
         * 新配置会换掉队列的归属时，先用「旧配置」把积压刷干净。
         * 保存之后再刷来不及：改了 Redis 地址就连不上老队列，
         * 改了统计数据库则会把老消息写进新库 —— 两种都是无声的数据错位。
         * 必须赶在 configPlugin() 之前，Core 读的是当下生效的那份配置。
         */
        $drain = self::drainBeforeSwitch($settings);
        if ($drain['blocked']) {
            # 保存被拦下，配置原样不动 —— 旧队列还有主人，不能在这时候换掉它
            self::goBack($drain['note'], 'error');
        }

        /*
         * 「强制切换」只对本次保存生效：留着不复位的话，这道闸门就被永久关掉了，
         * 而它恰恰是那种「平时不该起作用、起作用时很关键」的保护。
         */
        if (($settings['queueSwitch'] ?? 'safe') === 'force') {
            $settings['queueSwitch'] = 'safe';
        }

        $drainNote = $adoptNote . $drain['note'];

        Edit::configPlugin('Access', $settings);

        # 配置保存后，按新的数据库设置建表并迁移历史数据
        try {
            $msg = self::install($settings);
        } catch (\Throwable $e) {
            self::goBack(_t('插件设置已经保存，但初始化数据表失败：%s', $e->getMessage()), 'error');
        }

        # 顺带探测一次 Redis，避免在后台改完设置却毫无反馈
        $msg .= self::probeRedis($settings);

        self::goBack(_t('插件设置已经保存。%s%s', $msg, $drainNote), 'success');
    }

    /**
     * 表结构升级的结果说明
     *
     * @param array $schema Schema::ensure() 的返回值
     * @return string
     */
    private static function schemaNotice(array $schema): string
    {
        if ($schema['error'] !== null) {
            return _t(
                '（数据表结构升级未完成：%s，请修复后重新启用插件）',
                mb_strimwidth(trim($schema['error']), 0, 60, '…', 'UTF-8')
            );
        }

        if (!empty($schema['repaired'])) {
            # 版本号本来就是最新的，但实地校验发现结构缺了东西并补上了 —— 值得说一声
            return _t('（数据表结构校验补齐了：%s）', implode('、', $schema['repaired']));
        }

        return empty($schema['applied'])
            ? ''
            : _t('（数据表结构已由 %s 升级至 %s）', $schema['from'] ?? '3.1.x', $schema['to']);
    }

    /**
     * 配置切换前先把旧队列刷干净
     *
     * 刷不干净时返回 blocked=true，调用方必须放弃保存。
     * 以前这里只返回一句提示、配置照样保存，等于把「队列还有主人」这件事
     * 降级成了一条用户多半不会读的黄字。
     *
     * @param array $settings 即将保存的新配置
     * @return array{blocked:bool,note:string} note 为要展示给用户的说明，无事发生时为空串
     */
    private static function drainBeforeSwitch(array $settings): array
    {
        $pass = ['blocked' => false, 'note' => ''];

        try {
            $old = Options::alloc()->plugin('Access');
        } catch (\Throwable $e) {
            # 读不到旧配置说明插件还没配过，也就没有旧队列
            return $pass;
        }

        # 归属没变的话，老消息在新配置下照样会被消费，不用动它
        if (self::queueHome($old) === self::queueHome($settings)) {
            return $pass;
        }

        try {
            $drain = (new Core())->drainQueue();
        } catch (\Throwable $e) {
            # 连队列状态都确认不了，更不能当成「干净」放行
            $drain = [
                'clean' => false, 'written' => 0, 'pending' => null,
                'dead' => 0, 'error' => $e->getMessage(),
            ];
        }

        if ($drain['clean']) {
            return [
                'blocked' => false,
                'note' => $drain['written'] > 0
                    ? _t('切换前已刷入积压 %s 条。', number_format($drain['written']))
                    : '',
            ];
        }

        $left = $drain['pending'] === null
            ? _t('若干')
            : number_format((int)$drain['pending'] + (int)$drain['dead']);

        /*
         * 后果分两种，提示不能混为一谈：
         * Redis 目标变了 —— 旧队列留在旧 Redis 里，新配置根本连不上，等同于丢弃；
         * Redis 没变，只换了统计库或关了写入缓冲 —— 同一个队列会被继续消费，
         *   但落进新库，这批本该属于旧库的数据就此错位。
         *   原来那句「新配置不会再处理」在这种场景下是反的。
         */
        $consequence = self::redisHome($old) === self::redisHome($settings)
            ? _t('这些消息仍在同一个 Redis 里，保存后会被继续消费，但会写进新的统计数据库，造成数据错位。')
            : _t('这些消息会留在原来的 Redis 中，新配置连不上它们，等同于丢弃。');

        $why = empty($drain['error']) ? '' : _t('（%s）', $drain['error']);

        if (($settings['queueSwitch'] ?? 'safe') === 'force') {
            return [
                'blocked' => false,
                'note' => _t('注意：已按「强制切换一次」保存，旧队列中还有 %s 条未落库。%s', $left, $consequence),
            ];
        }

        return [
            'blocked' => true,
            'note' => _t(
                '配置未保存：旧队列中还有 %s 条未落库。%s%s '
                . '请先执行 tools/flush-queue.php 把队列刷干净后重试；'
                . '确实要丢下这些消息时，把「队列归属变更」改为「强制切换一次」再保存。',
                $left,
                $consequence,
                $why
            ),
        ];
    }

    /**
     * Redis 目标指纹：只看「连的是哪个 Redis」，不含统计库
     *
     * queueHome() 用来判断「要不要刷」，这个用来判断「刷不干净会怎样」——
     * Redis 没变的话旧消息还够得着，只是会写错库；变了就是彻底够不着。
     *
     * @param array|\Typecho\Config|null $config
     * @return string
     */
    private static function redisHome($config): string
    {
        $target = Health::redisTarget($config);

        return $target === null
            ? 'redis-off'
            : $target['host'] . ':' . $target['port'] . ':' . $target['auth'];
    }

    /**
     * 队列的「归属」指纹
     *
     * 换掉其中任何一项，原来那批消息就不再属于新配置：
     * Redis 目标变了连不上老队列，写入队列关了没人消费，统计库变了会写错地方。
     *
     * @param array|\Typecho\Config|null $config
     * @return string
     */
    private static function queueHome($config): string
    {
        $writeQueue = '';
        if (is_array($config) || $config instanceof \ArrayAccess) {
            $writeQueue = (string)($config['writeQueue'] ?? '');
        } elseif (is_object($config)) {
            $writeQueue = (string)($config->writeQueue ?? '');
        }

        return md5(implode('|', [
            self::redisHome($config),
            $writeQueue === '0' ? 'queue-off' : 'queue-on',
            Migrate::fingerprint(Database::settings($config)),
        ]));
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
    /**
     * 探测配置里的 Redis 是否可用
     *
     * Redis 只是加速器，连不上不该拦住插件启用 —— 缓存与写入队列会自动降级，
     * 统计功能是完整的。但探测本身有价值：把「不可用」这个问题记进熔断器，
     * 于是接下来的前台请求直接走降级路径，不必每个访客各撞一次连接超时。
     *
     * @param array|null $applied 本次从配置文件读到的设置，为 null 时读数据库里已保存的
     * @return string 附加到启用提示后面的说明，正常时为空串
     */
    private static function probeRedis(?array $applied): string
    {
        $config = $applied;
        if ($config === null) {
            try {
                $config = Options::alloc()->plugin(basename(__DIR__));
            } catch (\Throwable $e) {
                return '';
            }
        }

        if (Health::redisTarget($config) === null) {
            # 没启用缓存加速，没什么可探测的
            return '';
        }

        $error = Health::probeRedis($config);
        if ($error === null) {
            return self::adoptLegacyQueue($config);
        }

        return _t(
            '（注意：已配置 Redis 但当前不可用 —— %s，缓存与写入队列已自动降级，统计功能不受影响；'
            . '恢复后无需改动，插件会自动重新连接。）',
            $error
        );
    }

    /**
     * 把加站点指纹之前遗留的队列接管过来
     *
     * 键名带上站点指纹之后，老键上的队列会突然没人消费 —— 生产者写新键、
     * 消费者读新键，老键里没落库的访问日志就一直搁在那儿。
     * 保存设置是升级后必经的一步（表结构升级也在这里做），顺手接管掉。
     *
     * @param mixed $config 插件配置
     * @return string 要附加给用户的提示，无事发生时为空串
     */
    private static function adoptLegacyQueue($config): string
    {
        try {
            $target = Health::redisTarget($config);
            if ($target === null) {
                return '';
            }

            $redis = Health::connect($target['host'], $target['port'], $target['auth']);
            $moved = Queue::adoptLegacy($redis);
            $redis->close();
        } catch (\Throwable $e) {
            return '';
        }

        $note = '';
        if (!empty($moved['adopted'])) {
            $note .= _t('（已接管升级前遗留的队列：%s）', implode('、', $moved['adopted']));
        }
        if (!empty($moved['skipped'])) {
            $note .= _t(
                '（注意：%s 里还有升级前遗留的数据，但当前站点的队列已在使用中，未做合并，请人工处理）',
                implode('、', $moved['skipped'])
            );
        }

        return $note;
    }

    /**
     * 启用时读取 config/current.yaml
     *
     * 文件不存在是常态，什么都不做；解析失败也不阻断启用，
     * 只把原因带进启用成功的提示里，避免一个格式错误让插件装不上。
     *
     * @return array{0: array|null, 1: string} 读到的设置（没有则 null），以及要附加到启用提示前的说明
     */
    private static function readFileConfig(): array
    {
        try {
            $settings = Settings::load();
        } catch (\Throwable $e) {
            return [null, _t('配置文件加载失败（%s），本次沿用已有配置。', $e->getMessage())];
        }

        return $settings === null
            ? [null, '']
            : [$settings, _t('已从 config/current.yaml 加载插件配置。')];
    }

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
                    if ($script === '' || strtoupper($script) === 'COMMIT') {
                        continue;
                    }
                    /*
                     * 按分号切分是个很粗的做法：整行注释里只要出现一个分号，
                     * 就会在注释中间切一刀，切出来的碎片全是注释、一条语句都没有。
                     * 把它原样丢给数据库执行不合适，剥掉行注释后什么都不剩的直接跳过。
                     */
                    if (trim(preg_replace('/^\s*--.*$/m', '', $script)) === '') {
                        continue;
                    }
                    $db->query($script, Db::WRITE);
                }
                $created = true;
                $msg = _t('成功创建数据表%s，插件启用成功，', $where) . $configLink;
            } else {
                $msg = _t('数据表已经存在%s，插件启用成功，', $where) . $configLink;
            }

            /*
             * 表结构版本对不上就升级。放在这里而不是只放在 activate()：
             * configHandle() 保存设置时也会走 install()，于是「换了插件文件但忘了
             * 重新启用」的站点，只要在后台保存一次设置就能补上升级。
             */
            $dbSettings = Database::settings($settings);
            $schema = Schema::ensure($db, Migrate::fingerprint($dbSettings), $created);

            if ($external) {
                # 把主库里已有的统计数据搬过去；每次进来都检查，
                # 执行超时截断的迁移会自动重新迁移
                $migration = Migrate::ensure(
                    $db,
                    $dbSettings,
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

            return $msg . self::schemaNotice($schema);
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
