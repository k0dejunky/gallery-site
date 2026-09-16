<?php

namespace App\Core;

use PDO;

/**
 * Lazy singleton wrapper around PDO. The connection is opened once and reused
 * so pages do not pay the connection cost on every query.
 */
class Database
{
    private static ?PDO $pdo = null;

    /** In-memory record of queries that exceeded the slow threshold. */
    private static array $slowQueries = [];

    /**
     * Queries slower than this (seconds) are recorded and logged.
     */
    private const SLOW_THRESHOLD = 1.0;

    /**
     * Persisted slow-query ring buffer (capped). Kept on disk so the System
     * page can show slow queries across ALL requests (not just the request
     * that rendered the page). Bounded to avoid unbounded growth.
     */
    private const SLOW_LOG_MAX = 50;

    private static function slowLogPath(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/slow-queries.json';
    }

    /**
     * Get the shared PDO connection, configuring errors to throw exceptions
     * and rows to return as associative arrays. Enables SQLite foreign keys.
     */
    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../../config/database.php';

            $dsn = self::buildDsn($config);

            self::$pdo = new PDO(
                $dsn,
                $config['driver'] === 'sqlite' ? null : $config['username'],
                $config['driver'] === 'sqlite' ? null : $config['password'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            if ($config['driver'] === 'sqlite') {
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            }
        }

        return self::$pdo;
    }

    /**
     * Prepare and execute a parameterised query. Always pass values as
     * parameters (never interpolated) so input cannot change the query.
     * Queries that take longer than the slow threshold are recorded and
     * logged so regressions (e.g. missing indexes, N+1 loops) surface in the
     * System page and the error log.
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $start = microtime(true);
        $stmt  = self::connection()->prepare($sql);
        $stmt->execute($params);
        $elapsed = microtime(true) - $start;

        if ($elapsed >= self::SLOW_THRESHOLD) {
            $summary = preg_replace('/\s+/', ' ', trim($sql));
            $summary = mb_substr($summary, 0, 500);

            $entry = [
                'sql'      => $summary,
                'params'   => $params,
                'seconds'  => round($elapsed, 4),
                'at'       => date('Y-m-d H:i:s'),
            ];

            self::$slowQueries[] = $entry;
            self::persistSlowQuery($entry);

            error_log(sprintf(
                '[db-slow] %.4fs %s (%d params)',
                $elapsed,
                $summary,
                count($params)
            ));
        }

        return $stmt;
    }

    /**
     * Append a slow-query entry to the on-disk ring buffer, keeping only the
     * newest SLOW_LOG_MAX entries. Non-fatal: a write failure never affects
     * the request that triggered it.
     */
    private static function persistSlowQuery(array $entry): void
    {
        $path = self::slowLogPath();
        $rows = [];

        $existing = @file_get_contents($path);
        $decoded  = $existing !== false ? json_decode($existing, true) : null;
        if (is_array($decoded)) {
            $rows = $decoded;
        }

        $rows[] = $entry;

        // Trim from the front (oldest first) to stay within the cap.
        if (count($rows) > self::SLOW_LOG_MAX) {
            $rows = array_slice($rows, -self::SLOW_LOG_MAX);
        }

        @file_put_contents($path, (string) json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Slow queries recorded on this request, newest last (in-memory).
     */
    public static function slowQueries(): array
    {
        return self::$slowQueries;
    }

    /**
     * Slow queries recorded on this request PLUS the persisted ring from
     * earlier requests (most recent across all traffic). Each entry is
     * ['sql', 'params', 'seconds', 'at'].
     */
    public static function recentSlowQueries(): array
    {
        $persisted = [];

        $existing = @file_get_contents(self::slowLogPath());
        $decoded  = $existing !== false ? json_decode($existing, true) : null;
        if (is_array($decoded)) {
            $persisted = $decoded;
        }

        return array_merge(self::$slowQueries, $persisted);
    }

    /**
     * Build the PDO DSN from config; SQLite needs a file path while MySQL
     * uses host/port/database plus the utf8mb4 charset for full unicode.
     */
    private static function buildDsn(array $config): string
    {
        if ($config['driver'] === 'sqlite') {
            return 'sqlite:' . $config['path'];
        }

        $driver = $config['driver'];
        $host   = $config['host'];
        $port   = $config['port'] ?? 3306;
        $db     = $config['database'];

        return "$driver:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    }
}
