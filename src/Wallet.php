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
        return ['balance_inr' => (float) $account['balance_inr'], 'transactions' => $transactions];
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
        $latest = Database::row('SELECT status,amount_inr,transaction_id FROM store_wallet_topups WHERE order_id=?', [$orderId]);
        $snapshot = self::snapshot($accountId);
        return [
            'order_id' => $orderId,
            'status' => (string) ($latest['status'] ?? 'not_found'),
            'amount_inr' => (float) ($latest['amount_inr'] ?? 0),
            'transaction_id' => (string) ($latest['transaction_id'] ?? ''),
            'balance_inr' => $snapshot['balance_inr'],
        ];
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
