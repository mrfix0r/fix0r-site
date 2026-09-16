<?php
declare(strict_types=1);
require __DIR__.'/../dkp/auth.php';
$db=new PDO('sqlite::memory:');$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE dkp_users(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT UNIQUE,nickname TEXT,password_hash TEXT,created_at INTEGER,role TEXT DEFAULT 'member',verified_at INTEGER,session_version INTEGER DEFAULT 1);
CREATE TABLE dkp_tokens(token_hash TEXT PRIMARY KEY,user_id INTEGER REFERENCES dkp_users(id),purpose TEXT,expires_at INTEGER);
CREATE TABLE fc_registration_members(web_user_id INTEGER PRIMARY KEY REFERENCES dkp_users(id),nickname TEXT,status TEXT);
CREATE TABLE fc_roster(member_id INTEGER PRIMARY KEY AUTOINCREMENT,nickname TEXT COLLATE NOCASE,created_at INTEGER);
CREATE TABLE fc_web_links(web_user_id INTEGER PRIMARY KEY REFERENCES dkp_users(id),member_id INTEGER UNIQUE REFERENCES fc_roster(member_id),linked_at INTEGER);
CREATE TABLE fc_operations(request_key TEXT PRIMARY KEY,actor_id INTEGER REFERENCES dkp_users(id),kind TEXT,payload_hash TEXT,details TEXT,created_at INTEGER);
CREATE TABLE fc_write_lock(id INTEGER PRIMARY KEY,revision INTEGER);INSERT INTO fc_write_lock VALUES(1,0);");
$mail=[];$a=new Auth($db,'https://example.test',function($email,$subject,$body)use(&$mail){preg_match('/token=([a-f0-9]{64})/',$body,$m);$mail[$email][]=$m[1];return true;});
$n=0;function check($v){global $n;if(!$v)throw new Exception('Check failed #'.($n+1));$n++;}
function denied($fn){try{$fn();}catch(AuthError){check(true);return;}throw new Exception('Expected denial');}
$pw='local-fixture-password';
$a->register('one@example.test','One',$pw,true);$id=(int)$a->byEmail('one@example.test')['id'];
check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===0);
check($a->query('SELECT status FROM fc_registration_members WHERE web_user_id=?',[$id])->fetchColumn()==='pending');
$token=$mail['one@example.test'][0];denied(fn()=>$a->redeem($token,'verify','wrong-password'));
$a->requestToken('one@example.test','verify');$second=$mail['one@example.test'][1];
$a->redeem($token,'verify',$pw);check($a->user($id)['role']==='member');check((bool)$a->user($id)['verified_at']);
check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===1);check($a->query('SELECT COUNT(*) FROM fc_web_links')->fetchColumn()===1);
check($a->query('SELECT COUNT(*) FROM fc_operations')->fetchColumn()===1);check($a->query('SELECT COUNT(*) FROM fc_registration_members')->fetchColumn()===0);
denied(fn()=>$a->redeem($token,'verify',$pw));denied(fn()=>$a->redeem($second,'verify',$pw));
$a->register('plain@example.test','Plain',$pw);$plain=(int)$a->byEmail('plain@example.test')['id'];
$a->register('plain@example.test','Changed',$pw,true);check($a->query('SELECT COUNT(*) FROM fc_registration_members')->fetchColumn()===0);
$a->redeem($mail['plain@example.test'][0],'verify',$pw);check(!$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$plain])->fetchColumn());
$a->register('collision@example.test','ONE',$pw,true);$collision=(int)$a->byEmail('collision@example.test')['id'];
$a->redeem($mail['collision@example.test'][0],'verify',$pw);check((bool)$a->user($collision)['verified_at']);
check($a->query('SELECT status FROM fc_registration_members WHERE web_user_id=?',[$collision])->fetchColumn()==='conflict');check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===1);
check(!$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$collision])->fetchColumn());
// Password reset also proves email ownership and must not bypass the saved intent.
$a->register('reset@example.test','Reset',$pw,true);$reset=(int)$a->byEmail('reset@example.test')['id'];
$a->requestToken('reset@example.test','reset');$rt=$mail['reset@example.test'][1];$newPw='new-local-fixture-password';
$a->redeem($rt,'reset',$newPw);check((bool)$a->login('reset@example.test',$newPw));check((bool)$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$reset])->fetchColumn());
$a->requestToken('reset@example.test','reset');$a->redeem($mail['reset@example.test'][2],'reset',$pw);check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===2);
// A failure while auditing rolls back verification, token consumption, profile and link.
$a->register('rollback@example.test','Rollback',$pw,true);$rollback=(int)$a->byEmail('rollback@example.test')['id'];$rt=$mail['rollback@example.test'][0];
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON fc_operations BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
try{$a->redeem($rt,'verify',$pw);throw new Exception('Expected database failure');}catch(PDOException){check(true);}
check(!$a->user($rollback)['verified_at']);check((bool)$a->token($rt,'verify'));check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===2);
check(!$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$rollback])->fetchColumn());
$db->exec('DROP TRIGGER fail_audit');$a->redeem($rt,'verify',$pw);check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===3);
// A failed mail delivery retains intent for a later resend.
$failed=new Auth($db,'https://example.test',fn()=>false);denied(fn()=>$failed->register('mail@example.test','Mail',$pw,true));
$a->requestToken('mail@example.test','verify');$a->redeem($mail['mail@example.test'][0],'verify',$pw);check($a->query('SELECT COUNT(*) FROM fc_roster')->fetchColumn()===4);
// Registration rolls back completely if its intent cannot be stored.
$db->exec("CREATE TRIGGER fail_intent BEFORE INSERT ON fc_registration_members BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
try{$a->register('atomic@example.test','Atomic',$pw,true);throw new Exception('Expected failure');}catch(PDOException){check(true);}
check(!$a->byEmail('atomic@example.test'));
$db->exec('DROP TRIGGER fail_intent');
// Two registrations can arrive before either email is confirmed.
$a->register('twin1@example.test','Twin',$pw,true);$a->register('twin2@example.test','Twin',$pw,true);
$a->redeem($mail['twin1@example.test'][0],'verify',$pw);$a->redeem($mail['twin2@example.test'][0],'verify',$pw);
check($a->query("SELECT COUNT(*) FROM fc_roster WHERE nickname='Twin'")->fetchColumn()===1);
$twin2=(int)$a->byEmail('twin2@example.test')['id'];
check($a->query('SELECT status FROM fc_registration_members WHERE web_user_id=?',[$twin2])->fetchColumn()==='conflict');
check(!$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$twin2])->fetchColumn());
// A later reset must preserve an administrator-created link, without another roster row.
$a->query('INSERT INTO fc_roster(nickname,created_at) VALUES(?,?)',['Manual',time()]);$manual=$db->lastInsertId();
$a->query('INSERT INTO fc_web_links VALUES(?,?,?)',[$twin2,$manual,time()]);
$a->requestToken('twin2@example.test','reset');$a->redeem($mail['twin2@example.test'][1],'reset',$pw);
check((string)$a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$twin2])->fetchColumn()===(string)$manual);
check(!$a->query('SELECT status FROM fc_registration_members WHERE web_user_id=?',[$twin2])->fetchColumn());
echo "$n registration checks passed\n";
