<?php

namespace App\Models;

use App\Core\Database;
use DateTime;
use DateTimeZone;

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

    /** Call-to-action appended to every recommended post (keeps the domain). */
    public const POST_CTA = 'come visit my site to see what else I get myself into!! amethyst2213.com';

    /** Up to how many of a gallery's categories become post hashtags. */
    public const MAX_TAGS = 20;

    /** Minutes ahead of "now" a newly queued post is scheduled by default. */
    public const DEFAULT_SCHEDULE_MINUTES = 60;

    /** Recent window (days) a gallery must have new uploads in to be offered. */
    public const RECENT_WINDOW_DAYS = 14;

    /**
     * Max media attached per post: X allows 4 images or 1 video (no mixing).
     */
    public const MAX_ATTACHED_MEDIA = 4;

    /**
     * Attached images are blurred by this percent (0-100) before posting to
     * keep forum-sourced previews low detail. Blur is applied to a throwaway
     * temp copy; source files and web variants are never modified. Videos are
     * untouched.
     */
    public const POST_IMAGE_BLUR_PERCENT = 85;

    /**
     * How many random still frames are captured from a video and posted as
     * blurred preview images (X rejects full-size videos on standard accounts).
     */
    public const VIDEO_SCREENSHOTS = 3;

    /**
     * The editable auto-post template used to generate a recommendation's text.
     * Tokens (all optional):
     *   {title}       the gallery title ("New upload" when it has none)
     *   {sep}         a " — " separator, only when both title and description exist
     *   {description} the gallery description (blank when it has none)
     *   {hashtags}    the gallery's category hashtags, capped by the template's
     *                 max_tags setting
     * Everything else in the pattern is literal text — so the wording and any
     * link/URL in the post are edited here directly.
     */
    public const DEFAULT_PATTERN = '{title}{sep}{description} {hashtags} come visit my site to see what else I get myself into!! amethyst2213.com';

    /**
     * Words that must never appear in a recommended post. They are stripped
     * from the post text and offending category hashtags are dropped, but the
     * gallery itself is still posted. Editable via the template settings
     * (comma-separated); this constant is the fallback default.
     */
    public const BANNED_WORDS = ['nipple', 'nipples'];

    /**
     * Whether a piece of text contains any banned word (case-insensitive,
     * word-boundary aware so "nipples" also matches "nipple").
     */
    public static function containsBannedWord(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach (self::bannedWords() as $word) {
            if (preg_match('/(?<![a-z])' . preg_quote(mb_strtolower($word), '/') . '(?![a-z])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove every banned word from a piece of text so the word never appears
     * in a post (used on the title/description that build the post body).
     */
    public static function stripBannedWords(string $text): string
    {
        $result = $text;

        foreach (self::bannedWords() as $word) {
            $result = preg_replace(
                '/(?<![a-z])' . preg_quote(mb_strtolower($word), '/') . '(?![a-z])/iu',
                '',
                $result
            ) ?? $result;
        }

        $result = preg_replace('/\s{2,}/u', ' ', $result) ?? $result;

        return trim($result);
    }

    /**
     * The auto-post template settings (editable on the Auto Poster page),
     * stored in the config file and filled with the class constants whenever a
     * value is missing or out of range. Everything below is changeable without
     * code edits: the post pattern/wording/link, how many category hashtags are
     * used, the character budget, the default schedule lead time, the recent
     * window, media count, blur strength, video screenshots and banned words.
     */
    public static function templateSettings(): array
    {
        $t = is_array(AutoPosterConfig::all()['template'] ?? null) ? AutoPosterConfig::all()['template'] : [];

        return [
            'pattern'          => self::clampPattern((string) ($t['pattern'] ?? '')),
            'max_tags'         => self::clampInt($t['max_tags'] ?? null, 0, 60, self::MAX_TAGS),
            'max_length'       => self::clampInt($t['max_length'] ?? null, 50, 280, 280),
            'schedule_minutes' => self::clampInt($t['schedule_minutes'] ?? null, 1, 10080, self::DEFAULT_SCHEDULE_MINUTES),
            'recent_days'      => self::clampInt($t['recent_days'] ?? null, 1, 90, self::RECENT_WINDOW_DAYS),
            'max_media'        => self::clampInt($t['max_media'] ?? null, 1, self::MAX_ATTACHED_MEDIA, self::MAX_ATTACHED_MEDIA),
            'blur_percent'     => self::clampInt($t['blur_percent'] ?? null, 0, 100, self::POST_IMAGE_BLUR_PERCENT),
            'screenshots'      => self::clampInt($t['screenshots'] ?? null, 1, 4, self::VIDEO_SCREENSHOTS),
            'banned_words'     => self::parseBannedWords(is_array($t['banned_words'] ?? null) ? implode(',', $t['banned_words']) : (string) ($t['banned_words'] ?? '')),
        ];
    }

    /**
     * The banned-word list in effect: the configured words when any were saved,
     * otherwise the built-in default.
     *
     * @return list<string>
     */
    private static function bannedWords(): array
    {
        $words = self::templateSettings()['banned_words'];

        return $words !== [] ? $words : self::BANNED_WORDS;
    }

    /**
     * Parse a comma/space/newline separated banned-word list, keeping only
     * clean lowercase words (1-40 chars, max 30 of them).
     *
     * @return list<string>
     */
    private static function parseBannedWords(string $raw): array
    {
        $words = preg_split('/[\s,]+/', mb_strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = [];

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || mb_strlen($word) > 40) {
                continue;
            }
            if (!in_array($word, $out, true)) {
                $out[] = $word;
            }
            if (count($out) >= 30) {
                break;
            }
        }

        return $out;
    }

    /**
     * Clamp a numeric template value into $min..$max, falling back to $default
     * when the stored value is missing or not numeric.
     */
    private static function clampInt($value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * The post pattern to compose recommendations from, defaulting to the
     * built-in DEFAULT_PATTERN when blank or wonky. The pattern must stay short
     * enough to leave room for real content, so it is capped at 2048 chars.
     */
    private static function clampPattern(string $pattern): string
    {
        $pattern = trim($pattern);

        return $pattern === '' ? self::DEFAULT_PATTERN : mb_substr($pattern, 0, 2048);
    }

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
               AND p.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) self::templateSettings()['recent_days'] . " DAY)
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
            ], self::categoryHashtags((int) $row['gallery_id']));
            $row['default_scheduled_at'] = self::defaultSchedule();
        }
        unset($row);

        return $rows;
    }

    /**
     * Up to the template's max_media (default MAX_ATTACHED_MEDIA) of a
     * gallery's most recent media files for attaching to a post. Only files
     * uploaded within the recent window are candidates. When any of the newest
     * files is a video the post falls back to a single attachment (X does not
     * allow mixing images and videos in one tweet).
     *
     * @return array<int, array{id: int, filename: string, is_video: int, caption: string}>
     */
    public static function galleryMedia(int $galleryId, ?int $limit = null): array
    {
        $limit = max(1, min((int) self::templateSettings()['max_media'], $limit ?? (int) self::templateSettings()['max_media']));

        $photos = Database::run(
            "SELECT p.id, p.filename, p.is_video, p.caption
             FROM photos p
             JOIN gallery_photo gp ON gp.photo_id = p.id
             WHERE gp.gallery_id = ?
               AND p.created_at >= DATE_SUB(NOW(), INTERVAL " . (int) self::templateSettings()['recent_days'] . " DAY)
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
     * Up to the template's max_tags (default MAX_TAGS) of a gallery's
     * categories as hashtag words (first N in the gallery's category list, so
     * every post carries the site's labels).
     *
     * @return list<string>
     */
    public static function categoryHashtags(int $galleryId, ?int $limit = null): array
    {
        $limit = max(0, min(self::MAX_TAGS, $limit ?? (int) self::templateSettings()['max_tags']));
        $tags  = [];

        foreach (Gallery::categories($galleryId) as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            $tag  = ucwords(str_replace(['-', '_'], ' ', $name));
            $tag  = trim((string) preg_replace('/[^A-Za-z0-9_]/', '', $tag));
            if ($tag === '' || mb_strlen($tag) > 40) {
                continue;
            }
            $tags[] = $tag;
            if (count($tags) >= $limit) {
                break;
            }
        }

        return $tags;
    }

    /**
     * Compose the post text for a gallery from the editable template pattern.
     * Tokens are substituted in place ({title}, {sep}, {description},
     * {hashtags}) and everything else in the pattern — the wording, separators
     * and any link/URL — is kept verbatim, so the admin edits the actual post
     * shape here without code changes. The finished text is bounded by the
     * template's max_length (default 280): the content substitutions are
     * truncated so the pattern (and any trailing link) is always kept.
     */
    public static function buildText(array $gallery, array $tags = [], ?array $settings = null): string
    {
        $settings = $settings ?? self::templateSettings();

        $pattern = self::composePattern($settings['pattern'], $gallery);

        // The compact title/description body (pre-hashtags), used to drop a
        // redundant tits/titties hashtag that already appears in the text.
        $mentionedBody = strtolower(
            (string) str_replace('{hashtags}', '', $pattern)
        );

        $cleanTags = self::cleanHashtags($tags, (int) $settings['max_tags'], $mentionedBody);
        $maxLength = (int) $settings['max_length'];

        // Full text first; when it overflows, hashtags are dropped one at a
        // time (cheapest to cut) before any of the pattern text is touched.
        $count = count($cleanTags);
        for ($n = $count; $n >= 0; $n--) {
            $text = self::singleSpace(
                (string) str_replace('{hashtags}', self::hashtagsBlock(array_slice($cleanTags, 0, $n)), $pattern)
            );

            if (mb_strlen($text) <= $maxLength) {
                break;
            }
        }

        // Still over budget: truncate the tail of the composed text with an
        // ellipsis so the word count is always respected.
        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, max(1, $maxLength - 1))) . '…';
        }

        // Banned-word filter: strip offending words from the post body so the
        // word never appears, while still posting the gallery.
        return trim(self::stripBannedWords($text));
    }

    /**
     * Substitute the {title}, {sep} and {description} tokens of the pattern
     * with the gallery's content, leaving {hashtags} in place for the caller.
     */
    private static function composePattern(string $pattern, array $gallery): string
    {
        $title       = self::singleSpace((string) ($gallery['gallery_title'] ?? ''));
        $description = self::singleSpace((string) ($gallery['caption'] ?? ''));

        $pattern = str_replace('{title}', $title !== '' ? $title : 'New upload', $pattern);
        $pattern = str_replace('{sep}', $description !== '' ? ' — ' : '', $pattern);
        $pattern = str_replace('{description}', $description, $pattern);

        return $pattern;
    }

    /**
     * The hashtags to include in a post: up to $limit cleaned category names,
     * keeping to the X autoposter filter (no banned words; a tits/titties tag
     * becomes "boobs" or is dropped when the word already appears in the body).
     *
     * @return list<string>
     */
    private static function cleanHashtags(array $tags, int $limit, string $mentionedBody): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $tag));
            if ($tag === '' || mb_strlen($tag) > 40) {
                continue;
            }
            $tagLower = mb_strtolower($tag);
            if (self::containsBannedWord($tagLower)) {
                continue;
            }
            $isSensitive = mb_strpos($tagLower, 'tits') !== false
                || mb_strpos($tagLower, 'titties') !== false;
            if ($isSensitive) {
                if (mb_strpos($mentionedBody, 'tits') !== false
                    || mb_strpos($mentionedBody, 'titties') !== false) {
                    continue;
                }
                $tag = 'boobs';
            }
            $clean[] = $tag;
            if (count($clean) >= $limit) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Render a list of tag names as the in-post hashtag text (" #a #b"), or an
     * empty string when there are none.
     */
    private static function hashtagsBlock(array $tags): string
    {
        if ($tags === []) {
            return '';
        }

        return ' #' . implode(' #', $tags);
    }

    /**
     * Squash whitespace in a piece of text.
     */
    private static function singleSpace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Default schedule (a datetime-local string) for the next queued post:
     * DEFAULT_SCHEDULE_MINUTES from now in the scheduler timezone. Used to
     * prefill the admin's schedule field so every post consistently starts
     * with a publish date/time.
     */
    public static function defaultSchedule(?int $from = null): string
    {
        $from = $from ?? time();
        $dt   = (new DateTime('@' . $from))->setTimezone(self::schedulerTimezone());
        $dt->modify('+' . (int) self::templateSettings()['schedule_minutes'] . ' minutes');

        return $dt->format('Y-m-d\TH:i');
    }

    /**
     * Render a stored UTC scheduled_at as a datetime-local string in the
     * scheduler timezone for the admin picker. Missing/invalid values fall
     * back to the default schedule.
     */
    public static function displaySchedule(?string $utc): string
    {
        $value = trim((string) $utc);

        if ($value === '') {
            return self::defaultSchedule();
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        if ($dt === false) {
            return self::defaultSchedule();
        }

        return $dt->setTimezone(self::schedulerTimezone())->format('Y-m-d\TH:i');
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
            ], self::categoryHashtags($galleryId));
        } else {
            $text = mb_substr(trim($text), 0, 280);
        }

        // Banned-word filter: strip offending words from an admin-provided
        // draft too, so the word never appears in the published post (the
        // gallery itself is still queued).
        $text = self::stripBannedWords($text);

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
     * cover photo and gallery for the media/thumbnail the view needs. By
     * default every queued row is returned; pass a positive $limit to cap the
     * number of rows (e.g. for the worker's bounded post-all burst).
     */
    public static function queued(int $limit = 0): array
    {
        $limitSql = $limit > 0 ? ' LIMIT ' . max(1, $limit) : '';

        return Database::run(
            "SELECT q.*, p.filename, p.is_video AS is_photo_video,
                    COALESCE(g.title, '') AS gallery_title
             FROM auto_poster_queue q
             LEFT JOIN photos p ON p.id = q.photo_id
             LEFT JOIN galleries g ON g.id = q.gallery_id
             WHERE q.status = 'queued'
             ORDER BY COALESCE(q.scheduled_at, q.created_at) ASC, q.id ASC"
            . $limitSql
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
     * Most recent failed posts, newest first, joined to their gallery so the
     * admin can see what failed and retry it. Used on the dashboard and the
     * Auto Poster page.
     */
    public static function failed(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::run(
            "SELECT q.*, COALESCE(g.title, '') AS gallery_title
             FROM auto_poster_queue q
             LEFT JOIN galleries g ON g.id = q.gallery_id
             WHERE q.status = 'failed'
             ORDER BY q.id DESC
             LIMIT $limit"
        )->fetchAll();
    }

    /**
     * Move a failed post back into the queue (clearing its error and schedule)
     * so it can be posted again.
     */
    public static function requeue(int $id): bool
    {
        $row = Database::run(
            'UPDATE auto_poster_queue
             SET status = ?, error = NULL, scheduled_at = NULL, posted_at = NULL
             WHERE id = ? AND status = ?',
            ['queued', $id, 'failed']
        );

        return $row->rowCount() > 0;
    }

    /**
     * Recent post history: the newest posted, failed and skipped rows, joined
     * to their gallery so the admin can repost or reschedule them. Dismissed
     * rows are excluded.
     */
    public static function recentPosts(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::run(
            "SELECT q.*, COALESCE(g.title, '') AS gallery_title
             FROM auto_poster_queue q
             LEFT JOIN galleries g ON g.id = q.gallery_id
             WHERE q.status IN ('posted', 'failed', 'skipped')
             ORDER BY COALESCE(q.posted_at, q.created_at) DESC, q.id DESC
             LIMIT $limit"
        )->fetchAll();
    }

    /**
     * Copy a recorded post (posted/failed/skipped row) into a fresh queued
     * row so it can be reposted or rescheduled, preserving its text, media
     * set, gallery and platform. Pass an optional $text to override the stored
     * wording (e.g. to edit a failed post before reposting). An empty or
     * invalid schedule falls back to the standard default (an hour from now),
     * so a reposted/rescheduled row always lands in the queue with a real
     * publish time. Returns the new queue id, or 0 when the source row is
     * missing.
     */
    public static function requeueFrom(int $sourceId, ?string $scheduledAt = null, ?string $text = null): int
    {
        $src = Database::run(
            'SELECT * FROM auto_poster_queue WHERE id = ? LIMIT 1',
            [$sourceId]
        )->fetch();

        if (!$src) {
            return 0;
        }

        // Never default to a NULL schedule: without one the worker treats the
        // row as due immediately (posts "now") and the admin sees a blank
        // schedule in the queue. Default to the standard one-hour-ahead time.
        $scheduled = self::defaultSchedule();
        if (trim((string) $scheduledAt) !== '') {
            $scheduled = self::normalizeSchedule($scheduledAt) ?? self::defaultSchedule();
        }

        $newText = ($text !== null && trim($text) !== '')
            ? mb_substr(trim($text), 0, 280)
            : (string) $src['text'];

        Database::run(
            'INSERT INTO auto_poster_queue
                (platform, photo_id, gallery_id, media_ids, text, status, created_at, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)',
            [
                (string) $src['platform'],
                $src['photo_id'],
                $src['gallery_id'],
                $src['media_ids'],
                $newText,
                'queued',
                $scheduled,
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Whether a queue item's platform has an authorized connection ready for
     * posting. Twitter/Reddit-OAuth require their credentials AND the OAuth
     * user authorization (refresh token). Reddit via the Devvit bridge is
     * authorized when its bridge endpoint + shared secret are configured (it
     * does not need Reddit OAuth credentials). Unauthorized platforms are
     * skipped by the worker instead of being attempted (and failing) every
     * cron tick.
     */
    public static function platformAuthorized(string $platform): bool
    {
        $config = AutoPosterConfig::all();
        $cfg    = $config[$platform] ?? [];

        if ($platform === 'reddit') {
            // Bridge-driven reddit: no OAuth client needed, just the bridge.
            return trim((string) ($cfg['devvit_endpoint'] ?? '')) !== ''
                && trim((string) ($cfg['bridge_secret'] ?? '')) !== ''
                && trim((string) ($cfg['subreddit'] ?? '')) !== '';
        }

        $client = match ($platform) {
            'twitter' => new TwitterClient($cfg),
            default   => null,
        };

        return $client !== null && $client->isConfigured() && $client->isUserAuthorized();
    }

    /**
     * Move a queued post to the skipped state (platform not authorized). The
     * row stays recorded for review but is never attempted again.
     */
    public static function markSkipped(int $id, string $note = ''): bool
    {
        $row = Database::run(
            'UPDATE auto_poster_queue SET status = ?, error = ?, posted_at = NULL WHERE id = ?',
            ['skipped', $note, $id]
        );

        return $row->rowCount() > 0;
    }

    /**
     * Resolve the file to attach to a post for a stored photo. Prefers the
     * web-optimized variant (web_<file>, or its WebP copy) so uploads stay well
     * under X's 5 MB image cap; falls back to the original file when no web
     * variant exists (e.g. videos, which have no web copy). Returns null when
     * no file can be found.
     */
    public static function preferredMediaPath(string $filename): ?string
    {
        $dir = rtrim((string) config('app.uploads.dir'), '/');

        $candidates = [
            $dir . '/web_' . $filename,
            $dir . '/web_' . (string) preg_replace('/\.[^.]+$/', '.webp', $filename),
            $dir . '/' . $filename,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Status breakdown for the queue summary cards.
     *
     * @return array{queued: int, posted: int, failed: int, dismissed: int, skipped: int}
     */
    public static function statusCounts(): array
    {
        $rows = Database::run(
            'SELECT status, COUNT(*) AS c FROM auto_poster_queue GROUP BY status'
        )->fetchAll();

        $counts = ['queued' => 0, 'posted' => 0, 'failed' => 0, 'dismissed' => 0, 'skipped' => 0];

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
        ], self::categoryHashtags($galleryId));

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

        $platform = (string) $item['platform'];

        // Only send to platforms with an authorized API connection. An
        // unauthorized platform is skipped (recorded, never attempted) rather
        // than failed, so the autopost cron stays healthy.
        if (!self::platformAuthorized($platform)) {
            $note = ucfirst($platform) . ' is not authorized — skipped.';
            self::markSkipped((int) $item['id'], $note);
            AutoPosterConfig::log($platform, '', 'skipped', $note);

            return ['ok' => false, 'skipped' => true, 'error' => $note];
        }

        $media    = [];
        $blurredTmp = [];
        foreach (self::mediaFiles($item) as $photo) {
            $path = self::preferredMediaPath((string) $photo['filename']);

            if ($path === null) {
                continue;
            }

            $isVideo = (int) $photo['is_video'] === 1;

            // Images get blurred in a throwaway temp copy (source is never
            // overwritten). Videos are too large for X on a standard account,
            // so a few random frames are captured, blurred with the same
            // preview blur, and posted as images instead of the video file.
            if (!$isVideo) {
                $copy = create_blurred_copy($path, (int) self::templateSettings()['blur_percent']);

                if ($copy !== null) {
                    $blurredTmp[] = $copy;
                    $path         = $copy;
                }
            } else {
                 $frames = self::videoScreenshots($path, (int) self::templateSettings()['screenshots']);

                if ($frames !== []) {
                    foreach ($frames as $frame) {
                        $media[] = [
                            'tmp_name' => $frame,
                            'name'     => basename((string) $frame),
                            'type'     => 'image/jpeg',
                            'size'     => (int) filesize($frame),
                        ];
                        $blurredTmp[] = $frame;
                    }
                    continue;
                }

                // No screenshots could be extracted — fall back to the
                // original file (previous behaviour).
            }

            $media[] = [
                'tmp_name' => $path,
                'name'     => basename((string) $path),
                'type'     => (string) (mime_content_type($path) ?: 'application/octet-stream'),
                'size'     => (int) filesize($path),
            ];
        }

        $config   = AutoPosterConfig::all();

        // A throwing platform client (network error, bad response, encoding
        // failure) must never leave the row sitting 'queued' with no result —
        // it would then be silently published by the next worker run. Record
        // it as failed so it shows up in Recent posts for an explicit retry.
        try {
            $result = match ($platform) {
                'twitter' => (new TwitterClient($config['twitter']))->post((string) $item['text'], $media),
                'reddit'  => RedditBridge::publish((string) $item['text'], $media, $config['reddit']),
                default   => ['ok' => false, 'error' => 'Unsupported platform: ' . $platform],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage() . ' (thrown by the platform client)'];
        }

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

        foreach ($blurredTmp as $tmp) {
            @unlink($tmp);
        }

        return $result;
    }

    /**
     * Capture a few random still frames from a video, blur each with the same
     * preview blur used for images, and return the temp JPEG paths for posting.
     * Frames are scaled so the longest side is at most 1280 px (X images must
     * be under 5 MB). Returns an empty list when ffmpeg is unavailable or the
     * video cannot be decoded.
     *
     * @return list<string>
     */
    private static function videoScreenshots(string $path, int $count = 3): array
    {
        $ffmpeg  = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : null;
        $ffprobe = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : null;

        if ($ffmpeg === null) {
            return [];
        }

        // Probe the duration (seconds) so frames are picked from across the
        // clip rather than always the opening frames.
        $duration = null;
        if ($ffprobe !== null) {
            exec(
                escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
                . escapeshellarg($path) . ' 2>/dev/null',
                $durOut,
                $rc
            );
            if ($rc === 0 && isset($durOut[0]) && is_numeric(trim($durOut[0]))) {
                $duration = (float) trim($durOut[0]);
            }
        }

        $count = max(1, min(4, $count));
        $times = [];

        if ($duration !== null && $duration > 3) {
            $lo = max(0.5, $duration * 0.05);
            $hi = max($lo + 1, $duration * 0.95);

            for ($i = 0; $i < $count; $i++) {
                $times[] = $lo + (($hi - $lo) * (mt_rand(0, 10000) / 10000));
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $times[] = max(0.2, 0.6 + $i * 0.9);
            }
        }

        $frames = [];

        foreach ($times as $t) {
            $tmp = tempnam(sys_get_temp_dir(), 'xframe') . '.jpg';

            $cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error -ss ' . escapeshellarg((string) $t)
                . ' -i ' . escapeshellarg($path)
                . ' -frames:v 1 -q:v 3 -vf scale=1280:-2:force_original_aspect_ratio=decrease '
                . escapeshellarg($tmp) . ' 2>/dev/null';

            exec($cmd, $o, $rc);

            if ($rc === 0 && is_file($tmp) && (int) filesize($tmp) > 0) {
                $blurred = create_blurred_copy($tmp, (int) self::templateSettings()['blur_percent']);
                @unlink($tmp);

                if ($blurred !== null) {
                    $frames[] = $blurred;
                }
            } else {
                @unlink($tmp);
            }
        }

        return $frames;
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
     * Accept an admin datetime (datetime-local sends "Y-m-d\TH:i") and return
     * a MySQL DATETIME string in UTC, or null when unparseable. The picker
     * value is interpreted in the scheduler timezone, so 9:10 PM in the site's
     * timezone is stored as the correct UTC instant.
     */
    private static function normalizeSchedule(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        $v = str_replace('T', ' ', $v);
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
            $parsed = $m[1] . (isset($m[2]) ? $m[2] : ':00');
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $parsed, self::schedulerTimezone());
            if ($dt === false) {
                return null;
            }

            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return null;
    }

    /**
     * The timezone the admin schedules in (site setting, UTC by default).
     */
    private static function schedulerTimezone(): DateTimeZone
    {
        return new DateTimeZone(AutoPosterConfig::timezone());
    }
}