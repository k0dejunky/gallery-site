<?php

namespace App\Models;

use App\Core\Database;

/**
 * Queue and dispatch engine for the auto-emailer.
 *
 * There is no digest any more. The periodic newsletter - a sample of the newest
 * uploads mailed to every subscriber and free member on a schedule - was
 * removed, so the only mail this site sends is:
 *
 *   chat / support   a new chat reply or support answer (per member, always)
 *   gallery          ONE email for the day a gallery is posted (broadcast)
 *   live             the live-start broadcast (per member, always)
 *   lifecycle        the 7-day renewal reminder, a win-back, and billing
 *                    dunning (payment failed / past due / expired)
 *
 * Each row in email_queue carries the audience-specific subject/body snapshot,
 * so an email can never change after it was enqueued. A `kind` column labels
 * which of the above it is; only the two broadcasts (gallery, live) are subject
 * to the per-member daily cap, so a billing reminder or a chat answer can never
 * be dropped for want of an allowance.
 *
 * The cron-driven worker hands "queued" rows to Mailer::sendHtml() and marks
 * them sent/failed.
 *
 * Unsubscribing: the body contains a per-recipient placeholder that the worker
 * swaps for a per-user signed /unsubscribe link (HMAC over GALLERY_MEDIA_KEY).
 * Following it sets the user's marketing_opt_out flag, which filters them out
 * of every broadcast. Billing mail bypasses it - it is account-critical.
 */
class EmailQueue
{
    /** Body placeholder replaced with the recipient's {uid,t} unsubscribe URL. */
    public const UNSUB_PLACEHOLDER = '{{unsubscribe-url}}';

    /** How many failed delivery attempts before a row is given up on. */
    public const MAX_ATTEMPTS = 3;

    /** Roles that never receive broadcast mail (admins always count as members). */
    private const EXCLUDED_ROLES = ['super_admin', 'admin', 'editor', 'moderator', 'viewer'];

    /**
     * The queue `kind` a member preference flag maps to. The flags name the
     * event; the kinds name the daily-cap bucket.
     */
    public static function kindForFlag(string $flagColumn): string
    {
        switch ($flagColumn) {
            case 'notify_new_gallery':
                return 'gallery';
            case 'notify_live':
                return 'live';
            case 'notify_chat_reply':
                return 'chat';
            default:
                return 'notification';
        }
    }

