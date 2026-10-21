<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Scheduled live shows.
 *
 * A show is scheduled BEFORE any broadcast exists - it is the promise
 * "Amethyst will be live at 20:00". The member live page shows a
 * countdown banner until the show starts, and the operator still presses
 * Go Live when they are actually ready.
 *
 * Every time comparison here uses UTC_TIMESTAMP() and not NOW(): the
 * boxes disagree about their MySQL session timezone (the dev box runs six
 * hours behind UTC, the production box is UTC), so a NOW() comparison
 * would fire hours early on one of them. The column itself is stored in
 * UTC for the same reason.
 */
class LiveShow
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_STARTED   = 'started';
    public const STATUS_ENDED     = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public const MAX_TITLE       = 160;
    public const MAX_DESCRIPTION = 1000;
    public const MAX_DURATION    = 600;      // minutes
    public const DEFAULT_DURATION = 60;

    /** Find one show. */
    public static function find(int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM live_shows WHERE id = ? LIMIT 1',
            [$id]
        )->fetch();

        return $row ?: null;
    }

    /**
     * The shows that are still ahead, soonest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function upcoming(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        return Database::run(
            'SELECT * FROM live_shows
             WHERE status = ? AND starts_at > UTC_TIMESTAMP()
             ORDER BY starts_at ASC
             LIMIT ' . (int) $limit,
            [self::STATUS_SCHEDULED]
        )->fetchAll();
    }

    /**
     * The show the countdown banner is counting down to: the next one that
     * has not been cancelled.
     *
     * A show that has already started is still returned (so the banner can
     * say "live now" rather than vanishing), but only while it is
     * reasonably recent - an old show must not pin the banner forever.
     */
    public static function next(): ?array
    {
        $row = Database::run(
            'SELECT * FROM live_shows
             WHERE status IN (?, ?)
               AND starts_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
             ORDER BY starts_at ASC
             LIMIT 1',
            [self::STATUS_SCHEDULED, self::STATUS_STARTED]
        )->fetch();

        return $row ?: null;
    }

    /**
     * Schedule a show.
     *
     * $startsAt is a UTC 'Y-m-d H:i:s' string.
     */
    public static function create(
        string $title,
        string $startsAt,
        int $createdBy,
        ?string $description = null,
        int $durationMinutes = self::DEFAULT_DURATION
    ): int {
        Database::run(
            'INSERT INTO live_shows (title, description, starts_at, duration_minutes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $title,
                $description !== null && $description !== '' ? $description : null,
                $startsAt,
                max(1, min(self::MAX_DURATION, $durationMinutes)),
                self::STATUS_SCHEDULED,
                $createdBy,
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * Cancel a show. A show that already started cannot be cancelled -
     * the operator ends the broadcast for that.
     */
    public static function cancel(int $id): bool
    {
        return Database::run(
            'UPDATE live_shows
             SET status = ?, ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = ?',
            [self::STATUS_CANCELLED, $id, self::STATUS_SCHEDULED]
        )->rowCount() > 0;
    }

    /**
     * Mark a show as started (the operator went live) or ended.
     */
    public static function markStarted(int $id): bool
    {
        return Database::run(
            'UPDATE live_shows
             SET status = ?, started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = ?',
            [self::STATUS_STARTED, $id, self::STATUS_SCHEDULED]
        )->rowCount() > 0;
    }

    public static function markEnded(int $id): bool
    {
        return Database::run(
            'UPDATE live_shows
             SET status = ?, ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status IN (?, ?)',
            [self::STATUS_ENDED, $id, self::STATUS_STARTED, self::STATUS_SCHEDULED]
        )->rowCount() > 0;
    }

    /**
     * Close out the shows that are over, so the list stays tidy and the
     * banner does not show a show from last week.
     */
    public static function closeFinished(): int
    {
        return Database::run(
            'UPDATE live_shows
             SET status = ?, ended_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE status = ? AND starts_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)',
            [self::STATUS_ENDED, self::STATUS_SCHEDULED]
        )->rowCount();
    }

    /**
     * The show as the API and the member page want it: the start time as
     * a UTC timestamp (so every client counts down to the same instant)
     * plus the seconds remaining.
     */
    public static function toApi(?array $show): ?array
    {
        if ($show === null) {
            return null;
        }

        $startsAt = (string) ($show['starts_at'] ?? '');

        try {
            $when = new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'id'          => (int) $show['id'],
            'title'       => (string) ($show['title'] ?? ''),
            'description' => (string) ($show['description'] ?? ''),
            // 'Y-m-d H:i:s' in UTC. The client counts down from this, so a
            // device in another timezone still shows the same remaining time.
            'startsAt'    => $startsAt,
            'startsAtUtc' => $when->format('Y-m-d\TH:i:s\Z'),
            'duration'    => (int) ($show['duration_minutes'] ?? self::DEFAULT_DURATION),
            'status'      => (string) ($show['status'] ?? self::STATUS_SCHEDULED),
            // How long until the show starts, in seconds. Zero once it has.
            'seconds'     => max(0, $when->getTimestamp() - $now->getTimestamp()),
        ];
    }

    /**
     * Validate a start time the operator typed.
     *
     * The operator picks a local time, so the input is interpreted in the
     * site's display timezone and converted to UTC for storage.
     *
     * @return string|null the UTC 'Y-m-d H:i:s', or null when invalid
     */
    public static function normaliseStart(string $input, string $siteTimezone): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        try {
            $tz  = new \DateTimeZone($siteTimezone);
            $when = new \DateTimeImmutable($input, $tz);
        } catch (\Throwable $e) {
            return null;
        }

        // A show in the past is not a schedule, it is a mistake.
        if ($when->getTimestamp() < time() - 300) {
            return null;
        }

        return $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
