<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * What the editor did to the AI's category proposals.
 *
 * The categorizer proposes categories, the editor accepts, dismisses, adds
 * and removes them. Before this table only the accept/dismiss survived (as
 * the status of a proposal row), so the two strongest signals - a category
 * the editor removed by hand and a category the editor added by hand - were
 * thrown away and the AI could not learn from them.
 *
 * One row per (gallery, category): the latest verdict wins, so accepting a
 * proposal and removing it again later leaves a 'removed' verdict.
 *
 * The learning itself lives in CategoryAdvisor: the per-category score
 * (kept / removed / added) and the editor's vocabulary for similar
 * galleries.
 */
class CategoryFeedback
{
    public const KEPT    = 'kept';
    public const REMOVED = 'removed';
    public const ADDED   = 'added';

    /** How many decisions a category needs before its score may act. */
    public const MIN_SAMPLES = 3;

    /* ---------------- writing ---------------- */

    /**
     * Record one verdict about one category of one gallery. The latest
     * verdict wins.
     */
    public static function record(
        int $galleryId,
        int $categoryId,
        string $verdict,
        string $source = 'suggestion',
        ?float $confidence = null,
        ?int $userId = null
    ): void {
        if ($galleryId <= 0 || $categoryId <= 0) {
            return;
        }

        if (!in_array($verdict, [self::KEPT, self::REMOVED, self::ADDED], true)) {
            return;
        }

        // No editor (a script, a backfill) means no user id.
        $userId = ($userId === null || $userId <= 0) ? null : $userId;

        // One row per gallery and category, but every decision counted: the
        // counters are what the score is made of.
        $counter = match ($verdict) {
            self::KEPT    => 'the_kept',
            self::REMOVED => 'the_removed',
            default       => 'the_added',
        };

        Database::run(
            'INSERT INTO gallery_category_feedback
                (gallery_id, category_id, verdict, source, confidence, decided_by, ' . $counter . ')
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                verdict = VALUES(verdict),
                source = VALUES(source),
                confidence = VALUES(confidence),
                decided_by = VALUES(decided_by),
                ' . $counter . ' = ' . $counter . ' + 1,
                updated_at = CURRENT_TIMESTAMP',
            [
                $galleryId,
                $categoryId,
                $verdict,
                $source,
                $confidence,
                $userId,
            ]
        );
    }

    /**
     * Record what an edit did to a gallery's categories.
     *
     * - a category the editor added that the AI never proposed -> 'added'
     *   (the model under-proposes it)
     * - a category the editor took off -> 'removed'
     * - a category the editor kept that the AI had proposed -> 'kept'
     *
     * @param array<int, int> $before the categories before the edit
     * @param array<int, int> $after  the categories after the edit
     */
    public static function recordDelta(int $galleryId, array $before, array $after, int $userId = 0): void
    {
        $before = array_values(array_unique(array_map('intval', $before)));
        $after  = array_values(array_unique(array_map('intval', $after)));

        if ($before === [] && $after === []) {
            return;
        }

        $proposed = self::proposedFor($galleryId);

        foreach ($after as $categoryId) {
            if (in_array($categoryId, $before, true)) {
                // Kept: only a signal when the AI had proposed it.
                if (in_array($categoryId, $proposed, true)) {
                    self::record($galleryId, $categoryId, self::KEPT, 'suggestion', null, $userId);
                }
                continue;
            }

            // Added by hand.
            if (in_array($categoryId, $proposed, true)) {
                // The AI had proposed it and the editor put it back.
                self::record($galleryId, $categoryId, self::KEPT, 'suggestion', null, $userId);
            } else {
                self::record($galleryId, $categoryId, self::ADDED, 'manual', null, $userId);
            }
        }

        foreach ($before as $categoryId) {
            if (in_array($categoryId, $after, true)) {
                continue;
            }
            // Taken off: the strongest negative signal there is.
            self::record(
                $galleryId,
                $categoryId,
                self::REMOVED,
                in_array($categoryId, $proposed, true) ? 'suggestion' : 'manual',
                null,
                $userId
            );
        }
    }

    /* ---------------- reading ---------------- */

