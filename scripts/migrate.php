<?php

declare(strict_types=1);

/**
 * The MySQL errors that mean "the thing this migration adds is already
 * there".
 *
 * A fresh install imports schema.sql FIRST and then runs the migrations, and
 * schema.sql already carries most of what the migrations add - so the first
 * migration would fail with "Duplicate column name" and every later one
 * would never run. These errors are not failures: the intent of the migration
 * is already satisfied, so the migration is recorded as applied and the run
 * continues.
 *
 *   1050  the table already exists
 *   1060  the column already exists
 *   1061  the key/index already exists
 *   1091  the column/key that was to be dropped is already gone
 *   1826  the foreign key already exists
 */
const ALREADY_THERE = [1050, 1060, 1061, 1091, 1826];

$statusOnly = in_array('--status', $argv, true);
$database   = null;
$arguments  = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--status') {
        continue;
    }
    if (strpos($argument, '--database=') === 0) {
        // A database override, so a fresh install can be rehearsed against a
        // scratch database instead of the live one.
        $database = substr($argument, strlen('--database='));
        continue;
    }
    $arguments[] = $argument;
}

if ($arguments !== [] || ($database !== null && $database === '')) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [--status] [--database=NAME]\n");
    exit(1);
}

/**
 * A short label for a statement, for the log line.
 */
function migrationFirstWords(string $statement): string
{
    $words = preg_split('/\s+/', trim($statement)) ?: [];
    $label = implode(' ', array_slice($words, 0, 4));

    return strlen($label) > 70 ? substr($label, 0, 70) . '...' : $label;
}

/**
 * Split a migration into its statements.
 *
 * The comment lines are dropped (a comment may contain a semicolon) and the
 * rest is split on the semicolons. None of the migrations has a semicolon
 * inside a string literal, so this is safe here.
 *
 * @return string[]
 */
function migrationStatements(string $sql): array
{
    $lines = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        $lines[] = $line;
    }

    $statements = [];
    foreach (explode(';', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    return $statements;
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
        if ($database !== null) {
            $pdo->exec('USE `' . str_replace('`', '', $database) . '`');
        }
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

    // Prune recorded migrations whose file no longer exists (e.g. a feature
    // was retired and its migration deleted). Keeping the row would leave the
    // tracking table permanently out of sync with the file list.
    if (!$statusOnly) {
        $knownFiles = array_map('basename', $files);
        $orphans = array_diff(array_keys($applied), $knownFiles);
        if ($orphans !== []) {
            $stmt = $pdo->prepare('DELETE FROM schema_migrations WHERE filename = ?');
            foreach ($orphans as $orphan) {
                $stmt->execute([$orphan]);
                fwrite(STDOUT, "Pruned removed migration {$orphan}\n");
            }
        }
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

            // The statements run ONE BY ONE, so a migration whose first
            // statement is already in the schema does not lose the rest of
            // its work: each statement that is "already there" is skipped and
            // the others still run.
            foreach (migrationStatements($sql) as $statement) {
                try {
                    // query() + fetchAll() (not exec()): a statement that
                    // returns rows must have its result set consumed, or the
                    // next statement fails with "unbuffered queries active".
                    $result = $pdo->query($statement);
                    if ($result !== false) {
                        $result->fetchAll();
                        $result->closeCursor();
                    }
                } catch (PDOException $error) {
                    $code = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
                    if ($code === null || !in_array($code, ALREADY_THERE, true)) {
                        throw $error;
                    }
                    fwrite(STDOUT, "  already in the schema, skipped: " . migrationFirstWords($statement) . "\n");
                }
            }
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

            // "The column/key/table is already there" means the schema
            // already has what this migration adds (a fresh install imports
            // schema.sql first). That is not a failure: record the migration
            // as applied and carry on, or no later migration would ever run.
            $code = null;
            if ($error instanceof PDOException) {
                $code = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
            }
            if ($code !== null && in_array($code, ALREADY_THERE, true)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (filename, checksum) VALUES (:filename, :checksum)
                     ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
                );
                $stmt->execute(['filename' => $filename, 'checksum' => $checksum]);
                fwrite(STDOUT, "Already in the schema, recorded: {$filename}\n");
                continue;
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
