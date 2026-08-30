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

    /** Returns this project's SDK public key, generating one on first use. */
    public static function sdkPublicKeyFor(int $projectId): string
    {
        $existing = self::forProject($projectId);
        if ($existing !== null && !empty($existing['sdk_public_key'])) {
            return $existing['sdk_public_key'];
        }

        $key = 'sig_pub_' . bin2hex(random_bytes(16));
        $stmt = Database::pdo()->prepare(
            'UPDATE project_profiles SET sdk_public_key = ?, updated_at = NOW() WHERE project_id = ?'
        );
        $stmt->execute([$key, $projectId]);
        return $key;
    }

    /** Looks up the project a public SDK key belongs to, or null if the key is unknown. */
    public static function projectIdForSdkPublicKey(string $key): ?int
    {
        $stmt = Database::pdo()->prepare('SELECT project_id FROM project_profiles WHERE sdk_public_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? null : (int) $row['project_id'];
    }
}
