<?php

namespace App\Core;

use App\Models\Gallery;

/**
 * Duplicate-gallery detection. Galleries are "identical" when they reference
 * the same set of photos (photos.hash is unique, so an exact duplicate file
 * is the same photo row — there is no way two galleries can hold byte-identical
 * but separate copies of a media file). A scan groups galleries by their
 * canonical photo-id signature (exact duplicates), then flags near-duplicates
 * (Jaccard similarity ≥ a threshold) and contained pairs (one gallery's media
 * fully inside another's) so re-imports that drifted by a photo or two are
 * still caught.
 *
 * The scan is read-only; removing a duplicate is a separate, admin-confirmed
 * action (soft-delete, which never touches the shared photos).
 */
class DuplicateGalleries
{
    /** Jaccard similarity for near-duplicates (1.0 = exact). */
    public const NEAR_THRESHOLD = 0.8;

    /** Where the last scan report is persisted for the UI + weekly report. */
    public static function stateFile(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs/duplicate-galleries.json';
    }

    /**
     * Run a full scan of active galleries. Returns the persisted report shape:
     *   scanned_at, total_galleries, empty_galleries,
     *   exact:    [ ['photos'=>int, 'galleries'=>[...]] ... ] (identical sets)
     *   near:     [ ['similarity'=>float, 'a'=>g, 'b'=>g] ... ] (Jaccard ≥ threshold, < 1)
     *   contained:[ ['a'=>g, 'b'=>g] ... ] (a's media fully inside b's, b larger)
     * where each gallery g = [id,title,type,min_level,is_secret,published_at,
     * created_at,photo_count,photos] (photos = sorted photo_id list).
     */
    public static function scan(): array
    {
        $rows = Database::run(
            'SELECT g.id, g.title, g.type, g.min_level, g.is_secret, g.published_at, g.created_at,
                    (SELECT COUNT(*) FROM gallery_photo gp WHERE gp.gallery_id = g.id) AS photo_count
             FROM galleries g
             WHERE g.deleted_at IS NULL
             ORDER BY g.id'
        )->fetchAll();

        // photo sets per gallery (sorted photo_id => true).
        $sets = [];
        $linkRows = Database::run('SELECT gallery_id, photo_id FROM gallery_photo')->fetchAll();
        foreach ($linkRows as $link) {
            $sets[(int) $link['gallery_id']][(int) $link['photo_id']] = true;
        }

        $byId      = [];
        $signatures = [];
        $empty     = 0;

        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $set   = $sets[$id] ?? [];
            ksort($set);

            $entry = [
                'id'          => $id,
                'title'       => (string) $row['title'],
                'type'        => (string) $row['type'],
                'min_level'   => (int) $row['min_level'],
                'is_secret'   => (int) $row['is_secret'],
                'published_at'=> $row['published_at'],
                'created_at'  => (string) $row['created_at'],
                'photo_count' => (int) $row['photo_count'],
                'photos'      => array_keys($set),
            ];

            $byId[$id]       = $entry;
            $signatures[$id] = implode(',', $entry['photos']);

            if ($entry['photos'] === []) {
                $empty++;
            }
        }

        // Exact duplicates: identical sorted photo-id signatures.
        $sigGroups = [];
        foreach ($byId as $id => $entry) {
            if ($entry['photos'] === []) {
                continue;
            }
            $sigGroups[$signatures[$id]][] = $id;
        }

        $exact = [];
        foreach ($sigGroups as $sig => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $exact[] = [
                'photos'   => count($byId[$ids[0]]['photos']),
                'galleries' => array_map(static fn (int $id): array => $byId[$id], $ids),
            ];
        }
        usort($exact, static fn (array $a, array $b): int => $b['photos'] <=> $a['photos']);

        // Near-duplicates + contained pairs (one-way superset), all pairs.
        $ids   = array_keys($byId);
        $count = count($ids);
        $near      = [];
        $contained = [];

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $sa = $byId[$a]['photos'];
                $sb = $byId[$b]['photos'];

                if ($sa === [] || $sb === []) {
                    continue;
                }

                $intersection = count(array_intersect($sa, $sb));
                if ($intersection === 0) {
                    continue;
                }

                $union = count($sa) + count($sb) - $intersection;
                $similarity = $intersection / $union;

                if ($similarity >= self::NEAR_THRESHOLD && $similarity < 1.0) {
                    $near[] = [
                        'similarity' => round($similarity, 3),
                        'a'          => $byId[$a],
                        'b'          => $byId[$b],
                    ];
                }

                // Strict containment: every photo of one gallery is in the
                // other, and the other has at least one extra photo.
                $aInB = $intersection === count($sa) && count($sb) > count($sa);
                $bInA = $intersection === count($sb) && count($sa) > count($sb);

