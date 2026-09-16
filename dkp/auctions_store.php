<?php
declare(strict_types=1);
trait DKPAuctions {
    public function now():int {return $this->clock ? (int)($this->clock)() : time();}
    public function reserved(string $id,string $exclude='0'):int {
        return (int)$this->a->query("SELECT COALESCE(SUM(highest_bid),0) FROM fc_auctions WHERE status='open' AND highest_member=? AND id<>?",[$id,$exclude])->fetchColumn();
    }
    public function wallet(string $id):array {
        $own=!$this->a->db->inTransaction();if($own)$this->a->db->beginTransaction();
        try {$total=$this->balance($id);$reserved=$this->reserved($id);if($own)$this->a->db->commit();return ['total'=>$total,'reserved'=>$reserved,'available'=>$total-$reserved];}
        catch(Throwable $e){if($own && $this->a->db->inTransaction())$this->a->db->rollBack();throw $e;}
    }
    public function auctions(int $page=1):array {
        $offset=(max(1,min($page,100000))-1)*20;
        return $this->a->query("SELECT a.*,r.nickname AS leader FROM fc_auctions a LEFT JOIN fc_roster r ON r.member_id=a.highest_member ORDER BY a.id DESC LIMIT 21 OFFSET $offset")->fetchAll();
    }
    public function auction(string $id,int $page=1):array {
        $id=self::id($id);$offset=(max(1,min($page,100000))-1)*20;$own=!$this->a->db->inTransaction();if($own)$this->a->db->beginTransaction();
        try {
            $lot=$this->a->query('SELECT a.*,r.nickname AS leader FROM fc_auctions a LEFT JOIN fc_roster r ON r.member_id=a.highest_member WHERE a.id=?',[$id])->fetch();
            if(!$lot)throw new AuthError('Аукцион не найден.');
            $lot['bids']=$this->a->query("SELECT b.*,r.nickname FROM fc_bids b JOIN fc_roster r ON r.member_id=b.member_id WHERE auction_id=? ORDER BY b.id DESC LIMIT 21 OFFSET $offset",[$id])->fetchAll();
            if($own)$this->a->db->commit();return $lot;
        }catch(Throwable $e){if($own && $this->a->db->inTransaction())$this->a->db->rollBack();throw $e;}
    }
    // Same mutex as manual adjustments and bids. Entire settlement is atomic.
    // Expired reserves remain held until their matching debit commits.
    public function settleDue():int {
        if(!$this->a->query("SELECT id FROM fc_auctions WHERE status='open' AND ends_at<=? LIMIT 1",[$this->now()])->fetch())return 0;
        $db=$this->a->db;$db->beginTransaction();
        try {
            if($this->a->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1)throw new RuntimeException('Missing lock');
            $now=$this->now();$due=$this->a->query("SELECT * FROM fc_auctions WHERE status='open' AND ends_at<=? ORDER BY id LIMIT 100",[$now])->fetchAll();
            foreach($due as $lot) {
                $id=(string)$lot['id'];$key='sys:'.substr(hash('sha256','auction-close:'.$id),0,60);
                $details=['auction_id'=>$id,'item'=>$lot['item'],'winner'=>$lot['highest_member'],'amount'=>(int)$lot['highest_bid'],'automatic'=>true];
                $this->a->query('INSERT INTO fc_operations VALUES(?,?,?,?,?,?)',[$key,$lot['creator_id'],'auc_close',hash('sha256',json_encode($details)),json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
                if($lot['highest_member']!==null) {
                    if((int)$lot['highest_bid']<1 || $this->balance((string)$lot['highest_member'])<$this->reserved((string)$lot['highest_member']))throw new RuntimeException('Inconsistent auction reserve');
                    $this->a->query('INSERT INTO fc_points(member_id,amount,reason,actor_id,request_key,created_at) VALUES(?,?,?,?,?,?)',[$lot['highest_member'],-(int)$lot['highest_bid'],'Победа в аукционе #'.$id.': '.$lot['item'],$lot['creator_id'],$key,$now]);
                }
                $this->a->query("UPDATE fc_auctions SET status='closed',closed_at=?,revision=revision+1 WHERE id=?",[$now,$id]);
            }
            $db->commit();return count($due);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private function auctionChange(int $actor,string $kind,array $p,string $key):array {
        $now=$this->now();
        if($kind==='auc_create') {
            $item=self::label($p['item']??'',160);
            $minimum=filter_var($p['minimum']??'',FILTER_VALIDATE_INT);$step=filter_var($p['step']??'',FILTER_VALIDATE_INT);$minutes=filter_var($p['minutes']??'',FILTER_VALIDATE_INT);
            if($minimum===false || $minimum<1 || $minimum>1000000 || $step===false || $step<1 || $step>1000000)throw new AuthError('Минимальная ставка и шаг: целые числа от 1 до 1000000.');
            if($minutes===false || $minutes<1 || $minutes>10080)throw new AuthError('Длительность: от 1 до 10080 минут (7 суток).');
            if((int)$this->a->query("SELECT COUNT(*) FROM fc_auctions WHERE status='open'")->fetchColumn()>=100)throw new AuthError('Уже открыто 100 аукционов. Дождись завершения.');
            $this->a->query('INSERT INTO fc_auctions(item,minimum,bid_step,ends_at,creator_id,created_at) VALUES(?,?,?,?,?,?)',[$item,$minimum,$step,$now+$minutes*60,$actor,$now]);
            return ['auction_id'=>$this->a->db->lastInsertId(),'item'=>$item,'minimum'=>$minimum,'step'=>$step,'ends_at'=>$now+$minutes*60];
        }
        $id=self::id($p['auction']??'');$lot=$this->a->query('SELECT * FROM fc_auctions WHERE id=?',[$id])->fetch();
        if(!$lot)throw new AuthError('Аукцион не найден.');
        if($lot['status']!=='open' || (int)$lot['ends_at']<=$now)throw new AuthError('Аукцион уже завершён или время ставок истекло. Обнови страницу.');
        if((string)$lot['revision']!==($p['revision']??''))throw new AuthError('Аукцион изменился. Обнови страницу и проверь текущую ставку.');
        if($kind==='auc_cancel') {
            if($lot['highest_member']!==null)throw new AuthError('Аукцион со ставками отменить нельзя. Он завершится по времени.');
            $reason=self::label($p['reason']??'',500);
            $this->a->query("UPDATE fc_auctions SET status='cancelled',closed_at=?,cancel_reason=?,revision=revision+1 WHERE id=?",[$now,$reason,$id]);
            return ['auction_id'=>$id,'item'=>$lot['item'],'reason'=>$reason];
        }
        if($kind!=='auc_bid')throw new AuthError('Неизвестное действие аукциона.');
        $link=$this->a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$actor])->fetch();
        if(!$link)throw new AuthError('Сначала попроси главу гильдии привязать аккаунт к участнику.');
        $member=(string)$link['member_id'];$amount=filter_var($p['amount']??'',FILTER_VALIDATE_INT);
        $needed=$lot['highest_member']===null?(int)$lot['minimum']:(int)$lot['highest_bid']+(int)$lot['bid_step'];
        if($amount===false || $amount<$needed || $amount>1000000)throw new AuthError('Ставка должна быть не ниже '.$needed.' ДКП и не выше 1000000.');
        $available=$this->balance($member)-$this->reserved($member,$id);
        if($amount>$available)throw new AuthError('Недостаточно свободных ДКП. С учётом других аукционов доступно '.$available.'.');
        $ends=max((int)$lot['ends_at'],$now+120);
        $this->a->query('UPDATE fc_auctions SET highest_member=?,highest_bid=?,ends_at=?,revision=revision+1 WHERE id=?',[$member,$amount,$ends,$id]);
        $this->a->query('INSERT INTO fc_bids(auction_id,member_id,amount,created_at,request_key) VALUES(?,?,?,?,?)',[$id,$member,$amount,$now,$key]);
        return ['auction_id'=>$id,'item'=>$lot['item'],'member'=>$member,'amount'=>$amount,'ends_at'=>$ends];
    }
}
