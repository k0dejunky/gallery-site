<?php

declare(strict_types=1);

/**
 * Chat training export: cleans harvested operator replies (chat_training_pairs)
 * into a JSONL file for offline LoRA fine-tuning on the training PC.
 *
 * Usage:  php bin/chat_training_export.php [--limit=20000]
 *
 * Cleaning strips PII (emails/phones), dedupes by exact content, drops empty
 * rows, normalizes whitespace and caps message length. Rows are marked
 * 'cleaned' once exported so each pair is exported exactly once.
 */

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database;

$limit = 20000;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(100000, (int) $m[1]));
    }
}

$root = dirname(__DIR__);
$dir  = $root . '/storage/training';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$out = $dir . '/chat_train.jsonl';

$rows = Database::run(
    'SELECT id, user_message, operator_reply FROM chat_training_pairs WHERE cleaned = 0 ORDER BY id ASC LIMIT ' . $limit
)->fetchAll();

$clean = static function (string $text): string {
    $text = trim($text);
    $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
    $text = (string) preg_replace('/\b\d{3}[-.)]?\d{3}[-.]?\d{4}\b/', '[phone]', $text);
    $text = (string) preg_replace('/\s+/u', ' ', $text);

    return mb_substr($text, 0, 2000);
};

$fh   = fopen($out, 'w');
$seen = [];
$written = 0;
$maxId = 0;

foreach ($rows as $row) {
    $u = $clean((string) $row['user_message']);
    $r = $clean((string) $row['operator_reply']);

    if ($u === '' || $r === '') {
        continue;
    }
    if (isset($seen[$u . "\0" . $r])) {
        continue;
    }
    $seen[$u . "\0" . $r] = true;

    fwrite($fh, json_encode([
        'messages' => [
            ['role' => 'user', 'content' => $u],
            ['role' => 'assistant', 'content' => $r],
        ],
    ]) . "\n");

    $written++;
    $maxId = max($maxId, (int) $row['id']);
}

fclose($fh);

if ($maxId > 0) {
    Database::run('UPDATE chat_training_pairs SET cleaned = 1 WHERE id <= ?', [$maxId]);
}

printf("Exported %d cleaned pair(s) → %s\n", $written, $out);