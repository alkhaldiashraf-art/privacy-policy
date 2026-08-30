<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Scan
{
    public static function create(int $projectId, int $userId, string $type, ?string $targetUrl, ?string $sourceLabel): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO scans (project_id, user_id, type, target_url, source_label, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$projectId, $userId, $type, $targetUrl, $sourceLabel, 'completed']);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function markCompleted(
        int $scanId,
        int $score,
        string $summary,
        ?string $comprehensivePrompt,
        ?string $comprehensivePromptSource
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE scans
             SET status = ?, score = ?, summary = ?, comprehensive_fix_prompt = ?,
                 comprehensive_fix_prompt_source = ?, completed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute(['completed', $score, $summary, $comprehensivePrompt, $comprehensivePromptSource, $scanId]);
    }

    public static function markFailed(int $scanId, string $errorMessage): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE scans SET status = ?, error_message = ?, completed_at = NOW() WHERE id = ?'
        );
        $stmt->execute(['failed', $errorMessage, $scanId]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM scans WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Scans for a project, most recent first. */
    public static function forProject(int $projectId, int $limit = 20): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM scans WHERE project_id = ? ORDER BY created_at DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }
}
