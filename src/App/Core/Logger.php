<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private static function path(): string
    {
        return BASE_PATH . '/storage/logs/app.log';
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        $dir = dirname(self::path());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $line = json_encode([
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES);

        file_put_contents(self::path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }
}
