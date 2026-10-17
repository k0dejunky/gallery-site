<?php

namespace App\Models;

use App\Core\ChatAiResponder;
use App\Core\Database;

/**
 * Queued "answer this member message with the AI" jobs, drained by
 * bin/chat_ai_worker.php while chat_ai_async is enabled. One row per member
 * message (UNIQUE user_message_id), claimed transactionally so overlapping
 * worker ticks can never answer the same message twice.
 */
class ChatAiJob
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Queue one member message for a background answer. Idempotent: a retried
     * POST (same user_message_id) keeps the existing row. Returns the job id,
     * or 0 when the message row does not exist.
     */
    public static function enqueue(int $conversationId, int $userId, int $userMessageId, string $message, string $mode, ?int $level = null): int
    {
        if ($userMessageId <= 0) {
            return 0;
        }

        Database::run(
            'INSERT INTO chat_ai_jobs (conversation_id, user_id, user_message_id, message, ai_mode, user_level, status)
             VALUES (?, ?, ?, ?, ?, ?, \'queued\')
             ON DUPLICATE KEY UPDATE user_message_id = user_message_id',
            [$conversationId, $userId, $userMessageId, mb_substr($message, 0, 4000), $mode, $level]
        );

        return (int) Database::run(
            'SELECT id FROM chat_ai_jobs WHERE user_message_id = ?',
            [$userMessageId]
        )->fetchColumn();
    }

    /** Claim the oldest queued job transactionally (mirrors CategorySuggestion). */
    public static function claimNext(): ?int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $job = Database::run(
                'SELECT id FROM chat_ai_jobs WHERE status = ? ORDER BY updated_at ASC LIMIT 1 FOR UPDATE',
                ['queued']
            )->fetch();
            if ($job === false) {
                $db->commit();
                return null;
            }
            $id = (int) $job['id'];
            Database::run(
                "UPDATE chat_ai_jobs SET status = 'running', attempts = attempts + 1, error = NULL WHERE id = ?",
                [$id]
            );
            $db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Run one claimed job: generate the reply through the shared responder and
     * store the outcome. Never throws — a failing generation marks the row.
     */
    public static function process(int $id): bool
    {
        $job = self::find($id);
        if ($job === null) {
            return false;
        }

        try {
            $result = ChatAiResponder::generate(
                (int) $job['conversation_id'],
                (int) $job['user_id'],
                (string) $job['message'],
                (string) $job['ai_mode'],
                $job['user_level'] !== null ? (int) $job['user_level'] : null
            );
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'error' => $error->getMessage()];
        }

        if (!empty($result['ok'])) {
            self::complete($id);
            return true;
        }

        self::fail($id, (string) ($result['error'] ?? 'AI unavailable'));
        return false;
    }

    public static function find(int $id): ?array
    {
        return Database::run('SELECT * FROM chat_ai_jobs WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function complete(int $id): void
    {
        Database::run(
            "UPDATE chat_ai_jobs SET status = 'done', error = NULL WHERE id = ?",
            [$id]
        );
    }

    public static function fail(int $id, string $error): void
    {
        Database::run(
            "UPDATE chat_ai_jobs SET status = 'error', error = ? WHERE id = ?",
            [mb_substr($error, 0, 480), $id]
        );
    }

    /** Count of jobs still waiting for a background answer (for tests/UI). */
    public static function queuedCount(): int
    {
        return (int) Database::run(
            "SELECT COUNT(*) FROM chat_ai_jobs WHERE status IN ('queued', 'running')"
        )->fetchColumn();
    }

    /**
     * Return jobs stuck 'running' (worker died mid-run) and retryable errors
     * to the queue; attempts (bumped at claim time) caps the retries.
     */
    public static function recoverStale(): void
    {
        Database::run(
            "UPDATE chat_ai_jobs SET status = 'queued'
             WHERE status = 'running' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE) AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
        Database::run(
            "UPDATE chat_ai_jobs SET status = 'queued', error = NULL
             WHERE status = 'error' AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
    }
}
