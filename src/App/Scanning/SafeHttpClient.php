<?php

declare(strict_types=1);

namespace App\Scanning;

/**
 * A curl-based HTTP client hardened against SSRF: every hostname (including
 * ones reached via redirect) is resolved and validated as a public IP
 * *before* curl connects, and curl is pinned to that exact IP with
 * CURLOPT_RESOLVE so a DNS answer can't change between the check and the
 * connection (TOCTOU / DNS-rebinding). Redirects are followed manually, one
 * hop at a time, so each hop gets the same validation.
 */
final class SafeHttpClient
{
    private const MAX_REDIRECTS = 5;
    private const CONNECT_TIMEOUT = 8;
    private const TOTAL_TIMEOUT = 20;
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;
    private const USER_AGENT = 'SIUGOALS-Scanner/1.0 (+website audit tool)';

    public static function fetch(string $url): FetchResult
    {
        $current = $url;
        $redirects = 0;
        $chain = [];

        while (true) {
            $parts = parse_url($current);
            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                return FetchResult::error('Invalid URL.');
            }

            $scheme = strtolower($parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                return FetchResult::error('Only http/https URLs are supported.');
            }

            $host = $parts['host'];
            $ip = self::resolvePublicIp($host);
            if ($ip === null) {
                return FetchResult::error("The host \"{$host}\" does not resolve to a public, reachable address.");
            }

            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            $result = self::curlOnce($current, $host, $ip, $port);
            if ($result['error'] !== null) {
                return FetchResult::error($result['error']);
            }

            $chain[] = $current;
            $status = $result['status'];

            if (in_array($status, [301, 302, 303, 307, 308], true) && isset($result['headers']['location'])) {
                $redirects++;
                if ($redirects > self::MAX_REDIRECTS) {
                    return FetchResult::error('Too many redirects.');
                }
                $current = self::resolveRedirect($current, $result['headers']['location']);
                continue;
            }

            return FetchResult::success(
                finalUrl: $current,
                status: $status,
                headers: $result['headers'],
                setCookies: $result['setCookies'],
                body: $result['body'],
                tlsInfo: $result['tlsInfo'],
                totalTimeMs: $result['totalTimeMs'],
                redirectChain: $chain
            );
        }
    }

    /**
     * Resolves a hostname and returns the first IP that is not private,
     * reserved, or loopback — or null if none of its IPs are public.
     */
    public static function resolvePublicIp(string $host): ?string
    {
        // A literal IP in the URL is still checked, not trusted as-is.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? $host : null;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            $ipViaLookup = gethostbyname($host);
            if ($ipViaLookup !== $host && self::isPublicIp($ipViaLookup)) {
                return $ipViaLookup;
            }
            return null;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null && self::isPublicIp($ip)) {
                return $ip;
            }
        }

        return null;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function resolveRedirect(string $currentUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $path = $base['path'] ?? '/';
        $dir = rtrim(str_contains($path, '/') ? dirname($path) : '/', '/');
        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
    }

    /**
     * @return array{error:?string, status:int, headers:array<string,string>, body:string, tlsInfo:?array, totalTimeMs:float}
     */
    private static function curlOnce(string $url, string $host, string $ip, int $port): array
    {
        $ch = curl_init();
        $bytesRead = 0;
        $bodyChunks = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders, &$setCookies) {
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    if ($name === 'set-cookie') {
                        $setCookies[] = $value;
                    }
                    $responseHeaders[$name] = $value;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$bodyChunks, &$bytesRead) {
                $bytesRead += strlen($chunk);
                if ($bytesRead > self::MAX_BODY_BYTES) {
                    return -1;
                }
                $bodyChunks[] = $chunk;
                return strlen($chunk);
            },
        ]);
        $responseHeaders = [];
        $setCookies = [];

        $start = microtime(true);
        curl_exec($ch);
        $totalTimeMs = (microtime(true) - $start) * 1000;

        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => "Could not reach the site: {$error}", 'status' => 0, 'headers' => [], 'setCookies' => [], 'body' => '', 'tlsInfo' => null, 'totalTimeMs' => $totalTimeMs];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tlsInfo = null;
        if (str_starts_with(strtolower($url), 'https://')) {
            $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
            $verifyResult = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
            $tlsInfo = ['verify_result' => $verifyResult, 'cert' => $certInfo[0] ?? null];
        }
        curl_close($ch);

        return [
            'error' => null,
            'status' => $status,
            'headers' => $responseHeaders,
            'setCookies' => $setCookies,
            'body' => implode('', $bodyChunks),
            'tlsInfo' => $tlsInfo,
            'totalTimeMs' => $totalTimeMs,
        ];
    }
}
