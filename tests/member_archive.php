<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';require __DIR__.'/../dkp/dkp_store.php';
$db=new PDO('sqlite::memory:');$db->exec('PRAGMA foreign_keys=ON');$db->exec(file_get_contents(__DIR__.'/member_archive_schema.sql'));$a=new Auth($db,'https://example.test',fn()=>true);$d=new DKP($a);
$db->exec("CREATE TABLE dkp_users(id INTEGER PRIMARY KEY,email TEXT,nickname TEXT,role TEXT,verified_at INTEGER,session_version INTEGER);INSERT INTO dkp_users VALUES(1,'a@x.test','Admin','admin',1,1),(2,'o@x.test','Officer','officer',1,1),(3,'m@x.test','Member','member',1,1),(4,'n@x.test','New','member',NULL,1),(5,'f@x.test','Free','member',1,1);CREATE TABLE fc_roster(member_id INTEGER PRIMARY KEY AUTOINCREMENT,nickname TEXT,created_at INTEGER);INSERT INTO fc_roster VALUES(10,'One',1),(11,'Two',1);CREATE TABLE fc_20260914_ledger(id INTEGER PRIMARY KEY,user_id INTEGER,amount INTEGER,reason TEXT,created_at INTEGER,actor_id INTEGER);INSERT INTO fc_20260914_ledger VALUES(1,10,31,'old',1,111),(2,11,5,'old',1,111);CREATE TABLE fc_web_links(web_user_id INTEGER PRIMARY KEY,member_id INTEGER UNIQUE,linked_at INTEGER);CREATE TABLE fc_write_lock(id INTEGER PRIMARY KEY,revision INTEGER);INSERT INTO fc_write_lock VALUES(1,0);CREATE TABLE fc_operations(request_key TEXT PRIMARY KEY,actor_id INTEGER,kind TEXT,payload_hash TEXT,details TEXT,created_at INTEGER);CREATE TABLE fc_points(id INTEGER PRIMARY KEY AUTOINCREMENT,member_id INTEGER,amount INTEGER,reason TEXT,actor_id INTEGER,request_key TEXT REFERENCES fc_operations(request_key),created_at INTEGER,UNIQUE(request_key,member_id));");
$db->exec("CREATE TABLE fc_member_archive(member_id INTEGER PRIMARY KEY REFERENCES fc_roster(member_id),archived_at INTEGER,archived_by INTEGER REFERENCES dkp_users(id),reason TEXT);");
$n=0;function check($v){global $n;if(!$v)throw new Exception('Check failed #'.($n+1));$n++;}function deny($fn){try{$fn();}catch(AuthError){check(true);return;}throw new Exception('Expected denial');}function k(){return bin2hex(random_bytes(32));}
$p=['members'=>['10','11'],'amount'=>'5','reason'=>'ЧВ'];$key=k();
check($d->perform(2,1,$key,'adjust',$p));check($d->balance('10')===36 && $d->balance('11')===10);
check(!$d->perform(2,1,$key,'adjust',$p));check($d->balance('10')===36);
deny(fn()=>$d->perform(2,1,$key,'adjust',array_replace($p,['amount'=>'6'])));
deny(fn()=>$d->perform(3,1,k(),'adjust',$p));
deny(fn()=>$d->perform(2,1,k(),'role',['account'=>'3','role'=>'officer']));
deny(fn()=>$d->perform(2,1,k(),'link',['account'=>'3','member'=>'10']));
deny(fn()=>$d->perform(2,1,k(),'create',['nickname'=>'No']));
deny(fn()=>$d->perform(1,1,k(),'adjust',array_replace($p,['amount'=>'-11'])));check($d->balance('10')===36 && $d->balance('11')===10);
deny(fn()=>$d->perform(1,1,k(),'adjust',array_replace($p,['members'=>['10','999']])));check($d->balance('10')===36);
deny(fn()=>$d->perform(1,1,k(),'adjust',array_replace($p,['reason'=>''])));
deny(fn()=>$d->perform(1,1,k(),'adjust',array_replace($p,['amount'=>'0'])));
deny(fn()=>$d->perform(1,1,k(),'adjust',array_replace($p,['amount'=>'1.5'])));
check($d->perform(1,1,k(),'adjust',['members'=>['11','11'],'amount'=>'-10','reason'=>'Списание']));check($d->balance('11')===0);
check($d->perform(1,1,k(),'link',['member'=>'10','account'=>'3']));
deny(fn()=>$d->perform(1,1,k(),'link',['member'=>'10','account'=>'5']));deny(fn()=>$d->perform(1,1,k(),'link',['member'=>'11','account'=>'3']));
deny(fn()=>$d->perform(1,1,k(),'link',['member'=>'11','account'=>'4']));
check($d->perform(1,1,k(),'role',['account'=>'2','role'=>'member']));deny(fn()=>$d->perform(2,1,k(),'adjust',$p));
deny(fn()=>$d->perform(1,1,k(),'role',['account'=>'1','role'=>'member']));deny(fn()=>$d->perform(1,1,k(),'role',['account'=>'3','role'=>'admin']));
check($d->perform(1,1,k(),'create',['nickname'=>'New player']));check(count($d->roster())===3);deny(fn()=>$d->perform(1,1,k(),'create',['nickname'=>'New player']));
check(count($d->history('10'))===2);check($a->query('SELECT SUM(amount) FROM fc_20260914_ledger')->fetchColumn()==36);
check($a->query('SELECT COUNT(*) FROM fc_points')->fetchColumn()==3);
function h($s){return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function formStart($action){echo '<form method="post">';}
$dkp=$d;$auth=$a;$user=$a->user(1);$_SESSION=['csrf'=>'test'];
ob_start();include __DIR__.'/../dkp/manage_view.php';$html=ob_get_clean();check(str_contains($html,'Офицеры и аккаунты'));
$user=$a->user(2);$user['role']='officer';ob_start();include __DIR__.'/../dkp/manage_view.php';$html=ob_get_clean();check(str_contains($html,'m@x.test'));
echo "$n checks passed\n";
if (getenv('DKP_HTTP_FIXTURE')) {
    $a->query('ALTER TABLE dkp_users ADD COLUMN password_hash TEXT');
    $a->query('UPDATE dkp_users SET password_hash=?',[Auth::hashPassword('local-test-password')]);
    $a->query('CREATE TABLE dkp_limits(bucket TEXT PRIMARY KEY,hits INTEGER,expires_at INTEGER)');
    $a->query('VACUUM INTO ?',[getenv('DKP_HTTP_FIXTURE')]);
}

$a=new Auth($db,'https://example.test',fn()=>true);$d=new DKP($a);
$db->exec("CREATE TABLE fc_events(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,category TEXT,points INTEGER,scheduled_at INTEGER,status TEXT DEFAULT 'open',revision INTEGER DEFAULT 1,creator_id INTEGER,created_at INTEGER,closed_at INTEGER,closed_by INTEGER);CREATE TABLE fc_event_members(event_id INTEGER REFERENCES fc_events(id),member_id INTEGER REFERENCES fc_roster(member_id),PRIMARY KEY(event_id,member_id));UPDATE dkp_users SET role='officer' WHERE id=2;");
$fields=['title'=>'ЧВ <script>','category'=>'cw','points'=>'5','scheduled'=>'2026-09-14T20:00'];
$createKey=k();check($d->perform(2,2,$createKey,'evt_create',$fields));check(!$d->perform(2,2,$createKey,'evt_create',$fields));check(count($d->events())===1);
$e=$d->event('1');check($e['status']==='open' && $e['revision']==1 && $e['members']===[]);
deny(fn()=>$d->perform(3,1,k(),'evt_create',$fields));deny(fn()=>$d->perform(2,1,k(),'evt_create',$fields));
deny(fn()=>$d->perform(1,1,k(),'evt_create',array_replace($fields,['points'=>'0'])));
deny(fn()=>$d->perform(1,1,k(),'evt_create',array_replace($fields,['scheduled'=>'2026-02-30T10:00'])));
deny(fn()=>$d->perform(1,1,k(),'evt_create',array_replace($fields,['category'=>'bogus'])));
deny(fn()=>$d->perform(1,1,k(),'evt_award',['event'=>'1','revision'=>'1']));
$save=$fields+['event'=>'1','revision'=>'1','members'=>['10','11','11']];check($d->perform(2,2,k(),'evt_save',$save));
$e=$d->event('1');check(count($e['members'])===2 && $e['revision']==2);
deny(fn()=>$d->perform(1,1,k(),'evt_save',$save));deny(fn()=>$d->perform(1,1,k(),'evt_award',['event'=>'1','revision'=>'1']));
$bad=array_replace($save,['revision'=>'2','members'=>['10','999']]);deny(fn()=>$d->perform(1,1,k(),'evt_save',$bad));check(count($d->event('1')['members'])===2 && $d->event('1')['revision']==2);
$before10=$d->balance('10');$before11=$d->balance('11');$awardKey=k();
$db->exec("CREATE TRIGGER simulated_failure BEFORE INSERT ON fc_points WHEN NEW.member_id=11 BEGIN SELECT RAISE(ABORT,'simulated'); END");
try{$d->perform(1,1,$awardKey,'evt_award',['event'=>'1','revision'=>'2']);throw new Exception('Expected PDO failure');}catch(PDOException){check(true);}
check($d->event('1')['status']==='open' && $d->balance('10')===$before10);check(!$a->query('SELECT request_key FROM fc_operations WHERE request_key=?',[$awardKey])->fetch());
$db->exec('DROP TRIGGER simulated_failure');check($d->perform(1,1,$awardKey,'evt_award',['event'=>'1','revision'=>'2']));
check(!$d->perform(1,1,$awardKey,'evt_award',['event'=>'1','revision'=>'2']));
check($d->balance('10')===$before10+5 && $d->balance('11')===$before11+5);
deny(fn()=>$d->perform(2,2,k(),'evt_award',['event'=>'1','revision'=>'3']));deny(fn()=>$d->perform(1,1,k(),'evt_save',array_replace($save,['revision'=>'3'])));
deny(fn()=>$d->perform(1,1,k(),'evt_cancel',['event'=>'1','revision'=>'3','reason'=>'Already awarded']));
check($d->perform(1,1,k(),'evt_create',$fields));check($d->perform(2,2,k(),'evt_cancel',['event'=>'2','revision'=>'1','reason'=>'Не состоялось']));check($d->event('2')['status']==='cancelled');
deny(fn()=>$d->perform(1,1,k(),'evt_award',['event'=>'2','revision'=>'2']));
check($a->query('SELECT SUM(amount) FROM fc_20260914_ledger')->fetchColumn()==36);
check(count($d->history('10'))===3);
// Prepared open events used by independent concurrent workers and HTTP checks.
foreach(['Race award','Race cancel','HTTP event'] as $title){$d->perform(1,1,k(),'evt_create',array_replace($fields,['title'=>$title]));$id=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();$d->perform(1,1,k(),'evt_save',array_replace($save,['title'=>$title,'event'=>$id]));}
if(getenv('EVENT_FIXTURE')){$a->query('ALTER TABLE dkp_users ADD COLUMN password_hash TEXT');$a->query('UPDATE dkp_users SET password_hash=?',[Auth::hashPassword('local-test-password')]);$a->query('CREATE TABLE dkp_limits(bucket TEXT PRIMARY KEY,hits INTEGER,expires_at INTEGER)');$a->query('VACUUM INTO ?',[getenv('EVENT_FIXTURE')]);}
echo "$n total checks passed, including events and admin regression\n";

$a=new Auth($db,'https://example.test',fn()=>true);$now=10000;$d=new DKP($a,function()use(&$now){return $now;});
$d->perform(1,1,k(),'adjust',['members'=>['10'],'amount'=>'59','reason'=>'Fixture']);
$d->perform(1,1,k(),'adjust',['members'=>['11'],'amount'=>'95','reason'=>'Fixture']);
$d->perform(1,1,k(),'link',['member'=>'11','account'=>'5']);
check($d->balance('10')===100 && $d->balance('11')===100);
$lot=['item'=>'Лот <script>','minimum'=>'10','step'=>'5','minutes'=>'10'];
$create=k();check($d->perform(2,2,$create,'auc_create',$lot));check(!$d->perform(2,2,$create,'auc_create',$lot));check(count($d->auctions())===1);
deny(fn()=>$d->perform(3,1,k(),'auc_create',$lot));deny(fn()=>$d->perform(1,1,k(),'auc_create',array_replace($lot,['minimum'=>'0'])));deny(fn()=>$d->perform(1,1,k(),'auc_create',array_replace($lot,['minutes'=>'0'])));
$bid=['auction'=>'1','revision'=>'1','amount'=>'10'];$key=k();
deny(fn()=>$d->perform(2,2,k(),'auc_bid',$bid));deny(fn()=>$d->perform(4,1,k(),'auc_bid',$bid));
check($d->perform(3,1,$key,'auc_bid',$bid));check(!$d->perform(3,1,$key,'auc_bid',$bid));
check($d->wallet('10')===['total'=>100,'reserved'=>10,'available'=>90]);
deny(fn()=>$d->perform(5,1,k(),'auc_bid',$bid));
deny(fn()=>$d->perform(5,1,k(),'auc_bid',['auction'=>'1','revision'=>'2','amount'=>'10']));deny(fn()=>$d->perform(5,1,k(),'auc_bid',['auction'=>'1','revision'=>'2','amount'=>'14']));
check($d->perform(5,1,k(),'auc_bid',['auction'=>'1','revision'=>'2','amount'=>'15']));check($d->reserved('10')===0);
check($d->perform(5,1,k(),'auc_bid',['auction'=>'1','revision'=>'3','amount'=>'20']));check($d->reserved('11')===20);
deny(fn()=>$d->perform(1,1,k(),'auc_cancel',['auction'=>'1','revision'=>'4','reason'=>'No']));
check($d->perform(1,1,k(),'auc_create',array_replace($lot,['item'=>'Second'])));
deny(fn()=>$d->perform(5,1,k(),'auc_bid',['auction'=>'2','revision'=>'1','amount'=>'85']));
check($d->perform(5,1,k(),'auc_bid',['auction'=>'2','revision'=>'1','amount'=>'80']));check($d->wallet('11')['available']===0);
deny(fn()=>$d->perform(1,1,k(),'adjust',['members'=>['10','11'],'amount'=>'-1','reason'=>'Reserved']));check($d->balance('10')===100);
check($d->perform(1,1,k(),'auc_create',$lot));check($d->perform(2,2,k(),'auc_cancel',['auction'=>'3','revision'=>'1','reason'=>'Test cancel']));check($d->auction('3')['status']==='cancelled');
deny(fn()=>$d->perform(3,1,k(),'auc_bid',['auction'=>'3','revision'=>'2','amount'=>'10']));
$now=10570;check($d->perform(3,1,k(),'auc_bid',['auction'=>'1','revision'=>'4','amount'=>'25']));check($d->auction('1')['ends_at']==10690);
$now=10600;check($d->settleDue()===1);check($d->balance('11')===20 && $d->reserved('11')===0);
$now=10690;deny(fn()=>$d->perform(5,1,k(),'auc_bid',['auction'=>'1','revision'=>'5','amount'=>'30']));
$db->exec("CREATE TRIGGER fail_close BEFORE INSERT ON fc_points WHEN NEW.amount<0 BEGIN SELECT RAISE(ABORT,'fail'); END");
try{$d->settleDue();throw new Exception('Expected failure');}catch(PDOException){check(true);}
check($d->auction('1')['status']==='open' && $d->balance('10')===100 && $d->reserved('10')===25);
$db->exec('DROP TRIGGER fail_close');check($d->settleDue()===1);check($d->balance('10')===75 && $d->reserved('10')===0);check($d->settleDue()===0);
check($a->query("SELECT COUNT(*) FROM fc_operations WHERE kind='auc_close'")->fetchColumn()==2);
$d->perform(1,1,k(),'auc_create',array_replace($lot,['minutes'=>'1']));$now+=60;
deny(fn()=>$d->perform(1,1,k(),'auc_cancel',['auction'=>'4','revision'=>'1','reason'=>'Expired']));check($d->settleDue()===1);check($d->balance('10')===75 && $d->balance('11')===20);
deny(fn()=>$d->perform(3,1,'sys:'.str_repeat('a',60),'auc_bid',$bid));
// Fixtures for independent workers and HTTP. Keep these dates in the future.
$now=time();
foreach(['Race one','Race two','HTTP lot'] as $name)$d->perform(1,1,k(),'auc_create',array_replace($lot,['item'=>$name,'minutes'=>'60']));
if(getenv('AUCTION_FIXTURE')){$a->query('ALTER TABLE dkp_users ADD COLUMN password_hash TEXT');$a->query('UPDATE dkp_users SET password_hash=?',[Auth::hashPassword('local-test-password')]);$a->query('CREATE TABLE dkp_limits(bucket TEXT PRIMARY KEY,hits INTEGER,expires_at INTEGER)');$a->query('VACUUM INTO ?',[getenv('AUCTION_FIXTURE')]);}
echo "$n total checks passed (auctions, events, admin)\n";

$a=new Auth($db,'https://example.test',fn()=>true);$d=new DKP($a);
$f=['title'=>'Самоотметка <test>','category'=>'cw','points'=>'3','scheduled'=>'2026-09-14T22:00'];
$d->perform(1,1,k(),'evt_create',$f);$id=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();
$before=$d->balance('10');$ops=(int)$a->query('SELECT COUNT(*) FROM fc_points')->fetchColumn();$key=k();
check($d->perform(3,1,$key,'evt_join',['event'=>$id,'revision'=>'1','member_id'=>'11']));
$e=$d->event($id);check(count($e['members'])===1 && (string)$e['members'][0]['member_id']==='10');check($e['revision']==2);
check($d->balance('10')===$before && $a->query('SELECT COUNT(*) FROM fc_points')->fetchColumn()===$ops);
check(!$d->perform(3,1,$key,'evt_join',['event'=>$id,'revision'=>'1','member_id'=>'11']));
deny(fn()=>$d->perform(3,1,k(),'evt_join',['event'=>$id]));check($d->event($id)['revision']==2);
deny(fn()=>$d->perform(1,1,k(),'evt_join',['event'=>$id])); // admin has no player link
// No capability to award, edit other attendance, or cancel an event.
deny(fn()=>$d->perform(3,1,k(),'evt_award',['event'=>$id,'revision'=>'2']));
deny(fn()=>$d->perform(3,1,k(),'evt_save',$f+['event'=>$id,'revision'=>'2','members'=>['11']]));
deny(fn()=>$d->perform(3,1,k(),'evt_cancel',['event'=>$id,'revision'=>'2','reason'=>'Forbidden']));
deny(fn()=>$d->perform(4,1,k(),'evt_join',['event'=>$id]));
check($d->perform(5,1,k(),'evt_join',['event'=>$id]));
deny(fn()=>$d->perform(2,2,k(),'evt_award',['event'=>$id,'revision'=>'2']));
deny(fn()=>$d->perform(2,2,k(),'evt_save',$f+['event'=>$id,'revision'=>'2','members'=>['10']]));
check($d->perform(3,1,k(),'evt_leave',['event'=>$id,'member_id'=>'11']));$e=$d->event($id);check(count($e['members'])===1 && (string)$e['members'][0]['member_id']==='11');
deny(fn()=>$d->perform(3,1,k(),'evt_leave',['event'=>$id]));
check($d->perform(3,1,k(),'evt_join',['event'=>$id]));
$e=$d->event($id);check($d->perform(2,2,k(),'evt_award',['event'=>$id,'revision'=>(string)$e['revision']]));check($d->balance('10')===$before+3);
deny(fn()=>$d->perform(3,1,k(),'evt_leave',['event'=>$id]));deny(fn()=>$d->perform(3,1,k(),'evt_join',['event'=>$id]));
$d->perform(1,1,k(),'evt_create',$f);$cancel=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();$d->perform(1,1,k(),'evt_cancel',['event'=>$cancel,'revision'=>'1','reason'=>'Closed']);deny(fn()=>$d->perform(3,1,k(),'evt_join',['event'=>$cancel]));
// Capacity and transactional rollback on write failure.
$d->perform(1,1,k(),'evt_create',$f);$full=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();
for($i=1000;$i<1100;$i++){$a->query('INSERT INTO fc_roster VALUES(?,?,1)',[$i,'Fixture '.$i]);$a->query('INSERT INTO fc_event_members VALUES(?,?)',[$full,$i]);}
deny(fn()=>$d->perform(3,1,k(),'evt_join',['event'=>$full]));check(count($d->event($full)['members'])===100);
$d->perform(1,1,k(),'evt_create',$f);$fail=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();$key=k();
$db->exec("CREATE TRIGGER fail_members BEFORE INSERT ON fc_operations WHEN NEW.kind='evt_join' BEGIN SELECT RAISE(ABORT,'fail'); END");
try{$d->perform(3,1,$key,'evt_join',['event'=>$fail]);throw new Exception('Expected failure');}catch(PDOException){check(true);}
check($d->event($fail)['members']===[] && $d->event($fail)['revision']==1);$db->exec('DROP TRIGGER fail_members');
check($d->perform(3,1,$key,'evt_join',['event'=>$fail]));
// Clean events for concurrency and HTTP.
foreach(['Race join','Race award','HTTP join'] as $title){$d->perform(1,1,k(),'evt_create',array_replace($f,['title'=>$title]));}
if(getenv('CHECKIN_FIXTURE')){$a->query('ALTER TABLE dkp_users ADD COLUMN password_hash TEXT');$a->query('UPDATE dkp_users SET password_hash=?',[Auth::hashPassword('local-test-password')]);$a->query('CREATE TABLE dkp_limits(bucket TEXT PRIMARY KEY,hits INTEGER,expires_at INTEGER)');$a->query('VACUUM INTO ?',[getenv('CHECKIN_FIXTURE')]);}
echo "$n checks passed including self check-in\n";

$beforeCount=$n;
$p=['member'=>'10','reason'=>'Left guild'];$key=k();
deny(fn()=>$d->perform(2,2,k(),'archive',$p));
deny(fn()=>$d->perform(3,1,k(),'archive',$p));
deny(fn()=>$d->perform(1,0,k(),'archive',$p));
deny(fn()=>$d->perform(1,1,k(),'archive',['member'=>'999','reason'=>'Unknown']));
deny(fn()=>$d->perform(1,1,k(),'archive',['member'=>'10','reason'=>'']));
// Clear expired lots using the real settlement path, then create a leading bid.
$d->settleDue();
$d->perform(1,1,k(),'auc_create',['item'=>'Archive guard','minimum'=>'1','step'=>'1','minutes'=>'10']);
$auction=(string)$a->query('SELECT MAX(id) FROM fc_auctions')->fetchColumn();
$d->perform(3,1,k(),'auc_bid',['auction'=>$auction,'revision'=>'1','amount'=>'1']);
deny(fn()=>$d->perform(1,1,k(),'archive',$p));
$a->query('UPDATE fc_auctions SET ends_at=1 WHERE id=?',[$auction]);
deny(fn()=>$d->perform(1,1,k(),'archive',$p));
$d->settleDue();
$d->perform(1,1,k(),'evt_create',$f);$event=(string)$a->query('SELECT MAX(id) FROM fc_events')->fetchColumn();
$d->perform(3,1,k(),'evt_join',['event'=>$event]);
$revision=(string)$d->event($event)['revision'];
$history=$d->history('10');$balance=$d->balance('10');$expectedRoster=count($d->roster());
$closed=$a->query("SELECT m.* FROM fc_event_members m JOIN fc_events e ON e.id=m.event_id WHERE e.status<>'open' AND m.member_id=10 ORDER BY m.event_id")->fetchAll();
// Audit failure must roll back the archive and the open-event edits together.
$db->exec("CREATE TRIGGER fail_archive BEFORE INSERT ON fc_operations WHEN NEW.kind='archive' BEGIN SELECT RAISE(ABORT,'fail'); END");
try{$d->perform(1,1,$key,'archive',$p);throw new Exception('Expected failure');}catch(PDOException){check(true);}
check(count($d->archivedRoster())===0);check((string)$d->event($event)['revision']===$revision);check(count($d->event($event)['members'])===1);
$db->exec('DROP TRIGGER fail_archive');
check($d->perform(1,1,$key,'archive',$p));check(!$d->perform(1,1,$key,'archive',$p));
check(count($d->roster())===$expectedRoster-1 && count($d->archivedRoster())===1);
check($d->balance('10')===$balance && $d->history('10')===$history);
check((string)$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=3')->fetchColumn()==='10');
check($closed===$a->query("SELECT m.* FROM fc_event_members m JOIN fc_events e ON e.id=m.event_id WHERE e.status<>'open' AND m.member_id=10 ORDER BY m.event_id")->fetchAll());
check($d->event($event)['members']===[] && (int)$d->event($event)['revision']===(int)$revision+1);
deny(fn()=>$d->perform(1,1,k(),'archive',$p));
deny(fn()=>$d->perform(1,1,k(),'evt_award',['event'=>$event,'revision'=>$revision]));
deny(fn()=>$d->perform(3,1,k(),'evt_join',['event'=>$event]));
deny(fn()=>$d->perform(1,1,k(),'evt_save',$f+['event'=>$event,'revision'=>(string)((int)$revision+1),'members'=>['10']]));
deny(fn()=>$d->perform(1,1,k(),'adjust',['members'=>['11','10'],'amount'=>'1','reason'=>'Archived']));
deny(fn()=>$d->perform(1,1,k(),'link',['member'=>'10','account'=>'4']));
$d->perform(1,1,k(),'auc_create',['item'=>'No archived bidders','minimum'=>'1','step'=>'1','minutes'=>'10']);$auction=(string)$a->query('SELECT MAX(id) FROM fc_auctions')->fetchColumn();
deny(fn()=>$d->perform(3,1,k(),'auc_bid',['auction'=>$auction,'revision'=>'1','amount'=>'1']));
$dkp=$d;$auth=$a;$user=$a->user(1);ob_start();include __DIR__.'/../dkp/manage_view.php';$html=ob_get_clean();check(str_contains($html,'Удалить участника') && str_contains($html,'Восстановить участника'));
$user=$a->user(2);ob_start();include __DIR__.'/../dkp/manage_view.php';$html=ob_get_clean();check(!str_contains($html,'Удалить участника') && !str_contains($html,'Восстановить участника'));
$d=$dkp;
deny(fn()=>$d->perform(2,2,k(),'restore',['member'=>'10']));deny(fn()=>$d->perform(3,1,k(),'restore',['member'=>'10']));
$key=k();check($d->perform(1,1,$key,'restore',['member'=>'10']));check(!$d->perform(1,1,$key,'restore',['member'=>'10']));
check(count($d->roster())===$expectedRoster && $d->archivedRoster()===[]);check($d->balance('10')===$balance);check($d->event($event)['members']===[]);
check($d->perform(3,1,k(),'evt_join',['event'=>$event]));
deny(fn()=>$d->perform(1,1,k(),'restore',['member'=>'10']));
echo ($n-$beforeCount)." removal checks passed; $n total\n";
