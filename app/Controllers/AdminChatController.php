<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ChatAi;
use App\Core\Database;
use App\Models\ChatMessage;

/**
 * Admin chat management: list conversations, toggle each conversation between
 * retrieval and fine-tuned AI mode (or site default), reply as the operator,
 * view training-corpus stats, and trigger a training export.
 */
class AdminChatController extends Controller
{
    public function __construct($request)
    {
        parent::__construct($request);
        Auth::requirePermission('chat');
    }

    public function index(): void
    {
        $q      = trim((string) $this->request->query('q', ''));
        $mode   = (string) $this->request->query('mode', '');
        $page   = max(1, (int) $this->request->query('page', 1));
        $per    = 25;
        $offset = ($page - 1) * $per;

        $where  = ['1 = 1'];
        $params = [];

        if ($mode === 'retrieval' || $mode === 'finetuned') {
            $where[]  = 'c.ai_mode = ?';
            $params[] = $mode;
        }
        if ($q !== '') {
            $where[]  = '(u.email LIKE ? OR c.id = ?)';
            $params[] = '%' . $q . '%';
            $params[] = (int) $q;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::run(
            'SELECT COUNT(*) FROM chat_conversations c JOIN users u ON u.id = c.user_id WHERE ' . $whereSql,
            $params
        )->fetchColumn();

        $conversations = Database::run(
            "SELECT c.*, u.email AS user_email,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id) AS message_count,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.sender_role = 'user') AS user_count
             FROM chat_conversations c JOIN users u ON u.id = c.user_id
             WHERE $whereSql
             ORDER BY c.updated_at DESC
             LIMIT $per OFFSET $offset",
            $params
        )->fetchAll();

        $ai = ChatAi::ping();
        $state = $this->state();

        $this->viewAdmin('chat', [
            'title'        => 'Chat Admin',
            'conversations' => $conversations,
            'total'        => $total,
            'page'         => $page,
            'pages'        => max(1, (int) ceil($total / $per)),
            'filterQ'      => $q,
            'filterMode'   => $mode,
            'ai'           => $ai,
            'state'        => $state,
            'finetunedModel' => \App\Core\ChatAi::currentFineTunedModel(),
            'adapterInstalled' => \App\Core\ChatModel::adapterPath() !== null,
            'trainingCount'=> ChatMessage::trainingPairCount(),
            'cleanedCount' => ChatMessage::cleanedPairCount(),
        ]);
    }

    public function show(int $id): void
    {
        $conv = ChatMessage::find($id);
        if ($conv === null) {
            $this->notFound();
            return;
        }

        $user = Database::run('SELECT email FROM users WHERE id = ?', [(int) $conv['user_id']])->fetch();

        $this->viewAdmin('chat_show', [
            'title'      => 'Chat #' . $id,
            'conversation' => $conv,
            'user_email' => $user['email'] ?? 'user#' . $conv['user_id'],
            'messages'   => ChatMessage::messages($id),
        ]);
    }

    /** Toggle a conversation's AI mode. */
    public function mode(int $id): void
    {
        $mode = (string) $this->request->post('ai_mode', 'retrieval');
        if (!ChatMessage::setMode($id, $mode)) {
            $this->flash('error', 'Invalid mode.');
        } else {
            $this->flash('success', 'Conversation mode set to ' . $mode . '.');
        }
        $this->redirect('/admin/chat/' . $id);
    }

    /** Reply as the human operator (harvested into training data). */
    public function operatorReply(int $id): void
    {
        $message = trim((string) $this->request->input('message'));
        if ($message === '' || mb_strlen($message) > ChatMessage::MAX_MESSAGE_LENGTH) {
            $this->flash('error', 'Reply must be 1–' . ChatMessage::MAX_MESSAGE_LENGTH . ' characters.');
            $this->redirect('/admin/chat/' . $id);
            return;
        }

        $newId = ChatMessage::addMessage($id, ChatMessage::ROLE_OPERATOR, $message);

        $userMsg = Database::run(
            "SELECT message FROM chat_messages WHERE conversation_id = ? AND sender_role = 'user' ORDER BY id DESC LIMIT 1",
            [$id]
        )->fetchColumn();
        if ($userMsg !== false && trim((string) $userMsg) !== '') {
            ChatMessage::insertTrainingPair((string) $userMsg, $message);
        }

        $this->flash('success', 'Operator reply sent (message #' . $newId . ').');
        $this->redirect('/admin/chat/' . $id);
    }

    /** Site-wide AI mode default used for new conversations. */
    public function saveSettings(): void
    {
        $defaultMode = (string) $this->request->post('default_ai_mode', 'retrieval');
        if (!in_array($defaultMode, [ChatMessage::MODE_RETRIEVAL, ChatMessage::MODE_FINETUNED], true)) {
            $defaultMode = ChatMessage::MODE_RETRIEVAL;
        }

        $state = $this->state();
        $state['default_ai_mode'] = $defaultMode;
        $state['model']           = ChatAi::BASE_MODEL;
        $state['finetuned_model'] = ChatAi::FINETUNED_MODEL;

        $file = dirname(__DIR__, 2) . '/storage/chat.json';
        @file_put_contents($file, (string) json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        $this->flash('success', 'Chat settings saved.');
        $this->redirect('/admin/chat');
    }

    /** Write the cleaned training export (JSONL) ready for the training PC. */
    public function exportTraining(): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/training';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $out = $dir . '/chat_train.jsonl';

        $rows = Database::run(
            'SELECT user_message, operator_reply FROM chat_training_pairs WHERE cleaned = 0 ORDER BY id ASC LIMIT 20000'
        )->fetchAll();

        $fh = fopen($out, 'w');
        $written = 0;
        foreach ($rows as $row) {
            $u = $this->clean((string) $row['user_message']);
            $r = $this->clean((string) $row['operator_reply']);
            if ($u === '' || $r === '') {
                continue;
            }
            fwrite($fh, json_encode([
                'messages' => [
                    ['role' => 'user', 'content' => $u],
                    ['role' => 'assistant', 'content' => $r],
                ],
            ]) . "\n");
            $written++;
        }
        fclose($fh);

        if ($written > 0) {
            Database::run(
                'UPDATE chat_training_pairs SET cleaned = 1 WHERE id <= ?',
                [(int) Database::run('SELECT MAX(id) FROM chat_training_pairs')->fetchColumn()]
            );
        }

        $this->flash('success', "Cleaned training export written: {$written} pair(s) → storage/training/chat_train.jsonl");
        $this->redirect('/admin/chat');
    }

    private function clean(string $text): string
    {
        $text = trim($text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = (string) preg_replace('/\b\d{3}[-.)]?\d{3}[-.]?\d{4}\b/', '[phone]', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr($text, 0, 2000);
    }

    private function state(): array
    {
        $file = dirname(__DIR__, 2) . '/storage/chat.json';
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($data) ? $data : ['default_ai_mode' => 'retrieval'];
    }
}