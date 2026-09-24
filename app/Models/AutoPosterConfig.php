<?php

namespace App\Models;

use App\Core\Database;
use DateTimeZone;

/**
 * Persists Auto Poster configuration (Reddit + X/Twitter API credentials and
 * per-platform post templates) in the autoposter_settings key/value table
 * (migrated from storage/autoposter.json, so credentials never live in the
 * repo — storage/autoposter.json remains gitignored). The posting log stays in
 * the database as before.
 *
 * On the first read of an existing install whose table is empty, the legacy
 * storage/autoposter.json is imported once so tokens/templates are preserved.
 */
class AutoPosterConfig
{
    private const TABLE = 'autoposter_settings';

    /**
     * Load the saved credentials. Returns an array with 'reddit' and 'twitter'
     * sub-arrays (each may be empty), the validated 'timezone' and the separate
     * 'template_x' / 'template_reddit' settings used to generate post text per
     * platform (each may be empty, in which case the encoder falls back to its
     * built-in defaults).
     */
    public static function all(): array
    {
        $rows = Database::run(
            'SELECT setting_key, setting_value FROM ' . self::TABLE
        )->fetchAll();

        if ($rows === []) {
            // Empty table: import the legacy credentials file once, then re-read.
            $legacy = self::readLegacy();
            if ($legacy !== null) {
                self::saveAll($legacy);
                $rows = Database::run(
                    'SELECT setting_key, setting_value FROM ' . self::TABLE
                )->fetchAll();
            }
        }

        $data = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['setting_value'] ?? ''), true);
            $data[(string) $row['setting_key']] = $decoded;
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
     * The timezone the scheduler displays and schedules in. The auto-poster
     * has no timezone of its own: it always follows the site-wide timezone
     * set on the Settings page, so every picker and the queue show the same
     * zone the rest of the site uses.
     */
    public static function timezone(): string
    {
        return SiteConfig::timezone();
    }

    /**
     * Persist the credentials. When a template argument is null the
     * corresponding currently saved template is carried over, so a
     * credentials-only save never wipes either platform's post template.
     */
    public static function save(array $reddit, array $twitter, string $timezone = 'UTC', ?array $templateX = null, ?array $templateReddit = null): void
    {
        $current = self::all();

        self::saveAll([
            'reddit'          => $reddit,
            'twitter'         => $twitter,
            'timezone'        => self::validatedTimezone($timezone),
            'template_x'      => $templateX ?? ($current['template_x'] ?? []),
            'template_reddit' => $templateReddit ?? ($current['template_reddit'] ?? []),
        ]);
    }

    /**
     * Persist one platform's auto-post template settings, preserving
     * credentials, the timezone and the other platform's template. Valid
     * platforms: 'x' (alias 'twitter') and 'reddit'.
     */
    public static function saveTemplate(array $template, string $platform = 'x'): void
    {
        $config = self::all();
        $key = strtolower($platform) === 'reddit' ? 'template_reddit' : 'template_x';
        self::put($key, $template);
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

        self::put('reddit', $config['reddit']);
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

        self::put('twitter', $config['twitter']);
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

    /** Upsert a single JSON-encoded key, leaving the rest untouched. */
    private static function put(string $key, $value): void
    {
        Database::run(
            'INSERT INTO ' . self::TABLE . ' (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, json_encode($value, JSON_UNESCAPED_SLASHES)]
        );
    }

    /** Upsert every key of a full settings array at once. */
    private static function saveAll(array $state): void
    {
        foreach ($state as $key => $value) {
            self::put((string) $key, $value);
        }
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
     * The scheduler timezone to use: always the site-wide timezone. Kept as
     * a narrow shim so callers read one consistent source (SiteConfig) and a
     * legacy "timezone" stored value can never reintroduce an unrelated zone.
     */
    private static function effectiveTimezone(?string $stored): string
    {
        return SiteConfig::timezone();
    }

    /**
     * Read the legacy storage/autoposter.json into a settings array, or null
     * when the file is absent/unparseable.
     */
    private static function readLegacy(): ?array
    {
        $path = dirname(__DIR__, 2) . '/storage/autoposter.json';

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}