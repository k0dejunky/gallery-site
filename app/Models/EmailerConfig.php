<?php

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Persists the auto-emailer (newsletter digest) configuration in a JSON file
 * under storage/ (gitignored), mirroring the AutoPosterConfig convention.
 *
 * The schedule is defined by a mode plus a wall-clock time in a chosen
 * timezone:
 *   - hourly:  every N hours
 *   - daily:   every day at HH:MM
 *   - weekly:  every day-of-week at HH:MM
 * Runtime state (last_sent_at, last_sent_photo_id) lives in the same file so
 * the cron-driven worker can tell when a new digest is due and only sends
 * when there is genuinely new media to sample.
 */
class EmailerConfig
{
    /** Relative path (from the app root) to the JSON config file. */
    private const FILE = '/storage/emailer.json';

    /** Supported schedule modes. */
    public const MODES = ['hourly', 'daily', 'weekly'];

    /** How large a single "small sample" of the latest uploads may be. */
    public const MAX_SAMPLE = 12;

    /**
     * The config file's absolute path.
     */
    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /**
     * The defaults every config starts from (and individual fields fall back
     * to when the stored file is missing, invalid or missing a key).
     */
    public static function defaults(): array
    {
        return [
            'enabled'                => false,
            'mode'                   => 'daily',
            'every_hours'            => 6,
            'day_of_week'            => 1,
            'hour'                   => 9,
            'minute'                 => 0,
            'timezone'               => 'UTC',
            'sample_count'           => 6,
            'include_non_subscribers'=> true,
            'subject_subscriber'     => 'New in the {site} gallery — {count} fresh uploads',
            'subject_non_subscriber' => 'A blurred peek at what\'s new on {site}',
            'last_sent_at'           => null,
            'last_sent_photo_id'     => 0,
        ];
    }

    /**
     * Load the saved config, normalising every field so a stale or hand-edited
     * file can never poison the schedule or the worker.
     */
    public static function all(): array
    {
        $path = self::file();

        if (!is_file($path)) {
            return self::defaults();
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return self::defaults();
        }

        $d = self::defaults();

        $d['enabled']                 = !empty($data['enabled']);
        $d['mode']                    = in_array((string) ($data['mode'] ?? ''), self::MODES, true) ? $data['mode'] : $d['mode'];
        $d['every_hours']             = max(1, min(720, (int) ($data['every_hours'] ?? $d['every_hours'])));
        $d['day_of_week']             = max(0, min(6, (int) ($data['day_of_week'] ?? $d['day_of_week'])));
        $d['hour']                    = max(0, min(23, (int) ($data['hour'] ?? $d['hour'])));
        $d['minute']                  = max(0, min(59, (int) ($data['minute'] ?? $d['minute'])));
        $d['timezone']                = self::validatedTimezone((string) ($data['timezone'] ?? 'UTC'));
        $d['sample_count']            = max(1, min(self::MAX_SAMPLE, (int) ($data['sample_count'] ?? $d['sample_count'])));
        $d['include_non_subscribers'] = !empty($data['include_non_subscribers']);
        $d['subject_subscriber']      = trim((string) ($data['subject_subscriber'] ?? $d['subject_subscriber']));
        $d['subject_non_subscriber']  = trim((string) ($data['subject_non_subscriber'] ?? $d['subject_non_subscriber']));

        $lastSent = (string) ($data['last_sent_at'] ?? '');
        $d['last_sent_at'] = self::validatedUtcDatetime($lastSent);
        $d['last_sent_photo_id'] = max(0, (int) ($data['last_sent_photo_id'] ?? 0));

        return $d;
    }

    /**
     * The scheduler timezone (falls back to UTC when unset or invalid).
     */
    public static function timezone(array $config): string
    {
        return self::validatedTimezone((string) ($config['timezone'] ?? 'UTC'));
    }

