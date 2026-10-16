<?php
declare(strict_types=1);

final class Auth
{
    private const SESSION_COOKIE = 'hex_shop_session';
    private const SESSION_TTL = 2592000;

    public static function current(): ?array
    {
        $token = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $row = Database::row(
            'SELECT s.csrf_token, s.expires_at, a.id, a.email, a.display_name, a.disabled '
            . 'FROM store_sessions s JOIN store_accounts a ON a.id = s.account_id WHERE s.token_hash = ?',
            [hash('sha256', $token)]
        );
        if (!$row || (int) $row['expires_at'] < time() || (int) $row['disabled'] === 1) {
            $statement = Database::pdo()->prepare('DELETE FROM store_sessions WHERE token_hash = ?');
            $statement->execute([hash('sha256', $token)]);
            return null;
        }
        return $row;
    }

    public static function publicAccount(array $account): array
    {
        return [
            'id' => (int) ($account['id'] ?? 0),
            'email' => (string) ($account['email'] ?? ''),
            'display_name' => (string) (($account['display_name'] ?? '') ?: ($account['email'] ?? 'Shop customer')),
        ];
    }

    public static function register(array $body): array
    {
        if (self::rateLimited('register', 8)) {
            throw new HttpError('Too many attempts. Please try again in 15 minutes.', 429);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $name = trim((string) ($body['display_name'] ?? ''));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError('Enter a valid email address.', 400);
        }
        if (strlen($password) < 10 || strlen($password) > 256) {
            throw new HttpError('Password must be at least 10 characters.', 400);
        }
        $name = self::slice($name, 100);
        $now = time();
        try {
            $statement = Database::pdo()->prepare(
                'INSERT INTO store_accounts(email,password_hash,password_salt,display_name,created_at) VALUES(?,?,?,?,?)'
            );
            $statement->execute([$email, password_hash($password, PASSWORD_DEFAULT), '', $name, $now]);
            $id = (int) Database::pdo()->lastInsertId();
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                throw new HttpError('An account with that email already exists.', 409);
            }
            throw $e;
        }
        $account = ['id' => $id, 'email' => $email, 'display_name' => $name ?: $email];
        return ['ok' => true, 'account' => self::publicAccount($account), 'csrf_token' => self::createSession($id)];
    }

    public static function login(array $body): array
    {
        if (self::rateLimited('login', 12)) {
            throw new HttpError('Too many attempts. Please try again in 15 minutes.', 429);
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $row = Database::row('SELECT * FROM store_accounts WHERE lower(email) = ?', [$email]);
        if (!$row || (int) ($row['disabled'] ?? 0) === 1 || !self::verifyPassword($password, (string) ($row['password_hash'] ?? ''), (string) ($row['password_salt'] ?? ''))) {
            throw new HttpError('Email or password is incorrect.', 401);
        }
        if (str_starts_with((string) $row['password_hash'], '$')) {
            // Current password_hash formats are already safe; no migration is required.
        } else {
            $statement = Database::pdo()->prepare("UPDATE store_accounts SET password_hash = ?, password_salt = '' WHERE id = ?");
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
        }
        $csrf = self::createSession((int) $row['id']);
        return ['ok' => true, 'account' => self::publicAccount($row), 'csrf_token' => $csrf];
    }

    public static function logout(): array
    {
        $token = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        if ($token !== '') {
            $statement = Database::pdo()->prepare('DELETE FROM store_sessions WHERE token_hash = ?');
            $statement->execute([hash('sha256', $token)]);
        }
        setcookie(self::SESSION_COOKIE, '', self::cookieOptions(time() - 3600));
        return ['ok' => true];
    }

    public static function requireCsrf(array $account): void
    {
        $supplied = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) ($account['csrf_token'] ?? '');
        if ($supplied === '' || $expected === '' || !hash_equals($expected, $supplied)) {
            throw new HttpError('Session check failed. Refresh and try again.', 403);
        }
    }

    public static function adminLogin(array $body): array
    {
        $configured = trim((string) (getenv('ADMIN_WEB_KEY') ?: ''));
        if ($configured === '') {
            throw new HttpError('Admin access is not configured. Set ADMIN_WEB_KEY in Railway.', 503);
        }
        if (strlen($configured) < 24) {
            throw new HttpError('ADMIN_WEB_KEY must be at least 24 characters.', 503);
        }
        if (!hash_equals($configured, (string) ($body['key'] ?? ''))) {
            throw new HttpError('Invalid admin key.', 401);
        }
        $token = bin2hex(random_bytes(32));
        $now = time();
        $statement = Database::pdo()->prepare('INSERT INTO admin_sessions(token_hash,expires_at,created_at) VALUES(?,?,?)');
        $statement->execute([hash('sha256', $token), $now + 43200, $now]);
        Database::pdo()->prepare('DELETE FROM admin_sessions WHERE expires_at < ?')->execute([$now]);
        return ['token' => $token, 'expires_in' => 43200];
    }

    public static function requireAdmin(): void
    {
        $token = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        if ($token === '' || strlen($token) > 256) {
            throw new HttpError('Admin sign-in required.', 401);
        }
        $row = Database::row('SELECT expires_at FROM admin_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || (int) $row['expires_at'] < time()) {
            if ($row) {
                Database::pdo()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
            }
            throw new HttpError('Admin sign-in required.', 401);
        }
    }

    private static function createSession(int $accountId): string
    {
        $token = bin2hex(random_bytes(36));
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM store_sessions WHERE expires_at < ?')->execute([$now]);
        $pdo->prepare('INSERT INTO store_sessions(token_hash,account_id,csrf_token,expires_at,created_at) VALUES(?,?,?,?,?)')
            ->execute([hash('sha256', $token), $accountId, $csrf, $now + self::SESSION_TTL, $now]);
        $pdo->prepare('UPDATE store_accounts SET last_login_at = ? WHERE id = ?')->execute([$now, $accountId]);
        setcookie(self::SESSION_COOKIE, $token, self::cookieOptions($now + self::SESSION_TTL));
        return $csrf;
    }

    private static function cookieOptions(int $expires): array
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || getenv('RAILWAY_PUBLIC_DOMAIN');
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => (bool) $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function verifyPassword(string $password, string $stored, string $saltHex): bool
    {
        if ($stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$')) {
            return password_verify($password, $stored);
        }
        $salt = @hex2bin($saltHex);
        if ($salt === false || $salt === '') {
            return false;
        }
        $legacy = hash_pbkdf2('sha256', $password, $salt, 310000, 0, false);
        return hash_equals(strtolower($stored), strtolower($legacy));
    }

    private static function rateLimited(string $action, int $limit, int $window = 900): bool
    {
        $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'))[0]);
        $identity = $action . ':' . substr(hash('sha256', $ip), 0, 24);
        $now = time();
        $row = Database::row('SELECT hits,window_start FROM store_auth_attempts WHERE identity = ?', [$identity]);
        if (!$row || $now - (int) $row['window_start'] >= $window) {
            Database::pdo()->prepare(
                'INSERT INTO store_auth_attempts(identity,hits,window_start) VALUES(?,?,?) '
                . 'ON CONFLICT(identity) DO UPDATE SET hits=excluded.hits,window_start=excluded.window_start'
            )->execute([$identity, 1, $now]);
            return false;
        }
        if ((int) $row['hits'] >= $limit) {
            return true;
        }
        Database::pdo()->prepare('UPDATE store_auth_attempts SET hits = hits + 1 WHERE identity = ?')->execute([$identity]);
        return false;
    }

    private static function slice(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
