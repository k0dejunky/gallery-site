<?php

declare(strict_types=1);

$statusOnly = in_array('--status', $argv, true);
$arguments = array_values(array_filter(array_slice($argv, 1), static function (string $argument): bool {
    return $argument !== '--status';
}));

if ($arguments !== []) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [--status]\n");
    exit(1);
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $driver = (string) ($config['driver'] ?? 'mysql');

    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config['path']);
    } elseif ($driver === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            (int) $config['port'],
            $config['database']
        );
        $pdo = new PDO($dsn, $config['username'], $config['password']);
    } else {
        throw new RuntimeException("Unsupported database driver: {$driver}");
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (' .
        'filename VARCHAR(255) NOT NULL PRIMARY KEY, ' .
        'checksum CHAR(64) NOT NULL, ' .
        'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
        ')'
    );

    $migrationDir = __DIR__ . '/../database/migrations';
    $files = glob($migrationDir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    $applied = [];
    $query = $pdo->query('SELECT filename, checksum, applied_at FROM schema_migrations ORDER BY filename');
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $applied[$row['filename']] = $row;
    }

    foreach ($files as $file) {
        $filename = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException("Unable to checksum {$filename}");
        }

        if (isset($applied[$filename])) {
            if (!hash_equals($applied[$filename]['checksum'], $checksum)) {
                throw new RuntimeException("Checksum changed for applied migration {$filename}");
            }
            continue;
        }

        if ($statusOnly) {
            continue;
        }

        fwrite(STDOUT, "Applying {$filename}...\n");
        $pdo->beginTransaction();
        try {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Unable to read {$filename}");
            }
            $pdo->exec($sql);
            $insert = $pdo->prepare(
                'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)'
            );
            $insert->execute(['filename' => $filename, 'checksum' => $checksum]);
            // MySQL DDL may implicitly commit the transaction before the
            // migration record is inserted.
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        fwrite(STDOUT, "Applied {$filename}\n");
    }

    if ($statusOnly) {
        foreach ($files as $file) {
            $filename = basename($file);
            $checksum = hash_file('sha256', $file);
            $state = isset($applied[$filename]) ? 'applied' : 'pending';
            fwrite(STDOUT, sprintf("%-32s %s\n", $filename, $state));
        }
    } elseif ($files === []) {
        fwrite(STDOUT, "No migrations found.\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration error: {$error->getMessage()}\n");
    exit(1);
}
