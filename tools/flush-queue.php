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
 *   --limit=5000              本次最多处理多少条，默认 5000
 *   --deadline=20             本次最多跑多少秒，默认 20，到点收工剩下的留给下一次
 *   --quiet                   没有积压时不输出任何内容，适合 cron
 *   --lenient                 有消息转入死信队列时不改退出码（仍会打印警告）
 *
 * 退出码：
 *   0  正常（含「队列为空」「已有其它进程在刷」「达到本次上限，剩余部分下次继续」）
 *   1  有需要人过问的问题：Redis/数据库不可用、刷库中途出错、锁易主、
 *      刷完后读不到队列长度，或有消息没能写进数据库而转入了死信队列
 *      （--lenient 可豁免死信这一项）
 *
 * 说明：写入队列在插件设置里被禁用之后，本脚本仍会把已有的存量刷完 ——
 * 关掉开关只影响新消息入队，不代表之前攒下的可以不管。
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
use TypechoPlugin\Access\Health;
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

    # 和前台走同一个工厂，超时才不会漏设 —— 这里以前只有连接超时、没有读超时，
    # Redis 连上了却不回话时，cron 任务会一直挂着
    try {
        $redis = Health::connect(
            $settings['redisHost'] ?: '127.0.0.1',
            (int)($settings['redisPort'] ?: 6379),
            (string)($settings['redisAuth'] ?? ''),
            Health::CLI_CONNECT_TIMEOUT,
            Health::CLI_READ_TIMEOUT
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    /*
     * 键名带上站点指纹之前遗留的队列，在这里顺手接管过来。
     * cron 每分钟都会跑到这儿，比等管理员去后台保存一次设置更靠得住。
     */
    $adopted = Queue::adoptLegacy($redis);
    if (!empty($adopted['adopted'])) {
        say('已接管升级前遗留的队列：' . implode('、', $adopted['adopted']));
    }
    if (!empty($adopted['skipped'])) {
        fwrite(STDERR, '注意：' . implode('、', $adopted['skipped'])
            . " 里还有升级前遗留的数据，但当前站点的队列已在使用，未做合并，请人工处理。\n");
    }

    # 这里必须用 tryLength()：length() 会把 Redis 故障伪装成 0，
    # 于是「Redis 挂了」被打印成「队列为空」，再以退出码 0 收场，故障就此石沉大海
    $pending = Queue::tryLength($redis);
    if ($pending === null) {
        fwrite(STDERR, "无法读取队列长度，Redis 可能已不可用。\n");
        exit(1);
    }
    /*
     * 写入队列被关掉了，不代表之前攒的消息不存在。
     * 以前这里直接以 0 退出，旧积压就永远没人再看一眼，cron 也毫无提示。
     * 关掉之后仍要负责把存量刷完，刷完了才安静退出。
     */
    $queueOff = ($settings['writeQueue'] ?? '1') == '0';
    $deadOnly = Queue::deadLength($redis);

    if ($pending === 0) {
        if ($deadOnly > 0) {
            # 死信是「没能写进数据库的数据」，不是正常状态，不能装作队列干净
            fwrite(STDERR, sprintf(
                "队列已空，但死信队列中仍有 %s 条未能写入数据库，请检查后处理。\n",
                number_format($deadOnly)
            ));
            $redis->close();
            exit(empty($argvOptions['lenient']) ? 1 : 0);
        }
        say('队列为空，无需刷库。');
        exit(0);
    }

    if ($queueOff) {
        say(sprintf(
            '写入队列已在插件设置中禁用，但仍有 %s 条存量待落库，本次继续刷完。',
            number_format($pending)
        ));
    }

    $token = Queue::acquireLock($redis);
    if ($token === null) {
        say('已有其它进程正在刷库，本次跳过。');
        exit(0);
    }

    $db = Database::get($settings);
    $limit = isset($argvOptions['limit']) ? (int)$argvOptions['limit'] : Queue::FLUSH_LIMIT;
    $seconds = isset($argvOptions['deadline']) ? (int)$argvOptions['deadline'] : Queue::FLUSH_DEADLINE;
    $seconds = max(1, $seconds);
    $startedAt = microtime(true);

    try {
        $result = Queue::flush($redis, $db, $limit, $startedAt + $seconds, $token);
    } finally {
        Queue::releaseLock($redis, $token);
    }

    # 读不到长度是 Redis 出问题了，不能和「剩余 0 条」显示成同一句话
    $remaining = Queue::tryLength($redis);
    printf(
        "已处理 %s 条（写入 %s 条），耗时 %.2f 秒，队列剩余 %s。\n",
        number_format($result['attempted']),
        number_format($result['written']),
        microtime(true) - $startedAt,
        $remaining === null ? '未知（读取队列长度失败）' : number_format($remaining) . ' 条'
    );

    # attempted 与 written 的差额都没进数据库，必须说清楚去向
    if ($result['invalid'] > 0 || $result['rejected'] > 0) {
        printf(
            "其中 %s 条 JSON 无法解析、%s 条被数据库拒绝，已转入死信队列（当前积压 %s 条）。\n",
            number_format($result['invalid']),
            number_format($result['rejected']),
            number_format(Queue::deadLength($redis))
        );
    }

    /*
     * 退出码必须反映真实结果。
     * Queue::flush() 内部会吞掉数据库异常并照常返回，所以「写入 0 条」和「一切正常」
     * 在标准输出上长得差不多；只看有没有抛异常的话，一次彻底失败的刷库同样以 0 退出，
     * cron 和监控完全看不出来。
     */
    $failed = in_array($result['stopped'], ['db', 'lock', 'error'], true) || $remaining === null;

    # processing 长期不为 0 说明每轮刷库都没能确认，值得单独点出来
    $stuck = Queue::processingLength($redis);
    if ($stuck > 0) {
        printf("有 %s 条已取出但尚未确认，下次刷库会先处理它们。\n", number_format($stuck));
    }

    $note = match ($result['stopped']) {
        'limit'    => sprintf('本次达到条数上限 %s，剩余部分请再次执行。', number_format($limit)),
        'deadline' => sprintf('本次达到时间上限 %d 秒，剩余部分请再次执行。', $seconds),
        'db'       => $result['error'],
        'lock'     => $result['error'],
        'error'    => '刷库中断：' . $result['error'],
        default    => '',
    };
    if ($note !== '') {
        # 出问题的说明走 STDERR，正常的进度提示走 STDOUT
        fwrite($failed ? STDERR : STDOUT, $note . "\n");
    }

    # 进了死信队列的消息 = 没能写进数据库的数据，默认也算需要人过问
    $lenient = !empty($argvOptions['lenient']);
    if ($result['dead'] > 0) {
        fwrite(
            $lenient ? STDOUT : STDERR,
            sprintf("有 %s 条消息未能写入数据库，已转入死信队列，请检查。\n", number_format($result['dead']))
        );
        if (!$lenient) {
            $failed = true;
        }
    }

    $redis->close();
    exit($failed ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '刷库失败：' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
