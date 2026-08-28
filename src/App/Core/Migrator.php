<?php

declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    /**
     * Creates schema_migrations if missing, then applies every migration
     * file that hasn't run yet, in filename order. Additive only: migration
     * files are never edited after being committed, only added to.
     *
     * @return array<int, string> names of migrations that were applied
     */
    public static function run(): array
    {
        $pdo = Database::pdo();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY schema_migrations_migration_unique (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $already = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $already = array_flip($already);

        $dir = BASE_PATH . '/database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        $applied = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($already[$name])) {
                continue;
            }

            $statements = require $file;
            if (!is_array($statements)) {
                throw new \RuntimeException("Migration {$name} must return an array of SQL statements");
            }

            try {
                // MySQL/MariaDB DDL statements (CREATE TABLE, etc.) each
                // implicitly commit, so a transaction can't wrap them —
                // migrations must therefore only ever ADD schema, never
                // destructively rewrite it, so a failure partway through
                // is safe to fix by hand and re-run (already-created
                // tables are skipped on the next attempt because this
                // whole migration is only marked applied at the end).
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())'
                );
                $insert->execute([$name]);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }

            $applied[] = $name;
        }

        return $applied;
    }
}
