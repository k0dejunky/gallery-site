<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * AI gallery descriptions. Each gallery gets at most one row in
 * gallery_description_jobs (the queue + failure surface, claimed by
 * bin/describe_worker.php) and zero or more rows in
 * gallery_description_suggestions - proposals an admin accepts (optionally
 * edited) or dismisses. Accepting WRITES galleries.description; nothing here
 * ever does that on its own.
 *
 * Shares the CATEGORIZER_* vision config with the category advisor (one AI
 * backend for the site), so driver() delegates to CategorySuggestion.
 */
class DescriptionSuggestion
{
    private const MAX_ATTEMPTS = 3;
    private const PER_PAGE     = 20;
    /** Hard cap for accepted text - mirrors the manage-page field's limit. */
    public const MAX_LENGTH    = 600;

    /* ---------------- queue (gallery_description_jobs) ---------------- */

    /**
     * Queue a gallery for description generation unless one is already
     * queued/running or the driver is switched off. Returns true when a job
     * was created.
     */
    public static function enqueue(int $galleryId): bool
    {
        if (self::driver() === 'off') {
            return false;
        }

        $existing = Database::run(
            'SELECT status, attempts FROM gallery_description_jobs WHERE gallery_id = ?',
            [$galleryId]
        )->fetch();

        if ($existing !== false) {
            // Re-queue a finished analysis or a retryable failure; live
            // queued/running jobs are left alone.
            $retryable = ($existing['status'] === 'done')
                || ($existing['status'] === 'error' && (int) $existing['attempts'] < self::MAX_ATTEMPTS);

            if ($retryable) {
                Database::run(
                    "UPDATE gallery_description_jobs SET status = 'queued', error = NULL WHERE gallery_id = ?",
                    [$galleryId]
                );
                return true;
            }

            return false;
        }

        // INSERT IGNORE: a folder-import worker uploads the files of an
        // album side by side, and two of them can reach this point together.
        // The second insert would fail on the primary key.
        Database::run(
            "INSERT IGNORE INTO gallery_description_jobs (gallery_id, status, engine) VALUES (?, 'queued', ?)",
            [$galleryId, self::driver()]
        );

        return true;
    }

    /**
     * Enqueue only when the gallery has no description yet - the upload
     * hook, so new sets get a proposal without ever touching written copy.
     */
    public static function enqueueIfEmpty(int $galleryId): bool
    {
        if (self::driver() === 'off') {
            return false;
        }

        $description = Database::run(
            'SELECT description FROM galleries WHERE id = ? AND deleted_at IS NULL',
            [$galleryId]
        )->fetchColumn();

        if ($description === false || trim((string) $description) !== '') {
            return false;
        }

        return self::enqueue($galleryId);
    }

