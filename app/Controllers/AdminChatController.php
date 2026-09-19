<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ChatAi;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\ChatBroadcast;
use App\Models\ChatMessage;
use DateTime;
use DateTimeZone;

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

        if ($mode === 'retrieval' || $mode === 'finetuned' || $mode === 'operator') {
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
        $state = \App\Core\ChatSettings::all();

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
            'broadcasts'   => ChatBroadcast::log(50),
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

        $messages = ChatMessage::messagesLatest($id, 50);
        $hasMore = !empty($messages)
            ? ChatMessage::hasOlder($id, (int) $messages[0]['id'])
            : false;
        foreach ($messages as &$m) {
            $type = (string) ($m['attachment_type'] ?? '');
            // Self-heal: phone uploads sometimes arrive as octet-stream even
            // for images; sniff the real type so thumbnails render.
            if (!str_starts_with($type, 'image/') && !empty($m['attachment_path'])) {
                $full = dirname(__DIR__, 2) . '/' . ltrim((string) $m['attachment_path'], '/');
                if (is_file($full)) {
                    if (function_exists('finfo_open')) {
                        $fi = finfo_open(FILEINFO_MIME_TYPE);
                        $t = $fi !== false ? finfo_file($fi, $full) : false;
                        if (is_resource($fi)) {
                            finfo_close($fi);
                        }
                        if (is_string($t) && str_starts_with($t, 'image/')) {
                            $type = $t;
                        }
                    }
                }
            }
            $m['attachment_url'] = !empty($m['attachment_path'])
                ? url('/admin/chat/attachment?message=' . (int) $m['id'])
                : null;
            $m['attachment_thumb_url'] = !empty($m['attachment_path']) && str_starts_with($type, 'image/')
                ? url('/admin/chat/attachment?message=' . (int) $m['id'] . '&thumb=1')
                : null;
        }
        unset($m);

        $this->viewAdmin('chat_show', [
            'title'      => 'Chat #' . $id,
            'conversation' => $conv,
            'user_email' => $user['email'] ?? 'user#' . $conv['user_id'],
            'messages'   => $messages,
            'hasMore'    => $hasMore,
        ]);
    }

    /**
     * Batch-load older messages for the admin chat (lazy scroll-up).
     *
     *   GET /admin/chat/history?conversation=ID&before=N&limit=50
     */
    public function history(): void
    {
        $cid = max(0, (int) $this->request->query('conversation', 0));
        $before = max(0, (int) $this->request->query('before', 0));
        $limit = max(1, min(200, (int) $this->request->query('limit', 50)));

        $conv = ChatMessage::find($cid);
        if ($conv === null) {
            $this->json(['ok' => false, 'error' => 'Conversation not found.']);
            return;
        }

        $messages = ChatMessage::messagesBefore($cid, $before, $limit);
        $hasMore = !empty($messages)
            ? ChatMessage::hasOlder($cid, (int) $messages[0]['id'])
            : false;

        foreach ($messages as &$m) {
            $m['attachment_url'] = !empty($m['attachment_path'])
                ? url('/admin/chat/attachment?message=' . (int) $m['id'])
                : null;
            $m['attachment_thumb_url'] = !empty($m['attachment_path']) && !empty($m['attachment_type']) && str_starts_with((string) $m['attachment_type'], 'image/')
                ? url('/admin/chat/attachment?message=' . (int) $m['id'] . '&thumb=1')
                : null;
        }
        unset($m);

        $this->json([
            'ok'       => true,
            'conversation' => $cid,
            'messages' => $messages,
            'has_more' => $hasMore,
        ]);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
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

        // Optional attachment (image / video / text / etc.).
        $attachment = null;
        $file = $this->request->file('attachment');
        if ($file !== null && !empty($file['tmp_name']) && is_file($file['tmp_name'])) {
            $attachment = ChatMessage::storeAttachment($file);
            if ($attachment === null) {
                $this->flash('error', 'Attachment could not be stored.');
                $this->redirect('/admin/chat/' . $id);
                return;
            }
        }

        if ($message === '' && $attachment === null) {
            $this->flash('error', 'Reply must be 1–' . ChatMessage::MAX_MESSAGE_LENGTH . ' characters.');
            $this->redirect('/admin/chat/' . $id);
            return;
        }
        if ($message !== '' && mb_strlen($message) > ChatMessage::MAX_MESSAGE_LENGTH) {
            $this->flash('error', 'Reply must be 1–' . ChatMessage::MAX_MESSAGE_LENGTH . ' characters.');
            $this->redirect('/admin/chat/' . $id);
            return;
        }

        $newId = ChatMessage::addMessage($id, ChatMessage::ROLE_OPERATOR, $message, $attachment);

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
        if (!in_array($defaultMode, [ChatMessage::MODE_RETRIEVAL, ChatMessage::MODE_FINETUNED, ChatMessage::MODE_OPERATOR], true)) {
            $defaultMode = ChatMessage::MODE_RETRIEVAL;
        }

        $state = \App\Core\ChatSettings::all();
        $state['default_ai_mode'] = $defaultMode;
        $state['model']           = ChatAi::BASE_MODEL;
        $state['finetuned_model'] = ChatAi::FINETUNED_MODEL;

        \App\Core\ChatSettings::save($state);

        $this->flash('success', 'Chat settings saved.');
        $this->redirect('/admin/chat');
    }

    /** Toggle the master AI switch on/off (off = operator-only across the site). */
    public function toggleAi(): void
    {
        $state = \App\Core\ChatSettings::all();
        $state['ai_enabled'] = !(bool) ($state['ai_enabled'] ?? true);

        \App\Core\ChatSettings::save($state);

        $this->flash('success', 'Chat AI turned ' . ($state['ai_enabled'] ? 'on' : 'off') . '.');
        $this->redirect('/admin/chat');
    }

    /** Save the admin's daily broadcast shown to users without the chat feature. */
    public function saveDailyMessage(): void
    {
        $message = trim((string) $this->request->post('daily_message', ''));
        if (mb_strlen($message) > 5000) {
            $this->flash('error', 'Daily message must be 5,000 characters or fewer.');
            $this->redirect('/admin/chat');
            return;
        }

        $state = \App\Core\ChatSettings::all();
        $state['daily_message']    = $message;
        $state['daily_message_at'] = date('Y-m-d H:i:s');

        \App\Core\ChatSettings::save($state);

        $this->flash('success', 'Daily message ' . ($message === '' ? 'cleared' : 'saved') . '.');
        $this->redirect('/admin/chat');
    }

    /** Create a daily chat broadcast: schedule it or send it now. */
    public function createDailyBroadcast(): void
    {
        $message = trim((string) $this->request->input('message'));
        if ($message === '' || mb_strlen($message) > ChatBroadcast::MAX_MESSAGE_LENGTH) {
            $this->flash('error', 'Daily chat message must be 1–' . ChatBroadcast::MAX_MESSAGE_LENGTH . ' characters.');
            $this->redirect('/admin/chat');
            return;
        }

        $action = (string) $this->request->post('action', 'now');
        $scheduledAt = null;
        $raw = trim((string) $this->request->post('scheduled_at', ''));

        if ($action === 'schedule') {
            if ($raw === '') {
                $this->flash('error', 'Pick a schedule time, or use "Send now".');
                $this->redirect('/admin/chat');
                return;
            }
            $scheduledAt = self::normalizeSchedule($raw);
            if ($scheduledAt === null || $scheduledAt <= gmdate('Y-m-d H:i:s')) {
                $this->flash('error', 'The schedule must be a valid time in the future.');
                $this->redirect('/admin/chat');
                return;
            }
        }

        $adminId = (int) Auth::user()['id'];
        $id = ChatBroadcast::create($message, $scheduledAt, $adminId);

        if ($scheduledAt === null) {
            $result = ChatBroadcast::send($id);
            if ($result['ok']) {
                AuditLog::record($adminId, 'create', 'chat_daily_broadcast', $id,
                    'Sent daily chat broadcast #' . $id . ' now to ' . ($result['sent'] ?? 0) . ' recipient(s)');
                $this->flash('success', 'Daily chat sent now: ' . ($result['sent'] ?? 0) . ' of ' . ($result['recipients'] ?? 0) . ' recipient(s).');
            } else {
                $this->flash('error', 'Daily chat could not be sent: ' . ($result['error'] ?? 'unknown'));
            }
        } else {
            AuditLog::record($adminId, 'create', 'chat_daily_broadcast', $id,
                'Scheduled daily chat broadcast #' . $id . ' for ' . $scheduledAt . ' UTC');
            $this->flash('success', 'Daily chat scheduled for ' . tzdate('M j, Y H:i', $scheduledAt) . '.');
        }

        $this->redirect('/admin/chat');
    }

    /** Send a scheduled daily chat immediately. */
    public function runDailyBroadcast(int $id): void
    {
        $b = ChatBroadcast::find($id);
        if ($b === null) {
            $this->flash('error', 'That daily chat broadcast does not exist.');
            $this->redirect('/admin/chat');
            return;
        }

        if (in_array($b['status'], [ChatBroadcast::STATUS_SENT, ChatBroadcast::STATUS_SENDING], true)) {
            $this->flash('error', 'That broadcast was already sent (or is sending).');
            $this->redirect('/admin/chat');
            return;
        }

        Database::run('UPDATE chat_daily_broadcasts SET scheduled_at = NULL WHERE id = ?', [$id]);

        $result = ChatBroadcast::send($id);
        if ($result['ok']) {
            AuditLog::record((int) Auth::user()['id'], 'update', 'chat_daily_broadcast', $id,
                'Sent scheduled daily chat broadcast #' . $id . ' manually to ' . ($result['sent'] ?? 0) . ' recipient(s)');
            $this->flash('success', 'Daily chat sent: ' . ($result['sent'] ?? 0) . ' of ' . ($result['recipients'] ?? 0) . ' recipient(s).');
        } else {
            $this->flash('error', 'Daily chat could not be sent: ' . ($result['error'] ?? 'unknown'));
        }

        $this->redirect('/admin/chat');
    }

    /** Cancel a scheduled daily chat that has not been delivered yet. */
    public function cancelDailyBroadcast(int $id): void
    {
        if (ChatBroadcast::cancel($id)) {
            AuditLog::record((int) Auth::user()['id'], 'delete', 'chat_daily_broadcast', $id,
                'Cancelled scheduled daily chat broadcast #' . $id);
            $this->flash('success', 'Scheduled daily chat cancelled.');
        } else {
            $this->flash('error', 'That broadcast is not pending (already sent or cancelled).');
        }

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

    /** Normalize a datetime-local schedule (site timezone) to UTC, or null. */
    private static function normalizeSchedule(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        $v = str_replace('T', ' ', $v);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
            return null;
        }

        $parsed = $m[1] . (isset($m[2]) ? $m[2] : ':00');
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $parsed, new DateTimeZone(site_timezone()));
        if ($dt === false) {
            return null;
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Serve a chat attachment to the admin (session-authenticated via the
     * 'chat' route permission). Supports ?thumb=1 for image previews.
     */
    public function attachment(): void
    {
        $mid = max(0, (int) $this->request->query('message', 0));
        $thumb = (int) $this->request->query('thumb', 0) === 1;

        $msg = $mid > 0
            ? Database::run('SELECT * FROM chat_messages WHERE id = ? LIMIT 1', [$mid])->fetch()
            : null;

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

        // Phone uploads may be stored as octet-stream even for images: sniff
        // the real type from the file content so thumbnails still work.
        if (!str_starts_with($mime, 'image/')) {
            $full = dirname(__DIR__, 2) . '/' . ltrim((string) $msg['attachment_path'], '/');
            if (is_file($full)) {
                $real = function_exists('finfo_open') && ($fi = finfo_open(FILEINFO_MIME_TYPE)) !== false
                    ? finfo_file($fi, $full)
                    : (mime_content_type($full) ?: '');
                if (is_resource($fi)) {
                    finfo_close($fi);
                }
                if (is_string($real) && str_starts_with($real, 'image/')) {
                    $mime = $real;
                }
            }
        }

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

    /** Cached 320px JPEG thumbnail for image attachments. */
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
        $nw = min($maxW, $w);
        $nh = max(1, (int) round($h * ($nw / $w)));

        $thumb = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        imagejpeg($thumb, $out, 82);
        imagedestroy($thumb);

        return $out;
    }

    }