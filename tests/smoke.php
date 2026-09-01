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
    'database/migrations/007_auto_poster_queue.sql',
    'database/migrations/008_auto_post_media_and_schedule.sql',
    'app/Models/AutoPostQueue.php',
    'bin/autopost_worker.php',
    'bin/apply_cron.php',
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
$check(in_array('auto_poster_queue', $tables, true),
    'schema.sql missing table: auto_poster_queue');
$check(strpos($schema, 'media_ids') !== false && strpos($schema, 'scheduled_at') !== false,
    'schema.sql: auto_poster_queue must carry media_ids and scheduled_at columns');
$check(strpos($schema, 'idx_apq_scheduled') !== false,
    'schema.sql: auto_poster_queue scheduled index missing');
$autoPostQueueModel = (string) file_get_contents("$root/app/Models/AutoPostQueue.php");
$check(strpos($autoPostQueueModel, 'amethyst2213.com') !== false,
    'auto-post recommendations must include the site domain');
$check(strpos($autoPostQueueModel, 'mb_substr(trim($text), 0, 280)') !== false,
    'auto-post queue must cap custom text at 280 characters');
$check(strpos($autoPostQueueModel, 'MAX_ATTACHED_MEDIA') !== false,
    'auto-post queue must cap attachments at 4 media files');
$check(strpos($autoPostQueueModel, 'preferredMediaPath') !== false && strpos($autoPostQueueModel, "'/web_'") !== false,
    'auto-post queue must upload the web-optimized image variant');
$check(strpos($autoPostQueueModel, 'create_blurred_copy') !== false && strpos($autoPostQueueModel, 'POST_IMAGE_BLUR_PERCENT = 75') !== false,
    'auto-post queue must blur attached images (75%) before posting');
$helpersSource = (string) file_get_contents("$root/app/Core/helpers.php");
$check(strpos($helpersSource, 'function create_blurred_copy') !== false && strpos($helpersSource, 'IMG_FILTER_GAUSSIAN_BLUR') !== false,
    'helpers must provide a blurred temp copy that never overwrites the source');
$check(strpos($autoPostQueueModel, 'displaySchedule') !== false && strpos($autoPostQueueModel, 'schedulerTimezone') !== false && strpos($autoPostQueueModel, 'setTimezone(new DateTimeZone(\'UTC\'))') !== false,
    'auto-post queue must convert schedule times to/from the configured timezone');
$check(strpos($autoPostQueueModel, "'come visit my site to see what else I get myself into!! amethyst2213.com'") !== false,
    'auto-post recommendations must carry the call-to-action text');
$check(strpos($autoPostQueueModel, 'MAX_TAGS = 10') !== false,
    'auto-post recommendations must tag up to 10 categories');
$autoPostWorker = (string) file_get_contents("$root/bin/autopost_worker.php");
$check(strpos($autoPostWorker, 'AutoPostQueue::due') !== false,
    'autopost worker must publish due queue rows');
$check(strpos($autoPostWorker, 'flock') !== false,
    'autopost worker must lock against overlapping runs');
$autoPosterView = (string) file_get_contents("$root/views/admin/auto_poster.php");
$check(strpos($autoPosterView, 'name="text"') !== false,
    'auto-poster recommended posts must be editable text');
$check(strpos($autoPosterView, 'datetime-local') !== false,
    'auto-poster must expose a publish date/time field');
$check(strpos($autoPosterView, 'mediaFiles') !== false,
    'auto-poster queue must display attached media');
$check(strpos($autoPosterView, 'Schedule timezone') !== false && strpos($autoPosterView, 'DateTimeZone::listIdentifiers') !== false,
    'auto-poster settings must expose a schedule-timezone selector');
$check(strpos($autoPosterView, 'ap-countdown') !== false && strpos($autoPosterView, 'data-synced') !== false
    && strpos($autoPosterView, 'mo') !== false && strpos($autoPosterView, 'setInterval(tick, 1000)') !== false,
    'auto-poster queue must show a live months/days/hours/minutes/seconds countdown');
