<?php
/**
 * Access 插件 —— 把存量数据里的来源域名 / URL 规范化成小写
 *
 * 主机名不区分大小写（RFC 3986 §3.2.2），但 v3.2.5 之前入库时没做规范化，
 * 于是 wave.com 和 WAVE.COM 在 GROUP BY 里成了两个来源，同一个站点被拆开统计。
 * 判定规则改好之后只对新数据生效，存量行要靠这个脚本重算。
 *
 * 处理四列：referer_domain、entrypoint_domain（整列小写）、
 *           referer、entrypoint（只把 scheme 和主机名小写，path / query 原样保留）。
 * 其余列一概不动。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/normalize-domains.php --dry-run
 *   php usr/plugins/Access/tools/normalize-domains.php --yes
 *
 * 可选参数：
 *   --root=/path/to/typecho   指定 Typecho 根目录（默认自动向上查找 config.inc.php）
 *   --batch=2000              每批读取行数，默认 2000
 *   --from=123456             从这个 id 之后开始（中断后续跑）
 *   --limit=200000            最多处理这么多行后停下（分多次跑）
 *   --dry-run                 只统计会改多少行，不写库
 *   --yes                     跳过确认
 *   --scan-all                不做候选行预筛，逐行读出来比对（预筛出问题时的退路）
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
use TypechoPlugin\Access\Driver;
use TypechoPlugin\Access\Queue;

/** 整列小写的列 */
const HOST_COLUMNS = ['referer_domain', 'entrypoint_domain'];
/** 只规范化 scheme + 主机名的列 */
const URL_COLUMNS = ['referer', 'entrypoint'];

function out(string $s = ''): void { echo $s . "\n"; }

try {
    $db = Database::get();
    $driver = Database::driver($db);
    $table = $driver->quoteTable($db->getPrefix() . 'access');
    $plain = $db->getPrefix() . 'access';
} catch (\Throwable $e) {
    fwrite(STDERR, '连不上统计数据库：' . $e->getMessage() . "\n");
    exit(1);
}

if (!Database::tableExists($db, $plain)) {
    fwrite(STDERR, "统计表 {$plain} 不存在。\n");
    exit(1);
}

/**
 * 「这一行可能需要改」的 SQL 条件，用来少读大量已经规范的行
 *
 * **各家数据库的默认排序规则不一样，这里必须按引擎分开写。**
 * MySQL 默认是 utf8mb4_..._ci（不区分大小写），`col <> LOWER(col)` 恒为假 ——
 * 直接照搬 PostgreSQL 的写法会让预筛把所有行都判成「不用改」，脚本跑完一行没动，
 * 还看不出哪里不对。所以 MySQL 要转成二进制再比。
 *
 * 顺带说明：也正因为 MySQL 默认不区分大小写，GROUP BY referer_domain 在 MySQL 上
 * 本来就把 wave.com 和 WAVE.COM 合在一起了 —— 这个问题主要出现在
 * PostgreSQL 和 SQLite（两者的默认比较都区分大小写）。
 */
function candidateCondition(Driver $driver): string
{
    $columns = array_merge(HOST_COLUMNS, URL_COLUMNS);
    $parts = [];
    foreach ($columns as $c) {
        $parts[] = match ($driver) {
            Driver::Mysql => "CAST({$c} AS BINARY) <> CAST(LOWER({$c}) AS BINARY)",
            default       => "{$c} <> LOWER({$c})",
        };
    }
    return '(' . implode(' OR ', $parts) . ')';
}

$batch  = max(1, (int)($o['batch'] ?? 2000));
$from   = max(0, (int)($o['from'] ?? 0));
$limit  = isset($o['limit']) ? max(1, (int)$o['limit']) : 0;
$dryRun = !empty($o['dry-run']);
$scanAll = !empty($o['scan-all']);

try {
    $total = (int)$db->fetchAll($db->query("SELECT COUNT(1) AS c FROM {$table}", Db::READ))[0]['c'];
    $maxId = (int)($db->fetchAll($db->query("SELECT MAX(id) AS m FROM {$table}", Db::READ))[0]['m'] ?? 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '读取统计表失败：' . $e->getMessage() . "\n");
    exit(1);
}

out("表 {$plain}（{$driver->value}）：" . number_format($total) . ' 行，最大 id ' . number_format($maxId));
out('起点 id > ' . number_format($from) . '，每批 ' . number_format($batch) . ' 行'
    . ($limit > 0 ? '，本次最多处理 ' . number_format($limit) . ' 行' : ''));
out($scanAll ? '预筛：关闭（逐行比对）' : '预筛：只读出四列中含大写的行');
out($dryRun ? '模式：--dry-run（只统计，不写库）' : '模式：实际写入');

if ($driver === Driver::Mysql) {
    out();
    out('提示：MySQL 默认排序规则不区分大小写，GROUP BY 本来就把大小写变体合在一起了，');
    out('      这次回填只是让存进去的值本身也统一，统计数字不会变化。');
}
out();

if (!$dryRun && empty($o['yes'])) {
    echo '确认把这四列规范化并写回？[y/N] ';
    $answer = strtolower(trim((string)fgets(STDIN)));
    if ($answer !== 'y' && $answer !== 'yes') {
        out('已取消。');
        exit(0);
    }
    out();
}

$scanned = 0;
$changed = 0;
$lastId  = $from;
$samples = [];
$interrupted = false;
$startedAt = microtime(true);
$lastPrint = 0.0;
$condition = candidateCondition($driver);

@set_time_limit(0);

