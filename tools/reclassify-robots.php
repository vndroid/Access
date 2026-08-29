<?php
/**
 * Access 插件 —— 按当前 UA 判定规则重算存量数据的 robot 分类
 *
 * v3.2.4 之前，UA 解析器认不出来的一律记成人类（robot = 0），于是扫描器、curl、wget、
 * 空 UA 全都混在人类流量里；同时名单里的短词会反向误判（Presto 引擎的 Opera 12
 * 被记成机器人）。判定规则改好之后只对新数据生效，存量行要靠这个脚本重算。
 *
 * 只改 robot / robot_id / robot_version 三列，其余一概不动。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/reclassify-robots.php --dry-run
 *   php usr/plugins/Access/tools/reclassify-robots.php --yes
 *
 * 可选参数：
 *   --root=/path/to/typecho   指定 Typecho 根目录（默认自动向上查找 config.inc.php）
 *   --batch=2000              每批读取行数，默认 2000
 *   --from=123456             从这个 id 之后开始（中断后续跑）
 *   --dry-run                 只统计会改多少行、改成什么，不写库
 *   --yes                     跳过确认
 *   --limit=100000            最多处理这么多行后停下（分多次跑）
 *
 * 退出码：0 完成；1 环境或连接问题；2 被中断（用 --from 继续）
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script can only be run from the command line.');
}

$o = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $o[$m[1]] = $m[2] ?? true;
    }
}

$root = $o['root'] ?? null;
if ($root === null) {
    for ($dir = __DIR__, $i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/config.inc.php')) { $root = $dir; break; }
    }
}
if (!is_string($root) || !file_exists($root . '/config.inc.php')) {
    fwrite(STDERR, "找不到 config.inc.php，请用 --root=/path/to/typecho 指定网站根目录。\n");
    exit(1);
}

define('__TYPECHO_ROOT_DIR__', $root);
require_once $root . '/config.inc.php';

use Typecho\Db;
use TypechoPlugin\Access\Database;
use TypechoPlugin\Access\UA;

/** robot_id / robot_version 的列宽（见 sql/*.sql，与 Queue::LIMITS 保持一致） */
const ROBOT_COLUMN_WIDTH = 32;

function out(string $s = ''): void { echo $s . "\n"; }

try {
    $db = Database::get();
    $table = Database::driver($db)->quoteTable($db->getPrefix() . 'access');
    $plain = $db->getPrefix() . 'access';
} catch (\Throwable $e) {
    fwrite(STDERR, '连不上统计数据库：' . $e->getMessage() . "\n");
    exit(1);
}

if (!Database::tableExists($db, $plain)) {
    fwrite(STDERR, "统计表 {$plain} 不存在。\n");
    exit(1);
}

$batch  = max(1, (int)($o['batch'] ?? 2000));
$from   = max(0, (int)($o['from'] ?? 0));
$limit  = isset($o['limit']) ? max(1, (int)$o['limit']) : 0;
$dryRun = !empty($o['dry-run']);

try {
    $total = (int)$db->fetchAll($db->query("SELECT COUNT(1) AS c FROM {$table}", Db::READ))[0]['c'];
    $maxId = (int)($db->fetchAll($db->query("SELECT MAX(id) AS m FROM {$table}", Db::READ))[0]['m'] ?? 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '读取统计表失败：' . $e->getMessage() . "\n");
    exit(1);
}

out("表 {$plain}：" . number_format($total) . ' 行，最大 id ' . number_format($maxId));
out('起点 id > ' . number_format($from) . '，每批 ' . number_format($batch) . ' 行'
    . ($limit > 0 ? '，本次最多处理 ' . number_format($limit) . ' 行' : ''));
out($dryRun ? '模式：--dry-run（只统计，不写库）' : '模式：实际写入');
out();

if (!$dryRun && empty($o['yes'])) {
    echo '确认重算并写回 robot / robot_id / robot_version？[y/N] ';
    $answer = strtolower(trim((string)fgets(STDIN)));
    if ($answer !== 'y' && $answer !== 'yes') {
        out('已取消。');
        exit(0);
    }
    out();
}

/*
 * 逐批处理，改动按「目标值」分组后用 WHERE id IN (...) 一条 UPDATE 打包。
 * 逐行 UPDATE 在两百多万行上是几十万次往返；而不同的判定结果其实只有几十种，
 * 分组之后每批最多几十条语句。
 */
$scanned = 0;
$changed = 0;
$lastId  = $from;
$summary = [];          // "robot|robot_id" => 改动行数
$interrupted = false;
$startedAt = microtime(true);
$lastPrint = 0.0;

@set_time_limit(0);

