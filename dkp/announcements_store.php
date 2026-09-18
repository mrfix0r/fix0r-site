<?php
declare(strict_types=1);
final class Announcements {
    public function __construct(private Auth $a,private ?Closure $clock=null) {}
    private function now():int{return $this->clock?(int)($this->clock)():time();}
    public function member(int $account):string|false {
        $id=$this->a->query('SELECT l.member_id FROM fc_web_links l WHERE l.web_user_id=? AND NOT EXISTS (SELECT 1 FROM fc_member_archive ar WHERE ar.member_id=l.member_id)',[$account])->fetchColumn();
        return $id===false?false:(string)$id;
    }
    public function canRead(array $user):bool {
        return (bool)$user['verified_at'] && (in_array($user['role'],['admin','officer'],true) || $this->member((int)$user['id'])!==false);
    }
    private function access(array $user):void {if(!$this->canRead($user))throw new AuthError('Объявления доступны участникам гильдии. Попроси администратора привязать активный профиль ДКП.');}
    public function active(array $row):bool{return !(int)$row['archived'] && ($row['expires_at']===null || (int)$row['expires_at']>$this->now());}
    public function feed(array $user,int $page=1):array {
        $this->access($user);$offset=(max(1,min($page,100000))-1)*20;
        $where=$user['role']==='admin'?'':'WHERE n.archived=0 AND (n.expires_at IS NULL OR n.expires_at>?)';
        return $this->a->query("SELECT n.*,u.nickname AS author FROM fc_announcements n JOIN dkp_users u ON u.id=n.author_id $where ORDER BY n.id DESC LIMIT 21 OFFSET $offset",$where?[$this->now()]:[])->fetchAll();
    }
    public function pinned(array $user):array|false {
        if(!$this->canRead($user))return false;
        return $this->a->query('SELECT * FROM fc_announcements WHERE pinned=1 AND archived=0 AND (expires_at IS NULL OR expires_at>?) ORDER BY id DESC LIMIT 1',[$this->now()])->fetch();
    }
    public function get(array $user,string $id):array {
        $this->access($user);$id=DKP::id($id);
        $row=$this->a->query('SELECT n.*,u.nickname AS author FROM fc_announcements n JOIN dkp_users u ON u.id=n.author_id WHERE n.id=?',[$id])->fetch();
        if(!$row || ($user['role']!=='admin' && !$this->active($row)))throw new AuthError('Объявление не найдено или срок показа завершён.');
        return $row;
    }
    public function acknowledged(array $row,int $account):bool {
        $member=$this->member($account);if($member===false)return false;
        return (bool)$this->a->query('SELECT member_id FROM fc_announcement_receipts WHERE announcement_id=? AND ack_version=? AND member_id=?',[$row['id'],$row['ack_version'],$member])->fetchColumn();
    }
    public function readers(array $user,array $row):array {
        if($user['role']!=='admin')throw new AuthError('Доступно только администратору.');
        return $this->a->query('SELECT r.member_id,r.nickname,l.web_user_id,ack.read_at FROM fc_roster r LEFT JOIN fc_web_links l ON l.member_id=r.member_id LEFT JOIN fc_announcement_receipts ack ON ack.member_id=r.member_id AND ack.announcement_id=? AND ack.ack_version=? WHERE NOT EXISTS (SELECT 1 FROM fc_member_archive ar WHERE ar.member_id=r.member_id) ORDER BY r.nickname,r.member_id',[$row['id'],$row['ack_version']])->fetchAll();
    }
    private function fields(array $p):array {
        $title=DKP::label($p['title']??'',120);$body=trim(str_replace(["\r\n","\r"],"\n",$p['body']??''));
        $length=preg_match_all('/./us',$body);
        if(!$length || $length>2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$body))throw new AuthError('Текст объявления: от 1 до 2000 символов.');
        $expires=null;$date=$p['expires']??'';
        if($date!==''){
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('Europe/Moscow'));
            if(!$dt || $dt->format('Y-m-d\TH:i')!==$date || $dt->getTimestamp()<=$this->now())throw new AuthError('Срок показа должен быть в будущем, время по Москве.');
            $expires=$dt->getTimestamp();
        }
        return [$title,$body,$expires,($p['pinned']??'')==='1'?1:0,($p['requires_ack']??'')==='1'?1:0];
    }
    public function perform(int $actor,int $version,string $key,string $kind,array $p):string {
        if(!in_array($kind,['create','edit','archive','ack'],true) || !preg_match('/^[a-f0-9]{64}$/D',$key))throw new AuthError('Обнови форму и повтори действие.');
        $hash=hash('sha256',json_encode([$kind,$p],JSON_THROW_ON_ERROR));$db=$this->a->db;$db->beginTransaction();
        try {
            if($this->a->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1)throw new RuntimeException('Missing write lock');
            $user=$this->a->user($actor);
            if(!$user || !$user['verified_at'] || (int)$user['session_version']!==$version)throw new AuthError('Войди заново.');
            $this->access($user);
            if($kind!=='ack' && $user['role']!=='admin')throw new AuthError('Публикациями управляет администратор.');
            $old=$this->a->query('SELECT * FROM fc_announcement_actions WHERE request_key=?',[$key])->fetch();
            if($old){if((int)$old['actor_id']!==$actor || !hash_equals($old['payload_hash'],$hash))throw new AuthError('Форма уже использована.');$db->commit();return (string)$old['announcement_id'];}
            $before=null;$details=[];
            if($kind!=='create'){
                $before=$this->get($user,$p['id']??'');$id=(string)$before['id'];
                if((string)$before['revision']!==($p['revision']??''))throw new AuthError('Объявление изменилось. Обнови страницу и прочитай актуальный текст.');
                if((int)$before['archived'])throw new AuthError('Объявление удалено.');
            }
            if($kind==='ack'){
                if(!$this->active($before) || !(int)$before['requires_ack'])throw new AuthError('Подтверждение для этого объявления недоступно.');
                $member=$this->member($actor);if($member===false)throw new AuthError('Для подтверждения нужен активный профиль ДКП.');
                if(!$this->acknowledged($before,$actor))$this->a->query('INSERT INTO fc_announcement_receipts VALUES(?,?,?,?,?)',[$id,$before['ack_version'],$member,$actor,$this->now()]);
                $details=['member'=>$member,'ack_version'=>$before['ack_version']];
            } elseif($kind==='archive'){
                $this->a->query('UPDATE fc_announcements SET archived=1,pinned=0,revision=revision+1,updated_at=? WHERE id=?',[$this->now(),$id]);$details=['previous'=>$before];
            } else {
                [$title,$body,$expires,$pin,$ack]=$this->fields($p);
                if($pin)$this->a->query('UPDATE fc_announcements SET pinned=0,revision=revision+1 WHERE pinned=1'.($before?' AND id<>?':''),$before?[$id]:[]);
                if($kind==='create'){
                    $this->a->query('INSERT INTO fc_announcements(title,body,author_id,created_at,updated_at,expires_at,pinned,requires_ack) VALUES(?,?,?,?,?,?,?,?)',[$title,$body,$actor,$this->now(),$this->now(),$expires,$pin,$ack]);$id=(string)$db->lastInsertId();
                } else {
                    $ackVersion=(int)$before['ack_version']+(($p['reset_ack']??'')==='1' || ($ack && !(int)$before['requires_ack'])?1:0);
                    $this->a->query('UPDATE fc_announcements SET title=?,body=?,expires_at=?,pinned=?,requires_ack=?,ack_version=?,revision=revision+1,updated_at=? WHERE id=?',[$title,$body,$expires,$pin,$ack,$ackVersion,$this->now(),$id]);
                }
                $details=['previous'=>$before,'current'=>['title'=>$title,'body'=>$body,'expires_at'=>$expires,'pinned'=>$pin,'requires_ack'=>$ack,'reset_ack'=>$p['reset_ack']??'']];
            }
            $this->a->query('INSERT INTO fc_announcement_actions VALUES(?,?,?,?,?,?,?)',[$key,$actor,$id,$kind,$hash,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$this->now()]);
            $db->commit();return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
