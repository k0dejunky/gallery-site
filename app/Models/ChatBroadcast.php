<?php

namespace App\Models;

use App\Core\Database;

/**
 * Daily chat broadcasts: a message the admin can schedule (or send now) to
 * every chat-eligible member's conversation. The worker (bin/daily_chat_worker.php)
 * delivers scheduled broadcasts when their time passes; manual "send now" calls
 * ChatBroadcast::send() directly from the admin action so it is immediate.
 */
class ChatBroadcast
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_PARTIAL   = 'partial';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const MAX_MESSAGE_LENGTH = 5000;

    /** Fetch a single broadcast by id, or null. */
    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM chat_daily_broadcasts WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Insert a broadcast and return its id. A null schedule means "as soon as
     * possible" (delivered by the next worker run or by an immediate send).
     */
    public static function create(string $message, ?string $scheduledAt, int $createdBy): int
    {
        Database::run(
            'INSERT INTO chat_daily_broadcasts (message, scheduled_at, status, created_by)
             VALUES (?, ?, ?, ?)',
            [trim($message), $scheduledAt, self::STATUS_SCHEDULED, $createdBy]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Broadcasts waiting to be delivered: status scheduled and either no
     * schedule (send now) or a schedule that has already passed.
     */
    public static function due(): array
    {
        return Database::run(
            'SELECT * FROM chat_daily_broadcasts
             WHERE status = ?
               AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)
             ORDER BY id ASC',
            [self::STATUS_SCHEDULED]
        )->fetchAll();
    }

    /**
     * Chat-eligible member ids: users with a usable subscription on a plan
     * flagged can_chat. Admins always qualify in the member flow but are not
     * broadcast recipients.
     */
    public static function eligibleUserIds(): array
    {
        $rows = Database::run(
            "SELECT DISTINCT s.user_id
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE p.can_chat = 1
               AND s.status IN ('active', 'cancelled')
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)"
        )->fetchAll();

        return array_map('intval', array_column($rows, 'user_id'));
    }

    /**
     * Deliver one broadcast to every chat-eligible member: open (or reuse)
     * each conversation and append the message as a model/automated message.
     * Returns the run summary so callers can report it.
     */
    public static function send(int $broadcastId): array
    {
        $b = Database::run(
            'SELECT * FROM chat_daily_broadcasts WHERE id = ? LIMIT 1',
            [$broadcastId]
        )->fetch();

        if ($b === false) {
            return ['ok' => false, 'error' => 'Broadcast not found.'];
        }

        if (in_array($b['status'], [self::STATUS_SENT, self::STATUS_SENDING], true)) {
            return ['ok' => false, 'error' => 'Broadcast already sent (or is sending).'];
        }

        // Atomic claim: only one caller may move a scheduled broadcast to
        // 'sending', so overlapping cron runs / a cron plus an admin "send
        // now" can never double-deliver.
        $claimed = Database::run(
            'UPDATE chat_daily_broadcasts SET status = ? WHERE id = ? AND status = ?',
            [self::STATUS_SENDING, $broadcastId, self::STATUS_SCHEDULED]
        );

        if ((int) $claimed->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'Broadcast already claimed by another run.'];
        }

        $message    = trim((string) $b['message']);
        $userIds    = self::eligibleUserIds();
        $recipients = count($userIds);
        $sent       = 0;
        $errors     = [];

        foreach ($userIds as $userId) {
            try {
                $conversationId = ChatMessage::openFor($userId);
                $messageId = ChatMessage::addMessage($conversationId, ChatMessage::ROLE_MODEL, $message, null);
                if ($messageId > 0) {
                    $sent++;
                } else {
                    $errors[] = 'user #' . $userId . ' insert failed';
                }
            } catch (\Throwable $e) {
                $errors[] = 'user #' . $userId . ': ' . $e->getMessage();
            }
        }

        $status = $recipients === 0
            ? self::STATUS_FAILED
            : ($sent === $recipients ? self::STATUS_SENT : ($sent > 0 ? self::STATUS_PARTIAL : self::STATUS_FAILED));
        if ($recipients === 0) {
            $errors[] = 'No chat-eligible members';
        }
        $error = $errors === [] ? null : mb_substr(implode('; ', $errors), 0, 500);

        Database::run(
            'UPDATE chat_daily_broadcasts
             SET status = ?, recipients = ?, sent_count = ?, error = ?, sent_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$status, $recipients, $sent, $error, $broadcastId]
        );

        return ['ok' => true, 'status' => $status, 'recipients' => $recipients, 'sent' => $sent, 'error' => $error];
    }

    /** Cancel a scheduled broadcast that has not been delivered yet. */
    public static function cancel(int $broadcastId): bool
    {
        $stmt = Database::run(
            'UPDATE chat_daily_broadcasts
             SET status = ?
             WHERE id = ? AND status IN (?, ?)',
            [self::STATUS_CANCELLED, $broadcastId, self::STATUS_SCHEDULED, self::STATUS_SENDING]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * The send log for the admin chat page: newest broadcasts first, with the
     * creating admin's email attached.
     */
    public static function log(int $limit = 50): array
    {
        return Database::run(
            'SELECT b.*, u.email AS created_by_email
             FROM chat_daily_broadcasts b
             LEFT JOIN users u ON u.id = b.created_by
             ORDER BY b.id DESC
             LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    }
}