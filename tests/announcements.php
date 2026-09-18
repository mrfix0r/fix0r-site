<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';require __DIR__.'/../dkp/dkp_store.php';require __DIR__.'/../dkp/announcements_store.php';
$db=new PDO('sqlite::memory:');$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE dkp_users(id INTEGER PRIMARY KEY,email TEXT,nickname TEXT,role TEXT,verified_at INTEGER,session_version INTEGER);
INSERT INTO dkp_users VALUES(1,'admin@example.test','Admin','admin',1,1),(2,'officer@example.test','Officer','officer',1,1),(3,'one@example.test','One','member',1,1),(4,'free@example.test','Free','member',1,1),(5,'two@example.test','Two','member',1,1);
CREATE TABLE fc_roster(member_id INTEGER PRIMARY KEY,nickname TEXT);INSERT INTO fc_roster VALUES(10,'One'),(11,'Two'),(12,'No account');
CREATE TABLE fc_web_links(web_user_id INTEGER PRIMARY KEY,member_id INTEGER UNIQUE);INSERT INTO fc_web_links VALUES(3,10),(5,11);
CREATE TABLE fc_member_archive(member_id INTEGER PRIMARY KEY);
CREATE TABLE fc_write_lock(id INTEGER PRIMARY KEY,revision INTEGER);INSERT INTO fc_write_lock VALUES(1,0);
CREATE TABLE fc_announcements(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,body TEXT,author_id INTEGER REFERENCES dkp_users(id),created_at INTEGER,updated_at INTEGER,expires_at INTEGER,pinned INTEGER DEFAULT 0,requires_ack INTEGER DEFAULT 0,ack_version INTEGER DEFAULT 1,revision INTEGER DEFAULT 1,archived INTEGER DEFAULT 0);
CREATE TABLE fc_announcement_receipts(announcement_id INTEGER REFERENCES fc_announcements(id),ack_version INTEGER,member_id INTEGER REFERENCES fc_roster(member_id),web_user_id INTEGER REFERENCES dkp_users(id),read_at INTEGER,PRIMARY KEY(announcement_id,ack_version,member_id));
CREATE TABLE fc_announcement_actions(request_key TEXT PRIMARY KEY,actor_id INTEGER REFERENCES dkp_users(id),announcement_id INTEGER REFERENCES fc_announcements(id),kind TEXT,payload_hash TEXT,details TEXT,created_at INTEGER);");
$auth=new Auth($db,'https://example.test',fn()=>true);$now=time();$ann=new Announcements($auth,function()use(&$now){return $now;});
$n=0;function ok($b){global $n;if(!$b)throw new Exception('Check '.($n+1));$n++;}function keyId(){return bin2hex(random_bytes(32));}function deny($fn){try{$fn();}catch(AuthError){ok(true);return;}throw new Exception('Expected denial');}
$admin=$auth->user(1);$officer=$auth->user(2);$member=$auth->user(3);$free=$auth->user(4);
$p=['title'=>'Guild <notice>','body'=>"First line\nSecond line <script>",'pinned'=>'1','requires_ack'=>'1','expires'=>''];
ok($ann->feed($member)===[]);ok($ann->pinned($free)===false);deny(fn()=>$ann->feed($free));
foreach([2,3,4] as $uid)deny(fn()=>$ann->perform($uid,1,keyId(),'create',$p));
deny(fn()=>$ann->perform(1,0,keyId(),'create',$p));
$key=keyId();$id=$ann->perform(1,1,$key,'create',$p);ok($ann->perform(1,1,$key,'create',$p)===$id);ok(count($ann->feed($member))===1);
deny(fn()=>$ann->perform(1,1,$key,'create',array_replace($p,['title'=>'Other'])));
$row=$ann->get($member,$id);ok($ann->pinned($member)['id']==$id);ok($row['body']===$p['body']);
$ack=['id'=>$id,'revision'=>(string)$row['revision']];
deny(fn()=>$ann->perform(4,1,keyId(),'ack',$ack));deny(fn()=>$ann->perform(2,1,keyId(),'ack',$ack));
$k=keyId();$ann->perform(3,1,$k,'ack',$ack);$ann->perform(3,1,$k,'ack',$ack);$ann->perform(3,1,keyId(),'ack',$ack);
ok($auth->query('SELECT COUNT(*) FROM fc_announcement_receipts')->fetchColumn()===1);ok($ann->acknowledged($row,3));
$readers=$ann->readers($admin,$row);ok(count($readers)===3);ok(count(array_filter($readers,fn($r)=>$r['read_at']!==null))===1);deny(fn()=>$ann->readers($officer,$row));
$edit=$p+$ack+['reset_ack'=>'0'];$ann->perform(1,1,keyId(),'edit',$edit);$row=$ann->get($admin,$id);ok($ann->acknowledged($row,3));
deny(fn()=>$ann->perform(5,1,keyId(),'ack',$ack));deny(fn()=>$ann->perform(1,1,keyId(),'edit',$edit));
$edit['revision']=(string)$row['revision'];$edit['reset_ack']='1';$ann->perform(1,1,keyId(),'edit',$edit);$row=$ann->get($admin,$id);ok(!$ann->acknowledged($row,3));ok($auth->query('SELECT COUNT(*) FROM fc_announcement_receipts')->fetchColumn()===1);
$ann->perform(3,1,keyId(),'ack',['id'=>$id,'revision'=>(string)$row['revision']]);ok($ann->acknowledged($row,3));
$auth->query('INSERT INTO fc_member_archive VALUES(10)');deny(fn()=>$ann->feed($member));deny(fn()=>$ann->perform(3,1,keyId(),'ack',['id'=>$id,'revision'=>(string)$row['revision']]));ok(count($ann->readers($admin,$row))===2);$auth->query('DELETE FROM fc_member_archive');
// Pinning a new publication invalidates stale edit forms and keeps only one pin.
$second=$ann->perform(1,1,keyId(),'create',array_replace($p,['title'=>'Second']));ok((string)$ann->pinned($member)['id']===$second);ok((int)$ann->get($admin,$id)['pinned']===0);
$row2=$ann->get($admin,$second);deny(fn()=>$ann->perform(3,1,keyId(),'archive',['id'=>$second,'revision'=>(string)$row2['revision']]));
$ann->perform(1,1,keyId(),'archive',['id'=>$second,'revision'=>(string)$row2['revision']]);deny(fn()=>$ann->get($member,$second));ok((int)$ann->get($admin,$second)['archived']===1);ok($ann->pinned($member)===false);
// Expiry hides both banner and detail at the exact boundary without Cron.
$expiry=(new DateTimeImmutable('@'.($now+3600)))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i');
$exp=$ann->perform(1,1,keyId(),'create',array_replace($p,['expires'=>$expiry]));$expRow=$ann->get($member,$exp);$now=(int)$expRow['expires_at'];deny(fn()=>$ann->get($member,$exp));deny(fn()=>$ann->perform(3,1,keyId(),'ack',['id'=>$exp,'revision'=>'1']));ok($ann->pinned($member)===false);
foreach([['body'=>''],['body'=>str_repeat('x',2001)],['title'=>''],['expires'=>'2026-02-30T12:00']] as $invalid)deny(fn()=>$ann->perform(1,1,keyId(),'create',array_replace($p,$invalid)));
// Failure to audit must roll back publication, pin replacement and receipt.
$db->exec("CREATE TRIGGER fail_ann BEFORE INSERT ON fc_announcement_actions BEGIN SELECT RAISE(ABORT,'fixture'); END");$count=$auth->query('SELECT COUNT(*) FROM fc_announcements')->fetchColumn();
try{$ann->perform(1,1,keyId(),'create',$p);throw new Exception('Expected DB failure');}catch(PDOException){ok(true);}ok($auth->query('SELECT COUNT(*) FROM fc_announcements')->fetchColumn()===$count);
$db->exec('DROP TRIGGER fail_ann');
$active=$ann->perform(1,1,keyId(),'create',$p);$db->exec("CREATE TRIGGER fail_ack BEFORE INSERT ON fc_announcement_actions WHEN NEW.kind='ack' BEGIN SELECT RAISE(ABORT,'fixture'); END");
try{$ann->perform(5,1,keyId(),'ack',['id'=>$active,'revision'=>'1']);throw new Exception('Expected DB failure');}catch(PDOException){ok(true);}ok(!$ann->acknowledged($ann->get($admin,$active),5));$db->exec('DROP TRIGGER fail_ack');
function h($v){return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function formStart($v){echo '<form method="post"><input name="action" value="'.h($v).'">';}
$user=$admin;$announcements=$ann;$_GET=['announcement'=>$active];ob_start();include __DIR__.'/../dkp/announcements_view.php';$html=ob_get_clean();ok(!str_contains($html,'<script>'));ok(str_contains($html,'Прочитали 0 из 3'));ok(str_contains($html,'Редактировать объявление'));
$user=$member;ob_start();include __DIR__.'/../dkp/announcement_banner.php';$html=ob_get_clean();ok(str_contains($html,'Важное объявление'));ok(!str_contains($html,'<script>'));
echo "$n announcement checks passed\n";

if(getenv('ANN_HTTP_FIXTURE')) {
    $auth->query('ALTER TABLE dkp_users ADD COLUMN password_hash TEXT');
    $auth->query('UPDATE dkp_users SET password_hash=?',[Auth::hashPassword('local-fixture-password')]);
    $db->exec('CREATE TABLE dkp_limits(bucket TEXT PRIMARY KEY,hits INTEGER,expires_at INTEGER);CREATE TABLE fc_auctions(id INTEGER PRIMARY KEY,status TEXT,ends_at INTEGER);');
    $auth->query('VACUUM INTO ?',[getenv('ANN_HTTP_FIXTURE')]);
}
