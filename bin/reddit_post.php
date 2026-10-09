<?php

declare(strict_types=1);

// Post a queued auto-post to Reddit via the headless-browser "share-button
// method" (bin/browser/reddit-post.mjs). Reads a JSON payload from the file
// passed as argv[1] (the autopost worker writes it) and prints a JSON result
// on stdout: {ok, url?, error?}.
//
// Usage:
//   php bin/reddit_post.php <payload.json>

require __DIR__ . '/../app/bootstrap.php';

error_reporting(E_ERROR | E_PARSE); // keep stdout clean for the caller

$payloadFile = $argv[1] ?? '';
if ($payloadFile === '' || !is_file($payloadFile)) {
    echo json_encode(['ok' => false, 'error' => 'No payload file given.']);
    exit(1);
}

$payload = json_decode((string) file_get_contents($payloadFile), true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload JSON.']);
    exit(1);
}

$root  = dirname(__DIR__);
$script = $root . '/bin/browser/reddit-post.mjs';
$node  = trim((string) (getenv('REDDIT_NODE') ?: '')) ?: 'node';

if (!is_file($script)) {
    echo json_encode(['ok' => false, 'error' => 'Browser script missing: ' . $script]);
    exit(1);
}

$cmd = escapeshellcmd($node) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($payloadFile);

$sOutput = $sError = '';
$pipes   = null;
$proc    = @proc_open($cmd, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($proc)) {
    echo json_encode(['ok' => false, 'error' => 'Could not start the browser worker (node missing?).']);
    exit(1);
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$deadline = time() + 150;
while (time() < $deadline) {
    $st = proc_get_status($proc);
    $sOutput .= (string) stream_get_contents($pipes[1]);
    $sError  .= (string) stream_get_contents($pipes[2]);
    if (!$st['running']) {
        break;
    }
    usleep(200000);
}

if (proc_get_status($proc)['running']) {
    proc_terminate($proc, 9);
    $sError .= ' (timed out after 150s)';
}
// Drain any remaining output while the pipes are still open.
$sOutput .= (string) stream_get_contents($pipes[1]);
$sError  .= (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

$result = json_decode(trim($sOutput), true);

if (is_array($result)) {
    echo json_encode($result);
    exit($result['ok'] ? 0 : 1);
}

$err = trim((string) $sError);
$msg = $err !== '' ? $err : ('Browser worker exited with code ' . $exitCode . '.');
echo json_encode(['ok' => false, 'error' => $msg]);
exit(1);