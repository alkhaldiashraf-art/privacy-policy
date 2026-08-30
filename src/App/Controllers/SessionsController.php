<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectProfile;
use App\Models\SdkEvent;
use App\Models\SdkSession;
use App\Scanning\AiPromptGenerator;

final class SessionsController
{
    private const PER_PAGE = 20;
    private const RANGE_TO_INTERVAL = [
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    /** Mirrors ProjectController::authorize() — the IDOR choke point for every project-scoped route. */
    private function authorizeProject(int $projectId): ?array
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

    public function index(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        $range = $request->string('range', '7d');
        $since = isset(self::RANGE_TO_INTERVAL[$range])
            ? gmdate('Y-m-d H:i:s', strtotime(self::RANGE_TO_INTERVAL[$range]))
            : null;
        $search = $request->string('q');
        $page = max(1, (int) $request->string('page', '1'));

        $result = SdkSession::forProject((int) $id, $since, $search, $page, self::PER_PAGE);

        View::renderLayout('app', 'sessions/index', [
            'activeNav' => 'sessions',
            'title' => 'Sessions',
            'project' => $project,
            'sessions' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'range' => $range,
            'search' => $search,
            'sdkPublicKey' => ProjectProfile::sdkPublicKeyFor((int) $id),
        ]);
    }

    public function show(Request $request, string $id, string $sessionId): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        $sdkSession = SdkSession::findById((int) $sessionId);
        if ($sdkSession === null || (int) $sdkSession['project_id'] !== (int) $id) {
            http_response_code(404);
            View::renderLayout('app', 'errors/404-inline', ['activeNav' => 'sessions', 'title' => 'Not found']);
            return;
        }

        $events = SdkEvent::forSession((int) $sessionId);
        $this->ensureFixPrompts($events);

        View::renderLayout('app', 'sessions/show', [
            'activeNav' => 'sessions',
            'title' => 'Session ' . $sdkSession['session_uid'],
            'project' => $project,
            'sdkSession' => $sdkSession,
            'events' => $events,
        ]);
    }

    /** Generates (and caches) a fix prompt for any error event that doesn't have one yet. */
    private function ensureFixPrompts(array &$events): void
    {
        $pending = [];
        foreach ($events as $i => $event) {
            if ($event['type'] === 'error' && empty($event['fix_prompt'])) {
                $pending[$i] = $event;
            }
        }
        if ($pending === []) {
            return;
        }

        $findings = [];
        $indexMap = [];
        foreach ($pending as $i => $event) {
            $indexMap[count($findings)] = $i;
            $findings[] = [
                'category' => 'runtime-error',
                'severity' => 'high',
                'title' => $event['name'] ?: 'Unhandled runtime error',
                'description' => (string) $event['message'],
                'recommendation' => null,
                'file_path' => null,
                'line_number' => null,
            ];
        }

        $ai = AiPromptGenerator::generate($findings, 'a live runtime error captured by the SIUGOALS SDK');
        foreach ($ai['perFinding'] as $j => $fix) {
            $eventIndex = $indexMap[$j] ?? null;
            if ($eventIndex === null) {
                continue;
            }
            SdkEvent::saveFixPrompt((int) $events[$eventIndex]['id'], $fix['prompt'], $fix['source']);
            $events[$eventIndex]['fix_prompt'] = $fix['prompt'];
            $events[$eventIndex]['fix_prompt_source'] = $fix['source'];
        }
    }

    public function updateIdentity(Request $request, string $id, string $sessionId): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        $sdkSession = SdkSession::findById((int) $sessionId);
        if ($sdkSession === null || (int) $sdkSession['project_id'] !== (int) $id) {
            http_response_code(404);
            View::renderLayout('app', 'errors/404-inline', ['activeNav' => 'sessions', 'title' => 'Not found']);
            return;
        }

        $identity = $request->string('identity');
        if (mb_strlen($identity) > 255) {
            Session::flash('error', 'Identity is too long.');
        } else {
            SdkSession::setIdentity((int) $sessionId, $identity);
            Session::flash('success', 'Identity updated.');
        }

        header('Location: /projects/' . (int) $id . '/sessions/' . (int) $sessionId);
    }
}
