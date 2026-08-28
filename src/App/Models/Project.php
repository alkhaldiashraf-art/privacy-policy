<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Project
{
    public static function create(int $ownerId, string $name, ?string $url): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO projects (owner_id, name, url, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$ownerId, $name, $url]);
            $projectId = (int) $pdo->lastInsertId();

            ProjectMember::add($projectId, $ownerId, 'owner');

            $profile = $pdo->prepare(
                'INSERT INTO project_profiles (project_id, created_at, updated_at) VALUES (?, NOW(), NOW())'
            );
            $profile->execute([$projectId]);

            $pdo->commit();
            return $projectId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Projects the given user is a member of, most recently updated first. */
    public static function forUser(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, pm.role FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id
             WHERE pm.user_id = ?
             ORDER BY p.updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function updateGeneral(int $id, string $name, ?string $url): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE projects SET name = ?, url = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$name, $url, $id]);
    }
}
