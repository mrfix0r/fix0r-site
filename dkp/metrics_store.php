<?php
declare(strict_types=1);
require_once __DIR__.'/metrics_catalog.php';

final class SiteMetrics {
    private bool $sqlite;
    public function __construct(private PDO $db) {
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
        $this->sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    }
    private function query(string $sql,array $args=[]): PDOStatement {
        $q=$this->db->prepare($sql);$q->execute($args);return $q;
    }
    private static function day(int $now): string {
        return (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
    }
    public function collect(mixed $input,?int $now=null): int {
        $batch=fc_metrics_batch($input);$now??=time();$day=self::day($now);
        $visitor=hash('sha256',$batch['visitor']);$accepted=0;
        $upsert=$this->sqlite
            ? ' ON CONFLICT(day,kind,item,area,lang) DO UPDATE SET total=total+1'
            : ' ON DUPLICATE KEY UPDATE total=total+1';
        $this->db->beginTransaction();
        try {
            foreach ($batch['events'] as $event) {
                try {
                    $this->query('INSERT INTO fc_metrics_receipts(id,created_at) VALUES(?,?)',[hash('sha256',$visitor.':'.$event['id']),$now]);
                } catch (PDOException $e) {
                    if (!in_array((string)$e->getCode(),['23000','23505'],true)) throw $e;
                    continue; // A retried request never increments the same event twice.
                }
                $this->query('INSERT INTO fc_metrics_totals(day,kind,item,area,lang,total) VALUES(?,?,?,?,?,1)'.$upsert,
                    [$day,$event['kind'],$event['item'],$event['area'],$event['lang']]);
                $accepted++;
            }
            if ($accepted) {
                $ignore=$this->sqlite ? ' ON CONFLICT(day,visitor_hash) DO NOTHING' : ' ON DUPLICATE KEY UPDATE visitor_hash=VALUES(visitor_hash)';
                $this->query('INSERT INTO fc_metrics_visitors(day,visitor_hash) VALUES(?,?)'.$ignore,[$day,$visitor]);
            }
            $this->db->commit();return $accepted;
        } catch (Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function cleanup(?int $now=null): void {
        $now??=time();$cutoff=self::day($now-89*86400);
        $this->query('DELETE FROM fc_metrics_totals WHERE day<?',[$cutoff]);
        $this->query('DELETE FROM fc_metrics_visitors WHERE day<?',[$cutoff]);
        $this->query('DELETE FROM fc_metrics_receipts WHERE created_at<?',[$now-7*86400]);
    }
    public function report(mixed $user,int $days,?int $now=null): array {
        if(!fc_metrics_admin($user))throw new RuntimeException('Forbidden');
        if(!in_array($days,[1,7,30,90],true))throw new InvalidArgumentException('Invalid period');
        $now??=time();$to=self::day($now);$from=self::day($now-($days-1)*86400);
        $params=[$from,$to];
        $totals=['view'=>0,'click'=>0];
        foreach($this->query('SELECT kind,SUM(total) AS total FROM fc_metrics_totals WHERE day BETWEEN ? AND ? GROUP BY kind',$params) as $r)$totals[$r['kind']]=(int)$r['total'];
        $visitors=(int)$this->query('SELECT COUNT(DISTINCT visitor_hash) FROM fc_metrics_visitors WHERE day BETWEEN ? AND ?',$params)->fetchColumn();
        $daily=[];
        for($i=$days-1;$i>=0;$i--)$daily[self::day($now-$i*86400)]=['view'=>0,'click'=>0,'visitors'=>0];
        foreach($this->query('SELECT day,kind,SUM(total) AS total FROM fc_metrics_totals WHERE day BETWEEN ? AND ? GROUP BY day,kind',$params) as $r)$daily[$r['day']][$r['kind']]=(int)$r['total'];
        foreach($this->query('SELECT day,COUNT(*) AS total FROM fc_metrics_visitors WHERE day BETWEEN ? AND ? GROUP BY day',$params) as $r)$daily[$r['day']]['visitors']=(int)$r['total'];
        $items=$this->query('SELECT kind,item,area,lang,SUM(total) AS total FROM fc_metrics_totals WHERE day BETWEEN ? AND ? GROUP BY kind,item,area,lang ORDER BY total DESC,item,area,lang',$params)->fetchAll();
        $last=$this->query('SELECT MAX(created_at) FROM fc_metrics_receipts')->fetchColumn();
        return compact('from','to','totals','visitors','daily','items','last');
    }
}
