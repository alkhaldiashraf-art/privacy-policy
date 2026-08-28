<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

final class HomeController
{
    public function index(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            return;
        }

        View::renderLayout('guest', 'home/index');
    }
}
