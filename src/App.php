<?php
declare(strict_types=1);

final class App
{
    public static function run(): void
    {
        try {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('X-Frame-Options: DENY');
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
            if ($method === 'GET' && $path === '/healthz') {
                self::json(['ok' => true]);
                return;
            }
            if ($method === 'GET' && $path === '/readyz') {
                Database::initialize();
                Database::pdo()->query('SELECT 1')->fetchColumn();
                self::json(['ok' => true, 'database' => 'ready']);
                return;
            }
            Database::initialize();
            Homepage::seedDefaultPaymentAssets();
            Catalog::ensureSeeded();
            Auth::bootstrapAdmin();
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

        if ($method === 'GET' && $path === '/auth/google') {
            GoogleOAuth::start();
        }
        if ($method === 'GET' && $path === '/auth/google/callback') {
            GoogleOAuth::callback();
        }
        $customerPages = [
            '/login' => 'pages/login.php', '/register' => 'pages/register.php',
            '/wallet' => 'pages/wallet.php', '/add-funds' => 'pages/add-funds.php',
            '/orders' => 'pages/orders.php', '/keys' => 'pages/keys.php',
            '/account' => 'pages/account.php', '/help' => 'pages/help.php',
            '/terms' => 'pages/terms.php', '/admin/dashboard' => 'pages/admin-dashboard.php',
            '/admin/payments' => 'pages/admin-binance.php', '/admin/customers' => 'admin_customers.php',
            '/admin/catalog' => 'catalog_admin.php', '/admin/homepage' => 'homepage_admin.php',
        ];
        if ($method === 'GET' && isset($customerPages[$path])) {
            self::page($customerPages[$path]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/product/([a-z0-9_-]{2,60})$#i', $path, $match)) {
            $_GET['product_key'] = strtolower($match[1]);
            self::page('pages/product.php');
            return;
        }

        if ($method === 'GET' && $path === '/healthz') {
            self::json(['ok' => true]);
            return;
        }
        if ($method === 'GET' && ($path === '/' || $path === '/index.php')) {
            self::page('storefront.php');
            return;
        }
        if ($method === 'GET' && in_array($path, ['/admin', '/admin/'], true)) {
            self::page('admin_login.php');
            return;
        }
        if ($method === 'GET' && $path === '/admin/catalog') {
            self::page('catalog_admin.php');
            return;
        }
        if ($method === 'GET' && $path === '/admin/customers') {
            self::page('admin_customers.php');
            return;
        }
        if ($method === 'GET' && $path === '/admin/homepage') {
            self::page('homepage_admin.php');
            return;
        }
        if ($method === 'GET' && $path === '/api/store/config') {
            self::json(array_merge(Homepage::publicConfig(), ['currency' => 'INR', 'display_currencies' => ['INR', 'USD'], 'settlement_currency' => 'INR']));
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
        if ($method === 'GET' && preg_match('#^/api/store/banners/([1-3])/image$#', $path, $match)) {
            Homepage::bannerImage((int) $match[1]);
            return;
        }
        if ($method === 'GET' && $path === '/api/store/payments/binance/qr') {
            Homepage::binanceQr();
            return;
        }
        if ($method === 'GET' && $path === '/api/store/payments/usdt/qr') {
            Homepage::usdtQr();
            return;
        }
        if ($method === 'GET' && $path === '/api/store/session') {
            $account = Auth::current();
            self::json($account ? ['authenticated' => true, 'account' => Auth::publicAccount($account), 'csrf_token' => $account['csrf_token']] : ['authenticated' => false]);
            return;
        }
        if (in_array($method, ['GET', 'PUT'], true) && $path === '/api/store/profile') {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to view your profile.', 401);
            }
            if ($method === 'PUT') {
                self::json(Auth::updateProfile($account, self::body()));
            } else {
                self::json(['account' => Auth::publicAccount($account)]);
            }
            return;
        }
        if ($method === 'GET' && $path === '/api/store/wallet') {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to view your wallet.', 401);
            }
            self::json(Wallet::snapshot((int) $account['id']));
            return;
        }
        if ($method === 'POST' && $path === '/api/store/wallet/topups') {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to top up your wallet.', 401);
            }
            Auth::requireCsrf($account);
            self::json(Wallet::createTopup((int) $account['id'], self::body()), 201);
            return;
        }
        if ($method === 'POST' && $path === '/api/store/wallet/binance') {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to create a Binance top-up.', 401);
            }
            Auth::requireCsrf($account);
            self::json(Wallet::createBinanceTopup((int) $account['id'], self::body()), 201);
            return;
        }
        if ($method === 'POST' && $path === '/api/store/wallet/usdt') {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to create a USDT top-up.', 401);
            }
            Auth::requireCsrf($account);
            self::json(Wallet::createUsdtTopup((int) $account['id'], self::body()), 201);
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/store/wallet/topups/([A-Za-z0-9_-]{4,80})/proof$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to submit payment proof.', 401);
            }
            Auth::requireCsrf($account);
            self::json(Wallet::submitBinanceProof((int) $account['id'], $match[1]));
            return;
        }
        if ($method === 'GET' && preg_match('#^/api/store/wallet/topups/([A-Za-z0-9_-]{4,80})/qr$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in required.', 401);
            }
            $image = Wallet::topupQr((int) $account['id'], $match[1]);
            header('Content-Type: ' . $image['content_type']);
            header('Cache-Control: no-store');
            echo $image['body'];
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/store/wallet/topups/([A-Za-z0-9_-]{4,80})/verify$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to verify payment status.', 401);
            }
            Auth::requireCsrf($account);
            self::json(Wallet::topupStatus((int) $account['id'], $match[1], true));
            return;
        }
        if ($method === 'GET' && preg_match('#^/api/store/wallet/topups/([A-Za-z0-9_-]{4,80})$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in required.', 401);
            }
            self::json(Wallet::topupStatus((int) $account['id'], $match[1]));
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
        if ($method === 'POST' && $path === '/api/store/checkout/crypto') {
            self::checkoutCrypto();
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/store/checkouts/([A-Za-z0-9_-]{4,80})/proof$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to submit payment proof.', 401);
            }
            Auth::requireCsrf($account);
            self::submitCryptoCheckoutProof($match[1], (int) $account['id']);
            return;
        }
        if ($method === 'POST' && $path === '/api/store/checkout/wallet') {
            self::checkoutFromWallet();
            return;
        }
        if ($method === 'POST' && preg_match('#^/api/store/checkouts/([A-Za-z0-9_-]{4,80})/verify$#', $path, $match)) {
            $account = Auth::current();
            if (!$account) {
                throw new HttpError('Sign in to verify order status.', 401);
            }
            Auth::requireCsrf($account);
            self::checkoutStatus($match[1], true);
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
        if ($method === 'POST' && $path === '/admin/api/logout') {
            Auth::requireAdmin();
            self::json(Auth::adminLogout());
            return;
        }
        if (str_starts_with($path, '/admin/api/')) {
            Auth::requireAdmin();
            if ($method === 'GET' && $path === '/admin/api/products') {
                self::json(['products' => Catalog::adminProducts()]);
                return;
            }
            if ($method === 'GET' && $path === '/admin/api/homepage') {
                self::json(['homepage' => Homepage::adminConfig()]);
                return;
            }
            if ($method === 'PUT' && $path === '/admin/api/homepage') {
                self::json(Homepage::updateSettings(self::body()));
                return;
            }
            if ($method === 'PUT' && $path === '/admin/api/payments/binance') {
                self::json(Homepage::updateBinanceSettings(self::body()));
                return;
            }
            if ($method === 'POST' && $path === '/admin/api/payments/binance/qr') {
                self::json(Homepage::uploadBinanceQr());
                return;
            }
            if ($method === 'DELETE' && $path === '/admin/api/payments/binance/qr') {
                Homepage::deleteBinanceQr();
                self::json(['ok' => true]);
                return;
            }
            if ($method === 'PUT' && $path === '/admin/api/payments/usdt') {
                self::json(Homepage::updateUsdtSettings(self::body()));
                return;
            }
            if ($method === 'POST' && $path === '/admin/api/payments/usdt/qr') {
                self::json(Homepage::uploadUsdtQr());
                return;
            }
            if ($method === 'DELETE' && $path === '/admin/api/payments/usdt/qr') {
                Homepage::deleteUsdtQr();
                self::json(['ok' => true]);
                return;
            }
            if ($method === 'POST' && $path === '/admin/api/admin/password') {
                self::json(Auth::changeAdminPassword(self::body()));
                return;
            }
            if (preg_match('#^/admin/api/banners/([1-3])(/image)?$#', $path, $match)) {
                $slot = (int) $match[1];
                $isImage = ($match[2] ?? '') === '/image';
                if ($isImage && $method === 'POST') {
                    self::json(Homepage::uploadBanner($slot));
                    return;
                }
                if ($isImage && $method === 'DELETE') {
                    Homepage::deleteBannerImage($slot);
                    self::json(['ok' => true]);
                    return;
                }
                if (!$isImage && $method === 'PUT') {
                    self::json(Homepage::updateBanner($slot, self::body()));
                    return;
                }
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
            if ($method === 'GET' && $path === '/admin/api/store/wallet/binance') {
                self::json(['payments' => Wallet::adminBinanceTopups()]);
                return;
            }
            if ($method === 'GET' && $path === '/admin/api/store/wallet/crypto') {
                self::json(['payments' => Wallet::adminCryptoTopups()]);
                return;
            }
            if ($method === 'GET' && preg_match('#^/admin/api/store/wallet/crypto/([A-Za-z0-9_-]{4,80})/proof$#', $path, $match)) {
                $image = Wallet::adminCryptoProof($match[1]);
                header('Content-Type: ' . $image['content_type']);
                header('Cache-Control: no-store');
                header('Content-Disposition: inline');
                header('X-Content-Type-Options: nosniff');
                echo $image['body'];
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/wallet/crypto/([A-Za-z0-9_-]{4,80})/review$#', $path, $match)) {
                self::json(Wallet::reviewCryptoTopup($match[1], self::body()));
                return;
            }
            if ($method === 'GET' && $path === '/admin/api/store/checkout-crypto') {
                self::adminCryptoCheckouts();
                return;
            }
            if ($method === 'GET' && preg_match('#^/admin/api/store/checkout-crypto/([A-Za-z0-9_-]{4,80})/proof$#', $path, $match)) {
                $image = self::adminCryptoCheckoutProof($match[1]);
                header('Content-Type: ' . $image['content_type']);
                header('Cache-Control: no-store');
                header('Content-Disposition: inline');
                header('X-Content-Type-Options: nosniff');
                echo $image['body'];
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/checkout-crypto/([A-Za-z0-9_-]{4,80})/review$#', $path, $match)) {
                self::reviewCryptoCheckout($match[1], self::body());
                return;
            }
            if ($method === 'GET' && preg_match('#^/admin/api/store/wallet/binance/([A-Za-z0-9_-]{4,80})/proof$#', $path, $match)) {
                $image = Wallet::adminBinanceProof($match[1]);
                header('Content-Type: ' . $image['content_type']);
                header('Cache-Control: no-store');
                header('Content-Disposition: inline');
                header('X-Content-Type-Options: nosniff');
                echo $image['body'];
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/wallet/binance/([A-Za-z0-9_-]{4,80})/review$#', $path, $match)) {
                self::json(Wallet::reviewBinanceTopup($match[1], self::body()));
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
            if ($method === 'POST' && preg_match('#^/admin/api/store/accounts/(\d+)/wallet$#', $path, $match)) {
                self::adjustAccountWallet((int) $match[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/orders/([A-Za-z0-9_-]{4,80})/retry$#', $path, $match)) {
                self::retryDelivery($match[1]);
                return;
            }
            if ($method === 'POST' && preg_match('#^/admin/api/store/orders/([A-Za-z0-9_-]{4,80})/manual-key$#', $path, $match)) {
                self::saveManualDeliveryKey($match[1]);
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
        ob_start();
        require $file;
        $html = (string) ob_get_clean();
        $headAssets = '<script>(function(){try{var t=localStorage.getItem("hex_shop_theme");document.documentElement.dataset.theme=(t==="dark"||t==="light")?t:"light"}catch(e){document.documentElement.dataset.theme="light"}})();</script><link rel="stylesheet" href="/assets/css/theme.css?v=11">';
        $bodyAssets = '<script src="/assets/js/theme.js?v=7" defer></script><script src="/assets/js/spa.js?v=1" defer></script>';
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $headAssets . '</head>', $html, 1) ?? $html;
        }
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $bodyAssets . '</body>', $html, 1) ?? $html;
        }
        echo $html;
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
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new HttpError('Checkout session expired. Please start the purchase again.', 400);
        }
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
        $apiDuration = (string) ($product['api_duration'][$duration] ?? $duration);
        if (Integrations::paymentKey() === '') {
            throw new HttpError('Online payments are not configured yet. Please contact the shop administrator.', 503);
        }
        $pdo = Database::pdo();
        $orderId = 'WEB' . strtoupper(bin2hex(random_bytes(8)));
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = Database::row('SELECT * FROM store_checkouts WHERE account_id=? AND idempotency_key=?', [(int) $account['id'], $idempotencyKey]);
            if ($existing) {
                $hasCryptoProof = Database::row('SELECT order_id FROM store_checkout_crypto_proofs WHERE order_id=?', [(string) $existing['order_id']]);
                if ((string) $existing['product_key'] !== $key || (string) $existing['duration'] !== $duration
                    || abs((float) $existing['amount_inr'] - $amount) > 0.001 || $hasCryptoProof
                    || (string) $existing['transaction_id'] === 'WALLET') {
                    throw new HttpError('This checkout key was already used for different purchase details. Start checkout again.', 409);
                }
                $pdo->exec('COMMIT');
                if ((string) $existing['status'] !== 'pending') {
                    throw new HttpError('This checkout is already processing or complete. Check its status before starting another payment.', 409);
                }
                self::json([
                    'ok' => true, 'order_id' => (string) $existing['order_id'], 'product_name' => (string) $existing['product_name'],
                    'payment_url' => (string) $existing['payment_url'], 'qr_url' => '/api/store/checkouts/' . rawurlencode((string) $existing['order_id']) . '/qr',
                    'amount_inr' => (float) $existing['amount_inr'], 'status' => 'pending', 'replayed' => true,
                ], 200);
                return;
            }
            $now = time();
            $pdo->prepare(
                'INSERT INTO store_checkouts(order_id,account_id,product_key,product_id,product_name,duration,api_duration,amount_inr,provider_order_id,payment_url,idempotency_key,status,created_at,updated_at) '
                . "VALUES(?,?,?,?,?,?,?,?,'','',?,'initiating',?,?)"
            )->execute([
                $orderId, (int) $account['id'], $key, (string) ($product['id'] ?? ''), (string) ($product['name'] ?? $key),
                $duration, $apiDuration, $amount, $idempotencyKey, $now, $now,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        try {
            $provider = Integrations::createPaymentOrder($amount, $orderId, (string) ($account['display_name'] ?: $account['email'] ?: 'Shop customer'));
            $paymentUrl = Integrations::paymentUrl($provider);
            if ($paymentUrl !== '' && (!filter_var($paymentUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($paymentUrl, PHP_URL_SCHEME)) !== 'https')) {
                throw new RuntimeException('The payment provider returned an invalid checkout link.');
            }
            $providerId = Integrations::providerOrderId($provider, $orderId);
        } catch (Throwable) {
            $pdo->prepare("UPDATE store_checkouts SET status='provider_unknown',updated_at=? WHERE order_id=? AND status='initiating'")
                ->execute([time(), $orderId]);
            throw new HttpError('The provider could not confirm whether checkout was created. Do not retry payment; contact the shop administrator with reference ' . $orderId . '.', 502);
        }
        $pdo->prepare("UPDATE store_checkouts SET provider_order_id=?,payment_url=?,status='pending',updated_at=? WHERE order_id=? AND status='initiating'")
            ->execute([$providerId, $paymentUrl, time(), $orderId]);
        self::json([
            'ok' => true, 'order_id' => $orderId, 'product_name' => (string) ($product['name'] ?? $key),
            'payment_url' => $paymentUrl, 'qr_url' => '/api/store/checkouts/' . rawurlencode($orderId) . '/qr',
            'amount_inr' => $amount, 'status' => 'pending',
        ], 201);
    }

    private static function checkoutCrypto(): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to place an order.', 401);
        }
        Auth::requireCsrf($account);
        $body = self::body();
        $key = strtolower(trim((string) ($body['product_key'] ?? '')));
        $duration = trim((string) ($body['duration'] ?? ''));
        $method = strtolower(trim((string) ($body['payment_method'] ?? '')));
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{2,60}$/', $key)) {
            throw new HttpError('Choose a valid product.', 400);
        }
        if (!in_array($method, ['binance', 'usdt_bep20'], true)) {
            throw new HttpError('Choose Binance Pay USD or USDT on BNB Smart Chain (BEP20).', 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new HttpError('Checkout session expired. Please start the purchase again.', 400);
        }
        $product = Catalog::effective($key);
        if (empty($product['enabled']) || !empty($product['maintenance'])) {
            throw new HttpError('This product is currently unavailable.', 409);
        }
        if (!in_array($duration, $product['durations'] ?? [], true)) {
            throw new HttpError('Choose a valid product duration.', 400);
        }
        $amount = self::price((string) ($product['prices'][$duration] ?? ''));
        if ($amount === null || $amount <= 0) {
            throw new HttpError('The selected INR price is not configured.', 409);
        }
        $stock = $product['stock'][$duration] ?? null;
        if ($stock !== null && is_numeric(str_replace(',', '', (string) $stock)) && (float) str_replace(',', '', (string) $stock) <= 0) {
            throw new HttpError('This option is currently out of stock.', 409);
        }
        $config = $method === 'usdt_bep20' ? Homepage::publicUsdtConfig() : Homepage::publicBinanceConfig();
        if (empty($config['available'])) {
            throw new HttpError('This crypto payment option is not available right now.', 503);
        }
        $rate = Wallet::cryptoExchangeRateInr();
        $amountUsd = round($amount / $rate, 2);
        if ($amountUsd < 0.01 || $amountUsd > 100) {
            throw new HttpError('This product price is outside the supported crypto checkout range (0.01–100 USD equivalent).', 400);
        }
        $pdo = Database::pdo();
        $now = time();
        $orderId = 'CRY' . strtoupper(bin2hex(random_bytes(8)));
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = Database::row('SELECT * FROM store_checkouts WHERE account_id=? AND idempotency_key=?', [(int) $account['id'], $idempotencyKey]);
            if ($existing) {
                $proof = Database::row('SELECT payment_method,amount_usd,exchange_rate_inr,status FROM store_checkout_crypto_proofs WHERE order_id=?', [(string) $existing['order_id']]);
                if (!$proof || (string) $proof['payment_method'] !== $method
                    || (string) $existing['product_key'] !== $key || (string) $existing['duration'] !== $duration
                    || abs((float) $existing['amount_inr'] - $amount) > 0.001) {
                    throw new HttpError('This checkout session is already in use. Start the purchase again.', 409);
                }
                $pdo->exec('COMMIT');
                self::json([
                    'ok' => true, 'order_id' => (string) $existing['order_id'], 'product_name' => (string) $existing['product_name'],
                    'duration' => (string) $existing['duration'], 'amount_inr' => (float) $existing['amount_inr'],
                    'amount_usd' => (float) $proof['amount_usd'], 'exchange_rate_inr' => (float) $proof['exchange_rate_inr'],
                    'payment_method' => $method, 'qr_url' => (string) $config['qr_url'], 'instructions' => (string) $config['instructions'],
                    'status' => (string) $existing['status'],
                ], 200);
                return;
            }
            $now = time();
            $apiDuration = (string) ($product['api_duration'][$duration] ?? $duration);
            $pdo->prepare(
                'INSERT INTO store_checkouts(order_id,account_id,product_key,product_id,product_name,duration,api_duration,amount_inr,provider_order_id,payment_url,idempotency_key,status,transaction_id,created_at,updated_at) '
                . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $orderId, (int) $account['id'], $key, (string) ($product['id'] ?? ''), (string) ($product['name'] ?? $key),
                $duration, $apiDuration, $amount, '', '', $idempotencyKey, 'manual_pending', '', $now, $now,
            ]);
            $pdo->prepare(
                "INSERT INTO store_checkout_crypto_proofs(order_id,payment_method,amount_usd,exchange_rate_inr,status,proof_txid,created_at,updated_at) VALUES(?,?,?,?,'manual_pending','',?,?)"
            )->execute([$orderId, $method, $amountUsd, $rate, $now, $now]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        self::json([
            'ok' => true, 'order_id' => $orderId, 'product_name' => (string) ($product['name'] ?? $key),
            'duration' => $duration, 'amount_inr' => $amount, 'amount_usd' => $amountUsd,
            'exchange_rate_inr' => $rate, 'payment_method' => $method, 'qr_url' => (string) $config['qr_url'],
            'instructions' => (string) $config['instructions'], 'status' => 'manual_pending',
        ], 201);
    }

    private static function submitCryptoCheckoutProof(string $orderId, int $accountId): void
    {
        $txid = trim((string) ($_POST['txid'] ?? ''));
        if (mb_strlen($txid) < 4 || mb_strlen($txid) > 180) {
            throw new HttpError('Enter a transaction ID between 4 and 180 characters.', 400);
        }
        $file = $_FILES['proof'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError('Upload a payment screenshot to submit proof.', 400);
        }
        if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            throw new HttpError('Payment screenshot must be no larger than 5 MB.', 400);
        }
        $bytes = file_get_contents((string) $file['tmp_name']);
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new HttpError('Upload a valid PNG, JPEG, or WebP screenshot.', 400);
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > 20000000) {
            throw new HttpError('Screenshot dimensions are invalid or too large.', 400);
        }
        $filename = substr(basename((string) ($file['name'] ?? 'payment-proof')), 0, 255);
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $order = Database::row(
                'SELECT c.status,p.status AS proof_status FROM store_checkouts c JOIN store_checkout_crypto_proofs p ON p.order_id=c.order_id WHERE c.order_id=? AND c.account_id=?',
                [$orderId, $accountId]
            );
            if (!$order) {
                throw new HttpError('Crypto product checkout not found.', 404);
            }
            if ((string) $order['status'] !== 'manual_pending' || (string) $order['proof_status'] !== 'manual_pending') {
                throw new HttpError('Proof has already been submitted or this request was reviewed.', 409);
            }
            Database::reservePaymentTxid($txid, 'checkout', $orderId);
            $proof = $pdo->prepare("UPDATE store_checkout_crypto_proofs SET status='proof_submitted',proof_txid=?,proof_data=?,proof_content_type=?,proof_filename=?,updated_at=? WHERE order_id=? AND status='manual_pending'");
            $proof->bindValue(1, $txid);
            $proof->bindValue(2, $bytes, PDO::PARAM_LOB);
            $proof->bindValue(3, $mime);
            $proof->bindValue(4, $filename);
            $proof->bindValue(5, time(), PDO::PARAM_INT);
            $proof->bindValue(6, $orderId);
            $proof->execute();
            if ($proof->rowCount() !== 1) {
                throw new HttpError('Proof could not be saved because the request changed.', 409);
            }
            $pdo->prepare("UPDATE store_checkouts SET status='proof_submitted',transaction_id=?,updated_at=? WHERE order_id=? AND account_id=? AND status='manual_pending'")
                ->execute([$txid, time(), $orderId, $accountId]);
            $pdo->exec('COMMIT');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 19) {
                throw new HttpError('That transaction ID has already been submitted.', 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        self::json(['ok' => true, 'order_id' => $orderId, 'status' => 'proof_submitted']);
    }

    private static function checkoutFromWallet(): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to use your wallet.', 401);
        }
        Auth::requireCsrf($account);
        $body = self::body();
        $key = strtolower(trim((string) ($body['product_key'] ?? '')));
        $duration = trim((string) ($body['duration'] ?? ''));
        $idempotencyKey = trim((string) ($body['idempotency_key'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{2,60}$/', $key)) {
            throw new HttpError('Choose a valid product.', 400);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new HttpError('Checkout session expired. Please start the purchase again.', 400);
        }
        $product = Catalog::effective($key);
        if (empty($product['enabled']) || !empty($product['maintenance'])) {
            throw new HttpError('This product is currently unavailable.', 409);
        }
        if (!in_array($duration, $product['durations'] ?? [], true)) {
            throw new HttpError('Choose a valid product duration.', 400);
        }
        $amount = self::price((string) ($product['prices'][$duration] ?? ''));
        if ($amount === null) {
            throw new HttpError('The selected INR price is not configured.', 409);
        }
        $stock = $product['stock'][$duration] ?? null;
        if ($stock !== null && is_numeric(str_replace(',', '', (string) $stock)) && (float) str_replace(',', '', (string) $stock) <= 0) {
            throw new HttpError('This option is currently out of stock.', 409);
        }
        $orderId = 'BAL' . strtoupper(bin2hex(random_bytes(8)));
        $now = time();
        $apiDuration = (string) ($product['api_duration'][$duration] ?? $duration);
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = Database::row('SELECT * FROM store_checkouts WHERE account_id=? AND idempotency_key=?', [(int) $account['id'], $idempotencyKey]);
            if ($existing) {
                $cryptoProof = Database::row('SELECT order_id FROM store_checkout_crypto_proofs WHERE order_id=?', [(string) $existing['order_id']]);
                if ((string) $existing['product_key'] !== $key || (string) $existing['duration'] !== $duration
                    || abs((float) $existing['amount_inr'] - $amount) > 0.001 || $cryptoProof
                    || (string) $existing['transaction_id'] !== 'WALLET') {
                    throw new HttpError('This checkout key was already used for different purchase details. Start checkout again.', 409);
                }
                $pdo->exec('COMMIT');
                $outcome = self::deliverCheckout($existing, (string) ($existing['transaction_id'] ?: 'WALLET'));
                $snapshot = Wallet::snapshot((int) $account['id']);
                self::json([
                    'ok' => true, 'order_id' => (string) $existing['order_id'], 'product_name' => (string) $existing['product_name'],
                    'duration' => (string) $existing['duration'], 'amount_inr' => (float) $existing['amount_inr'],
                    'status' => (string) ($outcome['status'] ?? $existing['status']), 'delivery_key' => (string) ($outcome['key'] ?? ''),
                    'balance_inr' => $snapshot['balance_inr'],
                ], 200);
                return;
            }
            $fresh = Database::row('SELECT balance_inr FROM store_accounts WHERE id=? AND disabled=0', [(int) $account['id']]);
            if (!$fresh) {
                throw new HttpError('Account not found or disabled.', 403);
            }
            if ((float) $fresh['balance_inr'] + 0.00001 < $amount) {
                throw new HttpError('Your wallet balance is too low. Add funds or choose online payment.', 409);
            }
            $update = $pdo->prepare('UPDATE store_accounts SET balance_inr=balance_inr-? WHERE id=? AND balance_inr>=?');
            $update->execute([$amount, (int) $account['id'], $amount]);
            if ($update->rowCount() !== 1) {
                throw new HttpError('Your wallet balance changed. Refresh and try again.', 409);
            }
            $pdo->prepare(
                'INSERT INTO store_checkouts(order_id,account_id,product_key,product_id,product_name,duration,api_duration,amount_inr,provider_order_id,payment_url,idempotency_key,status,transaction_id,created_at,updated_at) '
                . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $orderId, (int) $account['id'], $key, (string) ($product['id'] ?? ''), (string) ($product['name'] ?? $key),
                $duration, $apiDuration, $amount, '', '', $idempotencyKey, 'wallet_paid', 'WALLET', $now, $now,
            ]);
            $balance = Database::row('SELECT balance_inr FROM store_accounts WHERE id=?', [(int) $account['id']]);
            $pdo->prepare(
                'INSERT INTO store_wallet_transactions(account_id,kind,amount_inr,balance_after,reference_id,description,created_at) VALUES(?,?,?,?,?,?,?)'
            )->execute([
                (int) $account['id'], 'debit', -$amount, (float) ($balance['balance_inr'] ?? 0),
                'PURCHASE:' . $orderId, 'Wallet purchase: ' . (string) ($product['name'] ?? $key), $now,
            ]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        $order = Database::row('SELECT * FROM store_checkouts WHERE order_id=?', [$orderId]);
        $outcome = self::deliverCheckout($order ?? [], 'WALLET');
        $snapshot = Wallet::snapshot((int) $account['id']);
        self::json([
            'ok' => true, 'order_id' => $orderId, 'product_name' => (string) ($product['name'] ?? $key),
            'duration' => $duration, 'amount_inr' => $amount, 'status' => (string) ($outcome['status'] ?? 'pending'),
            'delivery_key' => (string) ($outcome['key'] ?? ''), 'balance_inr' => $snapshot['balance_inr'],
        ], 201);
    }

    private static function checkoutStatus(string $orderId, bool $verify = false): void
    {
        $account = Auth::current();
        if (!$account) {
            throw new HttpError('Sign in to view this order.', 401);
        }
        $row = Database::row('SELECT * FROM store_checkouts WHERE order_id = ? AND account_id = ?', [$orderId, (int) $account['id']]);
        if (!$row) {
            throw new HttpError('Order not found.', 404);
        }
        if ($verify && $row['status'] === 'wallet_paid') {
            $outcome = self::deliverCheckout($row, (string) $row['transaction_id']);
            $row['status'] = $outcome['status'];
            $row['delivery_key'] = $outcome['key'] ?? '';
        } elseif ($verify && $row['status'] === 'pending') {
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
        $cryptoProof = Database::row(
            'SELECT payment_method,amount_usd,exchange_rate_inr,proof_txid,review_note FROM store_checkout_crypto_proofs WHERE order_id=?',
            [$orderId]
        ) ?? [];
        self::json([
            'order_id' => $orderId, 'status' => (string) $row['status'],
            'delivery_key' => (string) ($row['delivery_key'] ?? ''), 'amount_inr' => (float) $row['amount_inr'],
            'product_name' => (string) $row['product_name'], 'duration' => (string) $row['duration'],
            'payment_method' => (string) ($cryptoProof['payment_method'] ?? ''),
            'amount_usd' => (float) ($cryptoProof['amount_usd'] ?? 0),
            'exchange_rate_inr' => (float) ($cryptoProof['exchange_rate_inr'] ?? 0),
            'proof_txid' => (string) ($cryptoProof['proof_txid'] ?? ''),
            'review_note' => (string) ($cryptoProof['review_note'] ?? ''),
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
            "SELECT o.order_id,o.product_name,o.duration,o.amount,o.status,o.delivery_key,o.created_at,o.transaction_id,COALESCE(p.payment_method,'upi') AS payment_method,'' AS payment_url FROM store_orders o LEFT JOIN store_checkout_crypto_proofs p ON p.order_id=o.order_id WHERE o.account_id=? "
            . "UNION ALL SELECT c.order_id,c.product_name,c.duration,c.amount_inr AS amount,c.status,'' AS delivery_key,c.created_at,c.transaction_id,COALESCE(p.payment_method,'upi') AS payment_method,c.payment_url FROM store_checkouts c LEFT JOIN store_checkout_crypto_proofs p ON p.order_id=c.order_id WHERE c.account_id=? AND c.status!='complete' "
            . 'ORDER BY 7 DESC LIMIT 100',
            [(int) $account['id'], (int) $account['id']]
        );
        self::json(['orders' => $rows]);
    }

    private static function adminCryptoCheckouts(): void
    {
        $rows = Database::rows(
            'SELECT c.order_id,c.account_id,a.email,a.display_name,c.product_name,c.duration,c.amount_inr,c.status,c.transaction_id,c.created_at,c.updated_at,'
            . 'p.payment_method,p.amount_usd,p.exchange_rate_inr,p.proof_txid,p.proof_filename,p.proof_content_type,p.status AS proof_status,p.reviewed_at,p.review_note '
            . 'FROM store_checkouts c JOIN store_checkout_crypto_proofs p ON p.order_id=c.order_id '
            . 'LEFT JOIN store_accounts a ON a.id=c.account_id ORDER BY c.created_at DESC LIMIT 200'
        );
        self::json(['payments' => $rows]);
    }

    private static function adminCryptoCheckoutProof(string $orderId): array
    {
        $row = Database::row(
            'SELECT proof_content_type,proof_data FROM store_checkout_crypto_proofs WHERE order_id=?',
            [$orderId]
        );
        if (!$row || empty($row['proof_data'])) {
            throw new HttpError('Payment proof not found.', 404);
        }
        return ['content_type' => (string) $row['proof_content_type'], 'body' => $row['proof_data']];
    }

    private static function reviewCryptoCheckout(string $orderId, array $body): void
    {
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new HttpError('Review status must be approved or rejected.', 400);
        }
        if (empty($body['confirm_payment_checked'])) {
            throw new HttpError('Confirm that the crypto payment was checked in the receiving account before reviewing.', 409);
        }
        $note = trim((string) ($body['note'] ?? ''));
        if ($status === 'rejected' && $note === '') {
            $note = 'Payment was not verified in the receiving account.';
        }
        if (mb_strlen($note) > 240) {
            throw new HttpError('Review note must be 240 characters or fewer.', 400);
        }
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $row = Database::row(
                'SELECT c.*,p.payment_method,p.amount_usd,p.exchange_rate_inr,p.proof_txid,p.proof_data,p.status AS proof_status '
                . 'FROM store_checkouts c JOIN store_checkout_crypto_proofs p ON p.order_id=c.order_id WHERE c.order_id=?',
                [$orderId]
            );
            if (!$row || !in_array((string) $row['payment_method'], ['binance', 'usdt_bep20'], true)) {
                throw new HttpError('Crypto product checkout not found.', 404);
            }
            if ((string) $row['status'] !== 'proof_submitted' || (string) $row['proof_status'] !== 'proof_submitted' || empty($row['proof_data']) || trim((string) $row['proof_txid']) === '') {
                throw new HttpError('A submitted screenshot and transaction ID are required before review.', 409);
            }
            $now = time();
            $updateProof = $pdo->prepare("UPDATE store_checkout_crypto_proofs SET status=?,reviewed_at=?,review_note=?,updated_at=? WHERE order_id=? AND status='proof_submitted'");
            $updateProof->execute([$status, $now, $note, $now, $orderId]);
            if ($updateProof->rowCount() !== 1) {
                throw new HttpError('This crypto product payment has already been reviewed.', 409);
            }
            $checkoutStatus = $status === 'approved' ? 'wallet_paid' : 'rejected';
            $pdo->prepare("UPDATE store_checkouts SET status=?,transaction_id=?,updated_at=? WHERE order_id=? AND status='proof_submitted'")
                ->execute([$checkoutStatus, (string) $row['proof_txid'], $now, $orderId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        if ($status === 'approved') {
            $order = Database::row('SELECT * FROM store_checkouts WHERE order_id=?', [$orderId]);
            $delivery = self::deliverCheckout($order ?? [], (string) ($row['proof_txid'] ?? 'CRYPTO'));
            self::json([
                'ok' => true, 'status' => 'approved', 'order_id' => $orderId,
                'fulfillment_status' => (string) ($delivery['status'] ?? 'pending'),
                'delivery_key' => (string) ($delivery['key'] ?? ''),
            ]);
            return;
        }
        self::json(['ok' => true, 'status' => 'rejected', 'order_id' => $orderId]);
    }

    private static function adminAccounts(): void
    {
        $query = trim(substr((string) ($_GET['q'] ?? ''), 0, 120));
        if ($query !== '') {
            $like = '%' . strtolower($query) . '%';
            $rows = Database::rows(
                'SELECT id,email,display_name,disabled,balance_inr,created_at,last_login_at FROM store_accounts WHERE lower(email) LIKE ? OR lower(display_name) LIKE ? ORDER BY created_at DESC LIMIT 300',
                [$like, $like]
            );
        } else {
            $rows = Database::rows('SELECT id,email,display_name,disabled,balance_inr,created_at,last_login_at FROM store_accounts ORDER BY created_at DESC LIMIT 300');
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

    private static function adjustAccountWallet(int $accountId): void
    {
        $body = self::body();
        $amount = filter_var($body['amount_inr'] ?? null, FILTER_VALIDATE_FLOAT);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($amount === false || !is_finite((float) $amount) || abs((float) $amount) < 0.01 || abs((float) $amount) > 1000000) {
            throw new HttpError('Enter a non-zero wallet adjustment up to ₹1,000,000.', 400);
        }
        if ($reason === '' || mb_strlen($reason) > 240) {
            throw new HttpError('A reason of 1–240 characters is required for every wallet adjustment.', 400);
        }
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $account = Database::row('SELECT balance_inr FROM store_accounts WHERE id=?', [$accountId]);
            if (!$account) {
                throw new HttpError('Customer account not found.', 404);
            }
            $balance = round((float) $account['balance_inr'] + (float) $amount, 2);
            if ($balance < 0) {
                throw new HttpError('Wallet debit cannot make the balance negative.', 409);
            }
            $pdo->prepare('UPDATE store_accounts SET balance_inr=? WHERE id=?')->execute([$balance, $accountId]);
            $reference = 'ADMIN-' . strtoupper(bin2hex(random_bytes(10)));
            $kind = (float) $amount > 0 ? 'admin_credit' : 'admin_debit';
            $pdo->prepare('INSERT INTO store_wallet_transactions(account_id,kind,amount_inr,balance_after,reference_id,description,created_at) VALUES(?,?,?,?,?,?,?)')
                ->execute([$accountId, $kind, (float) $amount, $balance, $reference, 'Admin adjustment: ' . $reason, time()]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        self::json(['ok' => true, 'balance_inr' => $balance]);
    }

    private static function adminOrders(): void
    {
        $rows = Database::rows(
            'SELECT c.order_id,c.account_id,a.email,c.product_name,c.duration,c.amount_inr AS amount,c.status,c.delivery_key,c.transaction_id,c.created_at,c.updated_at, '
            . '(SELECT COUNT(*) FROM store_fulfillment_attempts f WHERE f.order_id=c.order_id) AS fulfillment_attempt_count, '
            . '(SELECT f.status FROM store_fulfillment_attempts f WHERE f.order_id=c.order_id ORDER BY f.attempt_no DESC LIMIT 1) AS last_fulfillment_attempt_status, '
            . '(SELECT f.provider_status FROM store_fulfillment_attempts f WHERE f.order_id=c.order_id ORDER BY f.attempt_no DESC LIMIT 1) AS last_provider_status, '
            . '(SELECT f.error_message FROM store_fulfillment_attempts f WHERE f.order_id=c.order_id ORDER BY f.attempt_no DESC LIMIT 1) AS last_fulfillment_error '
            . 'FROM store_checkouts c LEFT JOIN store_accounts a ON a.id=c.account_id ORDER BY c.created_at DESC LIMIT 300'
        );
        self::json(['orders' => $rows]);
    }

    private static function retryDelivery(string $orderId): void
    {
        $body = self::body();
        $row = Database::row('SELECT * FROM store_checkouts WHERE order_id = ?', [$orderId]);
        if (!$row) {
            throw new HttpError('Order not found.', 404);
        }
        $status = (string) $row['status'];
        if ($status === 'delivering' && (int) $row['updated_at'] < time() - 180 && !empty($body['confirm_provider_checked'])) {
            Database::pdo()->prepare("UPDATE store_checkouts SET status='delivery_unknown',updated_at=? WHERE order_id=? AND status='delivering'")
                ->execute([time(), $orderId]);
            Database::pdo()->prepare("UPDATE store_fulfillment_attempts SET status='unknown',error_message='Worker stopped before confirming the supplier result.',completed_at=? WHERE order_id=? AND status='started'")
                ->execute([time(), $orderId]);
            $row['status'] = 'delivery_unknown';
            $status = 'delivery_unknown';
        }
        if (!in_array($status, ['paid_delivery_failed', 'delivery_unknown'], true)) {
            throw new HttpError('This order is not eligible for a supplier retry.', 409);
        }
        if (empty($body['confirm_provider_checked'])) {
            throw new HttpError('Check the supplier account for this order before confirming a retry.', 409);
        }
        $result = self::deliverCheckout($row, (string) $row['transaction_id'], true);
        self::json($result);
    }

    private static function saveManualDeliveryKey(string $orderId): void
    {
        $body = self::body();
        if (empty($body['confirm_provider_checked'])) {
            throw new HttpError('Confirm that you checked the supplier account before saving a key.', 409);
        }
        $key = trim((string) ($body['delivery_key'] ?? ''));
        if (strlen($key) < 2 || strlen($key) > 4096) {
            throw new HttpError('Enter a delivery key between 2 and 4,096 characters.', 400);
        }
        $reference = trim((string) ($body['transaction_id'] ?? ''));
        if (strlen($reference) > 120) {
            throw new HttpError('Supplier transaction reference is too long.', 400);
        }
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $order = Database::row('SELECT * FROM store_checkouts WHERE order_id=?', [$orderId]);
            if (!$order) {
                throw new HttpError('Order not found.', 404);
            }
            if ((string) $order['status'] === 'complete') {
                throw new HttpError('This order already has a saved delivery key.', 409);
            }
            if (!in_array((string) $order['status'], ['paid_delivery_failed', 'delivery_unknown', 'delivering'], true)) {
                throw new HttpError('Manual key recovery is only available for paid orders needing delivery review.', 409);
            }
            if ((string) $order['status'] === 'delivering' && (int) $order['updated_at'] >= time() - 180) {
                throw new HttpError('A supplier request is still marked in progress. Wait three minutes, then review it again.', 409);
            }
            $transactionId = $reference !== '' ? $reference : (string) $order['transaction_id'];
            $now = time();
            $pdo->prepare("UPDATE store_checkouts SET status='complete',delivery_key=?,transaction_id=?,updated_at=? WHERE order_id=?")
                ->execute([$key, $transactionId, $now, $orderId]);
            $pdo->prepare(
                'INSERT INTO store_orders(order_id,account_id,product_key,product_name,duration,amount,delivery_key,status,created_at,transaction_id) VALUES(?,?,?,?,?,?,?,?,?,?) '
                . 'ON CONFLICT(order_id) DO UPDATE SET delivery_key=excluded.delivery_key,status=excluded.status,transaction_id=excluded.transaction_id'
            )->execute([
                $orderId, (int) $order['account_id'], (string) $order['product_key'], (string) $order['product_name'],
                (string) $order['duration'], (float) $order['amount_inr'], $key, 'complete', $now, $transactionId,
            ]);
            $pdo->prepare("UPDATE store_fulfillment_attempts SET status='manually_recovered',completed_at=? WHERE order_id=? AND status IN ('started','unknown')")
                ->execute([$now, $orderId]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        self::json(['ok' => true, 'order_id' => $orderId, 'status' => 'complete', 'key_saved' => true]);
    }

    private static function deliverCheckout(array $order, string $transactionId, bool $allowUnknownRetry = false): array
    {
        $orderId = (string) $order['order_id'];
        $pdo = Database::pdo();
        $attemptNo = 0;
        $requestId = 'HEX_' . $orderId;
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
            $allowed = ['pending', 'wallet_paid', 'paid_delivery_failed'];
            if ($allowUnknownRetry) {
                $allowed[] = 'delivery_unknown';
            }
            if (!in_array((string) $fresh['status'], $allowed, true)) {
                $status = (string) $fresh['status'];
                $pdo->exec('COMMIT');
                return ['status' => $status];
            }
            $last = Database::row('SELECT COALESCE(MAX(attempt_no),0) AS n FROM store_fulfillment_attempts WHERE order_id=?', [$orderId]);
            $attemptNo = (int) ($last['n'] ?? 0) + 1;
            $now = time();
            $pdo->prepare("UPDATE store_checkouts SET status='delivering',transaction_id=?,updated_at=? WHERE order_id=?")
                ->execute([$transactionId, $now, $orderId]);
            $pdo->prepare('INSERT INTO store_fulfillment_attempts(order_id,attempt_no,request_id,status,started_at) VALUES(?,?,?,?,?)')
                ->execute([$orderId, $attemptNo, $requestId, 'started', $now]);
            $order = $fresh;
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
        try {
            $result = Integrations::buyProduct((string) $order['product_id'], (string) $order['api_duration'], $requestId);
            $delivery = Integrations::deliveryFields($result);
            $providerStatus = (string) $delivery['status'];
            $key = (string) $delivery['key'];
            if ($providerStatus !== 'success' || $key === '') {
                Database::pdo()->prepare("UPDATE store_checkouts SET status='delivery_unknown',updated_at=? WHERE order_id=? AND status='delivering'")
                    ->execute([time(), $orderId]);
                Database::pdo()->prepare("UPDATE store_fulfillment_attempts SET status='unknown',provider_status=?,error_message='Supplier response did not include a confirmed key; inspect the supplier account before retrying.',completed_at=? WHERE order_id=? AND attempt_no=?")
                    ->execute([substr($providerStatus, 0, 80), time(), $orderId, $attemptNo]);
                return ['status' => 'delivery_unknown'];
            }
        } catch (Throwable) {
            Database::pdo()->prepare("UPDATE store_checkouts SET status='delivery_unknown',updated_at=? WHERE order_id=? AND status='delivering'")
                ->execute([time(), $orderId]);
            Database::pdo()->prepare("UPDATE store_fulfillment_attempts SET status='unknown',error_message='Supplier request outcome could not be confirmed; inspect the supplier account before retrying.',completed_at=? WHERE order_id=? AND attempt_no=?")
                ->execute([time(), $orderId, $attemptNo]);
            return ['status' => 'delivery_unknown'];
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
            $pdo->prepare("UPDATE store_fulfillment_attempts SET status='complete',provider_status='success',error_message='',completed_at=? WHERE order_id=? AND attempt_no=?")
                ->execute([$now, $orderId, $attemptNo]);
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
        return in_array($status, [
            'success', 'successful', 'paid', 'completed', 'verified', 'captured',
            'payment_success', 'payment_successful', 'payment_paid', 'payment_completed',
            'transaction_success', 'transaction_successful', 'order_paid', 'order_completed',
        ], true);
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
