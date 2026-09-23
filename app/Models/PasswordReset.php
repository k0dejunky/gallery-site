<?php

namespace App\Models;

use App\Core\Database;

class PasswordReset
{
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function create(string $email, string $token): void
    {
        Database::run(
            'DELETE FROM password_resets WHERE email = ?',
            [$email]
        );
        Database::run(
            'INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$email, self::hashToken($token)]
        );
    }

    public static function findByToken(string $token): ?array
    {
        // Look up by the SHA-256 hash of the token. Rows created before token
        // hashing stored the raw value; fall back to a raw match so an
        // in-flight reset email still works during the transition.
        $row = Database::run(
            'SELECT * FROM password_resets WHERE token = ?
             AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
             LIMIT 1',
            [self::hashToken($token)]
        )->fetch();

        if ($row === false) {
            $row = Database::run(
                'SELECT * FROM password_resets WHERE token = ?
                 AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)
                 LIMIT 1',
                [$token]
            )->fetch();
        }

        return $row ?: null;
    }

    public static function deleteByToken(string $token): void
    {
        Database::run('DELETE FROM password_resets WHERE token = ?', [self::hashToken($token)]);
    }

    public static function cleanup(): void
    {
        Database::run("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}