<?php

namespace App\Models;

use App\Core\Database;
use DateTimeZone;

/**
 * Persists Auto Poster configuration (Reddit + X/Twitter API credentials) and
 * the posting history. Credentials are stored in a JSON file under storage/
 * (gitignored) rather than the database, so they never travel with the repo.
 * The posting log is kept in the database so it survives config file rewrites.
 */
class AutoPosterConfig
{
    /** Relative path (from the app root) to the JSON credentials file. */
    private const FILE = '/storage/autoposter.json';

    /**
     * The credentials file's absolute path.
     */
    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /**
     * Load the saved credentials. Returns an array with 'reddit' and 'twitter'
     * sub-arrays (each may be empty), the validated 'timezone' and the separate
     * 'template_x' / 'template_reddit' settings used to generate post text per
     * platform (each may be empty, in which case the encoder falls back to its
     * built-in defaults). A legacy single 'template' key is honoured as the
     * starting point for both platforms until each is saved separately.
     */
    public static function all(): array
    {
        $path = self::file();

        if (!is_file($path)) {
            return ['reddit' => [], 'twitter' => [], 'timezone' => self::effectiveTimezone(null), 'template_x' => [], 'template_reddit' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return ['reddit' => [], 'twitter' => [], 'timezone' => self::effectiveTimezone(null), 'template_x' => [], 'template_reddit' => []];
        }

        $legacy = is_array($data['template'] ?? null) ? $data['template'] : [];

        return [
            'reddit'          => is_array($data['reddit'] ?? null) ? $data['reddit'] : [],
            'twitter'         => is_array($data['twitter'] ?? null) ? $data['twitter'] : [],
            'timezone'        => self::effectiveTimezone($data['timezone'] ?? null),
            'template_x'      => is_array($data['template_x'] ?? null) ? $data['template_x'] : $legacy,
            'template_reddit' => is_array($data['template_reddit'] ?? null) ? $data['template_reddit'] : $legacy,
        ];
    }

    /**
     * The timezone the scheduler displays and schedules in. Follows the
     * site-wide timezone set on the Settings page whenever the auto-poster
     * has never been given a timezone of its own (a missing, empty or UTC
     * stored value all mean "not explicitly set"); a non-UTC pick in the
     * auto-poster settings overrides the site zone.
     */
    public static function timezone(): string
    {
        return self::all()['timezone'];
    }

    /**
     * Persist the credentials file. Creates storage/ if needed. When a template
     * argument is null the corresponding currently saved template is carried
     * over, so a credentials-only save never wipes either platform's post
     * template.
     */
    public static function save(array $reddit, array $twitter, string $timezone = 'UTC', ?array $templateX = null, ?array $templateReddit = null): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $current = self::all();

        file_put_contents($path, json_encode([
            'reddit'          => $reddit,
            'twitter'         => $twitter,
            'timezone'        => self::validatedTimezone($timezone),
            'template_x'      => $templateX ?? ($current['template_x'] ?? []),
            'template_reddit' => $templateReddit ?? ($current['template_reddit'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Persist one platform's auto-post template settings, preserving
     * credentials, the timezone and the other platform's template. Valid
     * platforms: 'x' (alias 'twitter') and 'reddit'.
     */
    public static function saveTemplate(array $template, string $platform = 'x'): void
    {
        $config = self::all();

        if (strtolower($platform) === 'reddit') {
            self::save(
                $config['reddit'],
                $config['twitter'],
                (string) $config['timezone'],
                $config['template_x'] ?? [],
                $template
            );

            return;
        }

        self::save(
            $config['reddit'],
            $config['twitter'],
            (string) $config['timezone'],
            $template,
            $config['template_reddit'] ?? []
        );
    }

    /**
     * Validate a PHP IANA timezone identifier, defaulting to UTC. Callers use
     * this so a stale/typoed stored value can never break future scheduling.
     */
    private static function validatedTimezone(string $timezone): string
    {
        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'UTC';
    }

    /**
     * The scheduler timezone to use for a given stored value: an explicit
     * non-UTC pick is honoured as the poster's own override, anything else
     * (missing, empty or UTC) defers to the site-wide timezone.
     */
    private static function effectiveTimezone(?string $stored): string
    {
        $stored = trim((string) $stored);

        if ($stored !== '' && strcasecmp($stored, 'UTC') !== 0) {
            return self::validatedTimezone($stored);
        }

        return SiteConfig::timezone();
    }

    /**
     * Persist the Reddit refresh token (and optionally access token) obtained
     * from the user-authorization callback, preserving all other config.
     */
    public static function saveRedditToken(string $refreshToken, string $accessToken = ''): void
    {
        $config = self::all();

        $config['reddit']['refresh_token'] = $refreshToken;
        if ($accessToken !== '') {
            $config['reddit']['access_token'] = $accessToken;
        }

        self::save($config['reddit'], $config['twitter'], (string) $config['timezone']);
    }

    /**
     * Persist the X (Twitter) refresh token (and optionally access token)
     * obtained from the user-authorization callback, preserving all other
     * config.
     */
    public static function saveTwitterToken(string $refreshToken, string $accessToken = ''): void
    {
        $config = self::all();

        $config['twitter']['refresh_token'] = $refreshToken;
        if ($accessToken !== '') {
            $config['twitter']['access_token'] = $accessToken;
        }

        self::save($config['reddit'], $config['twitter'], (string) $config['timezone']);
    }

    /**
     * Append a row to the auto-poster log and return the new log id.
     */
    public static function log(string $platform, string $target, string $status, string $message, ?int $userId = null): int
    {
        Database::run(
            'INSERT INTO auto_poster_log (platform, target, status, message, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$platform, $target, $status, $message, $userId]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * The most recent log entries, newest first. Pass a platform ('x'/'twitter'
     * or 'reddit') to show only that platform's entries.
     */
    public static function logEntries(int $limit = 100, ?string $platform = null): array
    {
        // LIMIT cannot take a bound parameter in this PDO/MySQL mode, so inline
        // a clamped integer.
        $limit = max(1, min(500, $limit));
        $where = '1 = 1';
        $bind  = [];

        if ($platform !== null && $platform !== '') {
            $platform = $platform === 'x' ? 'twitter' : $platform;
            $where    = 'platform = ?';
            $bind[]   = $platform;
        }

        return Database::run(
            'SELECT * FROM auto_poster_log WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . (int) $limit,
            $bind
        )->fetchAll();
    }

    /**
     * Remove log entries. Pass a platform to clear only that platform's rows.
     */
    public static function clearLog(?string $platform = null): void
    {
        if ($platform !== null && $platform !== '') {
            $platform = $platform === 'x' ? 'twitter' : $platform;
            Database::run('DELETE FROM auto_poster_log WHERE platform = ?', [$platform]);
            return;
        }

        Database::run('DELETE FROM auto_poster_log');
    }
}
