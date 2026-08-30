<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Controllers\ProjectController;
use App\Controllers\ScanController;
use App\Core\Router;

$router = new Router();

$router->get('/', [HomeController::class, 'index']);

$router->get('/install', [InstallController::class, 'show']);
$router->post('/install', [InstallController::class, 'run']);

$router->get('/signup', [AuthController::class, 'showSignup']);
$router->post('/signup', [AuthController::class, 'signup']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);

$router->get('/projects/create', [ProjectController::class, 'create']);
$router->post('/projects', [ProjectController::class, 'store']);
$router->get('/projects/{id}', [ProjectController::class, 'show']);
$router->get('/projects/{id}/settings', [ProjectController::class, 'settings']);
$router->post('/projects/{id}/settings', [ProjectController::class, 'updateGeneral']);

$router->get('/projects/{id}/scan', [ScanController::class, 'newScan']);
$router->post('/projects/{id}/scan/url', [ScanController::class, 'runUrlScan']);
$router->post('/projects/{id}/scan/upload', [ScanController::class, 'runUploadScan']);
$router->get('/scans/{id}', [ScanController::class, 'show']);

return $router;
