<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectProfile;

final class ProjectController
{
    public function create(Request $request): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        View::renderLayout('app', 'projects/create', [
            'activeNav' => 'dashboard',
            'title' => 'New project',
            'old' => [],
        ]);
    }

    public function store(Request $request): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $name = $request->string('name');
        $url = $request->string('url');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'Please enter a project name.';
        }
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 2048)) {
            $errors[] = 'Please enter a valid URL, or leave it blank.';
        }

        if ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
            View::renderLayout('app', 'projects/create', [
                'activeNav' => 'dashboard',
                'title' => 'New project',
                'old' => ['name' => $name, 'url' => $url],
            ]);
            return;
        }

        $projectId = Project::create((int) Auth::id(), $name, $url === '' ? null : $url);
        AuditLog::record((int) Auth::id(), $projectId, 'project.created', ['name' => $name], $request->ip());

        header('Location: /projects/' . $projectId);
    }

    /**
     * Loads the project and the current user's role, or returns null and
     * writes a 404/redirect response if the user has no membership on it.
     * This is the single choke point that prevents IDOR: a project ID in
     * the URL is meaningless unless project_members has a row for this user.
     */
    private function authorize(int $projectId): ?array
    {
        $project = Project::findById($projectId);
        if ($project === null) {
            http_response_code(404);
            View::renderLayout('app', 'errors/404-inline', ['activeNav' => 'dashboard', 'title' => 'Not found']);
            return null;
        }

        $role = ProjectMember::roleFor($projectId, (int) Auth::id());
        if ($role === null) {
            http_response_code(404);
            View::renderLayout('app', 'errors/404-inline', ['activeNav' => 'dashboard', 'title' => 'Not found']);
            return null;
        }

        $project['role'] = $role;
        return $project;
    }

    public function show(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorize((int) $id);
        if ($project === null) {
            return;
        }

        View::renderLayout('app', 'projects/show', [
            'activeNav' => 'overview',
            'title' => $project['name'],
            'project' => $project,
        ]);
    }

    public function settings(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorize((int) $id);
        if ($project === null) {
            return;
        }

        $profile = ProjectProfile::forProject((int) $id) ?? [];

        View::renderLayout('app', 'projects/settings', [
            'activeNav' => 'settings',
            'title' => 'Settings',
            'project' => $project,
            'profile' => $profile,
        ]);
    }

    public function updateGeneral(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorize((int) $id);
        if ($project === null) {
            return;
        }

        if (!ProjectMember::canEdit($project['role'])) {
            http_response_code(403);
            View::renderLayout('app', 'errors/403-inline', [
                'activeNav' => 'settings',
                'title' => 'Forbidden',
                'project' => $project,
            ]);
            return;
        }

        $name = $request->string('name');
        $url = $request->string('url');
        $tagline = $request->string('tagline');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'Please enter a project name.';
        }
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 2048)) {
            $errors[] = 'Please enter a valid URL, or leave it blank.';
        }

        if ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
            header('Location: /projects/' . (int) $id . '/settings');
            return;
        }

        Project::updateGeneral((int) $id, $name, $url === '' ? null : $url);
        ProjectProfile::updateTagline((int) $id, $tagline === '' ? null : $tagline);

        AuditLog::record((int) Auth::id(), (int) $id, 'project.settings_updated', ['name' => $name], $request->ip());

        Session::flash('success', 'Project settings saved.');
        header('Location: /projects/' . (int) $id . '/settings');
    }
}
