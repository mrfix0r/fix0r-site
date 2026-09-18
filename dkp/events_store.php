<?php
declare(strict_types=1);
trait DKPEvents {
    public function nextEvent():array|false {
        return $this->a->query("SELECT id,title,category,points,scheduled_at FROM fc_events WHERE status='open' AND scheduled_at>=? ORDER BY scheduled_at ASC,id ASC LIMIT 1",[time()])->fetch();
    }
    public function events(int $page=1):array {
        $offset=(max(1,min($page,100000))-1)*20;
        return $this->a->query("SELECT e.*,(SELECT COUNT(*) FROM fc_event_members WHERE event_id=e.id) AS attendees FROM fc_events e ORDER BY e.id DESC LIMIT 21 OFFSET $offset")->fetchAll();
    }
    public function event(string $id):array {
        $id=self::id($id);$own=!$this->a->db->inTransaction();
        if($own)$this->a->db->beginTransaction();
        try {
            $event=$this->a->query('SELECT * FROM fc_events WHERE id=?',[$id])->fetch();
            if(!$event)throw new AuthError('Событие не найдено.');
            $event['members']=$this->a->query('SELECT r.member_id,r.nickname FROM fc_event_members m JOIN fc_roster r ON r.member_id=m.member_id WHERE m.event_id=? ORDER BY r.member_id',[$id])->fetchAll();
            if($own)$this->a->db->commit();return $event;
        }catch(Throwable $e){if($own && $this->a->db->inTransaction())$this->a->db->rollBack();throw $e;}
    }
    private function eventFields(array $p):array {
        $title=self::label($p['title']??'',160);$category=$p['category']??'';
        if(!in_array($category,['cw','pits','gvg','other'],true))throw new AuthError('Выбери тип события.');
        $points=filter_var($p['points']??'',FILTER_VALIDATE_INT);
        if($points===false || $points<1 || $points>1000000)throw new AuthError('Награда: целое число от 1 до 1000000 ДКП каждому.');
        $date=$p['scheduled']??'';
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('Europe/Moscow'));
        if(!$dt || $dt->format('Y-m-d\TH:i')!==$date || $dt->format('Y')<'2000' || $dt->format('Y')>'2100')throw new AuthError('Укажи дату и время события по Москве.');
        return [$title,$category,$points,$dt->getTimestamp()];
    }
    // Called exclusively inside perform(), after its write lock and permission check.
    private function eventChange(int $actor,string $kind,array $p):array {
        if($kind==='evt_create') {
            [$title,$category,$points,$scheduled]=$this->eventFields($p);
            $this->a->query('INSERT INTO fc_events(title,category,points,scheduled_at,creator_id,created_at) VALUES(?,?,?,?,?,?)',[$title,$category,$points,$scheduled,$actor,time()]);
            return ['event_id'=>$this->a->db->lastInsertId(),'title'=>$title,'points'=>$points,'scheduled_at'=>$scheduled];
        }
        $e=$this->event($p['event']??'');$id=(string)$e['id'];
        if($e['status']!=='open')throw new AuthError($e['status']==='awarded'?'Награда уже выдана. Повторного начисления нет.':'Событие отменено.');
        if(in_array($kind,['evt_join','evt_leave'],true)) {
            $link=$this->a->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$actor])->fetch();
            if(!$link)throw new AuthError('Попроси главу гильдии привязать аккаунт к твоему игровому профилю.');
            $member=(string)$link['member_id'];$this->requireActive($member);
            $joined=false;foreach($e['members'] as $row)if((string)$row['member_id']===$member)$joined=true;
            if($kind==='evt_join') {
                if($joined)throw new AuthError('Ты уже в списке участников. Повторная отметка не нужна.');
                if(count($e['members'])>=100)throw new AuthError('В событии уже 100 участников. Обратись к офицеру.');
                $this->a->query('INSERT INTO fc_event_members(event_id,member_id) VALUES(?,?)',[$id,$member]);
            } else {
                if(!$joined)throw new AuthError('Тебя уже нет в списке участников.');
                $this->a->query('DELETE FROM fc_event_members WHERE event_id=? AND member_id=?',[$id,$member]);
            }
            $this->a->query('UPDATE fc_events SET revision=revision+1 WHERE id=?',[$id]);
            $name=$this->a->query('SELECT nickname FROM fc_roster WHERE member_id=?',[$member])->fetchColumn();
            return ['event_id'=>$id,'title'=>$e['title'],'member_id'=>$member,'nickname'=>$name,'revision'=>(int)$e['revision']+1];
        }
        if((string)$e['revision']!==($p['revision']??''))throw new AuthError('Событие или список участников изменились. Обнови страницу и проверь состав и награду.');
        if($kind==='evt_save') {
            [$title,$category,$points,$scheduled]=$this->eventFields($p);
            $ids=$p['members']??[];
            if(!is_array($ids) || count($ids)>100)throw new AuthError('Можно выбрать до 100 участников.');
            $ids=array_values(array_unique(array_map(fn($v)=>self::id((string)$v),$ids)));sort($ids,SORT_STRING);$names=[];
            foreach($ids as $member) {
                $this->requireActive($member);
                $name=$this->a->query('SELECT nickname FROM fc_roster WHERE member_id=?',[$member])->fetchColumn();
                if($name===false)throw new AuthError('Участник не найден. Обнови состав.');$names[$member]=$name;
            }
            $this->a->query('DELETE FROM fc_event_members WHERE event_id=?',[$id]);
            foreach($ids as $member)$this->a->query('INSERT INTO fc_event_members VALUES(?,?)',[$id,$member]);
            $this->a->query('UPDATE fc_events SET title=?,category=?,points=?,scheduled_at=?,revision=revision+1 WHERE id=?',[$title,$category,$points,$scheduled,$id]);
            return ['event_id'=>$id,'title'=>$title,'points'=>$points,'members'=>$names,'scheduled_at'=>$scheduled,'revision'=>(int)$e['revision']+1];
        }
        if($kind==='evt_award') {
            if(!$e['members'])throw new AuthError('Сначала сохрани список участников. Пустому событию нельзя выдать награду.');
            if(count($e['members'])>100)throw new AuthError('Слишком много участников.');
            $names=[];foreach($e['members'] as $member){$this->requireActive((string)$member['member_id']);$names[(string)$member['member_id']]=$member['nickname'];}
            $this->a->query("UPDATE fc_events SET status='awarded',closed_at=?,closed_by=?,revision=revision+1 WHERE id=?",[time(),$actor,$id]);
            return ['event_id'=>$id,'title'=>$e['title'],'amount'=>(int)$e['points'],'members'=>$names,'reason'=>'Событие #'.$id.': '.$e['title']];
        }
        if($kind==='evt_cancel') {
            $reason=self::label($p['reason']??'',500);
            $this->a->query("UPDATE fc_events SET status='cancelled',closed_at=?,closed_by=?,revision=revision+1 WHERE id=?",[time(),$actor,$id]);
            return ['event_id'=>$id,'title'=>$e['title'],'reason'=>$reason];
        }
        throw new AuthError('Неизвестное действие события.');
    }
}
