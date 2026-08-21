<?php

namespace App\Models;

use App\Core\Database;

/**
 * Tracks failed login attempts per email+IP so the application can throttle
 * brute-force password guessing (see Auth::tooManyAttempts).
 */
class LoginAttempt
{
    /**
     * Log one failed login attempt.
     */
    public static function record(string $email, string $ip): void
    {
        Database::run(
            'INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, CURRENT_TIMESTAMP)',
            [$email, $ip]
        );
    }

    /**
     * Count failures for this email/IP since the given cutoff time.
     */
    public static function recentCount(string $email, string $ip, string $cutoff): int
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at >= ?',
            [$email, $ip, $cutoff]
        )->fetchColumn();
    }

    /**
     * Clear the failure history after a successful login.
     */
    public static function clear(string $email, string $ip): void
    {
        Database::run(
            'DELETE FROM login_attempts WHERE email = ? AND ip = ?',
            [$email, $ip]
        );
    }
}
