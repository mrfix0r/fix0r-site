<?php
require __DIR__.'/../dkp/auctions_store.php';
class AuctionFixture {use DKPAuctions;private ?Closure $clock;function __construct(private object $a){$this->clock=fn()=>1000;}}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE fc_auctions(id INTEGER PRIMARY KEY,item TEXT,minimum INTEGER,highest_bid INTEGER,highest_member INTEGER,ends_at INTEGER,status TEXT)');
$a=new class($db){function __construct(public PDO $db){}function query($s,$p=[]){$q=$this->db->prepare($s);$q->execute($p);return $q;}};
$d=new AuctionFixture($a);if($d->nextAuction()!==false)throw new Exception('empty');
foreach([[1,900,'open'],[2,1000,'open'],[3,1100,'closed'],[4,1200,'cancelled'],[5,2000,'open'],[6,1500,'open'],[7,1500,'open']] as [$id,$end,$status])$a->query('INSERT INTO fc_auctions VALUES(?,?,10,0,NULL,?,?)',[$id,'Lot '.$id,$end,$status]);
if($d->nextAuction()['id']!==6)throw new Exception('earliest time / tie / exclusions');
$a->query('UPDATE fc_auctions SET ends_at=2500,highest_bid=15,highest_member=1 WHERE id=6');
if($d->nextAuction()['id']!==7)throw new Exception('extension');
$db->exec("UPDATE fc_auctions SET status='closed'");if($d->nextAuction()!==false)throw new Exception('closed');
function h($s){return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
$user=['nickname'=>'Player','email'=>'player@example.test','role'=>'member'];
$dkp=new class {public $lot=false;function nextEvent(){return false;}function nextAuction(){return $this->lot;}};
foreach([null,1] as $leader){$dkp->lot=['id'=>8,'item'=>'<script>lot</script>','minimum'=>10,'highest_bid'=>15,'highest_member'=>$leader,'ends_at'=>2000];ob_start();include __DIR__.'/../dkp/profile_overview.php';$html=ob_get_clean();if(str_contains($html,'<script>') || !str_contains($html,'auction=8') || !str_contains($html,$leader===null?'Начальная цена':'Текущая ставка'))throw new Exception('render');}
$dkp->lot=false;ob_start();include __DIR__.'/../dkp/profile_overview.php';$html=ob_get_clean();if(!str_contains($html,'Сейчас нет открытых аукционов'))throw new Exception('empty render');
echo "Selection, expiration boundary, ties, extension, bid/no-bid display, escaping and empty state OK\n";
