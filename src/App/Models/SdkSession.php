<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SdkSession
{
    /**
     * Creates the session row on its first event, or returns the existing
     * id for a session_uid already seen for this project.
     */
    public static function firstOrCreate(int $projectId, string $sessionUid, array $attrs): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM sdk_sessions WHERE project_id = ? AND session_uid = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $sessionUid]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return (int) $row['id'];
        }

        $insert = Database::pdo()->prepare(
            'INSERT INTO sdk_sessions
                (project_id, session_uid, identity, os, browser, headless, country_code, ip_hash,
                 started_at, last_seen_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())'
        );
        $insert->execute([
            $projectId,
            $sessionUid,
            $attrs['identity'] ?? null,
            $attrs['os'] ?? null,
            $attrs['browser'] ?? null,
            !empty($attrs['headless']) ? 1 : 0,
            $attrs['country_code'] ?? null,
            $attrs['ip_hash'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function touch(int $sessionId): void
    {
        Database::pdo()->prepare('UPDATE sdk_sessions SET last_seen_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$sessionId]);
    }

    public static function incrementCounts(int $sessionId, int $errors, int $signals): void
    {
        if ($errors === 0 && $signals === 0) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE sdk_sessions SET error_count = error_count + ?, signal_count = signal_count + ? WHERE id = ?'
        )->execute([$errors, $signals, $sessionId]);
    }

    public static function setIdentity(int $sessionId, string $identity): void
    {
        Database::pdo()->prepare('UPDATE sdk_sessions SET identity = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$identity === '' ? null : $identity, $sessionId]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sdk_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array{rows:array<int,array>, total:int}
     */
    public static function forProject(int $projectId, ?string $since, string $search, int $page, int $perPage): array
    {
        $where = ['project_id = ?'];
        $params = [$projectId];

        if ($since !== null) {
            $where[] = 'started_at >= ?';
            $params[] = $since;
        }
        if ($search !== '') {
            $where[] = '(identity LIKE ? OR session_uid LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = Database::pdo()->prepare("SELECT COUNT(*) FROM sdk_sessions WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM sdk_sessions WHERE {$whereSql} ORDER BY started_at DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }
}
