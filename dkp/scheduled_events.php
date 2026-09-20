<?php
declare(strict_types=1);

final class ScheduledEvents {
    private const SLOTS = ['07:30'=>'Утреннее ЧВ', '15:30'=>'Дневное ЧВ', '20:30'=>'Вечернее ЧВ'];

    public function __construct(private Auth $auth, private array $settings) {
        if (!is_bool($settings['enabled'] ?? null)) throw new RuntimeException('Invalid CW enabled setting');
        foreach (['creator_id'=>PHP_INT_MAX, 'points'=>1000000, 'catch_up_minutes'=>1440] as $key=>$max) {
            if (!is_int($settings[$key] ?? null) || $settings[$key]<1 || $settings[$key]>$max) {
                throw new RuntimeException('Invalid CW setting: '.$key);
            }
        }
    }

    public function state(): array {
        $row=$this->auth->query('SELECT enabled,revision,resume_after FROM fc_event_schedule WHERE id=1')->fetch();
        if (!$row) throw new RuntimeException('Missing event schedule settings');
        return ['enabled'=>(bool)$row['enabled'], 'revision'=>(int)$row['revision'],
            'resume_after'=>(int)$row['resume_after'], 'configured'=>$this->settings['enabled']];
    }

    public function setEnabled(int $actor,int $version,string $enabled,string $revision,?int $now=null): void {
        if (!in_array($enabled,['0','1'],true) || !preg_match('/^[1-9][0-9]{0,9}$/D',$revision)) throw new AuthError('Обнови страницу и повтори действие.');
        $db=$this->auth->db;
        if ($db->inTransaction()) throw new RuntimeException('Schedule settings require their own transaction');
        $db->beginTransaction();
        try {
            if ($this->auth->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1) throw new RuntimeException('Missing write lock');
            $u=$this->auth->query('SELECT role,verified_at,session_version FROM dkp_users WHERE id=?',[$actor])->fetch();
            if (!$u || !$u['verified_at'] || (int)$u['session_version']!==$version || !in_array($u['role'],['admin','officer'],true)) throw new AuthError('Это действие доступно только офицеру или администратору.');
            $state=$this->state();
            if ($state['revision']!==(int)$revision) throw new AuthError('Настройка уже изменена. Обнови страницу.');
            if ($enabled==='1' && !$state['configured']) throw new AuthError('Автосоздание отключено в cw_schedule.php. Обратись к администратору.');
            if ($state['enabled']===($enabled==='1')) { $db->commit();return; }
            $now ??= time();
            // On resume, skip every slot whose scheduled time has already passed.
            $after=$enabled==='1'?$now:$state['resume_after'];
            $this->auth->query('UPDATE fc_event_schedule SET enabled=?,revision=revision+1,resume_after=? WHERE id=1',[(int)$enabled,$after]);
            $details=json_encode(['enabled'=>$enabled==='1','resume_after'=>$after,'revision'=>$state['revision']+1],JSON_THROW_ON_ERROR);
            $this->auth->query('INSERT INTO fc_operations(request_key,actor_id,kind,payload_hash,details,created_at) VALUES(?,?,?,?,?,?)',[bin2hex(random_bytes(32)),$actor,'cw_schedule',hash('sha256',$details),$details,$now]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function checkCreator(): void {
        $u=$this->auth->query('SELECT role,verified_at FROM dkp_users WHERE id=?',[$this->settings['creator_id']])->fetch();
        if (!$u || $u['role']!=='admin' || !$u['verified_at']) throw new RuntimeException('CW creator must be a verified administrator');
    }

    public function check(): void {
        if (!$this->settings['enabled']) return;
        $state=$this->state();
        if (!$state['enabled']) return;
        $this->checkCreator();
        $this->auth->query('SELECT id,title,category,points,scheduled_at,creator_id,created_at FROM fc_events LIMIT 1');
        $this->auth->query('SELECT request_key,actor_id,kind,payload_hash,details,created_at FROM fc_operations LIMIT 1');
        if (!$this->auth->query('SELECT id FROM fc_write_lock WHERE id=1')->fetch()) throw new RuntimeException('Missing write lock');
    }

    public function run(?int $now=null): int {
        if (!$this->settings['enabled']) return 0;
        $now ??= time();
        $day=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Moscow'));
        $created=0;
        foreach (self::SLOTS as $slot=>$title) {
            [$hour,$minute]=array_map('intval',explode(':',$slot));
            $scheduled=$day->setTime($hour,$minute,0)->getTimestamp();
            // No future events or old backlog on installation/restart.
            if ($now<$scheduled || $now-$scheduled>$this->settings['catch_up_minutes']*60) continue;
            $key=hash('sha256','fc-cw:v1:'.$day->format('Y-m-d').':'.$slot);
            $db=$this->auth->db;
            if ($db->inTransaction()) throw new RuntimeException('CW scheduler requires its own transaction');
            $db->beginTransaction();
            try {
                // Same lock as manual events, attendance, rewards and auctions.
                if ($this->auth->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1) throw new RuntimeException('Missing write lock');
                $state=$this->state();
                if (!$state['enabled'] || $scheduled<=$state['resume_after']) { $db->commit();continue; }
                if ($this->auth->query('SELECT request_key FROM fc_operations WHERE request_key=?',[$key])->fetch()) {
                    $db->commit();continue;
                }
                $this->checkCreator();
                // Respect an event already made manually for this exact slot,
                // including a cancelled/awarded event. Never reopen it.
                $existing=$this->auth->query("SELECT id,title,points FROM fc_events WHERE category='cw' AND scheduled_at=? ORDER BY id LIMIT 1",[$scheduled])->fetch();
                if ($existing) {
                    $id=(string)$existing['id'];$eventTitle=$existing['title'];
                } else {
                    $this->auth->query("INSERT INTO fc_events(title,category,points,scheduled_at,creator_id,created_at) VALUES(?,'cw',?,?,?,?)",[$title,$this->settings['points'],$scheduled,$this->settings['creator_id'],$now]);
                    $id=$db->lastInsertId();$eventTitle=$title;
                }
                $details=json_encode(['event_id'=>$id,'title'=>$eventTitle,'points'=>$existing?(int)$existing['points']:$this->settings['points'],'scheduled_at'=>$scheduled,'timezone'=>'Europe/Moscow','reused'=>(bool)$existing],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $this->auth->query('INSERT INTO fc_operations(request_key,actor_id,kind,payload_hash,details,created_at) VALUES(?,?,?,?,?,?)',[$key,$this->settings['creator_id'],'evt_auto',hash('sha256',$details),$details,$now]);
                $db->commit();
                if (!$existing) $created++;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        return $created;
    }
}
