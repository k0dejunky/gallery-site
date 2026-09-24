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
        // Cheap 60s cache; eligibility only changes when a subscription is
        // granted/expires, and bump('chat') fires on every chat write.
        return (bool) \App\Core\Cache::rememberGen('chat', 'canchat:u' . $userId, 60, static function () use ($userId): string {
            $user = Database::run(
                'SELECT id, role FROM users WHERE id = ? LIMIT 1',
                [$userId]
            )->fetch();

            if ($user !== null && in_array((string) $user['role'], \App\Core\Auth::ADMIN_ROLES, true)) {
                return '1';
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

            return ($row['can_chat'] ?? false) ? '1' : '0';
        });
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

        \App\Core\Cache::bump('chat');

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Store an uploaded chat attachment under storage/uploads/chat/ and
     * return metadata to persist on the message row, or null on failure.
     * The real MIME type is sniffed from the file content (finfo) because
     * phone uploads often arrive as application/octet-stream. Only a
     * allowlisted set of image/video/audio/document types is accepted.
     *
     * @param array{tmp_name:string, name:string, type:string, size:int} $file
     * @return array{name:string, type:string, path:string}|null
     */
    public static function storeAttachment(array $file): ?array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_file($tmp)) {
            return null;
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mp3', 'ogg', 'oga', 'wav', 'pdf', 'txt'];
        $allowedMime = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'video/mp4', 'video/webm',
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/x-pn-wav',
            'application/pdf', 'text/plain',
        ];

        $realType = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $t = $fi !== false ? finfo_file($fi, $tmp) : false;
            if (is_resource($fi)) {
                finfo_close($fi);
            }
            if (is_string($t) && $t !== '') {
                $realType = $t;
            }
        }
        if ($realType === '') {
            $realType = mime_content_type($tmp) ?: '';
        }

        // Reject by content before it ever reaches storage: sniffed MIME must
        // be in the allowlist, and the extension must match one too.
        if (!in_array(strtolower($realType), $allowedMime, true)) {
            return null;
        }

        $name = trim((string) ($file['name'] ?? ''));
        if ($name === '' || $name !== basename($name)) {
            $name = 'attachment-' . bin2hex(random_bytes(6));
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return null;
        }

        $dir = dirname(__DIR__, 2) . '/storage/uploads/chat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . bin2hex(random_bytes(6)) . '_' . $safeName;

        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                return null;
            }
        }

        $sent = (string) ($file['type'] ?? '');
        if (str_starts_with($realType, 'image/')) {
            $type = $realType;
        } elseif (str_starts_with($sent, 'image/')) {
            $type = $sent;
        } else {
            $type = $realType !== '' ? $realType : 'application/octet-stream';
        }

        return [
            'name' => $safeName,
            'type' => $type,
            'path' => str_replace(dirname(__DIR__, 2) . '/', '', $dest),
        ];
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

    /**
     * The most recent $limit messages, ordered oldest-first (so the client can
     * render them top-to-bottom). Used as the initial page of a thread.
     */
    public static function messagesLatest(int $conversationId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $rows = Database::run(
            'SELECT * FROM chat_messages WHERE conversation_id = ?
             ORDER BY id DESC LIMIT ' . $limit,
            [$conversationId]
        )->fetchAll();

        return array_reverse($rows);
    }

    /**
     * Up to $limit messages with id < $beforeId (older than a cursor), ordered
     * oldest-first. Returns [] when there is nothing older. Used for lazy /
     * batch loading when the user scrolls up.
     */
    public static function messagesBefore(int $conversationId, int $beforeId, int $limit = 50): array
    {
        if ($beforeId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        $rows = Database::run(
            'SELECT * FROM chat_messages WHERE conversation_id = ? AND id < ?
             ORDER BY id DESC LIMIT ' . $limit,
            [$conversationId, $beforeId]
        )->fetchAll();

        return array_reverse($rows);
    }

    /** Whether any message exists with id < $beforeId (more history to load). */
    public static function hasOlder(int $conversationId, int $beforeId): bool
    {
        if ($beforeId <= 0) {
            return false;
        }

        $id = Database::run(
            'SELECT id FROM chat_messages WHERE conversation_id = ? AND id < ?
             ORDER BY id ASC LIMIT 1',
            [$conversationId, $beforeId]
        )->fetchColumn();

        return $id !== false && $id !== null;
    }

    public static function latestId(int $conversationId): int
    {
        $id = Database::run(
            'SELECT MAX(id) FROM chat_messages WHERE conversation_id = ?',
            [$conversationId]
        )->fetchColumn();

        return (int) $id;
    }

    /** The earliest message id in a conversation, or 0 when empty. */
    public static function firstId(int $conversationId): int
    {
        $id = Database::run(
            'SELECT MIN(id) FROM chat_messages WHERE conversation_id = ?',
            [$conversationId]
        )->fetchColumn();

        return (int) $id;
    }

    public static function unreadCountForUser(int $userId): int
    {
        // Cheap 60s cache: the unread badge is re-shown after any send/read,
        // and recomputed on each write below via bump('chat').
        return (int) \App\Core\Cache::rememberGen('chat', 'unread:u' . $userId, 60, static function () use ($userId): string {
            $conv = self::forUser($userId);
            if ($conv === null) {
                return '0';
            }

            $lastRead = (int) ($conv['last_read_message_id'] ?? 0);

            // Fall back to the legacy behaviour when nothing has been read yet:
            // count replies after the member's last message.
            if ($lastRead <= 0) {
                $lastUser = (int) Database::run(
                    "SELECT MAX(id) FROM chat_messages WHERE conversation_id = ? AND sender_role = 'user'",
                    [(int) $conv['id']]
                )->fetchColumn();
                $lastRead = $lastUser;
            }

            return (string) (int) Database::run(
                'SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ? AND id > ? AND sender_role IN (?, ?)',
                [(int) $conv['id'], $lastRead, self::ROLE_MODEL, self::ROLE_OPERATOR]
            )->fetchColumn();
        });
    }

    /**
     * Record that the member has read up to (at least) $messageId in this
     * conversation. Only ever moves forward; never regresses.
     */
    public static function markRead(int $conversationId, int $messageId): void
    {
        if ($conversationId <= 0 || $messageId <= 0) {
            return;
        }

        Database::run(
            'UPDATE chat_conversations
                SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), ?)
              WHERE id = ?',
            [$messageId, $conversationId]
        );

        \App\Core\Cache::bump('chat');
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

    /** Whether the member may currently send replies in this conversation. */
    public static function memberReplyEnabled(int $conversationId): bool
    {
        $value = Database::run(
            'SELECT member_reply_enabled FROM chat_conversations WHERE id = ? LIMIT 1',
            [$conversationId]
        )->fetchColumn();

        return $value === false || $value === null || (int) $value === 1;
    }

    /** Toggle the member reply flag for a conversation. */
    public static function setMemberReply(int $conversationId, bool $enabled): bool
    {
        $stmt = Database::run(
            'UPDATE chat_conversations SET member_reply_enabled = ? WHERE id = ?',
            [$enabled ? 1 : 0, $conversationId]
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
        // Operator replies are trainer-ready immediately (cleaned = 1): the
        // model learns from real replies without a separate export step.
        // Junk and duplicate pairs are silently skipped.
        \App\Models\ChatTraining::savePair($userMessage, $operatorReply);
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