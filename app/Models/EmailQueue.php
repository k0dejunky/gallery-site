<?php

namespace App\Models;

use App\Core\Database;

/**
 * Queue and dispatch engine for the auto-emailer.
 *
 * A digest is a single "small sample of the latest uploads" email sent to
 * every subscriber (sharp thumbnails + a CTA back to the gallery) and, when
 * enabled, to every registered non-subscriber (heavily blurred previews + a
 * CTA to become a member). Each recipient gets its own row in email_queue
 * carrying a snapshot of the audience-specific subject/body, so a digest can
 * never change after it was enqueued. The cron-driven worker hands "queued"
 * rows to Mailer::sendHtml() and marks them sent/failed.
 *
 * Unsubscribing: the body contains a per-audience placeholder that the worker
 * swaps for a per-user signed /unsubscribe link (HMAC over GALLERY_MEDIA_KEY).
 * Following it sets the user's marketing_opt_out flag, filtering them out of
 * every future recipient query.
 */
class EmailQueue
{
    /** Body placeholder replaced with the recipient's {uid,t} unsubscribe URL. */
    public const UNSUB_PLACEHOLDER = '{{unsubscribe-url}}';

    /** How many failed delivery attempts before a row is given up on. */
    public const MAX_ATTEMPTS = 3;

    /** Roles that never receive marketing mail (admins always count as members). */
    private const EXCLUDED_ROLES = ['super_admin', 'admin', 'editor', 'moderator', 'viewer'];

    /**
     * Members with a usable paid membership (level 1+): opted-in, non-admin
     * accounts holding an active/cancelled subscription whose expiry (if any)
     * is still in the future.
     */
    public static function subscribers(): array
    {
        return self::recipients(true);
    }

    /**
     * Free members (membership level 0): opted-in, non-admin accounts with no
     * usable subscription right now.
     */
    public static function nonSubscribers(): array
    {
        return self::recipients(false);
    }

    /**
     * Shared recipient query. $paid true = has a usable subscription; false =
     * has none. Classification follows the membership level alone (0 = Free),
     * so every non-admin account lands in exactly one audience. Both sides
     * require an active account and an explicit non-opt-out (default 0 = opted
     * in). Email verification is not required: unverified free members still
     * count as non-subscribers.
     *
     * @return array<int, array{id: int, email: string}>
     */
    private static function recipients(bool $paid): array
    {
        $excluded = implode(', ', array_map(static fn (string $r): string => "'" . $r . "'", self::EXCLUDED_ROLES));

        $subscription = 'EXISTS (
            SELECT 1 FROM subscriptions s
            WHERE s.user_id = u.id
              AND s.status IN (\'active\', \'cancelled\')
              AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
        )';

        // The EXISTS/NOT EXISTS flips between the paid and free audiences.
        $having = $paid ? $subscription : 'NOT ' . $subscription;

        return Database::run(
            "SELECT u.id, u.email
             FROM users u
             WHERE u.status = 'active'
               AND COALESCE(u.marketing_opt_out, 0) = 0
               AND u.role NOT IN ($excluded)
               AND $having
             ORDER BY u.id ASC",
            []
        )->fetchAll();
    }

    /**
     * The newest image uploads the digest will show: images (never videos)
     * still attached to non-deleted galleries, deduplicated per photo, each
     * carrying the gallery it lives in so the email can link straight to it.
     * The newest photo on the list anchors the "last sent" watermark, so the
     * worker only re-mails after genuinely new uploads arrive.
     *
     * @return array<int, array{id: int, filename: string, created_at: string, gallery_id: int}>
     */
    public static function sample(int $count = 6): array
    {
        $count = max(1, min(EmailerConfig::MAX_SAMPLE, $count));

        return Database::run(
            "SELECT p.id, p.filename, p.created_at,
                    (SELECT MIN(gp2.gallery_id)
                     FROM gallery_photo gp2
                     JOIN galleries g2 ON g2.id = gp2.gallery_id
                     WHERE gp2.photo_id = p.id AND g2.deleted_at IS NULL) AS gallery_id
             FROM gallery_photo gp
             JOIN photos p ON p.id = gp.photo_id
             JOIN galleries g ON g.id = gp.gallery_id
             WHERE g.deleted_at IS NULL AND p.is_video = 0
             GROUP BY p.id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT " . (int) $count,
            []
        )->fetchAll();
    }