while (true) {
    if ($limit > 0 && $scanned >= $limit) {
        $interrupted = true;
        break;
    }

    $take = $limit > 0 ? min($batch, $limit - $scanned) : $batch;

    try {
        $rows = $db->fetchAll(
            $db->select('id', 'ua', 'robot', 'robot_id', 'robot_version')
                ->from('table.access')
                ->where('id > ?', $lastId)
                ->order('id', Db::SORT_ASC)
                ->limit($take)
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "\n读取失败（id > {$lastId}）：" . $e->getMessage() . "\n");
        fwrite(STDERR, "用 --from={$lastId} 继续。\n");
        exit(2);
    }

    if (empty($rows)) {
        break;
    }

    $batchEnd = $lastId;
    $groups = [];       // "robot\0robot_id\0robot_version" => [id, ...]
    foreach ($rows as $row) {
        # 只记本批扫到哪儿；$lastId 要等本批所有分组都写成功之后才推进（见批末）
        $batchEnd = (int)$row['id'];
        $scanned++;

        $ua = new UA((string)($row['ua'] ?? ''));
        $newRobot   = $ua->isRobot() ? 1 : 0;
        $newId      = $ua->getRobotID();
        $newVersion = $ua->getRobotVersion();

        # 列宽和写入侧保持一致，免得回填写进去的值比正常写入的还长
        $newId      = mb_substr($newId, 0, ROBOT_COLUMN_WIDTH);
        $newVersion = mb_substr($newVersion, 0, ROBOT_COLUMN_WIDTH);

        $oldRobot   = (int)($row['robot'] ?? 0);
        $oldId      = (string)($row['robot_id'] ?? '');
        $oldVersion = (string)($row['robot_version'] ?? '');

        if ($newRobot === $oldRobot && $newId === $oldId && $newVersion === $oldVersion) {
            continue;
        }

        $groups[$newRobot . "\0" . $newId . "\0" . $newVersion][] = (int)$row['id'];
        $changed++;
        $key = ($newRobot ? '机器人' : '人类  ') . ' ' . ($newId === '' ? '(无名)' : $newId);
        $summary[$key] = ($summary[$key] ?? 0) + 1;
    }

    if (!$dryRun && !empty($groups)) {
        foreach ($groups as $key => $ids) {
            [$robot, $robotId, $robotVersion] = explode("\0", $key, 3);
            try {
                $db->query(
                    $db->update('table.access')
                        ->rows([
                            'robot' => (int)$robot,
                            'robot_id' => $robotId,
                            'robot_version' => $robotVersion,
                        ])
                        ->where('id IN ?', $ids)
                );
            } catch (\Throwable $e) {
                /*
                 * 提示里给的是**上一批的末尾**，不是本批的。
                 *
                 * 本批可能有一部分分组已经写成功、另一部分还没写，中途退出时无从分辨。
                 * 报本批末尾的话，按提示续跑会把本批里没写成的那些整个跳过 ——
                 * 而重跑已经写成功的分组是幂等的（值算出来一样，改动为空），代价只是多读一批。
                 */
                fwrite(STDERR, "\n写入失败（本批读到 id {$batchEnd}）：" . $e->getMessage() . "\n");
                fwrite(STDERR, "本批未写完，请从上一批末尾续跑：--from={$lastId}\n");
                exit(2);
            }
        }
    }

    # 本批所有分组都写成功了，这时才允许推进续跑位置
    $lastId = $batchEnd;

    $now = microtime(true);
    if ($now - $lastPrint >= 1.0) {
        $lastPrint = $now;
        $pct = $maxId > 0 ? min(100, $lastId / $maxId * 100) : 0;
        printf("\r  已扫 %s 行，需改 %s 行，进度 id %s / %s (%.1f%%)   ",
            number_format($scanned), number_format($changed),
            number_format($lastId), number_format($maxId), $pct);
    }
}

echo "\r" . str_repeat(' ', 100) . "\r";

$elapsed = microtime(true) - $startedAt;
out('扫描 ' . number_format($scanned) . ' 行，'
    . ($dryRun ? '需要改动 ' : '已改动 ') . number_format($changed) . ' 行，'
    . '耗时 ' . number_format($elapsed, 1) . ' 秒');

if (!empty($summary)) {
    arsort($summary);
    out();
    out('改动明细（新分类 => 行数）：');
    $shown = 0;
    foreach ($summary as $key => $n) {
        # str_pad 按字节算宽度，中文会把列对不齐；按显示宽度补空格
        $pad = max(1, 42 - mb_strwidth($key));
        out('  ' . $key . str_repeat(' ', $pad) . number_format($n));
        if (++$shown >= 30) {
            out('  … 其余 ' . (count($summary) - 30) . ' 类未列出');
            break;
        }
    }
}

out();
if ($interrupted) {
    out('已达到 --limit 上限，尚未处理完。继续执行：');
    out("  php " . basename(__FILE__) . " --from={$lastId} --yes"
        . ($limit > 0 ? " --limit={$limit}" : ''));
    exit(2);
}

if ($dryRun) {
    out('--dry-run：未做任何写入。去掉这个参数即可实际执行。');
} else {
    /*
     * 不需要清 Redis 统计缓存：概览的 IP / UV / PV、来源 Top、文章饼图
     * 在查询时都**不按 robot 过滤**，重算这一列不会改变其中任何一个数字。
     * 它只影响日志列表的「人类 / 爬虫」筛选和显示名称，而那是实时查询的。
     */
    out('完成。robot 列只影响日志列表的人类/爬虫筛选，概览数字不受影响，无需清缓存。');
}

exit(0);
