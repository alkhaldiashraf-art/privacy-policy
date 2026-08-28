<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower($email)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function create(string $name, string $email, string $password): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $name,
            mb_strtolower($email),
            password_hash($password, PASSWORD_BCRYPT),
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, $user['password_hash']);
    }
}
