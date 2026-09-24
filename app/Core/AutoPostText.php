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

        $result = preg_replace('/\s{2,}/u', ' ', $result) ?? $result;

        return trim($result);
    }

    /**
     * The per-platform auto-post template settings (editable on the Auto Poster
     * page under the X and Reddit tabs), stored in the config file and filled
     * with the AutoPostQueue defaults whenever a value is missing or out of
     * range.
     *
     * @param string $platform 'x' (or 'twitter') or 'reddit'
     */
    public static function templateSettings(string $platform = 'x'): array
    {
        $platform = self::normalizePlatform($platform);
        $t = is_array(AutoPosterConfig::all()['template_' . $platform] ?? null) ? AutoPosterConfig::all()['template_' . $platform] : [];

        return [
            'pattern'          => self::clampPattern((string) ($t['pattern'] ?? '')),
            'max_tags'         => self::clampInt($t['max_tags'] ?? null, 0, 60, AutoPostQueue::MAX_TAGS),
            'max_length'       => self::clampInt($t['max_length'] ?? null, 50, 280, 280),
            'schedule_minutes' => self::clampInt($t['schedule_minutes'] ?? null, 1, 10080, AutoPostQueue::DEFAULT_SCHEDULE_MINUTES),
            'recent_days'      => self::clampInt($t['recent_days'] ?? null, 1, 90, AutoPostQueue::RECENT_WINDOW_DAYS),
            'max_media'        => self::clampInt($t['max_media'] ?? null, 1, AutoPostQueue::MAX_ATTACHED_MEDIA, AutoPostQueue::MAX_ATTACHED_MEDIA),
            'blur_percent'     => self::clampInt($t['blur_percent'] ?? null, 0, 100, AutoPostQueue::POST_IMAGE_BLUR_PERCENT),
            'screenshots'      => self::clampInt($t['screenshots'] ?? null, 1, 4, AutoPostQueue::VIDEO_SCREENSHOTS),
            'banned_words'     => self::parseBannedWords(is_array($t['banned_words'] ?? null) ? implode(',', $t['banned_words']) : (string) ($t['banned_words'] ?? '')),
        ];
    }

    /**
     * Compose the final post body from a gallery + cleaned hashtags using the
     * platform template settings. Applies the banned-word filter last.
     */
    public static function buildText(array $gallery, array $tags = [], ?array $settings = null): string
    {
        $settings = $settings ?? self::templateSettings('x');

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
     * Canonicalise a platform name for the template store. Queue rows use
     * 'twitter'/'reddit'; the template keys are 'x'/'reddit'.
     */
    public static function normalizePlatform(string $platform): string
    {
        return strtolower($platform) === 'reddit' ? 'reddit' : 'x';
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
     * built-in default pattern when blank or wonky. The pattern must stay short
     * enough to leave room for real content, so it is capped at 2048 chars.
     */
    private static function clampPattern(string $pattern): string
    {
        $pattern = trim($pattern);

        return $pattern === '' ? AutoPostQueue::DEFAULT_PATTERN : mb_substr($pattern, 0, 2048);
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
}