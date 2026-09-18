<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';require __DIR__.'/../dkp/dkp_store.php';require __DIR__.'/../dkp/telegram_store.php';
$db=new PDO('sqlite::memory:');$db->exec('PRAGMA foreign_keys=ON');$db->exec(file_get_contents(__DIR__.'/telegram_schema.sql'));
$a=new Auth($db,'https://example.test',fn()=>true);$now=1000000;$cfg=['enabled'=>true,'bot_username'=>'GuildFixtureBot','bot_token'=>'12345:'.str_repeat('x',30)];
$tg=new TelegramGuild($a,$cfg,'https://example.test',function()use(&$now){return $now;});$n=0;
function ok($b){global $n;if(!$b)throw new Exception('Check '.($n+1));$n++;}
function denied($fn){try{$fn();}catch(AuthError){ok(true);return;}throw new Exception('Expected denial');}
function token($link){return substr($link['url'],strpos($link['url'],'dkp_')+4);}
$updateId=1;function updateFor($chat,$text,$type='private'){global $updateId;return ['update_id'=>$updateId++,'message'=>['chat'=>['id'=>$chat,'type'=>$type],'from'=>['id'=>$chat,'is_bot'=>false,'username'=>'player'],'text'=>$text]];}
function notice($id){global $a;$a->query('INSERT INTO fc_announcements(id,title,body,revision) VALUES(?,?,?,1)',[$id,'Notice '.$id,'Body']);}
function bind($uid,$chat){global $tg,$lease;$l=$tg->startLink($uid,1);$tg->update($lease,updateFor($chat,'/start dkp_'.token($l)));}
ok($tg->enabled());$disabled=new TelegramGuild($a,[],'https://example.test');denied(fn()=>$disabled->startLink(3,1));
denied(fn()=>$tg->startLink(4,1));denied(fn()=>$tg->startLink(3,0));
$link=$tg->startLink(3,1);$raw=token($link);ok(strlen('dkp_'.$raw)<=64);ok($a->query('SELECT token_hash FROM fc_tg_tokens')->fetchColumn()===hash('sha256',$raw));
$lease=$tg->acquire();ok(is_string($lease));ok($tg->acquire()===false);$tg->identify($lease,'12345');
$tg->update($lease,updateFor(101,'/start dkp_'.$raw,'group'));ok($tg->subscription(3)===false);
$u=updateFor(101,'/start dkp_'.$raw);$reply=$tg->update($lease,$u);ok(str_contains($reply['text'],'Telegram привязан'));ok((int)$tg->subscription(3)['subscribed']===1);ok($tg->update($lease,$u)===null);
$tg->update($lease,updateFor(102,'/start dkp_'.$raw));ok((int)$a->query('SELECT chat_id FROM fc_tg_subscriptions WHERE web_user_id=3')->fetchColumn()===101);
$conflict=$tg->startLink(5,1);$r=$tg->update($lease,updateFor(101,'/start dkp_'.token($conflict)));ok(str_contains($r['text'],'другому'));ok($tg->subscription(5)===false);
$expired=$tg->startLink(5,1);$now+=600;$tg->release($lease,false);$lease=$tg->acquire();$tg->update($lease,updateFor(102,'/start dkp_'.token($expired)));ok($tg->subscription(5)===false);
$old=$tg->startLink(5,1);$fresh=$tg->startLink(5,1);$tg->update($lease,updateFor(102,'/start dkp_'.token($old)));ok($tg->subscription(5)===false);$tg->update($lease,updateFor(102,'/start dkp_'.token($fresh)));ok((int)$tg->subscription(5)['subscribed']===1);
notice(1);denied(fn()=>$tg->queue(2,1,'1','1'));denied(fn()=>$tg->queue(3,1,'1','1'));denied(fn()=>$tg->queue(1,0,'1','1'));denied(fn()=>$tg->queue(1,1,'1','0'));ok($tg->queue(1,1,'1','1')===2);denied(fn()=>$tg->queue(1,1,'1','1'));ok($a->query('SELECT COUNT(*) FROM fc_tg_deliveries')->fetchColumn()===2);
$job=$tg->claim($lease);ok($job['chat_id']==101);$tg->result($lease,$job,['ok'=>true,'result'=>['message_id'=>77]]);ok($a->query('SELECT status FROM fc_tg_deliveries WHERE id=?',[$job['id']])->fetchColumn()==='sent');
$tg->update($lease,updateFor(102,'/stop'));ok((int)$tg->subscription(5)['subscribed']===0);ok($tg->claim($lease)===false);
notice(2);ok($tg->queue(1,1,'2','1')===1);$job=$tg->claim($lease);$tg->result($lease,$job,['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>30]]);ok($tg->claim($lease)===false);$now+=30;$job=$tg->claim($lease);ok($job['attempts']===2);$tg->result($lease,$job,['error_code'=>'network_unknown']);ok($a->query('SELECT status FROM fc_tg_deliveries WHERE id=?',[$job['id']])->fetchColumn()==='unknown');ok($tg->claim($lease)===false);
notice(3);$tg->queue(1,1,'3','1');$a->query('UPDATE fc_announcements SET revision=2 WHERE id=3');ok(isset($tg->claim($lease)['skipped']));
notice(4);$tg->queue(1,1,'4','1');$tg->unlink(3,1);ok($tg->claim($lease)===false);ok($tg->subscription(3)===false);
bind(3,101);notice(5);$tg->queue(1,1,'5','1');bind(3,103);ok(isset($tg->claim($lease)['skipped']));
notice(6);$tg->queue(1,1,'6','1');$a->query('INSERT INTO fc_member_archive VALUES(10)');ok(isset($tg->claim($lease)['skipped']));denied(fn()=>$tg->startLink(3,1));$a->query('DELETE FROM fc_member_archive');
notice(7);$tg->queue(1,1,'7','1');$job=$tg->claim($lease);$tg->result($lease,$job,['ok'=>false,'error_code'=>403]);ok((int)$tg->subscription(3)['subscribed']===0);notice(8);denied(fn()=>$tg->queue(1,1,'8','1'));
bind(3,103);notice(9);$tg->queue(1,1,'9','1');$job=$tg->claim($lease);$now+=121;$newLease=$tg->acquire();ok(is_string($newLease));ok($a->query('SELECT status FROM fc_tg_deliveries WHERE id=?',[$job['id']])->fetchColumn()==='unknown');$tg->release($lease,true);ok($tg->acquire()===false);$lease=$newLease;
// Session revocation invalidates pending binding, rather than subscribing a stale account.
$revoked=$tg->startLink(5,1);$a->query('UPDATE dkp_users SET session_version=2 WHERE id=5');$tg->update($lease,updateFor(104,'/start dkp_'.token($revoked)));ok((int)$tg->subscription(5)['subscribed']===0);
// Queue insertion failure must not leave a batch or partial recipient set.
notice(10);$db->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON fc_tg_deliveries BEGIN SELECT RAISE(ABORT,'fixture'); END");
try{$tg->queue(1,1,'10','1');throw new Exception('Expected failure');}catch(PDOException){ok(true);}ok($tg->stats($a->user(1),'10')===false);$db->exec('DROP TRIGGER fail_queue');
notice(11);$tg->queue(1,1,'11','1');$job=$tg->claim($lease);$tg->result($lease,$job,['ok'=>false,'error_code'=>500]);ok($tg->claim($lease)===false);$now+=60;$job=$tg->claim($lease);ok($job['attempts']===2);$tg->result($lease,$job,['ok'=>true,'result'=>['message_id'=>88]]);
$tg->release($lease,true);ok((int)$tg->state()['last_success']===$now);
echo "$n Telegram checks passed (mock updates/results; no network)\n";
