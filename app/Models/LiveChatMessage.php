<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The live-stream chat messages.
 *
 * There was no admin screen for these at all: a member's live chat
 * message stayed in the replay for everyone to see, and there was
 * nowhere to look at what was said or to take a message down. The
 * member chat has a moderation path (the support desk and the wall); the
 * live chat had none.
 */
class LiveChatMessage
{
    /**
     * The most recent live chat messages, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 100, int $sessionId = 0): array
    {
        $limit = max(1, min(500, $limit));

        if ($sessionId > 0) {
            return Database::run(
                'SELECT m.*, u.email, s.status AS session_status
                 FROM live_chat_messages m
                 INNER JOIN users u ON u.id = m.user_id
                 INNER JOIN live_sessions s ON s.id = m.session_id
                 WHERE m.session_id = ?
                 ORDER BY m.id DESC
                 LIMIT ' . (int) $limit,
                [$sessionId]
            )->fetchAll();
        }

        return Database::run(
            'SELECT m.*, u.email, s.status AS session_status
             FROM live_chat_messages m
             INNER JOIN users u ON u.id = m.user_id
             INNER JOIN live_sessions s ON s.id = m.session_id
             ORDER BY m.id DESC
             LIMIT ' . (int) $limit
        )->fetchAll();
    }

    /**
     * How many live chat messages were posted in a window. Used by the
     * moderation page to show whether a stream was busy.
     */
    public static function countSince(int $seconds = 3600): int
    {
        return (int) Database::run(
            'SELECT COUNT(*) FROM live_chat_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [max(1, $seconds)]
        )->fetchColumn();
    }

    /**
     * Delete one message. This is the moderation action: the message is
     * gone from the replay and from the chat.
     */
    public static function delete(int $id): bool
    {
        $stmt = Database::run(
            'DELETE FROM live_chat_messages WHERE id = ?',
            [$id]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete every message from one member in one session. A member who
     * is flooding a stream can be removed from it in one go.
     */
    public static function deleteForUser(int $sessionId, int $userId): int
    {
        $stmt = Database::run(
            'DELETE FROM live_chat_messages WHERE session_id = ? AND user_id = ?',
            [$sessionId, $userId]
        );

        return $stmt->rowCount();
    }

    /**
     * The live sessions that have chat messages, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sessionsWithChat(int $limit = 25): array
    {
        return Database::run(
            'SELECT s.id, s.status, s.created_at, COUNT(m.id) AS message_count
             FROM live_sessions s
             INNER JOIN live_chat_messages m ON m.session_id = s.id
             GROUP BY s.id, s.status, s.created_at
             ORDER BY s.created_at DESC
             LIMIT ' . max(1, min(100, $limit))
        )->fetchAll();
    }
}
