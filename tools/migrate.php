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
 *   --forget-failed           清掉「写不进目标库」的行记录，之后可以重新标记完成
 *                             （这些行不会被补写，等于确认放弃它们）
 *
 * 退出码：
 *   0  迁移完成，或本来就没有需要迁移的数据
 *   1  环境或连接问题
 *   2  尚未完成（被打断、或有行写不进目标库），重新执行即可继续
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

    $fingerprint = Migrate::fingerprint($dbSettings);

    if (!empty($argvOptions['forget-failed'])) {
        $forgotten = count(Migrate::failures($main, $fingerprint));
        # 这也是一次写入，同样要让 --dry-run 挡住
        if (!empty($argvOptions['dry-run'])) {
            out(sprintf('--dry-run：本会清掉 %s 行失败记录，未执行。', number_format($forgotten)));
        } else {
            Migrate::clearFailures($main, $fingerprint);
            out(sprintf('已清掉 %s 行失败记录（这些行不会被补写）。', number_format($forgotten)));
        }
    }

    $known = Migrate::failures($main, $fingerprint);
    if (!empty($known)) {
        out(sprintf(
            '其中 %s 行此前写入失败，未标记完成：%s%s',
            number_format(count($known)),
            implode(', ', array_slice($known, 0, 20)),
            count($known) > 20 ? ' …' : ''
        ));
    }
    out();

    /*
     * --dry-run 必须挡在**所有**写入分支之前。
     * 以前它排在下面「pending === 0 就标记完成」之后，于是
     * `--dry-run` 会真的写下 access_migrate_done（顺带还可能清掉失败记录），
     * 而这个标记一旦写下，isMarked() 此后每次都直接返回，源表再也没人看一眼。
     * 一个名字叫「只看不动」的开关做了整个脚本里最不可逆的那件事。
     */
    if (!empty($argvOptions['dry-run'])) {
        out($pending === 0
            ? '--dry-run：没有需要迁移的数据（未写入完成标记）。'
            : '--dry-run：未做任何写入。');
        exit(0);
    }

    if ($pending === 0) {
        if (!empty($known)) {
            # 行数对上了但失败记录还在，说明那几行是人工补进去的，顺手把记录清掉
            Migrate::clearFailures($main, $fingerprint);
        }
        Migrate::mark($main, $fingerprint);
        out('没有需要迁移的数据，已标记为完成。');
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
        'fingerprint' => $fingerprint,
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

    # 只要有行写不进去就不能标记完成，否则源表从此不会再被看一眼
    $stuck = Migrate::failures($main, $fingerprint);

    if ($result['done'] && empty($stuck)) {
        Migrate::mark($main, $fingerprint);
        out(sprintf(
            '迁移完成，本次写入 %s 行，耗时 %s。',
            number_format($result['moved']),
            gmdate('H:i:s', (int)(microtime(true) - $startedAt))
        ));
        out('主库中的旧数据未做改动，确认无误后可自行删除 ' . $main->getPrefix() . 'access 表。');
    } elseif (!empty($stuck)) {
        out(sprintf('本次写入 %s 行。', number_format($result['moved'])));
        fwrite(STDERR, sprintf(
            "有 %s 行写不进统计数据库，迁移未标记为完成。\n",
            number_format(count($stuck))
        ));
        fwrite(STDERR, '源表中对应的行 id：' . implode(', ', array_slice($stuck, 0, 50))
            . (count($stuck) > 50 ? ' …' : '') . "\n");
        fwrite(STDERR, "常见原因是这些行超出目标表的列宽或类型限制。修好之后重新执行本脚本会继续处理剩下的部分；\n");
        fwrite(STDERR, "确认这些行可以放弃时，用 --forget-failed 清掉记录再执行。\n");
        exit(2);
    } elseif ($result['error'] !== null) {
        out(sprintf('本次写入 %s 行。', number_format($result['moved'])));
        fwrite(STDERR, $result['error'] . "\n");
        exit(2);
    } else {
        out(sprintf('本次写入 %s 行，尚未完成，重新执行本脚本即可继续。', number_format($result['moved'])));
        exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '迁移失败：' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
