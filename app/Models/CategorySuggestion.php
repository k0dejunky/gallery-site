<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * AI category suggestions. Each gallery gets at most one row in
 * gallery_category_jobs (the queue + failure surface, claimed by
 * bin/categorize_worker.php) and zero or more rows in
 * gallery_category_suggestions - proposals an admin accepts or dismisses.
 * Accepting MERGES into the gallery's existing categories; nothing here ever
 * writes gallery_category on its own.
 */
class CategorySuggestion
{
    private const MAX_ATTEMPTS = 3;
    private const PER_PAGE     = 20;

    /* ---------------- queue (gallery_category_jobs) ---------------- */

    /**
     * Queue a gallery for analysis unless one is already queued/running or
     * the driver is switched off. Returns true when a job was created.
     */
    public static function enqueue(int $galleryId): bool
    {
        if (self::driver() === 'off') {
            return false;
        }

        $existing = Database::run(
            'SELECT status, attempts FROM gallery_category_jobs WHERE gallery_id = ?',
            [$galleryId]
        )->fetch();

        if ($existing !== false) {
            // Re-queue a finished analysis (new media landed) or a retryable
            // failure; live queued/running jobs are left alone.
            $retryable = ($existing['status'] === 'done')
                || ($existing['status'] === 'error' && (int) $existing['attempts'] < self::MAX_ATTEMPTS);

            if ($retryable) {
                Database::run(
                    "UPDATE gallery_category_jobs SET status = 'queued', error = NULL WHERE gallery_id = ?",
                    [$galleryId]
                );
                return true;
            }

            return false;
        }

        Database::run(
            "INSERT INTO gallery_category_jobs (gallery_id, status, engine) VALUES (?, 'queued', ?)",
            [$galleryId, self::driver()]
        );

        return true;
    }

    /** Claim the oldest queued job for the worker (transactional, like PhotoJob::claimNext). */
    public static function claimNext(): ?int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $job = Database::run(
                'SELECT gallery_id FROM gallery_category_jobs WHERE status = ? ORDER BY updated_at ASC LIMIT 1 FOR UPDATE',
                ['queued']
            )->fetch();
            if ($job === false) {
                $db->commit();
                return null;
            }
            $galleryId = (int) $job['gallery_id'];
            Database::run(
                "UPDATE gallery_category_jobs SET status = 'running', attempts = attempts + 1, error = NULL WHERE gallery_id = ?",
                [$galleryId]
            );
            $db->commit();
            return $galleryId;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public static function complete(int $galleryId, string $engine): void
    {
        Database::run(
            "UPDATE gallery_category_jobs SET status = 'done', engine = ?, error = NULL WHERE gallery_id = ?",
            [$engine, $galleryId]
        );
    }

    public static function fail(int $galleryId, string $error): void
    {
        Database::run(
            "UPDATE gallery_category_jobs SET status = 'error', error = ? WHERE gallery_id = ?",
            [mb_substr($error, 0, 2000), $galleryId]
        );
    }

    /** Return jobs stuck 'running' (worker died mid-run) to the queue. */
    public static function recoverStale(): void
    {
        Database::run(
            "UPDATE gallery_category_jobs SET status = 'queued'
             WHERE status = 'running' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE) AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
        // Retry a failed analysis while attempts remain (a transient outage -
        // Ollama restarting, a timeout - must not strand the gallery until an
        // admin clicks reanalyze). claimNext() bumps attempts, so a
        // permanently failing gallery gives up after MAX_ATTEMPTS.
        Database::run(
            "UPDATE gallery_category_jobs SET status = 'queued', error = NULL
             WHERE status = 'error' AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
    }

    public static function jobFor(int $galleryId): ?array
    {
        return Database::run(
            'SELECT * FROM gallery_category_jobs WHERE gallery_id = ?',
            [$galleryId]
        )->fetch() ?: null;
    }

    /**
     * Queue every gallery that has no categories yet and no job row - the
     * "Analyze uncategorized galleries" backfill. Returns rows queued.
     */
    public static function enqueueUncategorized(int $limit = 200): int
    {
        if (self::driver() === 'off') {
            return 0;
        }

        $result = Database::run(
            "INSERT INTO gallery_category_jobs (gallery_id, status, engine)
             SELECT g.id, 'queued', ?
             FROM galleries g
             WHERE g.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM gallery_category gc WHERE gc.gallery_id = g.id)
               AND NOT EXISTS (SELECT 1 FROM gallery_category_jobs j WHERE j.gallery_id = g.id)
             LIMIT " . max(1, $limit),
            [self::driver()]
        );

        return $result->rowCount();
    }

