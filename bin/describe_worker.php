<?php

declare(strict_types=1);

/**
 * AI gallery-description worker: claims queued galleries from
 * gallery_description_jobs, asks DescriptionAdvisor (the same Ollama vision
 * or external API backend the categorizer uses) for a short marketing
 * description, and stages it as a pending row an admin accepts (optionally
 * edited) or dismisses on the manage page or /admin/description-suggestions.
 *
 * Runs every minute from /etc/cron.d ("--once"); a lock file prevents
 * overlapping ticks, and each tick drains up to 3 galleries (one vision call
 * each on CPU). Writes one line per batch to storage/logs/describer.log.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Core\DescriptionAdvisor;
use App\Models\DescriptionSuggestion;

$once = in_array('--once', $argv, true);

$lockFile = dirname(__DIR__) . '/storage/logs/describer.lock';
$logFile  = dirname(__DIR__) . '/storage/logs/describer.log';

if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}

$lock = fopen($lockFile, 'c');
if ($lock === false) {
    error_log('[describer] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another tick is already running; it will pick up the queue.
    exit(0);
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

const BATCH_LIMIT = 3;

do {
    try {
        if (DescriptionSuggestion::driver() === 'off') {
            if ($once) {
                $log('driver=off, nothing to do');
            }
            break;
        }

        DescriptionSuggestion::recoverStale();

        $processed = 0;
        $staged    = 0;
        $failed    = 0;

        while ($processed < BATCH_LIMIT) {
            $galleryId = DescriptionSuggestion::claimNext();
            if ($galleryId === null) {
                break;
            }
            $processed++;

            $result = DescriptionAdvisor::describe($galleryId);

            if (!empty($result['warnings'])) {
                $log('gallery ' . $galleryId . ': ' . $result['warnings']);
            }

            if (empty($result['ok'])) {
                DescriptionSuggestion::fail($galleryId, (string) $result['error']);
                $failed++;
                continue;
            }

            // Fresh analysis replaces any not-yet-decided proposal so a
            // re-run never doubles up text. Accepted/dismissed history stays.
            Database::run(
                "DELETE FROM gallery_description_suggestions WHERE gallery_id = ? AND status = 'pending'",
                [$galleryId]
            );
            Database::run(
                "INSERT INTO gallery_description_suggestions (gallery_id, description, status, engine)
                 VALUES (?, ?, 'pending', ?)",
                [$galleryId, $result['description'], $result['engine']]
            );
            $staged++;

            DescriptionSuggestion::complete($galleryId, (string) $result['engine']);
        }

        if ($processed > 0) {
            $log(sprintf(
                'processed %d gallery(s): %d description(s) staged, %d failed',
                $processed,
                $staged,
                $failed
            ));
        }
    } catch (Throwable $error) {
        $log('run failed: ' . $error->getMessage());
    }

    if ($once) {
        break;
    }

    sleep(60);
} while (true);
