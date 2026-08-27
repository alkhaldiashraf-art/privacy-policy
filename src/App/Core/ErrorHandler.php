<?php

declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e): void {
            self::handle($e);
        });
    }

    public static function handle(\Throwable $e): void
    {
        $errorId = bin2hex(random_bytes(8));

        Logger::error($e->getMessage(), [
            'error_id' => $errorId,
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (Config::isDebug()) {
            echo '<pre style="white-space:pre-wrap;font-family:monospace;padding:24px;">';
            echo htmlspecialchars((string) $e, ENT_QUOTES);
            echo '</pre>';
            return;
        }

        $errorFile = BASE_PATH . '/resources/views/errors/500.php';
        if (is_file($errorFile)) {
            require $errorFile;
        } else {
            echo 'Something went wrong. Reference: ' . htmlspecialchars($errorId, ENT_QUOTES);
        }
    }
}
