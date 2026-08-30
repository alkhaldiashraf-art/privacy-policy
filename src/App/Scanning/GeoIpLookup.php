<?php

declare(strict_types=1);

namespace App\Scanning;

use App\Core\Logger;

/**
 * Best-effort country lookup for an IP address via a free, keyless HTTPS
 * API. This is a real network call, not a guess — but it's inherently
 * best-effort (the API can be down or rate-limited), so callers must treat
 * a null result as "unknown" and never fabricate a country.
 */
final class GeoIpLookup
{
    private const TIMEOUT_SECONDS = 3;

    public static function countryCodeFor(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $ch = curl_init('https://ipapi.co/' . urlencode($ip) . '/country/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_USERAGENT => 'SIUGOALS-Runtime/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Logger::warning('GeoIP lookup failed', ['http_code' => $httpCode, 'curl_error' => $error]);
            return null;
        }

        $code = strtoupper(trim($response));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }
}
