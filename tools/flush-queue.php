<?php
/**
 * Access 插件 —— 把 Redis 写入队列里积压的访问日志落库
 *
 * 平时刷库由访问请求顺带完成，这个脚本用于兜底：
 * 低流量站点长时间没有新访问时，尾部数据会滞留在队列里，挂到 cron 即可定期清空。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/flush-queue.php
 *
 * 挂 cron（每分钟一次）：
 *   * * * * * cd /path/to/typecho && php usr/plugins/Access/tools/flush-queue.php --quiet
 *
 * 可选参数：
 *   --root=/path/to/typecho   指定 Typecho 根目录（默认自动向上查找 config.inc.php）
 *   --limit=5000              本次最多写入多少条，默认 5000
 *   --quiet                   没有积压时不输出任何内容，适合 cron
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script can only be run from the command line.');
}

$argvOptions = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $argvOptions[$m[1]] = $m[2] ?? true;
    }
}

// ── 定位并载入 Typecho 环境 ────────────────────────────────────────────────
$root = isset($argvOptions['root']) && is_string($argvOptions['root'])
    ? rtrim($argvOptions['root'], '/\\')
    : null;

if ($root === null) {
    $candidates = [dirname(__DIR__, 4), getcwd()];
    for ($dir = __DIR__, $i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        $candidates[] = $dir;
    }
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && file_exists($candidate . '/config.inc.php')) {
            $root = $candidate;
            break;
        }
    }
}

if ($root === null || !file_exists($root . '/config.inc.php')) {
    fwrite(STDERR, "找不到 config.inc.php，请用 --root=/path/to/typecho 指定网站根目录。\n");
    exit(1);
}

require_once $root . '/config.inc.php';

restore_exception_handler();
while (ob_get_level() > 0) {
    ob_end_flush();
}

use Typecho\Db;
use TypechoPlugin\Access\Database;
use TypechoPlugin\Access\Migrate;
use TypechoPlugin\Access\Queue;

$quiet = !empty($argvOptions['quiet']);

function say(string $line): void
{
    global $quiet;
    if (!$quiet) {
        echo $line . "\n";
    }
}

try {
    if (!extension_loaded('redis')) {
        say('PHP 未安装 redis 扩展，写入队列未启用，无需刷库。');
        exit(0);
    }

    $main = Db::get();
    $settings = Migrate::readPluginOptions($main);
    if (empty($settings)) {
        say('未找到 Access 插件的配置，请先在后台启用并设置插件。');
        exit(1);
    }

    if (($settings['redisCache'] ?? '0') != '1') {
        say('插件未启用 Redis，写入队列未生效，无需刷库。');
        exit(0);
    }
    if (($settings['writeQueue'] ?? '1') == '0') {
        say('写入队列已在插件设置中禁用，无需刷库。');
        exit(0);
    }

    $redis = new Redis();
    $host = $settings['redisHost'] ?: '127.0.0.1';
    $port = (int)($settings['redisPort'] ?: 6379);
    if (!$redis->connect($host, $port, 3)) {
        fwrite(STDERR, "无法连接 Redis {$host}:{$port}\n");
        exit(1);
    }
    if (!empty($settings['redisAuth'])) {
        $redis->auth($settings['redisAuth']);
    }
    $redis->ping();

    $pending = Queue::length($redis);
    if ($pending === 0) {
        say('队列为空，无需刷库。');
        exit(0);
    }

    if (!Queue::acquireLock($redis)) {
        say('已有其它进程正在刷库，本次跳过。');
        exit(0);
    }

    $db = Database::get($settings);
    $limit = isset($argvOptions['limit']) ? (int)$argvOptions['limit'] : Queue::FLUSH_LIMIT;
    $startedAt = microtime(true);

    try {
        $written = Queue::flush($redis, $db, $limit);
    } finally {
        Queue::releaseLock($redis);
    }

    $remaining = Queue::length($redis);
    printf(
        "已写入 %s 条，耗时 %.2f 秒，队列剩余 %s 条。\n",
        number_format($written),
        microtime(true) - $startedAt,
        number_format($remaining)
    );
    $redis->close();
} catch (\Throwable $e) {
    fwrite(STDERR, '刷库失败：' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
