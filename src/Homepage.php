<?php
declare(strict_types=1);

final class Homepage
{
    private const DEFAULTS = [
        'shop_name' => 'HEX CHEATS SHOP',
        'announcement' => 'Welcome to HEX CHEATS SHOP — secure checkout and digital delivery.',
        'hero_title' => 'Your next favorite access starts here.',
        'hero_subtitle' => 'Browse iOS, Android and PC products. Pick a duration, review the price, and keep your order key in your profile.',
    ];

    public static function publicConfig(): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = Database::setting('home_' . $key, $default) ?? $default;
        }
        return $settings + ['banners' => self::banners(false)];
    }

    public static function adminConfig(): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = Database::setting('home_' . $key, $default) ?? $default;
        }
        return $settings + ['banners' => self::banners(true)];
    }

    public static function updateSettings(array $body): array
    {
        $allowed = array_keys(self::DEFAULTS);
        if (array_diff(array_keys($body), $allowed)) {
            throw new HttpError('Unsupported homepage settings.', 400);
        }
        $limits = ['shop_name' => 80, 'announcement' => 300, 'hero_title' => 120, 'hero_subtitle' => 360];
        foreach ($limits as $key => $limit) {
            $value = trim((string) ($body[$key] ?? self::DEFAULTS[$key]));
            if ($value === '' || mb_strlen($value) > $limit) {
                throw new HttpError('Homepage text is empty or too long: ' . $key . '.', 400);
            }
            Database::saveSetting('home_' . $key, $value);
        }
        return ['ok' => true, 'homepage' => self::adminConfig()];
    }

    public static function updateBanner(int $slot, array $body): array
    {
        self::validSlot($slot);
        $allowed = ['title', 'subtitle', 'cta_label', 'cta_url'];
        if (array_diff(array_keys($body), $allowed)) {
            throw new HttpError('Unsupported banner fields.', 400);
        }
        $old = Database::row('SELECT title,subtitle,cta_label,cta_url FROM store_home_banners WHERE slot=?', [$slot]) ?? [];
        $title = trim((string) ($body['title'] ?? ($old['title'] ?? '')));
        $subtitle = trim((string) ($body['subtitle'] ?? ($old['subtitle'] ?? '')));
        $label = trim((string) ($body['cta_label'] ?? ($old['cta_label'] ?? 'Browse products')));
        $url = trim((string) ($body['cta_url'] ?? ($old['cta_url'] ?? '#catalog')));
        if (mb_strlen($title) > 120 || mb_strlen($subtitle) > 240 || mb_strlen($label) > 32) {
            throw new HttpError('Banner title, subtitle, or button label is too long.', 400);
        }
        if ($url !== '#catalog' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')) {
            throw new HttpError('Banner button URL must be #catalog or a valid HTTPS URL.', 400);
        }
        Database::pdo()->prepare(
            'INSERT INTO store_home_banners(slot,title,subtitle,cta_label,cta_url,updated_at) VALUES(?,?,?,?,?,?) '
            . 'ON CONFLICT(slot) DO UPDATE SET title=excluded.title,subtitle=excluded.subtitle,cta_label=excluded.cta_label,cta_url=excluded.cta_url,updated_at=excluded.updated_at'
        )->execute([$slot, $title, $subtitle, $label ?: 'Browse products', $url, time()]);
        return ['ok' => true, 'banner' => self::banners(true)[$slot - 1]];
    }

    public static function uploadBanner(int $slot): array
    {
        self::validSlot($slot);
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpError('Select a banner image to upload.', 400);
        }
        if ((int) $file['size'] < 1 || (int) $file['size'] > 8 * 1024 * 1024) {
            throw new HttpError('Banner image must be no larger than 8 MB.', 400);
        }
        $bytes = file_get_contents((string) $file['tmp_name']);
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new HttpError('Upload a valid PNG, JPEG, or WebP image.', 400);
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width * $height > 20000000) {
            throw new HttpError('Banner dimensions are invalid or too large.', 400);
        }
        $filename = substr(basename((string) ($file['name'] ?? 'banner')), 0, 255);
        $statement = Database::pdo()->prepare(
            'INSERT INTO store_home_banners(slot,content_type,filename,image,updated_at) VALUES(?,?,?,?,?) '
            . 'ON CONFLICT(slot) DO UPDATE SET content_type=excluded.content_type,filename=excluded.filename,image=excluded.image,updated_at=excluded.updated_at'
        );
        $statement->bindValue(1, $slot, PDO::PARAM_INT);
        $statement->bindValue(2, $mime);
        $statement->bindValue(3, $filename);
        $statement->bindValue(4, $bytes, PDO::PARAM_LOB);
        $statement->bindValue(5, time(), PDO::PARAM_INT);
        $statement->execute();
        return ['ok' => true, 'banner' => self::banners(true)[$slot - 1]];
    }

    public static function deleteBannerImage(int $slot): void
    {
        self::validSlot($slot);
        Database::pdo()->prepare('UPDATE store_home_banners SET content_type=\'\',filename=\'\',image=NULL,updated_at=? WHERE slot=?')
            ->execute([time(), $slot]);
    }

    public static function bannerImage(int $slot): void
    {
        self::validSlot($slot);
        $row = Database::row('SELECT content_type,image,updated_at FROM store_home_banners WHERE slot=? AND image IS NOT NULL', [$slot]);
        if (!$row) {
            throw new HttpError('Banner image not found.', 404);
        }
        header('Content-Type: ' . $row['content_type']);
        header('Cache-Control: public, max-age=900');
        header('ETag: "' . (int) $row['updated_at'] . '"');
        echo $row['image'];
    }

    private static function banners(bool $admin): array
    {
        $rows = [];
        for ($slot = 1; $slot <= 3; $slot++) {
            $row = Database::row('SELECT slot,title,subtitle,cta_label,cta_url,content_type,filename,image,updated_at FROM store_home_banners WHERE slot=?', [$slot]) ?? [];
            $hasImage = !empty($row['content_type']) && !empty($row['image']);
            $banner = [
                'slot' => $slot,
                'title' => (string) ($row['title'] ?? ''),
                'subtitle' => (string) ($row['subtitle'] ?? ''),
                'cta_label' => (string) (($row['cta_label'] ?? '') ?: 'Browse products'),
                'cta_url' => (string) (($row['cta_url'] ?? '') ?: '#catalog'),
                'image_url' => $hasImage ? '/api/store/banners/' . $slot . '/image?v=' . (int) ($row['updated_at'] ?? 0) : '',
            ];
            if ($admin) {
                $banner['filename'] = (string) ($row['filename'] ?? '');
                $banner['has_image'] = $hasImage;
            }
            $rows[] = $banner;
        }
        return $rows;
    }

    private static function validSlot(int $slot): void
    {
        if ($slot < 1 || $slot > 3) {
            throw new HttpError('Banner slot must be 1, 2, or 3.', 400);
        }
    }
}
