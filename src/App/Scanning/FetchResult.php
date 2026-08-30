<?php

declare(strict_types=1);

namespace App\Scanning;

final class FetchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly array $headers,
        public readonly array $setCookies,
        public readonly string $body,
        public readonly ?array $tlsInfo,
        public readonly float $totalTimeMs,
        public readonly array $redirectChain
    ) {
    }

    public static function success(
        string $finalUrl,
        int $status,
        array $headers,
        array $setCookies,
        string $body,
        ?array $tlsInfo,
        float $totalTimeMs,
        array $redirectChain
    ): self {
        return new self(true, null, $finalUrl, $status, $headers, $setCookies, $body, $tlsInfo, $totalTimeMs, $redirectChain);
    }

    public static function error(string $message): self
    {
        return new self(false, $message, '', 0, [], [], '', null, 0.0, []);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
