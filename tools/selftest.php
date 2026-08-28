<?php
/**
 * Access 插件 —— 写入队列故障处理自检
 *
 * 验证「写不进数据库时，消息去了哪儿」这条路径。它是整个插件里最容易静默丢数据的地方：
 * 判错一次的后果是整个队列被倒进死信，而死信满了从最旧的开始丢。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/selftest.php --root=/path/to/typecho \
 *       --pg-host=127.0.0.1 --pg-port=5432 --pg-user=postgres --pg-pass=xxx --pg-db=accesstest \
 *       --prefix=selftest_ --redis-db=15
 *
 * 隔离要求（脚本会强制检查，不满足直接退出）：
 *   --pg-db      一个**可以随便建表删表**的库，不要指向生产统计库
 *   --prefix     不能是 typecho_，脚本会建 {prefix}access 并在结束时删掉
 *   --redis-db   不能是 0，用一个空闲的逻辑库，避免碰到真实队列的键
 *
 * 只支持 PostgreSQL：只读事务、权限回收这两种故障在 PG 上最容易精确制造。
 *
 * 退出码：0 全部通过；1 环境问题；2 有断言失败
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
use TypechoPlugin\Access\Cache;
use TypechoPlugin\Access\Database;
use TypechoPlugin\Access\Migrate;
use TypechoPlugin\Access\Queue;
use TypechoPlugin\Access\WriteErrorKind;

// ── 隔离检查 ──────────────────────────────────────────────────────────────
$prefix  = (string)($o['prefix'] ?? 'selftest_');
$redisDb = (int)($o['redis-db'] ?? 15);
$pgDb    = (string)($o['pg-db'] ?? '');

if ($prefix === 'typecho_' || $prefix === '') {
    fwrite(STDERR, "--prefix 不能是 typecho_ 或空：脚本会建表并在结束时删表。\n");
    exit(1);
}
if ($redisDb === 0) {
    fwrite(STDERR, "--redis-db 不能是 0：请用一个空闲的逻辑库，避免碰到真实队列。\n");
    exit(1);
}
if ($pgDb === '') {
    fwrite(STDERR, "必须指定 --pg-db，且请指向一个可以随便建表删表的库。\n");
    exit(1);
}

// ── 连接 ─────────────────────────────────────────────────────────────────
function connect(array $o, string $db, string $prefix, ?string $user = null): Db
{
    $conn = new Db('Pdo_Pgsql', $prefix);
    $conn->addServer([
        'host'     => $o['pg-host'] ?? '127.0.0.1',
        'port'     => (int)($o['pg-port'] ?? 5432),
        'user'     => $user ?? ($o['pg-user'] ?? 'postgres'),
        'password' => $o['pg-pass'] ?? '',
        'database' => $db,
        'charset'  => 'utf8',
    ], Db::READ | Db::WRITE);
    return $conn;
}

try {
    $db = connect($o, $pgDb, $prefix);
    $db->query('SELECT 1', Db::WRITE);
} catch (\Throwable $e) {
    fwrite(STDERR, '连不上 PostgreSQL：' . $e->getMessage() . "\n");
    exit(1);
}

if (!extension_loaded('redis')) {
    fwrite(STDERR, "缺少 redis 扩展。\n");
    exit(1);
}
$r = new Redis();
try {
    $r->connect($o['redis-host'] ?? '127.0.0.1', (int)($o['redis-port'] ?? 6379), 3);
    if (!empty($o['redis-pass'])) { $r->auth((string)$o['redis-pass']); }
    $r->select($redisDb);
} catch (\Throwable $e) {
    fwrite(STDERR, '连不上 Redis：' . $e->getMessage() . "\n");
    exit(1);
}

# 再保险一道：这个逻辑库里如果已经有本插件的队列键，说明选错库了，不能动
if ($r->exists(Queue::key()) || $r->exists(Queue::processingKey()) || $r->exists(Queue::deadKey())) {
    fwrite(STDERR, "redis-db {$redisDb} 里已经存在本插件的队列键，换一个空闲的逻辑库再跑。\n");
    exit(1);
}

$table = $prefix . 'access';
$fail = 0;
function chk(bool $c, string $m): void { global $fail; if (!$c) { $fail++; echo "  FAIL  $m\n"; } else { echo "   OK   $m\n"; } }
function out(string $s = ''): void { echo $s . "\n"; }

// ── 建表 ─────────────────────────────────────────────────────────────────
$sqlFile = $root . '/usr/plugins/Access/sql/PostgreSQL.sql';
if (!is_file($sqlFile)) { fwrite(STDERR, "找不到 {$sqlFile}\n"); exit(1); }
$db->query("DROP TABLE IF EXISTS {$table}", Db::WRITE);
foreach (explode(';', str_replace('typecho_', $prefix, file_get_contents($sqlFile))) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '' || strtoupper($stmt) === 'COMMIT') { continue; }
    if (trim(preg_replace('/^\s*--.*$/m', '', $stmt)) === '') { continue; }
    $db->query($stmt, Db::WRITE);
}

function cleanup(Db $db, Redis $r, string $table): void
{
    try { $db->query("SET default_transaction_read_only = off", Db::WRITE); } catch (\Throwable $e) {}
    try { $db->query("DROP TABLE IF EXISTS {$table}", Db::WRITE); } catch (\Throwable $e) {}
    $r->del(Queue::key(), Queue::processingKey(), Queue::deadKey(), Cache::key('queue:stuck_since'));
}
register_shutdown_function(fn() => cleanup($db, $r, $table));

function row(array $over = []): string
{
    return json_encode($over + [
        'ua' => 'Mozilla/5.0', 'browser_id' => 'Chrome', 'browser_version' => '1',
        'os_id' => 'Mac', 'os_version' => '14', 'url' => '/a', 'path' => '/a',
        'query_string' => '', 'ip' => '123456', 'entrypoint' => '', 'entrypoint_domain' => '',
        'referer' => '', 'referer_domain' => '', 'time' => time(),
        'content_id' => 1, 'meta_id' => 0, 'robot' => 0, 'robot_id' => '', 'robot_version' => '',
        'event_id' => Queue::newEventId(),
    ]);
}
function resetAll(Redis $r, Db $db, string $table): void
{
    $r->del(Queue::key(), Queue::processingKey(), Queue::deadKey(), Cache::key('queue:stuck_since'));
    $db->query("DELETE FROM {$table}", Db::WRITE);
}
function rows(Db $db, string $table): int
{
    return (int)$db->fetchAll($db->query("SELECT COUNT(1) AS c FROM {$table}", Db::READ))[0]['c'];
}
function reasons(Redis $r): array
{
    return array_map(fn($j) => json_decode($j, true)['reason'] ?? '?', $r->lRange(Queue::deadKey(), 0, -1));
}

out("表 {$table} / redis-db {$redisDb} / 键前缀 " . Cache::prefix());
out();

out('=== 1. 正常写入 ===');
resetAll($r, $db, $table);
for ($i = 0; $i < 5; $i++) { $r->rPush(Queue::key(), row()); }
$res = Queue::flush($r, $db, 100);
chk(rows($db, $table) === 5 && $r->lLen(Queue::processingKey()) === 0 && $r->lLen(Queue::deadKey()) === 0,
    "5 条落库、processing 清空、死信 0（stopped={$res['stopped']}）");

out();
out('=== 2. 只读事务：不能转死信 ===');
resetAll($r, $db, $table);
for ($i = 0; $i < 5; $i++) { $r->rPush(Queue::key(), row()); }
$db->query('SET default_transaction_read_only = on', Db::WRITE);
chk(Migrate::alive($db) === true, 'alive() 仍返回 true —— 这正是旧判据失效的地方');
$res = Queue::flush($r, $db, 100);
chk($res['stopped'] === 'db', "stopped='db'（实得 {$res['stopped']}）");
chk($r->lLen(Queue::deadKey()) === 0, '死信 0 条 —— 旧代码会把 5 条全倒进去');
chk($r->lLen(Queue::processingKey()) === 5, '5 条完整保留在 processing 等重试');
$db->query('SET default_transaction_read_only = off', Db::WRITE);

out();
out('=== 3. 真脏数据：必须转死信，不能堵住队列 ===');
resetAll($r, $db, $table);
$db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS selftest_chk", Db::WRITE);
$db->query("ALTER TABLE {$table} ADD CONSTRAINT selftest_chk CHECK (robot_id <> 'POISON')", Db::WRITE);
$r->rPush(Queue::key(), row());
$r->rPush(Queue::key(), row(['robot_id' => 'POISON']));
$r->rPush(Queue::key(), row());
Queue::flush($r, $db, 100);
chk(rows($db, $table) === 2, '2 条好数据落库');
chk(reasons($r) === ['db-rejected'], '1 条脏数据进死信，原因 db-rejected（实得 ' . implode(',', reasons($r)) . '）');
chk($r->lLen(Queue::processingKey()) === 0, 'processing 清空 —— 脏数据没有堵住队列');
$db->query("ALTER TABLE {$table} DROP CONSTRAINT selftest_chk", Db::WRITE);

out();
out('=== 4. 卡满 STUCK_SECONDS 后放行 ===');
resetAll($r, $db, $table);
for ($i = 0; $i < 3; $i++) { $r->rPush(Queue::key(), row()); }
$db->query('SET default_transaction_read_only = on', Db::WRITE);
Queue::flush($r, $db, 100);
chk($r->lLen(Queue::processingKey()) === 3, '第一轮保留 3 条并开始计时');
$r->set(Cache::key('queue:stuck_since'), time() - Queue::STUCK_SECONDS - 1);
Queue::flush($r, $db, 100);
chk($r->lLen(Queue::processingKey()) === 0, '第二轮放行，processing 清空');
chk(reasons($r) === array_fill(0, 3, 'db-environment'),
    '3 条转死信且原因是 db-environment（实得 ' . implode(',', reasons($r)) . '）');
$db->query('SET default_transaction_read_only = off', Db::WRITE);

out();
out('=== 5. processing 读取失败必须停手 ===');
resetAll($r, $db, $table);
for ($i = 0; $i < 2; $i++) { $r->rPush(Queue::key(), row()); }
$r->set(Queue::processingKey(), 'not-a-list');
$res = Queue::flush($r, $db, 100);
chk($res['stopped'] === 'error', "stopped='error' 而不是当成空 processing（实得 {$res['stopped']}）");
chk($r->lLen(Queue::key()) === 2, '主队列 2 条原封不动');
$r->del(Queue::processingKey());

out();
out('=== 6. SQLSTATE 归类 ===');
$cases = [
    ['23505', 'Data'], ['22001', 'Data'], ['23502', 'Data'], ['22P02', 'Data'],
    ['23514', 'Data'], ['22003', 'Data'], ['23000', 'Data'],
    ['25006', 'Environment'], ['42501', 'Environment'], ['42P01', 'Environment'],
    ['42703', 'Environment'], ['08006', 'Environment'], ['53100', 'Environment'],
    ['57P01', 'Environment'], ['40001', 'Environment'], ['40P01', 'Environment'],
    ['HY000', 'Environment'], ['58030', 'Environment'], ['XX001', 'Environment'],
    ['99999', 'Unknown'],
];
foreach ($cases as [$state, $want]) {
    $got = Database::classifyWriteError(new \RuntimeException("SQLSTATE[{$state}]: x"))->name;
    chk($got === $want, sprintf('%-6s → %s', $state, $got));
}

out();
out($fail === 0 ? '全部通过。' : "失败断言：{$fail}");
exit($fail === 0 ? 0 : 2);
