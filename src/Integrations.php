<?php
declare(strict_types=1);

final class Integrations
{
    public static function paymentKey(): string
    {
        foreach (['FAMGATEWAY_API_KEY', 'FAM_API_KEY', 'FAMPAY_API_KEY'] as $name) {
            $value = trim((string) (getenv($name) ?: ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public static function createPaymentOrder(float $amount, string $orderId, string $customerName): array
    {
        $key = self::paymentKey();
        if ($key === '') {
            throw new HttpError('Online payments are not configured yet. Please contact the shop administrator.', 503);
        }
        $base = rtrim((string) (getenv('FAMGATEWAY_BASE_URL') ?: 'https://famgateway.in'), '/');
        return self::jsonRequest($base . '/api/create-order', 'POST', [
            'Authorization: Bearer ' . $key,
            'X-Api-Key: ' . $key,
            'Content-Type: application/json',
        ], [
            'amount' => $amount,
            'order_id' => $orderId,
            'customer_name' => substr($customerName, 0, 100),
        ], 20);
    }

    public static function paymentUrl(array $response): string
    {
        $data = self::data($response);
        return trim((string) ($data['checkout_url'] ?? $data['payment_url'] ?? ''));
    }

    public static function providerOrderId(array $response, string $fallback): string
    {
        $data = self::data($response);
        foreach ([$data['order_id'] ?? null, $data['orderId'] ?? null, $data['id'] ?? null, $data['payment_id'] ?? null,
                  $response['order_id'] ?? null, $response['orderId'] ?? null, $response['id'] ?? null] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }
        return $fallback;
    }

    public static function checkPaymentStatus(string $orderId): ?array
    {
        $key = self::paymentKey();
        if ($key === '') {
            return null;
        }
        $base = rtrim((string) (getenv('FAMGATEWAY_BASE_URL') ?: 'https://famgateway.in'), '/');
        $url = $base . '/api/verify-order.php?' . http_build_query(['api_key' => $key, 'order_id' => $orderId]);
        try {
            return self::jsonRequest($url, 'GET', [], null, 15);
        } catch (Throwable) {
            return null;
        }
    }

    public static function statusFields(array $response): array
    {
        $data = self::data($response);
        $statusValue = $data['payment_status'] ?? $data['paymentStatus'] ?? $data['status']
            ?? $response['payment_status'] ?? $response['paymentStatus'] ?? $response['status'] ?? '';
        $status = strtolower(str_replace(['-', ' '], '_', (string) $statusValue));
        return [
            'status' => trim($status),
            'amount' => $data['amount'] ?? $data['paid_amount'] ?? $data['amount_paid'] ?? $data['paidAmount'] ?? null,
            'transaction_id' => (string) ($data['transaction_id'] ?? $data['txn_id'] ?? $data['transactionId'] ?? $data['transaction'] ?? ''),
            'utr' => (string) ($data['utr'] ?? $data['utr_number'] ?? $data['rrn'] ?? $data['utrNumber'] ?? ''),
        ];
    }

    public static function qrImage(string $orderId): array
    {
        $base = rtrim((string) (getenv('FAMGATEWAY_BASE_URL') ?: 'https://famgateway.in'), '/');
        $url = $base . '/api/qr-image.php?' . http_build_query(['order_id' => $orderId]);
        $response = self::rawRequest($url, 'GET', [], null, 20);
        $contentType = strtolower(trim(explode(';', (string) ($response['content_type'] ?? 'image/png'))[0]));
        if ($contentType !== 'image/png' && $contentType !== 'image/jpeg' && $contentType !== 'image/webp') {
            throw new RuntimeException('QR image was not returned by the payment provider.');
        }
        return ['body' => $response['body'], 'content_type' => $contentType];
    }

    public static function buyProduct(string $productId, string $duration): array
    {
        $apiKey = trim((string) (getenv('XYZ_API_KEY') ?: ''));
        $masterKey = trim((string) (getenv('XYZ_MASTER_KEY') ?: ''));
        if ($apiKey === '' || $masterKey === '') {
            throw new RuntimeException('Reseller API credentials are not configured.');
        }
        $url = (string) (getenv('XYZ_API_URL') ?: 'https://bantibhaiya.to/api/reseller_v1.php');
        return self::jsonRequest($url, 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
            'x-master-key: ' . $masterKey,
        ], [
            'api_key' => $apiKey,
            'action' => 'buy',
            'product_id' => $productId,
            'duration' => $duration,
        ], 30, true);
    }

    private static function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private static function jsonRequest(string $url, string $method, array $headers, ?array $payload, int $timeout, bool $form = false): array
    {
        $body = null;
        if ($payload !== null) {
            $body = $form ? http_build_query($payload) : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $response = self::rawRequest($url, $method, $headers, $body, $timeout);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The API returned an invalid JSON response.');
        }
        return $decoded;
    }

    private static function rawRequest(string $url, string $method, array $headers, ?string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize the API request.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $content = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($content)) {
            throw new RuntimeException('API request failed (' . ($error !== '' ? 'network' : 'unknown') . ').');
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('API request returned HTTP ' . $code . '.');
        }
        return ['body' => $content, 'content_type' => $contentType, 'status' => $code];
    }
}
