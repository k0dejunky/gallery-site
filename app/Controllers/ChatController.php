<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ChatAi;
use App\Models\ChatMessage;

/**
 * Member-facing chat: the conversation page, sending messages, and the
 * polling endpoint that surfaces AI/operator replies live. Access is gated by
 * ChatMessage::canChat (Platinum-yearly, Lifetime, or the Chat add-on plan).
 */
class ChatController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        $eligible = ChatMessage::canChat($userId);

        // The chat feature is visible to every logged-in user. Users without
        // the chat plan see the admin's daily message (read-only) and an
        // upgrade prompt; they cannot send messages (the composer is hidden).
        $conv = $eligible ? ChatMessage::forUser($userId) : null;
        if ($conv === null) {
            $conv = ['id' => 0, 'ai_mode' => 'retrieval', 'status' => 'open'];
        }

        $messages = $conv['id'] > 0 ? $this->decorateMessages(ChatMessage::messages((int) $conv['id'])) : [];

        $this->view('chat/index', [
            'title'        => 'Chat',
            'eligible'     => $eligible,
            'dailyMessage' => \App\Core\ChatSettings::dailyMessage(),
            'aiEnabled'    => \App\Core\ChatSettings::aiEnabled(),
            'conversation' => $conv,
            'messages'     => $messages,
            'latestId'     => $conv['id'] > 0 ? ChatMessage::latestId((int) $conv['id']) : 0,
            'aiStatus'     => ChatAi::ping(),
        ]);
    }

    /**
     * Send a message. In retrieval/finetuned mode the server replies
     * immediately (synchronous). Returns JSON for the AJAX composer.
     */
    public function send(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            $this->json(['ok' => false, 'error' => 'You are not eligible to chat.']);
            return;
        }

        $message = (string) $this->request->post('message', '');
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > ChatMessage::MAX_MESSAGE_LENGTH) {
            $this->json(['ok' => false, 'error' => 'Message must be 1–' . ChatMessage::MAX_MESSAGE_LENGTH . ' characters.']);
            return;
        }

        $conv = ChatMessage::forUser($userId);
        if ($conv === null) {
            // New conversations start in the admin's saved default mode.
            $defaultMode = (string) (\App\Core\ChatSettings::all()['default_ai_mode'] ?? ChatMessage::MODE_RETRIEVAL);
            $cid = ChatMessage::openFor($userId, $defaultMode);
        } else {
            $cid = (int) $conv['id'];
        }
        $conv = ChatMessage::find($cid);

        ChatMessage::addMessage($cid, ChatMessage::ROLE_USER, $message);

        $result = ['ok' => true, 'user_message_id' => ChatMessage::latestId($cid)];

        // Synchronous AI reply only in AI modes (retrieval/finetuned).
        // In operator mode the message waits for a human via the Android app
        // or admin panel.
        $convMode = (string) ($conv['ai_mode'] ?? ChatMessage::MODE_RETRIEVAL);

        // Respect the site-wide AI master switch: when it's off, no AI reply
        // regardless of the conversation's AI mode (operator answers instead).
        $aiEnabled = \App\Core\ChatSettings::aiEnabled();

        if ($aiEnabled && ChatMessage::isAiMode($convMode)) {
            $aiReply = ChatAi::reply(
                $convMode,
                $message,
                ChatMessage::messages($cid, 0, false),
                ChatMessage::similarContext($message)
            );

            if ($aiReply['ok']) {
                ChatMessage::addMessage($cid, ChatMessage::ROLE_MODEL, (string) $aiReply['reply']);
                $result['ai_reply'] = $aiReply['reply'];
                $result['ai_reply_id'] = ChatMessage::latestId($cid);
            } else {
                $result['ai_pending'] = true; // model down; operator can respond via the Android app
            }
        } else {
            $result['awaiting_operator'] = true; // AI off or operator-only mode
        }

        $this->json($result);
    }

    /**
     * Poll for new messages (id > since). Returns JSON for the polling JS so
     * model/operator responses appear live.
     */
    public function poll(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            $this->json(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $since  = max(0, (int) $this->request->query('since', 0));
        $conv   = ChatMessage::forUser($userId);
        $cid    = $conv !== null ? (int) $conv['id'] : 0;

        if ($cid <= 0) {
            $this->json(['ok' => true, 'messages' => [], 'latestId' => 0]);
            return;
        }

        $messages = $this->decorateMessages(ChatMessage::messages($cid, $since));

        $this->json([
            'ok'       => true,
            'messages' => $messages,
            'latestId' => ChatMessage::latestId($cid),
            'mode'     => (string) ($conv['ai_mode'] ?? 'retrieval'),
        ]);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Server-Sent Events stream: pushes new messages to the member's chat
     * page in real time (no polling / manual refresh). Keeps the connection
     * open and emits an event whenever a new message id > since arrives.
     *
     * Browser client:
     *   const es = new EventSource('/chat/stream?since=N');
     *   es.onmessage = (e) => { const m = JSON.parse(e.data); ... };
     */
    public function stream(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            header('Content-Type: text/event-stream');
            echo "data: {\"ok\":false,\"error\":\"Forbidden\"}\n\n";
            exit;
        }

        $since = max(0, (int) $this->request->query('since', 0));
        $conv  = ChatMessage::forUser($userId);
        $cid   = $conv !== null ? (int) $conv['id'] : 0;

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Ensure PHP streams rather than buffering the whole response.
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 'off');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        if ($cid <= 0) {
            echo "data: {\"ok\":true,\"messages\":[],\"latestId\":0}\n\n";
            echo "retry: 3000\n\n";
            flush();
            exit;
        }

        $latestId = ChatMessage::latestId($cid);

        // Initial snapshot of anything already newer than the client.
        $new = $this->decorateMessages(ChatMessage::messages($cid, $since));
        if ($new !== []) {
            echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'mode' => (string) ($conv['ai_mode'] ?? 'retrieval')]) . "\n\n";
            flush();
            $since = $latestId;
        }

        // Long-poll loop: hold the connection, emit when a new message lands.
        $start = time();
        while (time() - $start < 30) {
            $new = $this->decorateMessages(ChatMessage::messages($cid, $since));
            if ($new !== []) {
                $latestId = ChatMessage::latestId($cid);
                echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'mode' => (string) ($conv['ai_mode'] ?? 'retrieval')]) . "\n\n";
                flush();
                $since = $latestId;
                continue;
            }

            // Heartbeat so proxies don't kill the connection.
            echo ": keepalive\n\n";
            flush();
            usleep(1500000); // 1.5s
        }

        exit;
    }

    /**
     * Serve a chat attachment to the member (login + ownership check). With
     * ?thumb=1 it streams a generated thumbnail (GD) cached under
     * storage/uploads/chat/thumbs/, otherwise the full-size file.
     */
    public function attachment(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $userId = (int) $user['id'];

        $mid = max(0, (int) $this->request->query('message', 0));
        $thumb = (int) $this->request->query('thumb', 0) === 1;

        $msg = $mid > 0 ? \App\Core\Database::run(
            'SELECT cm.* FROM chat_messages cm
             JOIN chat_conversations c ON c.id = cm.conversation_id
             WHERE cm.id = ? AND c.user_id = ? LIMIT 1',
            [$mid, $userId]
        )->fetch() : null;

        if (!$msg || empty($msg['attachment_path'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Attachment not found.']);
            exit;
        }

        $path = dirname(__DIR__, 2) . '/' . $msg['attachment_path'];
        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Attachment file missing.']);
            exit;
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

    /**
     * Add an attachment_url to each message row that has an attachment, so the
     * web chat can render a download link.
     */
    private function decorateMessages(array $messages): array
    {
        foreach ($messages as &$m) {
            $m['attachment_url'] = !empty($m['attachment_path'])
                ? url('/chat/attachment?message=' . (int) $m['id'])
                : null;
            $m['attachment_thumb_url'] = !empty($m['attachment_path']) && $m['attachment_type'] !== null && str_starts_with((string) $m['attachment_type'], 'image/')
                ? url('/chat/attachment?message=' . (int) $m['id'] . '&thumb=1')
                : null;
        }
        unset($m);

        return $messages;
    }
}