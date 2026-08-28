<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\Project;

final class DashboardController
{
    public function index(Request $request): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $projects = Project::forUser((int) Auth::id());

        View::renderLayout('app', 'dashboard/index', [
            'projects' => $projects,
            'activeNav' => 'dashboard',
            'title' => 'Projects',
        ]);
    }
}
