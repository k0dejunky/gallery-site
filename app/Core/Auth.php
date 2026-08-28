<?php

namespace App\Core;

use App\Core\Database;
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
        'admin'      => ['dashboard', 'trends', 'galleries', 'videos', 'categories', 'users', 'membership', 'payments', 'theme', 'site_editor', 'logs', 'documentation', 'autoposter', 'support'],
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

        if (isset($_SESSION['user_id'])) {
            $timeout = max(300, (int) config('app.auth.session_idle_seconds', 43200));
            $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
            if ($lastActivity > 0 && $lastActivity + $timeout < time()) {
                self::revokeSession();
                return;
            }
            $_SESSION['last_activity_at'] = time();
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
            if ((isset($user['status']) && $user['status'] !== 'active')) {
                LoginAttempt::record($email, $ip);

                return 'This account has been suspended.';
            }

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
        $_SESSION['last_activity_at'] = time();

        $row = User::find($userId);
        $_SESSION['session_version'] = (int) ($row['session_version'] ?? 0);

        \App\Core\Database::run(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Kill the current session completely: used when an account is
     * suspended, its session version bumped ("log out everywhere"), or the
     * user signs out.
     */
    public static function revokeSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['user_id'], $_SESSION['impersonator_id'], $_SESSION['session_version'], $_SESSION['last_activity_at']);
        self::$userCache = null;
        session_regenerate_id(true);
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
            $row = User::find((int) $_SESSION['user_id']);

            // Suspended accounts, and sessions issued before a "log out
            // everywhere" bump, are logged out on their very next request.
            if ($row === null
                || (isset($row['status']) && $row['status'] !== 'active')
                || !isset($_SESSION['session_version'])
                || (int) $_SESSION['session_version'] !== (int) ($row['session_version'] ?? 0)) {
                self::revokeSession();

                return null;
            }

            self::$userCache = $row;

            // Presence tracking: refresh last_seen_at at most every 5
            // minutes so the "Logged In Members" stat reflects currently
            // active sessions instead of anyone who has ever logged in.
            $seen = strtotime((string) ($row['last_seen_at'] ?? ''));
            if ($seen === false || $seen < time() - 300) {
                Database::run(
                    'UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [(int) $row['id']]
                );
                self::$userCache['last_seen_at'] = date('Y-m-d H:i:s');
            }
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
     * The highest gallery membership level this user may view. Free accounts
     * (and guests) only see level-0 content; members reach their plan level;
     * admins see everything. Used to filter gallery listings.
     */
    public static function effectiveLevel(): int
    {
        $user = self::user();

        if ($user === null) {
            return 0;
        }

        if (self::isAdmin()) {
            return PHP_INT_MAX;
        }

        $active = Subscription::activeFor((int) $user['id']);

        return $active !== null ? (int) $active['plan_level'] : 0;
    }

    /**
     * Guard for content gated by a gallery's minimum membership level. Level 0
     * content is free for any logged-in user; higher levels require a
     * subscription that reaches that level. Anonymous visitors go to login.
     */
    public static function requireGalleryLevel(int $minLevel, string $message): void
    {
        self::start();

        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . url('/login'));
            exit;
        }

        if ($minLevel <= 0) {
            return;
        }

        if (!self::hasMembershipLevel($minLevel)) {
            Flash::set('error', $message);
            header('Location: ' . url('/membership'));
            exit;
        }
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
        self::logoutEverywhere($userId);
        $_SESSION['session_version'] = (int) (User::find($userId)['session_version'] ?? 0);

        try {
            \App\Models\AuditLog::record($userId, 'update', 'user_password', $userId, 'Changed account password');
        } catch (\Throwable $exception) {
            error_log('[auth] password audit failed: ' . $exception->getMessage());
        }

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

    /** Invalidate every session for one account, including the current one. */
    public static function logoutEverywhere(int $userId): void
    {
        Database::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
    }

    /**
     * Rate-limit brute-force login attempts: true when the number of recent
     * failures for this email or IP has reached the configured maximum.
     */
    /**
     * Lockout rules within the configured window:
     *  - the same email+ip pair after login_max_attempts failures,
     *  - one IP hammering many accounts: 3x that threshold for the IP alone,
     *  - a distributed attack on one account: 3x threshold per email alone.
     */
    private static function tooManyAttempts(string $email, string $ip): bool
    {
        $config   = require __DIR__ . '/../../config/app.php';
        $cutoff   = date('Y-m-d H:i:s', time() - $config['auth']['login_window_seconds']);
        $max      = (int) $config['auth']['login_max_attempts'];

        if (LoginAttempt::recentCount($email, $ip, $cutoff) >= $max) {
            return true;
        }

        return (int) Database::run(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?',
            [$ip, $cutoff]
        )->fetchColumn() >= $max * 3
            || (int) Database::run(
                'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at >= ?',
                [$email, $cutoff]
            )->fetchColumn() >= $max * 3;
    }
}
