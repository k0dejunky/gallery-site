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

            if ($user !== null && \App\Core\Auth::isAdminRole($user)) {
                return '1';
            }

            $row = Database::run(
                'SELECT p.can_chat
                 FROM subscriptions s
                 JOIN plans p ON p.id = s.plan_id
                 WHERE s.user_id = ?
                   AND ' . \App\Models\Subscription::activeWhere('s') . '
                   AND p.can_chat = 1
                 ORDER BY s.id DESC
                 LIMIT 1',
                [$userId]
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
     * @param array<int,array{title:string,url:string}>|null $contentRefs clickable
     *        gallery references the AI reply used (stored so links survive reloads)
     * @param string|null $expiresAt when the attachment stops being viewable (UTC)
     * @param int|null $maxViews how many member views the attachment allows
     */
    public static function addMessage(int $conversationId, string $role, string $message, ?array $attachment = null, ?array $contentRefs = null, ?string $expiresAt = null, ?int $maxViews = null, ?int $replyToId = null): int
    {
        $message = mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH);
        if ($message === '' && $attachment === null) {
            return 0;
        }

        // Expiry only makes sense for messages that actually carry media.
        if ($attachment === null) {
            $expiresAt = null;
            $maxViews  = null;
        }

        // A quote must point at a live message in the SAME conversation: an
        // id from another conversation would leak its text into this thread.
        if ($replyToId !== null && $replyToId > 0) {
            $ok = Database::run(
                'SELECT id FROM chat_messages
                 WHERE id = ? AND conversation_id = ? AND deleted_at IS NULL LIMIT 1',
                [$replyToId, $conversationId]
            )->fetchColumn();
            if ($ok === false) {
                $replyToId = null;
            }
        } else {
            $replyToId = null;
        }

        Database::run(
            'INSERT INTO chat_messages (conversation_id, sender_role, message, attachment_name, attachment_type, attachment_path, expires_at, max_views, view_count, content_refs, reply_to_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [
                $conversationId,
                $role,
                $message,
                $attachment['name'] ?? null,
                $attachment['type'] ?? null,
                $attachment['path'] ?? null,
                $expiresAt,
                $maxViews !== null && $maxViews > 0 ? $maxViews : null,
                $contentRefs === null || $contentRefs === [] ? null : json_encode(array_values($contentRefs), JSON_UNESCAPED_SLASHES),
                $replyToId,
            ]
        );

        \App\Core\Cache::bump('chat');

        return (int) Database::connection()->lastInsertId();
    }

    /** How long a member may edit their own message. */
    public const EDIT_WINDOW_MINUTES = 15;

    /** The reactions the client offers. Anything else is refused. */
    public const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🔥'];

    /**
     * Edit one of your own messages. Returns [ok, error].
     *
     * The age is measured in SQL (TIMESTAMPDIFF against NOW()), not in PHP
     * against time(): created_at is written by MySQL in the session time
     * zone, and a box whose session zone differs from PHP's would otherwise
     * report a brand-new message as hours old.
     *
     * @return array{0:bool, 1:string}
     */
    public static function editMessage(int $id, int $userId, string $text): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_MESSAGE_LENGTH);
        if ($text === '') {
            return [false, 'Write something to save.'];
        }

        $row = Database::run(
            'SELECT m.id, m.sender_role, m.deleted_at, c.user_id,
                    TIMESTAMPDIFF(SECOND, m.created_at, NOW()) AS age
             FROM chat_messages m
             JOIN chat_conversations c ON c.id = m.conversation_id
             WHERE m.id = ? LIMIT 1',
            [$id]
        )->fetch();

        if ($row === false || (int) $row['user_id'] !== $userId) {
            return [false, 'That message was not found.'];
        }
        if ((string) $row['sender_role'] !== self::ROLE_USER) {
            return [false, 'Only your own messages can be edited.'];
        }
        if (!empty($row['deleted_at'])) {
            return [false, 'That message was deleted.'];
        }
        if ((int) $row['age'] > self::EDIT_WINDOW_MINUTES * 60) {
            return [false, 'A message can only be edited for ' . self::EDIT_WINDOW_MINUTES . ' minutes after it is sent.'];
        }

        Database::run(
            'UPDATE chat_messages SET message = ?, edited_at = UTC_TIMESTAMP() WHERE id = ?',
            [$text, $id]
        );
        \App\Core\Cache::bump('chat');

        return [true, ''];
    }

    /**
     * Soft-delete one of your own messages. The row stays so a quote and the
     * other side's answer still make sense; the bubble reads "deleted".
     */
    public static function softDelete(int $id, int $userId): bool
    {
        return Database::run(
            'UPDATE chat_messages m
             JOIN chat_conversations c ON c.id = m.conversation_id
             SET m.deleted_at = UTC_TIMESTAMP()
             WHERE m.id = ? AND c.user_id = ? AND m.sender_role = ? AND m.deleted_at IS NULL',
            [$id, $userId, self::ROLE_USER]
        )->rowCount() > 0;
    }

    /**
     * Toggle one reaction on a message.
     *
     * @return array{ok:bool, active:bool}
     */
    public static function toggleReaction(int $messageId, string $actor, string $emoji): array
    {
        if ($messageId <= 0 || !in_array($actor, [self::ROLE_USER, self::ROLE_OPERATOR], true)) {
            return ['ok' => false, 'active' => false];
        }
        if (!in_array($emoji, self::REACTIONS, true)) {
            return ['ok' => false, 'active' => false];
        }

        $exists = Database::run(
            'SELECT id FROM chat_messages WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$messageId]
        )->fetchColumn();
        if ($exists === false) {
            return ['ok' => false, 'active' => false];
        }

        $removed = Database::run(
            'DELETE FROM chat_reactions WHERE message_id = ? AND actor = ? AND emoji = ?',
            [$messageId, $actor, $emoji]
        )->rowCount();

        if ($removed > 0) {
            \App\Core\Cache::bump('chat');

            return ['ok' => true, 'active' => false];
        }

        Database::run(
            'INSERT IGNORE INTO chat_reactions (message_id, actor, emoji, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$messageId, $actor, $emoji]
        );
        \App\Core\Cache::bump('chat');

        return ['ok' => true, 'active' => true];
    }

    /**
     * The reactions on a set of messages, keyed by message id.
     *
     * One query for the whole thread: a chat with fifty messages must not run
     * fifty queries to draw its emoji.
     *
     * @param array<int, int|string> $messageIds
     * @return array<int, array<int, array{emoji:string, actors:array<int,string>}>>
     */
    public static function reactionsFor(array $messageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            "SELECT message_id, actor, emoji FROM chat_reactions
             WHERE message_id IN ($placeholders)
             ORDER BY id ASC",
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $mid    = (int) $row['message_id'];
            $emoji  = (string) $row['emoji'];
            $out[$mid] = $out[$mid] ?? [];
            $found = false;
            foreach ($out[$mid] as &$entry) {
                if ($entry['emoji'] === $emoji) {
                    $entry['actors'][] = (string) $row['actor'];
                    $found = true;
                    break;
                }
            }
            unset($entry);
            if (!$found) {
                $out[$mid][] = ['emoji' => $emoji, 'actors' => [(string) $row['actor']]];
            }
        }

        return $out;
    }

    /** How far the operator has read (their "seen" pointer). */
    public static function operatorReadThrough(int $conversationId): int
    {
        if ($conversationId <= 0) {
            return 0;
        }

        return (int) Database::run(
            'SELECT operator_read_through_id FROM chat_conversations WHERE id = ? LIMIT 1',
            [$conversationId]
        )->fetchColumn();
    }

    /** Mark the operator as typing in this conversation (admin panel). */
    public static function markOperatorTyping(int $conversationId): void
    {
        if ($conversationId <= 0) {
            return;
        }
        Database::run(
            'UPDATE chat_conversations SET operator_typing_at = NOW() WHERE id = ?',
            [$conversationId]
        );
    }

    /**
     * Whether the operator typed within the last $windowSec seconds.
     *
     * The comparison runs entirely in SQL for the same reason memberTyping()
     * does: a box whose MySQL session zone differs from PHP's would otherwise
     * report a live indicator as hours old.
     */
    public static function operatorTyping(int $conversationId, int $windowSec = 7): bool
    {
        if ($conversationId <= 0) {
            return false;
        }

        $live = Database::run(
            'SELECT (operator_typing_at IS NOT NULL
                      AND TIMESTAMPDIFF(SECOND, operator_typing_at, NOW()) <= ?) AS live
             FROM chat_conversations WHERE id = ? LIMIT 1',
            [max(1, $windowSec), $conversationId]
        )->fetchColumn();

        return (int) $live === 1;
    }

    /**
     * Search this conversation's text. Deleted messages are not results.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(int $conversationId, string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($conversationId <= 0 || $query === '') {
            return [];
        }
        $query = mb_substr($query, 0, 100);
        $limit = max(1, min(100, $limit));

        return Database::run(
            'SELECT id, sender_role, message, created_at FROM chat_messages
             WHERE conversation_id = ? AND deleted_at IS NULL AND message LIKE ?
             ORDER BY id DESC LIMIT ' . $limit,
            [$conversationId, '%' . $query . '%']
        )->fetchAll();
    }

    /** Whether a message's attachment is expired (time passed or view limit hit). */
    public static function isMediaExpired(array $msg): bool
    {
        if (empty($msg['attachment_path'])) {
            return false;
        }
        $expires = (string) ($msg['expires_at'] ?? '');
        if ($expires !== '' && $expires !== '0000-00-00 00:00:00' && strtotime($expires . ' UTC') <= time()) {
            return true;
        }
        $maxViews = (int) ($msg['max_views'] ?? 0);
        if ($maxViews > 0 && (int) ($msg['view_count'] ?? 0) >= $maxViews) {
            return true;
        }
        return false;
    }

    /**
     * Delete the stored files + attachment metadata of messages whose media has
     * expired, so expired media frees storage and becomes permanently gone.
     * Returns the number of messages purged.
     */
    public static function purgeExpiredMedia(): int
    {
        $rows = Database::run(
            'SELECT id, attachment_path FROM chat_messages
             WHERE attachment_path IS NOT NULL AND attachment_path <> \'\'
               AND (expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()
                    OR (max_views IS NOT NULL AND max_views > 0 AND view_count >= max_views))'
        )->fetchAll();

        $purged = 0;
        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $path = (string) $row['attachment_path'];

            $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
            @unlink($full);
            // Cached thumbnail lives next to the file under chat/thumbs/.
            $dir  = dirname($full);
            $base = pathinfo($full, PATHINFO_FILENAME);
            foreach (glob($dir . '/thumbs/' . $base . '*') ?: [] as $thumb) {
                @unlink($thumb);
            }

            Database::run(
                'UPDATE chat_messages
                 SET attachment_name = NULL, attachment_type = NULL, attachment_path = NULL,
                     expires_at = NULL, max_views = NULL, view_count = 0
                 WHERE id = ?',
                [$id]
            );
            $purged++;
        }

        if ($purged > 0) {
            \App\Core\Cache::bump('chat');
        }

        return $purged;
    }

    // ------------------------------------------------------------------ media
    // The three chat attachment endpoints (member, operator bridge, admin) all
    // serve the same file the same way; these shared helpers keep them from
    // duplicating the sniffing / thumbnail / serving / decoration logic.

    /**
     * Serve a chat attachment: resolve the file, enforce expiry, (optionally)
     * count a member view, sniff the real MIME type and stream the file - or a
     * cached 320px JPEG thumbnail when $thumb is set. The caller has already
     * authenticated the request and (where applicable) verified ownership.
     */
    public static function serveAttachment(array $msg, bool $thumb = false, bool $countView = false): void
    {
        if (empty($msg['attachment_path'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Attachment not found.']);
            exit;
        }

        // Expiring media: refuse to serve once the time or view limit is hit.
        if (self::isMediaExpired($msg)) {
            http_response_code(410);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Media has expired.']);
            exit;
        }

        $path = dirname(__DIR__, 2) . '/' . $msg['attachment_path'];
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Attachment file missing.']);
            exit;
        }

        // A member view of the full media consumes one of the view limit
        // (thumbnails are free - they are just list previews).
        if ($countView && (int) ($msg['max_views'] ?? 0) > 0 && !$thumb) {
            Database::run(
                'UPDATE chat_messages SET view_count = view_count + 1 WHERE id = ? AND view_count < max_views',
                [(int) $msg['id']]
            );
        }

        $name = (string) ($msg['attachment_name'] ?? basename($path));
        $mime = (string) ($msg['attachment_type'] ?? (mime_content_type($path) ?: 'application/octet-stream'));

        // Phone uploads may be stored as octet-stream even for images: sniff
        // the real type from the file content so thumbnails still work.
        if (!str_starts_with($mime, 'image/')) {
            $real = self::sniffAttachmentType((string) $msg['attachment_path']);
            if (str_starts_with($real, 'image/')) {
                $mime = $real;
            }
        }

        // Image attachments can be requested as a small thumbnail.
        if ($thumb && str_starts_with($mime, 'image/')) {
            $out = self::makeThumbnail($path);
            if ($out !== null) {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . (string) filesize($out));
                readfile($out);
                exit;
            }
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addcslashes($name, '"') . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** Sniff a stored attachment's real MIME type from its content. */
    public static function sniffAttachmentType(string $path): string
    {
        $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
        if (!is_file($full)) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $t = $fi !== false ? finfo_file($fi, $full) : false;
            if (is_resource($fi)) {
                finfo_close($fi);
            }
            if (is_string($t) && $t !== '') {
                return $t;
            }
        }
        return mime_content_type($full) ?: '';
    }

    /**
     * Generate (and cache) a JPEG thumbnail no wider than 320px for an image
     * file. Returns the cached thumb path, or null on any failure.
     */
    private static function makeThumbnail(string $path): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $key = 'thumb_' . hash('sha1', (string) filesize($path) . '_' . filemtime($path)) . '.jpg';
        $thumbsDir = dirname(__DIR__, 2) . '/storage/uploads/chat/thumbs';
        if (!is_dir($thumbsDir)) {
            @mkdir($thumbsDir, 0775, true);
        }
        $out = $thumbsDir . '/' . $key;
        if (is_file($out) && filemtime($out) >= filemtime($path)) {
            return $out;
        }

        $img = @imagecreatefromstring((string) file_get_contents($path));
        if ($img === false) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            return null;
        }

        $maxW = 320;
        if ($w > $maxW) {
            $scale = $maxW / $w;
            $nw = $maxW;
            $nh = max(1, (int) round($h * $scale));
        } else {
            $nw = $w;
            $nh = $h;
        }

        $thumb = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        imagejpeg($thumb, $out, 82);
        imagedestroy($thumb);

        return $out;
    }

    /**
     * Add attachment URL(s) + expiring-media metadata to message rows so the
     * various chat UIs (member web, admin web, operator app) can render them.
     * $urlBase is the controller's attachment route (e.g. '/chat/attachment').
     */
    public static function decorateMessages(array $messages, string $urlBase): array
    {
        foreach ($messages as &$m) {
            $type = (string) ($m['attachment_type'] ?? '');
            // Self-heal: phone uploads sometimes arrive as octet-stream even
            // for images; sniff the real type so thumbnails render.
            if (!str_starts_with($type, 'image/') && !empty($m['attachment_path'])) {
                $real = self::sniffAttachmentType((string) $m['attachment_path']);
                if (str_starts_with($real, 'image/')) {
                    $type = $real;
                    $m['attachment_type'] = $real;
                }
            }
            $m['attachment_url'] = !empty($m['attachment_path'])
                ? url($urlBase . '?message=' . (int) $m['id'])
                : null;
            $m['attachment_thumb_url'] = !empty($m['attachment_path']) && str_starts_with($type, 'image/')
                ? url($urlBase . '?message=' . (int) $m['id'] . '&thumb=1')
                : null;
            $m['expires_at'] = !empty($m['expires_at']) ? (string) $m['expires_at'] : null;
            $m['max_views']  = (int) ($m['max_views'] ?? 0);
            $m['view_count'] = (int) ($m['view_count'] ?? 0);
            $m['media_expired'] = self::isMediaExpired($m);
            if ($m['media_expired']) {
                $m['attachment_url'] = null;
                $m['attachment_thumb_url'] = null;
            }
            $m['content_refs'] = !empty($m['content_refs'])
                ? json_decode((string) $m['content_refs'], true)
                : [];

            // A deleted message keeps its place in the thread but nothing
            // else: no text, no media, no quote source.
            $m['deleted'] = !empty($m['deleted_at']);
            if ($m['deleted']) {
                $m['message'] = '';
                $m['attachment_name'] = null;
                $m['attachment_url'] = null;
                $m['attachment_thumb_url'] = null;
                $m['media_expired'] = false;
                $m['content_refs'] = [];
                $m['reply_to_id'] = null;
            }
            $m['edited'] = !empty($m['edited_at']);
            $m['pinned'] = !empty($m['pinned_at']);

            // Display labels are computed HERE, in the site time zone, and
            // shipped with the message. A browser does not know the site's
            // zone (the boxes disagree with their own MySQL session zone), so
            // letting JS format the timestamp drifts by hours on some boxes.
            $created = (string) ($m['created_at'] ?? '');
            $m['time_label'] = $created !== '' ? tzdate('g:i A', $created) : '';
            $m['day']        = $created !== '' ? tzdate('Y-m-d', $created) : '';
            $m['day_label']  = '';
            if ($m['day'] !== '') {
                if ($m['day'] === tzdate('Y-m-d')) {
                    $m['day_label'] = 'Today';
                } elseif ($m['day'] === tzdate('Y-m-d', time() - 86400)) {
                    $m['day_label'] = 'Yesterday';
                } else {
                    $m['day_label'] = tzdate('j F Y', $created);
                }
            }
            $m['reactions'] = [];
            $m['reply_to'] = null;
        }
        unset($m);

        if ($messages === []) {
            return [];
        }

        // Reactions and quotes are loaded for the WHOLE batch in two queries.
        // A thread must not run two queries per bubble.
        $reactions = self::reactionsFor(array_column($messages, 'id'));
        foreach ($messages as &$m) {
            $m['reactions'] = $reactions[(int) $m['id']] ?? [];
        }
        unset($m);

        $quoteIds = [];
        foreach ($messages as $m) {
            if (!empty($m['reply_to_id'])) {
                $quoteIds[] = (int) $m['reply_to_id'];
            }
        }
        $quoteIds = array_values(array_unique($quoteIds));

        if ($quoteIds !== []) {
            $placeholders = implode(',', array_fill(0, count($quoteIds), '?'));
            $quotes = Database::run(
                "SELECT id, sender_role, message, attachment_name, deleted_at
                 FROM chat_messages WHERE id IN ($placeholders)",
                $quoteIds
            )->fetchAll();

            $byId = [];
            foreach ($quotes as $quote) {
                $excerpt = trim(preg_replace('/\s+/', ' ', (string) $quote['message']) ?? '');
                if (!empty($quote['deleted_at'])) {
                    $excerpt = '';
                } elseif ($excerpt === '' && !empty($quote['attachment_name'])) {
                    $excerpt = '📎 ' . (string) $quote['attachment_name'];
                }
                $byId[(int) $quote['id']] = [
                    'id'      => (int) $quote['id'],
                    'role'    => (string) $quote['sender_role'],
                    'excerpt' => mb_substr($excerpt, 0, 140),
                    'deleted' => !empty($quote['deleted_at']),
                ];
            }

            foreach ($messages as &$m) {
                $rid = (int) ($m['reply_to_id'] ?? 0);
                $m['reply_to'] = $rid > 0 ? ($byId[$rid] ?? null) : null;
            }
            unset($m);
        }

        return $messages;
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
    /** Why the last storeAttachment() refused a file: '' | type | storage. */
    private static string $attachmentError = '';

    public static function attachmentError(): string
    {
        return self::$attachmentError;
    }

    public static function storeAttachment(array $file): ?array
    {
        self::$attachmentError = '';

        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_file($tmp)) {
            self::$attachmentError = 'storage';
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
            self::$attachmentError = 'type';
            return null;
        }

        $name = trim((string) ($file['name'] ?? ''));
        if ($name === '' || $name !== basename($name)) {
            $name = 'attachment-' . bin2hex(random_bytes(6));
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
        $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            self::$attachmentError = 'type';
            return null;
        }

        $dir = dirname(__DIR__, 2) . '/storage/uploads/chat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . bin2hex(random_bytes(6)) . '_' . $safeName;

        if (!@move_uploaded_file($tmp, $dest)) {
            if (!@copy($tmp, $dest)) {
                // A permissions problem is not an unsupported file type, and
                // saying so sends the operator looking in the wrong place.
                self::$attachmentError = 'storage';
                error_log('[chat-attachment] the upload could not be written to ' . $dir . ' (check ownership/permissions)');
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

    /**
     * The pinned messages of a conversation, oldest-first.
     *
     * The operator pins a message to keep it at hand (a promise, a price,
     * a correction). The thread shows them above the conversation.
     */
    public static function pinnedMessages(int $conversationId): array
    {
        return Database::run(
            'SELECT * FROM chat_messages
             WHERE conversation_id = ? AND pinned_at IS NOT NULL
             ORDER BY pinned_at ASC',
            [$conversationId]
        )->fetchAll();
    }

    /**
     * Pin or unpin a message.
     *
     * Only the operator can pin, and only their own conversations may be
     * touched.
     */
    public static function setPinned(int $messageId, bool $pinned): bool
    {
        if ($messageId <= 0) {
            return false;
        }

        if ($pinned) {
            return Database::run(
                'UPDATE chat_messages SET pinned_at = UTC_TIMESTAMP()
                 WHERE id = ? AND pinned_at IS NULL',
                [$messageId]
            )->rowCount() > 0;
        }

        return Database::run(
            'UPDATE chat_messages SET pinned_at = NULL WHERE id = ? AND pinned_at IS NOT NULL',
            [$messageId]
        )->rowCount() > 0;
    }

    /**
     * Delete a message and everything attached to it.
     *
     * The operator had no way to take a message down at all: the admin
     * panel could delete LIVE chat messages, but a regular chat message
     * could only be deleted by deleting the whole conversation.
     */
    public static function deleteMessage(int $messageId): bool
    {
        if ($messageId <= 0) {
            return false;
        }

        $row = Database::run(
            'SELECT id, attachment_path FROM chat_messages WHERE id = ? LIMIT 1',
            [$messageId]
        )->fetch();

        if ($row === false || $row === null) {
            return false;
        }

        $deleted = Database::run(
            'DELETE FROM chat_messages WHERE id = ?',
            [$messageId]
        )->rowCount() > 0;

        if ($deleted) {
            // The attachment file is left alone: it may be shared with
            // another message (the same upload in another conversation).
            \App\Core\Cache::bump('chat');
        }

        return $deleted;
    }

    /**
     * Whether a member is blocked from sending chat messages.
     */
    public static function userBlocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $until = Database::run(
            'SELECT chat_blocked_until FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetchColumn();

        if ($until === false || $until === null || $until === '') {
            return false;
        }

        return strtotime((string) $until . ' UTC') > time();
    }

    /**
     * Block a member from sending chat messages, for $hours (0 = forever).
     */
    public static function blockUser(int $userId, int $hours = 0): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($hours <= 0) {
            return Database::run(
                'UPDATE users SET chat_blocked_until = ? WHERE id = ?',
                ['2999-12-31 23:59:59', $userId]
            )->rowCount() >= 0;
        }

        return Database::run(
            'UPDATE users SET chat_blocked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR)
             WHERE id = ?',
            [$hours, $userId]
        )->rowCount() >= 0;
    }

    public static function unblockUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return Database::run(
            'UPDATE users SET chat_blocked_until = NULL WHERE id = ?',
            [$userId]
        )->rowCount() >= 0;
    }

    public static function latestId(int $conversationId): int
    {
        $id = Database::run(
            'SELECT MAX(id) FROM chat_messages WHERE conversation_id = ?',
            [$conversationId]
        )->fetchColumn();

        return (int) $id;
    }

    /**
     * The text of the most recent message in a conversation ('' when none).
     */
    public static function lastMessageText(int $conversationId): string
    {
        $text = Database::run(
            'SELECT message FROM chat_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1',
            [$conversationId]
        )->fetchColumn();

        return $text === false || $text === null ? '' : (string) $text;
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
                SET last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), CAST(? AS UNSIGNED))
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

    /** Record that the member is typing right now (throttled pings). */
    public static function markMemberTyping(int $conversationId): void
    {
        Database::run(
            'UPDATE chat_conversations SET member_typing_at = NOW() WHERE id = ?',
            [$conversationId]
        );
    }

    /**
     * Whether the member typed within the last $windowSec seconds. The window
     * is intentionally longer than the web composer's ping interval (3 s) so
     * the indicator stays up while someone types continuously.
     *
     * The comparison runs ENTIRELY in SQL: member_typing_at is written with
     * MySQL NOW(), so subtracting PHP time() skews the window whenever the
     * MySQL session timezone differs from the app timezone (it did — 6 h on
     * the dev box, which kept the indicator permanently off).
     */
    public static function memberTyping(int $conversationId, int $windowSec = 7): bool
    {
        $live = Database::run(
            'SELECT (member_typing_at IS NOT NULL
                       AND TIMESTAMPDIFF(SECOND, member_typing_at, NOW()) <= ?) AS live
             FROM chat_conversations WHERE id = ? LIMIT 1',
            [max(1, $windowSec), $conversationId]
        )->fetchColumn();

        return (bool) $live;
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
     * In-app (wall) notification letting the member know the operator replied.
     * Companion to EmailQueue::notifyChatReply, which handles the email side.
     */
    public static function notifyMemberReply(int $conversationId, string $preview = ''): void
    {
        $conversationId = max(0, $conversationId);
        if ($conversationId <= 0) {
            return;
        }

        $userId = (int) Database::run(
            'SELECT user_id FROM chat_conversations WHERE id = ? LIMIT 1',
            [$conversationId]
        )->fetchColumn();
        if ($userId <= 0) {
            return;
        }

        $preview = trim($preview);
        if (mb_strlen($preview) > 60) {
            $preview = mb_substr($preview, 0, 59) . '…';
        }

        Notification::add(
            $userId,
            'chat_reply',
            'New reply from the site',
            $preview !== '' ? $preview : 'You have a new message waiting in chat.',
            '/chat'
        );
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