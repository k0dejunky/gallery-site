<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for member-submitted support messages.
 */
class SupportMessage
{
    private const STATUSES = ['new', 'read', 'postponed', 'resolved', 'ignored'];

    private static function ticketId(int $ticketId): int
    {
        if ($ticketId < 1) {
            throw new \InvalidArgumentException('Invalid support ticket id.');
        }

        return $ticketId;
    }

    private static function userId(int $userId): int
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Invalid user id.');
        }

        return $userId;
    }
    /**
     * Store a support message and return its new id.
     */
    public static function create(int $userId, string $email, string $subject, string $message): int
    {
        self::userId($userId);
        Database::run(
            'INSERT INTO support_messages (user_id, email, subject, message) VALUES (?, ?, ?, ?)',
            [$userId, $email, $subject, $message]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Return all support messages, newest first, for the admin read-only view.
     */
    public static function all(): array
    {
        return Database::run(
            'SELECT sm.*, u.email AS user_email,
                    (SELECT COUNT(*) FROM support_replies sr WHERE sr.ticket_id = sm.id) AS reply_count
             FROM support_messages sm
             LEFT JOIN users u ON u.id = sm.user_id
             ORDER BY sm.created_at DESC, sm.id DESC'
        )->fetchAll();
    }

    public static function findForUser(int $ticketId, int $userId): ?array
    {
        self::ticketId($ticketId);
        self::userId($userId);
        $row = Database::run(
            'SELECT sm.*, u.email AS user_email
             FROM support_messages sm LEFT JOIN users u ON u.id = sm.user_id
             WHERE sm.id = ? AND sm.user_id = ? LIMIT 1', [$ticketId, $userId]
        )->fetch();
        return $row ?: null;
    }

    public static function find(int $ticketId): ?array
    {
        self::ticketId($ticketId);
        $row = Database::run(
            'SELECT sm.*, u.email AS user_email
             FROM support_messages sm LEFT JOIN users u ON u.id = sm.user_id
             WHERE sm.id = ? LIMIT 1', [$ticketId]
        )->fetch();
        return $row ?: null;
    }

    public static function forUser(int $userId): array
    {
        self::userId($userId);
        return Database::run(
            'SELECT sm.*,
                    (SELECT COUNT(*) FROM support_replies sr WHERE sr.ticket_id = sm.id) AS reply_count,
                    (SELECT MAX(sr.created_at) FROM support_replies sr WHERE sr.ticket_id = sm.id AND sr.author_role = \'admin\') AS latest_admin_reply,
                    (SELECT MAX(sr.created_at) FROM support_replies sr WHERE sr.ticket_id = sm.id AND sr.author_role = \'user\') AS latest_user_reply
             FROM support_messages sm
             WHERE sm.user_id = ? ORDER BY sm.updated_at DESC, sm.id DESC', [$userId]
        )->fetchAll();
    }

    /** Count tickets with an admin reply newer than the user's latest message. */
    public static function unreadCountForUser(int $userId): int
    {
        self::userId($userId);
        return (int) Database::run(
            "SELECT COUNT(*)
             FROM support_messages sm
             WHERE sm.user_id = ?
               AND COALESCE(sm.user_read_at, '1000-01-01 00:00:00') <
                   COALESCE((SELECT MAX(sr.created_at) FROM support_replies sr
                             WHERE sr.ticket_id = sm.id AND sr.author_role = 'admin'), '1000-01-01 00:00:00')
               AND COALESCE((SELECT MAX(sr.created_at) FROM support_replies sr
                             WHERE sr.ticket_id = sm.id AND sr.author_role = 'admin'), '1000-01-01 00:00:00') >
                   COALESCE((SELECT MAX(sr.created_at) FROM support_replies sr
                             WHERE sr.ticket_id = sm.id AND sr.author_role = 'user'), sm.created_at)",
            [$userId]
        )->fetchColumn();
    }

    /** Mark a member's ticket read without changing the admin ticket status. */
    public static function markReadForUser(int $ticketId, int $userId): void
    {
        self::ticketId($ticketId);
        self::userId($userId);
        Database::run(
            'UPDATE support_messages SET user_read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?',
            [$ticketId, $userId]
        );
    }

    public static function replies(int $ticketId): array
    {
        self::ticketId($ticketId);
        return Database::run(
            'SELECT sr.*, u.email AS author_email
             FROM support_replies sr LEFT JOIN users u ON u.id = sr.user_id
             WHERE sr.ticket_id = ? ORDER BY sr.created_at ASC, sr.id ASC', [$ticketId]
        )->fetchAll();
    }

    public static function addReply(int $ticketId, int $userId, string $role, string $message): int
    {
        self::ticketId($ticketId);
        self::userId($userId);
        if (!in_array($role, ['user', 'admin'], true) || $message === '' || mb_strlen($message) > 10000) {
            throw new \InvalidArgumentException('Invalid support reply.');
        }
        Database::run('INSERT INTO support_replies (ticket_id, user_id, author_role, message) VALUES (?, ?, ?, ?)',
            [$ticketId, $userId, $role, $message]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function setStatus(int $ticketId, string $status): void
    {
        self::ticketId($ticketId);
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid support status.');
        }
        Database::run('UPDATE support_messages SET status = ? WHERE id = ?', [$status, $ticketId]);
    }

    public static function delete(int $ticketId): void
    {
        self::ticketId($ticketId);
        Database::run('DELETE FROM support_messages WHERE id = ?', [$ticketId]);
    }

    public static function unreadCount(): int
    {
        return (int) Database::run("SELECT COUNT(*) FROM support_messages WHERE status = 'new'")->fetchColumn();
    }
}
