<?php

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * The permission catalogue and the stored access assignments that sit on top
 * of the Auth::PERMISSIONS default map.
 *
 * Auth::PERMISSIONS stays the source of DEFAULTS: it is what a fresh install
 * behaves like, and the smoke suite reads it as the declaration of which
 * permissions exist and which low-privilege roles must not hold them. This
 * model only stores the DIFFERENCE a super admin has chosen, per role and per
 * account, and answers the one question the rest of the app asks - may this
 * user do that?
 *
 * Two locks are enforced here rather than trusted to the stored rows, because
 * a row can be written by hand or by a bug:
 *   - SUPER_ADMIN_ONLY (mail_admin, manage_roles, system_ops) never leaves the
 *     super admin. The mail one drives a root script that rewrites the
 *     Postfix/Dovecot mailbox files, and manage_roles mints super admins.
 *   - ADMIN_ONLY (manage_admins, monetisation) never drops below the admin
 *     role. The first mints full admin accounts, the second mints free value
 *     (unlock codes, gift subscriptions, marking purchases paid).
 */
class PermissionMap
{
    /**
     * Every permission the admin panel knows about, with the label and the
     * one-line explanation the assignment screen shows. Grouped so the matrix
     * reads as sections rather than one 23-row wall.
     */
    public const CATALOG = [
        'Overview' => [
            'dashboard'   => ['Dashboard', 'The admin landing page and its operational alerts.'],
            'trends'      => ['Trends', 'Search trends and the missed-search approval queue.'],
            'traffic'     => ['Traffic', 'Referral sources, landing pages and campaign attribution.'],
            'analytics'   => ['Web Analytics', 'AWStats-style traffic reports from the Apache logs.'],
        ],
        'Content' => [
            'galleries'   => ['Galleries', 'Create, edit, import, caption and delete galleries and media.'],
            'videos'      => ['Video Projects', 'The video editor, exports and rendition jobs.'],
            'categories'  => ['Categories', 'Category CRUD and the AI category suggestion queue.'],
            'theme'       => ['Theme', 'Site and admin colour, type and layout presets.'],
            'site_editor' => ['Site Editor', 'Drag-and-drop page layout and the public creator profile.'],
        ],
        'People & community' => [
            'users'        => ['Users', 'Account list, roles, notes, flags and password resets.'],
            'user_monitor' => ['User Monitor', 'Live session and activity watching.'],
            'chat'         => ['Chat', 'Operator chat, questionnaires and the training corpus.'],
            'support'      => ['Support', 'Support desk and the live-chat moderation queue.'],
            'autoposter'   => ['Auto Poster', 'X/Reddit scheduling, templates and the posting queue.'],
        ],
        'Money' => [
            'membership'  => ['Membership', 'Plans, subscriptions, the emailer and earnings.'],
            'payments'    => ['Payment Processors', 'Braintree and PayPal credential configuration.'],
            'monetisation'=> ['Monetisation', 'Sales, gift and unlock codes, tips and the purchase ledger.'],
        ],
        'System' => [
            'logs'          => ['Logs', 'Application logs, error logs and the test suite.'],
            'documentation' => ['Documentation', 'Internal documentation.'],
            'system_ops'    => ['System Operations', 'Maintenance mode, database optimisation and cache rebuilds.'],
            'mail_admin'    => ['Mail Server', 'Postfix/Dovecot mailbox administration (runs a root script).'],
        ],
        'Access control' => [
            'manage_admins' => ['Manage Admins', 'Grant or change the admin role on other accounts.'],
            'manage_roles'  => ['Manage Roles', 'Edit this permission matrix and grant the super-admin role.'],
        ],
    ];

    /**
     * Permissions that may never be held below the `admin` role, whatever a
     * stored row says. Mirrors the invariants the smoke suite asserts about
     * Auth::PERMISSIONS, so overriding a role cannot re-open them.
     */
    public const ADMIN_ONLY = ['manage_admins', 'monetisation'];

    /** role deltas: role => [permission => granted]. Null until first read. */
    private static ?array $roleDeltas = null;

    /** user deltas: user id => [permission => granted]. */
    private static array $userDeltas = [];

    /** Flat permission => [group, label, desc], built once from the catalogue. */
    private static ?array $flat = null;

    /**
     * The catalogue flattened to permission => ['group','label','desc'].
     *
     * @return array<string, array{group: string, label: string, desc: string}>
     */
    public static function flat(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }

