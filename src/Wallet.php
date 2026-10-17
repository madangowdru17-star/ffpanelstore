<?php
declare(strict_types=1);

final class Wallet
{
    public static function snapshot(int $accountId): array
    {
        $account = Database::row('SELECT balance_inr FROM store_accounts WHERE id = ?', [$accountId]);
        if (!$account) {
            throw new HttpError('Account not found.', 404);
        }
        $transactions = Database::rows(
            'SELECT kind,amount_inr,balance_after,reference_id,description,created_at '
            . 'FROM store_wallet_transactions WHERE account_id = ? ORDER BY id DESC LIMIT 30',
            [$accountId]
        );
        return [
            'balance_inr' => (float) $account['balance_inr'],
            'transactions' => $transactions,
            'payment_history' => self::paymentHistory($accountId),
        ];
    }

    public static function createTopup(int $accountId, array $body): array
    {
        $amount = self::positiveAmount($body['amount_inr'] ?? null);
        if ($amount < 50 || $amount > 50000) {
            throw new HttpError('Choose a top-up amount between ₹50 and ₹50,000.', 400);
        }
        $orderId = 'WLT' . strtoupper(bin2hex(random_bytes(8)));
        try {
            $provider = Integrations::createPaymentOrder($amount, $orderId, 'Wallet top-up');
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
        Database::pdo()->prepare(
            'INSERT INTO store_wallet_topups(order_id,account_id,amount_inr,provider_order_id,payment_url,status,created_at,updated_at) '
            . 'VALUES(?,?,?,?,?,?,?,?)'
        )->execute([$orderId, $accountId, $amount, $providerId, $paymentUrl, 'pending', $now, $now]);
        return [
            'ok' => true, 'order_id' => $orderId, 'amount_inr' => $amount,
            'payment_url' => $paymentUrl, 'qr_url' => '/api/store/wallet/topups/' . rawurlencode($orderId) . '/qr',
            'status' => 'pending',
        ];
    }

    public static function topupStatus(int $accountId, string $orderId): array
    {
        $topup = Database::row('SELECT * FROM store_wallet_topups WHERE order_id = ? AND account_id = ?', [$orderId, $accountId]);
        if (!$topup) {
            throw new HttpError('Wallet top-up not found.', 404);
        }
        if ($topup['status'] === 'pending') {
            $response = null;
            foreach (array_unique(array_filter([(string) $topup['provider_order_id'], (string) $topup['order_id']])) as $candidate) {
                $response = Integrations::checkPaymentStatus($candidate);
                if ($response !== null) {
                    break;
                }
            }
            if ($response !== null) {
                $fields = Integrations::statusFields($response);
                if (self::paymentSuccessful((string) $fields['status'])) {
                    $paidAmount = self::normalizePaidAmount($fields['amount'] ?? null, (float) $topup['amount_inr']);
                    if ($paidAmount === null || abs($paidAmount - (float) $topup['amount_inr']) > 0.01) {
                        self::markAmountReview($orderId);
                    } else {
                        self::creditTopup(
                            $orderId,
                            $accountId,
                            (float) $topup['amount_inr'],
                            (string) ($fields['transaction_id'] ?: $fields['utr'])
                        );
                    }
                } elseif (self::paymentFailed((string) $fields['status'])) {
                    Database::pdo()->prepare("UPDATE store_wallet_topups SET status='failed',updated_at=? WHERE order_id=? AND status='pending'")
                        ->execute([time(), $orderId]);
                }
            }
        }
        $latest = Database::row('SELECT status,amount_inr,amount_usd,payment_method,transaction_id,proof_txid,review_note FROM store_wallet_topups WHERE order_id=?', [$orderId]);
        $snapshot = self::snapshot($accountId);
        return [
            'order_id' => $orderId,
            'status' => (string) ($latest['status'] ?? 'not_found'),
            'amount_inr' => (float) ($latest['amount_inr'] ?? 0),
            'amount_usd' => (float) ($latest['amount_usd'] ?? 0),
            'payment_method' => (string) ($latest['payment_method'] ?? 'upi'),
            'transaction_id' => (string) ($latest['transaction_id'] ?? ''),
            'proof_txid' => (string) ($latest['proof_txid'] ?? ''),
            'review_note' => (string) ($latest['review_note'] ?? ''),
            'balance_inr' => $snapshot['balance_inr'],
        ];
    }

    public static function createBinanceTopup(int $accountId, array $body): array
    {
        return self::createManualCryptoTopup($accountId, $body, 'binance');
    }

    public static function createUsdtTopup(int $accountId, array $body): array
    {
        return self::createManualCryptoTopup($accountId, $body, 'usdt_bep20');
    }

    private static function createManualCryptoTopup(int $accountId, array $body, string $method): array
    {
        $methodName = $method === 'usdt_bep20' ? 'USDT' : 'Binance';
        $raw = $body['amount_usd'] ?? null;
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            throw new HttpError('Enter a valid ' . $methodName . ' amount.', 400);
        }
        $amountUsd = round((float) $raw, 2);
        if (!is_finite($amountUsd) || $amountUsd < 0.52 || $amountUsd > 100) {
            throw new HttpError($methodName . ' top-ups must be between 0.52 and 100.00.', 400);
        }
        $config = $method === 'usdt_bep20' ? Homepage::publicUsdtConfig() : Homepage::publicBinanceConfig();
        if (empty($config['available'])) {
            throw new HttpError($methodName . ' payment QR is not ready. The shop administrator must upload its QR image.', 503);
        }
        $inrPerUsd = self::usdToInrRate();
        $amountInr = round($amountUsd * $inrPerUsd, 2);
        if ($amountInr <= 0 || $amountInr > 50000) {
            throw new HttpError('This ' . $methodName . ' amount is outside the supported wallet top-up range.', 400);
        }
        $orderId = ($method === 'usdt_bep20' ? 'USD' : 'BNZ') . strtoupper(bin2hex(random_bytes(8)));
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO store_wallet_topups(order_id,account_id,amount_inr,provider_order_id,payment_url,status,transaction_id,created_at,updated_at,payment_method,amount_usd,exchange_rate_inr) '
            . "VALUES(?,?,?,'','','manual_pending','',?,?,?,?,?)"
        )->execute([$orderId, $accountId, $amountInr, $now, $now, $method, $amountUsd, $inrPerUsd]);
        return [
            'ok' => true,
            'order_id' => $orderId,
            'amount_usd' => $amountUsd,
            'amount_inr' => $amountInr,
            'exchange_rate_inr' => $inrPerUsd,
            'qr_url' => (string) $config['qr_url'],
            'instructions' => (string) $config['instructions'],
            'payment_method' => $method,
            'status' => 'manual_pending',
        ];
    }

    public static function submitBinanceProof(int $accountId, string $orderId): array
    {
        $row = Database::row('SELECT status,payment_method FROM store_wallet_topups WHERE order_id=? AND account_id=?', [$orderId, $accountId]);
        if (!$row || !in_array((string) $row['payment_method'], ['binance', 'usdt_bep20'], true)) {
            throw new HttpError('Manual crypto top-up not found.', 404);
        }
        if ($row['status'] !== 'manual_pending') {
            throw new HttpError('Proof has already been submitted or this request was reviewed.', 409);
        }
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
        $statement = Database::pdo()->prepare(
            "UPDATE store_wallet_topups SET status='proof_submitted',proof_data=?,proof_content_type=?,proof_filename=?,proof_txid=?,transaction_id=?,updated_at=? "
            . "WHERE order_id=? AND account_id=? AND payment_method IN ('binance','usdt_bep20') AND status='manual_pending'"
        );
        $statement->bindValue(1, $bytes, PDO::PARAM_LOB);
        $statement->bindValue(2, $mime);
        $statement->bindValue(3, $filename);
        $statement->bindValue(4, $txid);
        $statement->bindValue(5, $txid);
        $statement->bindValue(6, time(), PDO::PARAM_INT);
        $statement->bindValue(7, $orderId);
        $statement->bindValue(8, $accountId, PDO::PARAM_INT);
        try {
            $statement->execute();
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 19) {
                throw new HttpError('That crypto transaction ID has already been submitted.', 409);
            }
            throw $e;
        }
        if ($statement->rowCount() !== 1) {
            throw new HttpError('Proof could not be saved because the request changed. Refresh and check its status.', 409);
        }
        return ['ok' => true, 'order_id' => $orderId, 'status' => 'proof_submitted'];
    }

    public static function paymentHistory(int $accountId): array
    {
        return Database::rows(
            'SELECT order_id,payment_method,amount_inr,amount_usd,status,transaction_id,proof_txid,review_note,created_at,reviewed_at '
            . 'FROM store_wallet_topups WHERE account_id=? ORDER BY created_at DESC LIMIT 50',
            [$accountId]
        );
    }

    public static function adminBinanceTopups(): array
    {
        return self::adminCryptoTopupsForMethods(['binance']);
    }

    public static function adminCryptoTopups(): array
    {
        return self::adminCryptoTopupsForMethods(['binance', 'usdt_bep20']);
    }

    private static function adminCryptoTopupsForMethods(array $methods): array
    {
        $placeholders = implode(',', array_fill(0, count($methods), '?'));
        return Database::rows(
            'SELECT t.order_id,t.account_id,a.email,a.display_name,t.payment_method,t.amount_usd,t.amount_inr,t.exchange_rate_inr,t.status,t.proof_txid,t.proof_filename,t.proof_content_type,t.created_at,t.updated_at,t.reviewed_at,t.review_note '
            . 'FROM store_wallet_topups t LEFT JOIN store_accounts a ON a.id=t.account_id WHERE t.payment_method IN (' . $placeholders . ') ORDER BY t.created_at DESC LIMIT 200',
            $methods
        );
    }

    public static function adminBinanceProof(string $orderId): array
    {
        return self::adminCryptoProofForMethods($orderId, ['binance']);
    }

    public static function adminCryptoProof(string $orderId): array
    {
        return self::adminCryptoProofForMethods($orderId, ['binance', 'usdt_bep20']);
    }

    private static function adminCryptoProofForMethods(string $orderId, array $methods): array
    {
        $placeholders = implode(',', array_fill(0, count($methods), '?'));
        $row = Database::row(
            'SELECT proof_content_type,proof_data FROM store_wallet_topups WHERE order_id=? AND payment_method IN (' . $placeholders . ')',
            array_merge([$orderId], $methods)
        );
        if (!$row || empty($row['proof_data'])) {
            throw new HttpError('Payment proof not found.', 404);
        }
        return ['content_type' => (string) $row['proof_content_type'], 'body' => $row['proof_data']];
    }

    public static function reviewBinanceTopup(string $orderId, array $body): array
    {
        return self::reviewCryptoTopupWithMethods($orderId, $body, ['binance']);
    }

    public static function reviewCryptoTopup(string $orderId, array $body): array
    {
        return self::reviewCryptoTopupWithMethods($orderId, $body, ['binance', 'usdt_bep20']);
    }

    private static function reviewCryptoTopupWithMethods(string $orderId, array $body, array $methods): array
    {
        $status = strtolower(trim((string) ($body['status'] ?? '')));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new HttpError('Review status must be approved or rejected.', 400);
        }
        if (empty($body['confirm_payment_checked'])) {
            throw new HttpError('Confirm that the payment was checked in the receiving account before reviewing.', 409);
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
            $row = Database::row('SELECT * FROM store_wallet_topups WHERE order_id=?', [$orderId]);
            if (!$row || !in_array((string) $row['payment_method'], $methods, true)) {
                throw new HttpError('Manual crypto top-up not found.', 404);
            }
            if ((string) $row['status'] !== 'proof_submitted' || empty($row['proof_data']) || trim((string) $row['proof_txid']) === '') {
                throw new HttpError('A submitted screenshot and transaction ID are required before review.', 409);
            }
            $now = time();
            $changed = $pdo->prepare("UPDATE store_wallet_topups SET status=?,reviewed_at=?,review_note=?,updated_at=? WHERE order_id=? AND status='proof_submitted'");
            $changed->execute([$status, $now, $note, $now, $orderId]);
            if ($changed->rowCount() !== 1) {
                throw new HttpError('This crypto payment request has already been reviewed.', 409);
            }
            $balance = null;
            if ($status === 'approved') {
                $pdo->prepare('UPDATE store_accounts SET balance_inr=balance_inr+? WHERE id=?')->execute([(float) $row['amount_inr'], (int) $row['account_id']]);
                $account = Database::row('SELECT balance_inr FROM store_accounts WHERE id=?', [(int) $row['account_id']]);
                if (!$account) {
                    throw new HttpError('Customer account no longer exists.', 404);
                }
                $balance = round((float) $account['balance_inr'], 2);
                $methodName = (string) $row['payment_method'] === 'usdt_bep20' ? 'USDT BEP20' : 'Binance Pay';
                $unit = (string) $row['payment_method'] === 'usdt_bep20' ? 'USDT' : 'USD';
                $description = sprintf('Verified %s top-up: %.2f %s · TXID %s', $methodName, (float) $row['amount_usd'], $unit, mb_substr((string) $row['proof_txid'], 0, 100));
                $referencePrefix = (string) $row['payment_method'] === 'usdt_bep20' ? 'USDT:' : 'BINANCE:';
                $transactionKind = (string) $row['payment_method'] === 'usdt_bep20' ? 'usdt_credit' : 'binance_credit';
                $pdo->prepare('INSERT INTO store_wallet_transactions(account_id,kind,amount_inr,balance_after,reference_id,description,created_at) VALUES(?,?,?,?,?,?,?)')
                    ->execute([(int) $row['account_id'], $transactionKind, (float) $row['amount_inr'], $balance, $referencePrefix . $orderId, $description, $now]);
            }
            $pdo->exec('COMMIT');
            return ['ok' => true, 'status' => $status, 'order_id' => $orderId, 'balance_inr' => $balance];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function cryptoExchangeRateInr(): float
    {
        return self::usdToInrRate();
    }

    private static function usdToInrRate(): float
    {
        $cachedRate = (float) (Database::setting('fx_inr_usd_rate', '') ?? 0);
        $cachedAt = (int) (Database::setting('fx_inr_usd_updated_at', '0') ?? 0);
        if ($cachedRate > 0 && $cachedAt > time() - 300) {
            return round(1 / $cachedRate, 4);
        }
        if (function_exists('curl_init')) {
            $curl = curl_init('https://api.frankfurter.app/latest?from=INR&to=USD');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true]);
            $response = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if (is_string($response) && $httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                $rate = (float) ($data['rates']['USD'] ?? 0);
                if ($rate > 0 && is_finite($rate)) {
                    Database::saveSetting('fx_inr_usd_rate', (string) $rate);
                    Database::saveSetting('fx_inr_usd_updated_at', (string) time());
                    return round(1 / $rate, 4);
                }
            }
        }
        if ($cachedRate > 0) {
            return round(1 / $cachedRate, 4);
        }
        return round(1 / 0.0119, 4);
    }

    public static function topupQr(int $accountId, string $orderId): array
    {
        $row = Database::row('SELECT order_id,provider_order_id FROM store_wallet_topups WHERE order_id=? AND account_id=?', [$orderId, $accountId]);
        if (!$row) {
            throw new HttpError('Wallet top-up not found.', 404);
        }
        try {
            return Integrations::qrImage((string) ($row['provider_order_id'] ?: $row['order_id']));
        } catch (Throwable) {
            throw new HttpError('Payment QR code is not available.', 404);
        }
    }

    public static function creditTopup(string $orderId, int $accountId, float $amount, string $transactionId): void
    {
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $fresh = Database::row('SELECT status,amount_inr FROM store_wallet_topups WHERE order_id=? AND account_id=?', [$orderId, $accountId]);
            if (!$fresh || $fresh['status'] !== 'pending') {
                $pdo->exec('COMMIT');
                return;
            }
            if (abs((float) $fresh['amount_inr'] - $amount) > 0.01) {
                $pdo->prepare("UPDATE store_wallet_topups SET status='amount_review',updated_at=? WHERE order_id=? AND status='pending'")->execute([time(), $orderId]);
                $pdo->exec('COMMIT');
                return;
            }
            $changed = $pdo->prepare("UPDATE store_wallet_topups SET status='complete',transaction_id=?,updated_at=? WHERE order_id=? AND status='pending'");
            $changed->execute([$transactionId, time(), $orderId]);
            if ($changed->rowCount() === 1) {
                $pdo->prepare('UPDATE store_accounts SET balance_inr=balance_inr+? WHERE id=?')->execute([$amount, $accountId]);
                $balance = Database::row('SELECT balance_inr FROM store_accounts WHERE id=?', [$accountId]);
                $pdo->prepare(
                    'INSERT OR IGNORE INTO store_wallet_transactions(account_id,kind,amount_inr,balance_after,reference_id,description,created_at) VALUES(?,?,?,?,?,?,?)'
                )->execute([$accountId, 'credit', $amount, (float) ($balance['balance_inr'] ?? 0), 'TOPUP:' . $orderId, 'Verified FamGateway wallet top-up', time()]);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function positiveAmount(mixed $value): float
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new HttpError('Enter a valid amount in INR.', 400);
        }
        $amount = round((float) $value, 2);
        if (!is_finite($amount) || $amount <= 0 || $amount > 50000) {
            throw new HttpError('Amount must be greater than ₹0 and no more than ₹50,000.', 400);
        }
        return $amount;
    }

    private static function markAmountReview(string $orderId): void
    {
        Database::pdo()->prepare("UPDATE store_wallet_topups SET status='amount_review',updated_at=? WHERE order_id=? AND status='pending'")
            ->execute([time(), $orderId]);
    }

    private static function normalizePaidAmount(mixed $raw, float $expected): ?float
    {
        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            return null;
        }
        $amount = (float) $raw;
        if (abs($amount - $expected * 100) < 0.01) {
            return $amount / 100;
        }
        return $amount;
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
}
