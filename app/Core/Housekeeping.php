<?php

namespace App\Core;

use App\Models\EmailerConfig;

/**
 * Scheduled housekeeping shared by the admin "Run now" button and the
 * unattended cron endpoint: expire stale subscriptions, remove long-abandoned
 * staging directories, prune old backups, and record what happened.
 */
class Housekeeping
{
    /**
     * Run every task and return a summary array. $backupKeep keeps the N
     * newest archives when greater than zero; when null it falls back to
     * the HOUSEKEEPING_KEEP_BACKUPS .env value (default 10).
     */
    public static function run(?int $backupKeep = null): array
    {
        $root = dirname(__DIR__, 2);

        if ($backupKeep === null) {
            $backupKeep = (int) env_value('HOUSEKEEPING_KEEP_BACKUPS', '10');
        }

        $out  = [
            'at'            => date('Y-m-d H:i:s'),
            'expired_subs'  => 0,
            'pending_dirs'  => 0,
            'backups_pruned' => 0,
            'paypal_reconciled' => 0,
            'disk_free_gb'  => null,
            'disk_used_percent' => null,
            'temp_files_removed' => 0,
            'disk_growth_alerted' => false,
            'chat_broadcast' => 0,
            'chat_prunes'   => [],
            'visit_prunes'  => [],
            'ffmpeg_count'  => 0,
            'errors'        => [],
        ];

        // Every step runs inside a try, and the log is written in a finally.
        // One failing step must not be able to stop the others, and it must
        // never be able to stop the log from being written - the log is the
        // only record that the run happened at all.
        try {
            // ffmpeg runaway guard: if concurrent ffmpeg processes ever spike
            // (variant/video bursts), alert the admin immediately so a CPU storm
            // like the one that took the site down is caught before it does.
            try {
                $ffmpegCount = (int) trim((string) shell_exec('pgrep -fc ffmpeg 2>/dev/null'));
                $out['ffmpeg_count'] = $ffmpegCount;
                if ($ffmpegCount >= 6) {
                    Mailer::adminAlert(
                        'ffmpeg-burst',
                        'ffmpeg burst detected',
                        'Concurrent process count: ' . $ffmpegCount . '. Check ps aux | grep ffmpeg.'
                    );
                }
            } catch (\Throwable $e) {
                $out['ffmpeg_count'] = -1;
            }

            // Disk-fill guard: a full disk sinks the whole box (uploads, backups,
            // MySQL). Alert the admin before usage crosses 85% so it is caught
            // with room to spare rather than after files start failing.
            try {
                $diskFree  = @disk_free_space($root);
                $diskTotal = @disk_total_space($root);
                if (is_float($diskFree) && is_float($diskTotal) && $diskTotal > 0) {
                    $usedPct                = (int) round((($diskTotal - $diskFree) / $diskTotal) * 100);
                    $out['disk_used_percent'] = $usedPct;
                    $out['disk_free_gb']     = (float) round($diskFree / 1073741824, 1);
                    if ($usedPct >= 85) {
                        Mailer::adminAlert(
                            'disk-fill',
                            'Disk at ' . $usedPct . '%',
                            'Filesystem used ' . $usedPct . '% of ' . round($diskTotal / 1073741824, 0)
                            . ' GB (' . $out['disk_free_gb'] . ' GB free). df -h / to inspect.'
                        );
                    }
                }
            } catch (\Throwable $e) {
                $out['disk_used_percent'] = -1;
            }

            self::step('backup_sync', fn () => self::watchBackupSync($root), $out);
            self::step('restore_drill', fn () => self::watchRestoreDrill($root), $out);

            // Orphaned PHP temp files (e.g. interrupted uploads/workers) pile up
            // in /tmp and /var/tmp; prune anything older than 48h so the disk
            // never silently fills with leftovers.
            $out['temp_files_removed'] = self::step('temp_files', fn () => self::cleanupOrphanedTemp(), $out);

            // Alert when media grows unusually fast (a bulk import) so the disk
            // is not discovered full after the fact.
            $out['disk_growth_alerted'] = self::step('disk_growth', fn () => self::alertOnDiskGrowth(), $out);

            // Weekly duplicate-gallery report (throttled to once per 7 days
            // inside sendWeeklyReport) so re-imports never pile up unnoticed.
            self::step('duplicate_report', fn () => DuplicateGalleries::sendWeeklyReport(), $out);

            // One-off abandoned-signup recovery emails (max once per day).
            $out['recovery_emails'] = self::step('recovery_emails', fn () => self::sendRecoveryEmails($root), $out);

            // Auto-approve paid PayPal memberships whose webhook was missed.
            $reconciled = self::step('paypal_reconcile', fn () => self::reconcilePayPalSubscriptions(), $out) ?? ['activated' => 0];
            $out['paypal_reconciled'] = $reconciled['activated']; // activations are the headline number

            // Subscriptions whose expiry passed while nobody was watching.
            $expiredRows = self::step('expire_subscriptions', function () {
                return Database::run(
                    "SELECT id FROM subscriptions
                     WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP"
                )->fetchAll();
            }, $out) ?? [];
            $out['expired_subs'] = count($expiredRows);
            if ($expiredRows !== []) {
                $ids = array_column($expiredRows, 'id');
                $marks = implode(',', array_fill(0, count($ids), '?'));
                Database::run(
                    "UPDATE subscriptions SET status = 'expired' WHERE id IN ($marks)",
                    $ids
                );
                foreach ($ids as $expiredId) {
                    Lifecycle::onExpired((int) $expiredId);
                }
            }

            // Renewal reminders (7 days out), win-backs (7 days lapsed), trial
            // endings (3 days out) and inactive-member win-backs (30 days unseen),
            // gated by the emailer's lifecycle toggles. Each audience is deduped
            // per subscription/member, so the 15-minute cadence cannot repeat one.
            $emailerCfg = self::step('emailer_config', fn () => EmailerConfig::all(), $out) ?? [];
            $out['renewal_reminders'] = self::step('renewal_reminders', fn () => !empty($emailerCfg['lifecycle_renewal_reminders']) ? Lifecycle::runRenewalReminders(7) : 0, $out);
            $out['winbacks']          = self::step('winbacks', fn () => !empty($emailerCfg['lifecycle_winbacks']) ? Lifecycle::runWinbacks(7) : 0, $out);
            $out['trial_endings']     = self::step('trial_endings', fn () => !empty($emailerCfg['lifecycle_trial_endings']) ? Lifecycle::runTrialEndings(3) : 0, $out);
            $out['inactive_winbacks'] = self::step('inactive_winbacks', fn () => !empty($emailerCfg['lifecycle_inactive_winbacks']) ? Lifecycle::runInactiveWinbacks(30) : 0, $out);

            // Staging directories abandoned mid-upload for more than 72 hours.
            $base = $root . '/storage/uploads/pending';

            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $ageH = (time() - (int) filemtime($dir)) / 3600;

                if ($ageH >= 72) {
                    self::rrmdir($dir);
                    $out['pending_dirs']++;
                }
            }

            // Keep only the newest backup runs so the disk never fills up. A
            // run groups everything sharing one timestamp: the split media
            // archive (.part-NN), its .sha256 checksums and the SQL dump.
            $backupDir = $root . '/storage/backups';
            $runs      = [];

            foreach (['*.tar.gz', '*.tar.gz.part-*', '*.tar.gz.sha256', '*.sql.gz'] as $pattern) {
                foreach ((glob($backupDir . '/' . $pattern) ?: []) as $file) {
                    $key = 'misc';

                    if (preg_match('/-(\d{8}-\d{6})\./', basename($file), $m)) {
                        $key = $m[1];
                    }

                    $runs[$key][] = $file;
                }
            }

            if ($backupKeep > 0 && count($runs) > $backupKeep) {
                $newest = fn (array $files): int => max(array_map(fn (string $f): int => (int) filemtime($f), $files));
                uasort($runs, fn (array $a, array $b): int => $newest($b) <=> $newest($a));

                foreach (array_slice($runs, $backupKeep, null, true) as $files) {
                    foreach ($files as $old) {
                        @unlink($old);
                        $out['backups_pruned']++;
                    }
                }
            }

            // Storage snapshot for the trending chart + low-disk alert.
            // photos_count/video_count mirror the dashboard's Photos/Videos
            // cards (attached, non-deleted media) so the numbers agree.
            self::step('storage_snapshot', function () use ($root) {
                $bytes = self::uploadsBytes($root . '/storage/uploads');
                [$photos, $videos] = self::mediaCounts();
                Database::run(
                    'INSERT INTO storage_snapshots (captured_at, uploads_bytes, photos_count, video_count) VALUES (CURRENT_TIMESTAMP, ?, ?, ?)',
                    [$bytes, $photos, $videos]
                );
                Database::run(
                    'DELETE FROM storage_snapshots WHERE captured_at < ?',
                    [date('Y-m-d H:i:s', time() - 90 * 86400)]
                );
            }, $out);

            $free = @disk_free_space($root);

            if ($free !== false) {
                $freeGb            = round($free / 1073741824, 1);
                $out['disk_free_gb'] = $freeGb;

                if ($freeGb <= (float) env_value('DISK_MIN_FREE_GB', '10')) {
                    Mailer::adminAlert(
                        'disk-low',
                        'Low disk space',
                        sprintf("Only %s GB free on the gallery server (threshold %s GB).\nClean up or extend the volume soon.",
                            $freeGb, env_value('DISK_MIN_FREE_GB', '10')),
                        43200
                    );
                }
            }

            // Expiring chat media whose time or view limit has been hit: remove
            // the stored files so they free storage and are permanently gone.
            $out['chat_media_purged'] = self::step('chat_media', fn () => \App\Models\ChatMessage::purgeExpiredMedia(), $out);

            // New PHP fatals since the last sweep: alert the admin so an outage
            // (like a runtime type error) is caught immediately, not by users.
            $out['fatal_alerted'] = self::step('php_fatals', fn () => self::alertOnPhpFatals($root), $out);

            // Member notifications: email members once per newly-live gallery
            // (covers galleries published on schedule, not just "publish now").
            $out['new_gallery_notified'] = self::step('new_gallery_notified', fn () => self::notifyNewGalleries(), $out);

            // Automatic daily chat broadcast (once per day) so members get a
            // fresh attributed link even when no admin scheduled one.
            $out['chat_broadcast'] = self::step('chat_broadcast', fn () => self::scheduleDailyChatBroadcast(), $out);

            // Chat retention: keep the idempotency window, the wall inbox and the
            // background-reply queue from growing without bound. Counts land in
            // $out so the cron log shows what each run removed.
            $out['chat_prunes'] = self::step('chat_prunes', fn () => self::pruneChatRetention(), $out);
            $out['visit_prunes'] = self::step('visit_prunes', fn () => self::pruneVisitHistory(), $out);

            // Refer-a-friend: +7 free days to referrers whose referred members
            // have become paying.
            $out['referral_rewards'] = self::step('referral_rewards', fn () => self::rewardReferrals(), $out);

        } finally {
            @file_put_contents(
                $root . '/storage/logs/cron.log',
                implode(' | ', array_map(fn ($k, $v) => "$k=$v", array_keys($out), $out)) . "\n",
                FILE_APPEND
            );
        }

