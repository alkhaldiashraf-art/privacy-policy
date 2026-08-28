<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/src/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

/** @var Router $router */
$router = require BASE_PATH . '/routes/web.php';
$router->dispatch(Request::capture());
