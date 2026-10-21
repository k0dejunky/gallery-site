<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ChatAi;
use App\Core\RateLimiter;
use App\Models\ChatMessage;
use App\Models\ChatQuestionnaire;

/**
 * Member-facing chat: the conversation page, sending messages, and the
 * polling endpoint that surfaces AI/operator replies live. Access is gated by
 * ChatMessage::canChat (Platinum-yearly, Lifetime, or the Chat add-on plan).
 */
class ChatController extends Controller
{
    /**
     * Member attachments share the operator app's cap and the model-level
     * type allow-list (ChatMessage::storeAttachment). 25 MB is the agreed
     * product limit for both directions.
     */
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    public function index(): void
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        // An admin on the member side of the chat is NOT the operator: viewing
        // the user-side chat clears operator presence so the site isn't shown
        // as online when only an admin is browsing as a member.
        if (Auth::isAdmin()) {
            \App\Core\ChatSettings::clearOperatorOnline();
        }

        $eligible = ChatMessage::canChat($userId);

        // The chat feature is visible to every logged-in user. Users without
        // the chat plan see the admin's daily message (read-only) and an
        // upgrade prompt; they cannot send messages (the composer is hidden).
        $conv = $eligible ? ChatMessage::forUser($userId) : null;
        if ($conv === null) {
            $conv = ['id' => 0, 'ai_mode' => 'retrieval', 'status' => 'open'];
        }

        $messages = $conv['id'] > 0 ? \App\Models\ChatMessage::decorateMessages(ChatMessage::messagesLatest((int) $conv['id'], 50), '/chat/attachment') : [];
        $hasMore = $conv['id'] > 0 && !empty($messages)
            ? ChatMessage::hasOlder((int) $conv['id'], (int) $messages[0]['id'])
            : false;

        // Opening the chat page counts as reading: clear the sidebar badge.
        if ($conv['id'] > 0) {
            ChatMessage::markRead((int) $conv['id'], ChatMessage::latestId((int) $conv['id']));
        }

