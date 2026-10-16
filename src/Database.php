<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = trim((string) (getenv('DATABASE_PATH') ?: (__DIR__ . '/../shop_database.sqlite')));
        if (str_starts_with($path, 'sqlite:')) {
            $dsn = $path;
        } else {
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('The database directory could not be created.');
            }
            $dsn = 'sqlite:' . $path;
        }

        self::$pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA busy_timeout=30000');
        self::$pdo->exec('PRAGMA foreign_keys=ON');
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        return self::$pdo;
    }

    public static function initialize(): void
    {
        self::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS store_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    password_salt TEXT NOT NULL DEFAULT '',
    display_name TEXT NOT NULL DEFAULT '',
    disabled INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_login_at INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS store_sessions (
    token_hash TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL,
    csrf_token TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(account_id) REFERENCES store_accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_store_sessions_account ON store_sessions(account_id);
CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash TEXT PRIMARY KEY,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS store_checkouts (
    order_id TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL,
    product_key TEXT NOT NULL,
    product_id TEXT NOT NULL,
    product_name TEXT NOT NULL,
    duration TEXT NOT NULL,
    api_duration TEXT NOT NULL,
    amount_inr REAL NOT NULL,
    provider_order_id TEXT NOT NULL DEFAULT '',
    payment_url TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    transaction_id TEXT NOT NULL DEFAULT '',
    delivery_key TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(account_id) REFERENCES store_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_store_checkouts_account ON store_checkouts(account_id, created_at DESC);
CREATE TABLE IF NOT EXISTS store_orders (
    order_id TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL,
    product_key TEXT NOT NULL,
    product_name TEXT NOT NULL,
    duration TEXT NOT NULL,
    amount REAL NOT NULL,
    delivery_key TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    transaction_id TEXT NOT NULL DEFAULT '',
    FOREIGN KEY(account_id) REFERENCES store_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_store_orders_account ON store_orders(account_id, created_at DESC);
CREATE TABLE IF NOT EXISTS store_product_images (
    product_key TEXT PRIMARY KEY,
    content_type TEXT NOT NULL,
    filename TEXT NOT NULL,
    data BLOB NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS store_auth_attempts (
    identity TEXT PRIMARY KEY,
    hits INTEGER NOT NULL,
    window_start INTEGER NOT NULL
);
SQL);
    }

    public static function rows(string $sql, array $params = []): array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $row = self::row('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row === null ? $default : (string) $row['value'];
    }

    public static function saveSetting(string $key, string|int|float|bool $value): void
    {
        $statement = self::pdo()->prepare(
            'INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value]);
    }

    public static function deleteSetting(string $key): void
    {
        $statement = self::pdo()->prepare('DELETE FROM settings WHERE key = ?');
        $statement->execute([$key]);
    }
}
