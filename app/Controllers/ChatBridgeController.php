<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ChatAi;
use App\Models\ChatMessage;

/**
 * Chat bridge webhooks — the thin Android app (and the always-on training PC)
 * talk to the site through these Bearer-authenticated endpoints. No session,
 * no CSRF (machine-to-machine), authenticated with GALLERY_CHAT_KEY.
 *
 *   GET  /webhooks/chat/config?conversation=ID  -> mode + pending count
 *   GET  /webhooks/chat/pending                  -> member messages awaiting a reply
 *   POST /webhooks/chat/reply                    -> append an operator/model reply
 *   GET  /webhooks/chat/context?conversation=ID  -> recent conversation + few-shot
 *   GET  /webhooks/chat/training-data            -> cleaned training pairs (JSONL)
 *   POST /webhooks/chat/training-upload          -> upload a trained adapter
 */
class ChatBridgeController extends Controller
{
    private function authorized(): bool
    {
        $expected = \env_value('GALLERY_CHAT_KEY', '');
        if ($expected === '') {
            return false;
        }

        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }

        return hash_equals($expected, $given);
    }

    public function __construct($request)
    {
        parent::__construct($request);
        if (!$this->authorized()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }

    /** The AI/Live mode + pending message count for a conversation. */
    public function config(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));
        $conv = $cid > 0 ? ChatMessage::find($cid) : null;

        $pending = $cid > 0
            ? (int) \App\Core\Database::run(
                "SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ? AND sender_role = 'user' AND id > ?",
                [$cid, 0]
            )->fetchColumn()
            : 0;

        $this->json([
            'ok'           => true,
            'conversation' => $conv ? (int) $conv['id'] : 0,
            'ai_mode'      => $conv ? (string) $conv['ai_mode'] : 'retrieval',
            'status'       => $conv ? (string) $conv['status'] : 'closed',
            'pending'      => $pending,
            'user_id'      => $conv ? (int) $conv['user_id'] : 0,
        ]);
    }

    /** Member messages awaiting a reply (newest first). */
    public function pending(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));

        $rows = $cid > 0
            ? ChatMessage::messages($cid, 0, false)
            : [];

        $userMessages = [];
        foreach ($rows as $row) {
            if (($row['sender_role'] ?? '') === 'user') {
                $userMessages[] = $row;
            }
        }

        $this->json(['ok' => true, 'messages' => array_slice($userMessages, 0, 20), 'conversation' => $cid]);
    }

    /**
     * The operator inbox: every conversation with the member's email, mode,
     * status, last message preview, and how many member messages are awaiting
     * a reply. Newest activity first. No chat id required.
     */
    public function inbox(): void
    {
        $rows = \App\Core\Database::run(
            "SELECT c.id, c.user_id, c.ai_mode, c.status, c.updated_at,
                    u.email AS user_email,
                    (SELECT m.message FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.sender_role FROM chat_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_role = 'user') AS member_count,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_role = 'user' AND m.id > COALESCE((SELECT MAX(x.id) FROM chat_messages x WHERE x.conversation_id = c.id AND x.sender_role IN ('model','operator')),0)) AS unread_replyable
             FROM chat_conversations c
             JOIN users u ON u.id = c.user_id
             WHERE c.status = 'open'
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT 100"
        )->fetchAll();

        $this->json(['ok' => true, 'conversations' => $rows]);
    }

    /**
     * Full message history for one conversation (oldest first) so the app can
     * render the whole thread like a messenger. Includes the member email.
     */
    public function thread(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));
        $conv = $cid > 0 ? ChatMessage::find($cid) : null;
        if ($conv === null) {
            $this->json(['ok' => false, 'error' => 'Conversation not found.']);
            return;
        }

        $user = \App\Core\Database::run('SELECT email FROM users WHERE id = ?', [(int) $conv['user_id']])->fetch();

        $this->json([
            'ok'           => true,
            'conversation' => $cid,
            'ai_mode'      => (string) $conv['ai_mode'],
            'status'       => (string) $conv['status'],
            'user_email'   => $user['email'] ?? ('user#' . $conv['user_id']),
            'messages'     => $this->decorateMessages(ChatMessage::messages($cid, 0, true)),
        ]);
    }

    /**
     * Server-Sent Events stream for the Android app: pushes new messages for a
     * conversation in real time (no polling / refresh). Authenticated with the
     * same Bearer token; keeps the connection open up to ~30s.
     *
     *   GET /webhooks/chat/stream?conversation=ID&since=N
     *   data: {"ok":true,"messages":[...],"latestId":N}
     */
    public function stream(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));
        $since = max(0, (int) $this->request->query('since', 0));
        $conv = $cid > 0 ? ChatMessage::find($cid) : null;
        if ($conv === null) {
            header('Content-Type: text/event-stream');
            echo "data: {\"ok\":false,\"error\":\"Conversation not found.\"}\n\n";
            exit;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Ensure PHP streams rather than buffering the whole response.
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        $latestId = ChatMessage::latestId($cid);
        $new = $this->decorateMessages(ChatMessage::messages($cid, $since));
        if ($new !== []) {
            echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'conversation' => $cid]) . "\n\n";
            flush();
            $since = $latestId;
        }

        $start = time();
        while (time() - $start < 30) {
            $new = $this->decorateMessages(ChatMessage::messages($cid, $since));
            if ($new !== []) {
                $latestId = ChatMessage::latestId($cid);
                echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'conversation' => $cid]) . "\n\n";
                flush();
                $since = $latestId;
                continue;
            }
            echo ": keepalive\n\n";
            flush();
            usleep(1500000);
        }

        exit;
    }

    /**
     * Append a reply from the Android app. sender_role is 'operator' when a
     * human replied live (harvested into the training corpus) or 'model' when
     * the server/AI produced it. Returns the new message id.
     */
    public function reply(): void
    {
        // Support both JSON (text only) and multipart (text + optional file).
        $data = json_decode($this->rawBody(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $cid  = (int) ($data['conversation_id'] ?? $this->request->post('conversation_id', 0));
        $msg  = trim((string) ($data['message'] ?? $this->request->post('message', '')));
        $role = (string) ($data['sender_role'] ?? $this->request->post('sender_role', 'operator'));

        if (!in_array($role, [ChatMessage::ROLE_OPERATOR, ChatMessage::ROLE_MODEL], true)) {
            $role = ChatMessage::ROLE_OPERATOR;
        }

        if ($cid <= 0 || ChatMessage::find($cid) === null) {
            $this->json(['ok' => false, 'error' => 'Conversation not found.']);
            return;
        }

        // Optional attachment from a multipart upload.
        $attachment = null;
        $file = $this->request->file('attachment');
        if ($file !== null && !empty($file['tmp_name']) && is_file($file['tmp_name'])) {
            $attachment = $this->storeChatAttachment($file);
            if ($attachment === null) {
                $this->json(['ok' => false, 'error' => 'Attachment could not be stored.']);
                return;
            }
        }

        $id = ChatMessage::addMessage($cid, $role, $msg, $attachment);
        if ($id <= 0) {
            $this->json(['ok' => false, 'error' => 'Message is empty (or too long) with no attachment.']);
            return;
        }

        // Harvest operator replies into the training corpus so the AI learns
        // from the human operator's responses.
        if ($role === ChatMessage::ROLE_OPERATOR) {
            $userMsg = \App\Core\Database::run(
                "SELECT message FROM chat_messages WHERE conversation_id = ? AND sender_role = 'user' AND id < ? ORDER BY id DESC LIMIT 1",
                [$cid, $id]
            )->fetchColumn();

            if ($userMsg !== false && trim((string) $userMsg) !== '') {
                ChatMessage::insertTrainingPair((string) $userMsg, $msg);
            }
        }

        $this->json(['ok' => true, 'id' => $id]);
    }

    /** Recent conversation + few-shot context (for the app/AI to build a reply). */
    public function context(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));
        $conv = $cid > 0 ? ChatMessage::find($cid) : null;
        if ($conv === null) {
            $this->json(['ok' => false, 'error' => 'Conversation not found.']);
            return;
        }

        $last = ChatMessage::messages($cid, 0, false);
        $last = array_slice($last, 0, 20);
        $userMsg = '';
        foreach ($last as $row) {
            if (($row['sender_role'] ?? '') === 'user') {
                $userMsg = (string) $row['message'];
                break;
            }
        }

        $this->json([
            'ok'           => true,
            'conversation' => $cid,
            'ai_mode'      => (string) $conv['ai_mode'],
            'history'      => $last,
            'few_shot'     => ChatMessage::similarContext($userMsg !== '' ? $userMsg : 'hello'),
            'ai_base'      => ChatAi::BASE_MODEL,
        ]);
    }

    /** Cleaned training pairs as JSONL (for the always-on training PC). */
    public function trainingData(): void
    {
        $since = max(0, (int) $this->request->query('since_id', 0));

        $rows = \App\Core\Database::run(
            'SELECT id, user_message, operator_reply, created_at FROM chat_training_pairs WHERE id > ? ORDER BY id ASC LIMIT 5000',
            [$since]
        )->fetchAll();

        header('Content-Type: application/x-ndjson');
        foreach ($rows as $row) {
            echo json_encode([
                'id'            => (int) $row['id'],
                'user_message'  => $row['user_message'],
                'operator_reply'=> $row['operator_reply'],
                'created_at'    => $row['created_at'],
            ]) . "\n";
        }
    }

    /** Accept an uploaded trained adapter + metadata; verify checksum. */
    public function trainingUpload(): void
    {
        $files = $this->request->file('adapter');
        $adapter = $files['tmp_name'] ?? '';
        $name    = (string) ($files['name'] ?? 'chat-lora.safetensors');

        if (!is_file($adapter)) {
            $this->json(['ok' => false, 'error' => 'No adapter file uploaded.']);
            return;
        }

        $checksum = hash_file('sha256', $adapter);
        $dir = dirname(__DIR__, 2) . '/storage/training';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/chat-lora.safetensors';
        copy($adapter, $dest);

        $meta = [
            'adapter_checksum' => $checksum,
            'base_model'       => (string) $this->request->post('base_model', ChatAi::BASE_MODEL),
            'pair_count'       => (int) $this->request->post('pair_count', 0),
            'trained_at'       => date('Y-m-d H:i:s'),
        ];

        $stateFile = dirname(__DIR__, 2) . '/storage/chat.json';
        $state = [];
        if (is_file($stateFile)) {
            $decoded = json_decode((string) @file_get_contents($stateFile), true);
            $state = is_array($decoded) ? $decoded : [];
        }
        $state['finetuned'] = $meta;
        @file_put_contents($stateFile, (string) json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        // Rebuild the Ollama fine-tuned model from the new adapter. If it
        // fails, the model is left unchanged (versioned create + smoke test).
        $rebuild = \App\Core\ChatModel::rebuild();
        if (!empty($rebuild['ok'])) {
            $state['finetuned']['created'] = $rebuild['created'];
            @file_put_contents($stateFile, (string) json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
            $meta['created'] = $rebuild['created'];
        } else {
            $meta['rebuild_error'] = $rebuild['error'] ?? 'unknown';
        }

        $this->json(['ok' => true, 'checksum' => $checksum, 'stored' => basename($dest), 'model' => $meta]);
    }

    /**
     * Download a chat attachment by message id (Bearer auth). Streams the
     * stored file so the Android app can render images / open files.
     */
    public function attachment(): void
    {
        $mid = max(0, (int) $this->request->query('message', 0));
        $thumb = (int) $this->request->query('thumb', 0) === 1;
        if ($mid <= 0) {
            $this->json(['ok' => false, 'error' => 'Message id required.']);
            return;
        }

        $msg = \App\Core\Database::run('SELECT * FROM chat_messages WHERE id = ? LIMIT 1', [$mid])->fetch();
        if (!$msg || empty($msg['attachment_path'])) {
            $this->json(['ok' => false, 'error' => 'No attachment on that message.']);
            return;
        }

        $path = dirname(__DIR__, 2) . '/' . $msg['attachment_path'];
        if (!is_file($path)) {
            $this->json(['ok' => false, 'error' => 'Attachment file missing.']);
            return;
        }

        $name = (string) ($msg['attachment_name'] ?? basename($path));
        $mime = (string) ($msg['attachment_type'] ?? (mime_content_type($path) ?: 'application/octet-stream'));

        // Image attachments can be requested as a small thumbnail.
        if ($thumb && str_starts_with($mime, 'image/')) {
            $out = $this->makeThumbnail($path);
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

    /**
     * Generate (and cache) a JPEG thumbnail no wider than 320px for an image
     * file. Returns the cached thumb path, or null on any failure.
     */
    private function makeThumbnail(string $path): ?string
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

    private function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Add an attachment_url to each message row that has an attachment, so
     * the Android app can render/download the file.
     */
    private function decorateMessages(array $messages): array
    {
        foreach ($messages as &$m) {
            $m['attachment_url'] = !empty($m['attachment_path'])
                ? url('/webhooks/chat/attachment?message=' . (int) $m['id'])
                : null;
            $m['attachment_thumb_url'] = !empty($m['attachment_path']) && $m['attachment_type'] !== null && str_starts_with((string) $m['attachment_type'], 'image/')
                ? url('/webhooks/chat/attachment?message=' . (int) $m['id'] . '&thumb=1')
                : null;
        }
        unset($m);

        return $messages;
    }

    /**
     * Store an uploaded chat attachment under storage/uploads/chat/ and return
     * the metadata to persist on the message row, or null on failure.
     *
     * @param array{tmp_name:string, name:string, type:string, size:int} $file
     * @return array{name:string, type:string, path:string}|null
     */
    private function storeChatAttachment(array $file): ?array
    {
        $dir = dirname(__DIR__, 2) . '/storage/uploads/chat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $name = trim((string) ($file['name'] ?? ''));
        if ($name === '' || $name !== basename($name)) {
            $name = 'attachment-' . bin2hex(random_bytes(6));
        }

        // Sanitize the stored filename (keep the extension, drop path chars).
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
        $dest = $dir . '/' . bin2hex(random_bytes(6)) . '_' . $safeName;

        if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
            if (!@copy((string) $file['tmp_name'], $dest)) {
                return null;
            }
        }

        return [
            'name' => $safeName,
            'type' => (string) ($file['type'] ?? (mime_content_type($dest) ?: 'application/octet-stream')),
            'path' => str_replace(dirname(__DIR__, 2) . '/', '', $dest),
        ];
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}