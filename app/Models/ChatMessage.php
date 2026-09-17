<?php

namespace App\Models;

use App\Core\Database;

/**
 * Chat conversations between eligible members and the site's AI model (or a
 * human operator via the Android app). A member may chat when any of their
 * usable subscriptions is on a plan flagged can_chat (Platinum-yearly,
 * Lifetime, or the Chat add-on purchasable by Silver/Gold).
 *
 * Conversations run in one of two AI modes (switchable per conversation by
 * the admin): 'retrieval' feeds the model the operator's most-similar past
 * replies as few-shot context, and 'finetuned' uses a LoRA adapter fine-tuned
 * on operator replies. Live (operator) replies are harvested into
 * chat_training_pairs to grow the training set.
 */
class ChatMessage
{
    public const ROLE_USER     = 'user';
    public const ROLE_MODEL    = 'model';
    public const ROLE_OPERATOR = 'operator';

    public const MODE_RETRIEVAL = 'retrieval';
    public const MODE_FINETUNED = 'finetuned';
    public const MODE_OPERATOR  = 'operator';

    public const AI_MODES = [self::MODE_RETRIEVAL, self::MODE_FINETUNED];

    public const MAX_MESSAGE_LENGTH = 2000;

    /**
     * Whether the given user may chat: they have a usable subscription on a
     * plan flagged can_chat. Admins always qualify.
     */
    public static function canChat(int $userId): bool
    {
        $user = Database::run(
            'SELECT id, role FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetch();

        if ($user !== null && in_array((string) $user['role'], \App\Core\Auth::ADMIN_ROLES, true)) {
            return true;
        }

        $row = Database::run(
            'SELECT p.can_chat
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ?
               AND s.status IN (?, ?)
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
               AND p.can_chat = 1
             ORDER BY s.id DESC
             LIMIT 1',
            [$userId, 'active', 'cancelled']
        )->fetch();

        return (bool) ($row['can_chat'] ?? false);
    }

    /**
     * Open (or reuse) the user's chat conversation. Each user has one
     * conversation; a closed one is reopened with the mode left as-is.
     */
    public static function openFor(int $userId, string $mode = self::MODE_RETRIEVAL): int
    {
        $existing = Database::run(
            'SELECT id, ai_mode FROM chat_conversations WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch();

        if ($existing) {
            $id = (int) $existing['id'];
            Database::run(
                "UPDATE chat_conversations SET status = 'open' WHERE id = ?",
                [$id]
            );
            return $id;
        }

        $mode = in_array($mode, [self::MODE_RETRIEVAL, self::MODE_FINETUNED, self::MODE_OPERATOR], true)
            ? $mode
            : self::MODE_RETRIEVAL;

        Database::run(
            "INSERT INTO chat_conversations (user_id, ai_mode) VALUES (?, ?)",
            [$userId, $mode]
        );

        return (int) Database::connection()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM chat_conversations WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    public static function forUser(int $userId): ?array
    {
        return Database::run(
            'SELECT * FROM chat_conversations WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch() ?: null;
    }

    /**
     * Add a message to a conversation. Returns the new message id.
     *
     * @param array{name:string, type:string, path:string}|null $attachment
     */
    public static function addMessage(int $conversationId, string $role, string $message, ?array $attachment = null): int
    {
        $message = mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH);
        if ($message === '' && $attachment === null) {
            return 0;
        }

        Database::run(
            'INSERT INTO chat_messages (conversation_id, sender_role, message, attachment_name, attachment_type, attachment_path)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $conversationId,
                $role,
                $message,
                $attachment['name'] ?? null,
                $attachment['type'] ?? null,
                $attachment['path'] ?? null,
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Messages in a conversation, optionally only those with id > sinceId
     * (for polling). Newest first, or oldest-first when fetching history.
     */
    public static function messages(int $conversationId, int $sinceId = 0, bool $oldestFirst = true): array
    {
        $since = $sinceId > 0 ? ' AND id > ' . (int) $sinceId : '';
        $order = $oldestFirst ? 'ASC' : 'DESC';

        return Database::run(
            "SELECT * FROM chat_messages WHERE conversation_id = ?$since ORDER BY id $order",
            [$conversationId]
        )->fetchAll();
    }

    public static function latestId(int $conversationId): int
    {
        $id = Database::run(
            'SELECT MAX(id) FROM chat_messages WHERE conversation_id = ?',
            [$conversationId]
        )->fetchColumn();

        return (int) $id;
    }

    public static function unreadCountForUser(int $userId): int
    {
        $conv = self::forUser($userId);
        if ($conv === null) {
            return 0;
        }

        $lastUser = (int) Database::run(
            "SELECT MAX(id) FROM chat_messages WHERE conversation_id = ? AND sender_role = 'user'",
            [(int) $conv['id']]
        )->fetchColumn();

        if ($lastUser <= 0) {
            return 0;
        }

        return (int) Database::run(
            "SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ? AND id > ? AND sender_role IN ('model','operator')",
            [(int) $conv['id'], $lastUser]
        )->fetchColumn();
    }

    public static function setMode(int $conversationId, string $mode): bool
    {
        if (!in_array($mode, [self::MODE_RETRIEVAL, self::MODE_FINETUNED, self::MODE_OPERATOR], true)) {
            return false;
        }

        $stmt = Database::run(
            'UPDATE chat_conversations SET ai_mode = ? WHERE id = ?',
            [$mode, $conversationId]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether a conversation mode uses the AI to reply. Operator mode is
     * answered only by a human via the Android app / admin panel.
     */
    public static function isAiMode(string $mode): bool
    {
        return in_array($mode, self::AI_MODES, true);
    }

    public static function close(int $conversationId): bool
    {
        $stmt = Database::run(
            "UPDATE chat_conversations SET status = 'closed' WHERE id = ?",
            [$conversationId]
        );

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------
    // Training data (operator replies harvested into chat_training_pairs)
    // ------------------------------------------------------------------

    public static function insertTrainingPair(string $userMessage, string $operatorReply): void
    {
        Database::run(
            'INSERT INTO chat_training_pairs (user_message, operator_reply) VALUES (?, ?)',
            [$userMessage, $operatorReply]
        );
    }

    public static function trainingPairCount(): int
    {
        return (int) Database::run('SELECT COUNT(*) FROM chat_training_pairs')->fetchColumn();
    }

    public static function cleanedPairCount(): int
    {
        return (int) Database::run('SELECT COUNT(*) FROM chat_training_pairs WHERE cleaned = 1')->fetchColumn();
    }

    /**
     * The operator's most-similar past replies to a member message, as
     * few-shot context for retrieval mode. Uses a simple word-overlap score
     * over the training corpus (lightweight, no ML dependencies).
     *
     * @return array<int, array{user_message:string, operator_reply:string}>
     */
    public static function similarContext(string $message, int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $words = self::tokenize($message);
        if ($words === []) {
            return [];
        }

        $rows = Database::run(
            'SELECT user_message, operator_reply FROM chat_training_pairs ORDER BY id DESC LIMIT 500'
        )->fetchAll();

        $scored = [];
        foreach ($rows as $row) {
            $qWords = self::tokenize((string) $row['user_message']);
            $score  = 0;
            foreach ($qWords as $w) {
                if (in_array($w, $words, true)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'user_message' => (string) $row['user_message'], 'operator_reply' => (string) $row['operator_reply']];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            static fn (array $r): array => ['user_message' => $r['user_message'], 'operator_reply' => $r['operator_reply']],
            array_slice($scored, 0, $limit)
        );
    }

    private static function tokenize(string $text): array
    {
        $text = mb_strtolower((string) preg_replace('/[^a-zA-Z0-9\s]/u', ' ', $text));
        $words = array_values(array_unique(array_filter(explode(' ', $text))));

        return $words;
    }
}