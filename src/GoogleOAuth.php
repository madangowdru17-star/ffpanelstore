<?php
declare(strict_types=1);

final class GoogleOAuth
{
    private const BROWSER_COOKIE = 'hex_google_oauth_browser';
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public static function start(): never
    {
        $nextPath = self::safeNext((string) ($_GET['next'] ?? '/account'));
        $clientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
        $redirectUri = self::redirectUri();
        if ($clientId === '' || !filter_var($redirectUri, FILTER_VALIDATE_URL) || !str_starts_with($redirectUri, 'https://')) {
            self::redirect('/login?google=not_configured&next=' . rawurlencode($nextPath));
        }

        $state = self::base64url(random_bytes(32));
        $verifier = self::base64url(random_bytes(48));
        $challenge = self::base64url(hash('sha256', $verifier, true));
        $browserNonce = self::base64url(random_bytes(32));
        setcookie(self::BROWSER_COOKIE, $browserNonce, [
            'expires' => time() + 600,
            'path' => '/auth',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM store_google_oauth_states WHERE expires_at < ?')->execute([time()]);
        $pdo->prepare('INSERT INTO store_google_oauth_states(state_hash,code_verifier,browser_hash,next_path,expires_at,created_at) VALUES(?,?,?,?,?,?)')
            ->execute([hash('sha256', $state), $verifier, hash('sha256', $browserNonce), $nextPath, time() + 600, time()]);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
        header('Cache-Control: no-store');
        header('Location: ' . self::AUTHORIZE_URL . '?' . $query, true, 302);
        exit;
    }

    public static function callback(): never
    {
        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');
        if (!empty($_GET['error']) || $state === '' || $code === '' || strlen($state) > 200 || strlen($code) > 4096) {
            self::redirect('/login?google=failed');
        }

        $stateHash = hash('sha256', $state);
        $browserNonce = (string) ($_COOKIE[self::BROWSER_COOKIE] ?? '');
        if ($browserNonce === '' || strlen($browserNonce) > 200) {
            self::clearBrowserCookie();
            self::redirect('/login?google=expired');
        }
        $pdo = Database::pdo();
        $statement = $pdo->prepare('SELECT code_verifier,browser_hash,next_path,expires_at FROM store_google_oauth_states WHERE state_hash=?');
        $statement->execute([$stateHash]);
        $saved = $statement->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM store_google_oauth_states WHERE state_hash=?')->execute([$stateHash]);
        self::clearBrowserCookie();
        if (!$saved || (int) $saved['expires_at'] < time()
            || !hash_equals((string) $saved['browser_hash'], hash('sha256', $browserNonce))) {
            self::redirect('/login?google=expired');
        }

        $clientId = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ''));
        $clientSecret = trim((string) (getenv('GOOGLE_CLIENT_SECRET') ?: ''));
        if ($clientId === '' || $clientSecret === '') {
            self::redirect('/login?google=not_configured');
        }
        $tokenResponse = self::postForm(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => (string) $saved['code_verifier'],
        ]);
        $idToken = (string) ($tokenResponse['id_token'] ?? '');
        if ($idToken === '' || strlen($idToken) > 16384) {
            self::redirect('/login?google=failed');
        }

        // Google tokeninfo verifies signature and token claims server-side; never trust decoded JWT fields alone.
        $claims = self::getJson(self::TOKENINFO_URL . '?' . http_build_query(['id_token' => $idToken], '', '&', PHP_QUERY_RFC3986));
        $aud = (string) ($claims['aud'] ?? '');
        $issuer = (string) ($claims['iss'] ?? '');
        $issued = (int) ($claims['iat'] ?? 0);
        $expires = (int) ($claims['exp'] ?? 0);
        if ($aud !== $clientId || !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || $issued > time() + 60 || $expires <= time() || $expires - $issued > 7200
            || (isset($claims['azp']) && (string) $claims['azp'] !== $clientId)
            || !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($claims['sub'] ?? '') === '') {
            self::redirect('/login?google=invalid');
        }

        try {
            Auth::googleLogin($claims);
        } catch (Throwable $e) {
            // Do not leak provider payloads, tokens, or account details to the browser.
            self::redirect('/login?google=failed');
        }
        self::redirect(self::safeNext((string) ($saved['next_path'] ?? '/account')));
    }

    private static function redirectUri(): string
    {
        return trim((string) (getenv('GOOGLE_REDIRECT_URI') ?: 'https://ffpanelstore.up.railway.app/auth/google/callback'));
    }

    private static function postForm(string $url, array $fields): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Google OAuth.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Google token exchange failed.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Google token response.');
        }
        return $data;
    }

    private static function getJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for Google OAuth.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Google ID-token verification failed.');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid Google ID-token response.');
        }
        return $data;
    }

    private static function safeNext(string $path): string
    {
        if ($path === '' || strlen($path) > 2000 || !str_starts_with($path, '/')
            || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path)) {
            return '/account';
        }
        return $path;
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function redirect(string $location): never
    {
        header('Cache-Control: no-store');
        header('Location: ' . $location, true, 302);
        exit;
    }

    private static function clearBrowserCookie(): void
    {
        setcookie(self::BROWSER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/auth',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
