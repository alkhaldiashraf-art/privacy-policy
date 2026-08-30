<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ScanFinding
{
    /** Severities ordered worst-first, used for sorting reports. */
    public const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'info'];

    public static function insert(int $scanId, array $finding): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO scan_findings
                (scan_id, category, severity, title, description, recommendation,
                 file_path, line_number, fix_prompt, fix_prompt_source, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $scanId,
            $finding['category'],
            $finding['severity'],
            $finding['title'],
            $finding['description'],
            $finding['recommendation'] ?? null,
            $finding['file_path'] ?? null,
            $finding['line_number'] ?? null,
            $finding['fix_prompt'] ?? null,
            $finding['fix_prompt_source'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function updateFixPrompt(int $findingId, string $prompt, string $source): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE scan_findings SET fix_prompt = ?, fix_prompt_source = ? WHERE id = ?'
        );
        $stmt->execute([$prompt, $source, $findingId]);
    }

    /** Findings for a scan, worst severity first. */
    public static function forScan(int $scanId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM scan_findings WHERE scan_id = ?
             ORDER BY FIELD(severity, 'critical','high','medium','low','info'), id ASC"
        );
        $stmt->execute([$scanId]);
        return $stmt->fetchAll();
    }
}