    /** Claim the oldest queued job for the worker (transactional, like CategorySuggestion::claimNext). */
    public static function claimNext(): ?int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $job = Database::run(
                'SELECT gallery_id FROM gallery_description_jobs WHERE status = ? ORDER BY updated_at ASC LIMIT 1 FOR UPDATE',
                ['queued']
            )->fetch();
            if ($job === false) {
                $db->commit();
                return null;
            }
            $galleryId = (int) $job['gallery_id'];
            Database::run(
                "UPDATE gallery_description_jobs SET status = 'running', attempts = attempts + 1, error = NULL WHERE gallery_id = ?",
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
            "UPDATE gallery_description_jobs SET status = 'done', engine = ?, error = NULL WHERE gallery_id = ?",
            [$engine, $galleryId]
        );
    }

    public static function fail(int $galleryId, string $error): void
    {
        Database::run(
            "UPDATE gallery_description_jobs SET status = 'error', error = ? WHERE gallery_id = ?",
            [mb_substr($error, 0, 2000), $galleryId]
        );
    }

    /** Return jobs stuck 'running' (worker died mid-run) to the queue, and retry failures while attempts remain. */
    public static function recoverStale(): void
    {
        Database::run(
            "UPDATE gallery_description_jobs SET status = 'queued'
             WHERE status = 'running' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE) AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
        Database::run(
            "UPDATE gallery_description_jobs SET status = 'queued', error = NULL
             WHERE status = 'error' AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );
    }

    public static function jobFor(int $galleryId): ?array
    {
        return Database::run(
            'SELECT * FROM gallery_description_jobs WHERE gallery_id = ?',
            [$galleryId]
        )->fetch() ?: null;
    }

    /**
     * Queue galleries with no description and no job row - the "Describe
     * galleries without a description" backfill. A gallery the admin already
     * dismissed keeps its job row, so a refill never re-queues it.
     * Returns rows queued.
     */
    public static function enqueueMissing(int $limit = 200): int
    {
        if (self::driver() === 'off') {
            return 0;
        }

        $result = Database::run(
            "INSERT INTO gallery_description_jobs (gallery_id, status, engine)
             SELECT g.id, 'queued', ?
             FROM galleries g
             WHERE g.deleted_at IS NULL
               AND TRIM(COALESCE(g.description, '')) = ''
               AND NOT EXISTS (SELECT 1 FROM gallery_description_jobs j WHERE j.gallery_id = g.id)
             LIMIT " . max(1, $limit),
            [self::driver()]
        );

        return $result->rowCount();
    }

    /** Counts for the review page header. */
    public static function stats(): array
    {
        $pending = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_description_suggestions WHERE status = 'pending'"
        )->fetchColumn();
        $errors = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_description_jobs WHERE status = 'error'"
        )->fetchColumn();
        $queued = (int) Database::run(
            "SELECT COUNT(*) FROM gallery_description_jobs WHERE status IN ('queued','running')"
        )->fetchColumn();
        $missing = (int) Database::run(
            "SELECT COUNT(*) FROM galleries g
             WHERE g.deleted_at IS NULL
               AND TRIM(COALESCE(g.description, '')) = ''
               AND NOT EXISTS (SELECT 1 FROM gallery_description_jobs j WHERE j.gallery_id = g.id)"
        )->fetchColumn();

        return [
            'pending' => $pending,
            'errors'  => $errors,
            'queued'  => $queued,
            'missing' => $missing,
        ];
    }

    /* ---------------- suggestions ---------------- */

    /** The latest pending proposal for one gallery, or null. */
    public static function pendingFor(int $galleryId): ?array
    {
        return Database::run(
            "SELECT * FROM gallery_description_suggestions
             WHERE gallery_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [$galleryId]
        )->fetch() ?: null;
    }

    /**
     * One page of galleries with pending proposals for the review page.
     * Returns ['rows' => [['gallery'=>, 'cover'=>, 'suggestion'=>, 'analyzed_at'=>]], ...].
     */
    public static function pendingPage(int $page = 1): array
    {
        $total = (int) Database::run(
            "SELECT COUNT(DISTINCT gallery_id) FROM gallery_description_suggestions WHERE status = 'pending'"
        )->fetchColumn();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $page   = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $galleryIds = Database::run(
            "SELECT gallery_id
             FROM gallery_description_suggestions
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
            "SELECT * FROM gallery_description_suggestions
             WHERE status = 'pending' AND gallery_id IN ($placeholders)
             ORDER BY id DESC",
            $galleryIds
        )->fetchAll();

        $byGallery = [];
        foreach ($suggestions as $s) {
            $gid = (int) $s['gallery_id'];
            if (!isset($byGallery[$gid])) {
                $byGallery[$gid] = $s;
            }
        }

        $analyzedAt = [];
        $ph = implode(',', array_fill(0, count($galleryIds), '?'));
        foreach (Database::run(
            "SELECT gallery_id, updated_at FROM gallery_description_jobs WHERE gallery_id IN ($ph)",
            $galleryIds
        )->fetchAll() as $job) {
            $analyzedAt[(int) $job['gallery_id']] = (string) $job['updated_at'];
        }

        $rows = [];
        foreach ($galleries as $g) {
            $gid = (int) $g['id'];
            $rows[] = [
                'gallery'     => $g,
                'cover'       => $covers[$gid]['filename'] ?? null,
                'suggestion'  => $byGallery[$gid] ?? null,
                'analyzed_at' => $analyzedAt[$gid] ?? null,
            ];
        }

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Database::run(
            'SELECT * FROM gallery_description_suggestions WHERE id = ?',
            [$id]
        )->fetch() ?: null;
    }

    /**
     * Accept one proposal: write the description onto the gallery (the admin
     * may have edited the textarea, so $text wins when non-empty) and retire
     * the row. Audited like every other gallery write.
     */
    public static function accept(int $suggestionId, int $userId, ?string $text = null): bool
    {
        $s = self::find($suggestionId);
        if ($s === null || $s['status'] !== 'pending') {
            return false;
        }

        $description = trim((string) ($text ?? ''));
        if ($description === '') {
            $description = trim((string) $s['description']);
        }
        if ($description === '') {
            return false;
        }
        $description = mb_substr($description, 0, self::MAX_LENGTH);

        Gallery::updateDescription((int) $s['gallery_id'], $description);

        Database::run(
            "UPDATE gallery_description_suggestions
             SET status = 'accepted', decided_at = CURRENT_TIMESTAMP, decided_by = ?
             WHERE id = ?",
            [$userId, $suggestionId]
        );

        AuditLog::record(
            $userId,
            'accept',
            'gallery',
            (int) $s['gallery_id'],
            'Accepted AI description suggestion',
            null,
            ['description' => mb_substr($description, 0, 200)]
        );

        return true;
    }

    public static function dismiss(int $suggestionId, int $userId): bool
    {
        $s = self::find($suggestionId);
        if ($s === null || $s['status'] !== 'pending') {
            return false;
        }

        Database::run(
            "UPDATE gallery_description_suggestions
             SET status = 'dismissed', decided_at = CURRENT_TIMESTAMP, decided_by = ?
             WHERE id = ?",
            [$userId, $suggestionId]
        );

        AuditLog::record(
            $userId,
            'dismiss',
            'gallery',
            (int) $s['gallery_id'],
            'Dismissed AI description suggestion',
            null,
            null
        );

        return true;
    }

    /* ---------------- config ---------------- */

    /** Active driver - shared with the category advisor (CATEGORIZER_DRIVER). */
    public static function driver(): string
    {
        return CategorySuggestion::driver();
    }
}