                if ($aInB) {
                    $contained[] = ['a' => $byId[$a], 'b' => $byId[$b]];
                } elseif ($bInA) {
                    $contained[] = ['a' => $byId[$b], 'b' => $byId[$a]];
                }
            }
        }

        usort($near, static fn (array $x, array $y): int => $y['similarity'] <=> $x['similarity']);
        usort($contained, static fn (array $x, array $y): int => $y['a']['photo_count'] <=> $x['a']['photo_count']);

        return [
            'scanned_at'     => date('Y-m-d H:i:s'),
            'total_galleries' => count($byId),
            'empty_galleries' => $empty,
            'exact'          => $exact,
            'near'           => $near,
            'contained'      => $contained,
        ];
    }

    /**
     * Return the current report, re-scanning when no persisted report exists
     * (first run). The UI reads this; scan endpoints call scan() + persist().
     */
    public static function report(): array
    {
        $file = self::stateFile();
        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['scanned_at'])) {
                return $decoded;
            }
        }

        $report = self::scan();
        self::persist($report);

        return $report;
    }

    /** Persist a scan result to the state file. */
    public static function persist(array $report): void
    {
        @file_put_contents(
            self::stateFile(),
            json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            LOCK_EX
        );
    }

    /**
     * Drop the persisted report so the next read re-scans. Called whenever a
     * gallery is created, deleted or restored so the duplicate list never
     * shows galleries that were just removed (or hides new duplicates).
     */
    public static function invalidate(): void
    {
        @unlink(self::stateFile());
    }

    /** Number of redundant galleries across all exact duplicate groups. */
    public static function redundantCount(array $report): int
    {
        $redundant = 0;
        foreach ($report['exact'] ?? [] as $group) {
            $redundant += max(0, count($group['galleries']) - 1);
        }

        return $redundant;
    }

    /**
     * Build the human-readable weekly report body. Empty when there is nothing
     * to report (caller skips the email).
     */
    public static function reportBody(array $report): string
    {
        $exact = $report['exact'] ?? [];
        $near  = $report['near'] ?? [];
        $contained = $report['contained'] ?? [];

        if ($exact === [] && $near === [] && $contained === []) {
            return '';
        }

        $lines   = [];
        $lines[] = 'Scanned ' . (int) ($report['total_galleries'] ?? 0) . ' galleries at ' . (string) ($report['scanned_at'] ?? '') . '.';

        if ($exact !== []) {
            $lines[] = '';
            $lines[] = 'Identical galleries (' . count($exact) . ' groups, ' . self::redundantCount($report) . ' redundant):';
            foreach (array_slice($exact, 0, 12) as $group) {
                $names = array_map(
                    static fn (array $g): string => '#' . $g['id'] . ' "' . $g['title'] . '" (' . $g['photo_count'] . ' photos)',
                    $group['galleries']
                );
                $lines[] = '  - ' . implode('  ==  ', $names);
            }
            if (count($exact) > 12) {
                $lines[] = '  … and ' . (count($exact) - 12) . ' more groups.';
            }
        }

        if ($near !== []) {
            $lines[] = '';
            $lines[] = 'Near-duplicates (Jaccard ≥ ' . self::NEAR_THRESHOLD . '):';
            foreach (array_slice($near, 0, 8) as $pair) {
                $lines[] = '  - #' . $pair['a']['id'] . ' "' . $pair['a']['title'] . '" vs #' . $pair['b']['id'] . ' "' . $pair['b']['title']
                    . '" (' . round($pair['similarity'] * 100) . '% shared)';
            }
        }

        if ($contained !== []) {
            $lines[] = '';
            $lines[] = 'Contained (one gallery inside another):';
            foreach (array_slice($contained, 0, 8) as $pair) {
                $lines[] = '  - #' . $pair['a']['id'] . ' "' . $pair['a']['title'] . '" inside #' . $pair['b']['id'] . ' "' . $pair['b']['title'] . '"';
            }
        }

        $lines[] = '';
        $lines[] = 'Review on the gallery management page (Duplicate galleries section) and remove the redundant copies.';

        return implode("\n", $lines);
    }

    /**
     * Emit the weekly duplicate-galleries report email. Gated to once per 7
     * days via the alert state (also enforced by Mailer::adminAlert's long
     * cooldown). No-op when there is nothing to report or no admin email.
     */
    public static function sendWeeklyReport(): void
    {
        $root = dirname(__DIR__, 2);
        $stateFile = $root . '/storage/logs/dup-galleries-weekly.state';
        $last = is_file($stateFile) ? (int) @file_get_contents($stateFile) : 0;

        if (time() - $last < 7 * 86400) {
            return;
        }

        $report = self::scan();
        self::persist($report);

        $body = self::reportBody($report);
        if ($body === '') {
            @file_put_contents($stateFile, (string) time());
            return;
        }

        $subject = 'Duplicate galleries — weekly report';
        Mailer::adminAlert('dup-galleries-weekly', $subject, $body, 7 * 86400);

        @file_put_contents($stateFile, (string) time());
    }
}