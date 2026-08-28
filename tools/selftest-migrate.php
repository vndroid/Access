<?php
/**
 * Access 插件 —— 历史数据迁移自检
 *
 * 验证「源库的历史数据会不会在迁移中被静默跳过」这条路径。
 * 它和写入队列一样属于「出错了也看不见」的地方：完成标记一旦打下，
 * isMarked() 此后每次直接返回，源表再也没人看一眼。
 *
 * 用法（在网站根目录执行）：
 *   php usr/plugins/Access/tools/selftest-migrate.php --root=/path/to/typecho \
 *       --pg-host=127.0.0.1 --pg-port=5432 --pg-user=postgres --pg-pass=xxx --pg-db=accesstest
 *
 * 隔离：脚本在 --pg-db 里用 stsrc_ / sttgt_ 两个前缀自建一整套源库和目标库
 * （含自己的 options 表），结束时全部删掉。**不碰你的真实数据**，也不读真实配置。
 * --pg-db 请指向一个可以随便建表删表的库。
 *
 * 只支持 PostgreSQL。退出码：0 通过；1 环境问题；2 有断言失败。
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
use TypechoPlugin\Access\Migrate;
use TypechoPlugin\Access\MigrateStatus;

$pgDb = (string)($o['pg-db'] ?? '');
if ($pgDb === '') {
    fwrite(STDERR, "必须指定 --pg-db，且请指向一个可以随便建表删表的库。\n");
    exit(1);
}

const SRC = 'stsrc_';
const TGT = 'sttgt_';

function conn(array $o, string $db, string $prefix, ?string $user = null): Db
{
    $c = new Db('Pdo_Pgsql', $prefix);
    $c->addServer([
        'host'     => $o['pg-host'] ?? '127.0.0.1',
        'port'     => (int)($o['pg-port'] ?? 5432),
        'user'     => $user ?? ($o['pg-user'] ?? 'postgres'),
        'password' => $o['pg-pass'] ?? '',
        'database' => $db,
        'charset'  => 'utf8',
    ], Db::READ | Db::WRITE);
    return $c;
}

try {
    $src = conn($o, $pgDb, SRC);
    $tgt = conn($o, $pgDb, TGT);
    $src->query('SELECT 1', Db::WRITE);
} catch (\Throwable $e) {
    fwrite(STDERR, '连不上 PostgreSQL：' . $e->getMessage() . "\n");
    exit(1);
}

$fail = 0;
function chk(bool $c, string $m): void { global $fail; if (!$c) { $fail++; echo "  FAIL  $m\n"; } else { echo "   OK   $m\n"; } }

$sqlFile = $root . '/usr/plugins/Access/sql/PostgreSQL.sql';
if (!is_file($sqlFile)) { fwrite(STDERR, "找不到 {$sqlFile}\n"); exit(1); }
$sql = file_get_contents($sqlFile);

function build(Db $d, string $sql, string $prefix): void
{
    $d->query('DROP TABLE IF EXISTS ' . $prefix . 'access', Db::WRITE);
    foreach (explode(';', str_replace('typecho_', $prefix, $sql)) as $st) {
        $st = trim($st);
        if ($st === '' || strtoupper($st) === 'COMMIT') { continue; }
        if (trim(preg_replace('/^\s*--.*$/m', '', $st)) === '') { continue; }
        $d->query($st, Db::WRITE);
    }
}

function teardown(Db $src, Db $tgt): void
{
    foreach ([SRC . 'access', SRC . 'options', TGT . 'access'] as $t) {
        try { $src->query("DROP TABLE IF EXISTS {$t}", Db::WRITE); } catch (\Throwable $e) {}
    }
}
register_shutdown_function(fn() => teardown($src, $tgt));

$src->query('DROP TABLE IF EXISTS ' . SRC . 'options', Db::WRITE);
$src->query('CREATE TABLE ' . SRC . 'options (name varchar(64) NOT NULL, "user" int NOT NULL DEFAULT 0,
             value text, PRIMARY KEY(name, "user"))', Db::WRITE);
build($src, $sql, SRC);
build($tgt, $sql, TGT);

# Migrate 内部通过 Database::main() 取主库，这里指到一次性源库上
Db::set($src);

$settings = Database::settings([
    'dbType' => 'pgsql',
    'dbHost' => $o['pg-host'] ?? '127.0.0.1',
    'dbPort' => (string)($o['pg-port'] ?? 5432),
    'dbUser' => $o['pg-user'] ?? 'postgres',
    'dbPass' => $o['pg-pass'] ?? '',
    'dbName' => $pgDb,
    'dbPrefix' => TGT,
]);
$fp = Migrate::fingerprint($settings);

function seed(Db $src, int $n): void
{
    $src->query('DELETE FROM ' . SRC . 'access', Db::WRITE);
    $src->query('ALTER SEQUENCE ' . SRC . 'access_id_seq RESTART WITH 1', Db::WRITE);
    $src->query('INSERT INTO ' . SRC . 'access (ua,browser_id,os_id,url,path,ip,time,content_id,meta_id,robot,event_id)
                 SELECT \'UA\',\'C\',\'M\',\'/x\',\'/x\',\'1\', extract(epoch from now())::int, 1, 0, 0,
                        lpad(to_hex(g),32,\'0\') FROM generate_series(1,' . $n . ') g', Db::WRITE);
}
function tgtRows(Db $tgt, string $where = '1=1'): int
{
    return (int)$tgt->fetchAll($tgt->query('SELECT COUNT(1) c FROM ' . TGT . "access WHERE {$where}", Db::READ))[0]['c'];
}
function clearState(Db $src, Db $tgt): void
{
    $src->query('DELETE FROM ' . SRC . 'options', Db::WRITE);
    $tgt->query('DELETE FROM ' . TGT . 'access', Db::WRITE);
    $tgt->query('ALTER SEQUENCE ' . TGT . 'access_id_seq RESTART WITH 1', Db::WRITE);
}

echo "源 " . SRC . "access / 目标 " . TGT . "access / 库 {$pgDb}\n\n";

echo "=== 1. 大表推迟迁移时，目标库新日志不能吃掉源数据 ===\n";
clearState($src, $tgt);
seed($src, Migrate::AUTO_LIMIT + 1);
$res = Migrate::ensure($tgt, $settings, microtime(true) + 30);
chk($res['status'] === MigrateStatus::Skipped, '数据量超过 AUTO_LIMIT，ensure 返回 Skipped');
$seq = (int)$tgt->fetchAll($tgt->query('SELECT last_value FROM ' . TGT . 'access_id_seq', Db::READ))[0]['last_value'];
chk($seq >= Migrate::AUTO_LIMIT + 1, "目标表自增起点已抬到源表最大 id 之上（{$seq}）");

for ($i = 0; $i < 5; $i++) {
    $tgt->query('INSERT INTO ' . TGT . 'access (ua,browser_id,os_id,url,path,ip,time,content_id,meta_id,robot,event_id)
                 VALUES (\'NEW\',\'C\',\'M\',\'/n\',\'/n\',\'2\',' . time() . ',9,0,0,\'' . bin2hex(random_bytes(16)) . '\')', Db::WRITE);
}
$minNew = (int)$tgt->fetchAll($tgt->query('SELECT MIN(id) m FROM ' . TGT . "access WHERE ua='NEW'", Db::READ))[0]['m'];
chk($minNew > Migrate::AUTO_LIMIT, "迁移期间产生的新日志 id 从 {$minNew} 起，不与迁移区间重叠");

$r = Migrate::run($src, $tgt, ['fingerprint' => $fp, 'batchSize' => 5000]);
chk(tgtRows($tgt, "ua='UA'") === Migrate::AUTO_LIMIT + 1, '源表数据一行不少地迁到位');
chk($r['done'] === true, 'done=true');
chk(Migrate::checkpoint($src, $fp) === Migrate::AUTO_LIMIT + 1, '断点已存进主库 options');

echo "\n=== 2. 源库查询失败不能被当成「没有历史数据」 ===\n";
clearState($src, $tgt);
seed($src, 10);

/*
 * 要测的是「表看得见、但读不出来」——「表真的不存在」本来就该打完成标记，拿它测等于没测。
 * 用一个只有 pg_tables 可见性、没有 SELECT 权限的角色来连：
 * tableExistsStrict() 照常返回 true，sourceCountStrict() 抛 42501。
 * 建角色要 CREATEROLE/超级用户；权限不够就明确跳过，不假装通过。
 */
