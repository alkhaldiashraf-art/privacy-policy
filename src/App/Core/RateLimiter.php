<?php

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /**
     * Returns true if the action is allowed, false if the limit has been exceeded.
     * Uses a row-locked DB counter so it works correctly across PHP-FPM workers.
     */
    public static function attempt(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT attempts, window_start FROM rate_limits WHERE identifier = ? AND action = ? FOR UPDATE'
            );
            $stmt->execute([$identifier, $action]);
            $row = $stmt->fetch();

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            if ($row === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO rate_limits (identifier, action, attempts, window_start) VALUES (?, ?, 1, ?)'
                );
                $insert->execute([$identifier, $action, $now->format('Y-m-d H:i:s')]);
                $pdo->commit();
                return true;
            }

            $windowStart = new \DateTimeImmutable($row['window_start'], new \DateTimeZone('UTC'));
            $elapsed = $now->getTimestamp() - $windowStart->getTimestamp();

            if ($elapsed > $windowSeconds) {
                $update = $pdo->prepare(
                    'UPDATE rate_limits SET attempts = 1, window_start = ? WHERE identifier = ? AND action = ?'
                );
                $update->execute([$now->format('Y-m-d H:i:s'), $identifier, $action]);
                $pdo->commit();
                return true;
            }

            if ((int) $row['attempts'] >= $maxAttempts) {
                $pdo->commit();
                return false;
            }

            $update = $pdo->prepare(
                'UPDATE rate_limits SET attempts = attempts + 1 WHERE identifier = ? AND action = ?'
            );
            $update->execute([$identifier, $action]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