while (true) {
    if ($limit > 0 && $scanned >= $limit) {
        $interrupted = true;
        break;
    }
    $take = $limit > 0 ? min($batch, $limit - $scanned) : $batch;

    try {
        $query = $db->select('id', 'referer', 'referer_domain', 'entrypoint', 'entrypoint_domain')
            ->from('table.access')
            ->where('id > ?', $lastId)
            ->order('id', Db::SORT_ASC)
            ->limit($take);
        if (!$scanAll) {
            $query->where($condition);
        }
        $rows = $db->fetchAll($query);
    } catch (\Throwable $e) {
        fwrite(STDERR, "\n读取失败（id > {$lastId}）：" . $e->getMessage() . "\n");
        fwrite(STDERR, "用 --from={$lastId} 继续；预筛条件有问题时可加 --scan-all。\n");
        exit(2);
    }

    if (empty($rows)) {
        break;
    }

    $groups = [];
    foreach ($rows as $row) {
        $lastId = (int)$row['id'];
        $scanned++;

        $new = [];
        foreach (HOST_COLUMNS as $c) {
            $new[$c] = strtolower((string)($row[$c] ?? ''));
        }
        foreach (URL_COLUMNS as $c) {
            $new[$c] = Queue::normalizeUrl((string)($row[$c] ?? ''));
        }

        $diff = false;
        foreach ($new as $c => $v) {
            if ($v !== (string)($row[$c] ?? '')) { $diff = true; break; }
        }
        if (!$diff) {
            continue;
        }

        $groups[implode("\0", [$new['referer'], $new['referer_domain'],
                               $new['entrypoint'], $new['entrypoint_domain']])][] = (int)$row['id'];
        $changed++;

        foreach (HOST_COLUMNS as $c) {
            $old = (string)($row[$c] ?? '');
            if ($old !== '' && $old !== $new[$c] && count($samples) < 200) {
                $samples[$old . ' → ' . $new[$c]] = ($samples[$old . ' → ' . $new[$c]] ?? 0) + 1;
            }
        }
    }

    if (!$dryRun && !empty($groups)) {
        foreach ($groups as $key => $ids) {
            [$referer, $refererDomain, $entrypoint, $entrypointDomain] = explode("\0", $key, 4);
            try {
                $db->query(
                    $db->update('table.access')
                        ->rows([
                            'referer' => $referer,
                            'referer_domain' => $refererDomain,
                            'entrypoint' => $entrypoint,
                            'entrypoint_domain' => $entrypointDomain,
                        ])
                        ->where('id IN ?', $ids)
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n写入失败（id > {$lastId}）：" . $e->getMessage() . "\n");
                fwrite(STDERR, "已处理到 id {$lastId}，用 --from={$lastId} 继续。\n");
                exit(2);
            }
        }
    }

    $now = microtime(true);
    if ($now - $lastPrint >= 1.0) {
        $lastPrint = $now;
        $pct = $maxId > 0 ? min(100, $lastId / $maxId * 100) : 0;
        printf("\r  已读 %s 行，需改 %s 行，进度 id %s / %s (%.1f%%)   ",
            number_format($scanned), number_format($changed),
            number_format($lastId), number_format($maxId), $pct);
    }
}

echo "\r" . str_repeat(' ', 100) . "\r";

$elapsed = microtime(true) - $startedAt;
out('读出 ' . number_format($scanned) . ' 行'
    . ($scanAll ? '' : '（已按预筛跳过其余行）') . '，'
    . ($dryRun ? '需要改动 ' : '已改动 ') . number_format($changed) . ' 行，'
    . '耗时 ' . number_format($elapsed, 1) . ' 秒');

if (!empty($samples)) {
    arsort($samples);
    out();
    # 计数是 referer_domain 与 entrypoint_domain 两列合计的出现次数，不等于行数
    out('域名合并示例（旧值 → 新值 => 出现次数，两列合计）：');
    $shown = 0;
    foreach ($samples as $key => $n) {
        $pad = max(1, 52 - mb_strwidth($key));
        out('  ' . $key . str_repeat(' ', $pad) . number_format($n));
        if (++$shown >= 25) {
            out('  … 其余 ' . (count($samples) - 25) . ' 类未列出');
            break;
        }
    }
}

out();
if ($interrupted) {
    out('已达到 --limit 上限，尚未处理完。继续执行：');
    out('  php ' . basename(__FILE__) . " --from={$lastId} --yes"
        . ($limit > 0 ? " --limit={$limit}" : '') . ($scanAll ? ' --scan-all' : ''));
    exit(2);
}

if ($dryRun) {
    out('--dry-run：未做任何写入。去掉这个参数即可实际执行。');
} else {
    /*
     * 受影响的只有来源 Top N（referer:url / referer:domain）—— 它们按这几列 GROUP BY。
     * 概览的 IP / UV / PV、按日期的那些数字和文章饼图都不看这几列，不受影响。
     *
     * 这两项走的是「陈旧优先 + 后台重算」：新鲜期 30 分钟，过期后下一次打开控制台
     * 会先把旧值返回、同时在后台重算，再打开就是新数字。所以不需要手动清缓存
     * （也没有「保存设置就重建」这回事 —— 清 Redis 只发生在禁用插件时）。
     */
    out('完成。');
    out('来源 Top N 的缓存新鲜期是 30 分钟：过后第一次打开控制台会先显示旧数字并在后台重算，');
    out('再打开一次就是合并后的结果。其余统计不看这几列，不受影响。');
}

exit(0);
