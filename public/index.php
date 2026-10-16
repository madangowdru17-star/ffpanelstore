<?php
declare(strict_types=1);

$requestPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
$assetPath = __DIR__ . $requestPath;
if ($requestPath !== '/' && !str_contains($requestPath, '..') && is_file($assetPath)
    && in_array(strtolower(pathinfo($assetPath, PATHINFO_EXTENSION)), ['js', 'css', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico'], true)) {
    return false;
}

require_once __DIR__ . '/../src/HttpError.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Catalog.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Integrations.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/App.php';

App::run();
