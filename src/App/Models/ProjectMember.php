<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ProjectMember
{
    public const ROLES = ['owner', 'admin', 'developer', 'viewer'];

    /** Roles that may edit project settings. */
    public const CAN_EDIT = ['owner', 'admin'];

    public static function add(int $projectId, int $userId, string $role): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO project_members (project_id, user_id, role, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$projectId, $userId, $role]);
    }

    public static function roleFor(int $projectId, int $userId): ?string
    {
        $stmt = Database::pdo()->prepare(
            'SELECT role FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row['role'];
    }

    public static function canEdit(?string $role): bool
    {
        return $role !== null && in_array($role, self::CAN_EDIT, true);
    }
}
