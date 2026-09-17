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
     * Append a reply from the Android app. sender_role is 'operator' when a
     * human replied live (harvested into the training corpus) or 'model' when
     * the server/AI produced it. Returns the new message id.
     */
    public function reply(): void
    {
        $data = json_decode($this->rawBody(), true);
        $cid  = (int) ($data['conversation_id'] ?? 0);
        $msg  = trim((string) ($data['message'] ?? ''));
        $role = (string) ($data['sender_role'] ?? 'operator');

        if (!in_array($role, [ChatMessage::ROLE_OPERATOR, ChatMessage::ROLE_MODEL], true)) {
            $role = ChatMessage::ROLE_OPERATOR;
        }

        if ($cid <= 0 || ChatMessage::find($cid) === null) {
            $this->json(['ok' => false, 'error' => 'Conversation not found.']);
            return;
        }

        $id = ChatMessage::addMessage($cid, $role, $msg);
        if ($id <= 0) {
            $this->json(['ok' => false, 'error' => 'Message is empty or too long.']);
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

        $this->json(['ok' => true, 'checksum' => $checksum, 'stored' => basename($dest)]);
    }

    private function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}