$roleOk = true;
try {
    $src->query("DROP ROLE IF EXISTS access_selftest_low", Db::WRITE);
    $src->query("CREATE ROLE access_selftest_low LOGIN", Db::WRITE);
    $src->query('GRANT USAGE ON SCHEMA public TO access_selftest_low', Db::WRITE);
    $src->query('GRANT ALL ON ' . SRC . 'options TO access_selftest_low', Db::WRITE);
    $src->query('REVOKE ALL ON ' . SRC . 'access FROM access_selftest_low', Db::WRITE);
} catch (\Throwable $e) {
    $roleOk = false;
    echo "  跳过  当前账号无法建角色（{$e->getMessage()}），这一节需要 CREATEROLE 或超级用户\n";
}

if ($roleOk) {
    $low = conn($o, $pgDb, SRC, 'access_selftest_low');
    Db::set($low);
    $res = Migrate::ensure($tgt, $settings, microtime(true) + 5);
    chk($res['status'] === MigrateStatus::None, 'status=None（实得 ' . $res['status']->name . '）');
    chk(!Migrate::isMarked($low, $fp), '**没有**写下完成标记 —— 旧代码这里会 mark()');
    $rr = Migrate::run($low, $tgt, ['fingerprint' => $fp]);
    chk($rr['done'] === false && $rr['error'] !== null,
        'run() 返回 done=false 并带错误：' . mb_strimwidth((string)$rr['error'], 0, 46, '…'));
    Db::set($src);
    try { $src->query("DROP ROLE IF EXISTS access_selftest_low", Db::WRITE); } catch (\Throwable $e) {}
}

