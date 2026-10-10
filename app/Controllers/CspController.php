<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * Collects Content-Security-Policy violation reports (report-uri target) into
 * storage/logs/csp.log so the site can move off script-src 'unsafe-inline'
 * with evidence. POST /webhooks/csp-report — unauthenticated by design (that's
 * how browsers send CSP reports), rate-limited and size-capped.
 */
class CspController extends Controller
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function report(): void
    {
        $body = trim($this->rawBody());

        if ($body === '') {
            http_response_code(204);
            return;
        }

        // Accept both the legacy {"csp-report":{…}} and the Reporting API
        // array form; only keep it reasonably small.
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            // Some browsers POST as application/csp-report with a JSON body;
            // if it didn't parse, drop it (never write attacker-controlled
            // blobs larger than the cap or non-JSON).
            http_response_code(204);
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/csp.log';

        // Simple size cap + rotate so a flood can't fill the disk.
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.1');
        }

        $line = date('c') . ' ' . substr(json_encode($payload), 0, 4000) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        http_response_code(204);
    }
}