<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The four questions usability work needs answered.
 *
 * Traffic analytics count hits, visits and addresses. They cannot say whether
 * a search found anything, whether the paywall was seen and then clicked,
 * whether a video was finished, or whether a new chat message got an answer.
 * These events are tiny and append-only, and recording one must never break
 * the page that fired it.
 */
final class FunnelEvent
{
    public const SEARCH          = 'search';
    public const SEARCH_EMPTY    = 'search_empty';
    public const PAYWALL_SEEN    = 'paywall_seen';
    public const UPGRADE_CLICK   = 'upgrade_click';
    public const VIDEO_START     = 'video_start';
    public const VIDEO_FINISH    = 'video_finish';
    public const CHAT_SEND       = 'chat_send';
    public const CHAT_REPLY      = 'chat_reply';

    /** Every event the admin page reports, in reading order. */
    public const ALL = [
        self::SEARCH,
        self::SEARCH_EMPTY,
        self::PAYWALL_SEEN,
        self::UPGRADE_CLICK,
        self::VIDEO_START,
        self::VIDEO_FINISH,
        self::CHAT_SEND,
        self::CHAT_REPLY,
    ];

    private static bool $failed = false;

    /**
     * Record one event. Never throws: a page must not fail because its
     * measuring failed.
     */
    public static function record(string $name, ?int $userId = null, string $meta = ''): void
    {
        if (self::$failed || !in_array($name, self::ALL, true)) {
            return;
        }

        try {
            Database::run(
                'INSERT INTO funnel_events (name, user_id, meta, created_at) VALUES (?, ?, ?, NOW())',
                [$name, $userId !== null && $userId > 0 ? $userId : null, $meta !== '' ? mb_substr($meta, 0, 255) : null]
            );
        } catch (\Throwable $e) {
            // One warning per request at most, then stay quiet for the rest
            // of the process: a missing table must not fill the error log.
            if (!self::$failed) {
                self::$failed = true;
                error_log('[funnel] could not record ' . $name . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Counts per event for a window, newest window first.
     *
     * @return array<string, int>
     */
    public static function counts(int $days = 7): array
    {
        $out = array_fill_keys(self::ALL, 0);

        try {
            $rows = Database::run(
                'SELECT name, COUNT(*) AS n FROM funnel_events
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY name',
                [max(1, min(365, $days))]
            )->fetchAll();
        } catch (\Throwable $e) {
            return $out;
        }

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (isset($out[$name])) {
                $out[$name] = (int) $row['n'];
            }
        }

        return $out;
    }

    /** A short, human label for the admin page. */
    public static function label(string $name): string
    {
        return match ($name) {
            self::SEARCH        => 'Searches',
            self::SEARCH_EMPTY  => 'Searches with no result',
            self::PAYWALL_SEEN  => 'Paywall shown',
            self::UPGRADE_CLICK => 'Upgrade clicked from the paywall',
            self::VIDEO_START   => 'Videos started',
            self::VIDEO_FINISH  => 'Videos finished',
            self::CHAT_SEND     => 'Chat messages sent',
            self::CHAT_REPLY    => 'Chat replies from the operator',
            default             => ucfirst(str_replace('_', ' ', $name)),
        };
    }
}
