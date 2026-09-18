<?php
require __DIR__.'/../dkp/events_store.php';
final class EventFixture {
    use DKPEvents;
    public function __construct(private object $a) {}
}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE fc_events(id INTEGER PRIMARY KEY,title TEXT,category TEXT,points INTEGER,scheduled_at INTEGER,status TEXT)');
$a=new class($db){public function __construct(public PDO $db){}public function query($s,$p=[]){$q=$this->db->prepare($s);$q->execute($p);return $q;}};
$d=new EventFixture($a);if($d->nextEvent()!==false)throw new Exception('Expected empty table');
$now=time();foreach([[1,'Old','open',-60],[2,'Cancelled','cancelled',10],[3,'Paid','awarded',20],[4,'Later','open',7200],[5,'Next','open',3600],[6,'Tie','open',3600]] as [$id,$title,$status,$delta])$a->query('INSERT INTO fc_events VALUES(?,?,?,5,?,?)',[$id,$title,'cw',$now+$delta,$status]);
if($d->nextEvent()['id']!==5)throw new Exception('Expected earliest future open event, stable by id');
$db->exec("UPDATE fc_events SET status='cancelled' WHERE id=5");if($d->nextEvent()['id']!==6)throw new Exception('Expected next open event');
$db->exec("UPDATE fc_events SET status='awarded' WHERE status='open'");if($d->nextEvent()!==false)throw new Exception('Expected empty state');
echo "Next event: chronological selection, tie, closed/past exclusion and empty state OK\n";