    /** Counts for the review page header: pending suggestions / errored jobs / queue depth. */
    public static function stats(): array
    {
        $pending = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_category_suggestions WHERE status = 'pending'"
        )->fetchColumn();
        $galleries = (int) Database::run(
            "SELECT COUNT(DISTINCT gallery_id) FROM gallery_category_suggestions WHERE status = 'pending'"
        )->fetchColumn();
        $errors = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_category_jobs WHERE status = 'error'"
        )->fetchColumn();
        $queued = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_category_jobs WHERE status IN ('queued','running')"
        )->fetchColumn();
        $uncategorized = (int) Database::run(
            'SELECT COUNT(*) FROM galleries g
             WHERE g.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM gallery_category gc WHERE gc.gallery_id = g.id)'
        )->fetchColumn();

        return [
            'pending'        => $pending,
            'galleries'      => $galleries,
            'errors'         => $errors,
            'queued'         => $queued,
            'uncategorized'  => $uncategorized,
        ];
    }

    /* ---------------- suggestions ---------------- */

    /** Pending proposals for one gallery (manage page chips). */
    public static function pendingFor(int $galleryId): array
    {
        return Database::run(
            "SELECT s.*, c.name AS category_name
             FROM gallery_category_suggestions s
             INNER JOIN categories c ON c.id = s.category_id
             WHERE s.gallery_id = ? AND s.status = 'pending'
             ORDER BY s.confidence DESC, c.name ASC",
            [$galleryId]
        )->fetchAll();
    }

    /**
     * One page of galleries with pending proposals for the review page.
     * Returns ['rows' => [...], 'page' => n, 'pages' => n, 'total' => n];
     * each row carries the gallery, its cover filename and its proposals.
     */
    public static function pendingPage(int $page = 1): array
    {
        $total = (int) Database::run(
            "SELECT COUNT(DISTINCT gallery_id) FROM gallery_category_suggestions WHERE status = 'pending'"
        )->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $galleryIds = Database::run(
            "SELECT gallery_id
             FROM gallery_category_suggestions
             WHERE status = 'pending'
             GROUP BY gallery_id
             ORDER BY MIN(created_at) DESC
             LIMIT " . self::PER_PAGE . " OFFSET $offset"
        )->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($galleryIds)) {
            return ['rows' => [], 'page' => $page, 'pages' => $pages, 'total' => $total];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $galleries    = Database::run(
            "SELECT * FROM galleries WHERE id IN ($placeholders) ORDER BY id DESC",
            $galleryIds
        )->fetchAll();
        $covers = Gallery::firstPhotos(array_map(static fn (array $g): int => (int) $g['id'], $galleries));

        $suggestions = Database::run(
            "SELECT s.*, c.name AS category_name
             FROM gallery_category_suggestions s
             INNER JOIN categories c ON c.id = s.category_id
             WHERE s.status = 'pending' AND s.gallery_id IN ($placeholders)
             ORDER BY s.confidence DESC, c.name ASC",
            $galleryIds
        )->fetchAll();

        $byGallery = [];
        foreach ($suggestions as $s) {
            $byGallery[(int) $s['gallery_id']][] = $s;
        }

        $analyzedAt = [];
        if ($gids = array_map(static fn (array $g): int => (int) $g['id'], $galleries)) {
            $ph = implode(',', array_fill(0, count($gids), '?'));
            foreach (Database::run(
                "SELECT gallery_id, updated_at FROM gallery_category_jobs WHERE gallery_id IN ($ph)",
                $gids
            )->fetchAll() as $job) {
                $analyzedAt[(int) $job['gallery_id']] = (string) $job['updated_at'];
            }
        }

        $rows = [];
        foreach ($galleries as $g) {
            $gid = (int) $g['id'];
            $rows[] = [
                'gallery'     => $g,
                'cover'       => $covers[$gid]['filename'] ?? null,
                'suggestions' => $byGallery[$gid] ?? [],
                'analyzed_at' => $analyzedAt[$gid] ?? null,
            ];
        }

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Database::run(
            'SELECT * FROM gallery_category_suggestions WHERE id = ?',
            [$id]
        )->fetch() ?: null;
    }

    /**
     * Accept one proposal: MERGE the category into the gallery's current set
     * (Gallery::setCategories REPLACES, so read first), then retire the row.
     */
    public static function accept(int $suggestionId, int $userId): bool
    {
        $s = self::find($suggestionId);
        if ($s === null || $s['status'] !== 'pending') {
            return false;
        }

        $current = array_map(
            static fn (array $c): int => (int) $c['id'],
            Gallery::categories((int) $s['gallery_id'])
        );
        $categoryId = (int) $s['category_id'];
        if (!in_array($categoryId, $current, true)) {
            $current[] = $categoryId;
            Gallery::setCategories((int) $s['gallery_id'], $current);
        }

        Database::run(
            "UPDATE gallery_category_suggestions
             SET status = 'accepted', decided_at = CURRENT_TIMESTAMP, decided_by = ?
             WHERE id = ?",
            [$userId, $suggestionId]
        );

        AuditLog::record(
            $userId,
            'accept',
            'gallery',
            (int) $s['gallery_id'],
            'Accepted AI category suggestion',
            null,
            ['category_id' => $categoryId]
        );

        return true;
    }

    /** Accept every pending proposal for one gallery in a single merge. */
    public static function acceptAll(int $galleryId, int $userId): int
    {
        $pending = self::pendingFor($galleryId);
        if ($pending === []) {
            return 0;
        }

        $current = array_map(
            static fn (array $c): int => (int) $c['id'],
            Gallery::categories($galleryId)
        );
        $added = [];
        foreach ($pending as $s) {
            $cid = (int) $s['category_id'];
            if (!in_array($cid, $current, true)) {
                $current[] = $cid;
                $added[]  = $cid;
            }
        }

        if ($added !== []) {
            Gallery::setCategories($galleryId, $current);
        }

        Database::run(
            "UPDATE gallery_category_suggestions
             SET status = 'accepted', decided_at = CURRENT_TIMESTAMP, decided_by = ?
             WHERE gallery_id = ? AND status = 'pending'",
            [$userId, $galleryId]
        );

        AuditLog::record(
            $userId,
            'accept',
            'gallery',
            $galleryId,
            'Accepted all AI category suggestions (' . count($added) . ' new)',
            null,
            ['category_ids' => $added]
        );

        return count($pending);
    }

    public static function dismiss(int $suggestionId, int $userId): bool
    {
        $s = self::find($suggestionId);
        if ($s === null || $s['status'] !== 'pending') {
            return false;
        }

        Database::run(
            "UPDATE gallery_category_suggestions
             SET status = 'dismissed', decided_at = CURRENT_TIMESTAMP, decided_by = ?
             WHERE id = ?",
            [$userId, $suggestionId]
        );

        AuditLog::record(
            $userId,
            'dismiss',
            'gallery',
            (int) $s['gallery_id'],
            'Dismissed AI category suggestion',
            null,
            ['category_id' => (int) $s['category_id']]
        );

        return true;
    }

    /* ---------------- config ---------------- */

    /** Active driver: 'ollama' | 'api' | 'off'. */
    public static function driver(): string
    {
        $driver = strtolower(env_value('CATEGORIZER_DRIVER', 'ollama'));

        return in_array($driver, ['ollama', 'api', 'off'], true) ? $driver : 'ollama';
    }
}
