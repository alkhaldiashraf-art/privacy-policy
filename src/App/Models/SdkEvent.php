<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SdkEvent
{
    public static function insert(int $sessionId, string $type, ?string $name, ?string $message, ?string $stack, string $occurredAt): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO sdk_events (session_id, type, name, message, stack, occurred_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$sessionId, $type, $name, $message, $stack, $occurredAt]);
    }

    /** All events for a session, oldest first — used for the activity timeline and the event log. */
    public static function forSession(int $sessionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM sdk_events WHERE session_id = ? ORDER BY occurred_at ASC');
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public static function errorsForSession(int $sessionId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM sdk_events WHERE session_id = ? AND type = 'error' ORDER BY occurred_at ASC"
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    /** Caches a generated fix prompt on the event row so it's only generated once. */
    public static function saveFixPrompt(int $eventId, string $prompt, string $source): void
    {
        Database::pdo()->prepare('UPDATE sdk_events SET fix_prompt = ?, fix_prompt_source = ? WHERE id = ?')
            ->execute([$prompt, $source, $eventId]);
    }
}
