<?php

declare(strict_types=1);

use App\Core\TestSuite;

/**
 * CLI worker for the admin "Test suite".
 *
 * Usage:  php bin/test_runner.php <runId>
 *
 * It reads the run spec written by TestSuiteController (storage/testruns/run-<id>.spec.php
 * containing selected test ids), iterates the tests through
 * TestSuite::run() and persists progressive status to the run's JSON file so
 * the admin page can poll it in real time. Runs as www-data, so it writes
 * only to storage/testruns (never to root-owned paths).
 */

require __DIR__ . '/../app/Core/helpers.php';
require __DIR__ . '/../app/Core/TestSuite.php';

$runId = (string) ($argv[1] ?? '');
$specFile = TestSuite::runPath($runId) . '.spec.php';
if ($runId === '' || !is_file($specFile)) {
    fwrite(STDERR, "invalid run\n");
    exit(1);
}

$ids = (array) (require $specFile);

$state = TestSuite::readRun($runId) ?: [
    'id'         => $runId,
    'started_at' => date('c'),
    'status'     => 'running',
    'total'      => 0,
    'passed'     => 0,
    'failed'     => 0,
    'ran'        => 0,
    'tests'      => [],
];
$state['status'] = 'running';
$state['total']  = count($ids);

// Pre-seed every chosen test as "pending" so the UI paints them instantly.
$pending = [];
foreach ($ids as $id) {
    $pending[$id] = ['id' => $id, 'name' => $id, 'group' => '', 'status' => 'pending', 'detail' => ''];
    if (!isset($state['tests'][$id])) {
        $state['tests'][$id] = $pending[$id];
    }
}
$state['status'] = 'running';
TestSuite::writeRun($state);

$results = TestSuite::run($ids, static function (int $index, array $row) use (&$state, $runId): void {
    $id = $row['id'];
    $state['tests'][$id] = $row;
    $state['ran']   = (int) max($index, $state['ran']);
    $pass = $row['status'] === 'passed';
    if ($pass) $state['passed']++;
    else       $state['failed']++;
    $state['status'] = $state['ran'] < $state['total'] ? 'running' : 'complete';
    TestSuite::writeRun($state);
});

// Normalize unknown ids' display names using the registry for the final write.
$registry = TestSuite::tests();
foreach ($state['tests'] as $id => &$r) {
    if (isset($registry[$id])) {
        $r['name']  = $registry[$id]['name'];
        $r['group'] = $registry[$id]['group'];
    }
    if (!isset($results[$id])) {
        $r['status'] = $r['status'] === 'passed' ? 'passed' : 'failed';
    }
}
unset($r);

$state['status'] = 'complete';
$state['ran']    = $state['total'];
TestSuite::writeRun($state);

exit($state['failed'] > 0 ? 3 : 0);