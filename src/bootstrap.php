<?php

declare(strict_types=1);

require BASE_PATH . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Session;

Env::load(BASE_PATH . '/.env');
Config::boot();
ErrorHandler::register();
Session::start();