    /**
     * The categories the AI has proposed for a gallery (any status).
     *
     * @return array<int, int>
     */
    public static function proposedFor(int $galleryId): array
    {
        return array_map(
            'intval',
            Database::run(
                'SELECT category_id FROM gallery_category_suggestions WHERE gallery_id = ?',
                [$galleryId]
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * The categories the editor removed on this gallery: they must never be
     * proposed there again.
     *
     * @return array<int, int>
     */
    public static function removedFor(int $galleryId): array
    {
        return array_map(
            'intval',
            Database::run(
                "SELECT category_id FROM gallery_category_feedback
                 WHERE gallery_id = ? AND verdict = 'removed'",
                [$galleryId]
            )->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * The per-category score, from one grouped query.
     *
     * score = (kept + 1) / (kept + removed + 2): a Beta(1,1) prior, so a
     * category nobody has ruled on sits at 0.5 and the score only moves as
     * the evidence comes in.
     *
     * @param array<int, int>|null $only the category ids to score
     * @return array<int, array{kept:int, removed:int, added:int, samples:int, score:float}>
     */
    public static function scoreMap(?array $only = null): array
    {
        $where = '';
        $params = [];

        if ($only !== null && $only !== []) {
            $ids = array_values(array_unique(array_map('intval', $only)));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where = " AND category_id IN ($placeholders)";
            $params = $ids;
        }

        $rows = Database::run(
            "SELECT category_id,
                    SUM(the_kept)    AS the_kept,
                    SUM(the_removed) AS the_removed,
                    SUM(the_added)   AS the_added
             FROM gallery_category_feedback
             WHERE 1 = 1$where
             GROUP BY category_id",
            $params
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $kept    = (int) $row['the_kept'];
            $removed = (int) $row['the_removed'];
            $added   = (int) $row['the_added'];
            $samples = $kept + $removed;

            $out[(int) $row['category_id']] = [
                'kept'    => $kept,
                'removed' => $removed,
                'added'   => $added,
                'samples' => $samples,
                'score'   => round(($kept + 1) / ($kept + $removed + 2), 4),
            ];
        }

        return $out;
    }

    /**
     * The categories the editor usually keeps and the ones they usually
     * remove, limited to the given category ids (one batch of the prompt).
     *
     * @param array<int, int> $categoryIds
     * @return array{keep: array<int, string>, remove: array<int, string>}
     */
    public static function preferences(array $categoryIds): array
    {
        $keep   = [];
        $remove = [];

        if ($categoryIds === []) {
            return ['keep' => $keep, 'remove' => $remove];
        }

        $scores = self::scoreMap($categoryIds);
        $names  = self::nameMap($categoryIds);

        foreach ($scores as $id => $score) {
            $name = $names[$id] ?? null;
            if ($name === null) {
                continue;
            }

            if ($score['samples'] >= 2 && $score['score'] >= 0.75) {
                $keep[$id] = $name;
            } elseif ($score['samples'] >= self::MIN_SAMPLES && $score['score'] <= 0.25) {
                $remove[$id] = $name;
            }
        }

        return ['keep' => $keep, 'remove' => $remove];
    }

    /**
     * Should a proposal for this category be dropped? True when the editor
     * has rejected it often enough.
     */
    public static function suppressed(int $categoryId, ?array $scores = null): bool
    {
        $scores = $scores ?? self::scoreMap([$categoryId]);
        $score  = $scores[$categoryId] ?? null;

        if ($score === null) {
            return false;
        }

        return $score['samples'] >= self::MIN_SAMPLES && $score['score'] <= 0.25;
    }

    /**
     * The confidence multiplier for a proposal: a category the editor keeps
     * is worth more, one they drop less.
     */
    public static function weight(int $categoryId, ?array $scores = null): float
    {
        $scores = $scores ?? self::scoreMap([$categoryId]);
        $score  = $scores[$categoryId] ?? null;

        if ($score === null || $score['samples'] < 2) {
            return 1.0;
        }

        return round(0.6 + 0.8 * $score['score'], 4);
    }

    /* ---------------- the editor's vocabulary ---------------- */

    /**
     * The galleries most like this one, from the ones the editor has ruled
     * on. The fulltext of the title and the description comes first, then the
     * shared tags, then the shared categories.
     *
     * @return array<int, int> the gallery ids, the closest first
     */
    public static function similarGalleries(int $galleryId, int $limit = 5): array
    {
        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            return [];
        }

        $limit = max(1, min(20, $limit));

        // 1. the fulltext of the title and the description.
        $terms = trim((string) $gallery['title'] . ' ' . (string) $gallery['description']);
        $query = Gallery::searchQuery($terms);

        if ($query !== '') {
            $rows = Database::run(
                "SELECT g.id
                 FROM galleries g
                 WHERE g.deleted_at IS NULL
                   AND g.id <> ?
                   AND EXISTS (SELECT 1 FROM gallery_category_feedback f WHERE f.gallery_id = g.id)
                   AND MATCH(g.title, g.description) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY MATCH(g.title, g.description) AGAINST (? IN BOOLEAN MODE) DESC
                 LIMIT " . (int) $limit,
                [$galleryId, $query, $query]
            )->fetchAll(\PDO::FETCH_COLUMN);

            if ($rows !== []) {
                return array_map('intval', $rows);
            }
        }

        // 2. the shared tags.
        $tagIds = array_map(
            static fn (array $t): int => (int) $t['id'],
            Tag::forGallery($galleryId)
        );

        if ($tagIds !== []) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $rows = Database::run(
                "SELECT g.id, COUNT(DISTINCT tg.tag_id) AS the_shared
                 FROM galleries g
                 INNER JOIN tag_gallery tg ON tg.gallery_id = g.id
                 WHERE tg.tag_id IN ($placeholders)
                   AND g.id <> ?
                   AND g.deleted_at IS NULL
                   AND EXISTS (SELECT 1 FROM gallery_category_feedback f WHERE f.gallery_id = g.id)
                 GROUP BY g.id
                 ORDER BY the_shared DESC, g.id DESC
                 LIMIT " . (int) $limit,
                array_merge($tagIds, [$galleryId])
            )->fetchAll();

            if ($rows !== []) {
                return array_map('intval', array_column($rows, 'id'));
            }
        }

        // 3. the same kind of gallery with the most categories in common.
        $mine = array_map(
            static fn (array $c): int => (int) $c['id'],
            Gallery::categories($galleryId)
        );

        if ($mine !== []) {
            $placeholders = implode(',', array_fill(0, count($mine), '?'));
            $rows = Database::run(
                "SELECT gc2.gallery_id AS the_id, COUNT(*) AS the_shared
                 FROM gallery_category gc2
                 WHERE gc2.category_id IN ($placeholders)
                   AND gc2.gallery_id <> ?
                   AND EXISTS (SELECT 1 FROM gallery_category_feedback f WHERE f.gallery_id = gc2.gallery_id)
                 GROUP BY gc2.gallery_id
                 ORDER BY the_shared DESC, gc2.gallery_id DESC
                 LIMIT " . (int) $limit,
                array_merge($mine, [$galleryId])
            )->fetchAll();

            if ($rows !== []) {
                return array_map('intval', array_column($rows, 'the_id'));
            }
        }

        return [];
    }

    /**
     * The editor's vocabulary for the given galleries: the categories those
     * galleries carry, weighted by how many of them have it.
     *
     * @param array<int, int> $galleryIds
     * @return array<int, float> the category id => the weight (0..1)
     */
    public static function vocabularyFor(array $galleryIds): array
    {
        $galleryIds = array_values(array_unique(array_map('intval', $galleryIds)));

        if ($galleryIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $rows = Database::run(
            "SELECT category_id, COUNT(*) AS the_count
             FROM gallery_category
             WHERE gallery_id IN ($placeholders)
             GROUP BY category_id",
            $galleryIds
        )->fetchAll();

        $total = max(1, count($galleryIds));
        $out   = [];

        foreach ($rows as $row) {
            $weight = (int) $row['the_count'] / $total;
            if ($weight > 0) {
                $out[(int) $row['category_id']] = round($weight, 4);
            }
        }

        return $out;
    }

    /* ---------------- the admin page ---------------- */

    /**
     * The per-category table for the "what the AI has learned" page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summary(): array
    {
        $rows = Database::run(
            "SELECT f.category_id, c.name,
                    SUM(f.the_kept)    AS the_kept,
                    SUM(f.the_removed) AS the_removed,
                    SUM(f.the_added)   AS the_added,
                    MAX(f.updated_at)   AS the_last
             FROM gallery_category_feedback f
             INNER JOIN categories c ON c.id = f.category_id
             GROUP BY f.category_id, c.name
             ORDER BY (the_kept + the_removed) DESC, c.name ASC"
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $kept    = (int) $row['the_kept'];
            $removed = (int) $row['the_removed'];
            $added   = (int) $row['the_added'];

            $out[] = [
                'category_id' => (int) $row['category_id'],
                'name'        => (string) $row['name'],
                'kept'        => $kept,
                'removed'     => $removed,
                'added'       => $added,
                'samples'     => $kept + $removed,
                'score'       => round(($kept + 1) / ($kept + $removed + 2), 4),
                'last'        => (string) $row['the_last'],
            ];
        }

        return $out;
    }

    /** Forget everything the AI has learned. */
    public static function forget(): int
    {
        $rows = Database::run('SELECT id FROM gallery_category_feedback')->fetchAll();
        Database::run('DELETE FROM gallery_category_feedback');

        return count($rows);
    }

    /**
     * The names of the given category ids.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private static function nameMap(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            "SELECT id, name FROM categories WHERE id IN ($placeholders)",
            $ids
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }
}
