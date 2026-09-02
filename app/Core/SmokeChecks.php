<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Every static smoke check as a runnable test definition, shared by
 * tests/smoke.php (CI / deploy gate) and the in-app admin "Test suite"
 * (App\Core\TestSuite merges these into its registry). Single source of
 * truth: a check added here shows up in both places.
 *
 * Each entry mirrors the TestSuite registry shape:
 *   ['id' => string, 'group' => string, 'name' => string, 'run' => callable(): array{pass: bool, detail: string}]
 *
 * The checks are deliberately static (file existence, route table sanity,
 * schema.sql shape, source-code markers, debug leftover scan) so they need
 * no database and no web server — identical behaviour in CI and on the
 * server.
 */
class SmokeChecks
{
    /** Build (once, then cache) the full list of smoke test definitions. */
    public static function all(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $root = dirname(__DIR__, 2);

        $tests = [];
        $add = static function (string $id, string $group, string $name, callable $run) use (&$tests): void {
            $tests[$id] = ['id' => $id, 'group' => $group, 'name' => $name, 'run' => $run];
        };
        $ok  = static fn (string $detail): array => ['pass' => true, 'detail' => $detail];
        $bad = static fn (string $detail): array => ['pass' => false, 'detail' => $detail];
        $read = static function (string $path) use ($root): string {
            return is_file($path) ? (string) file_get_contents($path) : '';
        };

        // ------------------------------------------------------------ Files
        $files = [
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
        ];
        foreach ($files as $rel) {
            $slug = str_replace(['/', '.'], '_', $rel);
            $add("smoke.file.$slug", 'Smoke · Files', "Exists: $rel", static function () use ($root, $rel, $ok, $bad): array {
                return is_file("$root/$rel") ? $ok('present') : $bad("missing file: $rel");
            });
        }
        $add('smoke.dir.migrations', 'Smoke · Files', 'Exists: database/migrations directory', static function () use ($root, $ok, $bad): array {
            return is_dir("$root/database/migrations") ? $ok('present') : $bad('missing directory: database/migrations');
        });

        // ----------------------------------------------------------- Routes
        $routes = require "$root/config/routes.php";

        $add('smoke.routes.non_trivial', 'Smoke · Routes', 'Route table loads and is non-trivial', static function () use ($routes, $ok, $bad): array {
            return is_array($routes) && count($routes) > 20 ? $ok(count($routes) . ' routes') : $bad('routes.php must return a non-trivial list');
        });

        $routeRe = static fn (string $path): string => preg_replace('#\{[a-zA-Z]+\}#', '{param}', $path);
        $seen = [];
        $duplicates = [];
        foreach ($routes as $route) {
            $key = strtoupper((string) $route[0]) . ' ' . $routeRe((string) $route[1]);
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;
        }
        $add('smoke.routes.duplicates', 'Smoke · Routes', 'No duplicate route patterns', static function () use ($duplicates, $ok, $bad): array {
            return $duplicates === [] ? $ok('unique') : $bad('duplicate routes: ' . implode(', ', $duplicates));
        });

        // One check per route: controller file exists AND action method exists.
        foreach ($routes as $i => $route) {
            $key = strtoupper((string) $route[0]) . ' ' . $routeRe((string) $route[1]);
            [$class, $action] = explode('@', (string) $route[2]);
            $file = "$root/app/Controllers/$class.php";
            $add("smoke.route.$i", 'Smoke · Routes', "Route resolves: $key", static function () use ($key, $class, $action, $file, $ok, $bad): array {
                if (!is_file($file)) {
                    return $bad("route {$key}: missing controller app/Controllers/$class.php");
                }
                $src = (string) file_get_contents($file);
                if (!preg_match('/function\s+' . preg_quote($action, '/') . '\s*\(/', $src)) {
                    return $bad("route {$key}: $class@$action not found");
                }
                return $ok("$class@$action resolves");
            });
        }

        // ----------------------------------------------------------- Schema
        $schema = $read("$root/schema.sql");
        preg_match_all('/CREATE TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $schema, $m);
        $tables = array_map('strtolower', $m[1]);

        foreach (['users', 'galleries', 'photos', 'subscriptions', 'storage_snapshots', 'support_replies', 'gallery_favorites', 'saved_searches'] as $must) {
            $add("smoke.schema.table.$must", 'Smoke · Schema', "schema.sql has table: $must", static function () use ($must, $tables, $ok, $bad): array {
                return in_array($must, $tables, true) ? $ok('present') : $bad("schema.sql missing table: $must");
            });
        }

        foreach (['last_seen_at' => 'users', 'email_verified_at' => 'users', 'email_verification_token' => 'users', 'video_count' => 'storage_snapshots', 'min_level' => 'galleries', 'membership_number' => 'subscriptions'] as $col => $table) {
            $add("smoke.schema.col.$table.$col", 'Smoke · Schema', "schema.sql has column: $table.$col", static function () use ($col, $table, $schema, $ok, $bad): array {
                return preg_match('/CREATE TABLE(\s+IF\s+NOT\s+EXISTS)?\s+' . $table . '\b(?:(?!CREATE TABLE).)*' . $col . '/is', $schema) === 1
                    ? $ok('present')
                    : $bad("schema.sql: $table.$col missing");
            });
        }

        $add('smoke.schema.idx_login_attempts', 'Smoke · Schema', 'login_attempts.attempted_at index in schema.sql', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'idx_login_attempts_at') !== false ? $ok('present') : $bad('schema.sql: login_attempts.attempted_at index missing');
        });
        $add('smoke.schema.content_views_table', 'Smoke · Schema', 'schema.sql has table: content_views', static function () use ($tables, $ok, $bad): array {
            return in_array('content_views', $tables, true) ? $ok('present') : $bad('schema.sql missing table: content_views');
        });
        $add('smoke.schema.content_views_uq', 'Smoke · Schema', 'content_views unique key in schema.sql', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'uq_content_views_type_id_date') !== false ? $ok('present') : $bad('schema.sql: content_views unique key missing');
        });
        $add('smoke.schema.apq_table', 'Smoke · Schema', 'schema.sql has table: auto_poster_queue', static function () use ($tables, $ok, $bad): array {
            return in_array('auto_poster_queue', $tables, true) ? $ok('present') : $bad('schema.sql missing table: auto_poster_queue');
        });
        $add('smoke.schema.apq_media', 'Smoke · Schema', 'auto_poster_queue has media_ids + scheduled_at', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'media_ids') !== false && strpos($schema, 'scheduled_at') !== false
                ? $ok('columns present')
                : $bad('schema.sql: auto_poster_queue must carry media_ids and scheduled_at columns');
        });
        $add('smoke.schema.apq_index', 'Smoke · Schema', 'auto_poster_queue scheduled index in schema.sql', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'idx_apq_scheduled') !== false ? $ok('present') : $bad('schema.sql: auto_poster_queue scheduled index missing');
        });

        // ------------------------------------------------------ Auto Poster
        $apq = $read("$root/app/Models/AutoPostQueue.php");
        $add('smoke.ap.domain', 'Smoke · Auto Poster', 'AutoPostQueue recommends the site domain', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'amethyst2213.com') !== false ? $ok('domain present') : $bad('auto-post recommendations must include the site domain');
        });
        $add('smoke.ap.char_limit', 'Smoke · Auto Poster', 'Custom text capped at 280 characters', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'mb_substr(trim($text), 0, 280)') !== false ? $ok('280 cap') : $bad('auto-post queue must cap custom text at 280 characters');
        });
        $add('smoke.ap.media_cap', 'Smoke · Auto Poster', 'Attachments capped at 4 media files', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'MAX_ATTACHED_MEDIA') !== false ? $ok('MAX_ATTACHED_MEDIA present') : $bad('auto-post queue must cap attachments at 4 media files');
        });
        $add('smoke.ap.web_variant', 'Smoke · Auto Poster', 'Uploads the web-optimized image variant', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'preferredMediaPath') !== false && strpos($apq, "'/web_'") !== false
                ? $ok('web variant preferred')
                : $bad('auto-post queue must upload the web-optimized image variant');
        });
        $add('smoke.ap.blur', 'Smoke · Auto Poster', 'Attached images blurred 75% before posting', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'create_blurred_copy') !== false && strpos($apq, 'POST_IMAGE_BLUR_PERCENT = 100') !== false
                ? $ok('75% blur')
                : $bad('auto-post queue must blur attached images (75%) before posting');
        });
        $helpers = $read("$root/app/Core/helpers.php");
        $add('smoke.ap.blur_helper', 'Smoke · Auto Poster', 'helpers provide a blurred temp copy', static function () use ($helpers, $ok, $bad): array {
            return strpos($helpers, 'function create_blurred_copy') !== false && strpos($helpers, 'IMG_FILTER_GAUSSIAN_BLUR') !== false
                ? $ok('blur helper present')
                : $bad('helpers must provide a blurred temp copy that never overwrites the source');
        });
        $add('smoke.ap.timezone', 'Smoke · Auto Poster', 'Schedule times converted to/from configured timezone', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'displaySchedule') !== false && strpos($apq, 'schedulerTimezone') !== false && strpos($apq, "setTimezone(new DateTimeZone('UTC'))") !== false
                ? $ok('timezone conversion present')
                : $bad('auto-post queue must convert schedule times to/from the configured timezone');
        });
        $add('smoke.ap.cta', 'Smoke · Auto Poster', 'Recommendations carry the call-to-action text', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, "'come visit my site to see what else I get myself into!! amethyst2213.com'") !== false
                ? $ok('CTA present')
                : $bad('auto-post recommendations must carry the call-to-action text');
        });
        $add('smoke.ap.tags', 'Smoke · Auto Poster', 'Recommendations tag up to 20 categories', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'MAX_TAGS = 20') !== false ? $ok('MAX_TAGS = 20') : $bad('auto-post recommendations must tag up to 20 categories');
        });
        $apw = $read("$root/bin/autopost_worker.php");
        $add('smoke.ap.worker_due', 'Smoke · Auto Poster', 'Worker publishes due queue rows', static function () use ($apw, $ok, $bad): array {
            return strpos($apw, 'AutoPostQueue::due') !== false ? $ok('AutoPostQueue::due used') : $bad('autopost worker must publish due queue rows');
        });
        $add('smoke.ap.worker_lock', 'Smoke · Auto Poster', 'Worker locks against overlapping runs', static function () use ($apw, $ok, $bad): array {
            return strpos($apw, 'flock') !== false ? $ok('flock used') : $bad('autopost worker must lock against overlapping runs');
        });
        $apv = $read("$root/views/admin/auto_poster.php");
        $add('smoke.ap.view_text', 'Smoke · Auto Poster', 'Recommended posts editable text field', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'name="text"') !== false ? $ok('text field') : $bad('auto-poster recommended posts must be editable text');
        });
        $add('smoke.ap.view_datetime', 'Smoke · Auto Poster', 'Publish date/time field exposed', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'datetime-local') !== false ? $ok('datetime-local') : $bad('auto-poster must expose a publish date/time field');
        });
        $add('smoke.ap.view_media', 'Smoke · Auto Poster', 'Queue displays attached media', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'mediaFiles') !== false ? $ok('mediaFiles') : $bad('auto-poster queue must display attached media');
        });
        $add('smoke.ap.view_tz', 'Smoke · Auto Poster', 'Schedule timezone selector exposed', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'Schedule timezone') !== false && strpos($apv, 'DateTimeZone::listIdentifiers') !== false
                ? $ok('timezone selector')
                : $bad('auto-poster settings must expose a schedule-timezone selector');
        });
        $add('smoke.ap.view_countdown', 'Smoke · Auto Poster', 'Live mo/d/h/m/s countdown shown', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'ap-countdown') !== false && strpos($apv, 'data-synced') !== false
                && strpos($apv, 'mo') !== false && strpos($apv, 'setInterval(tick, 1000)') !== false
                ? $ok('countdown present')
                : $bad('auto-poster queue must show a live months/days/hours/minutes/seconds countdown');
        });
        $add('smoke.ap.view_log', 'Smoke · Auto Poster', 'Posting log renders pills + relative times', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'ap-log') !== false && strpos($apv, 'ap-pill') !== false
                && strpos($apv, 'ap-time-relative') !== false && strpos($apv, 'data-uts') !== false
                ? $ok('log pills present')
                : $bad('auto-poster posting log must render status pills and relative timestamps');
        });
        $apc = $read("$root/app/Models/AutoPosterConfig.php");
        $add('smoke.ap.config_tz', 'Smoke · Auto Poster', 'Config persists validated timezone', static function () use ($apc, $ok, $bad): array {
            return strpos($apc, 'validatedTimezone') !== false && strpos($apc, "'timezone' =>") !== false
                ? $ok('validated timezone')
                : $bad('auto-poster config must persist a validated timezone');
        });
        $twc = $read("$root/app/Models/TwitterClient.php");
        $add('smoke.ap.twitter_oauth1', 'Smoke · Auto Poster', 'X uploads signed with OAuth1.0a', static function () use ($twc, $ok, $bad): array {
            return strpos($twc, 'oauth1Header') !== false && strpos($twc, 'HMAC-SHA1') !== false
                ? $ok('OAuth1 header')
                : $bad('twitter client must sign media uploads with OAuth1.0a');
        });
        $add('smoke.ap.twitter_multipart', 'Smoke · Auto Poster', 'Multipart body excluded from APPEND signature', static function () use ($twc, $ok, $bad): array {
            return strpos($twc, "mediaAuth('POST', [], \$token)") !== false && strpos($twc, "'multipart'") !== false
                ? $ok('multipart excluded')
                : $bad('twitter client must exclude the multipart body from the APPEND signature');
        });
        $add('smoke.ap.twitter_secrets', 'Smoke · Auto Poster', 'OAuth1 consumer + access-token secrets read', static function () use ($twc, $ok, $bad): array {
            return strpos($twc, 'consumer_key') !== false && strpos($twc, 'oauth_token_secret') !== false
                ? $ok('secrets read')
                : $bad('twitter client must read OAuth1 consumer/access-token secrets');
        });
        $add('smoke.ap.view_twitter_fields', 'Smoke · Auto Poster', 'Settings expose OAuth1 media-upload fields', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'twitter_consumer_key') !== false && strpos($apv, 'twitter_oauth_token_secret') !== false
                ? $ok('fields present')
                : $bad('auto-poster settings must expose OAuth1 media-upload fields');
        });
        $apcCtrl = $read("$root/app/Controllers/AutoPosterController.php");
        $add('smoke.ap.controller_token', 'Smoke · Auto Poster', 'Settings save persists OAuth1 token secret', static function () use ($apcCtrl, $ok, $bad): array {
            return strpos($apcCtrl, 'twitter_oauth_token_secret') !== false ? $ok('persisted') : $bad('auto-poster settings save must persist the OAuth1 token secret');
        });
        $migReadme = $read("$root/database/migrations/README.md");
        $add('smoke.ap.migration_readme', 'Smoke · Auto Poster', 'Migrations README documents schema_migrations', static function () use ($migReadme, $ok, $bad): array {
            return strpos($migReadme, 'schema_migrations') !== false ? $ok('documented') : $bad('database/migrations/README.md must document schema_migrations');
        });

        // ------------------------------------------------- Security & Ops
        $health = $read("$root/app/Controllers/HealthController.php");
        $limiter = $read("$root/app/Core/RateLimiter.php");
        $deploy = $read("$root/scripts/deploy.sh");
        $routesSrc = $read("$root/config/routes.php");
        $add('smoke.sec.health_nostore', 'Smoke · Security', 'HealthController disables response caching', static function () use ($health, $ok, $bad): array {
            return strpos($health, 'Cache-Control: no-store') !== false ? $ok('no-store') : $bad('HealthController must disable response caching');
        });
        $add('smoke.sec.rate_sha256', 'Smoke · Security', 'RateLimiter hashes identifiers with sha256', static function () use ($limiter, $ok, $bad): array {
            return strpos($limiter, "hash('sha256'") !== false ? $ok('sha256') : $bad('RateLimiter must hash identifiers with sha256');
        });
        $add('smoke.sec.deploy_rollback', 'Smoke · Security', 'Deploy script contains rollback handling', static function () use ($deploy, $ok, $bad): array {
            return strpos($deploy, 'rollback') !== false ? $ok('rollback present') : $bad('deploy script must contain rollback handling');
        });
        $add('smoke.sec.health_route', 'Smoke · Security', 'Health route exposed in routes.php', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, "'/health'") !== false ? $ok('health route') : $bad('routes.php must expose health route');
        });

        // --------------------------------------------------------- Hygiene
        $add('smoke.hygiene.debug_leftovers', 'Smoke · Hygiene', 'No var_dump/print_r/dd debug calls in app/', static function () use ($root, $ok, $bad): array {
            $debugHits = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/app"));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($f->getPathname());
                if (preg_match('/(?<![a-zA-Z_>:])(var_dump|print_r)\s*\(|(?<![a-zA-Z_>$])dd\s*\(/', $code)) {
                    $debugHits[] = basename($f->getPathname());
                }
            }
            return $debugHits === [] ? $ok('clean') : $bad('debug calls in: ' . implode(', ', array_unique($debugHits)));
        });
        $adminLayout = $read("$root/views/admin/layout.php");
        $add('smoke.hygiene.admin_layout_user', 'Smoke · Hygiene', 'Admin layout never clobbers $user', static function () use ($adminLayout, $ok, $bad): array {
            return strpos($adminLayout, '$user =') === false ? $ok('no $user assignment') : $bad('views/admin/layout.php must not assign $user (viewAdmin() scope collision)');
        });

        // -------------------------------------------------------- System
        $systemView = $read("$root/views/admin/system.php");
        $systemCtrl = $read("$root/app/Controllers/SystemController.php");
        $add('smoke.sys.cron_table', 'Smoke · System', 'System view renders scheduled-tasks (cron) table', static function () use ($systemView, $ok, $bad): array {
            return strpos($systemView, 'Scheduled tasks (cron)') !== false
                && strpos($systemView, 'cronJobs') !== false && strpos($systemView, 'lastRun') !== false
                ? $ok('cron table present')
                : $bad('system view must render a scheduled-tasks (cron) table with last-run times');
        });
        $add('smoke.sys.cron_status', 'Smoke · System', 'System controller assembles cron-job status from logs', static function () use ($systemCtrl, $ok, $bad): array {
            return strpos($systemCtrl, 'private function cronJobs') !== false
                && strpos($systemCtrl, 'relativeAge') !== false
                && strpos($systemCtrl, 'autopostRecentFailure') !== false
                ? $ok('cron status logic present')
                : $bad('system controller must assemble cron-jobs status from log files');
        });
        $add('smoke.sys.schedule_form', 'Smoke · System', 'System view offers per-job super-admin cron schedule cards', static function () use ($systemView, $ok, $bad): array {
            return strpos($systemView, 'cron-card') !== false
                && stripos($systemView, 'save &amp; apply') !== false
                && strpos($systemView, 'cron_housekeeping_min') !== false && strpos($systemView, 'cron_backup_hour') !== false
                && strpos($systemView, 'cron_drill_dow') !== false
                && strpos($systemView, 'admin/system/cron-schedule/') !== false
                ? $ok('per-job schedule cards present')
                : $bad('system view must offer a super-admin cron schedule config form');
        });
        $add('smoke.sys.schedule_save', 'Smoke · System', 'System controller saves + applies schedules for super admin', static function () use ($systemCtrl, $ok, $bad): array {
            return strpos($systemCtrl, 'saveCronSchedule') !== false && strpos($systemCtrl, 'cronSchedule()') !== false
                && strpos($systemCtrl, 'super_admin') !== false && strpos($systemCtrl, 'apply_cron.php') !== false
                ? $ok('save/apply logic present')
                : $bad('system controller must persist + apply schedules only for super admin via the root helper');
        });
        $add('smoke.sys.cron_route', 'Smoke · System', 'Route for saving the cron schedule exists', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, 'system/cron-schedule') !== false ? $ok('route present') : $bad('route for saving the cron schedule must exist');
        });
        $applyCron = $read("$root/bin/apply_cron.php");
        $add('smoke.sys.apply_cron', 'Smoke · System', 'apply_cron.php requires root, writes /etc/cron.d, restarts workers', static function () use ($applyCron, $ok, $bad): array {
            return strpos($applyCron, 'posix_geteuid') !== false && strpos($applyCron, '/etc/cron.d/') !== false
                && strpos($applyCron, 'schedules.json') !== false && strpos($applyCron, 'systemctl restart gallery-video-export gallery-photo-edit') !== false
                ? $ok('root helper wired')
                : $bad('apply_cron.php must require root, write /etc/cron.d and restart worker services');
        });

        // ------------------------------------------------------ Test suite
        $add('smoke.suite.routes', 'Smoke · Test Suite', 'Test suite routes registered', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, 'admin/test-suite') !== false && strpos($routesSrc, 'admin/test-suite/status') !== false
                && strpos($routesSrc, 'TestSuiteController@index') !== false
                && strpos($routesSrc, 'TestSuiteController@run') !== false
                && strpos($routesSrc, 'TestSuiteController@status') !== false
                ? $ok('routes present')
                : $bad('test suite routes (page / run POST / status GET) must be registered');
        });
        $add('smoke.suite.nav', 'Smoke · Test Suite', 'Admin sidebar links the Test suite page', static function () use ($adminLayout, $ok, $bad): array {
            return strpos($adminLayout, "Test suite") !== false && strpos($adminLayout, '/admin/test-suite') !== false
                ? $ok('nav item present')
                : $bad('admin layout sidebar must link the Test suite nav item');
        });
        $tsCore = $read("$root/app/Core/TestSuite.php");
        $add('smoke.suite.core', 'Smoke · Test Suite', 'TestSuite core exposes tests()/grouped()/run()/writeRun()', static function () use ($tsCore, $ok, $bad): array {
            return strpos($tsCore, 'public static function tests()') !== false
                && strpos($tsCore, 'public static function grouped()') !== false
                && strpos($tsCore, 'public static function run(') !== false
                && strpos($tsCore, 'public static function writeRun(') !== false
                ? $ok('core methods present')
                : $bad('TestSuite core must expose tests()/grouped()/run()/writeRun()');
        });
        $tsCtrl = $read("$root/app/Controllers/TestSuiteController.php");
        $add('smoke.suite.controller', 'Smoke · Test Suite', 'TestSuiteController implements index/run/status + spawn', static function () use ($tsCtrl, $ok, $bad): array {
            return strpos($tsCtrl, 'public function index()') !== false && strpos($tsCtrl, 'public function run()') !== false
                && strpos($tsCtrl, 'public function status()') !== false && strpos($tsCtrl, 'spawnWorker') !== false
                && strpos($tsCtrl, 'requirePermission') !== false
                ? $ok('controller methods present')
                : $bad('TestSuiteController must implement index/run/status + spawn the detached worker');
        });
        $add('smoke.suite.runner_file', 'Smoke · Test Suite', 'bin/test_runner.php exists', static function () use ($root, $ok, $bad): array {
            return is_file("$root/bin/test_runner.php") ? $ok('present') : $bad('missing file: bin/test_runner.php');
        });
        $runner = $read("$root/bin/test_runner.php");
        $add('smoke.suite.runner_drive', 'Smoke · Test Suite', 'bin/test_runner.php drives the TestSuite runner', static function () use ($runner, $ok, $bad): array {
            return strpos($runner, 'App\\Core\\TestSuite') !== false && strpos($runner, 'TestSuite::run(') !== false
                ? $ok('runner wired')
                : $bad('bin/test_runner.php must drive the TestSuite runner');
        });
        $tsView = $read("$root/views/admin/test_suite.php");
        $add('smoke.suite.view', 'Smoke · Test Suite', 'Test suite view renders controls + polls status', static function () use ($tsView, $ok, $bad): array {
            return strpos($tsView, 'ts-run-all') !== false && strpos($tsView, 'ts-run-selected') !== false
                && strpos($tsView, 'fetch(') !== false && strpos($tsView, 'setInterval') !== false
                && strpos($tsView, 'statusUrl') !== false
                ? $ok('view wired')
                : $bad('test suite view must render run controls + poll the status endpoint in real time');
        });
        $gitignore = $read("$root/.gitignore");
        $add('smoke.suite.gitignore', 'Smoke · Test Suite', '.gitignore excludes storage/testruns', static function () use ($gitignore, $ok, $bad): array {
            return strpos($gitignore, 'storage/testruns') !== false ? $ok('ignored') : $bad('.gitignore must exclude the runtime test-run state directory');
        });
        $add('smoke.suite.includes_smoke', 'Smoke · Test Suite', 'Admin test suite includes all smoke checks', static function (): array {
            $registry = TestSuite::tests();
            $smoke = array_filter(array_keys($registry), static fn (string $id): bool => str_starts_with($id, 'smoke.'));
            $count = count($smoke);
            return $count > 0 ? ['pass' => true, 'detail' => $count . ' smoke tests exposed'] : ['pass' => false, 'detail' => 'smoke checks missing from TestSuite registry'];
        });

        $cache = $tests;

        return $cache;
    }
}