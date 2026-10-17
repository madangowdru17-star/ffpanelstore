<?php
declare(strict_types=1);

final class Auth
{
    private const SESSION_COOKIE = 'hex_shop_session';
    private const ADMIN_SESSION_COOKIE = 'hex_admin_session';
    private const ADMIN_CSRF_COOKIE = 'hex_admin_csrf';
    private const SESSION_TTL = 2592000;

    public static function current(): ?array
    {
        $token = (string) ($_COOKIE[self::SESSION_COOKIE] ?? '');
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $row = Database::row(
            'SELECT s.csrf_token, s.expires_at, a.id, a.email, a.display_name, a.disabled, a.balance_inr '
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
            'balance_inr' => (float) ($account['balance_inr'] ?? 0),
        ];
    }

    public static function updateProfile(array $account, array $body): array
    {
        self::requireCsrf($account);
        $name = trim((string) ($body['display_name'] ?? ''));
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
            throw new HttpError('Display name must be 1–100 characters.', 400);
        }
        Database::pdo()->prepare('UPDATE store_accounts SET display_name=? WHERE id=?')->execute([$name, (int) $account['id']]);
        $updated = Database::row('SELECT id,email,display_name,balance_inr FROM store_accounts WHERE id=?', [(int) $account['id']]);
        return ['ok' => true, 'account' => self::publicAccount($updated ?? $account)];
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

