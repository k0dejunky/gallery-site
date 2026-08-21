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
    public static function all(): array
    {
        return Database::run(
            'SELECT u.id, u.email, u.role, u.created_at,
                    s.status AS sub_status, p.name AS sub_plan
             FROM users u
             LEFT JOIN subscriptions s ON s.id = (
                 SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.user_id = u.id
             )
             LEFT JOIN plans p ON p.id = s.plan_id
             ORDER BY u.role DESC, u.created_at ASC'
        )->fetchAll();
    }

    /**
     * Search users by email (case-insensitive LIKE).
     */
    public static function search(string $query): array
    {
        return Database::run(
            'SELECT u.id, u.email, u.role, u.created_at,
                    s.status AS sub_status, p.name AS sub_plan
             FROM users u
             LEFT JOIN subscriptions s ON s.id = (
                 SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.user_id = u.id
             )
             LEFT JOIN plans p ON p.id = s.plan_id
             WHERE u.email LIKE ?
             ORDER BY u.role DESC, u.created_at ASC',
            ['%' . $query . '%']
        )->fetchAll();
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
     * Update a user's stored card details (token-based — never store full numbers).
     */
    public static function updatePayment(int $id, ?string $customerId, ?string $lastFour, ?string $brand, ?int $expMonth, ?int $expYear): void
    {
        Database::run(
            'UPDATE users SET payment_customer_id = ?, card_last_four = ?, card_brand = ?, card_exp_month = ?, card_exp_year = ? WHERE id = ?',
            [$customerId ?: null, $lastFour ?: null, $brand ?: null, $expMonth, $expYear, $id]
        );
    }

    /**
     * Mark a user's age as verified by an admin.
     */
    public static function markAgeVerified(int $id): void
    {
        Database::run(
            'UPDATE users SET age_verified = 1, age_verified_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$id]
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
}
