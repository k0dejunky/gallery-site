<?php

namespace App\Core;

use App\Models\LoginAttempt;
use App\Models\Subscription;
use App\Models\User;

/**
 * Authentication helpers: session lifecycle, credential verification, login
 * attempt throttling and access guards shared by all controllers.
 */
class Auth
{
    public const ADMIN_ROLES = ['super_admin', 'admin', 'editor', 'moderator', 'viewer'];
    public const PERMISSIONS = [
        'super_admin' => ['*'],
        'admin'      => ['dashboard', 'trends', 'galleries', 'videos', 'categories', 'users', 'membership', 'theme', 'site_editor', 'logs', 'documentation'],
        'editor'     => ['dashboard', 'trends', 'galleries', 'videos', 'categories', 'documentation'],
        'moderator'  => ['dashboard', 'users', 'membership', 'logs', 'documentation'],
        'viewer'     => ['dashboard', 'trends', 'documentation'],
    ];
    /**
     * Begin the PHP session if it has not already been started. Called before
     * any session access so the session cookie is set consistently.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Verify an email/password pair. Returns true on success, false on a
     * bad credential, or a message string when the user is locked out by too
     * many recent failures. Successful logins clear the attempt history.
     */
    public static function attempt(string $email, string $password, string $ip)
    {
        if (self::tooManyAttempts($email, $ip)) {
            return 'Too many failed attempts. Please try again later.';
        }

        $user = User::findByEmail($email);

        if ($user !== null && password_verify($password, $user['password_hash'])) {
            self::loginUser((int) $user['id']);
            LoginAttempt::clear($email, $ip);

            return true;
        }

        LoginAttempt::record($email, $ip);

        return false;
    }

    /**
     * Mark a user as logged in: regenerate the session id to prevent session
     * fixation, then store the user id in the session.
     */
    public static function loginUser(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        \App\Core\Database::run(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Whether the current request is authenticated.
     */
    public static function check(): bool
    {
        self::start();

        return isset($_SESSION['user_id']);
    }

    private static ?array $userCache = null;

    /**
     * The logged-in user row (fresh from the database) or null if anonymous.
     * Cached for the request to avoid repeated DB hits.
     */
    public static function user(): ?array
    {
        self::start();

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        if (self::$userCache === null) {
            self::$userCache = User::find((int) $_SESSION['user_id']);
        }

        return self::$userCache;
    }

    /**
     * Whether the current user has the admin role.
     */
    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && in_array($user['role'], self::ADMIN_ROLES, true);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null || !self::isAdmin()) return false;
        $permissions = self::PERMISSIONS[$user['role']] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::check()) {
            header('Location: ' . url('/admin'));
            exit;
        }
        if (!self::can($permission)) {
            Flash::set('error', 'You do not have permission to do that.');
            header('Location: ' . url('/admin'));
            exit;
        }
    }

    /**
     * Where a logged-in user should land after login: admins go to the admin
     * panel, everyone else to their gallery home page.
     */
    public static function homePath(): string
    {
        if (self::isAdmin()) {
            return '/admin';
        }

        return self::hasActiveSubscription() ? '/galleries' : '/membership';
    }

    /**
     * Whether the current user has a usable paid subscription. Admins always
     * count as members so the admin role itself grants access.
     */
    public static function hasActiveSubscription(): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        return Subscription::isActive((int) $user['id']);
    }

    public static function canUseCustomTheme(): bool
    {
        $user = self::user();
        if ($user === null) return false;
        $active = Subscription::activeFor((int) $user['id']);
        return $active !== null && in_array($active['billing_cycle'] ?? '', ['yearly', 'lifetime'], true);
    }

    /**
     * Guard for member-only pages: redirects users without a usable
     * subscription to the membership page so they can see the available
     * plans. Anonymous visitors are sent to the login page.
     */
    public static function requireSubscription(): void
    {
        self::start();

        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . url('/login'));
            exit;
        }

        if (!self::hasActiveSubscription()) {
            Flash::set('error', 'A membership is required to view that content.');
            header('Location: ' . url('/membership'));
            exit;
        }
    }

    /**
     * Whether the current user's active membership reaches at least the given
     * plan level. Admins always count as the highest level.
     */
    public static function hasMembershipLevel(int $level): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        return Subscription::hasMinLevel((int) $user['id'], $level);
    }

    /**
     * Guard for actions that need a membership of at least the given plan
     * level: users who do not qualify are redirected to the membership page
     * with the given message. Anonymous visitors go to the login page.
     */
    public static function requireMembershipLevel(int $level, string $message): void
    {
        self::start();

        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . url('/login'));
            exit;
        }

        if (!self::hasMembershipLevel($level)) {
            Flash::set('error', $message);
            header('Location: ' . url('/membership'));
            exit;
        }
    }

    /**
     * Guard for user pages: redirect anonymous visitors to the login page.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . url('/login'));
            exit;
        }
    }

    /**
     * Guard for admin pages: requires an authenticated admin, otherwise
     * redirect to the admin login page (with a flash message when a plain
     * user tries to access admin functionality).
     */
    public static function requireAdmin(): void
    {
        if (!self::check()) {
            header('Location: ' . url('/admin'));
            exit;
        }

        if (!self::isAdmin()) {
            Flash::set('error', 'You do not have permission to do that.');
            header('Location: ' . url('/login'));
            exit;
        }
    }

    /**
     * Change a user's password after confirming the current password is
     * correct. Returns null on success or an error message to display.
     */
    public static function changePassword(int $userId, string $current, string $new): ?string
    {
        $user = User::find($userId);

        if ($user === null || !password_verify($current, $user['password_hash'])) {
            return 'Current password is incorrect.';
        }

        if (strlen($new) < 8) {
            return 'New password must be at least 8 characters.';
        }

        User::updatePassword($userId, password_hash($new, PASSWORD_DEFAULT));

        return null;
    }

    /**
     * End the session completely so a logged-out user has no lingering state.
     */
    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Rate-limit brute-force login attempts: true when the number of recent
     * failures for this email or IP has reached the configured maximum.
     */
    private static function tooManyAttempts(string $email, string $ip): bool
    {
        $config   = require __DIR__ . '/../../config/app.php';
        $cutoff   = date('Y-m-d H:i:s', time() - $config['auth']['login_window_seconds']);
        $attempts = LoginAttempt::recentCount($email, $ip, $cutoff);

        return $attempts >= $config['auth']['login_max_attempts'];
    }
}
