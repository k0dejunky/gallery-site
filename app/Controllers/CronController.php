<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Housekeeping;

/**
 * Unattended maintenance endpoint for the server crontab:
 *   curl -fsS "http://localhost/gallery/cron/housekeeping?key=SECRET"
 * The key comes from GALLERY_CRON_KEY in .env so the route is useless to
 * outsiders; CSRF does not apply because this is a machine-to-machine GET.
 */
class CronController extends Controller
{
    public function run(): void
    {
        $expected = \env_value('GALLERY_CRON_KEY');
        $given    = (string) ($this->request->query('key', ''));

        header('Content-Type: application/json');

        if ($expected === '' || !hash_equals($expected, $given)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $summary = Housekeeping::run(10);

        // Import any live-stream recordings left by a crashed/disconnected
        // operator (no /live/stop reached the server).
        try {
            $imported = \App\Models\LiveRecording::importOrphans();
            if ($imported > 0) {
                $summary['live_recordings_imported'] = $imported;
            }
        } catch (\Throwable $error) {
            $summary['live_recordings_error'] = $error->getMessage();
        }

        echo json_encode(['ok' => true] + $summary);
    }
}