        self::$flat = [];
        foreach (self::CATALOG as $group => $permissions) {
            foreach ($permissions as $permission => $info) {
                self::$flat[$permission] = [
                    'group' => $group,
                    'label' => $info[0],
                    'desc'  => $info[1],
                ];
            }
        }

        return self::$flat;
    }

    /** Every permission key the panel knows about. */
    public static function all(): array
    {
        return array_keys(self::flat());
    }

    /**
     * Whether a role is allowed to hold a permission at all.
     *
     * The super admin holds everything (Auth::PERMISSIONS grants it '*'), so
     * the locks only ever constrain the other four roles.
     */
    public static function mayHold(string $permission, string $role): bool
    {
        if ($role === 'super_admin') {
            return true;
        }

        if (in_array($permission, Auth::SUPER_ADMIN_ONLY, true)) {
            return false;
        }

        if (in_array($permission, self::ADMIN_ONLY, true)) {
            return $role === 'admin';
        }

        return true;
    }

    /**
     * The effective permission set for a role: the Auth::PERMISSIONS default
     * with this install's stored deltas applied, minus anything the role may
     * not hold.
     *
     * @return string[]
     */
    public static function effectiveRole(string $role): array
    {
        if ($role === 'super_admin') {
            return self::all();
        }

        $permissions = Auth::PERMISSIONS[$role] ?? [];

        foreach (self::roleDeltas()[$role] ?? [] as $permission => $granted) {
            if ($granted) {
                $permissions[] = $permission;
            } else {
                $permissions = array_values(array_diff($permissions, [$permission]));
            }
        }

        // Belt and braces: a hand-edited row cannot smuggle in a locked one.
        $permissions = array_values(array_filter(
            $permissions,
            static fn (string $permission): bool => self::mayHold($permission, $role)
        ));

        return array_values(array_unique($permissions));
    }

    /**
     * The effective permission set for one account: its role's set with that
     * account's own allow/deny overrides applied on top.
     *
     * @return string[]
     */
    public static function effectiveUser(array $user): array
    {
        $role      = (string) ($user['role'] ?? '');
        $permission = self::effectiveRole($role);

        if ($role === 'super_admin') {
            return $permission;
        }

        $userId = (int) ($user['id'] ?? 0);
        foreach (self::userDeltas($userId) as $name => $granted) {
            if ($granted) {
                $permission[] = $name;
            } else {
                $permission = array_values(array_diff($permission, [$name]));
            }
        }

        $permission = array_values(array_filter(
            $permission,
            static fn (string $name): bool => self::mayHold($name, $role)
        ));

        return array_values(array_unique($permission));
    }

    /** Whether an account holds a permission, after both locks are applied. */
    public static function allows(array $user, string $permission): bool
    {
        $role = (string) ($user['role'] ?? '');

        if ($role === 'super_admin') {
            return true;
        }

        if (!self::mayHold($permission, $role)) {
            return false;
        }

        return in_array($permission, self::effectiveUser($user), true);
    }

    /**
     * Replace a role's assignments with exactly the permissions posted.
     *
     * Only the difference from the Auth::PERMISSIONS default is written, so a
     * role nobody has touched keeps no rows at all and still follows future
     * default changes. Permissions the role may not hold are dropped before
     * the diff, so posting one can never create a row for it.
     *
     * @param string[] $granted The full desired set for the role.
     */
    public static function saveRole(string $role, array $granted): void
    {
        if (!in_array($role, ['admin', 'editor', 'moderator', 'viewer'], true)) {
            return;
        }

        $granted = array_values(array_filter(
            array_unique($granted),
            static fn (string $permission): bool => in_array($permission, self::all(), true)
                && self::mayHold($permission, $role)
        ));

        $default  = Auth::PERMISSIONS[$role] ?? [];
        $delta    = [];

        foreach (array_unique(array_merge($default, $granted)) as $permission) {
            $shouldBe = in_array($permission, $granted, true);
            $isDefault = in_array($permission, $default, true);
            if ($shouldBe && !$isDefault) {
                $delta[$permission] = 1;
            } elseif (!$shouldBe && $isDefault) {
                $delta[$permission] = 0;
            }
        }

        $pdo    = Database::connection();
        // A caller (a test, or a future wrapper) may already hold an
        // transaction. Nesting beginTransaction() throws, so only take the
        // lock when we are the ones who opened it.
        $owned  = !$pdo->inTransaction();
        if ($owned) {
            $pdo->beginTransaction();
        }
        try {
            Database::run('DELETE FROM admin_role_permissions WHERE role = ?', [$role]);
            foreach ($delta as $permission => $grantedFlag) {
                Database::run(
                    'INSERT INTO admin_role_permissions (role, permission, granted) VALUES (?, ?, ?)',
                    [$role, $permission, $grantedFlag]
                );
            }
            if ($owned) {
                $pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($owned && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }

        self::clearCache();
    }

    /**
     * Set one account's per-permission override.
     *
     * The value is absolute rather than a diff against the role: '' inherits
     * the role, 1 always allows, 0 always denies. Storing the explicit choice
     * keeps the screen honest when the role's own set changes later.
     *
     * @param array<string, string> $states permission => '' | '1' | '0'
     */
    public static function saveUser(int $userId, string $role, array $states): void
    {
        $rows = [];
        foreach ($states as $permission => $state) {
            if (!in_array($permission, self::all(), true)) {
                continue;
            }
            if ($state === '' || $state === null) {
                continue;
            }
            // The locks are per role, so an override cannot grant what the
            // account's own role is forbidden to hold.
            if (!self::mayHold($permission, $role)) {
                continue;
            }
            $rows[$permission] = $state === '1' || $state === 1 || $state === true ? 1 : 0;
        }

        $pdo   = Database::connection();
        $owned = !$pdo->inTransaction();
        if ($owned) {
            $pdo->beginTransaction();
        }
        try {
            Database::run('DELETE FROM admin_user_permissions WHERE user_id = ?', [$userId]);
            foreach ($rows as $permission => $granted) {
                Database::run(
                    'INSERT INTO admin_user_permissions (user_id, permission, granted) VALUES (?, ?, ?)',
                    [$userId, $permission, $granted]
                );
            }
            if ($owned) {
                $pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($owned && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }

        unset(self::$userDeltas[$userId]);
    }

    /** Drop every stored override for a role, returning it to the default. */
    public static function resetRole(string $role): void
    {
        Database::run('DELETE FROM admin_role_permissions WHERE role = ?', [$role]);
        self::clearCache();
    }

    /** Drop every stored override for an account, returning it to its role. */
    public static function resetUser(int $userId): void
    {
        Database::run('DELETE FROM admin_user_permissions WHERE user_id = ?', [$userId]);
        unset(self::$userDeltas[$userId]);
    }

    /**
     * Whether a role has been customised away from its default.
     *
     * Used by the matrix to mark a column as "edited" and offer a reset.
     */
    public static function isCustomised(string $role): bool
    {
        return (self::roleDeltas()[$role] ?? []) !== [];
    }

    /** Forget the memoised rows so the next read hits the database. */
    public static function clearCache(): void
    {
        self::$roleDeltas = null;
        self::$userDeltas = [];
    }

    /**
     * All stored role deltas, loaded once per request.
     *
     * A missing table (the migration has not run yet) must not take the admin
     * panel down, so a failure degrades to "no overrides" - the built-in
     * defaults - rather than throwing out of Auth::can().
     *
     * @return array<string, array<string, bool>>
     */
    private static function roleDeltas(): array
    {
        if (self::$roleDeltas !== null) {
            return self::$roleDeltas;
        }

        self::$roleDeltas = [];

        try {
            $rows = Database::run(
                'SELECT role, permission, granted FROM admin_role_permissions'
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            return self::$roleDeltas;
        }

        foreach ($rows as $row) {
            self::$roleDeltas[(string) $row['role']][(string) $row['permission']] = (bool) $row['granted'];
        }

        return self::$roleDeltas;
    }

    /**
     * One account's overrides, memoised per user id for the request.
     *
     * @return array<string, bool>
     */
    private static function userDeltas(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (isset(self::$userDeltas[$userId])) {
            return self::$userDeltas[$userId];
        }

        self::$userDeltas[$userId] = [];

        try {
            $rows = Database::run(
                'SELECT permission, granted FROM admin_user_permissions WHERE user_id = ?',
                [$userId]
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            return self::$userDeltas[$userId];
        }

        foreach ($rows as $row) {
            self::$userDeltas[$userId][(string) $row['permission']] = (bool) $row['granted'];
        }

        return self::$userDeltas[$userId];
    }
}
