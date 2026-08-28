<?php

declare(strict_types=1);

// CLI migration runner. Usage: php database/migrate.php
// (Hostinger Business/Cloud plans expose SSH for this. On plain shared
// hosting without SSH, use the web installer at /install instead.)

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\Migrator;

Env::load(BASE_PATH . '/.env');
Config::boot();

try {
    $applied = Migrator::run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($applied === []) {
    echo "Database already up to date. No migrations to apply." . PHP_EOL;
    exit(0);
}

echo "Applied migrations:" . PHP_EOL;
foreach ($applied as $name) {
    echo "  - {$name}" . PHP_EOL;
}
