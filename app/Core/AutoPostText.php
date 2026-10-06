<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AutoPosterConfig;
use App\Models\AutoPostQueue;

/**
 * Pure text-composition helpers for the Auto Poster: banned-word filtering,
 * per-platform template settings and post-body composition. Kept separate from
 * the queue model so the text rules (editable on the Auto Poster page) live in
 * one small, DB-free class. All methods are static and side-effect free.
 *
 * Formatting is registry-driven: every platform's defaults (pattern, character
 * limit, hashtag style, media rules) come from App\Core\Platforms so each
 * posting method auto-formats correctly the moment it is enabled.
 */
final class AutoPostText
{
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

        $result = preg_replace('/[ \t]{2,}/u', ' ', $result) ?? $result;

        return trim($result);
    }

    /**
     * The per-platform auto-post template settings (editable on the Auto Poster
     * page under each channel's tab), stored in the settings table and filled
     * with that channel's registry defaults whenever a value is missing or out
     * of range — so a newly enabled channel formats correctly with no manual
     * edits.
     *
     * @param string $platform canonical platform key ('x', 'telegram', ...)
     */
    public static function templateSettings(string $platform = 'x'): array
    {
        $platform = self::normalizePlatform($platform);
        $defaults = Platforms::templateDefaults($platform);
        $t = is_array(AutoPosterConfig::all()['template_' . $platform] ?? null) ? AutoPosterConfig::all()['template_' . $platform] : [];
        [$minLength, $maxLength] = Platforms::maxLengthClamp($platform);

        return [
            'pattern'          => self::clampPattern((string) ($t['pattern'] ?? ''), $platform),
            'max_tags'         => self::clampInt($t['max_tags'] ?? null, 0, 60, $defaults['max_tags']),
            'max_length'       => self::clampInt($t['max_length'] ?? null, $minLength, $maxLength, $defaults['max_length']),
            'schedule_minutes' => self::clampInt($t['schedule_minutes'] ?? null, 1, 10080, AutoPostQueue::DEFAULT_SCHEDULE_MINUTES),
            'recent_days'      => self::clampInt($t['recent_days'] ?? null, 1, 90, AutoPostQueue::RECENT_WINDOW_DAYS),
            'max_media'        => self::clampInt($t['max_media'] ?? null, 0, $defaults['media_max'], $defaults['max_media']),
            'blur_percent'     => self::clampInt($t['blur_percent'] ?? null, 0, 100, $defaults['blur_percent']),
            'screenshots'      => self::clampInt($t['screenshots'] ?? null, 1, 4, AutoPostQueue::VIDEO_SCREENSHOTS),
            'banned_words'     => self::parseBannedWords(is_array($t['banned_words'] ?? null) ? implode(',', $t['banned_words']) : (string) ($t['banned_words'] ?? '')),
            'hashtag_style'    => (string) $defaults['hashtag_style'],
            'structure'        => (string) $defaults['structure'],
            'media'            => (bool) $defaults['media'],
            'video'            => (string) $defaults['video'],
            'sensitive'        => (string) $defaults['sensitive'],
        ];
    }

    /**
     * Compose the final post body from a gallery + cleaned hashtags using the
     * platform template settings. Applies the banned-word filter last.
     *
     * $platform tags the {url} placeholder with that channel's attribution
     * code (?c=<platform>&s=...), so click-throughs credit the channel.
     */
    public static function buildText(array $gallery, array $tags = [], ?array $settings = null, string $platform = 'x'): string
    {
        $settings = $settings ?? self::templateSettings('x');
        $keepBreaks = (string) ($settings['structure'] ?? 'single') === 'title_body';

        $pattern = self::composePattern($settings['pattern'], $gallery, $platform);

        // The compact title/description body (pre-hashtags), used to drop a
        // redundant tits/titties hashtag that already appears in the text.
        $mentionedBody = strtolower(
            (string) str_replace('{hashtags}', '', $pattern)
        );

        $cleanTags = self::cleanHashtags($tags, (int) $settings['max_tags'], $mentionedBody);
        $maxLength = (int) $settings['max_length'];
        $style     = (string) ($settings['hashtag_style'] ?? 'hash');

        // Full text first; when it overflows, hashtags are dropped one at a
        // time (cheapest to cut) before any of the pattern text is touched.
        $count = count($cleanTags);
        for ($n = $count; $n >= 0; $n--) {
            $text = self::squashText(
                (string) str_replace('{hashtags}', self::hashtagsBlock(array_slice($cleanTags, 0, $n), $style), $pattern),
                $keepBreaks
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
     * Split a composed post into a {title, body} pair for the title/body
     * platforms (Lemmy, Blogger, PeerTube, ...). The title is the first line
     * (capped at 300 chars); the remainder is the body. Single-body platforms
     * get an empty title and the whole text as the body.
     *
     * @return array{title: string, body: string}
     */
    public static function splitForPlatform(string $text, string $platform = 'x'): array
    {
        $platform = self::normalizePlatform($platform);
        $text     = trim((string) $text);

        if (!Platforms::isTitleBody($platform)) {
            return ['title' => '', 'body' => $text];
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $title = trim((string) array_shift($lines));
        $body  = trim(implode("\n", $lines));

        if ($title === '') {
            $title = 'New upload';
        }

        // Title caps per platform: Lemmy 200, Blogger/PeerTube generous.
        $titleCap = $platform === 'lemmy' ? 200 : 300;
        $title    = mb_substr($title, 0, $titleCap);

        return ['title' => $title, 'body' => $body];
    }

    /**
     * Canonicalise a platform name for the template store. Queue rows use the
     * platform dbKey ('twitter' for x, else the canonical key); template keys
     * are the canonical key. Unknown platforms fall back to 'x'.
     */
    public static function normalizePlatform(string $platform): string
    {
        $canonical = Platforms::canonicalize($platform);

        return $canonical !== '' ? $canonical : 'x';
    }

    /**
     * The banned-word list in effect for the X template (the recommended-posts
     * queue): the configured words when any were saved, otherwise the built-in
     * default.
     *
     * @return list<string>
     */
    private static function bannedWords(): array
    {
        $words = self::templateSettings('x')['banned_words'];

        return $words !== [] ? $words : AutoPostQueue::BANNED_WORDS;
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
     * platform's registry pattern when blank or wonky. The pattern must stay
     * short enough to leave room for real content, so it is capped at 2048.
     */
    private static function clampPattern(string $pattern, string $platform = 'x'): string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            $defaults = Platforms::templateDefaults($platform);
            $pattern  = (string) $defaults['pattern'];
        }

        return $pattern === '' ? AutoPostQueue::DEFAULT_PATTERN : mb_substr($pattern, 0, 2048);
    }

    /**
     * Substitute the {title}, {sep}, {description} and {url} tokens of the
     * pattern with the gallery's content, leaving {hashtags} in place for the
     * caller. {url} expands to the gallery's absolute link carrying the
     * platform's signed attribution code, so every social post is trackable.
     */
    private static function composePattern(string $pattern, array $gallery, string $platform = 'x'): string
    {
        $title       = self::singleSpace((string) ($gallery['gallery_title'] ?? ''));
        $description = self::singleSpace((string) ($gallery['caption'] ?? ''));

        $pattern = str_replace('{title}', $title !== '' ? $title : 'New upload', $pattern);
        $pattern = str_replace('{sep}', $description !== '' ? ' — ' : '', $pattern);
        $pattern = str_replace('{description}', $description, $pattern);

        if (strpos($pattern, '{url}') !== false) {
            $gid = (int) ($gallery['gallery_id'] ?? 0);
            $url = $gid > 0
                ? \App\Models\Traffic::buildUrl('/galleries/' . $gid, self::normalizePlatform($platform))
                : absolute_url('');
            $pattern = str_replace('{url}', $url, $pattern);
        }

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
     * empty string when there are none. Style 'hash' prefixes each tag with '#';
     * any other style (e.g. 'none') renders nothing.
     */
    private static function hashtagsBlock(array $tags, string $style = 'hash'): string
    {
        if ($tags === [] || $style !== 'hash') {
            return '';
        }

        return ' #' . implode(' #', $tags);
    }

    /**
     * Squash whitespace in a piece of text. Single-body platforms collapse
     * everything to single spaces; title/body platforms keep single/two
     * newlines so the first line can become the post title.
     */
    private static function squashText(string $text, bool $keepBreaks): string
    {
        if (!$keepBreaks) {
            return self::singleSpace($text);
        }

        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }

    /**
     * Squash whitespace in a piece of text.
     */
    private static function singleSpace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}