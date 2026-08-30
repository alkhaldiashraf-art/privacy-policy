<?php

declare(strict_types=1);

/**
 * Two supported layouts, auto-detected so deployment doesn't depend on
 * being able to change the host's Document Root setting (many shared
 * hosting accounts fix it to public_html with no override available):
 *
 *  A. Document Root points at this public/ folder directly, with the
 *     rest of the app one level up (dirname(__DIR__)) — the normal git
 *     checkout layout.
 *  B. This file's own folder IS the fixed Document Root (e.g.
 *     public_html itself, with this folder's siblings being index.php,
 *     .htaccess, assets/ copied in directly), and the rest of the app
 *     lives in a sibling folder named "app" next to it — NOT inside the
 *     Document Root, so it stays unreachable over the web. See
 *     DEPLOYMENT.md "Option B".
 */
$layoutA = dirname(__DIR__);
$layoutB = dirname(__DIR__) . '/app';

if (is_file($layoutA . '/src/bootstrap.php')) {
    define('BASE_PATH', $layoutA);
} elseif (is_file($layoutB . '/src/bootstrap.php')) {
    define('BASE_PATH', $layoutB);
} else {
    http_response_code(500);
    exit('SIUGOALS could not locate its application files (src/bootstrap.php). See DEPLOYMENT.md.');
}

require BASE_PATH . '/src/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

/** @var Router $router */
$router = require BASE_PATH . '/routes/web.php';
$router->dispatch(Request::capture());
