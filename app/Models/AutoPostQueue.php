<?php

namespace App\Models;

use App\Core\Database;

/**
 * Queue for the Auto Poster: stores generated post drafts (recommendations
 * built from recent uploads) waiting for the admin to review and publish.
 *
 * A recommendation is a recent photo that has never been queued/posted/
 * dismissed before. Each queue row carries the post text (<= 280 chars), a
 * link back to its source photo (for media + thumbnail) and a platform.
 */
class AutoPostQueue
{
    public const POST_DOMAIN = 'amethyst2213.com';

    /**
     * Recent uploads the queue will propose. Photos already referenced by any
     * queue row (queued, posted, failed or dismissed) are skipped so a photo
     * is only ever suggested once.
     *
     * @return array<int, array{id: int, filename: string, caption: string, is_video: int, views: int, created_at: string, gallery_title: string, gallery_id: int|null, suggested_text: string}>
     */
    public static function recommendations(int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));

        $rows = Database::run(
            "SELECT p.id, p.filename, p.caption, p.is_video, p.views, p.created_at,
                    (SELECT g2.title FROM gallery_photo gc2
                       JOIN galleries g2 ON g2.id = gc2.gallery_id
                      WHERE gc2.photo_id = p.id AND g2.deleted_at IS NULL
                      ORDER BY gc2.gallery_id LIMIT 1) AS gallery_title,
                    (SELECT gc2.gallery_id FROM gallery_photo gc2
                       JOIN galleries g2 ON g2.id = gc2.gallery_id
                      WHERE gc2.photo_id = p.id AND g2.deleted_at IS NULL
                      ORDER BY gc2.gallery_id LIMIT 1) AS gallery_id
             FROM photos p
             WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
               AND NOT EXISTS (SELECT 1 FROM auto_poster_queue q WHERE q.photo_id = p.id)
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT $limit"
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['suggested_text'] = self::buildText($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * Compose the 280-character post text for a photo: starts with the gallery
     * title (when known), adds the photo caption, then a site hashtag line and
     * the site domain so every post links back to the site. Hashtags already
     * in the caption/title are kept.
     */
    public static function buildText(array $photo): string
    {
        $title   = trim((string) ($photo['gallery_title'] ?? ''));
        $caption = trim((string) ($photo['caption'] ?? ''));

        $parts = [];
        if ($title !== '') {
            $parts[] = $title;
        } else {
            $parts[] = 'New upload';
        }
        if ($caption !== '') {
            $parts[] = $caption;
        }

        $text = implode(' — ', $parts);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        $site     = mb_strtolower(trim((string) config('app.site_name', '')));
        $hashtag  = $site !== '' ? '#' . str_replace([' ', '-'], '', ucwords(str_replace(['_', '-'], ' ', $site))) : '#new';
        $url      = ' ' . self::postDomain();

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
     * Domain appended to every generated post so the links always point back
     * to the site, regardless of the environment the queue runs in.
     */
    private static function postDomain(): string
    {
        return self::POST_DOMAIN;
    }

    /**
     * Insert a new queued post for the given photo. Returns the new queue id.
     *
     * @param string|null $text Optional approved/custom post text; when empty
     *                          the standard recommendation is generated.
     */
    public static function enqueue(int $photoId, ?string $text = null): int
    {
        $photo = Database::run(
            'SELECT * FROM photos WHERE id = ? LIMIT 1',
            [$photoId]
        )->fetch();

        if (!$photo) {
            return 0;
        }

        $galleryId = (int) (Photo::firstGalleryId($photoId) ?? 0);

        if ($text === null || trim($text) === '') {
            $text = self::buildText([
                'gallery_title' => $galleryId > 0 ? (string) (Gallery::find($galleryId)['title'] ?? '') : '',
                'caption'       => (string) $photo['caption'],
            ]);
        } else {
            $text = mb_substr(trim($text), 0, 280);
        }

        Database::run(
            'INSERT INTO auto_poster_queue (platform, photo_id, gallery_id, text, status, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            ['twitter', $photoId, $galleryId > 0 ? $galleryId : null, $text, 'queued']
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Queued (awaiting-publish) posts, newest first, joined to their photo for
     * the media + thumbnail the view needs.
     */
    public static function queued(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return Database::run(
            "SELECT q.*, p.filename, p.is_video AS is_photo_video
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             WHERE q.status = 'queued'
             ORDER BY q.id DESC
             LIMIT $limit"
        )->fetchAll();
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
     * Dismiss a queue row so the photo is never recommended again but keeps a
     * record of the decision.
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
     * Dismiss a recommendation directly from a photo id (no queue row yet).
     * Inserts a dismissed row when the photo has none, so it is never offered
     * again. Returns the queue row id or 0.
     */
    public static function dismissPhoto(int $photoId): int
    {
        $existing = (int) Database::run(
            'SELECT COUNT(*) FROM auto_poster_queue WHERE photo_id = ?',
            [$photoId]
        )->fetchColumn();

        if ($existing > 0) {
            Database::run(
                'UPDATE auto_poster_queue SET status = ? WHERE photo_id = ?',
                ['dismissed', $photoId]
            );

            $row = Database::run(
                'SELECT id FROM auto_poster_queue WHERE photo_id = ? ORDER BY id DESC LIMIT 1',
                [$photoId]
            )->fetch();

            return (int) ($row['id'] ?? 0);
        }

        $galleryId = (int) (Photo::firstGalleryId($photoId) ?? 0);
        $photo     = Database::run('SELECT caption FROM photos WHERE id = ? LIMIT 1', [$photoId])->fetch();

        $text = self::buildText([
            'gallery_title' => $galleryId > 0 ? (string) (Gallery::find($galleryId)['title'] ?? '') : '',
            'caption'       => (string) ($photo['caption'] ?? ''),
        ]);

        Database::run(
            'INSERT INTO auto_poster_queue (platform, photo_id, gallery_id, text, status, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            ['twitter', $photoId, $galleryId > 0 ? $galleryId : null, $text, 'dismissed']
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Publish a queued post to its platform right now (used by the admin's
     * "Post now"/"Post queue" action). The source photo's stored file is sent
     * as the media attachment.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function post(int $id): array
    {
        $item = Database::run(
            'SELECT q.*, p.filename
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             WHERE q.id = ? AND q.status = ?
             LIMIT 1',
            [$id, 'queued']
        )->fetch();

        if (!$item) {
            return ['ok' => false, 'error' => 'Queue item not found or already processed.'];
        }

        $media = [];
        if (!empty($item['filename'])) {
            $path = config('app.uploads.dir') . '/' . $item['filename'];

            if (is_file($path)) {
                $media = [[
                    'tmp_name' => $path,
                    'name'     => (string) $item['filename'],
                    'type'     => (string) (mime_content_type($path) ?: 'application/octet-stream'),
                    'size'     => (int) filesize($path),
                ]];
            }
        }

        $config  = AutoPosterConfig::all();
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
            'SELECT q.*, p.filename
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             WHERE q.id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }
}