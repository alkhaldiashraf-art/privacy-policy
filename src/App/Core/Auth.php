<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class Auth
{
    private static ?array $userCache = null;

    public static function check(): bool
    {
        return Session::get('user_id') !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get('user_id');
        return $id !== null ? (int) $id : null;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$userCache !== null) {
            return self::$userCache;
        }
        self::$userCache = User::findById((int) self::id());
        return self::$userCache;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', $user['id']);
        Csrf::rotate();
        self::$userCache = $user;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$userCache = null;
    }
}