    /**
     * Persist an admin-submitted config. Preserves runtime state
     * (last_sent_at / last_sent_photo_id) across saves.
     */
    public static function save(array $in, string $timezone = 'UTC'): void
    {
        $current = self::all();
        $d       = self::defaults();

        $d['enabled']                 = !empty($in['enabled']);
        $d['mode']                    = in_array((string) ($in['mode'] ?? ''), self::MODES, true) ? (string) $in['mode'] : 'daily';
        $d['every_hours']             = max(1, min(720, (int) ($in['every_hours'] ?? 6)));
        $d['day_of_week']             = max(0, min(6, (int) ($in['day_of_week'] ?? 1)));
        $d['hour']                    = max(0, min(23, (int) ($in['hour'] ?? 9)));
        $d['minute']                  = max(0, min(59, (int) ($in['minute'] ?? 0)));
        $d['timezone']                = self::validatedTimezone($timezone);
        $d['sample_count']            = max(1, min(self::MAX_SAMPLE, (int) ($in['sample_count'] ?? 6)));
        $d['include_non_subscribers'] = !empty($in['include_non_subscribers']);
        $d['subject_subscriber']      = trim((string) ($in['subject_subscriber'] ?? ''));
        $d['subject_non_subscriber']  = trim((string) ($in['subject_non_subscriber'] ?? ''));
        $d['last_sent_at']            = $current['last_sent_at'];
        $d['last_sent_photo_id']      = $current['last_sent_photo_id'];

        self::write($d);
    }

    /**
     * Record that a digest was generated "now". $newestPhotoId is the id of
     * the newest photo the digest sampled, so the worker can skip re-sending
     * until a new upload arrives.
     */
    public static function markSent(string $utcNow, int $newestPhotoId): void
    {
        $config = self::all();
        $config['last_sent_at']       = self::validatedUtcDatetime($utcNow);
        $config['last_sent_photo_id'] = max(0, $newestPhotoId);
        self::write($config);
    }

    /**
     * The next time a digest is scheduled, expressed in the configured
     * timezone. Never returns a time in the past when the config's clock
     * fields move backwards: the first candidate strictly after the last send
     * (or after now when nothing was ever sent) is returned.
     */
    public static function nextSendAt(array $config, ?DateTimeZone $tz = null): DateTimeImmutable
    {
        $tz = $tz ?? new DateTimeZone(self::timezone($config));

        $last = $config['last_sent_at'];
        $base = is_string($last) && $last !== ''
            ? (new DateTimeImmutable($last, new DateTimeZone('UTC')))->setTimezone($tz)
            : new DateTimeImmutable('now', $tz);

        if ($config['mode'] === 'hourly') {
            return $base->modify('+' . max(1, (int) $config['every_hours']) . ' hours');
        }

        $next = $base->setTime((int) $config['hour'], (int) $config['minute']);
        if ($next <= $base) {
            $next = $next->modify('+1 day');
        }

        if ($config['mode'] === 'weekly') {
            $dow = (int) $config['day_of_week'];
            while ((int) $next->format('w') !== $dow) {
                $next = $next->modify('+1 day');
            }
        }

        return $next;
    }

    /**
     * Whether a digest is due right now, according to the schedule in the
     * configured timezone. Disabled configs are never due.
     */
    public static function due(array $config, ?DateTimeImmutable $now = null): bool
    {
        if (empty($config['enabled'])) {
            return false;
        }

        $tz  = new DateTimeZone(self::timezone($config));
        $now = $now ?? new DateTimeImmutable('now', $tz);

        return $now >= self::nextSendAt($config, $tz);
    }

    /**
     * Validate a PHP IANA timezone identifier, defaulting to UTC.
     */
    public static function validatedTimezone(string $timezone): string
    {
        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'UTC';
    }

    /**
     * Normalise a stored last_sent_at value to a UTC 'Y-m-d H:i:s' string
     * (or null when blank/invalid).
     */
    private static function validatedUtcDatetime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Write the config file, creating storage/ if needed.
     */
    private static function write(array $config): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $path,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}