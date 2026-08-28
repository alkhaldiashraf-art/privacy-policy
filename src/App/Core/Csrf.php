<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES);
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function verify(?string $submitted): bool
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || !is_string($submitted) || $token === '') {
            return false;
        }
        return hash_equals($token, $submitted);
    }

    public static function rotate(): void
    {
        Session::set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
