<?php
/**
 * Access 插件 —— 把 Typecho 主库中的历史统计数据迁移到独立统计数据库
 *
 * 命令行专用，不受 Web 服务器超时限制，支持断点续传：
 * 中途被 Ctrl+C 或断线打断后重新执行，会从上次的位置继续。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/migrate.php
 *
 * 可选参数：
 *   --root=/path/to/typecho   指定 Typecho 根目录（默认自动向上查找 config.inc.php）
 *   --batch=1000              每批迁移的行数，默认 1000
 *   --yes                     跳过确认，直接开始
 *   --dry-run                 只显示待迁移数量，不写入
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
    // tools -> Access -> plugins -> usr -> 根目录，同时也向上逐级兜底查找
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

// config.inc.php 会注册面向网页的异常处理和输出缓冲，命令行下要还原掉
restore_exception_handler();
while (ob_get_level() > 0) {
    ob_end_flush();
}

use Typecho\Db;
use TypechoPlugin\Access\Database;
use TypechoPlugin\Access\Migrate;

function out(string $line = ''): void
{
    echo $line . "\n";
    flush();
}

try {
    $main = Db::get();

    $settings = Migrate::readPluginOptions($main);
    if (empty($settings)) {
        out('未找到 Access 插件的配置，请先在后台启用并设置插件。');
        exit(1);
    }

    $dbSettings = Database::settings($settings);
    if (!Database::isExternal($dbSettings)) {
        out('当前插件配置为「跟随 Typecho」，统计数据本来就在主库里，无需迁移。');
        exit(0);
    }

    $error = Database::test($dbSettings);
    if ($error !== null) {
        out('无法连接统计数据库：' . $error);
        exit(1);
    }

    $target = Database::get($dbSettings);
    $targetTable = $target->getPrefix() . 'access';

    if (!Database::tableExists($target, $targetTable)) {
        out("统计数据库中还没有 {$targetTable} 表，请先在后台保存一次插件设置以建表。");
        exit(1);
    }

    if (!Database::tableExists($main, $main->getPrefix() . 'access')) {
        out('主库中没有历史统计数据表，无需迁移。');
        exit(0);
    }

    $total = Migrate::sourceCount($main);
    $maxSourceId = Migrate::sourceMaxId($main);
    $migrated = Migrate::migratedCount($target, $maxSourceId);
    $pending = max(0, $total - $migrated);

    out('源  ' . $main->getAdapterName() . '  ' . $main->getPrefix() . 'access  共 ' . number_format($total) . ' 行');
    out('目标 ' . $target->getAdapterName() . '  ' . $targetTable
        . '  已有 ' . number_format($migrated) . ' 行（迁移区间内）');
    out('待迁移 ' . number_format($pending) . ' 行');
    out();

    if ($pending === 0) {
        Migrate::mark($main, Migrate::fingerprint($dbSettings));
        out('没有需要迁移的数据，已标记为完成。');
        exit(0);
    }

    if (!empty($argvOptions['dry-run'])) {
        out('--dry-run：未做任何写入。');
        exit(0);
    }

    if (empty($argvOptions['yes'])) {
        echo '确认开始迁移？[y/N] ';
        $answer = strtolower(trim((string)fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            out('已取消。');
            exit(0);
        }
        out();
    }

    $batch = isset($argvOptions['batch']) ? (int)$argvOptions['batch'] : Migrate::BATCH_SIZE;
    $startedAt = microtime(true);
    $lastPrint = 0.0;

    $result = Migrate::run($main, $target, [
        'batchSize' => $batch,
        'progress' => function (int $done, int $all, int $lastId) use ($startedAt, &$lastPrint) {
            $now = microtime(true);
            // 每秒最多刷新一次，避免刷屏
            if ($now - $lastPrint < 1 && $done < $all) {
                return;
            }
            $lastPrint = $now;
            $elapsed = max(0.001, $now - $startedAt);
            $rate = $done / $elapsed;
            $percent = $all > 0 ? $done / $all * 100 : 100;
            $eta = $rate > 0 ? ($all - $done) / $rate : 0;
            printf(
                "  %s / %s (%.1f%%)  %.0f 行/秒  剩余约 %s\n",
                number_format($done),
                number_format($all),
                $percent,
                $rate,
                $eta > 0 ? gmdate('H:i:s', (int)$eta) : '00:00:00'
            );
            flush();
        },
    ]);

    out();
    if ($result['done']) {
        Migrate::mark($main, Migrate::fingerprint($dbSettings));
        out(sprintf(
            '迁移完成，本次写入 %s 行，耗时 %s。',
            number_format($result['moved']),
            gmdate('H:i:s', (int)(microtime(true) - $startedAt))
        ));
        out('主库中的旧数据未做改动，确认无误后可自行删除 ' . $main->getPrefix() . 'access 表。');
    } else {
        out(sprintf('本次写入 %s 行，尚未完成，重新执行本脚本即可继续。', number_format($result['moved'])));
        exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '迁移失败：' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
