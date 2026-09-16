<?php
declare(strict_types=1);

final class AuthError extends RuntimeException {}

final class Auth {
    public function __construct(public PDO $db, private string $origin, private Closure $mailer) {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if (!defined('PASSWORD_ARGON2ID')) throw new RuntimeException('Argon2id required');
    }
    public function query(string $sql, array $args = []): PDOStatement {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }
    public static function email(string $value): string {
        $value = strtolower(trim($value));
        if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[^\x21-\x7E]/', $value)) {
            throw new AuthError('Укажи корректный email.');
        }
        return $value;
    }
    public static function password(string $value): string {
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length < 12 || $length > 128 || strlen($value) > 512 || str_contains($value, "\0")) {
            throw new AuthError('Пароль должен содержать от 12 до 128 символов.');
        }
        return $value;
    }
    public static function hashPassword(string $password): string {
        return password_hash(self::password($password), PASSWORD_ARGON2ID, ['memory_cost'=>19456, 'time_cost'=>2, 'threads'=>1]);
    }
    public function rate(string $key, int $maximum, int $seconds = 900): void {
        $bucket = hash('sha256', $key); $now = time();
        // Atomic UPDATE takes the row lock; insertion races count conservatively.
        $q = $this->query('UPDATE dkp_limits SET hits=hits+1 WHERE bucket=? AND expires_at>? AND hits<?', [$bucket,$now,$maximum]);
        if ($q->rowCount()) return;
        $this->query('DELETE FROM dkp_limits WHERE bucket=? AND expires_at<=?', [$bucket,$now]);
        try {
            $this->query('INSERT INTO dkp_limits(bucket,hits,expires_at) VALUES(?,1,?)', [$bucket,$now+$seconds]);
        } catch (PDOException $e) {
            if (!in_array((string)$e->getCode(), ['23000','23505'], true)) throw $e;
            throw new AuthError('Слишком много попыток. Попробуй через 15 минут.');
        }
        if (random_int(1, 100) === 1) $this->query('DELETE FROM dkp_limits WHERE expires_at<?', [$now]);
    }
    public function byEmail(string $email): array|false {
        return $this->query('SELECT * FROM dkp_users WHERE email=?', [$email])->fetch();
    }
    public function user(int $id): array|false {
        return $this->query('SELECT id,email,nickname,role,verified_at,session_version FROM dkp_users WHERE id=?', [$id])->fetch();
    }
    public function register(string $email, string $nickname, string $password, bool $newMember=false): void {
        $email = self::email($email); $nickname = trim($nickname);
        if (!preg_match('/^[^\p{C}]{1,32}$/u', $nickname)) throw new AuthError('Игровой ник: от 1 до 32 символов без управляющих знаков.');
        $hash = self::hashPassword($password);
        $this->db->beginTransaction();
        try {
            try {
                $this->query('INSERT INTO dkp_users(email,nickname,password_hash,created_at) VALUES(?,?,?,?)', [$email,$nickname,$hash,time()]);
            } catch (PDOException $e) {
                if (!in_array((string)$e->getCode(), ['23000','23505'], true)) throw $e;
                $this->db->rollBack();return; // Never alter an existing account's intent.
            }
            $id=(int)$this->db->lastInsertId();
            if($newMember)$this->query('INSERT INTO fc_registration_members(web_user_id,nickname,status) VALUES(?,?,?)',[$id,$nickname,'pending']);
            $this->db->commit();
        } catch(Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        // Email is sent after commit; resend retains the saved registration intent.
        $this->sendToken($id, $email, 'verify');
    }
    // Runs only inside email redemption, under the shared DKP write lock.
    private function createRegistrationMember(int $id):void {
        $intent=$this->query('SELECT nickname,status FROM fc_registration_members WHERE web_user_id=?',[$id])->fetch();
        if(!$intent)return;
        if($this->query('SELECT member_id FROM fc_web_links WHERE web_user_id=?',[$id])->fetchColumn()) {
            $this->query('DELETE FROM fc_registration_members WHERE web_user_id=?',[$id]);return;
        }
        // A matching nickname is never proof of ownership, including archived profiles.
        if($this->query('SELECT member_id FROM fc_roster WHERE nickname=?',[$intent['nickname']])->fetchColumn()) {
            $this->query("UPDATE fc_registration_members SET status='conflict' WHERE web_user_id=?",[$id]);return;
        }
        $this->query('INSERT INTO fc_roster(nickname,created_at) VALUES(?,?)',[$intent['nickname'],time()]);
        $member=(string)$this->db->lastInsertId();
        $this->query('INSERT INTO fc_web_links(web_user_id,member_id,linked_at) VALUES(?,?,?)',[$id,$member,time()]);
        $details=['nickname'=>$intent['nickname'],'member_id'=>$member,'account'=>$id,'registration'=>true];
        $key=hash('sha256','registration-member:'.$id);
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->query('INSERT INTO fc_operations(request_key,actor_id,kind,payload_hash,details,created_at) VALUES(?,?,?,?,?,?)',[$key,$id,'create',hash('sha256',$json),$json,time()]);
        $this->query('DELETE FROM fc_registration_members WHERE web_user_id=?',[$id]);
    }

    public function sendToken(int $id, string $email, string $purpose): void {
        $raw = bin2hex(random_bytes(32));
        $this->query('DELETE FROM dkp_tokens WHERE expires_at<=?', [time()]);
        $this->query('INSERT INTO dkp_tokens(token_hash,user_id,purpose,expires_at) VALUES(?,?,?,?)',
            [hash('sha256',$raw),$id,$purpose,time()+($purpose==='verify'?86400:1800)]);
        $url = $this->origin . '/dkp/?page=' . $purpose . '&token=' . $raw;
        $subject = $purpose === 'verify' ? 'Подтверждение регистрации' : 'Восстановление пароля';
        $body = $purpose === 'verify'
            ? "Подтверди свой email по ссылке ниже. Понадобится пароль, указанный при регистрации. Ссылка действует 24 часа."
            : "Установи новый пароль по ссылке ниже. Ссылка действует 30 минут.";
        if (!(($this->mailer)($email, 'SleepingForest — '.$subject, $body."\n\n".$url."\n\nЕсли ты не отправлял этот запрос, ничего делать не нужно."))) {
            $this->query('DELETE FROM dkp_tokens WHERE token_hash=?', [hash('sha256',$raw)]);
            throw new AuthError('Сейчас не удалось отправить письмо. Попробуй позже или сообщи администратору сайта.');
        }
    }
    public function requestToken(string $email, string $purpose): void {
        $user = $this->byEmail(self::email($email));
        if ($user && ($purpose === 'reset' || !$user['verified_at'])) $this->sendToken((int)$user['id'],$user['email'],$purpose);
    }
    public function token(string $raw, string $purpose): array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $raw)) throw new AuthError('Ссылка недействительна или устарела. Запроси новое письмо.');
        $row = $this->query('SELECT t.user_id,u.password_hash FROM dkp_tokens t JOIN dkp_users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose=? AND t.expires_at>?',
            [hash('sha256',$raw),$purpose,time()])->fetch();
        if (!$row) throw new AuthError('Ссылка недействительна или устарела. Запроси новое письмо.');
        return $row;
    }
    public function redeem(string $raw, string $purpose, string $password): void {
        if (!in_array($purpose, ['verify','reset'], true)) throw new AuthError('Некорректное действие.');
        $row = $this->token($raw, $purpose);
        if ($purpose === 'verify' && !password_verify($password,$row['password_hash'])) throw new AuthError('Пароль не совпадает с указанным при регистрации.');
        $hash = $purpose === 'reset' ? self::hashPassword($password) : null;
        $this->db->beginTransaction();
        try {
            // Same lock order as all roster/link/award operations. Reset also verifies email.
            if($this->query('UPDATE fc_write_lock SET revision=revision+1 WHERE id=1')->rowCount()!==1)throw new RuntimeException('Missing write lock');
            $q = $this->query('DELETE FROM dkp_tokens WHERE token_hash=? AND purpose=? AND expires_at>?', [hash('sha256',$raw),$purpose,time()]);
            if ($q->rowCount() !== 1) throw new AuthError('Ссылка уже использована. Запроси новое письмо.');
            if ($purpose === 'reset') {
                $this->query('UPDATE dkp_users SET password_hash=?,verified_at=?,session_version=session_version+1 WHERE id=?', [$hash,time(),$row['user_id']]);
            } else {
                $q = $this->query('UPDATE dkp_users SET verified_at=?,session_version=session_version+1 WHERE id=? AND password_hash=?', [time(),$row['user_id'],$row['password_hash']]);
                if ($q->rowCount() !== 1) throw new AuthError('Пароль изменился. Запроси новое письмо.');
            }
            $this->createRegistrationMember((int)$row['user_id']);
            $this->query('DELETE FROM dkp_tokens WHERE user_id=?', [$row['user_id']]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function login(string $email, string $password): array {
        $user = $this->byEmail(self::email($email));
        // A real fixed hash keeps non-existent accounts on the password verification path.
        $dummy = '$argon2id$v=19$m=19456,t=2,p=1$RWNtWnVHYnA5c1R2UVdzag$5VSvZfmVvSI+bLp/jRy9LKYwVpTZrg+wBnCYKVCNYGE';
        if (strlen($password)>512 || !password_verify($password, $user['password_hash'] ?? $dummy)) throw new AuthError('Неверный email или пароль.');
        if (!$user['verified_at']) throw new AuthError('Сначала подтверди email. Письмо можно запросить повторно.');
        return $this->user((int)$user['id']);
    }
    public function changePassword(int $id, string $old, string $new): void {
        $user = $this->query('SELECT password_hash FROM dkp_users WHERE id=?',[$id])->fetch();
        if (!$user || strlen($old)>512 || !password_verify($old,$user['password_hash'])) throw new AuthError('Текущий пароль указан неверно.');
        $hash = self::hashPassword($new);
        $this->db->beginTransaction();
        try {
            $q = $this->query('UPDATE dkp_users SET password_hash=?,session_version=session_version+1 WHERE id=? AND password_hash=?',[$hash,$id,$user['password_hash']]);
            if ($q->rowCount() !== 1) throw new AuthError('Пароль уже изменился. Войди заново.');
            $this->query('DELETE FROM dkp_tokens WHERE user_id=?',[$id]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
