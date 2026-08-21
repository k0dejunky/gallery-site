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
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
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
