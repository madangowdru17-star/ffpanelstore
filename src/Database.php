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
    google_sub TEXT NOT NULL DEFAULT '',
    disabled INTEGER NOT NULL DEFAULT 0,
    balance_inr REAL NOT NULL DEFAULT 0,
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
CREATE TABLE IF NOT EXISTS store_google_oauth_states (
    state_hash TEXT PRIMARY KEY,
    code_verifier TEXT NOT NULL,
    browser_hash TEXT NOT NULL DEFAULT '',
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash TEXT PRIMARY KEY,
    csrf_token TEXT NOT NULL DEFAULT '',
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
    idempotency_key TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    transaction_id TEXT NOT NULL DEFAULT '',
    delivery_key TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(account_id) REFERENCES store_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_store_checkouts_account ON store_checkouts(account_id, created_at DESC);
CREATE TABLE IF NOT EXISTS store_checkout_crypto_proofs (
    order_id TEXT PRIMARY KEY,
    payment_method TEXT NOT NULL,
    amount_usd REAL NOT NULL,
    exchange_rate_inr REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'manual_pending',
    proof_txid TEXT NOT NULL DEFAULT '',
    proof_data BLOB,
    proof_content_type TEXT NOT NULL DEFAULT '',
    proof_filename TEXT NOT NULL DEFAULT '',
    reviewed_at INTEGER NOT NULL DEFAULT 0,
    review_note TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(order_id) REFERENCES store_checkouts(order_id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_store_checkout_crypto_txid ON store_checkout_crypto_proofs(proof_txid) WHERE proof_txid != '';
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
CREATE TABLE IF NOT EXISTS store_catalog_products (
    product_key TEXT PRIMARY KEY,
    is_builtin INTEGER NOT NULL DEFAULT 0,
    data_json TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS store_admin_users (
    email TEXT PRIMARY KEY COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    disabled INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_login_at INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS store_home_banners (
    slot INTEGER PRIMARY KEY CHECK(slot BETWEEN 1 AND 3),
    title TEXT NOT NULL DEFAULT '',
    subtitle TEXT NOT NULL DEFAULT '',
    cta_label TEXT NOT NULL DEFAULT '',
    cta_url TEXT NOT NULL DEFAULT '#catalog',
    content_type TEXT NOT NULL DEFAULT '',
    filename TEXT NOT NULL DEFAULT '',
    image BLOB,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS store_payment_assets (
    kind TEXT PRIMARY KEY,
    content_type TEXT NOT NULL,
    filename TEXT NOT NULL,
    data BLOB NOT NULL,
    updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS store_fulfillment_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id TEXT NOT NULL,
    attempt_no INTEGER NOT NULL,
    request_id TEXT NOT NULL,
    status TEXT NOT NULL,
    provider_status TEXT NOT NULL DEFAULT '',
    error_message TEXT NOT NULL DEFAULT '',
    started_at INTEGER NOT NULL,
    completed_at INTEGER NOT NULL DEFAULT 0,
    UNIQUE(order_id, attempt_no)
);
CREATE INDEX IF NOT EXISTS idx_fulfillment_attempts_order ON store_fulfillment_attempts(order_id, attempt_no DESC);
CREATE TABLE IF NOT EXISTS store_auth_attempts (
    identity TEXT PRIMARY KEY,
    hits INTEGER NOT NULL,
    window_start INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS store_wallet_topups (
    order_id TEXT PRIMARY KEY,
    account_id INTEGER NOT NULL,
    amount_inr REAL NOT NULL,
    provider_order_id TEXT NOT NULL DEFAULT '',
    payment_url TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    transaction_id TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(account_id) REFERENCES store_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_store_wallet_topups_account ON store_wallet_topups(account_id, created_at DESC);
CREATE TABLE IF NOT EXISTS store_wallet_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    kind TEXT NOT NULL,
    amount_inr REAL NOT NULL,
    balance_after REAL NOT NULL,
    reference_id TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(account_id) REFERENCES store_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_store_wallet_transactions_account ON store_wallet_transactions(account_id, id DESC);
SQL);
        $columns = self::rows('PRAGMA table_info(store_accounts)');
        $hasBalance = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'balance_inr') {
                $hasBalance = true;
                break;
            }
        }
        if (!$hasBalance) {
            self::pdo()->exec('ALTER TABLE store_accounts ADD COLUMN balance_inr REAL NOT NULL DEFAULT 0');
        }
        $accountColumns = self::rows('PRAGMA table_info(store_accounts)');
        $hasGoogleSub = false;
        foreach ($accountColumns as $column) {
            if (($column['name'] ?? '') === 'google_sub') {
                $hasGoogleSub = true;
                break;
            }
        }
        if (!$hasGoogleSub) {
            self::pdo()->exec("ALTER TABLE store_accounts ADD COLUMN google_sub TEXT NOT NULL DEFAULT ''");
        }
        $oauthColumns = self::rows('PRAGMA table_info(store_google_oauth_states)');
        $hasBrowserHash = false;
        foreach ($oauthColumns as $column) {
            if (($column['name'] ?? '') === 'browser_hash') {
                $hasBrowserHash = true;
                break;
            }
        }
        if (!$hasBrowserHash) {
            self::pdo()->exec("ALTER TABLE store_google_oauth_states ADD COLUMN browser_hash TEXT NOT NULL DEFAULT ''");
        }
        $hasNextPath = false;
        foreach (self::rows('PRAGMA table_info(store_google_oauth_states)') as $column) {
            if (($column['name'] ?? '') === 'next_path') {
                $hasNextPath = true;
                break;
            }
        }
        if (!$hasNextPath) {
            self::pdo()->exec("ALTER TABLE store_google_oauth_states ADD COLUMN next_path TEXT NOT NULL DEFAULT '/account'");
        }
        self::pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_accounts_google_sub ON store_accounts(google_sub) WHERE google_sub != ''");
        self::pdo()->exec('DELETE FROM store_google_oauth_states WHERE expires_at < ' . time());
        $adminSessionColumns = array_fill_keys(array_map(static fn(array $column): string => (string) $column['name'], self::rows('PRAGMA table_info(admin_sessions)')), true);
        if (!isset($adminSessionColumns['csrf_token'])) {
            self::pdo()->exec("ALTER TABLE admin_sessions ADD COLUMN csrf_token TEXT NOT NULL DEFAULT ''");
        }
        $checkoutColumns = self::rows('PRAGMA table_info(store_checkouts)');
        $hasIdempotencyKey = false;
        foreach ($checkoutColumns as $column) {
            if (($column['name'] ?? '') === 'idempotency_key') {
                $hasIdempotencyKey = true;
                break;
            }
        }
        if (!$hasIdempotencyKey) {
            self::pdo()->exec("ALTER TABLE store_checkouts ADD COLUMN idempotency_key TEXT NOT NULL DEFAULT ''");
        }
        self::pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_checkout_idempotency ON store_checkouts(account_id,idempotency_key) WHERE idempotency_key != ''");
        $topupColumns = self::rows('PRAGMA table_info(store_wallet_topups)');
        $existingTopupColumns = array_fill_keys(array_map(static fn(array $column): string => (string) $column['name'], $topupColumns), true);
        $topupMigrations = [
            'idempotency_key' => "TEXT NOT NULL DEFAULT ''",
            'payment_method' => "TEXT NOT NULL DEFAULT 'upi'",
            'amount_usd' => 'REAL NOT NULL DEFAULT 0',
            'exchange_rate_inr' => 'REAL NOT NULL DEFAULT 0',
            'proof_data' => 'BLOB',
            'proof_content_type' => "TEXT NOT NULL DEFAULT ''",
            'proof_filename' => "TEXT NOT NULL DEFAULT ''",
            'proof_txid' => "TEXT NOT NULL DEFAULT ''",
            'reviewed_at' => 'INTEGER NOT NULL DEFAULT 0',
            'review_note' => "TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($topupMigrations as $column => $definition) {
            if (!isset($existingTopupColumns[$column])) {
                self::pdo()->exec('ALTER TABLE store_wallet_topups ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
        self::pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_wallet_topup_idempotency ON store_wallet_topups(account_id,idempotency_key) WHERE idempotency_key != ''");
        self::pdo()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_store_wallet_topups_proof_txid ON store_wallet_topups(proof_txid) WHERE proof_txid != ''");
        self::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS store_payment_txid_claims (
    txid_hash TEXT PRIMARY KEY,
    owner_type TEXT NOT NULL,
    order_id TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
SQL);
        $claim = self::pdo()->prepare('INSERT OR IGNORE INTO store_payment_txid_claims(txid_hash,owner_type,order_id,created_at) VALUES(?,?,?,?)');
        foreach (self::rows("SELECT proof_txid,order_id,created_at FROM store_checkout_crypto_proofs WHERE trim(proof_txid) != ''") as $proof) {
            $claim->execute([hash('sha256', strtolower(trim((string) $proof['proof_txid']))), 'checkout', (string) $proof['order_id'], (int) $proof['created_at']]);
        }
        foreach (self::rows("SELECT proof_txid,order_id,created_at FROM store_wallet_topups WHERE trim(proof_txid) != ''") as $proof) {
            $claim->execute([hash('sha256', strtolower(trim((string) $proof['proof_txid']))), 'wallet', (string) $proof['order_id'], (int) $proof['created_at']]);
        }
    }

    public static function reservePaymentTxid(string $txid, string $ownerType, string $orderId): void
    {
        $normalized = strtolower(trim($txid));
        if ($normalized === '' || !in_array($ownerType, ['wallet', 'checkout'], true)) {
            throw new HttpError('Invalid payment transaction reference.', 400);
        }
        try {
            self::pdo()->prepare(
                'INSERT INTO store_payment_txid_claims(txid_hash,owner_type,order_id,created_at) VALUES(?,?,?,?)'
            )->execute([hash('sha256', $normalized), $ownerType, $orderId, time()]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 19) {
                throw new HttpError('That crypto transaction ID has already been submitted.', 409);
            }
            throw $e;
        }
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
