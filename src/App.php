<?php
declare(strict_types=1);

final class App
{
    public static function run(): void
    {
        try {
            Database::initialize();
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('X-Frame-Options: DENY');
            self::dispatch();
        } catch (HttpError $e) {
            self::json(['error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            error_log('HEX CHEATS SHOP request failed: ' . get_class($e));
            self::json(['error' => 'The request could not be completed. Please try again.'], 500);
        }
    }

    private static function dispatch(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

        if ($method === 'GET' && $path === '/healthz') {
            self::json(['ok' => true]);
            return;
        }
        if ($method === 'GET' && ($path === '/' || $path === '/index.php')) {
            self::page('storefront.html');
            return;
        }
        if ($method === 'GET' && in_array($path, ['/admin', '/admin/'], true)) {
            self::page('admin_login.html');
            return;
        }
        if ($method === 'GET' && $path === '/admin/catalog') {
            self::page('catalog_admin.html');
            return;
        }
        if ($method === 'GET' && $path === '/admin/customers') {
            self::page('admin_customers.html');
            return;
        }
        if ($method === 'GET' && $path === '/api/store/config') {
            self::json(['shop_name' => 'HEX CHEATS SHOP', 'currency' => 'INR']);
            return;
        }
        if ($method === 'GET' && $path === '/api/store/products') {
            self::json(['products' => Catalog::publicProducts()]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/api/store/images/([a-z0-9_-]{2,60})$#i', $path, $match)) {
            self::productImage(strtolower($match[1]));
            return;
        }
        if ($method === 'GET' && $path === '/api/store/session') {
            $account = Auth::current();
            self::json($account ? ['authenticated' => true, 'account' => Auth::publicAccount($account), 'csrf_token' => $account['csrf_token']] : ['authenticated' => false]);
            return;
        }
        if ($method === 'POST' && $path === '/api/store/register') {
            self::json(Auth::register(self::body()), 201);
            return;
        }
        if ($method === 'POST' && $path === '/api/store/login') {
            self::json(Auth::login(self::body()));
            return;
        }
        if ($method === 'POST' && $path === '/api/store/logout') {
            self::json(Auth::logout());
            return;
        }
        if ($method === 'POST' && $path === '/api/store/checkout') {
            self::checkout();
            return;
        }
        if ($method === 'GET' && preg_match('#^/api/store/checkouts/([A-Za-z0-9_-]{4,80})$#', $path, $match)) {
            self::checkoutStatus($match[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/api/store/checkouts/([A-Za-z0-9_-]{4,80})/qr$#', $path, $match)) {
            self::checkoutQr($match[1]);
            return;
        }
        if ($method === 'GET' && $path === '/api/store/orders') {
            self::customerOrders();
            return;
        }

        if ($method === 'POST' && $path === '/admin/api/login') {
            self::json(Auth::adminLogin(self::body()));
            return;
        }
        if (str_starts_with($path, '/admin/api/')) {
            Auth::requireAdmin();
            if ($method === 'GET' && $path === '/admin/api/products') {
                self::json(['products' => Catalog::adminProducts()]);
                return;
            }
            if ($method === 'POST' && $path === '/admin/api/products') {
                $body = self::body();
                $key = self::validProductKey((string) ($body['key'] ?? ''));
                if (in_array($key, Catalog::keys(), true)) {
                    throw new HttpError('A product with that key already exists.', 409);
                }
                $product = Catalog::save($key, self::productPayload($body), true);
                self::json(['ok' => true, 'product' => $product], 201);
                return;
            }
            if ($method === 'GET' && $path === '/admin/api/store/accounts') {
                self::adminAccounts();
                return;
            }
            if ($method === 'GET' && $path === '/admin/api/store/orders') {
                self::adminOrders();
                return;
            }
            if (preg_match('#^/admin/api/products/([a-z0-9_-]{2,60})(/image)?$#i', $path, $match)) {
                $key = strtolower($match[1]);
                $isImage = ($match[2] ?? '') === '/image';
                if ($isImage && $method === 'POST') {
                    self::uploadImage($key);
                    return;
                }
                if ($isImage && $method === 'DELETE') {
                    Database::pdo()->prepare('DELETE FROM store_product_images WHERE product_key = ?')->execute([$key]);
                    self::json(['ok' => true]);
                    return;
                }
                if (!$isImage && $method === 'PUT') {
                    $body = self::body();
                    $product = Catalog::save($key, self::productPayload($body), false);
                    self::json(['ok' => true, 'product' => $product]);
                    return;
                }
                if (!$isImage && $method === 'DELETE') {
                    self::json(Catalog::delete($key));
                    return;
                }
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/accounts/(\d+)/status$#', $path, $match)) {
                self::setAccountStatus((int) $match[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/orders/([A-Za-z0-9_-]{4,80})/retry$#', $path, $match)) {
                self::retryDelivery($match[1]);
                return;
            }
            throw new HttpError('Admin API route not found.', 404);
        }
        throw new HttpError('Page or API route not found.', 404);
    }

    private static function page(string $name): void
    {
        $file = __DIR__ . '/../public/' . $name;
        if (!is_file($file)) {
            throw new HttpError('Page not found.', 404);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($file);
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new HttpError('A JSON request is required.', 400);
        }
        return $decoded;
    }

    private static function productPayload(array $body): array
    {
        return is_array($body['product'] ?? null) ? $body['product'] : $body;
    }

    private static function validProductKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9_-]{2,60}$/', $key)) {
            throw new HttpError('Product key must be 2–60 lowercase letters, numbers, underscores, or hyphens.', 400);
        }
        return $key;
    }

    private static function productImage(string $key): void
    {
        $row = Database::row('SELECT content_type,data,updated_at FROM store_product_images WHERE product_key = ?', [$key]);
        if (!$row) {
            throw new HttpError('Product image not found.', 404);
        }
        header('Content-Type: ' . $row['content_type']);
        header('Cache-Control: public, max-age=900');
        header('ETag: "' . (int) $row['updated_at'] . '"');
        echo $row['data'];
    }

    private static function uploadImage(string $key): void
    {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError('Select a product image to upload.', 400);
        }
        if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            throw new HttpError('Image must be no larger than 5 MB.', 400);
        }
        $bytes = file_get_contents((string) $file['tmp_name']);
        if (!is_string($bytes)) {
            throw new HttpError('The uploaded image could not be read.', 400);
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new HttpError('Upload a valid PNG, JPEG, or WebP image.', 400);
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > 16000000) {
            throw new HttpError('Image dimensions are invalid or too large.', 400);
        }
        $filename = basename((string) ($file['name'] ?? 'product-image'));
        $statement = Database::pdo()->prepare(
            'INSERT INTO store_product_images(product_key,content_type,filename,data,updated_at) VALUES(?,?,?,?,?) '
            . 'ON CONFLICT(product_key) DO UPDATE SET content_type=excluded.content_type,filename=excluded.filename,data=excluded.data,updated_at=excluded.updated_at'
        );
        $statement->bindValue(1, $key);
        $statement->bindValue(2, $mime);
        $statement->bindValue(3, substr($filename, 0, 255));
        $statement->bindValue(4, $bytes, PDO::PARAM_LOB);
        $statement->bindValue(5, time(), PDO::PARAM_INT);
        $statement->execute();
        self::json(['ok' => true, 'image_url' => '/api/store/images/' . rawurlencode($key)]);
    }

    private static function checkout(): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to place an order.', 401);
        }
        Auth::requireCsrf($account);
        $body = self::body();
        $key = trim((string) ($body['product_key'] ?? ''));
        $duration = trim((string) ($body['duration'] ?? ''));
        $product = Catalog::effective($key);
        if (empty($product['enabled']) || !empty($product['maintenance'])) {
            throw new HttpError('This product is currently unavailable.', 409);
        }
        if (!in_array($duration, $product['durations'] ?? [], true)) {
            throw new HttpError('Choose a valid product duration.', 400);
        }
        $amount = self::price((string) ($product['prices'][$duration] ?? ''));
        if ($amount === null) {
            throw new HttpError('The selected price is not configured.', 409);
        }
        $stock = $product['stock'][$duration] ?? null;
        if ($stock !== null && is_numeric(str_replace(',', '', (string) $stock)) && (float) str_replace(',', '', (string) $stock) <= 0) {
            throw new HttpError('This option is currently out of stock.', 409);
        }
        $orderId = 'WEB' . strtoupper(bin2hex(random_bytes(8)));
        try {
            $provider = Integrations::createPaymentOrder($amount, $orderId, (string) ($account['display_name'] ?: $account['email'] ?: 'Shop customer'));
        } catch (HttpError $e) {
            throw $e;
        } catch (Throwable) {
            throw new HttpError('The payment provider is temporarily unavailable.', 502);
        }
        $paymentUrl = Integrations::paymentUrl($provider);
        if ($paymentUrl !== '' && (!filter_var($paymentUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($paymentUrl, PHP_URL_SCHEME)) !== 'https')) {
            throw new HttpError('The payment provider returned an invalid checkout link.', 502);
        }
        $providerId = Integrations::providerOrderId($provider, $orderId);
        $now = time();
        $apiDuration = (string) ($product['api_duration'][$duration] ?? $duration);
        Database::pdo()->prepare(
            'INSERT INTO store_checkouts(order_id,account_id,product_key,product_id,product_name,duration,api_duration,amount_inr,provider_order_id,payment_url,status,created_at,updated_at) '
            . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $orderId, (int) $account['id'], $key, (string) ($product['id'] ?? ''), (string) ($product['name'] ?? $key),
            $duration, $apiDuration, $amount, $providerId, $paymentUrl, 'pending', $now, $now,
        ]);
        self::json([
            'ok' => true, 'order_id' => $orderId, 'product_name' => (string) ($product['name'] ?? $key),
            'payment_url' => $paymentUrl, 'qr_url' => '/api/store/checkouts/' . rawurlencode($orderId) . '/qr',
            'amount_inr' => $amount, 'status' => 'pending',
        ], 201);
    }

    private static function checkoutStatus(string $orderId): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to view this order.', 401);
        }
        $row = Database::row('SELECT * FROM store_checkouts WHERE order_id = ? AND account_id = ?', [$orderId, (int) $account['id']]);
        if (!$row) {
            throw new HttpError('Order not found.', 404);
        }
        if ($row['status'] === 'pending') {
            $response = null;
            foreach (array_unique(array_filter([(string) $row['provider_order_id'], (string) $row['order_id']])) as $candidate) {
                $response = Integrations::checkPaymentStatus($candidate);
                if ($response !== null) {
                    break;
                }
            }
            if ($response !== null) {
                $fields = Integrations::statusFields($response);
                if (self::paymentSuccessful($fields['status'])) {
                    $paid = self::price((string) ($fields['amount'] ?? ''));
                    if ($paid !== null && abs($paid - (float) $row['amount_inr'] * 100) < 0.01) {
                        $paid /= 100;
                    }
                    if ($paid !== null && abs($paid - (float) $row['amount_inr']) > 0.01) {
                        Database::pdo()->prepare("UPDATE store_checkouts SET status='amount_review',updated_at=? WHERE order_id=? AND status='pending'")->execute([time(), $orderId]);
                        $row['status'] = 'amount_review';
                    } elseif ($paid !== null) {
                        $outcome = self::deliverCheckout($row, $fields['transaction_id'] ?: $fields['utr']);
                        $row['status'] = $outcome['status'];
                        $row['delivery_key'] = $outcome['key'] ?? '';
                    }
                } elseif (self::paymentFailed($fields['status'])) {
                    Database::pdo()->prepare("UPDATE store_checkouts SET status='failed',updated_at=? WHERE order_id=? AND status='pending'")->execute([time(), $orderId]);
                    $row['status'] = 'failed';
                }
            }
        }
        self::json([
            'order_id' => $orderId, 'status' => (string) $row['status'],
            'delivery_key' => (string) ($row['delivery_key'] ?? ''), 'amount_inr' => (float) $row['amount_inr'],
            'product_name' => (string) $row['product_name'], 'duration' => (string) $row['duration'],
        ]);
    }

    private static function checkoutQr(string $orderId): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in required.', 401);
        }
        $row = Database::row('SELECT provider_order_id,order_id FROM store_checkouts WHERE order_id = ? AND account_id = ?', [$orderId, (int) $account['id']]);
        if (!$row) {
            throw new HttpError('Order not found.', 404);
        }
        try {
            $image = Integrations::qrImage((string) ($row['provider_order_id'] ?: $row['order_id']));
        } catch (Throwable) {
            throw new HttpError('Payment QR code is not available.', 404);
        }
        header('Content-Type: ' . $image['content_type']);
        header('Cache-Control: no-store');
        echo $image['body'];
    }

    private static function customerOrders(): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to view your orders.', 401);
        }
        $rows = Database::rows(
            "SELECT order_id,product_name,duration,amount,status,delivery_key,created_at,transaction_id FROM store_orders WHERE account_id=? "
            . "UNION ALL SELECT order_id,product_name,duration,amount_inr AS amount,status,'' AS delivery_key,created_at,transaction_id FROM store_checkouts WHERE account_id=? AND status!='complete' "
            . 'ORDER BY created_at DESC LIMIT 100',
            [(int) $account['id'], (int) $account['id']]
        );
        self::json(['orders' => $rows]);
    }

    private static function adminAccounts(): void
    {
        $query = trim(substr((string) ($_GET['q'] ?? ''), 0, 120));
        if ($query !== '') {
            $like = '%' . strtolower($query) . '%';
            $rows = Database::rows(
                'SELECT id,email,display_name,disabled,created_at,last_login_at FROM store_accounts WHERE lower(email) LIKE ? OR lower(display_name) LIKE ? ORDER BY created_at DESC LIMIT 300',
                [$like, $like]
            );
        } else {
            $rows = Database::rows('SELECT id,email,display_name,disabled,created_at,last_login_at FROM store_accounts ORDER BY created_at DESC LIMIT 300');
        }
        self::json(['accounts' => $rows]);
    }

    private static function setAccountStatus(int $accountId): void
    {
        $body = self::body();
        $disabled = !empty($body['disabled']) ? 1 : 0;
        $statement = Database::pdo()->prepare('UPDATE store_accounts SET disabled = ? WHERE id = ?');
        $statement->execute([$disabled, $accountId]);
        if ($statement->rowCount() < 1) {
            throw new HttpError('Account not found.', 404);
        }
        if ($disabled) {
            Database::pdo()->prepare('DELETE FROM store_sessions WHERE account_id = ?')->execute([$accountId]);
        }
        self::json(['ok' => true, 'disabled' => (bool) $disabled]);
    }

    private static function adminOrders(): void
    {
        $rows = Database::rows(
            'SELECT c.order_id,c.account_id,a.email,c.product_name,c.duration,c.amount_inr AS amount,c.status,c.delivery_key,c.transaction_id,c.created_at,c.updated_at '
            . 'FROM store_checkouts c LEFT JOIN store_accounts a ON a.id=c.account_id ORDER BY c.created_at DESC LIMIT 300'
        );
        self::json(['orders' => $rows]);
    }

    private static function retryDelivery(string $orderId): void
    {
        $row = Database::row('SELECT * FROM store_checkouts WHERE order_id = ?', [$orderId]);
        if (!$row) {
            throw new HttpError('Order not found.', 404);
        }
        if ((string) $row['status'] !== 'paid_delivery_failed') {
            throw new HttpError('Only paid orders with a failed delivery can be retried.', 409);
        }
        $result = self::deliverCheckout($row, (string) $row['transaction_id']);
        self::json($result);
    }

    private static function deliverCheckout(array $order, string $transactionId): array
    {
        $orderId = (string) $order['order_id'];
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $fresh = Database::row('SELECT * FROM store_checkouts WHERE order_id = ?', [$orderId]);
            if (!$fresh) {
                $pdo->exec('ROLLBACK');
                return ['status' => 'not_found'];
            }
            if ($fresh['status'] === 'complete') {
                $pdo->exec('COMMIT');
                return ['status' => 'complete', 'key' => (string) $fresh['delivery_key']];
            }
            if ($fresh['status'] === 'delivering') {
                $pdo->exec('COMMIT');
                return ['status' => 'delivering'];
            }
            if (!in_array((string) $fresh['status'], ['pending', 'paid_delivery_failed'], true)) {
                $status = (string) $fresh['status'];
                $pdo->exec('COMMIT');
                return ['status' => $status];
            }
            $pdo->prepare("UPDATE store_checkouts SET status='delivering',transaction_id=?,updated_at=? WHERE order_id=?")
                ->execute([$transactionId, time(), $orderId]);
            $order = $fresh;
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        try {
            $result = Integrations::buyProduct((string) $order['product_id'], (string) $order['api_duration']);
            $status = strtolower((string) ($result['status'] ?? ''));
            $key = trim((string) ($result['key'] ?? ''));
            if (str_starts_with($key, 'Key: ')) {
                $key = trim(substr($key, 5));
            }
            if ($status !== 'success' || $key === '') {
                throw new RuntimeException('Reseller delivery was not confirmed.');
            }
        } catch (Throwable) {
            $pdo->prepare("UPDATE store_checkouts SET status='paid_delivery_failed',updated_at=? WHERE order_id=? AND status='delivering'")->execute([time(), $orderId]);
            return ['status' => 'paid_delivery_failed'];
        }
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->prepare("UPDATE store_checkouts SET status='complete',delivery_key=?,transaction_id=?,updated_at=? WHERE order_id=?")
                ->execute([$key, $transactionId, $now, $orderId]);
            $pdo->prepare(
                'INSERT OR IGNORE INTO store_orders(order_id,account_id,product_key,product_name,duration,amount,delivery_key,status,created_at,transaction_id) VALUES(?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $orderId, (int) $order['account_id'], (string) $order['product_key'], (string) $order['product_name'],
                (string) $order['duration'], (float) $order['amount_inr'], $key, 'complete', $now, $transactionId,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        return ['status' => 'complete', 'key' => $key];
    }

    private static function paymentSuccessful(string $status): bool
    {
        return in_array($status, ['success', 'successful', 'paid', 'completed', 'verified', 'captured'], true)
            || str_contains($status, 'paid') || str_contains($status, 'success') || str_contains($status, 'completed')
            || str_contains($status, 'verified') || str_contains($status, 'captured');
    }

    private static function paymentFailed(string $status): bool
    {
        return in_array($status, ['failed', 'failure', 'expired', 'cancelled', 'canceled'], true);
    }

    private static function price(string $raw): ?float
    {
        $text = preg_replace('/[₹$,\s]/u', '', $raw) ?? '';
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        $amount = (float) $text;
        return is_finite($amount) && $amount > 0 && $amount <= 1000000 ? $amount : null;
    }

    private static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
