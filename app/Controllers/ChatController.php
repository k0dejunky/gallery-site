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

        $messages = $conv['id'] > 0 ? ChatMessage::messages((int) $conv['id']) : [];

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

        $messages = ChatMessage::messages($cid, $since);

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
}