        $questionnaires = ChatQuestionnaire::activeForUser($userId);
        $this->view('chat/index', [
            'title'        => 'Chat',
            'questionnaires' => $questionnaires,
            'eligible'     => $eligible,
            'replyEnabled' => $conv['id'] > 0 ? ChatMessage::memberReplyEnabled((int) $conv['id']) : $eligible,
            'dailyMessage' => \App\Core\ChatSettings::dailyMessage(),
            'aiEnabled'    => \App\Core\ChatSettings::aiEnabled(),
            'operatorOnline' => \App\Core\ChatSettings::operatorOnline(),
            'awayMessage'  => \App\Core\ChatSettings::operatorAwayMessage(),
            'conversation' => $conv,
            'messages'     => $messages,
            'hasMore'      => $hasMore,
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
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            $this->json(['ok' => false, 'error' => 'You are not eligible to chat.']);
            return;
        }

        // A blocked member can still read the conversation, but cannot
        // write into it.
        if (ChatMessage::userBlocked($userId)) {
            $this->json(['ok' => false, 'error' => 'You have been blocked from chatting by the operator.']);
            return;
        }

        // Per-user send throttle: 12 messages per 60 seconds. Enforced before
        // the synchronous Ollama generation so spam cannot drive model load.
        if (!RateLimiter::allow(['chat_send:' . $userId], 12, 60)) {
            http_response_code(429);
            $this->json(['ok' => false, 'error' => 'You are sending messages too quickly. Please wait a moment.']);
            return;
        }

        $existing = ChatMessage::forUser($userId);
        if ($existing !== null && !ChatMessage::memberReplyEnabled((int) $existing['id'])) {
            $this->json(['ok' => false, 'error' => 'Replies are disabled for this conversation.']);
            return;
        }

        $message = (string) $this->request->post('message', '');
        $message = trim($message);

        // Optional member attachment. Same 25 MB cap and type allow-list as
        // the operator app (ChatMessage::storeAttachment sniffs the content).
        $attachment = null;
        $file = $this->request->file('attachment');
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $this->json(['ok' => false, 'error' => 'The attachment upload failed. Please try again.']);
                return;
            }
            if ((int) ($file['size'] ?? 0) > self::MAX_ATTACHMENT_BYTES) {
                $this->json(['ok' => false, 'error' => 'Attachments must be 25 MB or smaller.']);
                return;
            }
            $attachment = ChatMessage::storeAttachment($file);
            if ($attachment === null) {
                $this->json(['ok' => false, 'error' => 'That file type is not supported. Images, video, audio, PDF and text files only.']);
                return;
            }
        }

        if (($message === '' && $attachment === null) || mb_strlen($message) > ChatMessage::MAX_MESSAGE_LENGTH) {
            $this->json(['ok' => false, 'error' => 'Attach a file or write a message (max ' . ChatMessage::MAX_MESSAGE_LENGTH . ' characters).']);
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

        ChatMessage::addMessage($cid, ChatMessage::ROLE_USER, $message, $attachment);

        $result = ['ok' => true, 'user_message_id' => ChatMessage::latestId($cid)];

        // AI reply in AI modes (retrieval/finetuned). In operator mode the
        // message waits for a human via the Android app or admin panel.
        $convMode = (string) ($conv['ai_mode'] ?? ChatMessage::MODE_RETRIEVAL);

        // Respect the site-wide AI master switch: when it's off, no AI reply
        // regardless of the conversation's AI mode (operator answers instead).
        $settings = \App\Core\ChatSettings::all();
        $aiEnabled = !empty($settings['ai_enabled']);

        // An attachment-only message has no text to answer — a human (operator
        // mode) or the admin sees the media; never feed an empty prompt to AI.
        if ($message !== '' && $aiEnabled && ChatMessage::isAiMode($convMode)) {
            if (!empty($settings['chat_ai_async'])) {
                // Background mode (slow local models): the worker generates the
                // reply; the member's stream picks it up when it is stored.
                \App\Models\ChatAiJob::enqueue(
                    $cid,
                    $userId,
                    (int) $result['user_message_id'],
                    $message,
                    $convMode,
                    Auth::effectiveLevel()
                );
                $result['ai_pending'] = true;
                $result['ai_queued']  = true;
            } else {
                $gen = \App\Core\ChatAiResponder::generate($cid, $userId, $message, $convMode, Auth::effectiveLevel());
                if (!empty($gen['ok'])) {
                    $result['ai_reply']    = (string) $gen['reply'];
                    $result['ai_reply_id'] = (int) ($gen['id'] ?? ChatMessage::latestId($cid));
                    if (!empty($gen['refs'])) {
                        $result['ai_content_refs'] = $gen['refs'];
                    }
                } else {
                    $result['ai_pending'] = true; // model down; operator can respond via the Android app
                }
            }
        } else {
            // AI off or operator-only mode: operator answers manually.
            $result['awaiting_operator'] = true;

            // When the operator chat is not online and a default/away message
            // is configured, auto-respond with it (once per away period — never
            // repeat the same away message back-to-back).
            $awayMessage = \App\Core\ChatSettings::operatorAwayMessage();
            if ($awayMessage !== '' && !\App\Core\ChatSettings::operatorOnline()) {
                if (ChatMessage::lastMessageText($cid) !== $awayMessage) {
                    ChatMessage::addMessage($cid, ChatMessage::ROLE_MODEL, $awayMessage);
                    $result['ai_reply']     = $awayMessage;
                    $result['ai_reply_id']  = ChatMessage::latestId($cid);
                    $result['away_message'] = true;
                }
            }
        }

        $this->json($result);
    }

    /**
     * Throttled ping from the member's composer while they write. The bridge
     * stream reports it to the operator app ("Member is typing…"). Never
     * creates a conversation and never feeds the AI — pure presence.
     */
    public function typing(): void
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            $this->json(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        // The web composer pings every ~3 s while typing; 20/min leaves room
        // for a few composer tabs without becoming a spam vector.
        if (!RateLimiter::allow(['chat_typing:' . $userId], 20, 60)) {
            $this->json(['ok' => false]);
            return;
        }

        $conv = ChatMessage::forUser($userId);
        if ($conv !== null && ChatMessage::memberReplyEnabled((int) $conv['id'])) {
            ChatMessage::markMemberTyping((int) $conv['id']);
        }

        $this->json(['ok' => true]);
    }

    /**
     * Poll for new messages (id > since). Returns JSON for the polling JS so
     * model/operator responses appear live.
     */
    public function poll(): void
    {
        $user = Auth::requireUser();
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

        $messages = \App\Models\ChatMessage::decorateMessages(ChatMessage::messages($cid, $since), '/chat/attachment');

        $this->json([
            'ok'       => true,
            'messages' => $messages,
            'latestId' => ChatMessage::latestId($cid),
            'mode'     => (string) ($conv['ai_mode'] ?? 'retrieval'),
        ]);
    }

    /**
     * Batch-load older messages for the member's chat (lazy scroll-up).
     * Returns up to 50 messages with id < before, ordered oldest-first.
     *
     *   GET /chat/history?before=N&limit=50
     */
    public function history(): void
    {
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            $this->json(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $before = max(0, (int) $this->request->query('before', 0));
        $limit = max(1, min(200, (int) $this->request->query('limit', 50)));

        $conv = ChatMessage::forUser($userId);
        $cid = $conv !== null ? (int) $conv['id'] : 0;

        if ($cid <= 0 || $before <= 0) {
            $this->json(['ok' => true, 'messages' => [], 'has_more' => false]);
            return;
        }

        $messages = \App\Models\ChatMessage::decorateMessages(ChatMessage::messagesBefore($cid, $before, $limit), '/chat/attachment');
        $older = !empty($messages)
            ? ChatMessage::hasOlder($cid, (int) $messages[0]['id'])
            : false;

        $this->json([
            'ok'       => true,
            'messages' => $messages,
            'has_more' => $older,
        ]);
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
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        if (!ChatMessage::canChat($userId)) {
            header('Content-Type: text/event-stream');
            echo "data: {\"ok\":false,\"error\":\"Forbidden\"}\n\n";
            exit;
        }

        $since = max(0, (int) $this->request->query('since', 0));
        $conv  = ChatMessage::forUser($userId);
        $cid   = $conv !== null ? (int) $conv['id'] : 0;

        start_sse();

        // Release the session lock: this SSE connection lives for up to 30s
        // and would otherwise block the member's send() request (PHP session
        // files are single-writer), delaying messages by the whole window.
        session_write_close();

        if ($cid <= 0) {
            echo "data: {\"ok\":true,\"messages\":[],\"latestId\":0}\n\n";
            echo "retry: 3000\n\n";
            flush();
            exit;
        }

        $latestId = ChatMessage::latestId($cid);

        // Initial snapshot of anything already newer than the client.
        $new = \App\Models\ChatMessage::decorateMessages(ChatMessage::messages($cid, $since), '/chat/attachment');
        if ($new !== []) {
            echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'mode' => (string) ($conv['ai_mode'] ?? 'retrieval')]) . "\n\n";
            flush();
            $since = $latestId;
            ChatMessage::markRead($cid, $latestId);
        }

        // Long-poll loop: hold the connection, emit when a new message lands.
        // A cheap MAX(id) probe runs each tick and the full message fetch only
        // when a newer row actually exists, halving the per-tick query cost
        // and keeping idle chat tabs off the busiest path.
        $start = time();
        while (time() - $start < 30) {
            $headId = ChatMessage::latestId($cid);
            if ($headId > $since) {
                $new = \App\Models\ChatMessage::decorateMessages(ChatMessage::messages($cid, $since), '/chat/attachment');
                if ($new !== []) {
                    $latestId = $headId;
                    echo 'data: ' . json_encode(['ok' => true, 'messages' => $new, 'latestId' => $latestId, 'mode' => (string) ($conv['ai_mode'] ?? 'retrieval')]) . "\n\n";
                    flush();
                    $since = $latestId;
                    ChatMessage::markRead($cid, $latestId);
                    continue;
                }
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
        $user = Auth::requireUser();
        $userId = (int) $user['id'];

        $mid = max(0, (int) $this->request->query('message', 0));
        $thumb = (int) $this->request->query('thumb', 0) === 1;

        // Members may only fetch their own conversation's attachments.
        $msg = $mid > 0 ? \App\Core\Database::run(
            'SELECT cm.* FROM chat_messages cm
             JOIN chat_conversations c ON c.id = cm.conversation_id
             WHERE cm.id = ? AND c.user_id = ? LIMIT 1',
            [$mid, $userId]
        )->fetch() : null;

        if (!$msg) {
            $this->json(['ok' => false, 'error' => 'Attachment not found.'], 404);
        }

        \App\Models\ChatMessage::serveAttachment($msg, $thumb, true);
    }
}