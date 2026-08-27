<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\User;

final class AuthController
{
    public function showSignup(Request $request): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            return;
        }
        View::renderLayout('guest', 'auth/signup', ['old' => []]);
    }

    public function signup(Request $request): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            return;
        }

        $allowed = RateLimiter::attempt(
            $request->ip(),
            'signup',
            Env::int('RATE_LIMIT_SIGNUP_MAX', 5),
            Env::int('RATE_LIMIT_SIGNUP_WINDOW_SECONDS', 3600)
        );

        if (!$allowed) {
            http_response_code(429);
            Session::flash('error', 'Too many signup attempts. Please try again later.');
            View::renderLayout('guest', 'auth/signup', ['old' => $request->all()]);
            return;
        }

        $name = $request->string('name');
        $email = $request->string('email');
        $password = (string) $request->input('password', '');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 255) {
            $errors[] = 'Please enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if ($errors === [] && User::findByEmail($email) !== null) {
            $errors[] = 'That email is already registered.';
        }

        if ($errors !== []) {
            Session::flash('error', implode(' ', $errors));
            View::renderLayout('guest', 'auth/signup', ['old' => ['name' => $name, 'email' => $email]]);
            return;
        }

        $userId = User::create($name, $email, $password);
        $user = User::findById($userId);

        AuditLog::record($userId, null, 'user.signup', [], $request->ip());

        Auth::login($user);

        header('Location: /dashboard');
    }

    public function showLogin(Request $request): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            return;
        }
        View::renderLayout('guest', 'auth/login', ['old' => []]);
    }

    public function login(Request $request): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            return;
        }

        $email = $request->string('email');
        $password = (string) $request->input('password', '');

        $allowed = RateLimiter::attempt(
            $request->ip() . '|' . mb_strtolower($email),
            'login',
            Env::int('RATE_LIMIT_LOGIN_MAX', 5),
            Env::int('RATE_LIMIT_LOGIN_WINDOW_SECONDS', 300)
        );

        if (!$allowed) {
            http_response_code(429);
            Session::flash('error', 'Too many login attempts. Please try again later.');
            View::renderLayout('guest', 'auth/login', ['old' => ['email' => $email]]);
            return;
        }

        $user = User::findByEmail($email);

        if ($user === null || !User::verifyPassword($user, $password)) {
            AuditLog::record(null, null, 'user.login_failed', ['email' => $email], $request->ip());
            Session::flash('error', 'Invalid email or password.');
            View::renderLayout('guest', 'auth/login', ['old' => ['email' => $email]]);
            return;
        }

        Auth::login($user);
        AuditLog::record((int) $user['id'], null, 'user.login', [], $request->ip());

        header('Location: /dashboard');
    }

    public function logout(Request $request): void
    {
        $userId = Auth::id();
        Auth::logout();
        AuditLog::record($userId, null, 'user.logout', [], $request->ip());
        header('Location: /login');
    }
}
