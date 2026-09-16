<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';
require __DIR__.'/../dkp/scheduled_events.php';

function fixture(string $dsn='sqlite::memory:'): Auth {
    $db=new PDO($dsn);$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000;');
    $auth=new Auth($db,'https://example.test',static fn()=>false);
    $db->exec("CREATE TABLE dkp_users(id INTEGER PRIMARY KEY,role TEXT,verified_at INTEGER);
        INSERT INTO dkp_users VALUES(1,'admin',1);
        CREATE TABLE fc_write_lock(id INTEGER PRIMARY KEY,revision INTEGER);
        INSERT INTO fc_write_lock VALUES(1,0);
        CREATE TABLE fc_events(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,category TEXT,points INTEGER,scheduled_at INTEGER,status TEXT DEFAULT 'open',revision INTEGER DEFAULT 1,creator_id INTEGER REFERENCES dkp_users(id),created_at INTEGER,closed_at INTEGER,closed_by INTEGER);
        CREATE TABLE fc_operations(request_key TEXT PRIMARY KEY,actor_id INTEGER REFERENCES dkp_users(id),kind TEXT,payload_hash TEXT,details TEXT,created_at INTEGER);
        CREATE TABLE fc_points(id INTEGER PRIMARY KEY,amount INTEGER);");
    return $auth;
}
function at(string $s): int {return (new DateTimeImmutable($s,new DateTimeZone('Europe/Moscow')))->getTimestamp();}
function settings(array $overrides=[]):array {return array_replace(['enabled'=>true,'creator_id'=>1,'points'=>5,'catch_up_minutes'=>30],$overrides);}
$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('Failed: '.$label);$n++;}
function countEvents(Auth $a):int {return (int)$a->query('SELECT COUNT(*) FROM fc_events')->fetchColumn();}
if (($argv[1]??'')==='--prepare') {fixture('sqlite:'.$argv[2]);exit;}
if (($argv[1]??'')==='--worker') {
    $db=new PDO('sqlite:'.$argv[2]);$db->exec('PRAGMA busy_timeout=10000');
    $a=new Auth($db,'https://example.test',static fn()=>false);
    echo (new ScheduledEvents($a,settings()))->run(at('2026-09-16 07:30:00'));exit;
}
date_default_timezone_set('America/Los_Angeles');
$a=fixture();$s=new ScheduledEvents($a,settings());$s->check();
check(countEvents($a)===0,'configuration check is read-only');
check($s->run(at('2026-09-16 07:29:59'))===0,'not before morning');
check($s->run(at('2026-09-16 07:30:00'))===1,'morning boundary');
check($s->run(at('2026-09-16 07:30:59'))===0,'minute retry');
check($s->run(at('2026-09-16 15:29:59'))===0,'not before afternoon');
check($s->run(at('2026-09-16 15:30:00'))===1,'afternoon boundary');
check($s->run(at('2026-09-16 20:30:00'))===1,'evening boundary');
$rows=$a->query('SELECT * FROM fc_events ORDER BY id')->fetchAll();
check(array_column($rows,'title')===['Утреннее ЧВ','Дневное ЧВ','Вечернее ЧВ'],'requested names');
check(array_column($rows,'scheduled_at')===[at('2026-09-16 07:30:00'),at('2026-09-16 15:30:00'),at('2026-09-16 20:30:00')],'Moscow timestamps despite host timezone');
check(array_column($rows,'points')===[5,5,5] && array_column($rows,'status')===['open','open','open'],'open events and configured points');
check((int)$a->query('SELECT COUNT(*) FROM fc_points')->fetchColumn()===0,'no automatic rewards');
check($s->run(at('2026-09-17 00:00:00'))===0,'no previous-day backlog');
check($s->run(at('2026-09-17 07:30:00'))===1,'next day has its own key');
$a->query("UPDATE fc_events SET title='Changed',scheduled_at=0,status='cancelled'");
check($s->run(at('2026-09-17 07:31:00'))===0,'cancelled/renamed/rescheduled event does not reappear');
check((new ScheduledEvents($a,settings(['points'=>9])))->run(at('2026-09-17 07:32:00'))===0,'changing reward does not duplicate');
check(countEvents($a)===4,'one event per slot');
foreach (['07:59:59'=>1,'08:00:00'=>1,'08:00:01'=>0,'12:00:00'=>0] as $time=>$expected) {
    $b=fixture();check((new ScheduledEvents($b,settings()))->run(at('2026-09-16 '.$time))===$expected,'catch-up '.$time);
}
$b=fixture();$b->query("INSERT INTO fc_events(title,category,points,scheduled_at,creator_id,created_at,status) VALUES('Manual','cw',9,?,1,0,'awarded')",[at('2026-09-16 07:30:00')]);
check((new ScheduledEvents($b,settings()))->run(at('2026-09-16 07:30:00'))===0 && countEvents($b)===1,'manual slot adopted without duplication');
check($b->query('SELECT status FROM fc_events')->fetchColumn()==='awarded','existing status unchanged');
$b=fixture();$b->db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON fc_operations BEGIN SELECT RAISE(ABORT,'simulated audit failure'); END;");
try {(new ScheduledEvents($b,settings()))->run(at('2026-09-16 07:30:00'));throw new RuntimeException('Expected failure');}catch(PDOException){}
check(countEvents($b)===0 && !$b->db->inTransaction(),'event rolls back if journal fails');
$b->db->exec('DROP TRIGGER fail_audit');
check((new ScheduledEvents($b,settings()))->run(at('2026-09-16 07:30:01'))===1,'retry after rollback');
$b=fixture();$b->query("UPDATE dkp_users SET role='member'");
try {(new ScheduledEvents($b,settings()))->run(at('2026-09-16 07:30:00'));throw new LogicException('Expected invalid creator');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
check(countEvents($b)===0,'unprivileged creator rejected');
check((new ScheduledEvents($b,settings(['enabled'=>false])))->run(at('2026-09-16 07:30:00'))===0,'disabled scheduler');
try {new ScheduledEvents($b,settings(['points'=>0]));throw new LogicException('Expected invalid reward');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
check(true,'invalid settings rejected');
echo "$n checks passed\n";
