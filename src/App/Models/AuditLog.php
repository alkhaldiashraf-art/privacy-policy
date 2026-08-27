<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AuditLog
{
    public static function record(?int $userId, ?int $projectId, string $action, array $metadata, string $ip): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_logs (user_id, project_id, action, metadata, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId,
            $projectId,
            $action,
            $metadata === [] ? null : json_encode($metadata),
            $ip,
        ]);
    }
}