    /**
     * Resolve the subject line for an audience from the config, substituting
     * the site name and the headline photo count. Falls back generically when
     * the admin left the field blank.
     */
    public static function subjectFor(array $config, string $audience, int $count): string
    {
        $template = $audience === 'non_subscriber'
            ? (string) ($config['subject_non_subscriber'] ?? '')
            : (string) ($config['subject_subscriber'] ?? '');

        $subject = str_replace(
            ['{site}', '{count}'],
            [(string) config('app.site_name'), (string) $count],
            $template
        );

        return trim($subject) !== '' ? trim($subject) : 'New in the ' . config('app.site_name') . ' gallery';
    }

    /**
     * Build and queue one digest for every eligible recipient, then advance
     * the "last sent" watermark.
     *
     * @param array $config The validated EmailerConfig (its schedule/mode is
     *                      not consulted here — the caller decides when this
     *                      runs, so "Send now" reuses the same path).
     * @param bool  $force  Send even when there is no upload newer than the
     *                      last-sent watermark (worker "no_new_uploads" skip).
     *
     * @return array{ok: bool, reason?: string, audience?: array{subscriber: int, non_subscriber: int}, count?: int}
     */
    public static function enqueueDigest(array $config, bool $force = false): array
    {
        $samples = self::sample((int) ($config['sample_count'] ?? 6));

        if ($samples === []) {
            return ['ok' => false, 'reason' => 'no_sample'];
        }

        $newestId = (int) $samples[0]['id'];
        $lastId   = (int) ($config['last_sent_photo_id'] ?? 0);

        if (!$force && $lastId > 0 && $newestId <= $lastId) {
            return ['ok' => false, 'reason' => 'no_new_uploads'];
        }

        $count = count($samples);

        $subHtml     = render_email('newsletter', ['subscriber' => true, 'samples' => $samples, 'count' => $count]);
        $subText     = render_email('newsletter.text', ['subscriber' => true, 'samples' => $samples, 'count' => $count]);
        $freeHtml    = render_email('newsletter', ['subscriber' => false, 'samples' => $samples, 'count' => $count]);
        $freeText    = render_email('newsletter.text', ['subscriber' => false, 'samples' => $samples, 'count' => $count]);
        $subSubject  = self::subjectFor($config, 'subscriber', $count);
        $freeSubject = self::subjectFor($config, 'non_subscriber', $count);

        $rows = [];

        foreach (self::subscribers() as $user) {
            $rows[] = ['subscriber', (int) $user['id'], (string) $user['email'], $subSubject, $subHtml, $subText];
        }

        if (!empty($config['include_non_subscribers'])) {
            foreach (self::nonSubscribers() as $user) {
                $rows[] = ['non_subscriber', (int) $user['id'], (string) $user['email'], $freeSubject, $freeHtml, $freeText];
            }
        }

        if ($rows === []) {
            return ['ok' => false, 'reason' => 'no_recipients'];
        }

        self::insertRows($rows);

        EmailerConfig::markSent(date('Y-m-d H:i:s'), $newestId);

        $perAudience = ['subscriber' => 0, 'non_subscriber' => 0];
        foreach ($rows as $row) {
            $perAudience[$row[0]]++;
        }

        return [
            'ok' => true,
            'audience' => $perAudience,
            'count' => $count,
        ];
    }

    /**
     * Batch-insert queued rows 200 at a time so even a large mailing stays
     * inside a single prepared statement.
     *
     * @param array<int, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string}> $rows
     */
    private static function insertRows(array $rows): void
    {
        $columns = '(audience, user_id, email, subject, html_body, text_body)';

        foreach (array_chunk($rows, 200) as $chunk) {
            $values  = [];
            $params  = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, ?)';
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
     */
    public static function queued(int $limit = 25): array
    {
        $limit = max(1, min(500, $limit));

        return Database::run(
            'SELECT * FROM email_queue
             WHERE status = ?
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
     * Record a delivery failure for a row, keeping it fair until
     * MAX_ATTEMPTS, then giving up. The snapshot body is preserved either way
     * so the admin can inspect and retry it.
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
        $status   = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued';

        $stmt = Database::run(
            'UPDATE email_queue SET attempts = ?, status = ?, error = ?
             WHERE id = ?',
            [$attempts, $status, mb_substr($error, 0, 500) ?: 'Unknown delivery error', $id]
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
     * Opt a user out of all marketing email. Returns true when an account was
     * updated.
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