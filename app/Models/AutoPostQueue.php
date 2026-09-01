<?php

namespace App\Models;

use App\Core\Database;

/**
 * Queue for the Auto Poster: stores generated post drafts built from recent
 * gallery uploads, waiting for the admin to review, schedule and publish.
 *
 * A recommendation is one post per recently-updated gallery, carrying 1-4 of
 * its most recent media files (up to 4 images, or a single video, matching X
 * attachment rules). Each queue row keeps the post text (<= 280 chars), the
 * source gallery, its media set (media_ids) and the publish time it awaits
 * (scheduled_at). A cron worker publishes rows once scheduled_at passes.
 */
class AutoPostQueue
{
    public const POST_DOMAIN = 'amethyst2213.com';

    /** Minutes ahead of "now" a newly queued post is scheduled by default. */
    public const DEFAULT_SCHEDULE_MINUTES = 60;

    /** Recent window (days) a gallery must have new uploads in to be offered. */
    public const RECENT_WINDOW_DAYS = 14;

    /**
     * Max media attached per post: X allows 4 images or 1 video (no mixing).
     */
    public const MAX_ATTACHED_MEDIA = 4;

    /**
     * Recent galleries the queue will propose. A gallery with new uploads in
     * the last RECENT_WINDOW_DAYS becomes one recommended post (built from its
     * gallery title/description with up to 4 of its newest files attached).
     * Galleries already referenced by any queue row are skipped so each gallery
     * is only suggested once.
     *
     * @return array<int, array{gallery_id: int, gallery_title: string, gallery_description: string, newest_media_at: string, media: array<int, array{id: int, filename: string, is_video: int, caption: string}>, media_count: int, suggested_text: string, default_scheduled_at: string}>
     */
    public static function recommendations(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));

        $rows = Database::run(
            "SELECT g.id AS gallery_id, g.title AS gallery_title,
                    g.description AS gallery_description,
                    MAX(p.created_at) AS newest_media_at
             FROM galleries g
             JOIN gallery_photo gp ON gp.gallery_id = g.id
             JOIN photos p ON p.id = gp.photo_id
             WHERE g.deleted_at IS NULL
               AND p.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) self::RECENT_WINDOW_DAYS . " DAY)
               AND NOT EXISTS (SELECT 1 FROM auto_poster_queue q WHERE q.gallery_id = g.id)
             GROUP BY g.id
             ORDER BY newest_media_at DESC, g.id DESC
             LIMIT $limit"
        )->fetchAll();

        foreach ($rows as &$row) {
            $media              = self::galleryMedia((int) $row['gallery_id']);
            $row['media']       = $media;
            $row['media_count'] = count($media);
            $row['suggested_text'] = self::buildText([
                'gallery_title' => (string) $row['gallery_title'],
                'caption'       => (string) $row['gallery_description'],
            ]);
            $row['default_scheduled_at'] = self::defaultSchedule();
        }
        unset($row);

        return $rows;
    }

    /**
     * Up to 4 of a gallery's most recent media files for attaching to a post.
     * Only files uploaded within the recent window are candidates. When any of
     * the newest files is a video the post falls back to a single attachment
     * (X does not allow mixing images and videos in one tweet).
     *
     * @return array<int, array{id: int, filename: string, is_video: int, caption: string}>
     */
    public static function galleryMedia(int $galleryId, int $limit = 4): array
    {
        $limit = max(1, min(self::MAX_ATTACHED_MEDIA, $limit));

        $photos = Database::run(
            "SELECT p.id, p.filename, p.is_video, p.caption
             FROM photos p
             JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ?
               AND p.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) self::RECENT_WINDOW_DAYS . " DAY)
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT $limit",
            [$galleryId]
        )->fetchAll();

        foreach ($photos as $photo) {
            if ((int) $photo['is_video'] === 1) {
                return array_slice($photos, 0, 1);
            }
        }

        return $photos;
    }

    /**
     * Compose the 280-character post text for a gallery: starts with the
     * gallery title, adds the gallery description, then a site hashtag line and
     * the site domain so every post links back to the site. Hashtags already in
     * the title/description are kept.
     */
    public static function buildText(array $gallery): string
    {
        $title       = trim((string) ($gallery['gallery_title'] ?? ''));
        $description = trim((string) ($gallery['caption'] ?? ''));

        $parts = [];
        if ($title !== '') {
            $parts[] = $title;
        } else {
            $parts[] = 'New upload';
        }
        if ($description !== '') {
            $parts[] = $description;
        }

        $text = implode(' — ', $parts);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $site    = mb_strtolower(trim((string) config('app.site_name', '')));
        $hashtag = $site !== '' ? '#' . str_replace([' ', '-'], '', ucwords(str_replace(['_', '-'], ' ', $site))) : '#new';
        $url     = ' ' . self::postDomain();

        $maxText = 280 - mb_strlen($hashtag) - mb_strlen($url) - 1;
        if ($maxText < 10) {
            $maxText = 10;
        }

        if (mb_strlen($text) > $maxText) {
            $text = rtrim(mb_substr($text, 0, max(0, $maxText - 1))) . '…';
        }

        return trim($text . ' ' . $hashtag . $url);
    }

    /**
     * Default schedule (a datetime-local string) for the next queued post:
     * DEFAULT_SCHEDULE_MINUTES from now. Used to prefill the admin's schedule
     * field so every post consistently starts with a publish date/time.
     */
    public static function defaultSchedule(?int $from = null): string
    {
        $from = $from ?? time();

        return date('Y-m-d\TH:i', $from + self::DEFAULT_SCHEDULE_MINUTES * 60);
    }

    /**
     * Insert a new queued post for a gallery recommendation. Resolves the
     * gallery's media set, generates the draft text when none was supplied and
     * stores the publish schedule (defaults to DEFAULT_SCHEDULE_MINUTES ahead).
     * Returns the new queue id, or 0 when the gallery is not eligible.
     *
     * @param string|null $scheduledAt Datetime submitted by the admin
     *                                 (Y-m-d\TH:i) — invalid values fall back
     *                                 to the default.
     */
    public static function enqueue(int $galleryId, ?string $text = null, ?string $scheduledAt = null): int
    {
        $gallery = Database::run(
            'SELECT id, title, description FROM galleries
             WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$galleryId]
        )->fetch();

        if (!$gallery) {
            return 0;
        }

        $media = self::galleryMedia($galleryId);
        $mediaIds = array_map(static function (array $photo): int {
            return (int) $photo['id'];
        }, $media);

        if ($text === null || trim($text) === '') {
            $text = self::buildText([
                'gallery_title' => (string) $gallery['title'],
                'caption'       => (string) $gallery['description'],
            ]);
        } else {
            $text = mb_substr(trim($text), 0, 280);
        }

        $scheduled = self::normalizeSchedule($scheduledAt) ?? self::defaultSchedule();

        Database::run(
            'INSERT INTO auto_poster_queue
                (platform, photo_id, gallery_id, media_ids, text, status, created_at, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [
                'twitter',
                !empty($mediaIds) ? $mediaIds[0] : null,
                $galleryId,
                !empty($mediaIds) ? json_encode(array_map(static fn (int $id): int => $id, $mediaIds)) : null,
                $text,
                'queued',
                $scheduled,
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Queued (awaiting-publish) posts, soonest-to-post first, joined to the
     * cover photo and gallery for the media/thumbnail the view needs.
     */
    public static function queued(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::run(
            "SELECT q.*, p.filename, p.is_video AS is_photo_video,
                    COALESCE(g.title, '') AS gallery_title
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             LEFT JOIN galleries g ON g.id = q.gallery_id
             WHERE q.status = 'queued'
             ORDER BY COALESCE(q.scheduled_at, q.created_at) ASC, q.id ASC
             LIMIT $limit"
        )->fetchAll();
    }

    /**
     * Queue rows that are due for auto-publishing: queued posts whose
     * scheduled_at has passed. Rows without a schedule (legacy/unscheduled)
     * are also considered due. Ordered oldest schedule first so the worker
     * publishes in the order the admin scheduled them.
     */
    public static function due(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::run(
            "SELECT q.*
             FROM auto_poster_queue q
             WHERE q.status = 'queued'
               AND (q.scheduled_at IS NULL OR q.scheduled_at <= CURRENT_TIMESTAMP)
             ORDER BY COALESCE(q.scheduled_at, q.created_at) ASC, q.id ASC
             LIMIT $limit"
        )->fetchAll();
    }

    /**
     * The photo rows (filename, is_video, caption) that a queue row attaches:
     * decoded from media_ids, falling back to a single photo_id for legacy rows.
     * Order is the order the media was attached.
     *
     * @return array<int, array{id: int, filename: string, is_video: int, caption: string}>
     */
    public static function mediaFiles(array $item): array
    {
        $ids = json_decode((string) ($item['media_ids'] ?? ''), true);
        if (!is_array($ids) || $ids === []) {
            $ids = !empty($item['photo_id']) ? [(int) $item['photo_id']] : [];
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run(
            "SELECT id, filename, is_video, caption
             FROM photos WHERE id IN ($placeholders)",
            $ids
        )->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Status breakdown for the queue summary cards.
     *
     * @return array{queued: int, posted: int, failed: int, dismissed: int}
     */
    public static function statusCounts(): array
    {
        $rows = Database::run(
            'SELECT status, COUNT(*) AS c FROM auto_poster_queue GROUP BY status'
        )->fetchAll();

        $counts = ['queued' => 0, 'posted' => 0, 'failed' => 0, 'dismissed' => 0];

        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['c'];
            }
        }

        return $counts;
    }

    /**
     * Move a queued post: set (or clear, with an empty/null value) its publish
     * date/time. Returns whether the row was updated.
     */
    public static function reschedule(int $id, ?string $scheduledAt): bool
    {
        if (trim((string) $scheduledAt) === '') {
            $stmt = Database::run(
                'UPDATE auto_poster_queue SET scheduled_at = NULL
                 WHERE id = ? AND status = ?',
                [$id, 'queued']
            );

            return $stmt->rowCount() > 0;
        }

        $scheduled = self::normalizeSchedule($scheduledAt);
        if ($scheduled === null) {
            return false;
        }

        $stmt = Database::run(
            'UPDATE auto_poster_queue SET scheduled_at = ?
             WHERE id = ? AND status = ?',
            [$scheduled, $id, 'queued']
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a queue row posted. Returns true when the row existed.
     */
    public static function markPosted(int $id, string $url): bool
    {
        $row = Database::run(
            'UPDATE auto_poster_queue
             SET status = ?, post_url = ?, error = NULL, posted_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            ['posted', $url, $id]
        );

        return $row->rowCount() > 0;
    }

    /**
     * Mark a queue row failed, keeping the error for the admin to inspect.
     */
    public static function markFailed(int $id, string $error): bool
    {
        $row = Database::run(
            'UPDATE auto_poster_queue
             SET status = ?, error = ?, posted_at = NULL
             WHERE id = ?',
            ['failed', $error, $id]
        );

        return $row->rowCount() > 0;
    }

    /**
     * Dismiss a queue row so its gallery is never recommended again but the
     * decision is still recorded.
     */
    public static function dismiss(int $id): bool
    {
        $row = Database::run(
            'UPDATE auto_poster_queue SET status = ? WHERE id = ?',
            ['dismissed', $id]
        );

        return $row->rowCount() > 0;
    }

    /**
     * Dismiss a recommendation directly from a gallery id (no queue row yet),
     * so the gallery is never offered again. Inserts a dismissed row when the
     * gallery has none. Returns the queue row id or 0.
     */
    public static function dismissGallery(int $galleryId): int
    {
        $gallery = Database::run(
            'SELECT id, title, description FROM galleries
             WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$galleryId]
        )->fetch();

        if (!$gallery) {
            return 0;
        }

        $existing = (int) Database::run(
            'SELECT COUNT(*) FROM auto_poster_queue WHERE gallery_id = ?',
            [$galleryId]
        )->fetchColumn();

        if ($existing > 0) {
            Database::run(
                'UPDATE auto_poster_queue SET status = ? WHERE gallery_id = ?',
                ['dismissed', $galleryId]
            );

            $row = Database::run(
                'SELECT id FROM auto_poster_queue WHERE gallery_id = ? ORDER BY id DESC LIMIT 1',
                [$galleryId]
            )->fetch();

            return (int) ($row['id'] ?? 0);
        }

        $text = self::buildText([
            'gallery_title' => (string) $gallery['title'],
            'caption'       => (string) $gallery['description'],
        ]);

        Database::run(
            'INSERT INTO auto_poster_queue
                (platform, gallery_id, text, status, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)',
            ['twitter', $galleryId, $text, 'dismissed']
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Publish a queued post to its platform right away (admin "Post now", the
     * "Post all queued" action, or the autopost cron worker when a row's
     * schedule comes due). All attached media files from storage/uploads are
     * uploaded along with the text.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function post(int $id): array
    {
        $item = Database::run(
            'SELECT q.* FROM auto_poster_queue q
             WHERE q.id = ? AND q.status = ?
             LIMIT 1',
            [$id, 'queued']
        )->fetch();

        if (!$item) {
            return ['ok' => false, 'error' => 'Queue item not found or already processed.'];
        }

        $media = [];
        foreach (self::mediaFiles($item) as $photo) {
            $path = config('app.uploads.dir') . '/' . $photo['filename'];

            if (is_file($path)) {
                $media[] = [
                    'tmp_name' => $path,
                    'name'     => (string) $photo['filename'],
                    'type'     => (string) (mime_content_type($path) ?: 'application/octet-stream'),
                    'size'     => (int) filesize($path),
                ];
            }
        }

        $config   = AutoPosterConfig::all();
        $platform = $item['platform'];

        $result = match ($platform) {
            'twitter' => (new TwitterClient($config['twitter']))->post((string) $item['text'], $media),
            default   => ['ok' => false, 'error' => 'Unsupported platform: ' . $platform],
        };

        if ($result['ok']) {
            $url = (string) ($result['url'] ?? '');
            self::markPosted((int) $item['id'], $url);
        } else {
            self::markFailed((int) $item['id'], (string) ($result['error'] ?? 'Unknown error'));
            $result['error'] = ($result['error'] ?? 'Unknown error') . ' (logged as failed)';
        }

        AutoPosterConfig::log(
            (string) $platform,
            '',
            $result['ok'] ? 'success' : 'failed',
            $result['ok'] ? ($result['url'] ?? 'Posted') : ($result['error'] ?? 'Unknown error')
        );

        return $result;
    }

    /**
     * Fetch a single queue item by id, or null when missing.
     */
    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT q.*, p.filename, p.is_video AS is_photo_video
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             WHERE q.id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Domain appended to every generated post so the links always point back
     * to the site, regardless of the environment the queue runs in.
     */
    private static function postDomain(): string
    {
        return self::POST_DOMAIN;
    }

    /**
     * Accept an admin datetime (datetime-local sends "Y-m-d\TH:i") and return
     * a MySQL DATETIME string, or null when unparseable.
     */
    private static function normalizeSchedule(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        $v = str_replace('T', ' ', $v);
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
            $timestamp = strtotime($m[1]);
            if ($timestamp === false) {
                return null;
            }

            return date('Y-m-d H:i:s', $timestamp);
        }

        return null;
    }
}