<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$ids = array_keys(\App\Core\TestSuite::tests());
$fails = [];
$total = 0;
\App\Core\TestSuite::run($ids, function (int $i, array $row) use (&$fails, &$total): void {
    $total++;
    if ($row['status'] !== 'passed') {
        $fails[] = $row['id'] . ': ' . $row['detail'];
    }
});
echo "TESTS: $total, FAILED: " . count($fails) . "\n";
foreach ($fails as $f) {
    echo "FAIL - $f\n";
}