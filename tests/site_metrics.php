<?php
declare(strict_types=1);
require __DIR__.'/../dkp/metrics_store.php';
$db=new PDO('sqlite::memory:');
$db->exec('CREATE TABLE fc_metrics_totals(day TEXT,kind TEXT,item TEXT,area TEXT,lang TEXT,total INTEGER,PRIMARY KEY(day,kind,item,area,lang));
CREATE TABLE fc_metrics_visitors(day TEXT,visitor_hash TEXT,PRIMARY KEY(day,visitor_hash));
CREATE TABLE fc_metrics_receipts(id TEXT PRIMARY KEY,created_at INTEGER);');
$store=new SiteMetrics($db);$n=0;
function check(bool $value,string $why):void{global $n;if(!$value)throw new RuntimeException($why);$n++;}
function denied(Closure $call):void{try{$call();}catch(InvalidArgumentException|RuntimeException $e){check(true,'denied');return;}throw new RuntimeException('Expected denial');}
function event(string $id,string $kind='view',string $item='home',string $area='page',string $lang='ru'):array{return compact('id','kind','item','area','lang');}
$admin=['role'=>'admin','verified_at'=>1];$now=strtotime('2026-09-28 20:59:00 UTC');$a=str_repeat('a',32);$b=str_repeat('b',32);
$batch=['visitor'=>$a,'events'=>[event(str_repeat('1',32))]];
check($store->collect($batch,$now)===1,'first view');
check($store->collect($batch,$now)===0,'network retry deduplicated');
check($store->collect($batch,$now+120)===0,'retry across Moscow midnight deduplicated');
$r=$store->report($admin,7,$now);check($r['totals']['view']===1&&$r['visitors']===1,'view and visitor');
check($r['daily']['2026-09-28']['view']===1,'Moscow day before midnight');
$click=event(str_repeat('2',32),'click','out.telegram','announcement');
check($store->collect(['visitor'=>$a,'events'=>[$click,$click]],$now)===1,'duplicate IDs in batch');
$store->collect(['visitor'=>$b,'events'=>[event(str_repeat('3',32),'click','out.telegram','about','en')]],$now);
$store->collect(['visitor'=>$a,'events'=>[event(str_repeat('4',32))]],$now+120);
$r=$store->report($admin,7,$now+120);
check($r['visitors']===2,'unique browsers across days');
check($r['totals']['view']===2&&$r['totals']['click']===2,'separate event totals');
check($r['daily']['2026-09-29']['view']===1,'Moscow day after midnight');
check(count(array_filter($r['items'],fn($v)=>$v['kind']==='click'))===2,'areas and languages stay separate');
foreach([null,['role'=>'member','verified_at'=>1],['role'=>'officer','verified_at'=>1],['role'=>'admin','verified_at'=>null]] as $u)denied(fn()=>$store->report($u,7,$now));
denied(fn()=>$store->report($admin,365,$now));
foreach([
 ['visitor'=>$a,'events'=>[]],
 ['visitor'=>$a,'events'=>array_fill(0,21,$click)],
 ['visitor'=>'email@example.test','events'=>[$click]],
 ['visitor'=>$a,'events'=>[$click],'url'=>'https://example.test/?token=secret'],
 ['visitor'=>$a,'events'=>[array_merge($click,['item'=>'https://example.test/?token=secret'])]],
 ['visitor'=>$a,'events'=>[array_merge($click,['email'=>'private@example.test'])]],
 ['visitor'=>$a,'events'=>[array_merge($click,['area'=>['header']])]],
 ['visitor'=>$a,'events'=>[array_merge($click,['lang'=>'xx'])]],
 ['visitor'=>$a,'events'=>[array_merge($click,['id'=>'not-a-random-id'])]],
 ['visitor'=>$a,'events'=>[event(str_repeat('5',32),'view','dkp.reset')]],
] as $bad)denied(fn()=>$store->collect($bad,$now));
$bad=['visitor'=>$a,'events'=>[event(str_repeat('6',32)),array_merge($click,['item'=>'unknown'])]];
denied(fn()=>$store->collect($bad,$now));
check((int)$db->query('SELECT COUNT(*) FROM fc_metrics_receipts')->fetchColumn()===4,'invalid batch writes nothing');
$db->exec("CREATE TRIGGER fail_metric BEFORE INSERT ON fc_metrics_totals BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
$retry=['visitor'=>$a,'events'=>[event(str_repeat('7',32))]];
denied(fn()=>$store->collect($retry,$now));
check((int)$db->query('SELECT COUNT(*) FROM fc_metrics_receipts')->fetchColumn()===4,'failed write rolls back receipt');
$db->exec('DROP TRIGGER fail_metric');check($store->collect($retry,$now)===1,'retry after rollback works');
check($db->query('SELECT visitor_hash FROM fc_metrics_visitors LIMIT 1')->fetchColumn()!==$a,'raw visitor ID not stored');
$store->collect(['visitor'=>$a,'events'=>[event(str_repeat('8',32))]],$now-100*86400);
$store->cleanup($now+120);
check((int)$db->query("SELECT COUNT(*) FROM fc_metrics_totals WHERE day<'2026-07-02'")->fetchColumn()===0,'old totals pruned');
check((int)$db->query('SELECT COUNT(*) FROM fc_metrics_receipts')->fetchColumn()===5,'old receipts pruned independently');
check(count($store->report($admin,7,$now+120)['daily'])===7,'empty days filled');
echo "$n site metrics checks passed\n";
