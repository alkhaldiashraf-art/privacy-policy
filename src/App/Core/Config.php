<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        error_reporting(E_ALL);
        ini_set('display_errors', Env::bool('APP_DEBUG', false) ? '1' : '0');
        date_default_timezone_set('UTC');
    }

    public static function appName(): string
    {
        return Env::get('APP_NAME', 'SIUGOALS');
    }

    public static function appUrl(): string
    {
        return rtrim(Env::get('APP_URL', ''), '/');
    }

    public static function isDebug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $trustedProxies = array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))));
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($trustedProxies !== [] && in_array($remoteAddr, $trustedProxies, true)) {
            $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (strtolower($proto) === 'https') {
                return true;
            }
        }

        return false;
    }

    public static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "style-src 'self' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "script-src 'self'; "
            . "img-src 'self' data:; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
