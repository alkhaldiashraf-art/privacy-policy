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
use App\Models\Scan;
use App\Models\ScanFinding;
use App\Scanning\AiPromptGenerator;
use App\Scanning\CodeScanner;
use App\Scanning\UrlScanner;

final class ScanController
{
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
    private const SEVERITY_WEIGHT = ['critical' => 25, 'high' => 12, 'medium' => 5, 'low' => 2, 'info' => 0];

    /**
     * Loads the project and the current user's role, or returns null and
     * writes a 404 response if the user has no membership on it. Mirrors
     * ProjectController::authorize() — the single choke point that prevents
     * IDOR on any project-scoped route.
     */
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

    public function newScan(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        View::renderLayout('app', 'scan/new', [
            'activeNav' => 'scan',
            'title' => 'Scan',
            'project' => $project,
            'recentScans' => Scan::forProject((int) $id, 10),
        ]);
    }

    public function runUrlScan(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        $url = $request->string('url');
        if ($url === '') {
            $url = (string) ($project['url'] ?? '');
        }

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || mb_strlen($url) > 2048) {
            Session::flash('error', 'Please enter a valid URL to scan.');
            header('Location: /projects/' . (int) $id . '/scan');
            return;
        }

        $scanId = Scan::create((int) $id, (int) Auth::id(), 'url', $url, parse_url($url, PHP_URL_HOST) ?: $url);

        $result = UrlScanner::scan($url);
        if (!$result['ok']) {
            Scan::markFailed($scanId, $result['error']);
            AuditLog::record((int) Auth::id(), (int) $id, 'scan.url.failed', ['url' => $url], $request->ip());
            header('Location: /scans/' . $scanId);
            return;
        }

        $this->persistFindingsAndComplete($scanId, $result['findings'], "the website {$url}");

        AuditLog::record((int) Auth::id(), (int) $id, 'scan.url.completed', ['url' => $url], $request->ip());

        header('Location: /scans/' . $scanId);
    }

    public function runUploadScan(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $project = $this->authorizeProject((int) $id);
        if ($project === null) {
            return;
        }

        $file = $request->file('code_file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $message = $file !== null && $file['error'] === UPLOAD_ERR_INI_SIZE
                ? 'The file is larger than this server allows.'
                : 'Please choose a .zip of your project (or a single source file) to upload.';
            Session::flash('error', $message);
            header('Location: /projects/' . (int) $id . '/scan');
            return;
        }

        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            Session::flash('error', 'The uploaded file is larger than the 20 MB limit.');
            header('Location: /projects/' . (int) $id . '/scan');
            return;
        }

        $originalName = basename($file['name']);
        $scanId = Scan::create((int) $id, (int) Auth::id(), 'upload', null, $originalName);

        $result = CodeScanner::scanUploadedFile($file['tmp_name'], $originalName);
        if (!$result['ok']) {
            Scan::markFailed($scanId, $result['error']);
            AuditLog::record((int) Auth::id(), (int) $id, 'scan.upload.failed', ['file' => $originalName], $request->ip());
            header('Location: /scans/' . $scanId);
            return;
        }

        $this->persistFindingsAndComplete($scanId, $result['findings'], "the uploaded project \"{$originalName}\"");

        AuditLog::record((int) Auth::id(), (int) $id, 'scan.upload.completed', [
            'file' => $originalName,
            'files_scanned' => $result['filesScanned'],
        ], $request->ip());

        header('Location: /scans/' . $scanId);
    }

    private function persistFindingsAndComplete(int $scanId, array $findings, string $contextLabel): void
    {
        $ai = AiPromptGenerator::generate($findings, $contextLabel);

        foreach ($findings as $i => $finding) {
            $fix = $ai['perFinding'][$i] ?? null;
            if ($fix !== null) {
                $finding['fix_prompt'] = $fix['prompt'];
                $finding['fix_prompt_source'] = $fix['source'];
            }
            ScanFinding::insert($scanId, $finding);
        }

        $score = $this->computeScore($findings);
        $summary = $this->summarize($findings);

        Scan::markCompleted($scanId, $score, $summary, $ai['comprehensive'] !== '' ? $ai['comprehensive'] : null, $ai['comprehensive'] !== '' ? $ai['comprehensiveSource'] : null);
    }

    private function computeScore(array $findings): int
    {
        $score = 100;
        foreach ($findings as $f) {
            $score -= self::SEVERITY_WEIGHT[$f['severity']] ?? 0;
        }
        return max(0, min(100, $score));
    }

    private function summarize(array $findings): string
    {
        $counts = array_fill_keys(array_keys(self::SEVERITY_WEIGHT), 0);
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }
        if (array_sum($counts) === 0) {
            return 'No issues were found by this scan.';
        }
        $parts = [];
        foreach ($counts as $severity => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$severity}";
            }
        }
        return 'Found ' . implode(', ', $parts) . ' finding(s).';
    }

    public function show(Request $request, string $id): void
    {
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $scan = Scan::findById((int) $id);
        if ($scan === null) {
            http_response_code(404);
            View::renderLayout('app', 'errors/404-inline', ['activeNav' => 'dashboard', 'title' => 'Not found']);
            return;
        }

        // Choke point against IDOR: a scan is only visible if the current
        // user is a member of the project it belongs to.
        $project = $this->authorizeProject((int) $scan['project_id']);
        if ($project === null) {
            return;
        }

        $findings = ScanFinding::forScan((int) $id);

        View::renderLayout('app', 'scan/show', [
            'activeNav' => 'scan',
            'title' => 'Scan report',
            'project' => $project,
            'scan' => $scan,
            'findings' => $findings,
        ]);
    }
}