    public static function googleLogin(array $claims): array
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $subject = trim((string) ($claims['sub'] ?? ''));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$verified || $subject === '' || strlen($subject) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError('Google did not return a verified account.', 401);
        }
        if (self::rateLimited('google_login', 20)) {
            throw new HttpError('Too many sign-in attempts. Please try again later.', 429);
        }
        $pdo = Database::pdo();
        $row = Database::row('SELECT * FROM store_accounts WHERE google_sub = ?', [$subject]);
        if (!$row) {
            $row = Database::row('SELECT * FROM store_accounts WHERE lower(email) = ?', [$email]);
            if ($row && (int) ($row['disabled'] ?? 0) === 1) {
                throw new HttpError('This account is disabled. Contact the shop administrator.', 403);
            }
            if ($row) {
                $existingSubject = (string) ($row['google_sub'] ?? '');
                if ($existingSubject !== '' && !hash_equals($existingSubject, $subject)) {
                    throw new HttpError('This email is already linked to a different Google account.', 409);
                }
                $pdo->prepare("UPDATE store_accounts SET google_sub=?, display_name=CASE WHEN display_name='' THEN ? ELSE display_name END WHERE id=?")
                    ->execute([$subject, self::slice(trim((string) ($claims['name'] ?? '')), 100), (int) $row['id']]);
                $row = Database::row('SELECT * FROM store_accounts WHERE id=?', [(int) $row['id']]);
            } else {
                $name = self::slice(trim((string) ($claims['name'] ?? '')), 100);
                $randomPassword = password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT);
                try {
                    $pdo->prepare('INSERT INTO store_accounts(email,password_hash,password_salt,display_name,google_sub,created_at,last_login_at) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$email, $randomPassword, '', $name, $subject, time(), time()]);
                } catch (PDOException $e) {
                    if (str_contains(strtolower($e->getMessage()), 'unique')) {
                        throw new HttpError('This Google account could not be linked. Please sign in with your existing method.', 409);
                    }
                    throw $e;
                }
                $row = Database::row('SELECT * FROM store_accounts WHERE google_sub=?', [$subject]);
            }
        }
        if (!$row || (int) ($row['disabled'] ?? 0) === 1) {
            throw new HttpError('This account is disabled or unavailable.', 403);
        }
        $pdo->prepare('UPDATE store_accounts SET last_login_at=? WHERE id=?')->execute([time(), (int) $row['id']]);
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

    public static function bootstrapAdmin(): void
    {
        if (Database::row('SELECT email FROM store_admin_users LIMIT 1')) {
            return;
        }
        $email = strtolower(trim((string) (getenv('ADMIN_EMAIL') ?: '')));
        $password = (string) (getenv('ADMIN_PASSWORD') ?: '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || preg_match('/@(?:example\.(?:com|net|org)|invalid)$/i', $email)
            || strlen($password) < 12 || strlen($password) > 256
            || str_starts_with(strtolower($password), 'replace-with-')) {
            return;
        }
        Database::pdo()->prepare('INSERT OR IGNORE INTO store_admin_users(email,password_hash,created_at) VALUES(?,?,?)')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), time()]);
    }

    public static function adminLogin(array $body): array
    {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        if ($email !== '' || $password !== '') {
            if (self::rateLimited('admin_login', 8)) {
                throw new HttpError('Too many admin sign-in attempts. Try again in 15 minutes.', 429);
            }
            $row = Database::row('SELECT email,password_hash,disabled FROM store_admin_users WHERE lower(email)=?', [$email]);
            if (!$row || (int) $row['disabled'] === 1 || !password_verify($password, (string) $row['password_hash'])) {
                throw new HttpError('Email or password is incorrect.', 401);
            }
            Database::pdo()->prepare('UPDATE store_admin_users SET last_login_at=? WHERE email=?')->execute([time(), (string) $row['email']]);
            return self::createAdminSession();
        }
        $configured = trim((string) (getenv('ADMIN_WEB_KEY') ?: ''));
        if ($configured === '') {
            throw new HttpError('Admin access is not configured. Set ADMIN_EMAIL and ADMIN_PASSWORD in Railway.', 503);
        }
        if (strlen($configured) < 24) {
            throw new HttpError('ADMIN_WEB_KEY must be at least 24 characters.', 503);
        }
        if (!hash_equals($configured, (string) ($body['key'] ?? ''))) {
            throw new HttpError('Invalid admin key.', 401);
        }
        return self::createAdminSession();
    }

    private static function createAdminSession(): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $statement = Database::pdo()->prepare('INSERT INTO admin_sessions(token_hash,csrf_token,expires_at,created_at) VALUES(?,?,?,?)');
        $statement->execute([hash('sha256', $token), $csrf, $now + 43200, $now]);
        Database::pdo()->prepare('DELETE FROM admin_sessions WHERE expires_at < ?')->execute([$now]);
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || getenv('RAILWAY_PUBLIC_DOMAIN');
        $options = ['expires' => $now + 43200, 'path' => '/admin', 'secure' => (bool) $secure, 'samesite' => 'Strict'];
        setcookie(self::ADMIN_SESSION_COOKIE, $token, $options + ['httponly' => true]);
        setcookie(self::ADMIN_CSRF_COOKIE, $csrf, $options + ['httponly' => false]);
        return ['ok' => true, 'expires_in' => 43200];
    }

    public static function changeAdminPassword(array $body): array
    {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $current = (string) ($body['current_password'] ?? '');
        $next = (string) ($body['new_password'] ?? '');
        if (strlen($next) < 12 || strlen($next) > 256) {
            throw new HttpError('New admin password must be at least 12 characters.', 400);
        }
        $row = Database::row('SELECT email,password_hash,disabled FROM store_admin_users WHERE lower(email)=?', [$email]);
        if (!$row || (int) $row['disabled'] === 1 || !password_verify($current, (string) $row['password_hash'])) {
            throw new HttpError('Admin email or current password is incorrect.', 401);
        }
        Database::pdo()->prepare('UPDATE store_admin_users SET password_hash=? WHERE email=?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (string) $row['email']]);
        Database::pdo()->exec('DELETE FROM admin_sessions');
        return ['ok' => true, 'signed_out' => true];
    }

    public static function requireAdmin(): void
    {
        $cookieToken = (string) ($_COOKIE[self::ADMIN_SESSION_COOKIE] ?? '');
        $legacyToken = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        $token = $cookieToken !== '' ? $cookieToken : $legacyToken;
        if ($token === '' || strlen($token) > 256) {
            throw new HttpError('Admin sign-in required.', 401);
        }
        $row = Database::row('SELECT expires_at,csrf_token FROM admin_sessions WHERE token_hash = ?', [hash('sha256', $token)]);
        if (!$row || (int) $row['expires_at'] < time()) {
            if ($row) {
                Database::pdo()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
            }
            throw new HttpError('Admin sign-in required.', 401);
        }
        if ($cookieToken !== '') {
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $supplied = (string) ($_SERVER['HTTP_X_ADMIN_CSRF'] ?? '');
                $cookie = (string) ($_COOKIE[self::ADMIN_CSRF_COOKIE] ?? '');
                $expected = (string) $row['csrf_token'];
                if ($expected === '' || $supplied === '' || $cookie === ''
                    || !hash_equals($expected, $supplied) || !hash_equals($expected, $cookie)) {
                    throw new HttpError('Admin session check failed. Refresh and try again.', 403);
                }
            }
        }
    }

    public static function adminLogout(): array
    {
        $token = (string) ($_COOKIE[self::ADMIN_SESSION_COOKIE] ?? $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        if ($token !== '') {
            Database::pdo()->prepare('DELETE FROM admin_sessions WHERE token_hash=?')->execute([hash('sha256', $token)]);
        }
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || getenv('RAILWAY_PUBLIC_DOMAIN');
        $options = ['expires' => time() - 3600, 'path' => '/admin', 'secure' => (bool) $secure, 'samesite' => 'Strict'];
        setcookie(self::ADMIN_SESSION_COOKIE, '', $options + ['httponly' => true]);
        setcookie(self::ADMIN_CSRF_COOKIE, '', $options + ['httponly' => false]);
        return ['ok' => true];
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
        $secure = (bool) $https;
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $secure ? 'None' : 'Lax',
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
