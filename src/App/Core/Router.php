<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, params:array<int,string>, handler:array{0:string,1:string}}> */
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    /**
     * Path prefixes that are called cross-origin, from arbitrary customer
     * websites embedding the runtime SDK — not from our own session-backed
     * pages. Session cookies (and therefore CSRF tokens) don't apply to
     * them; they authenticate instead with a per-project public key
     * checked inside the controller, the same model third-party analytics
     * SDKs (Segment, Sentry, GA) use for their collection endpoints.
     */
    private const CSRF_EXEMPT_PREFIXES = ['/api/'];

    private function isCsrfExempt(string $path): bool
    {
        foreach (self::CSRF_EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        // Cross-origin preflight for the public SDK ingestion endpoint.
        // Handled before security headers/CSRF, which don't apply to it.
        if ($method === 'OPTIONS' && $this->isCsrfExempt($path)) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 600');
            http_response_code(204);
            return;
        }

        Config::sendSecurityHeaders();

        if ($request->isPost() && !$this->isCsrfExempt($path) && !Csrf::verify($request->string('_csrf'))) {
            http_response_code(419);
            $viewFile = BASE_PATH . '/resources/views/errors/419.php';
            if (is_file($viewFile)) {
                require $viewFile;
            } else {
                echo 'Session expired. Please go back and try again.';
            }
            return;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['params'], $matches) ?: [];

                [$controllerClass, $action] = $route['handler'];
                $controller = new $controllerClass();
                $controller->$action($request, ...array_values($params));
                return;
            }
        }

        http_response_code(404);
        $viewFile = BASE_PATH . '/resources/views/errors/404.php';
        if (is_file($viewFile)) {
            require $viewFile;
        } else {
            echo 'Not found';
        }
    }
}
