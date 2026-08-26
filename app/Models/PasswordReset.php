<?php

namespace App\Models;

use App\Core\Database;

class PasswordReset
{
    public static function create(string $email, string $token): void
    {
        Database::run(
            'DELETE FROM password_resets WHERE email = ?',
            [$email]
        );
        Database::run(
            'INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$email, $token]
        );
    }

    public static function findByToken(string $token): ?array
    {
        $row = Database::run(
            'SELECT * FROM password_resets WHERE token = ?
             AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
             LIMIT 1',
            [$token]
        )->fetch();
        return $row ?: null;
    }

    public static function deleteByToken(string $token): void
    {
        Database::run('DELETE FROM password_resets WHERE token = ?', [$token]);
    }

    public static function cleanup(): void
    {
        Database::run("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}
