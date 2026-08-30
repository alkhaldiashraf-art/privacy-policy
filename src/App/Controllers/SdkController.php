<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Models\ProjectProfile;
use App\Models\SdkEvent;
use App\Models\SdkSession;
use App\Scanning\GeoIpLookup;

/**
 * Public, cross-origin ingestion endpoint for the embeddable runtime SDK
 * (public/assets/js/sdk.js). Authenticated by a per-project public key —
 * the same trust model third-party RUM/analytics SDKs (Segment, Sentry,
 * GA) use for their collection endpoints — not by session/CSRF, since
 * requests originate from arbitrary customer websites.
 */
final class SdkController
{
    private const MAX_EVENTS_PER_REQUEST = 20;
    private const MAX_TEXT_LENGTH = 4000;

    public function collect(Request $request): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        $raw = file_get_contents('php://input', false, null, 0, 1024 * 1024);
        $payload = $raw !== false ? json_decode($raw, true) : null;

        if (!is_array($payload) || !isset($payload['key'], $payload['session_uid'])) {
            $this->respond(400, ['ok' => false, 'error' => 'Malformed payload.']);
            return;
        }

        $key = is_string($payload['key']) ? $payload['key'] : '';
        $sessionUid = is_string($payload['session_uid']) ? substr($payload['session_uid'], 0, 64) : '';
        if ($key === '' || $sessionUid === '') {
            $this->respond(400, ['ok' => false, 'error' => 'Malformed payload.']);
            return;
        }

        $projectId = ProjectProfile::projectIdForSdkPublicKey($key);
        if ($projectId === null) {
            $this->respond(401, ['ok' => false, 'error' => 'Unknown SDK key.']);
            return;
        }

        $ip = $request->ip();
        if (!RateLimiter::attempt("sdk:{$key}:{$ip}", 'sdk.collect', 120, 60)) {
            $this->respond(429, ['ok' => false, 'error' => 'Rate limit exceeded.']);
            return;
        }

        $sessionId = $this->resolveSession($projectId, $sessionUid, $payload, $ip);

        if (isset($payload['identity']) && is_string($payload['identity']) && $payload['identity'] !== '') {
            SdkSession::setIdentity($sessionId, substr(trim($payload['identity']), 0, 255));
        }

        $events = is_array($payload['events'] ?? null) ? array_slice($payload['events'], 0, self::MAX_EVENTS_PER_REQUEST) : [];
        $errorCount = 0;
        $signalCount = 0;

        foreach ($events as $event) {
            if (!is_array($event) || !isset($event['type']) || !in_array($event['type'], ['pageview', 'signal', 'error', 'heartbeat'], true)) {
                continue;
            }

            if ($event['type'] === 'heartbeat') {
                continue;
            }

            $occurredAt = $this->parseTimestamp($event['occurred_at'] ?? null);
            SdkEvent::insert(
                $sessionId,
                $event['type'],
                isset($event['name']) && is_string($event['name']) ? substr($event['name'], 0, 255) : null,
                isset($event['message']) && is_string($event['message']) ? substr($event['message'], 0, self::MAX_TEXT_LENGTH) : null,
                isset($event['stack']) && is_string($event['stack']) ? substr($event['stack'], 0, self::MAX_TEXT_LENGTH) : null,
                $occurredAt
            );

            if ($event['type'] === 'error') {
                $errorCount++;
            } elseif ($event['type'] === 'signal') {
                $signalCount++;
            }
        }

        SdkSession::touch($sessionId);
        SdkSession::incrementCounts($sessionId, $errorCount, $signalCount);

        $this->respond(200, ['ok' => true]);
    }

    private function resolveSession(int $projectId, string $sessionUid, array $payload, string $ip): int
    {
        // firstOrCreate() only writes the row's initial attributes the first
        // time this session_uid is seen; the country lookup below runs once
        // for the same reason (an outbound HTTPS call per event would be
        // wasteful and slow every heartbeat down for no benefit).
        $attrs = [
            'identity' => isset($payload['identity']) && is_string($payload['identity']) ? substr($payload['identity'], 0, 255) : null,
            'os' => isset($payload['os']) && is_string($payload['os']) ? substr($payload['os'], 0, 50) : null,
            'browser' => isset($payload['browser']) && is_string($payload['browser']) ? substr($payload['browser'], 0, 50) : null,
            'headless' => !empty($payload['headless']),
            'ip_hash' => hash('sha256', $ip),
        ];

        $sessionId = SdkSession::firstOrCreate($projectId, $sessionUid, $attrs);

        // Re-check whether a country was already resolved rather than
        // tracking "just created" separately — one extra indexed read,
        // and it means a session created before geoIP was reachable still
        // gets a country filled in on a later request.
        $session = SdkSession::findById($sessionId);
        if ($session !== null && $session['country_code'] === null) {
            $country = GeoIpLookup::countryCodeFor($ip);
            if ($country !== null) {
                $this->setCountry($sessionId, $country);
            }
        }

        return $sessionId;
    }

    private function setCountry(int $sessionId, string $countryCode): void
    {
        Database::pdo()
            ->prepare('UPDATE sdk_sessions SET country_code = ? WHERE id = ?')
            ->execute([$countryCode, $sessionId]);
    }

    private function parseTimestamp(mixed $value): string
    {
        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts !== false) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }
        return gmdate('Y-m-d H:i:s');
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body);
    }
}
