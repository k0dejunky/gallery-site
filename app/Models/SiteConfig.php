<?php

namespace App\Models;

use DateTimeZone;

/**
 * Persists site-wide configuration (currently the timezone used to display
 * dates and times across the site). Stored as a JSON file under storage/
 * (gitignored) so it needs no schema migration and never travels with the
 * repo, mirroring how the auto-poster keeps its own configuration.
 */
class SiteConfig
{
    /** Relative path (from the app root) to the JSON config file. */
    private const FILE = '/storage/site_config.json';

    /** The config file's absolute path. */
    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /** Defaults for every configurable key. */
    private static function defaults(): array
    {
        return [
            'timezone'          => 'UTC',
            'trial_days'        => 3,
            'age_gate_enabled'  => true,
        ];
    }

    /**
     * Load the saved configuration merged over the defaults. Unknown values
     * fall back to defaults so a malformed file never breaks the site.
     */
    public static function all(): array
    {
        $path = self::file();
        $data = [];

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $merged = array_merge(self::defaults(), $data);
        $merged['timezone'] = self::validatedTimezone((string) ($merged['timezone'] ?? 'UTC'));

        return $merged;
    }

    /** Persist config keys, preserving anything not being written. */
    public static function save(array $config): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $merged = array_merge(self::all(), array_intersect_key($config, self::defaults()));
        file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The display timezone (falls back to UTC when unset or invalid).
     */
    public static function timezone(string $default = 'UTC'): string
    {
        return self::all()['timezone'];
    }

    /**
     * Persist the display timezone (preserves the other config keys).
     */
    public static function setTimezone(string $timezone): void
    {
        self::save(['timezone' => self::validatedTimezone($timezone)]);
    }

    /**
     * Free-trial length in days (default 3, clamped 1-90).
     */
    public static function trialDays(): int
    {
        return max(1, min(90, (int) (self::all()['trial_days'] ?? 3)));
    }

    /**
     * Set the free-trial length in days (clamped 1-90).
     */
    public static function setTrialDays(int $days): void
    {
        self::save(['trial_days' => max(1, min(90, $days))]);
    }

    /**
     * Whether the 18+ entry gate is shown to guests (default on).
     */
    public static function ageGateEnabled(): bool
    {
        return (bool) (self::all()['age_gate_enabled'] ?? true);
    }

    /**
     * Turn the guest 18+ entry gate on or off (members are never gated).
     */
    public static function setAgeGateEnabled(bool $enabled): void
    {
        self::save(['age_gate_enabled' => $enabled]);
    }

    /**
     * Validate a timezone identifier; UTC is returned for anything that is
     * not a real IANA timezone so an unknown value never breaks the site.
     */
    public static function validatedTimezone(string $timezone): string
    {
        $tz = trim($timezone);

        try {
            return (new DateTimeZone($tz))->getName();
        } catch (\Exception $e) {
            return 'UTC';
        }
    }
}