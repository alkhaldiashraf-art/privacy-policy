<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $query;
    private array $body;
    private array $server;

    private function __construct(array $query, array $body, array $server)
    {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            return '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        $trustedProxies = array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))));
        if ($trustedProxies !== [] && in_array($remote, $trustedProxies, true)) {
            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $parts = explode(',', $forwarded);
                $candidate = trim($parts[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }
}
