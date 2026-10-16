<?php
declare(strict_types=1);

final class Catalog
{
    private static ?array $builtins = null;

    public static function builtins(): array
    {
        if (self::$builtins === null) {
            $raw = file_get_contents(__DIR__ . '/../catalog.json');
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                throw new RuntimeException('The product catalog could not be loaded.');
            }
            self::$builtins = $data;
        }
        return self::$builtins;
    }

    public static function keys(): array
    {
        $keys = array_keys(self::builtins());
        foreach (Database::rows("SELECT key FROM settings WHERE key LIKE 'catalog_product_%'") as $row) {
            $key = substr((string) $row['key'], strlen('catalog_product_'));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }

    public static function effective(string $key): array
    {
        $builtins = self::builtins();
        $product = $builtins[$key] ?? [
            'id' => '', 'name' => $key, 'category' => 'Digital access', 'durations' => [],
            'prices' => [], 'usd_prices' => [], 'stock' => [], 'api_duration' => [], 'features' => [],
        ];
        $raw = Database::setting('catalog_product_' . $key);
        if ($raw !== null) {
            $custom = json_decode($raw, true);
            if (is_array($custom)) {
                $product = array_replace($product, $custom);
            }
        }
        foreach (['durations', 'prices', 'usd_prices', 'stock', 'api_duration', 'features'] as $field) {
            if (!isset($product[$field]) || !is_array($product[$field])) {
                $product[$field] = [];
            }
        }
        foreach (($builtins[$key]['durations'] ?? []) as $duration) {
            if (!in_array($duration, $product['durations'], true)) {
                $product['durations'][] = $duration;
                foreach (['prices', 'usd_prices', 'stock', 'api_duration'] as $field) {
                    if (isset($builtins[$key][$field][$duration])) {
                        $product[$field][$duration] = $builtins[$key][$field][$duration];
                    }
                }
            }
        }
        $product['key'] = $key;
        $product['enabled'] = strtolower((string) Database::setting('enabled_' . $key, 'true')) === 'true';
        $product['maintenance'] = strtolower((string) Database::setting('maintenance_' . $key, 'false')) === 'true';
        $product['name'] = Database::setting('product_name_' . $key, (string) ($product['name'] ?? $key));
        $product['demo_url'] = Database::setting('product_demo_url_' . $key, (string) ($product['demo_url'] ?? ''));
        $product['demo_enabled'] = strtolower((string) Database::setting(
            'product_demo_enabled_' . $key,
            !empty($product['demo_enabled']) ? 'true' : 'false'
        )) === 'true';
        foreach ($product['durations'] as $duration) {
            $inr = Database::setting('price_' . $key . '_' . $duration);
            $usd = Database::setting('price_usd_' . $key . '_' . $duration);
            if ($inr !== null) {
                $product['prices'][$duration] = $inr;
            }
            if ($usd !== null && $usd !== '') {
                $product['usd_prices'][$duration] = $usd;
            }
        }
        $product['features'] = self::featureLabels($product['features']);
        return $product;
    }

    public static function publicProducts(): array
    {
        $result = [];
        foreach (self::keys() as $key) {
            $product = self::effective($key);
            if (!$product['enabled'] || $product['maintenance']) {
                continue;
            }
            $result[] = [
                'key' => $key,
                'name' => (string) ($product['name'] ?? $key),
                'category' => (string) ($product['category'] ?? 'Digital access'),
                'durations' => array_values($product['durations']),
                'prices' => $product['prices'],
                'features' => $product['features'],
                'demo_url' => $product['demo_enabled'] ? (string) $product['demo_url'] : '',
                'image_url' => '/api/store/images/' . rawurlencode($key),
            ];
        }
        return $result;
    }

    public static function adminProducts(): array
    {
        $images = [];
        foreach (Database::rows('SELECT product_key FROM store_product_images') as $row) {
            $images[(string) $row['product_key']] = true;
        }
        $builtins = self::builtins();
        $result = [];
        foreach (self::keys() as $key) {
            $product = self::effective($key);
            $product['is_builtin'] = array_key_exists($key, $builtins);
            $product['image_url'] = isset($images[$key]) ? '/api/store/images/' . rawurlencode($key) : '';
            $result[] = $product;
        }
        return $result;
    }

    public static function save(string $key, array $incoming, bool $creating = false): array
    {
        $allowed = ['id', 'name', 'category', 'durations', 'prices', 'usd_prices', 'stock', 'api_duration', 'feature_names', 'enabled', 'maintenance', 'demo_enabled', 'demo_url'];
        if (array_diff(array_keys($incoming), $allowed)) {
            throw new HttpError('Unsupported product fields.', 400);
        }
        $product = $creating ? self::effective($key) : self::effective($key);
        $name = trim((string) ($incoming['name'] ?? ($creating ? '' : $product['name'])));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new HttpError('Product name must be 1–120 characters.', 400);
        }
        $id = trim((string) ($incoming['id'] ?? ($creating ? '' : ($product['id'] ?? ''))));
        if ($id === '' || strlen($id) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new HttpError('Reseller product ID must use letters, numbers, hyphens, or underscores.', 400);
        }
        $category = trim((string) ($incoming['category'] ?? ($product['category'] ?? '')));
        if (mb_strlen($category) > 100) {
            throw new HttpError('Category must be at most 100 characters.', 400);
        }
        $durations = array_key_exists('durations', $incoming)
            ? self::durations($incoming['durations'])
            : array_values($product['durations'] ?? []);
        if ($creating) {
            $durations = self::durations($incoming['durations'] ?? []);
        }
        if (!$durations) {
            throw new HttpError('Add at least one product duration.', 400);
        }
        $prices = array_key_exists('prices', $incoming)
            ? self::mapping($incoming['prices'], $durations, $product['prices'] ?? [], 'inr', false)
            : self::mapping($product['prices'] ?? [], $durations, [], 'inr', false);
        if ($creating) {
            $prices = self::mapping($incoming['prices'] ?? null, $durations, [], 'inr', false);
        }
        $usd = array_key_exists('usd_prices', $incoming)
            ? self::mapping($incoming['usd_prices'], $durations, $product['usd_prices'] ?? [], 'usd', true)
            : self::mapping($product['usd_prices'] ?? [], $durations, [], 'usd', true);
        $stock = array_key_exists('stock', $incoming)
            ? self::mapping($incoming['stock'], $durations, $product['stock'] ?? [], 'stock', true)
            : self::mapping($product['stock'] ?? [], $durations, [], 'stock', true);
        $apiDurations = array_key_exists('api_duration', $incoming)
            ? self::mapping($incoming['api_duration'], $durations, $product['api_duration'] ?? [], 'duration', true)
            : self::mapping($product['api_duration'] ?? [], $durations, [], 'duration', true);
        $features = array_key_exists('feature_names', $incoming)
            ? self::featureLabels($incoming['feature_names'])
            : self::featureLabels($product['features'] ?? []);
        if (count($features) > 40) {
            throw new HttpError('Use no more than 40 product features.', 400);
        }
        $demoUrl = (string) ($incoming['demo_url'] ?? ($product['demo_url'] ?? ''));
        if (strlen($demoUrl) > 2048 || ($demoUrl !== '' && (!filter_var($demoUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($demoUrl, PHP_URL_SCHEME)) !== 'https'))) {
            throw new HttpError('Demo URL must be a valid HTTPS URL no longer than 2048 characters.', 400);
        }
        $saved = [
            'id' => $id, 'name' => $name, 'category' => $category, 'durations' => $durations,
            'prices' => $prices, 'usd_prices' => $usd, 'stock' => $stock,
            'api_duration' => $apiDurations, 'features' => $features,
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : (!$creating && !empty($product['enabled'])),
            'maintenance' => array_key_exists('maintenance', $incoming) ? (bool) $incoming['maintenance'] : (!$creating && !empty($product['maintenance'])),
            'demo_enabled' => array_key_exists('demo_enabled', $incoming) ? (bool) $incoming['demo_enabled'] : (!$creating && !empty($product['demo_enabled'])),
            'demo_url' => $demoUrl,
        ];
        Database::saveSetting('catalog_product_' . $key, json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        Database::saveSetting('product_name_' . $key, $name);
        Database::saveSetting('enabled_' . $key, $saved['enabled']);
        Database::saveSetting('maintenance_' . $key, $saved['maintenance']);
        Database::saveSetting('product_demo_enabled_' . $key, $saved['demo_enabled']);
        Database::saveSetting('product_demo_url_' . $key, $demoUrl);
        foreach ($durations as $duration) {
            Database::saveSetting('price_' . $key . '_' . $duration, $prices[$duration]);
            Database::saveSetting('price_usd_' . $key . '_' . $duration, $usd[$duration] ?? '');
        }
        return self::effective($key);
    }

    public static function delete(string $key): array
    {
        $builtins = self::builtins();
        $product = self::effective($key);
        if (array_key_exists($key, $builtins)) {
            Database::saveSetting('enabled_' . $key, 'false');
            Database::saveSetting('maintenance_' . $key, 'false');
            return ['ok' => true, 'disabled' => true];
        }
        foreach (['catalog_product_', 'enabled_', 'maintenance_', 'product_name_', 'product_demo_url_', 'product_demo_enabled_'] as $prefix) {
            Database::deleteSetting($prefix . $key);
        }
        foreach ($product['durations'] ?? [] as $duration) {
            Database::deleteSetting('price_' . $key . '_' . $duration);
            Database::deleteSetting('price_usd_' . $key . '_' . $duration);
        }
        $statement = Database::pdo()->prepare('DELETE FROM store_product_images WHERE product_key = ?');
        $statement->execute([$key]);
        return ['ok' => true, 'deleted' => true];
    }

    public static function featureLabels(mixed $features): array
    {
        if (!is_array($features)) {
            return [];
        }
        $result = [];
        foreach ($features as $feature) {
            $label = is_array($feature) ? (string) ($feature[0] ?? '') : (string) $feature;
            $label = trim($label);
            if ($label !== '') {
                $result[] = mb_substr($label, 0, 160);
            }
        }
        return $result;
    }

    private static function durations(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new HttpError('Durations must be a list.', 400);
        }
        $durations = array_map(static fn($v) => trim((string) $v), $raw);
        if (count($durations) < 1 || count($durations) > 30 || count(array_unique($durations)) !== count($durations)) {
            throw new HttpError('Enter 1–30 unique durations.', 400);
        }
        foreach ($durations as $duration) {
            if ($duration === '' || mb_strlen($duration) > 100) {
                throw new HttpError('Each duration must be 1–100 characters.', 400);
            }
        }
        return $durations;
    }

    private static function mapping(mixed $raw, array $durations, array $existing, string $kind, bool $allowEmpty): array
    {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw)) {
            throw new HttpError('Duration mappings must be JSON objects.', 400);
        }
        $out = [];
        foreach ($durations as $duration) {
            $value = $raw[$duration] ?? $existing[$duration] ?? null;
            if ($kind === 'inr' || $kind === 'usd') {
                $text = preg_replace('/[,$₹\s]/u', '', (string) ($value ?? ''));
                if ($text === '' && $allowEmpty) {
                    continue;
                }
                if (!is_numeric($text)) {
                    if ($kind === 'usd' && $allowEmpty) {
                        continue;
                    }
                    throw new HttpError('Enter a valid numeric price for every duration.', 400);
                }
                $number = (float) $text;
                if (!is_finite($number) || $number <= 0 || $number > 1000000) {
                    throw new HttpError('Prices must be greater than zero and no more than 1,000,000.', 400);
                }
                $out[$duration] = ($kind === 'inr' ? '₹' : '$') . number_format($number, 2, '.', '');
            } elseif ($kind === 'stock') {
                if ($value === null || $value === '') {
                    $out[$duration] = 999999;
                } elseif (filter_var(str_replace(',', '', (string) $value), FILTER_VALIDATE_INT) === false || (int) str_replace(',', '', (string) $value) < 0) {
                    throw new HttpError('Stock must be a non-negative whole number.', 400);
                } else {
                    $out[$duration] = (int) str_replace(',', '', (string) $value);
                }
            } else {
                $out[$duration] = mb_substr(trim((string) ($value ?? $duration)) ?: $duration, 0, 100);
            }
        }
        return $out;
    }
}
