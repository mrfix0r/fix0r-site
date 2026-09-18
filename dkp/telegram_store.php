<?php
declare(strict_types=1);
final class TelegramGuild {
    public function __construct(private Auth $a,private array $cfg,private string $origin,private ?Closure $clock=null) {}
    public function now():int{return $this->clock?(int)($this->clock)():time();}
    public function enabled():bool{return ($this->cfg['enabled']??false)===true && preg_match('/^[A-Za-z0-9_]{5,64}$/D',$this->cfg['bot_username']??'')===1 && preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D',$this->cfg['bot_token']??'')===1;}
    public function username():string{return $this->cfg['bot_username']??'';}
    public function apiToken():string{return $this->cfg['bot_token']??'';}
    public function member(int $account):string|false {
        $v=$this->a->query('SELECT l.member_id FROM fc_web_links l WHERE l.web_user_id=? AND NOT EXISTS(SELECT 1 FROM fc_member_archive ar WHERE ar.member_id=l.member_id)',[$account])->fetchColumn();return $v===false?false:(string)$v;
    }
    private function lock():void {if($this->a->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1)throw new RuntimeException('Missing DKP lock');}
    private function user(int $id,int $version):array {
        $u=$this->a->user($id);if(!$u || !$u['verified_at'] || (int)$u['session_version']!==$version)throw new AuthError('Войди заново.');return $u;
    }
    public function subscription(int $account):array|false{return $this->a->query('SELECT username,subscribed,linked_at FROM fc_tg_subscriptions WHERE web_user_id=?',[$account])->fetch();}
    public function startLink(int $actor,int $version):array {
        if(!$this->enabled())throw new AuthError('Telegram-бот ещё не настроен.');
        $db=$this->a->db;$db->beginTransaction();try{
            $this->lock();$this->user($actor,$version);$member=$this->member($actor);if($member===false)throw new AuthError('Сначала привяжи активный профиль ДКП.');
            $raw=bin2hex(random_bytes(24));$until=$this->now()+600;
            $this->a->query('DELETE FROM fc_tg_tokens WHERE web_user_id=? OR expires_at<=?',[$actor,$this->now()]);
            $this->a->query('INSERT INTO fc_tg_tokens VALUES(?,?,?,?,?)',[hash('sha256',$raw),$actor,$member,$version,$until]);$db->commit();
            return ['url'=>'https://t.me/'.$this->username().'?start=dkp_'.$raw,'expires_at'=>$until];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function unlink(int $actor,int $version):void {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$this->user($actor,$version);
            $this->a->query('DELETE FROM fc_tg_tokens WHERE web_user_id=?',[$actor]);
            $this->a->query('DELETE FROM fc_tg_subscriptions WHERE web_user_id=?',[$actor]);
            $this->a->query("UPDATE fc_tg_deliveries SET status='skipped',error_code='unsubscribed',updated_at=? WHERE web_user_id=? AND status='pending'",[$this->now(),$actor]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function stats(array $user,string $id):array|false {
        if($user['role']!=='admin')throw new AuthError('Доступно администратору.');
        if(!$this->a->query('SELECT announcement_id FROM fc_tg_batches WHERE announcement_id=?',[$id])->fetchColumn())return false;
        return $this->a->query('SELECT status,COUNT(*) AS total FROM fc_tg_deliveries WHERE announcement_id=? GROUP BY status',[$id])->fetchAll();
    }
    public function queue(int $actor,int $version,string $id,string $revision):int {
        if(!$this->enabled())throw new AuthError('Telegram-бот ещё не настроен.');
        $id=DKP::id($id);$db=$this->a->db;$db->beginTransaction();try{
            $this->lock();$u=$this->user($actor,$version);if($u['role']!=='admin')throw new AuthError('Оповещать может только администратор.');
            // Unique announcement_id guarantees one broadcast even across different form keys.
            if($this->a->query('SELECT announcement_id FROM fc_tg_batches WHERE announcement_id=?',[$id])->fetchColumn())throw new AuthError('Рассылка этого объявления уже создана. Повторная отправка не выполняется.');
            $n=$this->a->query('SELECT * FROM fc_announcements WHERE id=?',[$id])->fetch();
            if(!$n || (int)$n['archived'] || ($n['expires_at']!==null && (int)$n['expires_at']<=$this->now()))throw new AuthError('Объявление уже недоступно.');
            if((string)$n['revision']!==$revision)throw new AuthError('Объявление изменилось. Обнови страницу перед рассылкой.');
            $recipients=$this->a->query('SELECT s.* FROM fc_tg_subscriptions s JOIN fc_web_links l ON l.web_user_id=s.web_user_id AND l.member_id=s.member_id JOIN dkp_users u ON u.id=s.web_user_id WHERE s.subscribed=1 AND u.verified_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM fc_member_archive ar WHERE ar.member_id=s.member_id)')->fetchAll();
            if(!$recipients)throw new AuthError('Пока нет подписавшихся участников.');
            preg_match_all('/./us',$n['body'],$chars);$excerpt=implode('',array_slice($chars[0],0,1600)).(count($chars[0])>1600?"…":'');
            $text="Объявление гильдии\n\n".$n['title']."\n\n".$excerpt."\n\n".$this->origin.'/dkp/?page=announcements&announcement='.$id."\n\nОтписаться: /stop";
            $this->a->query('INSERT INTO fc_tg_batches VALUES(?,?,?,?,?)',[$id,$n['revision'],$actor,$this->now(),$text]);
            foreach($recipients as $s)$this->a->query('INSERT INTO fc_tg_deliveries(announcement_id,member_id,web_user_id,chat_id,generation,available_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$s['member_id'],$s['web_user_id'],$s['chat_id'],$s['generation'],$this->now(),$this->now()]);
            $db->commit();return count($recipients);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function acquire():string|false {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$state=$this->state();
            if((int)$state['lease_until']>$this->now()){$db->commit();return false;}
            $key=bin2hex(random_bytes(16));$this->a->query('UPDATE fc_tg_state SET lease_token=?,lease_until=? WHERE id=1',[$key,$this->now()+120]);
            // A worker may have died after sending. Never blindly resend these messages.
            $this->a->query("UPDATE fc_tg_deliveries SET status='unknown',error_code='worker_interrupted',updated_at=? WHERE status='sending'",[$this->now()]);
            $db->commit();return $key;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function state():array {$s=$this->a->query('SELECT * FROM fc_tg_state WHERE id=1')->fetch();if(!$s)throw new RuntimeException('Telegram schema missing');return $s;}
    private function lease(string $key):void {$s=$this->state();if(!hash_equals($s['lease_token'],$key) || (int)$s['lease_until']<=$this->now())throw new RuntimeException('Telegram lease expired');}
    public function release(string $key,bool $success):void {
        $this->a->query('UPDATE fc_tg_state SET lease_until=0'.($success?',last_success=?':'').' WHERE id=1 AND lease_token=?',$success?[$this->now(),$key]:[$key]);
    }
    public function identify(string $key,string $botId):void {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$this->lease($key);$s=$this->state();
            if($s['bot_id']!==null && (string)$s['bot_id']!==$botId)throw new RuntimeException('Configured bot differs from stored bot');
            $this->a->query('UPDATE fc_tg_state SET bot_id=? WHERE id=1',[$botId]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    // Only called by CLI after authenticated getUpdates. Never accept public HTTP updates here.
    public function update(string $key,array $update):?array {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$this->lease($key);$uid=$update['update_id']??null;
            if(!is_int($uid) || $uid<0)throw new RuntimeException('Invalid update');
            if($uid<(int)$this->state()['update_offset']){$db->commit();return null;}
            $reply=null;$m=$update['message']??[];$chat=(string)($m['chat']['id']??'');$text=$m['text']??'';
            if(($m['chat']['type']??'')==='private' && preg_match('/^[1-9][0-9]{0,15}$/D',$chat) && $chat===(string)($m['from']['id']??'') && empty($m['from']['is_bot']) && is_string($text)) {
                if(preg_match('~^/stop(?:@[A-Za-z0-9_]+)?(?:\s|$)~',$text)) {
                    $this->a->query('UPDATE fc_tg_subscriptions SET subscribed=0 WHERE chat_id=?',[$chat]);
                    $this->a->query("UPDATE fc_tg_deliveries SET status='skipped',error_code='unsubscribed',updated_at=? WHERE chat_id=? AND status='pending'",[$this->now(),$chat]);
                    $reply=['chat_id'=>$chat,'text'=>'Подписка отключена. Чтобы подписаться снова, используй «Привязать Telegram» в кабинете ДКП.'];
                } elseif(preg_match('~^/start(?:@[A-Za-z0-9_]+)? dkp_([a-f0-9]{48})$~D',$text,$matches)) {
                    $hash=hash('sha256',$matches[1]);$t=$this->a->query('SELECT * FROM fc_tg_tokens WHERE token_hash=? AND expires_at>?',[$hash,$this->now()])->fetch();
                    $valid=false;
                    if($t){$u=$this->a->user((int)$t['web_user_id']);$valid=$u && $u['verified_at'] && (int)$u['session_version']===(int)$t['session_version'] && $this->member((int)$t['web_user_id'])===(string)$t['member_id'];}
                    if($valid){
                        $other=$this->a->query('SELECT member_id FROM fc_tg_subscriptions WHERE chat_id=?',[$chat])->fetchColumn();
                        if($other!==false && (string)$other!==(string)$t['member_id']){$valid=false;$response='Этот Telegram уже привязан к другому профилю. Сначала отвяжи его в прежнем кабинете.';}
                        else {
                            $this->a->query('DELETE FROM fc_tg_subscriptions WHERE member_id=?',[$t['member_id']]);
                            $username=is_string($m['from']['username']??null)?$m['from']['username']:'';if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username))$username='';
                            $this->a->query('INSERT INTO fc_tg_subscriptions VALUES(?,?,?,?,?,1,?)',[$t['member_id'],$t['web_user_id'],$chat,$username,bin2hex(random_bytes(16)),$this->now()]);
                            $this->a->query('DELETE FROM fc_tg_tokens WHERE token_hash=?',[$hash]);
                            $response='Telegram привязан. Ты подписан на объявления гильдии. Отписаться: /stop. Подтверждение прочтения объявлений остаётся на сайте.';
                        }
                    }else $response='Ссылка недействительна или истекла. Получи новую через «Привязать Telegram» в кабинете ДКП.';
                    $reply=['chat_id'=>$chat,'text'=>$response];
                } elseif(str_starts_with($text,'/start') || str_starts_with($text,'/help'))$reply=['chat_id'=>$chat,'text'=>'Для подписки нажми «Привязать Telegram» в своём кабинете ДКП: '.$this->origin.'/dkp/. Отписаться: /stop.'];
            }
            $this->a->query('UPDATE fc_tg_state SET update_offset=? WHERE id=1',[$uid+1]);$this->a->query('DELETE FROM fc_tg_tokens WHERE expires_at<=?',[$this->now()]);$db->commit();return $reply;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function claim(string $key):array|false {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$this->lease($key);
            if((int)$this->state()['cooldown_until']>$this->now()){$db->commit();return false;}
            $row=$this->a->query("SELECT d.*,b.message_text,b.revision AS batch_revision FROM fc_tg_deliveries d JOIN fc_tg_batches b ON b.announcement_id=d.announcement_id WHERE d.status='pending' AND d.available_at<=? ORDER BY d.id LIMIT 1",[$this->now()])->fetch();
            if(!$row){$db->commit();return false;}
            $s=$this->a->query('SELECT * FROM fc_tg_subscriptions WHERE member_id=?',[$row['member_id']])->fetch();$u=$this->a->user((int)$row['web_user_id']);
            $n=$this->a->query('SELECT * FROM fc_announcements WHERE id=?',[$row['announcement_id']])->fetch();
            $reason='';
            if(!$s || !(int)$s['subscribed'] || $s['generation']!==$row['generation'] || (string)$s['chat_id']!==(string)$row['chat_id'] || !$u || !$u['verified_at'] || $this->member((int)$row['web_user_id'])!==(string)$row['member_id'])$reason='unsubscribed';
            elseif(!$n || (int)$n['archived'] || ($n['expires_at']!==null && (int)$n['expires_at']<=$this->now()) || (int)$n['revision']!==(int)$row['batch_revision'])$reason='announcement_changed';
            $this->a->query('UPDATE fc_tg_deliveries SET status=?,error_code=?,attempts=attempts+?,updated_at=? WHERE id=?',[$reason?'skipped':'sending',$reason,$reason?0:1,$this->now(),$row['id']]);
            $db->commit();if($reason)return ['skipped'=>true];$row['attempts']=(int)$row['attempts']+1;return $row;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function result(string $key,array $job,array $response):void {
        $db=$this->a->db;$db->beginTransaction();try{$this->lock();$this->lease($key);
            $status='failed';$code=(string)($response['error_code']??'network_unknown');$when=$this->now();$mid=null;
            if(($response['ok']??false)===true && isset($response['result']['message_id'])){$status='sent';$code='';$mid=$response['result']['message_id'];}
            elseif(!isset($response['ok']) || $response['ok']===true){$status='unknown';}
            elseif((int)($response['error_code']??0)===429){$status='pending';$when+=max(1,(int)($response['parameters']['retry_after']??60));$this->a->query('UPDATE fc_tg_state SET cooldown_until=? WHERE id=1',[$when]);}
            elseif((int)($response['error_code']??0)>=500 && $job['attempts']<5){$status='pending';$when+=min(3600,60*(2**($job['attempts']-1)));}
            elseif((int)($response['error_code']??0)===403)$this->a->query('UPDATE fc_tg_subscriptions SET subscribed=0 WHERE member_id=? AND generation=?',[$job['member_id'],$job['generation']]);
            $this->a->query("UPDATE fc_tg_deliveries SET status=?,available_at=?,updated_at=?,message_id=?,error_code=? WHERE id=? AND status='sending'",[$status,$when,$this->now(),$mid,substr($code,0,32),$job['id']]);$db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
