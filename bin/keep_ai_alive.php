<?php

declare(strict_types=1);

/**
 * AI watchdog: keep the self-hosted Ollama chat AI running on this server.
 *
 * The ollama.service unit already restarts on exit, but the model runner can
 * wedge while the process stays alive (requests hang / return 500) and the
 * chat model unloads after ~5 minutes of inactivity, so the first message
 * after a lull is slow or fails. This watchdog, run every minute as root,
 * handles all three failure modes:
 *
 *   1. API unreachable          -> restart the ollama service.
 *   2. Base chat model missing  -> pull it back.
 *   3. Runner wedged (a tiny    -> restart the service (with a cooldown so a
 *      generate probe times out)   persistent failure cannot flap it).
 *
 * The wedge probe is also a warm-up: with OLLAMA_KEEP_ALIVE set on the unit
 * it keeps the model resident, so chat answers are instant and never stall on
 * a cold reload.
 *
 * Usage (root):  php bin/keep_ai_alive.php
 * Cron:          * * * * * root /usr/bin/php /var/www/gallery/bin/keep_ai_alive.php
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\Http;

$logFile  = dirname(__DIR__) . '/storage/logs/ai-watchdog.log';
$stateDir = dirname(__DIR__) . '/storage/logs';

$log = static function (string $line): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

$isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : is_dir('/etc/cron.d');

// Restart the ollama systemd service. Requires root (the cron runs as root);
// falls back to a logged warning when a human runs this as a lesser user.
// Skips when the service is already starting (activating) so a restart in
// progress is never double-triggered.
$restart = static function (string $reason) use ($log, $isRoot): void {
    if (!$isRoot) {
        $log("WARNING restart needed but not root: {$reason}");
        return;
    }
    exec('/usr/bin/systemctl is-active ollama.service 2>/dev/null', $actOut, $actRc);
    if ($actRc === 0 && trim(implode(' ', $actOut)) === 'activating') {
        $log("skipping restart ({$reason}): ollama already activating");
        return;
    }
    $log("restarting ollama.service: {$reason}");
    exec('/usr/bin/systemctl restart ollama.service 2>&1', $out, $rc);
    sleep(2);
    $log($rc === 0 ? 'ollama.service restarted' : 'restart command failed: ' . implode(' ', $out));
};

// Cooldown: never restart more than once per 90s so a persistently broken
// runner cannot flap the service every minute.
$lastRestart = $stateDir . '/.ai_watchdog_last_restart';
if (is_file($lastRestart) && (int) file_get_contents($lastRestart) + 90 > time()) {
    $log('skipping check (recent restart within cooldown)');
    exit(0);
}

$baseUrl = rtrim((string) env_value('OLLAMA_URL', 'http://127.0.0.1:11434'), '/');

// --- 1. Is the API alive? -----------------------------------------------------
[$status, , $body] = Http::request($baseUrl . '/api/tags', ['method' => 'GET', 'timeout' => 5]);

if ($status < 200 || $status >= 300) {
    $restart("Ollama API unreachable (HTTP {$status})");
    @file_put_contents($lastRestart, (string) time());
    exit(1);
}

$data = json_decode($body, true);
$names = [];
foreach (($data['models'] ?? []) as $m) {
    $names[] = (string) ($m['name'] ?? '');
}

// --- 2. Base chat model present? ----------------------------------------------
$base = \App\Core\ChatAi::BASE_MODEL;
$basePresent = in_array($base, $names, true) || in_array($base . ':latest', $names, true);

if (!$basePresent) {
    $log("base model {$base} missing; pulling");
    exec('/usr/local/bin/ollama pull ' . escapeshellarg($base) . ' 2>&1', $pullOut, $pullRc);
    $basePresent = $pullRc === 0;
    $log($basePresent ? "pulled {$base}" : 'pull failed (no restart: a restart cannot create a missing model): ' . trim(implode(' ', $pullOut)));
    if (!$basePresent) {
        exit(0);
    }
}

// --- 3. Is the runner wedged? A tiny real generation must complete. ----------
[$gStatus, , $gBody] = Http::request($baseUrl . '/api/generate', [
    'method'  => 'POST',
    'timeout' => 15,
    'json'    => [
        'model'     => $base,
        'prompt'    => 'ping',
        'stream'    => false,
        'keep_alive' => 300,
        'options'   => ['num_predict' => 4],
    ],
]);

if ($gStatus < 200 || $gStatus >= 300) {
    $restart("runner wedged (generate probe HTTP {$gStatus})");
    @file_put_contents($lastRestart, (string) time());
    exit(1);
}

$log('AI healthy (api ' . count($names) . ' model(s), generate ok)');
exit(0);