<?php
declare(strict_types=1);

// Persistent browser credentials are separate from short-lived PHP sessions.
// Only SHA-256 hashes enter the existing token table; email tokens keep their purposes.
final class RememberLogin {
    public const COOKIE = '__Secure-FC_DKP_REMEMBER';
    public const TTL = 30 * 86400;

    public function __construct(private Auth $auth) {}

    private static function valid(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public function issue(array $user, mixed $previousCookie = null, mixed $previousHash = null): array {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = time() + self::TTL;
        $this->auth->db->beginTransaction();
        try {
            $current = $this->auth->user((int)$user['id']);
            if (!$current || !$current['verified_at'] || (int)$current['session_version'] !== (int)$user['session_version']) {
                throw new AuthError(t('Данные аккаунта изменились. Войди заново.'));
            }
            // A replacement revokes this browser only, leaving other devices signed in.
            $this->revokeCookie($previousCookie);
            $this->revokeHash($previousHash);
            $this->auth->query("DELETE FROM dkp_tokens WHERE user_id=? AND purpose LIKE 'stay:%' AND expires_at<=?", [$user['id'], time()]);
            // 'stay:' + a signed INT version fits the existing VARCHAR(16).
            $this->auth->query('INSERT INTO dkp_tokens(token_hash,user_id,purpose,expires_at) VALUES(?,?,?,?)',
                [$hash, $user['id'], 'stay:'.$current['session_version'], $expires]);
            $this->auth->db->commit();
        } catch (Throwable $e) {
            if ($this->auth->db->inTransaction()) $this->auth->db->rollBack();
            throw $e;
        }
        return ['raw'=>$raw, 'hash'=>$hash, 'expires'=>$expires];
    }

    public function fromCookie(mixed $raw): array|false {
        return self::valid($raw) ? $this->byHash(hash('sha256', $raw)) : false;
    }

    public function byHash(mixed $hash): array|false {
        if (!self::valid($hash)) return false;
        $row = $this->auth->query("SELECT u.id,u.email,u.nickname,u.role,u.verified_at,u.session_version,t.purpose,t.expires_at
            FROM dkp_tokens t JOIN dkp_users u ON u.id=t.user_id
            WHERE t.token_hash=? AND t.purpose LIKE 'stay:%' AND t.expires_at>?", [$hash, time()])->fetch();
        if (!$row || !$row['verified_at'] || $row['purpose'] !== 'stay:'.$row['session_version']) return false;
        $expires = (int)$row['expires_at'];
        unset($row['purpose'], $row['expires_at']);
        // Fixed expiry: visiting the site does not silently extend the 30-day grant.
        return ['user'=>$row, 'hash'=>$hash, 'expires'=>$expires];
    }

    public function revokeCookie(mixed $raw): void {
        if (self::valid($raw)) $this->revokeHash(hash('sha256', $raw));
    }

    public function revokeHash(mixed $hash): void {
        if (self::valid($hash)) $this->auth->query("DELETE FROM dkp_tokens WHERE token_hash=? AND purpose LIKE 'stay:%'", [$hash]);
    }

    public static function cookie(?array $ticket): void {
        setcookie(self::COOKIE, $ticket['raw'] ?? '', [
            'expires'=>$ticket['expires'] ?? time()-3600,
            'path'=>'/dkp', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax',
        ]);
        if ($ticket === null) unset($_COOKIE[self::COOKIE]);
    }
}
