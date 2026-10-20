<?php

declare(strict_types=1);

/**
 * Seeds the category feedback table from what already happened, so the
 * categorizer can learn from the editor's decisions that predate it.
 *
 * Two sources:
 *   1. the proposal rows: 'accepted' is a category the editor kept,
 *      'dismissed' is one they rejected;
 *   2. the audit log: every gallery edit records the categories before and
 *      after, so a category the editor added or took off by hand is a
 *      lesson too - the strongest ones there are.
 *
 * The write is an upsert, so running it twice changes nothing the second
 * time.
 *
 * Usage: php bin/categorize_feedback_backfill.php [--dry-run]
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\CategoryFeedback;

$dryRun = in_array('--dry-run', $argv, true);

$log = static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
};

/* ---------------- 1. the proposal rows ---------------- */

$rows = Database::run(
    "SELECT gallery_id, category_id, confidence, status, decided_by, decided_at
     FROM gallery_category_suggestions
     WHERE status IN ('accepted', 'dismissed')"
)->fetchAll();

$kept = 0;
$removed = 0;

foreach ($rows as $row) {
    $verdict = $row['status'] === 'accepted' ? CategoryFeedback::KEPT : CategoryFeedback::REMOVED;

    if (!$dryRun) {
        CategoryFeedback::record(
            (int) $row['gallery_id'],
            (int) $row['category_id'],
            $verdict,
            'suggestion',
            $row['confidence'] === null ? null : (float) $row['confidence'],
            $row['decided_by'] === null ? null : (int) $row['decided_by']
        );
    }

    if ($verdict === CategoryFeedback::KEPT) {
        $kept++;
    } else {
        $removed++;
    }
}

$log('the proposals: ' . $kept . ' kept, ' . $removed . ' removed');

/* ---------------- 2. the audit log ---------------- */

// The gallery must still exist: an audit row can outlive its gallery.
$edits = Database::run(
    "SELECT l.id, l.entity_id, l.user_id, l.before_json, l.after_json
     FROM admin_logs l
     INNER JOIN galleries g ON g.id = l.entity_id
     WHERE l.entity_type = 'gallery'
       AND l.action = 'update'
       AND l.before_json LIKE '%categories%'
       AND l.after_json LIKE '%categories%'
     ORDER BY l.id ASC"
)->fetchAll();

$added = 0;
$tookOff = 0;
$editCount = 0;

foreach ($edits as $edit) {
    $before = json_decode((string) $edit['before_json'], true);
    $after  = json_decode((string) $edit['after_json'], true);

    if (!is_array($before) || !is_array($after)) {
        continue;
    }

    if (!isset($before['categories']) || !isset($after['categories'])) {
        continue;
    }

    $beforeIds = array_values(array_unique(array_map('intval', (array) $before['categories'])));
    $afterIds  = array_values(array_unique(array_map('intval', (array) $after['categories'])));

    if ($beforeIds === $afterIds) {
        continue;
    }

    $editCount++;
    $userId = $edit['user_id'] === null ? 0 : (int) $edit['user_id'];

    if (!$dryRun) {
        CategoryFeedback::recordDelta((int) $edit['entity_id'], $beforeIds, $afterIds, $userId);
    }

    foreach ($afterIds as $id) {
        if (!in_array($id, $beforeIds, true)) {
            $added++;
        }
    }
    foreach ($beforeIds as $id) {
        if (!in_array($id, $afterIds, true)) {
            $tookOff++;
        }
    }
}

$log('the audit log: ' . $editCount . ' category edits, ' . $added . ' added, ' . $tookOff . ' taken off');

$total = (int) Database::run('SELECT COUNT(*) FROM gallery_category_feedback')->fetchColumn();
$log(($dryRun ? 'would have ' : '') . $total . ' category decisions in the feedback table');
