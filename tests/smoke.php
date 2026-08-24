<?php
/**
 * Static smoke checks runnable anywhere (CI or CLI): php tests/smoke.php
 *
 * These catch the regression classes that have actually bitten this code
 * base: PHP-version syntax errors (caught by CI's php -l step), route
 * table mistakes, schema shape drift, and debug leftovers. They need no
 * database and no web server.
 */

declare(strict_types=1);

$failures = [];
$checks   = 0;

$check = function (bool $ok, string $what) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $what;
    }
};

$root = dirname(__DIR__);

// --- Critical files exist -------------------------------------------------
foreach ([
    'public/index.php',
    'config/routes.php',
    'schema.sql',
    'views/layout.php',
    'views/admin/layout.php',
    'app/Core/Router.php',
    'app/Core/Database.php',
    'app/Core/Auth.php',
] as $rel) {
    $check(file_exists("$root/$rel"), "missing file: $rel");
}

// --- Route table sanity ---------------------------------------------------
/** @var array $routes */
$routes = require "$root/config/routes.php";
$check(is_array($routes) && count($routes) > 20, 'routes.php must return a non-trivial list');

$seen = [];
$duplicates = [];
$routeRe = function (string $path): string {
    return preg_replace('#\{[a-zA-Z]+\}#', '{param}', $path);
};
foreach ($routes as $route) {
    [$method, $path] = $route;
    $key = strtoupper((string) $method) . ' ' . $routeRe((string) $path);
    if (isset($seen[$key])) {
        $duplicates[] = $key;
    }
    $seen[$key] = true;

    // Every route action must reference an existing controller method.
    [$class, $action] = explode('@', (string) $route[2]);
    $file = "$root/app/Controllers/$class.php";
    if (!is_file($file)) {
        $failures[] = "route {$key}: missing controller app/Controllers/$class.php";
        $checks++;
        continue;
    }
    $src = file_get_contents($file);
    $checks++;
    if (!preg_match('/function\s+' . preg_quote($action, '/') . '\s*\(/', $src)) {
        $failures[] = "route {$key}: $class@$action not found";
    }
}
$check($duplicates === [], 'duplicate routes: ' . implode(', ', $duplicates));

// --- Schema shape ---------------------------------------------------------
$schema = (string) file_get_contents("$root/schema.sql");
preg_match_all('/CREATE TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $schema, $m);
$tables = array_map('strtolower', $m[1]);
foreach (['users', 'galleries', 'photos', 'subscriptions', 'storage_snapshots'] as $must) {
    $check(in_array($must, $tables, true), "schema.sql missing table: $must");
}
// Columns added by recent features must stay in schema.sql.
foreach (['last_seen_at' => 'users', 'video_count' => 'storage_snapshots', 'min_level' => 'galleries'] as $col => $table) {
    $check(preg_match('/CREATE TABLE(\s+IF\s+NOT\s+EXISTS)?\s+' . $table . '\b(?:(?!CREATE TABLE).)*' . $col . '/is', $schema) === 1,
        "schema.sql: $table.$col missing");
}

// --- Debug leftovers ------------------------------------------------------
$debugHits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/app"));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $code = (string) file_get_contents($f->getPathname());
    if (preg_match('/(?<![a-zA-Z_>:])(var_dump|print_r)\s*\(|(?<![a-zA-Z_>$])dd\s*\(/', $code)) {
        $debugHits[] = basename($f->getPathname());
    }
}
$check($debugHits === [], 'debug calls in: ' . implode(', ', array_unique($debugHits)));

// --- Admin layout never clobbers view data --------------------------------
$adminLayout = (string) file_get_contents("$root/views/admin/layout.php");
$check(strpos($adminLayout, '$user =') === false,
    'views/admin/layout.php must not assign $user (viewAdmin() scope collision)');

// --- Report ----------------------------------------------------------------
echo "smoke: $checks checks\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL - $f\n";
    }
    exit(1);
}
echo "smoke: all passed\n";
