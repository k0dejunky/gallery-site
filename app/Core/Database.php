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

    /** Drop the shared handle so the next call opens a fresh connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * True when the error means MySQL closed an idle connection
     * (wait_timeout expired while a worker was busy, e.g. a multi-minute
     * vision/FFmpeg call). Such a connection is dead and must be reopened.
     */
    public static function isLostConnection(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 2006 server has gone away, 2013 lost connection during query.
        return ($sqlState === 'HY000' && ($driverCode === 2006 || $driverCode === 2013))
            || $sqlState === '40001'
            || stripos($e->getMessage(), 'server has gone away') !== false;
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

        // mysqlnd prints a "Packets out of order" warning while probing a
        // connection the server already dropped; the exception below is what
        // matters. Other warnings are forwarded to the normal handler.
        set_error_handler(static function (int $no, string $msg): bool {
            $known = ['Packets out of order', 'Lost connection', 'MySQL server has gone away'];
            foreach ($known as $needle) {
                if (stripos($msg, $needle) !== false) {
                    return true;
                }
            }

            return false;
        });
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // A worker that blocks for minutes (vision, FFmpeg) lets the
            // server's wait_timeout close the idle connection; transparently
            // reconnect and run the statement once so the work is not lost.
            if (!self::isLostConnection($e)) {
                throw $e;
            }
            self::reset();
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
        } finally {
            restore_error_handler();
        }

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
            // Keep the in-memory ring bounded (same cap as the on-disk log):
            // without this, a query that is consistently slow would append an
            // entry per call and grow memory without limit inside a
            // long-running worker/daemon (SSE polls, the cron/worker loops).
            self::$slowQueries = array_slice(self::$slowQueries, -self::SLOW_LOG_MAX);
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

        return self::mergeSlowEntries(self::$slowQueries, $persisted);
    }

    /**
     * Combine the in-memory slow entries of this request with the persisted
     * ring from earlier requests.
     *
     * A query that went slow during THIS request is in both halves, so a
     * straight merge lists it twice - which is what happens whenever the
     * System page's own read trips the threshold while the page renders.
     * The signature is sql + time + duration: identical for one event no
     * matter which half collected it.
     *
     * Public so the smoke suite can exercise the dedupe without a database.
     *
     * @param array<int, array<string, mixed>> $memory
     * @param array<int, array<string, mixed>> $persisted
     * @return array<int, array<string, mixed>>
     */
    public static function mergeSlowEntries(array $memory, array $persisted): array
    {
        $seen = [];
        $out  = [];

        foreach (array_merge($memory, $persisted) as $entry) {
            $key = ($entry['sql'] ?? '') . '|' . ($entry['at'] ?? '') . '|' . ($entry['seconds'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $entry;
        }

        return $out;
    }

    /**
     * How long information_schema sizes may be reused before they are re-read.
     */
    private const TABLE_STATS_TTL = 300;

    /** Directory for small runtime files shared by every request. */
    private static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // storage/cache is not writable on a fresh install created by an older
        // unpack; storage/logs always is (the slow-query ring lives there).
        return is_dir($dir) && is_writable($dir)
            ? $dir
            : dirname(__DIR__, 2) . '/storage/logs';
    }

    /**
     * Per-table sizes for one schema, read from information_schema at most
     * once every TABLE_STATS_TTL seconds.
     *
     * information_schema.tables is normally served from the InnoDB data
     * dictionary, but a dictionary miss costs 2-7s on the dev box - on the
     * System page, which exists to report slow queries, and on the admin
     * dashboard's storage pie, which loads on every visit. The cache is a
     * file rather than Cache: Redis is optional in this app and the
     * per-process fallback would miss most requests.
     *
     * @return array<int, array{name:string,rows:int,bytes:int,size_mb:float}>
     */
    public static function tableSizes(string $schema): array
    {
        $path = self::cacheDir() . '/table-sizes-' . md5($schema) . '.json';
        $age  = @filemtime($path);

        if ($age !== false && (time() - $age) < self::TABLE_STATS_TTL) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $rows = self::run(
            'SELECT table_name AS name, table_rows AS `rows`,
                    data_length + index_length AS bytes,
                    ROUND((data_length + index_length) / 1048576, 1) AS size_mb
             FROM information_schema.tables
             WHERE table_schema = ?
             ORDER BY (data_length + index_length) DESC',
            [$schema]
        )->fetchAll();

        // Write through a temp file so a reader never sees a half-written JSON.
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($rows)) !== false) {
            @rename($tmp, $path);
        }

        return $rows;
    }

    /** Total data+index bytes for a schema, read from the same cache. */
    public static function tableSizeBytes(string $schema): int
    {
        $total = 0;

        foreach (self::tableSizes($schema) as $row) {
            $total += (int) ($row['bytes'] ?? 0);
        }

        return $total;
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
