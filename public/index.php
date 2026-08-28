<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// When running under PHP's built-in dev server (`php -S ... public/index.php`),
// this script is invoked for every request, including static assets — unlike
// Apache/Nginx in production, where .htaccess/server config serves files under
// public/assets directly and never reaches this router. Mirror that behavior
// here so `php -S` matches production instead of routing CSS/JS through the
// app router (which would 404 them).
if (PHP_SAPI === 'cli-server') {
    $requestedFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($requestedFile !== __DIR__ . '/' && is_file($requestedFile)) {
        return false;
    }
}

require BASE_PATH . '/src/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

/** @var Router $router */
$router = require BASE_PATH . '/routes/web.php';
$router->dispatch(Request::capture());
