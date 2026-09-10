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

    /**
     * The config file's absolute path.
     */
    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /**
     * Load the saved configuration. Falls back to UTC when the file is
     * missing, unreadable, or holds an invalid timezone.
     *
     * @return array{timezone: string}
     */
    public static function all(): array
    {
        $path = self::file();

        if (!is_file($path)) {
            return ['timezone' => 'UTC'];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return ['timezone' => 'UTC'];
        }

        return ['timezone' => self::validatedTimezone((string) ($data['timezone'] ?? 'UTC'))];
    }

    /**
     * The display timezone (falls back to UTC when unset or invalid).
     */
    public static function timezone(string $default = 'UTC'): string
    {
        return self::all()['timezone'];
    }

    /**
     * Persist the display timezone. Creates storage/ when needed.
     */
    public static function setTimezone(string $timezone): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode([
            'timezone' => self::validatedTimezone($timezone),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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