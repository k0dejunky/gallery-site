<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for user accounts. Passwords are always stored as bcrypt
 * hashes, never plaintext.
 */
class User
{
    /**
     * Look up a user by login email (used during authentication).
     */
    public static function findByEmail(string $email): ?array
    {
        $user = Database::run(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch();

        return $user ?: null;
    }

    /**
     * Look up a user by id (used for the current session's user).
     */
    public static function find(int $id): ?array
    {
        $user = Database::run(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $user ?: null;
    }

    /**
     * Every account (without password hashes) for the admin user list, each
     * annotated with its latest membership status and plan.
     */
    public static function all(
        string $search = '',
        string $flag = '',
        string $status = '',
        string $role = '',
        string $sortBy = 'created_at',
        string $sortDir = 'ASC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $whitelist = ['email', 'created_at', 'role', 'status'];
        if (!in_array($sortBy, $whitelist, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = 'u.email LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if ($flag !== '') {
            $where[]  = 'u.flag = ?';
            $params[] = $flag;
        }
        if ($status !== '') {
            $where[]  = 'u.status = ?';
            $params[] = $status;
        }
        if ($role !== '') {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }

        $sql = 'SELECT u.id, u.email, u.role, u.created_at, u.last_login_at, u.status,
                       s.status AS sub_status, p.name AS sub_plan
                FROM users u
                LEFT JOIN subscriptions s ON s.id = (
                    SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.user_id = u.id
                )
                LEFT JOIN plans p ON p.id = s.plan_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.' . $sortBy . ' ' . $sortDir;
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return Database::run($sql, $params)->fetchAll();
    }

    /**
     * Register a new account with a bcrypt-hashed password.
     */
    public static function create(string $email, string $password, string $role, ?string $dateOfBirth = null): bool
    {
        Database::run(
            'INSERT INTO users (email, password_hash, role, date_of_birth) VALUES (?, ?, ?, ?)',
            [$email, password_hash($password, PASSWORD_DEFAULT), $role, $dateOfBirth ?: null]
        );

        return true;
    }

    /**
     * Generate and store a new email verification token. The raw token is
     * returned only to the caller so it can be placed in the email link.
     */
    public static function createVerificationToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        Database::run(
            'UPDATE users SET email_verification_token = ?, email_verified_at = NULL WHERE id = ?',
            [$token, $id]
        );

        return $token;
    }

    /**
     * Find an unverified user by token. Used tokens are removed by
     * markEmailVerified(); legacy accounts have no token and are unaffected.
     */
    public static function findByVerificationToken(string $token): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }

        $user = Database::run(
            'SELECT * FROM users WHERE email_verification_token = ? AND email_verified_at IS NULL LIMIT 1',
            [$token]
        )->fetch();

        return $user ?: null;
    }

    /**
     * Consume a verification token and record the verification time.
     */
    public static function markEmailVerified(int $id): void
    {
        Database::run(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP, email_verification_token = NULL WHERE id = ?',
            [$id]
        );
    }

    /**
     * Delete an account.
     */
    public static function delete(int $id): void
    {
        Database::run('DELETE FROM users WHERE id = ?', [$id]);
    }

    /**
     * Update a user's core fields and optional billing/age-verification data.
     */
    public static function updateProfile(
        int $id,
        string $email,
        string $role,
        ?string $dateOfBirth,
        bool $ageVerified,
        ?string $billingFirstName,
        ?string $billingLastName,
        ?string $billingAddress1,
        ?string $billingAddress2,
        ?string $billingCity,
        ?string $billingState,
        ?string $billingZip,
        ?string $billingCountry
    ): void {
        Database::run(
            'UPDATE users
             SET email = ?, role = ?, date_of_birth = ?, age_verified = ?,
                 billing_first_name = ?, billing_last_name = ?,
                 billing_address_line1 = ?, billing_address_line2 = ?,
                 billing_city = ?, billing_state = ?, billing_zip = ?, billing_country = ?
             WHERE id = ?',
            [$email, $role, $dateOfBirth ?: null, $ageVerified ? 1 : 0,
             $billingFirstName ?: null, $billingLastName ?: null,
             $billingAddress1 ?: null, $billingAddress2 ?: null,
             $billingCity ?: null, $billingState ?: null, $billingZip ?: null, $billingCountry ?: null,
             $id]
        );
    }

    /**
     * Count admin accounts, so the app can prevent deleting the last admin.
     */
    public static function countAdmins(): int
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM users WHERE role IN (\'super_admin\', \'admin\', \'editor\', \'moderator\', \'viewer\')',
            []
        )->fetchColumn();
    }

    /**
     * Persist a new password hash (the caller hashes the plaintext).
     */
    public static function updatePassword(int $id, string $hash): void
    {
        Database::run(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [$hash, $id]
        );
    }

    /**
     * Store the selected site theme preset for a user, or clear it to use the default.
     */
    public static function updateThemePreset(int $id, ?string $slug): void
    {
        Database::run(
            'UPDATE users SET theme_preset = ? WHERE id = ?',
            [$slug ?: null, $id]
        );
    }

    /** Update only member-editable billing/name details. */
    public static function updateBillingProfile(int $id, ?string $firstName, ?string $lastName, ?string $address1, ?string $address2, ?string $city, ?string $state, ?string $zip, ?string $country): void
    {
        Database::run(
            'UPDATE users SET billing_first_name = ?, billing_last_name = ?, billing_address_line1 = ?, billing_address_line2 = ?, billing_city = ?, billing_state = ?, billing_zip = ?, billing_country = ? WHERE id = ?',
            [$firstName ?: null, $lastName ?: null, $address1 ?: null, $address2 ?: null, $city ?: null, $state ?: null, $zip ?: null, $country ?: null, $id]
        );
    }

    /**
     * Number of users with a given status value.
     */
    public static function countByStatus(string $status): int
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM users WHERE status = ?',
            [$status]
        )->fetchColumn();
    }

    /**
     * Lifetime revenue from all active, completed, or cancelled subscriptions.
     */
    public static function lifetimeRevenue(int $userId): float
    {
        return (float) Database::run(
            'SELECT COALESCE(SUM(p.price), 0)
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status IN (\'active\',\'completed\',\'cancelled\')',
            [$userId]
        )->fetchColumn();
    }
}
