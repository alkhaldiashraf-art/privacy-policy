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

    public function dispatch(Request $request): void
    {
        Config::sendSecurityHeaders();

        $method = $request->method();
        $path = $request->path();

        if ($request->isPost() && !Csrf::verify($request->string('_csrf'))) {
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