$check(strpos($autoPosterView, 'ap-log') !== false && strpos($autoPosterView, 'ap-pill') !== false
    && strpos($autoPosterView, 'ap-time-relative') !== false && strpos($autoPosterView, 'data-uts') !== false,
    'auto-poster posting log must render status pills and relative timestamps');
$autoPosterConfigModel = (string) file_get_contents("$root/app/Models/AutoPosterConfig.php");
$check(strpos($autoPosterConfigModel, 'validatedTimezone') !== false && strpos($autoPosterConfigModel, "'timezone' =>") !== false,
    'auto-poster config must persist a validated timezone');
$twitterClient = (string) file_get_contents("$root/app/Models/TwitterClient.php");
$check(strpos($twitterClient, 'oauth1Header') !== false && strpos($twitterClient, 'HMAC-SHA1') !== false,
    'twitter client must sign media uploads with OAuth1.0a');
$check(strpos($twitterClient, "mediaAuth('POST', [], \$token)") !== false && strpos($twitterClient, "'multipart'") !== false,
    'twitter client must exclude the multipart body from the APPEND signature');
$check(strpos($twitterClient, 'consumer_key') !== false && strpos($twitterClient, 'oauth_token_secret') !== false,
    'twitter client must read OAuth1 consumer/access-token secrets');
$check(strpos($autoPosterView, 'twitter_consumer_key') !== false && strpos($autoPosterView, 'twitter_oauth_token_secret') !== false,
    'auto-poster settings must expose OAuth1 media-upload fields');
$autoPosterController = (string) file_get_contents("$root/app/Controllers/AutoPosterController.php");
$check(strpos($autoPosterController, 'twitter_oauth_token_secret') !== false,
    'auto-poster settings save must persist the OAuth1 token secret');
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

// --- System page cron oversight -------------------------------------------
$systemView   = (string) file_get_contents("$root/views/admin/system.php");
$systemCtrl   = (string) file_get_contents("$root/app/Controllers/SystemController.php");
$check(strpos($systemView, 'Scheduled tasks (cron)') !== false
    && strpos($systemView, 'cronJobs') !== false && strpos($systemView, 'lastRun') !== false,
    'system view must render a scheduled-tasks (cron) table with last-run times');
$check(strpos($systemCtrl, 'private function cronJobs') !== false
    && strpos($systemCtrl, 'relativeAge') !== false
    && strpos($systemCtrl, 'autopostRecentFailure') !== false,
    'system controller must assemble cron-jobs status from log files');
$check(strpos($systemView, 'Configure schedules') !== false
    && stripos($systemView, 'save &amp; apply schedules') !== false
    && strpos($systemView, 'cron_housekeeping_min') !== false && strpos($systemView, 'cron_backup_hour') !== false
    && strpos($systemView, 'cron_drill_dow') !== false,
    'system view must offer a super-admin cron schedule config form');
$check(strpos($systemCtrl, 'saveCronSchedule') !== false && strpos($systemCtrl, 'cronSchedule()') !== false
    && strpos($systemCtrl, 'super_admin') !== false && strpos($systemCtrl, 'apply_cron.php') !== false,
    'system controller must persist + apply schedules only for super admin via the root helper');
$routesFile = (string) file_get_contents("$root/config/routes.php");
$check(strpos($routesFile, 'system/cron-schedule') !== false,
    'route for saving the cron schedule must exist');
$applyCron = (string) file_get_contents("$root/bin/apply_cron.php");
$check(strpos($applyCron, 'posix_geteuid') !== false && strpos($applyCron, '/etc/cron.d/') !== false
    && strpos($applyCron, 'schedules.json') !== false && strpos($applyCron, 'systemctl restart gallery-video-export gallery-photo-edit') !== false,
    'apply_cron.php must require root, write /etc/cron.d and restart worker services');

// --- Report ----------------------------------------------------------------
echo "smoke: $checks checks\n";
if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL - $f\n";
    }
    exit(1);
}
echo "smoke: all passed\n";
