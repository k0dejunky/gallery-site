<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

/** Public, deliberately small liveness/readiness response for monitoring. */
class HealthController extends Controller
{
    public function show(): void
    {
        $db = true;
        try {
            Database::run('SELECT 1')->fetchColumn();
        } catch (\Throwable $error) {
            $db = false;
        }

        $uploads = (string) config('app.uploads.dir');
        $directories = [dirname(__DIR__, 2) . '/storage', $uploads, $uploads . '/pending', $uploads . '/exports'];
        $storage = count(array_filter($directories, static function (string $directory): bool {
            return is_dir($directory) && is_readable($directory) && is_writable($directory);
        })) === count($directories);
        $ok = $db && $storage;

        http_response_code($ok ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => $ok,
            'app' => (string) config('app.site_name', 'gallery'),
            'db' => $db,
            'time' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
    }
}
