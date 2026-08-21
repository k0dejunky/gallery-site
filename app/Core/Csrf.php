<?php

namespace App\Core;

/**
 * Cross-site request forgery protection. Every POST form carries a random
 * per-session token that must be echoed back, so a third-party site cannot
 * forge a request on behalf of a logged-in user.
 */
class Csrf
{
    /**
     * The session's CSRF token, generating one on first use.
     */
    public static function token(): string
    {
        Auth::start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Render the hidden input a form must include before any POST.
     */
    public static function field(): string
    {
        $token = self::token();

        return '<input type="hidden" name="_token" value="' . e($token) . '">';
    }

    /**
     * Confirm a submitted token matches the session token. Uses hash_equals
     * for a constant-time comparison that resists timing attacks.
     */
    public static function verify(?string $token): bool
    {
        Auth::start();

        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
