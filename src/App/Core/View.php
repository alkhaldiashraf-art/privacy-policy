<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        $file = BASE_PATH . '/resources/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$view}");
        }

        extract($data, EXTR_SKIP);
        require $file;
    }

    public static function renderLayout(string $layout, string $view, array $data = []): void
    {
        ob_start();
        self::render($view, $data);
        $content = ob_get_clean();
        self::render('layouts/' . $layout, array_merge($data, ['content' => $content]));
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
