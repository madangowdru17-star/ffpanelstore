<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/HttpError.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Catalog.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Integrations.php';
require_once __DIR__ . '/../src/App.php';

App::run();
