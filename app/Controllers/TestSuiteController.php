<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\TestSuite;
use App\Models\AuditLog;

/**
 * Admin "Test suite": a self-contained, read-only diagnostic suite that runs
 * front-end and back-end checks against the whole site. Tests run in a
 * detached background PHP worker so the page can stream status updates
 * (pending -> running -> passed/failed) in real time via JSON polling.
 */
class TestSuiteController extends Controller
{
    private string $root;

    public function __construct($request)
    {
        parent::__construct($request);
        Auth::requirePermission('logs');

        $this->root = dirname(__DIR__, 2);
        TestSuite::ensureRunsDir();
    }

    /** Admin page: list every available test + the latest run state. */
    public function index(): void
    {
        $this->viewAdmin('test_suite', [
            'groups'  => TestSuite::grouped(),
            'runs'    => $this->recentRuns(),
            'running' => $this->activeRunId(),
            'canRun'  => (Auth::user()['role'] ?? '') === 'super_admin',
        ]);
    }

    /**
     * Accept a CSRF-protected POST with a set of test ids (or none = all) and
     * detach a worker to execute them. Returns JSON so the page's client can
     * begin polling immediately. Rejects the request while a run is active.
     */
    public function run(): void
    {
        header('Content-Type: application/json');

        if (!$this->isSafeToSpawn()) {
            echo json_encode(['ok' => false, 'error' => 'PHP exec worker unavailable']);
            return;
        }

        if ($this->activeRunId() !== null) {
            echo json_encode(['ok' => false, 'error' => 'A test run is already in progress.']);
            return;
        }

        $all       = TestSuite::tests();
        $requested = $this->request->post('tests');
        $ids       = [];

        if (is_array($requested) && $requested !== []) {
            foreach ($requested as $id) {
                $id = (string) $id;
                if (isset($all[$id])) $ids[$id] = $id;
            }
            // Fall back to running everything when nothing valid was supplied.
            if ($ids === []) $ids = array_keys($all);
        } else {
            $ids = array_keys($all);
        }

        // If every test was requested we just mark "all".
        $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $state = [
            'id'         => $runId,
            'started_at' => date('c'),
            'status'     => 'pending',
            'total'      => count($ids),
            'passed'     => 0,
            'failed'     => 0,
            'ran'        => 0,
            'tests'      => [],
        ];
        foreach ($ids as $id) {
            $state['tests'][$id] = [
                'id' => $id, 'name' => $all[$id]['name'], 'group' => $all[$id]['group'],
                'status' => 'pending', 'detail' => '',
            ];
        }
        TestSuite::writeRun($state);

        // Persist the selected ids for the worker to consume.
        file_put_contents(TestSuite::runPath($runId) . '.spec.php',
            '<?php return ' . var_export(array_values($ids), true) . ';');

        AuditLog::record(Auth::user()['id'] ?? null, 'create', 'test_suite_run', null,
            'Started test suite run ' . $runId . ' (' . count($ids) . ' tests)');

        $this->spawnWorker($runId);

        echo json_encode(['ok' => true, 'run' => $runId, 'count' => count($ids)]);
    }

    /** Real-time polling endpoint: current state of a run as JSON. */
    public function status(): void
    {
        header('Content-Type: application/json');
        $runId = (string) $this->request->query('run');
        if ($runId === '' || !preg_match('/^[A-Za-z0-9-]+$/', $runId)) {
            echo json_encode(['ok' => false, 'error' => 'bad run']);
            return;
        }
        $state = TestSuite::readRun($runId);
        if ($state === null) {
            echo json_encode(['ok' => false, 'error' => 'no such run']);
            return;
        }
        $state['ok'] = true;
        echo json_encode($state);
    }

    private function isSafeToSpawn(): bool
    {
        return function_exists('exec') && trim((string) ini_get('disable_functions')) !== '*';
    }

    /** Return the runId of an in-flight run, if any. */
    private function activeRunId(): ?string
    {
        foreach ($this->recentRuns() as $r) {
            if (in_array($r['status'], ['pending', 'running'], true)) {
                return $r['id'];
            }
        }
        return null;
    }

    /** Newest finished/in-flight runs for the page. */
    private function recentRuns(int $limit = 8): array
    {
        $glob = TestSuite::runsGlob();
        $paths = array_values(array_filter(array_map('realpath', glob($glob) ?: []),
            static fn (string $p): bool => str_ends_with($p, '.json')));
        usort($paths, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $runs = [];
        foreach (array_slice($paths, 0, $limit) as $p) {
            $data = json_decode((string) file_get_contents($p), true);
            if (is_array($data) && isset($data['id'], $data['status'])) {
                $runs[] = $data;
            }
        }
        return $runs;
    }

    /** Detach the worker so it survives the FPM request ending. */
    private function spawnWorker(string $runId): void
    {
        $spec = TestSuite::runPath($runId) . '.spec.php';
        $log  = TestSuite::runPath($runId) . '.log';

        // PHP_BINARY inside FPM is the php-fpm master, which just prints
        // usage when given a script. Use the fixed CLI binary instead, as
        // bin/apply_cron.php already does.
        $php = '/usr/bin/php';

        $script = sys_get_temp_dir() . '/gallery-tests-' . $runId . '.sh';
        file_put_contents($script, '#!/bin/bash' . "\n"
            . escapeshellarg($php) . ' ' . escapeshellarg($this->root . '/bin/test_runner.php') . ' ' . escapeshellarg($runId) . "\n"
            . 'rm -f ' . escapeshellarg($spec) . "\n");
        chmod($script, 0700);

        @shell_exec('setsid nohup bash ' . escapeshellarg($script)
            . ' > ' . escapeshellarg($log) . ' 2>&1 &');
    }
}