    /**
     * Members who enabled a notification type (notify_new_gallery /
     * notify_live): active, non-admin accounts that have not used the global
     * marketing opt-out. Both paid and free members receive transactional
     * notifications (a new gallery / live start is relevant to everyone).
     *
     * @return array<int, array{id: int, email: string}>
     */
    public static function notificationRecipients(string $flagColumn): array
    {
        if (!in_array($flagColumn, ['notify_new_gallery', 'notify_live'], true)) {
            return [];
        }

        $excluded = implode(', ', array_map(static fn (string $r): string => "'" . $r . "'", self::EXCLUDED_ROLES));

        $rows = Database::run(
            "SELECT u.id, u.email
             FROM users u
             WHERE u.status = 'active'
               AND u.role NOT IN ($excluded)
               AND COALESCE(u.marketing_opt_out, 0) = 0
               AND COALESCE(u.{$flagColumn}, 1) = 1"
        )->fetchAll();

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'email' => (string) $row['email']],
            $rows
        );
    }

    /**
     * User ids that have already had their per-day allowance for this kind used
     * up today. One grouped query for the whole fan-out, so publishing a gallery
     * still costs a single read no matter how many members there are.
     *
     * Scoped to the capped broadcast kinds only: counting every "notification"
     * row let a billing reminder or a chat answer consume a member's one
     * allowance and silently drop the day's gallery email.
     *
     * The day boundary is MySQL's own CURDATE(), i.e. exactly the day the queue
     * table itself shows - the admin reading "sent today" sees the same count
     * the cap is enforcing.
     *
     * @return array<int, true> keyed by user id
     */
    private static function usersAtNotificationCap(int $cap, string $kind): array
    {
        if ($cap <= 0 || !in_array($kind, EmailerConfig::CAPPED_KINDS, true)) {
            return []; // 0 = unlimited, and transactional kinds are never capped
        }

        $rows = Database::run(
            "SELECT user_id
             FROM email_queue
             WHERE kind = ?
               AND user_id IS NOT NULL
               AND DATE(created_at) = CURDATE()
             GROUP BY user_id
             HAVING COUNT(*) >= ?",
            [$kind, $cap]
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']] = true;
        }

        return $out;
    }

    /**
     * Whether any broadcast of this kind has already been queued today.
     *
     * The caller (gallery publish / live start) uses this BEFORE claiming its
     * once-only marker. Claiming first and being dropped by the cap afterwards
     * consumed the marker, so the item was never emailed at all - a second
     * gallery published the same day was silently swallowed forever.
     */
    public static function broadcastSentToday(string $kind): bool
    {
        if (!in_array($kind, EmailerConfig::CAPPED_KINDS, true)) {
            return false;
        }

        return (int) Database::run(
            'SELECT COUNT(*) FROM email_queue WHERE kind = ? AND DATE(created_at) = CURDATE()',
            [$kind]
        )->fetchColumn() > 0;
    }

    /**
     * Queue a broadcast notification (gallery / live) to every member who
     * enabled the given type. The body carries the per-recipient
     * {{unsubscribe-url}} placeholder, so opting out of marketing mail also
     * stops these. Returns the number of recipients.
     *
     * Capped per recipient per day, per kind. At the default of 1 a member gets
     * ONE email for the day a gallery is posted - not one per gallery, which is
     * what happened on 2026-09-28: 266 emails to 15 people. Chat/support and
     * billing mail are exempt. A cap of 0 restores unlimited fan-out.
     */
    public static function enqueueNotification(string $flagColumn, string $subject, string $html, string $text): int
    {
        if (!EmailerConfig::broadcastsEnabled()) {
            return 0;
        }

        $recipients = self::notificationRecipients($flagColumn);
        if ($recipients === []) {
            return 0;
        }

        $kind  = self::kindForFlag($flagColumn);
        $atCap = self::usersAtNotificationCap(self::notificationDailyCap(), $kind);

        $rows = [];
        foreach ($recipients as $user) {
            if (isset($atCap[$user['id']])) {
                continue;
            }
            $rows[] = ['notification', $kind, $user['id'], $user['email'], $subject, $html, $text];
        }

        if ($rows === []) {
            return 0;
        }

        self::insertRows($rows);

        return count($rows);
    }

    /**
     * How many broadcast emails a single member may receive per day, per kind.
     * Chat, support and billing mail are never subject to this.
     */
    public static function notificationDailyCap(): int
    {
        $cap = (int) (EmailerConfig::all()['notification_daily_cap'] ?? 1);

        return max(0, min(10, $cap));
    }

    /**
     * Queue a single-recipient transactional notification to one member,
     * honouring the global marketing opt-out and the per-type flag. Used for
     * e.g. "you have a new chat reply". Returns 1 when queued, else 0.
     */
    public static function enqueueUser(int $userId, string $flagColumn, string $subject, string $html, string $text): int
    {
        $userId = max(0, (int) $userId);
        if ($userId <= 0 || !in_array($flagColumn, ['notify_new_gallery', 'notify_live', 'notify_chat_reply'], true)) {
            return 0;
        }

        $row = Database::run(
            "SELECT u.id, u.email
             FROM users u
             WHERE u.id = ? AND u.status = 'active'
               AND COALESCE(u.marketing_opt_out, 0) = 0
               AND COALESCE(u.{$flagColumn}, 1) = 1
             LIMIT 1",
            [$userId]
        )->fetch();

        if ($row === false) {
            return 0;
        }

        $kind = self::kindForFlag($flagColumn);

        // A broadcast kind still counts against the member's daily allowance.
        if (in_array($kind, EmailerConfig::CAPPED_KINDS, true)) {
            $atCap = self::usersAtNotificationCap(self::notificationDailyCap(), $kind);
            if (isset($atCap[(int) $row['id']])) {
                return 0;
            }
        }

        self::insertRows([['notification', $kind, (int) $row['id'], (string) $row['email'], $subject, $html, $text]]);

        return 1;
    }

    /**
     * Notify the member of a conversation that the operator replied to it.
     * Fired from both operator reply paths (admin panel + operator app);
     * honours users.notify_chat_reply and the global opt-out.
     */
    public static function notifyChatReply(int $conversationId): void
    {
        $conversationId = max(0, (int) $conversationId);
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

        $subject = 'You have a new reply';
        $html    = render_email('chat_reply', ['conversation_id' => $conversationId]);
        $text    = render_email('chat_reply.text', ['conversation_id' => $conversationId]);

        self::enqueueUser($userId, 'notify_chat_reply', $subject, $html, $text);
    }

    /**
     * Queue a single transactional email to one active user, bypassing the
     * marketing opt-out (billing / account-critical mail). Optionally held
     * until scheduled_at. Returns the email_queue id, or null when the user
     * is missing/inactive.
     */
    public static function enqueueTx(int $userId, string $subject, string $html, string $text, ?string $scheduledAt = null): ?int
    {
        $userId = max(0, (int) $userId);
        if ($userId <= 0) {
            return null;
        }

        $email = (string) Database::run(
            "SELECT email FROM users WHERE id = ? AND status = 'active' LIMIT 1",
            [$userId]
        )->fetchColumn();

        if ($email === '') {
            return null;
        }

        if ($scheduledAt !== null && $scheduledAt !== '') {
            Database::run(
                'INSERT INTO email_queue (audience, kind, user_id, email, subject, html_body, text_body, scheduled_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                ['notification', 'lifecycle', $userId, $email, $subject, $html, $text, $scheduledAt]
            );
        } else {
            Database::run(
                'INSERT INTO email_queue (audience, kind, user_id, email, subject, html_body, text_body)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                ['notification', 'lifecycle', $userId, $email, $subject, $html, $text]
            );
        }

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Exactly-once guard for lifecycle (dunning/win-back) email. Returns true
     * when this (subscription_id, event_key) pair has NOT been sent before.
     */
    public static function lifecycleAlreadySent(int $subscriptionId, string $eventKey): bool
    {
        $row = Database::run(
            'SELECT id FROM subscription_email_log WHERE subscription_id = ? AND event_key = ? LIMIT 1',
            [$subscriptionId, $eventKey]
        )->fetch();

        return $row !== false;
    }

    /**
     * Record that a lifecycle email was queued for a subscription.
     */
    public static function lifecycleRecord(int $subscriptionId, string $eventKey, int $emailQueueId): bool
    {
        $stmt = Database::run(
            'INSERT INTO subscription_email_log (subscription_id, event_key, email_queue_id)
             VALUES (?, ?, ?)',
            [$subscriptionId, $eventKey, $emailQueueId]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Per-event counts of lifecycle emails sent, for the admin emailer page.
     *
     * @return array<string,int>
     */
    public static function lifecycleStats(): array
    {
        $stats = [];
        foreach (Database::run(
            'SELECT event_key, COUNT(*) AS c FROM subscription_email_log GROUP BY event_key'
        )->fetchAll() as $row) {
            $stats[(string) $row['event_key']] = (int) $row['c'];
        }

        return $stats;
    }

    /**
     * Batch-insert queued rows 200 at a time so even a large mailing stays
     * inside a single prepared statement.
     *
     * @param array<int, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string, 6: string}> $rows
     */
    private static function insertRows(array $rows): void
    {
        $columns = '(audience, kind, user_id, email, subject, html_body, text_body)';

        foreach (array_chunk($rows, 200) as $chunk) {
            $values  = [];
            $params  = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                foreach ($row as $value) {
                    $params[] = $value;
                }
            }

            Database::run(
                'INSERT INTO email_queue ' . $columns . ' VALUES ' . implode(', ', $values),
                $params
            );
        }
    }

    /**
     * Rows waiting to be emailed, oldest first, up to $limit per worker tick.
     * Lifecycle rows can carry a scheduled_at (send no earlier than) and a
     * next_attempt_at (retry backoff), both honoured here.
     */
    public static function queued(int $limit = 25): array
    {
        $limit = max(1, min(500, $limit));

        return Database::run(
            'SELECT * FROM email_queue
             WHERE status = ?
               AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)
               AND (next_attempt_at IS NULL OR next_attempt_at <= CURRENT_TIMESTAMP)
             ORDER BY id ASC
             LIMIT ' . (int) $limit,
            ['queued']
        )->fetchAll();
    }

    /**
     * Hand the next batch of queued rows to Mailer::sendHtml(), substituting
     * each recipient's signed unsubscribe link for the body placeholder. A row
     * stays queued after a failed attempt until MAX_ATTEMPTS is reached, so a
     * transient SMTP outage self-recovers on the next cron tick.
     *
     * @return array{attempted: int, sent: int, failed: int}
     */
    public static function sendDue(int $limit = 25): array
    {
        $result = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

        foreach (self::queued($limit) as $row) {
            $result['attempted']++;

            $unsubscribe = '#';
            if ($row['user_id'] !== null) {
                $unsubscribe = self::unsubscribeUrl((int) $row['user_id']);
            }
            $unsubscribe = $unsubscribe !== '' ? $unsubscribe : '#';

            $html = str_replace(self::UNSUB_PLACEHOLDER, $unsubscribe, (string) $row['html_body']);
            $text = str_replace(self::UNSUB_PLACEHOLDER, $unsubscribe, (string) $row['text_body']);

            $error = 'Mailer did not accept the message';

            try {
                $ok = \App\Core\Mailer::sendHtml(
                    (string) $row['email'],
                    (string) $row['subject'],
                    $html,
                    $text
                );
            } catch (\Throwable $exception) {
                $ok    = false;
                $error = $exception->getMessage();
            }

            if ($ok) {
                self::markSent((int) $row['id']);
                $result['sent']++;
            } else {
                self::markFailed((int) $row['id'], $error);
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * Mark a delivered row sent.
     */
    public static function markSent(int $id): bool
    {
        $stmt = Database::run(
            'UPDATE email_queue SET status = ?, sent_at = CURRENT_TIMESTAMP, error = NULL
             WHERE id = ? AND status = ?',
            ['sent', $id, 'queued']
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Record a delivery failure for a row, keeping it queued until
     * MAX_ATTEMPTS, with retry backoff (30 min then 2 h) so a transient SMTP
     * outage drains slowly instead of hammering the mail server every tick.
     * The snapshot body is preserved either way so the admin can inspect and
     * retry it.
     */
    public static function markFailed(int $id, string $error): bool
    {
        $row = Database::run(
            'SELECT attempts FROM email_queue WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        if ($row === false) {
            return false;
        }

        $attempts = (int) $row['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $status = 'failed';
            $next   = null;
        } else {
            $status = 'queued';
            $next   = date('Y-m-d H:i:s', time() + ($attempts === 1 ? 1800 : 7200));
        }

        $stmt = Database::run(
            'UPDATE email_queue SET attempts = ?, status = ?, next_attempt_at = ?, error = ?
             WHERE id = ?',
            [$attempts, $status, $next, mb_substr($error, 0, 500) ?: 'Unknown delivery error', $id]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Move a given-up row back to the front of the queue for another attempt.
     */
    public static function requeue(int $id): bool
    {
        $stmt = Database::run(
            'UPDATE email_queue SET status = ?, attempts = 0, error = NULL
             WHERE id = ? AND status = ?',
            ['queued', $id, 'failed']
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Counts by status for the admin summary card.
     *
     * @return array{queued: int, sent: int, failed: int}
     */
    public static function statusCounts(): array
    {
        $rows = Database::run(
            'SELECT status, COUNT(*) AS c FROM email_queue GROUP BY status'
        )->fetchAll();

        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * Counts by kind for the admin page, so the operator can see exactly what
     * is being mailed and how often.
     *
     * @return array<string,int>
     */
    public static function kindCounts(): array
    {
        $out = [];

        foreach (Database::run('SELECT kind, COUNT(*) AS c FROM email_queue GROUP BY kind')->fetchAll() as $row) {
            $out[(string) $row['kind']] = (int) $row['c'];
        }

        return $out;
    }

    /**
     * The most recent queue rows, newest first, for the admin list.
     */
    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return Database::run(
            'SELECT * FROM email_queue ORDER BY id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    /**
     * Remove resolved rows (sent/failed) older than $days, keeping the table
     * small. Returns the number of rows removed.
     */
    public static function purgeOld(int $days = 30): int
    {
        $days = max(1, min(365, $days));

        $stmt = Database::run(
            'DELETE FROM email_queue
             WHERE status IN (?, ?)
               AND created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)',
            ['sent', 'failed']
        );

        return $stmt->rowCount();
    }

    /**
     * Shared secret for unsubscribe links: HMAC over the user id, backed by
     * GALLERY_MEDIA_KEY (same key that gates the media endpoints). Empty when
     * the key is missing so the check degrades to "reject".
     */
    public static function unsubscribeToken(int $userId): string
    {
        $secret = (string) env_value('GALLERY_MEDIA_KEY', '');

        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', 'unsubscribe:' . $userId, $secret);
    }

    /**
     * The signed one-click opt-out link for a user, embedded in their email.
     */
    public static function unsubscribeUrl(int $userId): string
    {
        $token = self::unsubscribeToken($userId);

        if ($token === '') {
            return absolute_url('/unsubscribe');
        }

        return absolute_url('/unsubscribe?uid=' . $userId . '&t=' . rawurlencode($token));
    }

    /**
     * Whether a submitted uid/t pair is a valid signature for that user.
     */
    public static function verifyUnsubscribe(int $userId, string $token): bool
    {
        if ($userId <= 0 || trim($token) === '') {
            return false;
        }

        $expected = self::unsubscribeToken($userId);

        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Opt a user out of marketing email (the gallery + live broadcasts).
     * Returns true when an account was updated. Chat/support and billing mail
     * are unaffected - those are transactional or account-critical.
     */
    public static function optOut(int $userId): bool
    {
        $stmt = Database::run(
            'UPDATE users SET marketing_opt_out = 1 WHERE id = ?',
            [$userId]
        );

        return $stmt->rowCount() > 0;
    }
}