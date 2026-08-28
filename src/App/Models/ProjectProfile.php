<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ProjectProfile
{
    public static function forProject(int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM project_profiles WHERE project_id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function updateTagline(int $projectId, ?string $tagline): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE project_profiles SET tagline = ?, updated_at = NOW() WHERE project_id = ?'
        );
        $stmt->execute([$tagline, $projectId]);
    }
}
