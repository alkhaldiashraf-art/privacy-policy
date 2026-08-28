<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\View;

final class InstallController
{
    private function lockPath(): string
    {
        return BASE_PATH . '/storage/installed.lock';
    }

    private function isLocked(): bool
    {
        return is_file($this->lockPath());
    }

    public function show(Request $request): void
    {
        if ($this->isLocked()) {
            http_response_code(403);
            View::renderLayout('guest', 'install/locked');
            return;
        }

        View::renderLayout('guest', 'install/show', ['error' => null]);
    }

    public function run(Request $request): void
    {
        // Re-check the lock on every request. There is no query-string or
        // header bypass: once storage/installed.lock exists this endpoint
        // always refuses, permanently, until an operator removes the file.
        if ($this->isLocked()) {
            http_response_code(403);
            View::renderLayout('guest', 'install/locked');
            return;
        }

        try {
            $applied = Migrator::run();
        } catch (\Throwable $e) {
            View::renderLayout('guest', 'install/show', ['error' => $e->getMessage()]);
            return;
        }

        file_put_contents($this->lockPath(), gmdate('c') . " installed\n", LOCK_EX);

        View::renderLayout('guest', 'install/done', ['applied' => $applied]);
    }
}
