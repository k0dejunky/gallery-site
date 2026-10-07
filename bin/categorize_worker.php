<?php

declare(strict_types=1);

/**
 * AI category-suggestion worker: claims queued galleries from
 * gallery_category_jobs, asks CategoryAdvisor (Ollama vision or an external
 * API) for category proposals, and stores them as pending rows an admin
 * accepts on the manage page or /admin/category-suggestions.
 *
 * Runs every minute from /etc/cron.d ("--once"); a lock file prevents
 * overlapping ticks, and each tick drains up to 5 galleries (each analysis
 * can take seconds-to-minutes on CPU). Writes one line per batch to
 * storage/logs/categorizer.log.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\CategoryAdvisor;
use App\Models\CategorySuggestion;

$once = in_array('--once', $argv, true);

$lockFile = dirname(__DIR__) . '/storage/logs/categorizer.lock';
$logFile  = dirname(__DIR__) . '/storage/logs/categorizer.log';

if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0775, true);
}

$lock = fopen($lockFile, 'c');
if ($lock === false) {
    error_log('[categorizer] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another tick is already running; it will pick up the queue.
    exit(0);
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

const BATCH_LIMIT = 5;

do {
    try {
        if (CategorySuggestion::driver() === 'off') {
            if ($once) {
                $log('driver=off, nothing to do');
            }
            break;
        }

        CategorySuggestion::recoverStale();

        $processed = 0;
        $suggested = 0;
        $failed    = 0;

        while ($processed < BATCH_LIMIT) {
            $galleryId = CategorySuggestion::claimNext();
            if ($galleryId === null) {
                break;
            }
            $processed++;

            $result = CategoryAdvisor::suggest($galleryId);

            if (!empty($result['warnings'])) {
                $log('gallery ' . $galleryId . ': ' . $result['warnings']);
            }

            if (empty($result['ok'])) {
                CategorySuggestion::fail($galleryId, (string) $result['error']);
                $failed++;
                continue;
            }

            // Fresh analysis replaces any not-yet-decided proposals so a
            // re-run never doubles up chips. Accepted/dismissed history stays.
            \App\Core\Database::run(
                "DELETE FROM gallery_category_suggestions WHERE gallery_id = ? AND status = 'pending'",
                [$galleryId]
            );

            foreach ($result['suggestions'] as $s) {
                // ON DUPLICATE only refreshes confidence: a category the
                // admin already accepted or dismissed stays decided instead
                // of being re-proposed on every analysis.
                \App\Core\Database::run(
                    "INSERT INTO gallery_category_suggestions (gallery_id, category_id, confidence, status, engine)
                     VALUES (?, ?, ?, 'pending', ?)
                     ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), engine = VALUES(engine)",
                    [$galleryId, $s['category_id'], $s['confidence'], $result['engine']]
                );
                $suggested++;
            }

            CategorySuggestion::complete($galleryId, (string) $result['engine']);
        }

        if ($processed > 0) {
            $log(sprintf(
                'processed %d gallery(s): %d suggestion row(s), %d failed',
                $processed,
                $suggested,
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
