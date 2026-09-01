<?php
/**
 * Static smoke checks runnable anywhere (CI or CLI): php tests/smoke.php
 *
 * These catch the regression classes that have actually bitten this code
 * base: PHP-version syntax errors (caught by CI's php -l step), route
 * table mistakes, schema shape drift, and debug leftovers. They need no
 * database and no web server.
 *
 * Every check lives in app/Core/SmokeChecks.php — the same definitions the
 * in-app admin "Test suite" runs — so the CI gate and the server-side suite
 * can never drift apart.
 */

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
require __DIR__ . '/../app/Core/TestSuite.php';
require __DIR__ . '/../app/Core/SmokeChecks.php';

use App\Core\SmokeChecks;

$failures = [];
$checks   = 0;

foreach (SmokeChecks::all() as $id => $test) {
    $checks++;
    try {
        $result = $test['run']();
    } catch (\Throwable $ex) {
        $result = ['pass' => false, 'detail' => 'exception: ' . $ex->getMessage()];
    }
    if (!$result['pass']) {
        $failures[] = "$id: " . ($result['detail'] ?? '');
    }
}

// The in-app suite must surface every one of these checks as well.
$registry = \App\Core\TestSuite::tests();
$smokeInSuite = array_filter(
    array_keys($registry),
    static fn (string $id): bool => str_starts_with($id, 'smoke.')
);
if (count($smokeInSuite) < $checks) {
    $failures[] = 'TestSuite must expose all smoke checks (suite has ' . count($smokeInSuite) . ', smoke has ' . $checks . ')';
}

// --- Report ----------------------------------------------------------------
echo "smoke: $checks checks\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL - $f\n";
    }
    exit(1);
}
echo "smoke: all passed\n";