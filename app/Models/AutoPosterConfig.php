<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Platforms;
use DateTimeZone;

/**
 * Persists Auto Poster configuration (API credentials per channel and
 * per-platform post templates) in the autoposter_settings key/value table
 * (migrated from storage/autoposter.json, so credentials never live in the
 * repo — storage/autoposter.json remains gitignored). The posting log stays in
 * the database as before.
 *
 * Channels are keyed by their canonical platform key ('x', 'telegram',
 * 'mastodon', ...). 'x' is stored under its legacy dbKey 'twitter' for queue
 * compatibility. On the first read of an existing install whose table is
 * empty, the legacy storage/autoposter.json is imported once so tokens and
 * templates are preserved.
 */
class AutoPosterConfig
{
    private const TABLE = 'autoposter_settings';

    /**
     * Load every saved setting. Returns each stored channel config under its
     * key, the validated 'timezone', the 'enabled_channels' list and the
     * per-platform 'template_<key>' settings (each may be empty, in which case
     * the encoder falls back to its registry defaults).
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

        $out = $data;
        $out['reddit']   = is_array($data['reddit'] ?? null) ? $data['reddit'] : [];
        $out['twitter']  = is_array($data['twitter'] ?? null) ? $data['twitter'] : [];
        $out['timezone'] = self::timezone();
        $out['enabled_channels'] = is_array($data['enabled_channels'] ?? null)
            ? array_values(array_filter(array_map('strval', $data['enabled_channels'])))
            : Platforms::defaultEnabledKeys();
        $out['template_x']      = is_array($data['template_x'] ?? null) ? $data['template_x'] : $legacy;
        $out['template_reddit'] = is_array($data['template_reddit'] ?? null) ? $data['template_reddit'] : $legacy;

        return $out;
    }

    /**
     * The timezone the scheduler displays and schedules in. The auto-poster
     * has no timezone of its own: it always follows the site-wide timezone
     * set on the Settings page.
     */
    public static function timezone(): string
    {
        return SiteConfig::timezone();
    }

    /**
     * The saved credential block for a channel (canonical key or dbKey).
     * Returns an empty array when the platform is unknown.
     */
    public static function channel(string $platform): array
    {
        $canonical = Platforms::canonicalize($platform);
        if ($canonical === '') {
            return [];
        }

        $all   = self::all();
        $dbKey = Platforms::dbKey($canonical);

        $stored = $all[$dbKey] ?? $all[$canonical] ?? [];

        return is_array($stored) ? $stored : [];
    }

    /**
     * Persist a channel's credential/target fields, merging over the current
     * values so a partial save never wipes tokens the form leaves blank.
     */
    public static function saveChannel(string $platform, array $values): void
    {
        $canonical = Platforms::canonicalize($platform);
        if ($canonical === '') {
            return;
        }

        $current = self::channel($canonical);
        $merged  = array_merge($current, $values);
        self::put(Platforms::dbKey($canonical), $merged);
    }

    /**
     * Persist a single token/credential field for a channel (used by OAuth
     * callbacks), preserving everything else.
     */
    public static function saveChannelToken(string $platform, string $field, string $value): void
    {
        $canonical = Platforms::canonicalize($platform);
        if ($canonical === '' || trim($value) === '') {
            return;
        }

        self::saveChannel($canonical, [$field => trim($value)]);
    }

    /**
     * The list of canonical channel keys the admin has enabled (participate in
     * the tabs + queue/refill). Falls back to all registry-enabled channels.
     *
     * @return list<string>
     */
    public static function enabledChannels(): array
    {
        $all = self::all();
        $keys = is_array($all['enabled_channels'] ?? null) ? $all['enabled_channels'] : [];

        $clean = [];
        foreach ($keys as $key) {
            $canonical = Platforms::canonicalize((string) $key);
            if ($canonical !== '' && Platforms::isEnabled($canonical)) {
                $clean[] = $canonical;
            }
        }

        return $clean !== [] ? $clean : Platforms::defaultEnabledKeys();
    }

    /**
     * Persist the enabled-channel selection.
     *
     * @param list<string> $keys canonical channel keys
     */
    public static function setEnabledChannels(array $keys): void
    {
        $clean = [];
        foreach ($keys as $key) {
            $canonical = Platforms::canonicalize((string) $key);
            if ($canonical !== '' && Platforms::isEnabled($canonical) && !in_array($canonical, $clean, true)) {
                $clean[] = $canonical;
            }
        }

        self::put('enabled_channels', $clean);
    }

    /**
     * Persist the legacy two-platform credentials + timezone (used by the
     * original X/Reddit save form and any callers that predate the channel
     * registry). Templates are carried over when null.
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
     * credentials, the timezone and the other platforms' templates. The
     * platform is any canonical key (or its dbKey).
     */
    public static function saveTemplate(array $template, string $platform = 'x'): void
    {
        $canonical = Platforms::canonicalize($platform) ?: 'x';
        self::put('template_' . $canonical, $template);
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
     * The most recent log entries, newest first. Pass a platform (canonical
     * key or dbKey) to show only that channel's entries.
     */
    public static function logEntries(int $limit = 100, ?string $platform = null): array
    {
        // LIMIT cannot take a bound parameter in this PDO/MySQL mode, so inline
        // a clamped integer.
        $limit = max(1, min(500, $limit));
        $where = '1 = 1';
        $bind  = [];

        if ($platform !== null && $platform !== '') {
            $canonical = Platforms::canonicalize($platform) ?: 'x';
            $where     = 'platform = ?';
            $bind[]    = Platforms::dbKey($canonical);
        }

        return Database::run(
            'SELECT * FROM auto_poster_log WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . (int) $limit,
            $bind
        )->fetchAll();
    }

    /**
     * Remove log entries. Pass a platform to clear only that channel's rows.
     */
    public static function clearLog(?string $platform = null): void
    {
        if ($platform !== null && $platform !== '') {
            $canonical = Platforms::canonicalize($platform) ?: 'x';
            Database::run('DELETE FROM auto_poster_log WHERE platform = ?', [Platforms::dbKey($canonical)]);
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
     * Validate a PHP IANA timezone identifier, defaulting to UTC.
     */
    private static function validatedTimezone(string $timezone): string
    {
        if ($timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return 'UTC';
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