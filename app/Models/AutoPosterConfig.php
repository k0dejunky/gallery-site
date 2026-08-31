<?php

namespace App\Models;

use App\Core\Database;

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
     * sub-arrays (each may be empty).
     */
    public static function all(): array
    {
        $path = self::file();

        if (!is_file($path)) {
            return ['reddit' => [], 'twitter' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            return ['reddit' => [], 'twitter' => []];
        }

        return [
            'reddit'  => is_array($data['reddit'] ?? null) ? $data['reddit'] : [],
            'twitter' => is_array($data['twitter'] ?? null) ? $data['twitter'] : [],
        ];
    }

    /**
     * Persist the credentials file. Creates storage/ if needed.
     */
    public static function save(array $reddit, array $twitter): void
    {
        $path = self::file();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode([
            'reddit'  => $reddit,
            'twitter' => $twitter,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        self::save($config['reddit'], $config['twitter']);
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

        self::save($config['reddit'], $config['twitter']);
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
     * The most recent log entries, newest first.
     */
    public static function logEntries(int $limit = 100): array
    {
        // LIMIT cannot take a bound parameter in this PDO/MySQL mode, so inline
        // a clamped integer.
        $limit = max(1, min(500, $limit));

        return Database::run(
            'SELECT * FROM auto_poster_log ORDER BY id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    /**
     * Remove every log entry.
     */
    public static function clearLog(): void
    {
        Database::run('DELETE FROM auto_poster_log');
    }
}
