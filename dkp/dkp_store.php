<?php
declare(strict_types=1);
require_once __DIR__.'/events_store.php';
require_once __DIR__.'/auctions_store.php';
final class DKP {
    use DKPEvents, DKPAuctions;
    public function __construct(private Auth $a, private ?Closure $clock=null) {}
    public function roster(): array {
        return $this->a->query('SELECT r.*, (SELECT COALESCE(SUM(amount),0) FROM fc_20260914_ledger WHERE user_id=r.member_id)+(SELECT COALESCE(SUM(amount),0) FROM fc_points WHERE member_id=r.member_id) AS balance, (SELECT COALESCE(SUM(highest_bid),0) FROM fc_auctions WHERE status=\'open\' AND highest_member=r.member_id) AS reserved FROM fc_roster r WHERE NOT EXISTS (SELECT 1 FROM fc_member_archive ar WHERE ar.member_id=r.member_id) ORDER BY r.nickname,r.member_id')->fetchAll();
    }
    public function archivedRoster(): array {
        return $this->a->query('SELECT r.member_id,r.nickname,ar.reason,ar.archived_at FROM fc_member_archive ar JOIN fc_roster r ON r.member_id=ar.member_id ORDER BY ar.archived_at DESC,r.member_id')->fetchAll();
    }
    private function requireActive(string $id): void {
        if (!$this->a->query('SELECT member_id FROM fc_roster WHERE member_id=?',[$id])->fetchColumn()) throw new AuthError('Участник не найден.');
        if ($this->a->query('SELECT member_id FROM fc_member_archive WHERE member_id=?',[$id])->fetchColumn()) throw new AuthError('Участник удалён из состава. Администратор может восстановить его.');
    }
    // Called under the same write lock as bids, attendance and DKP awards.
    private function archiveChange(int $actor,string $kind,array $p): array {
        $id=self::id($p['member']??'');
        $name=$this->a->query('SELECT nickname FROM fc_roster WHERE member_id=?',[$id])->fetchColumn();
        if($name===false)throw new AuthError('Участник не найден.');
        if($kind==='restore') {
            if($this->a->query('DELETE FROM fc_member_archive WHERE member_id=?',[$id])->rowCount()!==1)throw new AuthError('Участник уже в активном составе.');
            return ['member'=>$id,'nickname'=>$name];
        }
        $this->requireActive($id);
        $reason=self::label($p['reason']??'',500);
        if($this->a->query("SELECT id FROM fc_auctions WHERE status='open' AND highest_member=? LIMIT 1",[$id])->fetchColumn())throw new AuthError('Участник лидирует в открытом аукционе. Сначала заверши или отмени аукцион.');
        $events=$this->a->query("SELECT e.id FROM fc_events e JOIN fc_event_members m ON m.event_id=e.id WHERE e.status='open' AND m.member_id=?",[$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach($events as $event) {
            $this->a->query('DELETE FROM fc_event_members WHERE event_id=? AND member_id=?',[$event,$id]);
            $this->a->query('UPDATE fc_events SET revision=revision+1 WHERE id=?',[$event]);
        }
        $this->a->query('INSERT INTO fc_member_archive(member_id,archived_at,archived_by,reason) VALUES(?,?,?,?)',[$id,time(),$actor,$reason]);
        return ['member'=>$id,'nickname'=>$name,'reason'=>$reason,'removed_from_events'=>$events];
    }
    public function balance(string $id): int {
        $old=$this->a->query('SELECT COALESCE(SUM(amount),0) FROM fc_20260914_ledger WHERE user_id=?',[$id])->fetchColumn();
        $new=$this->a->query('SELECT COALESCE(SUM(amount),0) FROM fc_points WHERE member_id=?',[$id])->fetchColumn();
        return (int)$old+(int)$new;
    }
    public function history(string $id,int $page=1): array {
        $offset=(max(1,min($page,100000))-1)*30;
        return $this->a->query("SELECT * FROM (SELECT id,amount,reason,created_at,'bot' AS origin,actor_id FROM fc_20260914_ledger WHERE user_id=? UNION ALL SELECT id,amount,reason,created_at,'site' AS origin,actor_id FROM fc_points WHERE member_id=?) h ORDER BY created_at DESC,origin DESC,id DESC LIMIT 31 OFFSET $offset",[$id,$id])->fetchAll();
    }
    public static function id(string $id): string {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || (strlen($id)===19 && strcmp($id,'9223372036854775807')>0)) throw new AuthError('Некорректный участник.');
        return $id;
    }
    public static function label(string $s,int $max):string {
        $s=trim($s);$n=preg_match_all('/./us',$s);
        if (!$n || $n>$max || preg_match('/\p{C}/u',$s)) throw new AuthError('Заполни текст без переносов: до '.$max.' символов.');
        return $s;
    }
    // Every write takes the same InnoDB row lock. Permission checks, balances,
    // deduplication, journal and the whole batch share one transaction.
    public function perform(int $actor,int $version,string $key,string $kind,array $p): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new AuthError('Обнови форму и повтори действие.');
        if (!in_array($kind,['archive','restore','adjust','link','role','create','evt_create','evt_save','evt_award','evt_cancel','evt_join','evt_leave','auc_create','auc_cancel','auc_bid'],true)) throw new AuthError('Неизвестное действие.');
        $hash=hash('sha256',json_encode([$kind,$p],JSON_THROW_ON_ERROR));
        $db=$this->a->db;$db->beginTransaction();
        try {
            if ($this->a->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1) throw new RuntimeException('Missing write lock');
            $u=$this->a->user($actor);
            if (!$u || !$u['verified_at'] || (int)$u['session_version']!==$version || !in_array($u['role'],['admin','officer','member'],true)) throw new AuthError('Недостаточно прав. Войди заново.');
            if(!in_array($kind,['auc_bid','evt_join','evt_leave'],true) && $u['role']==='member')throw new AuthError('Это действие доступно только офицеру или администратору.');
            if (!in_array($kind,['evt_join','evt_leave','auc_create','auc_cancel','auc_bid','adjust','evt_create','evt_save','evt_award','evt_cancel'],true) && $u['role']!=='admin') throw new AuthError('Это действие доступно только администратору.');
            $old=$this->a->query('SELECT actor_id,payload_hash FROM fc_operations WHERE request_key=?',[$key])->fetch();
            if ($old) {
                if ((int)$old['actor_id']!==$actor || !hash_equals($old['payload_hash'],$hash)) throw new AuthError('Эта форма уже использована для другого действия. Обнови страницу.');
                $db->commit();return false;
            }
            $details=$p;
            if (in_array($kind,['archive','restore'],true)) {
                $details=$this->archiveChange($actor,$kind,$p);
            } elseif (str_starts_with($kind,'auc_')) {
                $details=$this->auctionChange($actor,$kind,$p,$key);
            } elseif (str_starts_with($kind,'evt_')) {
                $details=$this->eventChange($actor,$kind,$p);
            } elseif ($kind==='adjust') {
                $ids=$p['members']??[];
                if (!is_array($ids) || count($ids)<1 || count($ids)>100) throw new AuthError('Выбери от 1 до 100 участников.');
                $ids=array_values(array_unique(array_map(fn($v)=>self::id((string)$v),$ids)));sort($ids,SORT_STRING);
                $amount=filter_var($p['amount']??'',FILTER_VALIDATE_INT);
                if ($amount===false || $amount===0 || abs($amount)>1000000) throw new AuthError('Количество: целое число от −1000000 до 1000000, кроме нуля.');
                $reason=self::label($p['reason']??'',500);
                $names=[];
                foreach ($ids as $id) {
                    $this->requireActive($id);
                    $m=$this->a->query('SELECT nickname FROM fc_roster WHERE member_id=?',[$id])->fetch();
                    if (!$m) throw new AuthError('Участник не найден. Обнови состав.');
                    if ($this->balance($id)-$this->reserved($id)+$amount<0) throw new AuthError('Недостаточно ДКП у '.$m['nickname'].'. Зарезервированные ставки нельзя списать. Ничего не списано.');
                    $names[$id]=$m['nickname'];
                }
                $details=['members'=>$names,'amount'=>$amount,'reason'=>$reason];
            } elseif ($kind==='link') {
                $member=self::id($p['member']??'');$account=self::id($p['account']??'');
                if (!$this->a->query('SELECT member_id FROM fc_roster WHERE member_id=?',[$member])->fetch()) throw new AuthError('Участник не найден.');
                $this->requireActive($member);
                $target=$this->a->user((int)$account);
                if (!$target || !$target['verified_at']) throw new AuthError('Аккаунт не найден или email не подтверждён.');
                if ($this->a->query('SELECT web_user_id FROM fc_web_links WHERE member_id=? OR web_user_id=?',[$member,$account])->fetch()) throw new AuthError('Участник или аккаунт уже привязан. Существующую привязку нельзя заменить этой формой.');
                $this->a->query('INSERT INTO fc_web_links VALUES(?,?,?)',[$account,$member,time()]);
            } elseif ($kind==='role') {
                $account=self::id($p['account']??'');$role=$p['role']??'';
                if (!in_array($role,['member','officer'],true)) throw new AuthError('Можно назначить участника или офицера.');
                $target=$this->a->user((int)$account);
                if (!$target || !$target['verified_at'] || $target['role']==='admin') throw new AuthError('Этот аккаунт нельзя изменить.');
                $details['previous_role']=$target['role'];
                $this->a->query('UPDATE dkp_users SET role=?,session_version=session_version+1 WHERE id=?',[$role,$account]);
            } else {
                $name=self::label($p['nickname']??'',32);
                if ($this->a->query('SELECT member_id FROM fc_roster WHERE nickname=?',[$name])->fetch()) throw new AuthError('Такой ник уже есть в составе.');
                $this->a->query('INSERT INTO fc_roster(nickname,created_at) VALUES(?,?)',[$name,time()]);
                $details=['nickname'=>$name,'member_id'=>$db->lastInsertId()];
            }
            $this->a->query('INSERT INTO fc_operations VALUES(?,?,?,?,?,?)',[$key,$actor,$kind,$hash,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()]);
            if ($kind==='adjust') foreach ($ids as $id) $this->a->query('INSERT INTO fc_points(member_id,amount,reason,actor_id,request_key,created_at) VALUES(?,?,?,?,?,?)',[$id,$amount,$reason,$actor,$key,time()]);
            if ($kind==='evt_award') foreach(array_keys($details['members']) as $id) $this->a->query('INSERT INTO fc_points(member_id,amount,reason,actor_id,request_key,created_at) VALUES(?,?,?,?,?,?)',[$id,$details['amount'],$details['reason'],$actor,$key,time()]);
            $db->commit();return true;
        } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
    }
}
