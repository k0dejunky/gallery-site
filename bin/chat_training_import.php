<?php

declare(strict_types=1);

/**
 * Chat training import: load operator-style reply pairs from a file and insert
 * them as cleaned training data so the always-on training PC picks them up on
 * its next poll.
 *
 * Usage:
 *   php bin/chat_training_import.php --file=pairs.jsonl
 *   php bin/chat_training_import.php --file=pairs.csv --dry-run
 *   php bin/chat_training_import.php --file=pairs.txt --limit=500
 *
 * Accepted formats (auto-detected):
 *   .jsonl  one {"messages":[{role:user,content:...},{role:assistant,content:...}]}
 *           per line (the exporter's format), or {"user_message":..,"operator_reply":..}
 *   .csv    user_message,operator_reply (optional header row)
 *   .tsv    user_message<TAB>operator_reply (optional header row)
 *   .txt    alternating "Q:"/"A:" lines, or plain alternating lines
 *
 * Inserted pairs are cleaned (PII stripped, whitespace normalized), filtered
 * for junk, deduped, and stored with cleaned = 1.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\ChatTraining;

$file    = '';
$dryRun  = false;
$limit   = 100000;

foreach ($argv as $arg) {
    if (preg_match('/^--file=(.+)$/', $arg, $m)) {
        $file = $m[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500000, (int) $m[1]));
    }
}

if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/chat_training_import.php --file=<path> [--dry-run] [--limit=N]\n");
    exit(1);
}

$pairs = readPairs($file);
printf("%s %d raw pair(s) from %s\n", $dryRun ? '[dry-run] parsed' : 'Parsed', count($pairs), basename($file));

$normalized = [];
foreach ($pairs as $p) {
    if (count($normalized) >= $limit) {
        break;
    }
    $n = ChatTraining::normalizePair($p[0], $p[1]);
    if ($n !== null) {
        $normalized[] = $n;
    }
}

printf("Cleaned/kept: %d\n", count($normalized));

if ($dryRun) {
    foreach (array_slice($normalized, 0, 10) as $n) {
        printf("  - %s  =>  %s\n", mb_substr($n['user_message'], 0, 60), mb_substr($n['operator_reply'], 0, 60));
    }
    exit(0);
}

$result = ChatTraining::insertPairs($normalized);
printf("Imported %d pair(s), skipped %d duplicate(s).\n", $result['inserted'], $result['skipped']);
exit($result['inserted'] > 0 ? 0 : 1);

/**
 * Parse a file into a list of [user_message, operator_reply] tuples.
 */
function readPairs(string $path): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = (string) file_get_contents($path);

    if ($ext === 'jsonl') {
        return parseJsonl($text);
    }
    if ($ext === 'csv') {
        return parseDelimited($text, ',');
    }
    if ($ext === 'tsv') {
        return parseDelimited($text, "\t");
    }
    return parseText($text);
}

function parseJsonl(string $text): array
{
    $pairs = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        if (isset($decoded['user_message'], $decoded['operator_reply'])) {
            $pairs[] = [(string) $decoded['user_message'], (string) $decoded['operator_reply']];
            continue;
        }
        if (isset($decoded['messages']) && is_array($decoded['messages'])) {
            $user = $assistant = '';
            foreach ($decoded['messages'] as $m) {
                $role = (string) ($m['role'] ?? '');
                $content = (string) ($m['content'] ?? '');
                if ($role === 'user') {
                    $user = $content;
                } elseif ($role === 'assistant') {
                    $assistant = $content;
                }
            }
            if ($user !== '' && $assistant !== '') {
                $pairs[] = [$user, $assistant];
            }
        }
    }
    return $pairs;
}

function parseDelimited(string $text, string $delim): array
{
    $pairs = [];
    $first = true;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = str_getcsv($line, $delim);
        if (count($parts) < 2) {
            continue;
        }
        if ($first && strtolower($parts[0]) === 'user_message') {
            $first = false;
            continue;
        }
        $first = false;
        $pairs[] = [trim((string) $parts[0]), trim((string) $parts[1])];
    }
    return $pairs;
}

function parseText(string $text): array
{
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $pairs = [];
    $user  = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^Q:\s*(.+)$/i', $line, $m)) {
            $user = trim($m[1]);
        } elseif (preg_match('/^A:\s*(.+)$/i', $line, $m)) {
            $reply = trim($m[1]);
            if ($user !== null) {
                $pairs[] = [$user, $reply];
                $user = null;
            }
        } elseif ($user === null) {
            $user = $line;
        } else {
            $pairs[] = [$user, $line];
            $user = null;
        }
    }

    return $pairs;
}