        return $out;
    }

    /**
     * Queue "new gallery" notifications (once each, via notified_at) for
     * galleries that are visible and were created in the last 14 days. The
     * 14-day window stops a legacy site from notifying its entire back
     * catalogue on first deploy; the LIMIT bounds a burst.
     *
     * Test and secret galleries are excluded in the SQL, not just in the
     * notifier: a gallery that is skipped stays unmarked (so clearing the
     * flag later still announces it), and if the skipped ones were left in
     * the result set they would eat the LIMIT and starve the real galleries
     * behind them.
     */
    private static function notifyNewGalleries(): int
    {
        $rows = Database::run(
            'SELECT id FROM galleries
             WHERE deleted_at IS NULL
               AND notified_at IS NULL
               AND is_test = 0
               AND is_secret = 0
               AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 14 DAY)
             ORDER BY id ASC
             LIMIT 200'
        )->fetchAll();

        $count = 0;
        foreach ($rows as $row) {
            \App\Models\Gallery::notifyNewGalleryIfUnsent((int) $row['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Auto-compose the daily chat broadcast (once per day, deduped by the
     * row's created_at date) so members always receive a fresh link even
     * when no admin scheduled a broadcast. The link carries the `chat`
     * attribution code; delivery is the daily_chat_worker's job. Returns
     * 1 when a row was created, 0 when one already exists today.
     */
    private static function scheduleDailyChatBroadcast(): int
    {
        $already = Database::run(
            'SELECT 1 FROM chat_daily_broadcasts WHERE created_at >= CURDATE() LIMIT 1'
        )->fetch();

        if ($already !== false) {
            return 0;
        }

        $gallery = Database::run(
            'SELECT id, title FROM galleries
             WHERE deleted_at IS NULL
               AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)
               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 14 DAY)
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        )->fetch();

        if ($gallery !== false) {
            $url = \App\Models\Traffic::buildUrl('/galleries/' . (int) $gallery['id'], 'chat');
            $msg = 'New in the gallery: ' . trim((string) $gallery['title']) . ' — ' . $url;
        } else {
            $url = \App\Models\Traffic::buildUrl('/signup', 'chat');
            $msg = 'Join the site for fresh uploads — ' . $url;
        }

        $admin = Database::run(
            'SELECT id FROM users WHERE role = ? ORDER BY id ASC LIMIT 1',
            ['super_admin']
        )->fetch();
        $createdBy = $admin !== false ? (int) $admin['id'] : 1;

        return \App\Models\ChatBroadcast::create($msg, null, $createdBy);
    }

    /**
     * Chat retention (daily, inside run()):
     *  - chat_reply_idempotency keys older than 7 days (they only need to
     *    outlive a client's retry window);
     *  - READ notifications older than 90 days (unread ones are never touched);
     *  - finished/failed chat_ai_jobs older than 7 days.
     *
     * @return array<string, int> rows removed per table
     */
    private static function pruneChatRetention(): array
    {
        $out = [];

        $out['reply_keys'] = (int) Database::run(
            'DELETE FROM chat_reply_idempotency WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        )->rowCount();

        $out['notifications'] = (int) Database::run(
            'DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
        )->rowCount();

        $out['ai_jobs'] = (int) Database::run(
            "DELETE FROM chat_ai_jobs
              WHERE status IN ('done', 'error')
                AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->rowCount();

        // Abandoned checkouts: a pending subscription nobody ever paid for
        // held a slot of a limited sale FOREVER, because nothing ever
        // removed it. A week is far longer than a checkout takes.
        $out['abandoned_subscriptions'] = (int) Database::run(
            "UPDATE subscriptions SET status = 'cancelled', updated_at = NOW()
              WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->rowCount();

        return $out;
    }

    /**
     * Prune the tables that grow with every visit.
     *
     * user_activity gets a row per gallery view, content_views a row per
     * (entity, day) and category_views a row per category page view - none of
     * them was ever pruned, so they grew forever. The windows are far longer
     * than anything the site reads (the trends chart asks for 7 days, the
     * member monitor for 30).
     */
    private static function pruneVisitHistory(): array
    {
        $out = [];

        $out['user_activity'] = (int) Database::run(
            'DELETE FROM user_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
        )->rowCount();

        $out['content_views'] = (int) Database::run(
            'DELETE FROM content_views WHERE view_date < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)'
        )->rowCount();

        $out['category_views'] = (int) Database::run(
            'DELETE FROM category_views WHERE created_at < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)'
        )->rowCount();

        $out['search_stats'] = (int) Database::run(
            'DELETE FROM search_stats WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)'
        )->rowCount();

        return $out;
    }

    /**
     * Run one housekeeping step so that it cannot take the others with it.
     *
     * A single bad query used to abort the whole run. The throw landed in
     * the last 5% of this function, so the refer-a-friend rewards never ran
     * and - because the log write was AFTER the throw - the cron log
     * stopped being written at all. Nineteen hours of failure went
     * unnoticed.
     *
     * The error is recorded in $out['errors'] and the run carries on.
     *
     * @param mixed[] $out
     * @return mixed
     */
    private static function step(string $name, callable $fn, array &$out)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            $out['errors'][] = $name . ': ' . $e->getMessage();
            error_log('[housekeeping] the ' . $name . ' step failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Refer-a-friend rewards: when a referred member becomes a paying member
     * (an active subscription that is not a trial), extend the referrer's
     * active (expiring) subscription by 7 days — once per referred member.
     */
    private static function rewardReferrals(): int
    {
        $rows = Database::run(
            "SELECT u.id AS referred_id, u.referred_by_user_id
             FROM users u
             JOIN subscriptions s ON s.user_id = u.id
             WHERE u.referred_by_user_id IS NOT NULL
               AND u.referred_by_rewarded_at IS NULL
               AND s.status = 'active'
               AND s.transaction_ref NOT LIKE 'TRIAL-%'
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
             GROUP BY u.id, u.referred_by_user_id
             LIMIT 50"
        )->fetchAll();

        $rewarded = 0;
        foreach ($rows as $row) {
            $referrerId = (int) $row['referred_by_user_id'];

            // Only extend expiring (non-lifetime) memberships.
            $sub = Database::run(
                "SELECT id FROM subscriptions
                 WHERE user_id = ? AND status = 'active'
                   AND expires_at IS NOT NULL AND expires_at > CURRENT_TIMESTAMP
                 ORDER BY id DESC LIMIT 1",
                [$referrerId]
            )->fetch();

            if ($sub === false) {
                continue;
            }

            Database::run(
                'UPDATE subscriptions SET expires_at = DATE_ADD(expires_at, INTERVAL 7 DAY) WHERE id = ?',
                [(int) $sub['id']]
            );
            Database::run(
                'UPDATE users SET referred_by_rewarded_at = CURRENT_TIMESTAMP WHERE id = ?',
                [(int) $row['referred_id']]
            );
            $rewarded++;
        }

        return $rewarded;
    }

    /**
     * Watch for brand-new PHP fatal/parse errors in storage/logs/php-error.log
     * since the last sweep and email the admin when one appears. Tracks the
     * byte offset it has already consumed in a small state file, so a fatal
     * is reported exactly once; if the log is rotated/truncated the offset is
     * reset and the tail is scanned again.
     */
    private static function alertOnPhpFatals(string $root): int
    {
        $logPath = $root . '/storage/logs/php-error.log';
        $state   = $root . '/storage/logs/.php_fatal_alert_offset';

        $size = is_file($logPath) ? (int) @filesize($logPath) : 0;
        if ($size <= 0) {
            @file_put_contents($state, '0');

            return 0;
        }

        $offset = is_file($state) ? (int) @file_get_contents($state) : 0;
        if ($offset > $size) {
            $offset = 0; // log was rotated or truncated
        }

        // Keep the tail bounded so a huge backlog never floods the read.
        $start = max($offset, $size - 262144);

        $chunk = $start < $size ? (string) @file_get_contents($logPath, false, null, $start, $size - $start) : '';

        @file_put_contents($state, (string) $size);

        if ($chunk === '') {
            return 0;
        }

        $lines = preg_split('/\r?\n/', $chunk) ?: [];
        $fresh = [];

        foreach ($lines as $line) {
            if (preg_match('/PHP (Fatal|Parse) error:|Uncaught [A-Za-z\\\\]+:/', $line)) {
                $fresh[] = trim($line);
            }
        }

        if (!$fresh) {
            return 0;
        }

        $sample = implode("\n", array_slice($fresh, 0, 10));

        Mailer::adminAlert(
            'php-fatal',
            'PHP fatal error on the gallery site',
            "New PHP fatal/parse errors detected:\n\n" . $sample . "\n\nCheck storage/logs/php-error.log for the full trace.",
            3600
        );

        return count($fresh);
    }

    /**
     * Reconcile pending PayPal subscriptions against PayPal's own status so
     * a paid membership activates even when a webhook was missed (verified
     * path — activation only happens when PayPal reports the subscription
     * ACTIVE/APPROVED). Returns a summary for the caller to log.
     *
     * @return array{activated:int, closed:int, skipped:int, errors:int}
     */
    public static function reconcilePayPalSubscriptions(): array
    {
        $summary = ['activated' => 0, 'closed' => 0, 'skipped' => 0, 'errors' => 0];

        $rows = Database::run(
            "SELECT s.id, s.transaction_ref, s.user_id, s.plan_id,
                    pp.id AS processor_id, pp.config_json
             FROM subscriptions s
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             WHERE s.status = 'pending' AND s.transaction_ref LIKE 'PAYPAL-%'
             ORDER BY s.id ASC"
        )->fetchAll();

        if ($rows === []) {
            return $summary;
        }

        // A single enabled PayPal processor provides the credentials.
        $gateway = null;
        if (count($rows) > 0) {
            $processor = Database::run(
                "SELECT * FROM payment_processors
                 WHERE provider = 'paypal' AND enabled = 1
                 ORDER BY is_default DESC, id ASC LIMIT 1"
            )->fetch();

            if ($processor !== false) {
                $gateway = \App\Core\PayPalGateway::fromConfig($processor);
            }
        }

        if ($gateway === null) {
            $summary['skipped'] = count($rows);
            error_log('[paypal-reconcile] no enabled PayPal processor with credentials; left ' . count($rows) . ' pending');
            return $summary;
        }

        foreach ($rows as $row) {
            $id  = (int) $row['id'];
            $ref = (string) $row['transaction_ref'];
            // ref format: PAYPAL-<paypal subscription id>
            $paypalId = preg_replace('/^PAYPAL-/', '', $ref);

            try {
                $status = $gateway->getSubscriptionStatus($paypalId);
            } catch (\Throwable $e) {
                error_log('[paypal-reconcile] status check failed for ' . $ref . ': ' . $e->getMessage());
                $summary['errors']++;
                continue;
            }

            if ($status === null) {
                $summary['skipped']++;
                continue;
            }

            if (in_array($status, ['ACTIVE', 'APPROVED'], true)) {
                \App\Models\Subscription::activateWithTransaction($id, $ref);
                \App\Models\AuditLog::record(
                    (int) ($row['user_id'] ?? 0),
                    'update',
                    'subscription',
                    $id,
                    'Auto-approved via PayPal reconciliation (' . $status . ')',
                    null,
                    ['paypal_subscription_id' => $paypalId, 'status' => $status]
                );
                $summary['activated']++;
                error_log('[paypal-reconcile] activated ' . $ref . ' (' . $status . ')');
            } elseif (in_array($status, ['SUSPENDED', 'CANCELLED', 'EXPIRED', 'INACTIVE'], true)) {
                \App\Models\Subscription::cancel($id);
                \App\Models\AuditLog::record(
                    (int) ($row['user_id'] ?? 0),
                    'update',
                    'subscription',
                    $id,
                    'Closed via PayPal reconciliation (' . $status . ')',
                    null,
                    ['paypal_subscription_id' => $paypalId, 'status' => $status]
                );
                $summary['closed']++;
                error_log('[paypal-reconcile] closed ' . $ref . ' (' . $status . ')');
            } else {
                // Any other status (e.g. PENDING/APPROVED-but-not-active) is left alone.
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * Detect a failed background backup (the runner leaves .failed behind
     * when it exits without success). First caller gets the message and the
     * marker is renamed to .failed.seen so admins are alerted once.
     */
    public static function consumeBackupFailure(): ?string
    {
        $root  = dirname(__DIR__, 2);
        $file  = $root . '/storage/backups/.failed';

        if (!is_file($file)) {
            return null;
        }

        $msg = trim((string) @file_get_contents($file));
        @rename($file, $file . '.seen');
        Mailer::adminAlert('backup-failed', 'Backup failed', "The scheduled/backup job reported failure:\n" . ($msg ?: '(no detail)'), 600);

        return $msg !== '' ? $msg : 'backup failed';
    }

    /**
     * Total size of the uploads tree + photo-file count, walking with the
     * SPL iterators (no shell out).
     *
     * @return array{0: int, 1: int} [bytes, fileCount]
     */
    /**
     * Alert when the offsite backup sync looks unhealthy: the .last_sync
     * status file reports a failure, or no successful sync for over 26 h.
     */
    private static function watchBackupSync(string $root): void
    {
        $file = $root . '/storage/backups/.last_sync';
        if (!is_file($file)) {
            return; // sync feature not configured
        }

        $data  = json_decode((string) @file_get_contents($file), true);
        $ok    = is_array($data) && !empty($data['ok']) && (int) ($data['sync_rc'] ?? 1) === 0;
        $ageH  = is_array($data) && !empty($data['at']) ? (time() - strtotime((string) $data['at'])) / 3600 : null;

        if ($ok && $ageH !== null && $ageH <= 26) {
            return;
        }

        $why = !$ok ? 'last sync reported failure (sync_rc=' . ($data['sync_rc'] ?? '?') . ')'
                    : 'no successful sync for ' . round((float) $ageH) . ' hours';
        Mailer::adminAlert(
            'backup-sync',
            'Backup sync unhealthy',
            "Offsite backup sync problem: {$why}.\nCheck storage/backups/.last_sync and the rclone logs on the server.",
            43200
        );
    }

    /**
     * Alert when the weekly restore drill has not passed recently — the
     * proof that backups are actually restorable.
     */
    private static function watchRestoreDrill(string $root): void
    {
        $file = $root . '/storage/backups/.last_drill';

        if (!is_file($file)) {
            Mailer::adminAlert(
                'restore-drill',
                'Restore drill never run',
                "No backup restore drill has been recorded yet.\nRun scripts/restore-drill.sh from cron to verify restorability.",
                604800
            );
            return;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        $ageD = is_array($data) && !empty($data['at']) ? (time() - strtotime((string) $data['at'])) / 86400 : null;

        if (is_array($data) && !empty($data['ok']) && $ageD !== null && $ageD <= 8) {
            return;
        }

        $why = !is_array($data) || empty($data['ok'])
            ? 'last drill FAILED: ' . ($data['note'] ?? 'unknown error')
            : 'last successful drill was ' . round((float) $ageD) . ' days ago';
        Mailer::adminAlert(
            'restore-drill',
            'Restore drill stale or failed',
            "Backup restore drill problem: {$why}\nCheck storage/backups/.last_drill on the server.",
            604800
        );
    }

    private static function uploadsBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $bytes = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        ) as $item) {
            if ($item->isFile()) {
                $bytes += $item->getSize();
            }
        }

        return $bytes;
    }

    /**
     * Remove orphaned PHP temp files (`php*`) from /tmp and /var/tmp that are
     * older than the threshold. Interrupted uploads/workers leave these behind
     * and, without cleanup, they can accumulate gigabytes. Files younger than
     * the threshold are left alone — no live operation keeps a temp file for
     * that long.
     *
     * @return int number of files removed
     */
    private static function cleanupOrphanedTemp(): int
    {
        $maxAgeHours = max(1, (int) env_value('DISK_TEMP_MAX_AGE_HOURS', '48'));
        $cutoff      = time() - $maxAgeHours * 3600;
        $removed     = 0;

        foreach (['/tmp', '/var/tmp'] as $dir) {
            foreach (glob($dir . '/php*') ?: [] as $file) {
                if (is_file($file) && @filemtime($file) < $cutoff) {
                    if (@unlink($file)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Alert when the media on disk grows unusually fast within a day (e.g. a
     * bulk video import), using the 15-minute storage_snapshots history. This
     * catches the kind of silent 40-50 GB growth that the low-disk alert (which
     * only fires at a low absolute free figure) misses.
     *
     * @return bool true when an alert was emitted this run
     */
    private static function alertOnDiskGrowth(): bool
    {
        $thresholdGb = max(1, (int) env_value('DISK_GROWTH_ALERT_GB', '5'));

        $nowBytes = Database::run(
            'SELECT uploads_bytes FROM storage_snapshots ORDER BY captured_at DESC LIMIT 1'
        )->fetchColumn();
        $dayAgoBytes = Database::run(
            'SELECT uploads_bytes FROM storage_snapshots
             WHERE captured_at <= (CURRENT_TIMESTAMP - INTERVAL 24 HOUR)
             ORDER BY captured_at DESC LIMIT 1'
        )->fetchColumn();

        if ($nowBytes === false || $nowBytes === null
            || $dayAgoBytes === false || $dayAgoBytes === null) {
            return false;
        }

        $growthGb = ((int) $nowBytes - (int) $dayAgoBytes) / 1073741824;
        if ($growthGb < $thresholdGb) {
            return false;
        }

        $freeGb = round((float) @disk_free_space(dirname(__DIR__, 2)) / 1073741824, 1);

        Mailer::adminAlert(
            'disk-growth',
            'Disk usage growing fast',
            sprintf(
                "Media grew by %.1f GB in the last 24 hours (threshold %.0f GB).\nOnly %.1f GB free now.\nA bulk import may be in progress — review before the disk fills.",
                $growthGb, $thresholdGb, $freeGb
            ),
            86400
        );

        return true;
    }

    /**
     * Send one-off "finish setting up your account" emails to users who
     * signed up but never verified their email (a proxy for abandonment),
     * at most once per day. Each user is emailed at most once (guarded by
     * the recovery_email_sent_at column). Respects marketing opt-out.
     *
     * @return int number of recovery emails sent this run
     */
    private static function sendRecoveryEmails(string $root): int
    {
        $stateFile = $root . '/storage/logs/recovery-sent.state';
        $last      = is_file($stateFile) ? (int) @file_get_contents($stateFile) : 0;

        // Run at most once per day regardless of the 15-minute cron cadence.
        if (time() - $last < 86400) {
            return 0;
        }

        $rows = Database::run(
            "SELECT id, email FROM users
             WHERE email_verified_at IS NULL
               AND recovery_email_sent_at IS NULL
               AND marketing_opt_out = 0
               AND status = 'active'
               AND created_at <= (CURRENT_TIMESTAMP - INTERVAL 3 DAY)
             LIMIT 50"
        )->fetchAll();

        if ($rows === []) {
            @file_put_contents($stateFile, (string) time());
            return 0;
        }

        $sent = 0;
        foreach ($rows as $user) {
            $id    = (int) $user['id'];
            $email = (string) $user['email'];

            $token = \App\Models\User::createVerificationToken($id);
            $verifyUrl = rtrim((string) env_value('APP_URL', ''), '/')
                . '/verify-email?token=' . rawurlencode($token);

            $ok = \App\Core\Mailer::send(
                $email,
                'Complete your ' . config('app.site_name') . ' account',
                "You created an account with " . config('app.site_name') . " a few days ago "
                . "but haven't verified your email yet.\n\n"
                . "Finish setting up by opening this link:\n"
                . $verifyUrl . "\n\n"
                . "If you didn't create this account, you can ignore this email."
            );

            if ($ok) {
                Database::run(
                    'UPDATE users SET recovery_email_sent_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [$id]
                );
                $sent++;
            }
        }

        @file_put_contents($stateFile, (string) time());

        return $sent;
    }

    /**
     * Live media counts matching the dashboard's Photos and Videos cards:
     * media attached to at least one non-deleted gallery.
     *
     * @return array{0: int, 1: int} [photos, videos]
     */
    private static function mediaCounts(): array
    {
        $photos = (int) Database::run(
            'SELECT COUNT(DISTINCT gp.photo_id) AS c
             FROM gallery_photo gp JOIN galleries g ON g.id = gp.gallery_id
             WHERE g.deleted_at IS NULL'
        )->fetch()['c'];

        $videos = (int) Database::run(
            'SELECT COUNT(DISTINCT gp.photo_id) AS c
             FROM gallery_photo gp
             JOIN galleries g ON g.id = gp.gallery_id
             JOIN photos p ON p.id = gp.photo_id
             WHERE g.deleted_at IS NULL AND p.is_video = 1'
        )->fetch()['c'];

        return [$photos, $videos];
    }

    private static function rrmdir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
