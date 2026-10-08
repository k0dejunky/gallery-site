<?php

namespace App\Models;

use App\Core\Database;

class ChatQuestionnaire
{
    public const STATUS_DRAFT      = 'draft';
    public const STATUS_SCHEDULED  = 'scheduled';
    public const STATUS_SENDING    = 'sending';
    public const STATUS_SENT       = 'sent';
    public const STATUS_PARTIAL    = 'partial';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const QTYPE_TEXT       = 'text';
    public const QTYPE_CHOICE     = 'choice';
    public const QTYPE_MULTICHOICE= 'multichoice';
    public const QTYPE_RATING     = 'rating';
    public const QTYPE_NUMBER     = 'number';

    public static function create(int $createdBy, string $title, ?string $intro, bool $allowReplies, ?string $scheduledAt, array $questions): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }
        $intro = $intro === null ? null : trim($intro);
        if ($intro === '') {
            $intro = null;
        }
        $scheduledAt = $scheduledAt === null ? null : trim($scheduledAt);
        if ($scheduledAt !== null && $scheduledAt !== '' && $scheduledAt < gmdate('Y-m-d H:i:s')) {
            throw new \InvalidArgumentException('Scheduled time must be in the future.');
        }

        $conn = Database::connection();
        Database::run('START TRANSACTION');

        try {
            Database::run(
                'INSERT INTO chat_questionnaires (title, intro, status, allow_replies, scheduled_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$title, $intro, $scheduledAt === null ? self::STATUS_DRAFT : self::STATUS_SCHEDULED, $allowReplies ? 1 : 0, $scheduledAt, $createdBy]
            );
            $id = (int) $conn->lastInsertId();

            $pos = 0;
            foreach ($questions as $q) {
                $pos++;
                $prompt = trim((string) ($q['prompt'] ?? ''));
                if ($prompt === '') {
                    throw new \InvalidArgumentException('Question prompt is required.');
                }
                $qtype = (string) ($q['qtype'] ?? self::QTYPE_TEXT);
                if (!in_array($qtype, [self::QTYPE_TEXT, self::QTYPE_CHOICE, self::QTYPE_MULTICHOICE, self::QTYPE_RATING, self::QTYPE_NUMBER], true)) {
                    $qtype = self::QTYPE_TEXT;
                }
                $required = !empty($q['required']) ? 1 : 0;
                $options = null;
                if ($qtype === self::QTYPE_CHOICE || $qtype === self::QTYPE_MULTICHOICE) {
                    $opts = array_filter(array_map(static fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])));
                    if ($opts === []) {
                        throw new \InvalidArgumentException('Choice questions require options.');
                    }
                    $options = json_encode(array_values($opts), JSON_UNESCAPED_SLASHES);
                } elseif ($qtype === self::QTYPE_RATING) {
                    $min = max(1, (int) ($q['min'] ?? 1));
                    $max = max($min, (int) ($q['max'] ?? 5));
                    $options = json_encode(['min' => $min, 'max' => $max], JSON_UNESCAPED_SLASHES);
                }
                Database::run(
                    'INSERT INTO chat_questionnaire_questions (questionnaire_id, position, prompt, qtype, options, required)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $pos, $prompt, $qtype, $options, $required]
                );
            }

            Database::run('COMMIT');
            return $id;
        } catch (\Throwable $e) {
            Database::run('ROLLBACK');
            throw $e;
        }
    }

    public static function find(int $id): ?array
    {
        $row = Database::run('SELECT * FROM chat_questionnaires WHERE id = ? LIMIT 1', [$id])->fetch();
        return $row ?: null;
    }

    public static function questions(int $id): array
    {
        $rows = Database::run('SELECT * FROM chat_questionnaire_questions WHERE questionnaire_id = ? ORDER BY position ASC, id ASC', [$id])->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['options'])) {
                $r['options_decoded'] = json_decode((string) $r['options'], true);
            } else {
                $r['options_decoded'] = null;
            }
        }
        unset($r);
        return $rows;
    }

    public static function due(): array
    {
        return Database::run(
            "SELECT * FROM chat_questionnaires
             WHERE status = 'scheduled' AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)
             ORDER BY id ASC"
        )->fetchAll();
    }

    public static function recent(int $limit = 20): array
    {
        $limit = max(1, (int) $limit);
        return Database::run(
            'SELECT * FROM chat_questionnaires ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();
    }

    public static function cancel(int $id): bool
    {
        $stmt = Database::run(
            "UPDATE chat_questionnaires SET status = 'cancelled' WHERE id = ? AND status IN ('draft','scheduled','sending')",
            [$id]
        );
        return $stmt->rowCount() > 0;
    }

    public static function markSending(int $id): bool
    {
        $stmt = Database::run(
            "UPDATE chat_questionnaires SET status = 'sending' WHERE id = ? AND status IN ('scheduled')",
            [$id]
        );
        return $stmt->rowCount() > 0;
    }

    public static function finishSend(int $id, int $recipients, int $notified, bool $partial): void
    {
        $status = $recipients === 0 ? self::STATUS_FAILED : ($notified === $recipients && !$partial ? self::STATUS_SENT : ($notified > 0 ? self::STATUS_PARTIAL : self::STATUS_FAILED));
        Database::run(
            "UPDATE chat_questionnaires SET status = ?, recipients = ?, notified_count = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$status, $recipients, $notified, $id]
        );
    }

    public static function send(int $id): array
    {
        $q = self::find($id);
        if ($q === null) {
            return ['ok' => false, 'error' => 'Questionnaire not found.'];
        }
        if ($q['status'] === 'sent' || $q['status'] === 'sending') {
            return ['ok' => false, 'error' => 'Questionnaire already sent.'];
        }
        if (!self::markSending($id)) {
            $q2 = self::find($id);
            if ($q2 && $q2['status'] === 'sending') {
                return ['ok' => false, 'error' => 'Claimed by another run.'];
            }
            if ($q2 && $q2['status'] === 'sent') {
                return ['ok' => false, 'error' => 'Questionnaire already sent.'];
            }
        }
        $qf = self::find($id);
        if ($qf === null) {
            return ['ok' => false, 'error' => 'Questionnaire not found.'];
        }
        $title = (string) $qf['title'];
        $intro = (string) ($qf['intro'] ?? '');
        $body = $intro !== '' ? $intro : 'Please complete the questionnaire on the Chat page.';
        $url = url('/chat#questionnaire-' . $id);
        $notified = Notification::broadcastToMembers('questionnaire', $title, $body, $url);
        $recipients = $notified;
        $partial = false;
        self::finishSend($id, $recipients, $notified, $partial);
        $qf2 = self::find($id);
        return ['ok' => true, 'status' => $qf2['status'] ?? 'sent', 'recipients' => $recipients, 'notified' => $notified];
    }

    public static function activeForUser(int $userId): array
    {
        // Return all sent/questionnaires that allow replies and haven't been fully answered by this user.
        $rows = Database::run(
            "SELECT q.* FROM chat_questionnaires q
             WHERE q.status IN ('sent','partial') AND q.allow_replies = 1
             ORDER BY q.sent_at DESC, q.id DESC"
        )->fetchAll();
        $out = [];
        foreach ($rows as $q) {
            $qid = (int) $q['id'];
            $answered = (int) Database::run(
                'SELECT COUNT(DISTINCT qq.id)
                 FROM chat_questionnaire_questions qq
                 LEFT JOIN chat_questionnaire_answers aa ON aa.question_id = qq.id AND aa.user_id = ?
                 WHERE qq.questionnaire_id = ? AND qq.required = 1 AND aa.id IS NOT NULL',
                [$userId, $qid]
            )->fetchColumn();
            $req = (int) Database::run('SELECT COUNT(*) FROM chat_questionnaire_questions WHERE questionnaire_id = ? AND required = 1', [$qid])->fetchColumn();
            if ($req > 0 && $answered >= $req) {
                continue;
            }
            $q['questions'] = self::questions($qid);
            $out[] = $q;
        }
        return $out;
    }

    public static function hasAnswered(int $questionnaireId, int $userId): bool
    {
        $req = (int) Database::run('SELECT COUNT(*) FROM chat_questionnaire_questions WHERE questionnaire_id = ? AND required = 1', [$questionnaireId])->fetchColumn();
        if ($req === 0) {
            $ans = (int) Database::run('SELECT COUNT(DISTINCT qq.id) FROM chat_questionnaire_questions qq JOIN chat_questionnaire_answers aa ON aa.question_id = qq.id WHERE qq.questionnaire_id = ? AND aa.user_id = ?', [$questionnaireId, $userId])->fetchColumn();
            return $ans > 0;
        }
        $ans = (int) Database::run(
            'SELECT COUNT(DISTINCT qq.id)
             FROM chat_questionnaire_questions qq
             JOIN chat_questionnaire_answers aa ON aa.question_id = qq.id
             WHERE qq.questionnaire_id = ? AND aa.user_id = ? AND qq.required = 1',
            [$questionnaireId, $userId]
        )->fetchColumn();
        return $ans >= $req;
    }

    public static function answer(int $questionnaireId, int $userId, array $answers): array
    {
        $q = self::find($questionnaireId);
        if ($q === null) {
            return ['ok' => false, 'error' => 'Questionnaire not found.'];
        }
        if (!in_array($q['status'], ['sent', 'partial'], true)) {
            return ['ok' => false, 'error' => 'Questionnaire is not open.'];
        }
        if ((int) $q['allow_replies'] !== 1) {
            return ['ok' => false, 'error' => 'Replies are disabled for this questionnaire.'];
        }
        $questions = self::questions($questionnaireId);
        $updates = 0;
        $errors = [];
        $conn = Database::connection();
        Database::run('START TRANSACTION');
        try {
            foreach ($questions as $qq) {
                $qqid = (int) $qq['id'];
                $key = 'q' . $qqid;
                $raw = $answers[$key] ?? null;
                $qtype = (string) $qq['qtype'];
                $required = (int) $qq['required'] === 1;
                if ($raw === null || (is_string($raw) && trim($raw) === '') || (is_array($raw) && count($raw) === 0)) {
                    if ($required) {
                        $errors[] = 'Question ' . $qq['position'] . ' is required.';
                        continue;
                    } else {
                        continue;
                    }
                }
                $store = null;
                if ($qtype === 'multichoice') {
                    if (!is_array($raw)) {
                        $raw = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $raw)));
                    }
                    $raw = array_values(array_filter(array_map('trim', (array) $raw)));
                    if ($raw === []) {
                        if ($required) {
                            $errors[] = 'Question ' . $qq['position'] . ' is required.';
                            continue;
                        } else {
                            continue;
                        }
                    }
                    $opts = $qq['options_decoded'] ?? [];
                    foreach ($raw as $r) {
                        if (!in_array($r, $opts, true)) {
                            $errors[] = 'Invalid option for question ' . $qq['position'] . '.';
                            continue 2;
                        }
                    }
                    $store = json_encode($raw, JSON_UNESCAPED_SLASHES);
                } elseif ($qtype === 'choice') {
                    $val = is_string($raw) ? trim($raw) : (is_array($raw) ? trim((string) ($raw[0] ?? '')) : trim((string) $raw));
                    $opts = $qq['options_decoded'] ?? [];
                    if (!in_array($val, $opts, true)) {
                        $errors[] = 'Invalid option for question ' . $qq['position'] . '.';
                        continue;
                    }
                    $store = $val;
                } elseif ($qtype === 'rating') {
                    $val = (int) (is_array($raw) ? ($raw[0] ?? $raw) : $raw);
                    $opts = $qq['options_decoded'] ?? ['min' => 1, 'max' => 5];
                    $min = (int) ($opts['min'] ?? 1);
                    $max = (int) ($opts['max'] ?? 5);
                    if ($val < $min || $val > $max) {
                        $errors[] = 'Rating out of range for question ' . $qq['position'] . '.';
                        continue;
                    }
                    $store = (string) $val;
                } elseif ($qtype === 'number') {
                    if (!is_numeric($raw)) {
                        $errors[] = 'Number required for question ' . $qq['position'] . '.';
                        continue;
                    }
                    $store = (string) $raw;
                } else { // text
                    $store = is_array($raw) ? trim(implode("\n", array_map('trim', $raw))) : trim((string) $raw);
                    if ($store === '' && $required) {
                        $errors[] = 'Question ' . $qq['position'] . ' is required.';
                        continue;
                    }
                }
                if ($store === null && !$required) {
                    continue;
                }
                Database::run(
                    'INSERT INTO chat_questionnaire_answers (questionnaire_id, question_id, user_id, answer)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = CURRENT_TIMESTAMP',
                    [$questionnaireId, $qqid, $userId, $store]
                );
                $updates++;
            }
            if ($errors !== []) {
                Database::run('ROLLBACK');
                return ['ok' => false, 'error' => implode(' ', $errors)];
            }
            Database::run('COMMIT');
            return ['ok' => true, 'updates' => $updates];
        } catch (\Throwable $e) {
            Database::run('ROLLBACK');
            return ['ok' => false, 'error' => 'Save failed.'];
        }
    }

    public static function results(int $id): array
    {
        $questions = self::questions($id);
        $out = [];
        foreach ($questions as $qq) {
            $qqid = (int) $qq['id'];
            $qtype = (string) $qq['qtype'];
            $rows = Database::run('SELECT aa.user_id, u.email, aa.answer, aa.created_at FROM chat_questionnaire_answers aa JOIN users u ON u.id = aa.user_id WHERE aa.question_id = ? ORDER BY aa.created_at DESC', [$qqid])->fetchAll();
            $res = ['question' => $qq, 'rows' => $rows];
            if ($qtype === 'choice' || $qtype === 'rating' || $qtype === 'number') {
                $tally = [];
                foreach ($rows as $r) {
                    $k = (string) $r['answer'];
                    $tally[$k] = ($tally[$k] ?? 0) + 1;
                }
                ksort($tally);
                $res['tally'] = $tally;
            } elseif ($qtype === 'multichoice') {
                $tally = [];
                foreach ($rows as $r) {
                    $vals = json_decode((string) $r['answer'], true) ?? [];
                    foreach ($vals as $k) {
                        $k = (string) $k;
                        $tally[$k] = ($tally[$k] ?? 0) + 1;
                    }
                }
                ksort($tally);
                $res['tally'] = $tally;
            }
            $out[] = $res;
        }
        return $out;
    }
}
