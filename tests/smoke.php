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
    'config/validate.php',
    'scripts/migrate.php',
    'app/Core/RateLimiter.php',
    'app/Controllers/HealthController.php',
    'app/Controllers/SupportController.php',
    'app/Controllers/FavoriteController.php',
    'app/Controllers/SavedSearchController.php',
    'app/Models/SupportMessage.php',
    'app/Models/Gallery.php',
    'app/Models/FavoriteCategory.php',
    'app/Models/SavedSearch.php',
    'views/support/contact.php',
    'views/support/show.php',
    'views/favorites/index.php',
    'bin/video_export_worker.php',
    'bin/video_export_queue.php',
    'config/gallery-video-export.service',
    'database/migrations/005_login_attempts_attempted_at.sql',
    'database/migrations/006_content_view_stats.sql',
] as $rel) {
    $check(file_exists("$root/$rel"), "missing file: $rel");
}
$check(is_dir("$root/database/migrations"), 'missing directory: database/migrations');

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
foreach (['users', 'galleries', 'photos', 'subscriptions', 'storage_snapshots', 'support_replies', 'gallery_favorites', 'saved_searches'] as $must) {
    $check(in_array($must, $tables, true), "schema.sql missing table: $must");
}
// Columns added by recent features must stay in schema.sql.
foreach (['last_seen_at' => 'users', 'email_verified_at' => 'users', 'email_verification_token' => 'users', 'video_count' => 'storage_snapshots', 'min_level' => 'galleries', 'membership_number' => 'subscriptions'] as $col => $table) {
    $check(preg_match('/CREATE TABLE(\s+IF\s+NOT\s+EXISTS)?\s+' . $table . '\b(?:(?!CREATE TABLE).)*' . $col . '/is', $schema) === 1,
        "schema.sql: $table.$col missing");
}
$check(strpos($schema, 'idx_login_attempts_at') !== false,
    'schema.sql: login_attempts.attempted_at index missing');
$check(in_array('content_views', $tables, true),
    'schema.sql missing table: content_views');
$check(strpos($schema, 'uq_content_views_type_id_date') !== false,
    'schema.sql: content_views unique key missing');
$migrationReadme = (string) file_get_contents("$root/database/migrations/README.md");
$check(strpos($migrationReadme, 'schema_migrations') !== false,
    'database/migrations/README.md must document schema_migrations');

// Operational and security guardrails must remain present.
$health = (string) file_get_contents("$root/app/Controllers/HealthController.php");
$limiter = (string) file_get_contents("$root/app/Core/RateLimiter.php");
$deploy = (string) file_get_contents("$root/scripts/deploy.sh");
$routesSource = (string) file_get_contents("$root/config/routes.php");
$check(strpos($health, 'Cache-Control: no-store') !== false,
    'HealthController must disable response caching');
$check(strpos($limiter, "hash('sha256'") !== false,
    'RateLimiter must hash identifiers with sha256');
$check(strpos($deploy, 'rollback') !== false, 'deploy script must contain rollback handling');
$check(strpos($routesSource, "'/health'") !== false, 'routes.php must expose health route');

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
