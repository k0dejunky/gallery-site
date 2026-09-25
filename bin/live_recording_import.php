<?php

declare(strict_types=1);

/**
 * Live recording orphan import: saves any live-stream recordings left in
 * storage/live-recordings/ that belong to a live session but were never
 * finalized (operator app crashed / disconnected without /live/stop).
 *
 * Usage:
 *   php bin/live_recording_import.php        # import + report
 *   php bin/live_recording_import.php --dry-run
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\LiveRecording;

$dryRun = in_array('--dry-run', $argv, true);

$dir = dirname(__DIR__) . '/storage/live-recordings';
$files = glob($dir . '/*.ts') ?: [];

if ($dryRun) {
    echo count($files) . " recording(s) pending\n";
    exit(0);
}

$imported = LiveRecording::importOrphans();
echo "Imported " . $imported . " live recording(s) into galleries.\n";
if ($imported > 0) {
    \App\Core\Cache::bump('gallery');
}