echo "\n=== 3. 写不进去的行会被真正重试 ===\n";
clearState($src, $tgt);
seed($src, 10);
$tgt->query('ALTER TABLE ' . TGT . 'access ADD CONSTRAINT st_poison CHECK (content_id <> 7)', Db::WRITE);
$src->query('UPDATE ' . SRC . 'access SET content_id = 7 WHERE id = 4', Db::WRITE);

$r = Migrate::run($src, $tgt, ['fingerprint' => $fp, 'batchSize' => 3]);
chk($r['done'] === false, '有失败行时 done=false');
chk($r['failedIds'] === [4], '失败行记为 id=4');
chk(Migrate::checkpoint($src, $fp) === 10, '断点仍推进到 10，不卡在失败行上');
chk(tgtRows($tgt) === 9, '其余 9 行照常落库');
chk(Migrate::failureAttempts($src, $fp) === [4 => 1], '失败次数 = 1');

Migrate::run($src, $tgt, ['fingerprint' => $fp]);
chk(Migrate::failureAttempts($src, $fp) === [4 => 2], '再跑一轮次数变 2 —— 说明确实被重读重试了');

$tgt->query('ALTER TABLE ' . TGT . 'access DROP CONSTRAINT st_poison', Db::WRITE);
$r = Migrate::run($src, $tgt, ['fingerprint' => $fp]);
chk(tgtRows($tgt) === 10, '障碍排除后补写成功，10 行齐了');
chk(Migrate::failures($src, $fp) === [], '失败记录已清空');
chk($r['done'] === true, 'done=true，这时才允许打完成标记');

echo "\n=== 4. 一直失败的行不会让迁移永远重试下去 ===\n";
clearState($src, $tgt);
seed($src, 5);
$tgt->query('ALTER TABLE ' . TGT . 'access ADD CONSTRAINT st_poison CHECK (content_id <> 7)', Db::WRITE);
$src->query('UPDATE ' . SRC . 'access SET content_id = 7 WHERE id = 2', Db::WRITE);
for ($i = 0; $i < 5; $i++) { Migrate::run($src, $tgt, ['fingerprint' => $fp]); }
$att = Migrate::failureAttempts($src, $fp);
chk(($att[2] ?? 0) === 3, '重试次数封顶在 3（实得 ' . ($att[2] ?? 0) . '）');
chk(Migrate::failures($src, $fp) === [2], '记录仍在 → 完成标记打不下去');
$tgt->query('ALTER TABLE ' . TGT . 'access DROP CONSTRAINT st_poison', Db::WRITE);

echo "\n" . ($fail === 0 ? '全部通过。' : "失败断言：{$fail}") . "\n";
exit($fail === 0 ? 0 : 2);
