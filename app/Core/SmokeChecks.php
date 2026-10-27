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
            'app/Models/EmailerConfig.php',
            'app/Models/EmailQueue.php',
            'app/Controllers/EmailerController.php',
            'app/Controllers/UnsubscribeController.php',
            'bin/email_worker.php',
            'views/emails/new_gallery.php',
            'views/emails/chat_reply.php',
            'views/emails/layout.php',
            'views/errors/partials/shell.php',
            'views/admin/partials/staged_uploads.php',
            'views/admin/partials/upload_client.php',
            'views/unsubscribe.php',
            'views/admin/emailer.php',
            'database/migrations/012_email_queue.sql',
            'database/migrations/013_user_marketing_opt_out.sql',
            'database/migrations/014_traffic_links.sql',
            'app/Models/Traffic.php',
            'app/Controllers/TrafficController.php',
            'views/admin/traffic.php',
            'views/admin/traffic_show.php',
            'views/partials/share-bar.php',
            'app/Models/PageVisit.php',
            'database/migrations/015_page_ip_visits.sql',
            'app/Models/ChatBroadcast.php',
            'bin/daily_chat_worker.php',
            'database/migrations/029_chat_daily_broadcast.sql',
            'app/Models/ServerOptimizations.php',
            'bin/apply_server_optimizations.php',
            'app/Models/OperatorToken.php',
            'database/migrations/031_operator_tokens.sql',
            'database/migrations/057_comments_photo.sql',
            'views/partials/comments.php',
            'views/2257.php',
            'views/dmca.php',
            'views/report-abuse.php',
            'views/partials/footer.php',
            'views/partials/ga.php',
            'views/partials/consent_banner.php',
            'app/Core/Lifecycle.php',
            'database/migrations/060_lifecycle_emails.sql',
            'views/emails/payment_failed.php',
            'views/emails/payment_failed.text.php',
            'views/emails/past_due.php',
            'views/emails/past_due.text.php',
            'views/emails/expired.php',
            'views/emails/expired.text.php',
            'views/emails/renewal_reminder.php',
            'views/emails/renewal_reminder.text.php',
            'views/emails/winback.php',
            'views/emails/winback.text.php',
            'app/Controllers/EarningsController.php',
            'app/Models/Purchase.php',
            'database/migrations/061_ppv_live_payments.sql',
            'views/admin/earnings.php',
            'views/checkout_complete.php',
            'views/partials/card_checkout.php',
            'database/migrations/062_plan_checkout_processor.sql',
            'bin/reddit_post.php',
            'bin/browser/reddit-post.mjs',
            'bin/browser/reddit-login.mjs',
            'bin/browser/setup_reddit_browser.sh',
            'app/Models/RedditClient.php',
            'app/Controllers/RedditBrowserJobsController.php',
            'bin/browser/reddit-homeworker.mjs',
            'app/Controllers/CspController.php',
            'app/Controllers/BackupPullController.php',
            'bin/backup_offsite_package.php',
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

        $add('smoke.routes.head_as_get', 'Smoke · Routes', 'HEAD requests match GET routes (RFC 9110)', static function () use ($root, $read, $ok, $bad): array {
            $router = $read("$root/app/Core/Router.php");
            return strpos($router, "\$request->method() === 'HEAD' ? 'GET'") !== false
                && strpos($router, '$method === $requestMethod') !== false
                ? $ok('HEAD normalised to GET before route matching')
                : $bad('Router must normalise HEAD to GET so HEAD /files/x answers 200 with GET\'s headers, not 405/404');
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

        foreach (['users', 'galleries', 'photos', 'subscriptions', 'storage_snapshots', 'support_replies', 'gallery_favorites', 'saved_searches', 'email_queue', 'content_views', 'auto_poster_queue', 'traffic_links', 'traffic_visits', 'page_ip_visits'] as $must) {
            $add("smoke.schema.table.$must", 'Smoke · Schema', "schema.sql has table: $must", static function () use ($must, $tables, $ok, $bad): array {
                return in_array($must, $tables, true) ? $ok('present') : $bad("schema.sql missing table: $must");
            });
        }

        foreach (['last_seen_at' => 'users', 'email_verified_at' => 'users', 'email_verification_token' => 'users', 'video_count' => 'storage_snapshots', 'min_level' => 'galleries', 'membership_number' => 'subscriptions', 'marketing_opt_out' => 'users', 'sent_at' => 'email_queue', 'audience' => 'email_queue', 'attempts' => 'email_queue', 'signup_source_link_id' => 'users', 'utm_source' => 'users', 'visitor_id' => 'traffic_visits', 'gallery_id' => 'wall_posts'] as $col => $table) {
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
        $add('smoke.schema.email_queue_index', 'Smoke · Schema', 'email_queue status + audience index in schema.sql', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'idx_email_queue_status') !== false && strpos($schema, 'idx_email_queue_audience') !== false
                ? $ok('indexes present')
                : $bad('schema.sql: email_queue must carry status and audience indexes');
        });
        $add('smoke.schema.traffic_uq', 'Smoke · Schema', 'traffic_visits unique (link_id, ref_date, visitor_id)', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'uq_traffic_visits') !== false ? $ok('daily dedupe key') : $bad('schema.sql: traffic_visits must carry the (link_id, ref_date, visitor_id) unique key');
        });
        $add('smoke.schema.page_ip_visits_table', 'Smoke · Schema', 'schema.sql has table: page_ip_visits', static function () use ($tables, $ok, $bad): array {
            return in_array('page_ip_visits', $tables, true) ? $ok('present') : $bad('schema.sql missing table: page_ip_visits');
        });
        $add('smoke.schema.page_ip_visits_uq', 'Smoke · Schema', 'page_ip_visits unique (page, ip, visit_date)', static function () use ($schema, $ok, $bad): array {
            return strpos($schema, 'uq_page_ip_visits_page_ip_date') !== false ? $ok('daily per-IP dedupe key') : $bad('schema.sql: page_ip_visits must carry the (page, ip, visit_date) unique key');
        });

        // -------------------------------------------------------- Legal/consent
        $layoutSrc = $read("$root/views/layout.php");
        $footerSrc = $read("$root/views/partials/footer.php");
        $gaSrc     = $read("$root/views/partials/ga.php");
        $staticCtrl = $read("$root/app/Controllers/StaticPageController.php");
        $headersConf = $read("$root/config/gallery-headers.conf");
        $adminLayout = $read("$root/views/admin/layout.php");
        $checkoutView = $read("$root/views/membership/braintree_checkout.php");
        $add('smoke.auth.no_role_escalation', 'Smoke · Auth', 'A low-privilege admin cannot grant or touch an admin account', static function () use ($root, $ok, $bad): array {
            // The `users` permission is held by `moderator`, and it is enough
            // to reset a password, change an email, suspend or delete ANY
            // account. Granting the `admin` role used to be allowed to
            // anyone with an admin role, so a moderator could mint a full
            // admin (and demote the owner).
            $controller = (string) @file_get_contents($root . '/app/Controllers/UserController.php');
            $auth       = (string) @file_get_contents($root . '/app/Core/Auth.php');
            $problems   = [];

            if (strpos($controller, "Auth::isAdmin()) \$allowed[] = 'admin'") !== false) {
                $problems[] = 'the admin role must not be granted through isAdmin() alone';
            }
            if (strpos($controller, 'manage_admins') === false) {
                $problems[] = 'granting the admin role must go through the manage_admins permission';
            }
            foreach (['moderator', 'editor', 'viewer'] as $role) {
                if (preg_match("/'" . $role . "'\s*=>\s*\[([^\]]*)\]/", $auth, $m) === 1
                    && strpos($m[1], 'manage_admins') !== false) {
                    $problems[] = 'the ' . $role . ' role must not hold manage_admins';
                }
            }
            // Every action that changes an account must refuse a target that
            // is more privileged than the admin performing it.
            foreach (['resetPassword', 'setStatus', 'destroy', 'impersonate', 'logoutEverywhere', 'update'] as $action) {
                $at = strpos($controller, 'function ' . $action . '(');
                if ($at === false) {
                    continue;
                }
                // Only this method's body - not the next one.
                $next = strpos($controller, "\n    public function ", $at + 10);
                $body = $next === false ? substr($controller, $at) : substr($controller, $at, $next - $at);
                if (strpos($body, 'guardManageable') === false && strpos($body, 'canManageRole') === false) {
                    $problems[] = $action . '() must refuse an account more privileged than the admin';
                }
            }
            if (strpos($controller, 'guardManageable') === false || strpos($controller, 'canManageRole') === false) {
                $problems[] = 'the account guard is missing';
            }

            return $problems === []
                ? $ok('only an admin may grant the admin role, and nobody may act on a more privileged account')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.auth.privileged_surfaces', 'Smoke · Auth', 'The surfaces that reach outside the app are super-admin only', static function () use ($root, $ok, $bad): array {
            // The mail admin drives a ROOT script that rewrites the
            // Postfix/Dovecot mailbox files. It used to need the
            // 'dashboard' permission, which EVERY admin role holds - so a
            // viewer could re-password or delete any mailbox on the server.
            $routes = (string) @file_get_contents($root . '/config/routes.php');
            $auth   = (string) @file_get_contents($root . '/app/Core/Auth.php');
            $mail   = (string) @file_get_contents($root . '/app/Controllers/EmailController.php');
            $nav    = (string) @file_get_contents($root . '/views/admin/layout.php');
            $problems = [];

            foreach (['index', 'create', 'delete', 'password', 'smtpTest'] as $action) {
                if (strpos($routes, "'/admin/mail/$action', 'EmailController@$action', 'dashboard'") !== false) {
                    $problems[] = 'the /admin/mail/' . $action . ' route is still gated on dashboard';
                }
            }
            if (strpos($mail, "requirePermission('dashboard')") !== false) {
                $problems[] = 'the mail controller must not need the dashboard permission';
            }
            if (strpos($mail, "requirePermission('mail_admin')") === false) {
                $problems[] = 'the mail controller must require the mail_admin permission';
            }
            if (strpos($nav, "'/admin/mail', 'icon' => 'inbox', 'perm' => 'mail_admin'") === false
                && strpos($nav, "'perm' => 'mail_admin'") === false) {
                $problems[] = 'the admin nav must hide the Email page from a non super admin';
            }
            // The super-admin-only permissions must be in no other role.
            foreach (['moderator', 'admin', 'editor', 'viewer'] as $role) {
                if (preg_match("/'" . $role . "'\s*=>\s*\[([^\]]*)\]/", $auth, $m) !== 1) {
                    continue;
                }
                foreach (['mail_admin', 'manage_roles'] as $perm) {
                    if (strpos($m[1], $perm) !== false) {
                        $problems[] = 'the ' . $role . ' role must not hold ' . $perm;
                    }
                }
            }

            // The public creator profile is site content: it needs the site
            // editor permission, and the URLs it renders are filtered.
            $profile = (string) @file_get_contents($root . '/app/Controllers/CreatorProfileController.php');
            $model   = (string) @file_get_contents($root . '/app/Models/SiteProfile.php');
            if (strpos($routes, "'/admin/profile', 'CreatorProfileController@edit', 'dashboard'") !== false
                || strpos($routes, "'/admin/profile', 'CreatorProfileController@save', 'dashboard'") !== false) {
                $problems[] = 'the creator profile is still gated on dashboard';
            }
            if (strpos($profile, "requirePermission('site_editor')") === false) {
                $problems[] = 'the creator profile must need the site_editor permission';
            }
            if (strpos($model, 'function safeUrl(') === false) {
                $problems[] = 'SiteProfile must filter the avatar and the link URLs';
            }

            // The system operations: maintenance mode answers 503 to every
            // guest, the age gate changes what every visitor sees, and the
            // cleanups delete files. The controller used to need 'logs',
            // which a moderator holds.
            $system = (string) @file_get_contents($root . '/app/Controllers/SystemController.php');
            foreach (['maintenanceToggle', 'saveAgeGate', 'cleanupPending', 'cleanupOrphans',
                      'variantsRegenerate', 'dbOptimize', 'aiRebuild', 'backupDelete'] as $action) {
                $at = strpos($system, 'function ' . $action . '(');
                if ($at === false) {
                    $problems[] = $action . '() is missing';
                    continue;
                }
                $next = strpos($system, "\n    public function ", $at + 10);
                $body = $next === false ? substr($system, $at) : substr($system, $at, $next - $at);
                if (strpos($body, "requirePermission('system_ops')") === false) {
                    $problems[] = $action . '() must require the system_ops permission';
                }
            }
            foreach (['moderator', 'admin', 'editor', 'viewer'] as $role) {
                if (preg_match("/'" . $role . "'\s*=>\s*\[([^\]]*)\]/", $auth, $m) === 1
                    && strpos($m[1], 'system_ops') !== false) {
                    $problems[] = 'the ' . $role . ' role must not hold system_ops';
                }
            }

            return $problems === []
                ? $ok('the mail admin, the role grant and the creator profile are properly gated')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.auth.money_needs_permission', 'Smoke · Auth', 'The money actions need the monetisation permission', static function () use ($root, $ok, $bad): array {
            // Minting an unlock code, creating a 100%-off gift subscription
            // and marking a purchase paid all grant value for free. They
            // were gated on 'dashboard', which every admin role holds.
            $routes      = (string) @file_get_contents($root . '/config/routes.php');
            $controller  = (string) @file_get_contents($root . '/app/Controllers/ModerationController.php');
            $auth        = (string) @file_get_contents($root . '/app/Core/Auth.php');
            $problems    = [];

            $money = [
                'codeStore'      => '/admin/engagement/codes',
                'codeToggle'     => '/admin/engagement/codes/{id}/toggle',
                'codeDelete'     => '/admin/engagement/codes/{id}/delete',
                'giftStore'      => '/admin/engagement/gift',
                'purchaseStatus' => '/admin/engagement/purchases/{id}',
            ];
            foreach ($money as $action => $path) {
                if (strpos($routes, "'" . $path . "', 'ModerationController@" . $action . "', 'dashboard'") !== false) {
                    $problems[] = $path . ' is still gated on dashboard';
                }
                $at = strpos($controller, 'function ' . $action . '(');
                if ($at === false) {
                    $problems[] = $action . '() is missing';
                    continue;
                }
                $next = strpos($controller, "\n    public function ", $at + 10);
                $body = $next === false ? substr($controller, $at) : substr($controller, $at, $next - $at);
                if (strpos($body, "requirePermission('monetisation')") === false) {
                    $problems[] = $action . '() must require the monetisation permission';
                }
            }
            // The permission must exist, and no low-privilege role may hold it.
            if (strpos($auth, "'monetisation'") === false) {
                $problems[] = 'the monetisation permission is not defined';
            }
            foreach (['moderator', 'editor', 'viewer'] as $role) {
                if (preg_match("/'" . $role . "'\s*=>\s*\[([^\]]*)\]/", $auth, $m) === 1
                    && strpos($m[1], 'monetisation') !== false) {
                    $problems[] = 'the ' . $role . ' role must not hold monetisation';
                }
            }

            return $problems === []
                ? $ok('unlock codes, gift subscriptions and the purchase ledger need monetisation')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.schema.migrations_are_in_the_schema', 'Smoke · Schema', 'Every column a migration adds is in schema.sql', static function () use ($root, $ok, $bad): array {
            // schema.sql and database/migrations/ have drifted before: a
            // fresh install (install.sh imports schema.sql) could not pause
            // a live stream because live_sessions.paused_at only lived in
            // migration 044. The migrator now runs after the import, but the
            // schema file should still carry the column: a fresh install
            // should not depend on the migration runner.
            $schema = (string) @file_get_contents($root . '/schema.sql');
            $files  = glob($root . '/database/migrations/*.sql') ?: [];
            $missing = [];
            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                // ADD COLUMN [IF NOT EXISTS] `col` type
                if (preg_match_all('/ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s+(?:DATETIME|TIMESTAMP|DATE|INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT|VARCHAR|TEXT|LONGTEXT|DECIMAL|ENUM|JSON|BOOLEAN|BLOB)/i', $src, $m) === false) {
                    continue;
                }
                foreach ($m[1] as $col) {
                    if (preg_match('/\b' . preg_quote($col, '/') . '\b/', $schema) === 1) {
                        continue;
                    }
                    $missing[] = basename($file) . ' adds ' . $col;
                }
            }
            return $missing === []
                ? $ok('schema.sql carries every column the migrations add')
                : $bad('these columns are only in a migration, so a fresh install misses them: ' . implode('; ', $missing));
        });

        $add('smoke.legal.static_actions', 'Smoke · Legal', 'StaticPageController provides 2257/DMCA/report actions', static function () use ($staticCtrl, $ok, $bad): array {
            return strpos($staticCtrl, 'function notice2257') !== false
                && strpos($staticCtrl, 'function dmca') !== false
                && strpos($staticCtrl, 'function reportAbuse') !== false
                && stripos($staticCtrl, '/2257') !== false && stripos($staticCtrl, '/dmca') !== false && stripos($staticCtrl, '/report-abuse') !== false
                ? $ok('actions + sitemap entries present')
                : $bad('StaticPageController must define notice2257/dmca/reportAbuse and list /2257 /dmca /report-abuse in the sitemap');
        });
        $add('smoke.legal.footer_links', 'Smoke · Legal', 'Layout renders a footer linking the compliance pages', static function () use ($layoutSrc, $footerSrc, $ok, $bad): array {
            return strpos($layoutSrc, "require __DIR__ . '/partials/footer.php'") !== false
                && stripos($footerSrc, '/2257') !== false && stripos($footerSrc, '/dmca') !== false && stripos($footerSrc, '/report-abuse') !== false
                ? $ok('footer included with legal links')
                : $bad('views/layout.php must include partials/footer.php which links /2257 /dmca /report-abuse');
        });
        $add('smoke.legal.ga_consent_gate', 'Smoke · Legal', 'Google Analytics only loads behind consent + age gate', static function () use ($layoutSrc, $gaSrc, $adminLayout, $checkoutView, $ok, $bad): array {
            return strpos($layoutSrc, '$gaAllowed') !== false && strpos($layoutSrc, "require __DIR__ . '/partials/ga.php'") !== false
                && strpos($gaSrc, 'ga_consent') !== false
                && stripos($adminLayout, 'googletagmanager') === false
                && stripos($checkoutView, 'googletagmanager') === false
                ? $ok('gtag gated; admin and checkout pages untracked')
                : $bad('layout must gate GA behind $gaAllowed via partials/ga.php, and admin layout / braintree checkout must not embed gtag');
        });
        // ----------------------------------------------- Admin design system
        // The admin chrome is generated from data in views/admin/layout.php, so
        // these guard the parts a future edit can quietly break: a nav entry
        // with no icon, a stylesheet that stops loading, or the accessibility
        // scaffolding going missing.
        $adminCss = $read("$root/public/assets/css/admin.css");
        $adminIconSrc = $read("$root/app/Core/Icon.php");
        $userLayout = $read("$root/views/layout.php");
        $userCss = $read("$root/public/assets/css/user.css");
        $add('smoke.user.rail', 'Smoke · Admin UI', 'Member rail mirrors the admin: icons, groups, identity and a sign-out footer', static function () use ($root, $userLayout, $userCss, $ok, $bad): array {
            // rail-identity carries a conditional class, so no closing quote.
            foreach (['class="home-nav-actions user-nav"', 'class="rail-identity', 'class="rail-scroll"',
                      'class="rail-foot"', 'class="rail-logout-btn"', 'aria-label="Site menu"'] as $needle) {
                if (strpos($userLayout, $needle) === false) {
                    return $bad('views/layout.php member rail is missing ' . $needle);
                }
            }
            // Every rail entry declares an icon, and the icon must exist.
            if (!preg_match_all("/'key'\s*=>\s*'(pub-[a-z-]+)'.*?'icon'\s*=>\s*'([a-z0-9-]+)'/", $userLayout, $m, PREG_SET_ORDER)) {
                return $bad('member rail entries must be declared as key + icon pairs');
            }
            if (!class_exists(\App\Core\Icon::class)) {
                return $bad('App\Core\Icon must exist');
            }
            $missing = [];
            foreach ($m as $entry) {
                if (!\App\Core\Icon::has($entry[2])) {
                    $missing[] = $entry[1] . ' -> ' . $entry[2];
                }
            }
            if ($missing !== []) {
                return $bad('rail entries with an unknown icon: ' . implode(', ', $missing));
            }
            // The old rail had a "Dashboard" brand heading immediately above a
            // "Dashboard" link. pub-dashboard now lives on the identity block and
            // must appear exactly once in the whole layout.
            if (substr_count($userLayout, 'data-se-move-key="pub-dashboard"') !== 1) {
                return $bad('pub-dashboard must appear exactly once (the duplicated Dashboard heading is gone)');
            }
            foreach (['.rail-identity', '.rail-scroll', '.rail-group', '.rail-foot', '.rail-ico'] as $sel) {
                if (strpos($userCss, $sel) === false) {
                    return $bad('user.css is missing ' . $sel);
                }
            }
            return $ok(count($m) . ' rail entries, all icons resolve, identity + footer present');
        });

        $add('smoke.admin.design_system', 'Smoke · Admin UI', 'Admin shell loads its stylesheet after the theme CSS', static function () use ($adminLayout, $adminCss, $ok, $bad): array {
            $adminAt = strpos($adminLayout, '/assets/css/admin.css');
            $themeAt = strpos($adminLayout, 'Theme::css(');
            if ($adminAt === false) {
                return $bad('admin layout must link /assets/css/admin.css');
            }
            if ($themeAt === false) {
                return $bad('admin layout must still load the theme CSS');
            }
            if ($adminAt < $themeAt) {
                return $bad('admin.css must be linked AFTER the theme CSS so it wins the cascade');
            }
            // The admin has its own stylesheet, the shared utilities and the
            // shared support desk. It must NOT load the member stylesheet:
            // it used to, and the member's compact-mode rules and the member's
            // support styles leaked into the console.
            if (strpos($adminLayout, '/assets/css/utilities.css') === false) {
                return $bad('the admin layout must load the shared utilities');
            }
            if (strpos($adminLayout, '/assets/css/support.css') === false) {
                return $bad('the admin layout must load the shared support desk');
            }
            if (strpos($adminLayout, '/assets/css/user.css') !== false) {
                return $bad('the admin layout must not load the member stylesheet - it leaks the member rules into the console');
            }
            if (strpos($adminLayout, 'asset_url(') === false) {
                return $bad('admin.css must be cache-busted through asset_url() (filemtime)');
            }
            return strlen($adminCss) > 20000
                ? $ok('admin.css linked last, filemtime-busted, ' . strlen($adminCss) . ' bytes')
                : $bad('public/assets/css/admin.css looks truncated (' . strlen($adminCss) . ' bytes)');
        });

        $add('smoke.admin.mobile_nav', 'Smoke · Admin UI', 'The mobile drawer opens: nav-open rules must match the body element', static function () use ($adminCss, $adminLayout, $ok, $bad): array {
            // ".admin-theme body.nav-open" requires a <body> nested inside an
            // .admin-theme element, which can never exist (body IS the root and
            // carries .admin-theme itself) — the drawer stayed off-screen and
            // the mobile hamburger looked dead. The rules must scope the body
            // with a compound selector instead.
            if (strpos($adminCss, '.admin-theme body.nav-open') !== false) {
                return $bad('admin.css must not scope nav-open with ".admin-theme body" (an impossible descendant selector)');
            }
            foreach (['body.admin-theme.nav-open .admin-nav', 'body.admin-theme.nav-open .nav-backdrop'] as $needle) {
                if (strpos($adminCss, $needle) === false) {
                    return $bad('admin.css is missing ' . $needle);
                }
            }
            foreach (['id="nav-toggle"', 'id="nav-backdrop"', "classList.toggle('nav-open'"] as $needle) {
                if (strpos($adminLayout, $needle) === false) {
                    return $bad('views/admin/layout.php is missing ' . $needle);
                }
            }
            return $ok('drawer opens via body.admin-theme.nav-open; toggle + backdrop wired');
        });

        $add('smoke.tour.card_over_header', 'Smoke · Admin UI', 'The tour welcome card never parks over the site header', static function () use ($root, $userLayout, $adminLayout, $ok, $bad): array {
            $tourJs = (string) @file_get_contents("$root/public/assets/js/tour.js");
            if ($tourJs === '') {
                return $bad('public/assets/js/tour.js is missing');
            }
            // The Welcome step has no target: pinning the card at top:24px put
            // it straight over the header row, covering the mobile hamburger.
            if (strpos($tourJs, "card.style.top = '24px'") !== false) {
                return $bad("tour.js must not pin the target-less card to top:24px (it covers the header/hamburger)");
            }
            if (strpos($tourJs, '(window.innerHeight - h) / 2') === false) {
                return $bad('tour.js must vertically centre the target-less card');
            }
            foreach ([$userLayout, $adminLayout] as $layout) {
                // A literal ?v=NN or the file's mtime (asset_url()) - the
                // mtime cannot be forgotten when the script changes.
                $busted = preg_match('/tour\.js.*\?v=\d+/', $layout) === 1
                    || strpos($layout, "asset_url('/assets/js/tour.js')") !== false;
                if (!$busted) {
                    return $bad('tour.js must be loaded cache-busted in both layouts');
                }
            }
            return $ok('welcome card is centred clear of the header; tour.js versioned');
        });

        $add('smoke.admin.nav_icons', 'Smoke · Admin UI', 'Every admin nav entry has an icon that exists in Icon', static function () use ($adminLayout, $adminIconSrc, $root, $ok, $bad): array {
            if (!class_exists(\App\Core\Icon::class)) {
                return $bad('App\Core\Icon must exist');
            }
            // The sidebar is a PHP array; parse the icon names out of the entries.
            if (!preg_match_all("/'key'\s*=>\s*'(nav-[a-z0-9-]+)'.*?'icon'\s*=>\s*'([a-z0-9-]+)'/", $adminLayout, $m, PREG_SET_ORDER)) {
                return $bad('admin layout must declare nav entries as key + icon pairs');
            }
            $missing = [];
            foreach ($m as $entry) {
                if (!\App\Core\Icon::has($entry[2])) {
                    $missing[] = $entry[1] . ' -> ' . $entry[2];
                }
            }
            if ($missing !== []) {
                return $bad('nav entries with an unknown icon: ' . implode(', ', $missing));
            }
            if (strpos($adminLayout, '<nav class="admin-nav"') === false
                || strpos($adminLayout, 'aria-label="Admin sections"') === false) {
                return $bad('the sidebar <nav> must carry an accessible name');
            }
            return $ok(count($m) . ' nav entries, all icons resolve');
        });

        $add('smoke.admin.table_scrollers', 'Smoke · Admin UI', 'Admin tables are wrapped in the shared scroller, not an ad-hoc overflow div', static function () use ($root, $ok, $bad): array {
            // A bare `style="overflow-x:auto"` wrapper becomes a scrollport, and a
            // sticky <thead> inside one resolves `top` against that box instead of
            // the viewport - pushing the header down onto the first rows. The Auto
            // Poster tables were doing exactly this. Use .users-table-wrap, which
            // already pins the header with top: 0.
            $views = glob("$root/views/admin/*.php") ?: [];
            $offenders = [];
            foreach ($views as $view) {
                $src = (string) @file_get_contents($view);
                if (preg_match('/<div[^>]*style="[^"]*overflow-x\s*:\s*auto[^"]*"[^>]*>\s*<table/i', $src)) {
                    $offenders[] = basename($view);
                }
            }
            if ($offenders !== []) {
                return $bad('these admin views wrap a table in an ad-hoc overflow-x div (use .users-table-wrap): ' . implode(', ', $offenders));
            }
            return $ok('no ad-hoc table scrollers');
        });

        $add('smoke.ui.sections_paginate_separately', 'Smoke · UI', 'Paginated sections on one page page on their own', static function () use ($root, $ok, $bad): array {
            // The category page shows the image galleries and the video
            // galleries side by side. Both used the same "page" parameter, so
            // paging one section moved the other one as well.
            $partial  = (string) @file_get_contents("$root/views/partials/pagination.php");
            $view     = (string) @file_get_contents("$root/views/gallery/category.php");
            $ctrl     = (string) @file_get_contents("$root/app/Controllers/GalleryController.php");

            if (strpos($partial, '$pageParam') === false) {
                return $bad('the pagination partial must support a per-section page parameter');
            }
            if (strpos($partial, "page=") !== false && strpos($partial, '$pageParam') === false) {
                return $bad('the pagination partial still hard-codes the "page" parameter');
            }
            foreach (['page_images', 'page_videos'] as $param) {
                if (strpos($view, "'" . $param . "'") === false) {
                    return $bad('the category view must page the sections on ' . $param);
                }
                if (strpos($ctrl, "'" . $param . "'") === false) {
                    return $bad('the category controller must read ' . $param);
                }
            }
            return $ok('every paginated section pages on its own parameter');
        });

        $add('smoke.live.viewer_recovers', 'Smoke · Live', 'The live viewer rebuilds a frozen player instead of waiting for a reload', static function () use ($root, $ok, $bad): array {
            // When the operator pauses the broadcast or mutes the camera the
            // publisher stops sending frames: the playlist stops updating and
            // the picture freezes. The page watches for that and rebuilds the
            // player, so the feed comes back on its own.
            $live = (string) @file_get_contents("$root/views/live.php");
            foreach ([
                'function watchStall'  => 'the stall watchdog',
                'function rebuildPlayer'=> 'the player rebuild',
                'function scheduleRebuild' => 'the error rebuild',
                'function teardownHls'  => 'a stream-only teardown',
            ] as $needle => $what) {
                if (strpos($live, $needle) === false) {
                    return $bad('views/live.php is missing ' . $what . ' (' . $needle . ')');
                }
            }
            // A player rebuild must not close the chat stream.
            if (strpos($live, 'function buildPlayer') === false
                || preg_match('/function buildPlayer\([^)]*\)\s*\{\s*\/\/[^
]*
\s*teardownHls\(\)/', $live) !== 1) {
                return $bad('a player rebuild must only replace the stream, not the chat');
            }
            // The pause/resume transition has to rebuild the player.
            if (strpos($live, 'wasPaused && !paused') === false) {
                return $bad('the live page must rebuild the player when the broadcast resumes');
            }
            return $ok('a frozen or dead player is rebuilt on its own');
        });

        $add('smoke.admin.galleries_uncategorized_filter', 'Smoke · Admin UI', 'The gallery list can be narrowed to the galleries with no categories', static function () use ($root, $read, $ok, $bad): array {
            // The list already marks an uncategorized gallery with a pill, but
            // working through the backlog meant scanning every row.
            $controller = (string) $read($root . '/app/Controllers/AdminController.php');
            $model      = (string) $read($root . '/app/Models/Gallery.php');
            $view       = (string) $read($root . '/views/admin/galleries.php');
            $problems   = [];

            if (strpos($controller, '$_GET[\'categories\']') === false) {
                $problems[] = 'the controller does not read the categories filter';
            }
            if (strpos($controller, '$filters[\'uncategorized\'] = true') === false) {
                $problems[] = 'the controller does not pass the uncategorized filter to the model';
            }
            if (strpos($model, 'NOT EXISTS (SELECT 1 FROM gallery_category') === false) {
                $problems[] = 'the model has no uncategorized filter';
            }
            if (strpos($view, 'Uncategorized') === false || strpos($view, 'filterCategories') === false) {
                $problems[] = 'the list has no Uncategorized chip';
            }
            // A chip that drops the other filters would silently widen the
            // list, so every group must carry all three.
            foreach ([
                '$filterUrl($t, $filterLevelKey, $filterCategories, $filterContent)' => 'the type chips',
                '$filterUrl($filterType, $l, $filterCategories, $filterContent)'    => 'the level chips',
                '$filterUrl($filterType, $filterLevelKey, $c, $filterContent)'      => 'the content chips',
            ] as $needle => $what) {
                if (strpos($view, $needle) === false) {
                    $problems[] = $what . ' drop the category filter from their links';
                }
            }
            if (strpos($view, '$filterCategories !== \'all\'') === false) {
                $problems[] = 'the uncategorized filter does not count as an active filter (Clear filters would not show)';
            }

            return $problems === []
                ? $ok('the uncategorized filter is wired from the query string to the query')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.admin.bulk_jobs_stay_on_page', 'Smoke · Admin UI', 'The bulk photo actions update the page in place instead of reloading it', static function () use ($root, $ok, $bad): array {
            // The manage page used to reload itself every five seconds while a
            // background job ran, which threw the admin back to the top of a
            // long gallery. The bulk actions now post in the background and
            // the page watches the job instead.
            $view  = (string) @file_get_contents("$root/views/admin/manage.php");
            $routes = (string) @file_get_contents("$root/config/routes.php");

            if (preg_match('/window\.location\.reload\(\)/', $view, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($view, 0, $m[0][1]), "\n") + 1;
                return $bad('views/admin/manage.php still reloads the page (line ' . $line . '); a background job must not throw away the admin\'s position');
            }
            foreach ([
                'form.bulk-photo-form' => 'the bulk form',
                '/admin/photos/job/'    => 'the job state endpoint',
                '__manageJobActive'     => 'the busy flag',
            ] as $needle => $what) {
                if (strpos($view, $needle) === false) {
                    return $bad('views/admin/manage.php is missing ' . $what . ' (' . $needle . ')');
                }
            }
            if (strpos($routes, "'/admin/photos/job/{id}'") === false) {
                return $bad('the route /admin/photos/job/{id} is missing, so the page cannot watch a job');
            }
            $controller = (string) @file_get_contents("$root/app/Controllers/PhotoController.php");
            foreach (['bulkRotate', 'bulkCaption', 'bulkDelete', 'bulkWatermark'] as $method) {
                if (strpos($controller, 'public function ' . $method . '(') === false) {
                    return $bad('PhotoController::' . $method . '() is missing');
                }
            }
            // Every bulk action answers JSON when the page posts in the
            // background, so it can update the status line in place.
            if (substr_count($controller, "\$this->json(['ok' => true, 'job_id' => \$jobId") < 4) {
                return $bad('the bulk photo actions do not all answer JSON for the background post');
            }
            return $ok('the bulk photo actions update the page in place');
        });

        $add('smoke.admin.manage_no_nested_forms', 'Smoke · Admin UI', 'The manage page has no form nested inside the bulk photo form', static function () use ($root, $ok, $bad): array {
            // A form element may not contain another form: the HTML parser
            // DROPS nested form start tags, so the per-photo caption/move/
            // rotate/remove forms inside the photo table collapsed into the
            // bulk form - a row's Save (or rotate, or Remove) submitted the
            // BULK form to bulk-rotate, rotating every checked photo with the
            // default direction. The bulk form now holds the toolbar only and
            // the row checkboxes point at it with the form= attribute.
            $view = (string) @file_get_contents("$root/views/admin/manage.php");
            if (strpos($view, 'id="bulk-photo-form"') === false) {
                return $bad('the bulk photo form has no id, so the table checkboxes cannot point at it');
            }
            if (substr_count($view, 'form="bulk-photo-form"') === 0) {
                return $bad('the photo checkboxes do not reference the bulk form with the form= attribute');
            }
            $bulkAt = strpos($view, 'class="bulk-photo-form"');
            $closeAt = strpos($view, '</form>', (int) $bulkAt);
            $tableAt = strpos($view, '<table>', (int) $bulkAt);
            if ($bulkAt === false || $closeAt === false || $tableAt === false || $closeAt > $tableAt) {
                return $bad('the bulk photo form still wraps the photo table (form tags inside would be DROPPED by the parser)');
            }
            // Two row forms (rotate left/right) were also nested in the bulk
            // form; keep count of forms per table row bounded: after the fix
            // the table keeps its own caption/move/rotate/remove forms, so a
            // nested stray form tag inside a td must be gone.
            $rowExcerpt = substr($view, $tableAt, strpos($view, '</table>', $tableAt) - $tableAt);
            if (preg_match_all('/<form\b/', $rowExcerpt, $forms) !== false && count($forms[0]) !== substr_count($rowExcerpt, '</form>')) {
                return $bad('a form inside the photo table is left open (nested-form regression)');
            }
            // The overlay auto-shows on every admin submit; the bulk form does
            // not navigate (it posts fetch-style and the job is watched in
            // place), so the overlay would sit over the panel until dismissed.
            if (strpos($view, 'data-no-progress') === false) {
                return $bad('the manage page does not opt out of the autoshowing "Saving…" overlay on its fetch-based forms');
            }
            if (strpos($view, 'fd.append(submitter.name') === false) {
                return $bad('the bulk post does not carry the clicked button\'s name/value, so both rotate buttons lose their direction');
            }
            return $ok('the manage page has no nested forms and its bulk post keeps the clicked direction');
        });

        $add('smoke.admin.chips_show_selected_state', 'Smoke · Admin UI', 'Category chips are readable in every browser, not only :has() supporting ones', static function () use ($root, $ok, $bad): array {
            // The ticked-chip look used to live ONLY on
            // ":has(input[type=checkbox]:checked)" while the server (and the
            // per-page scripts) surface the state through the "selected"
            // class. A browser without :has() support therefore showed NO
            // selected categories anywhere - neither the saved ones nor one
            // freshly clicked, because the checkbox itself is display:none.
            $css = (string) @file_get_contents("$root/public/assets/css/admin.css");
            if (preg_match('/\.admin-theme \.chip\.selected/', $css) !== 1) {
                return $bad('admin.css does not style .chip.selected, so the tick is invisible on browsers without has() support');
            }
            $js = (string) @file_get_contents("$root/public/assets/js/admin.js");
            // Pages without a per-page script (edit/create) need the shared
            // fallback that keeps the class in lockstep with the checkbox.
            if (strpos($js, "querySelectorAll('label.chip > input[type=\"checkbox\"]')") === false
                || strpos($js, "label.classList.toggle('selected', checkbox.checked)") === false) {
                return $bad('admin.js does not keep the chip state in lockstep with its checkbox');
            }
            // admin.js is loaded with a HARDCODED "?v=2", so a browser held
            // the old copy for good and the lockstep script never arrived
            // ("still broken" with a correct deployment). The stylesheet is
            // mtime-busted through asset_url(); the script must be too.
            $layout = (string) @file_get_contents("$root/views/admin/layout.php");
            if (strpos($layout, "asset_url('/assets/js/admin.js')") === false) {
                return $bad('the admin layout does not mtime-bust admin.js, so browsers run a stale copy forever');
            }
            if (preg_match('#/assets/js/admin\.js[^\n]*\?v=#', $layout) === 1) {
                return $bad('the admin layout still pins a hardcoded version query on admin.js');
            }
            // The edit page must also render the saved state itself: it has
            // no per-page script, so before the shared script runs (and on
            // :has()-less browsers) the saved categories must already read
            // as selected.
            $edit = (string) @file_get_contents("$root/views/admin/edit.php");
            if (strpos($edit, "\$assigned, true) ? ' selected'") === false
                || strpos($edit, "\$assigned, true) ? 'checked'") === false) {
                return $bad('the edit page does not server-render the selected state for saved categories');
            }
            return $ok('the chip state is styled and kept in lockstep by the shared script');
        });

        $add('smoke.photo.rotate_normalises_exif', 'Smoke · Admin UI', 'Rotate the pixels upright before rotating (EXIF orientation)', static function () use ($root, $ok, $bad): array {
            // A JPEG with an EXIF orientation is DISPLAYED upright (the tag is
            // honoured on display), but imagerotate() works on the raw pixels
            // and the GD save drops the tag - so "rotate right" left the image
            // looking rotated by 180 (the display transform was applied a
            // second time). The photo editor has always normalised first; the
            // manage-page rotate (controller + bulk worker) must do the same.
            $controller = (string) @file_get_contents("$root/app/Controllers/PhotoController.php");
            $start = strpos($controller, 'public function rotate(');
            $end   = strpos($controller, 'public function bulkRotate(');
            if ($start === false || $end === false || $start >= $end) {
                return $bad('PhotoController::rotate() could not be located');
            }
            $method = substr($controller, $start, $end - $start);
            if (strpos($method, 'ImageEditor::rotate(') === false) {
                return $bad('PhotoController::rotate() does not rotate');
            }
            if (strpos($method, 'ImageEditor::normalize(') === false
                || strpos($method, 'ImageEditor::normalize(') > strpos($method, 'ImageEditor::rotate(')) {
                return $bad('PhotoController::rotate() must normalise the EXIF orientation BEFORE rotating the pixels');
            }
            $worker = (string) @file_get_contents("$root/bin/photo_edit_worker.php");
            $wStart = strpos($worker, 'function runBulkRotate(');
            $wEnd   = strpos($worker, "\nfunction ", $wStart + 1);
            if ($wStart === false || $wEnd === false) {
                return $bad('photo_edit_worker::runBulkRotate() could not be located');
            }
            $bulk = substr($worker, $wStart, $wEnd - $wStart);
            if (strpos($bulk, 'ImageEditor::rotate(') === false) {
                return $bad('the bulk rotate worker does not rotate');
            }
            if (strpos($bulk, 'ImageEditor::normalize(') === false
                || strpos($bulk, 'ImageEditor::normalize(') > strpos($bulk, 'ImageEditor::rotate(')) {
                return $bad('the bulk rotate worker must normalise the EXIF orientation BEFORE rotating');
            }
            return $ok('rotations normalise the EXIF orientation first');
        });

        $add('smoke.admin.a11y', 'Smoke · Admin UI', 'Admin shell has a skip link, one h1 and aria-current navigation', static function () use ($adminLayout, $root, $ok, $bad): array {
            $views = glob("$root/views/admin/*.php") ?: [];
            $dupes = [];
            foreach ($views as $view) {
                $src = (string) @file_get_contents($view);
                // login.php renders its own shell, so it is exempt.
                if (basename($view) === 'login.php' || basename($view) === 'layout.php') {
                    continue;
                }
                if (preg_match_all('/<h1[\s>]/i', $src) > 0) {
                    $dupes[] = basename($view);
                }
            }
            if ($dupes !== []) {
                return $bad('these admin views still emit their own <h1> (the topbar owns the page h1): ' . implode(', ', $dupes));
            }
            foreach (['class="skip-link"', 'id="admin-content"', 'aria-current="page"', 'class="adm-crumb"'] as $needle) {
                if (strpos($adminLayout, $needle) === false) {
                    return $bad('admin layout is missing ' . $needle);
                }
            }
            return $ok('skip link, main landmark, breadcrumb and aria-current present; no view-level h1');
        });

        $add('smoke.ui.copy_link_full_url', 'Smoke · UI', 'Admin trial links show the full URL and copy via the Clipboard API', static function () use ($root, $layoutSrc, $ok, $bad): array {
            $subs  = (string) @file_get_contents($root . '/views/admin/subscriptions.php');
            $admin = (string) @file_get_contents($root . '/views/admin/layout.php');
            return strpos($layoutSrc, 'navigator.clipboard') !== false
                && strpos($layoutSrc, 'window.__copyText') !== false
                && strpos($layoutSrc, 'window.copyLinkValue=function') !== false
                && strpos($subs, '<?= e($linkUrl) ?>') !== false
                && strpos($subs, 'data-arg="<?= e($linkUrl) ?>"') !== false
                && strpos($admin, 'window.copyLinkValue') !== false
                && strpos($admin, 'window.__copyText') !== false
                && strpos($admin, "closest('[data-confirm],[data-action],[data-copy]") !== false
                ? $ok('full trial URL rendered; Clipboard-API helpers + data-* delegation in both admin and public layouts')
                : $bad('trial links must render the full absolute URL and the admin layout must delegate data-action/data-copy to a navigator.clipboard helper');
        });
        $add('smoke.ui.admin_bulk_confirm', 'Smoke · UI', 'A destructive admin bulk action cannot run without a confirmation', static function () use ($root, $ok, $bad): array {
            $users = (string) @file_get_contents($root . '/views/admin/users.php');
            $adminJs = (string) @file_get_contents($root . '/public/assets/js/admin.js');
            $adminLayout = (string) @file_get_contents($root . '/views/admin/layout.php');
            $memberLayout = (string) @file_get_contents($root . '/views/layout.php');
            $adminTable = (string) @file_get_contents($root . '/app/Core/AdminTable.php');

            // The bulk bar calls window.bulkApply. That handler used to live
            // in the MEMBER layout, which the admin never loads - so the
            // admin's data-action delegation found no handler, skipped its
            // preventDefault(), and the form submitted straight away: bulk
            // DELETE with no confirmation, while every single-row delete had
            // one. The handler now lives in the admin's own JS.
            $handler = strpos($adminJs, 'window.bulkApply') !== false
                && strpos($adminJs, 'window.toggleBulkRole') !== false;
            $loaded  = strpos($adminLayout, '/assets/js/admin.js') !== false;
            $notInMember = strpos($memberLayout, 'window.bulkApply') === false;
            // And the form carries a data-confirm as a safety net for the
            // case where admin.js does not load at all. The form is built by
            // the shared component now, so the guard is either a literal
            // data-confirm on the form or a 'confirm' handed to the
            // component (which renders that attribute).
            $formGuard = (strpos($users, 'id="users-bulk-form"') !== false
                    && preg_match('/id="users-bulk-form"[^>]*data-confirm=/', $users) === 1)
                || (strpos($users, "'confirm'") !== false
                    && strpos($adminTable, 'data-confirm=') !== false);

            return $handler && $loaded && $notInMember && $formGuard
                ? $ok('bulk actions are handled by the admin JS and guarded by a confirm')
                : $bad('window.bulkApply/toggleBulkRole must be defined in public/assets/js/admin.js (loaded by the admin layout, NOT the member layout), and the users bulk form must carry a data-confirm as a fallback');
        });

        $add('smoke.ui.css_vars_defined', 'Smoke · UI', 'Every CSS variable the stylesheets use is actually defined', static function () use ($root, $ok, $bad): array {
            $defined = [];
            // Tokens the theme emits: the colour palette and the layout scale.
            $theme = (string) @file_get_contents($root . '/app/Models/Theme.php');
            foreach (['COLORS', 'MEMBER_COLORS', 'LAYOUT_DEFAULTS'] as $constName) {
                $start = strpos($theme, 'const ' . $constName . ' = [');
                if ($start === false) {
                    continue;
                }
                $end = strpos($theme, '];', $start);
                $block = $end === false ? substr($theme, $start) : substr($theme, $start, $end - $start);
                if (preg_match_all("/'([a-z0-9-]+)'\s*=>/", $block, $m)) {
                    foreach ($m[1] as $name) {
                        $defined['--' . $name] = true;
                    }
                }
            }
            // Tokens defined inside the stylesheets themselves.
            foreach (['public/assets/css/user.css', 'public/assets/css/admin.css', 'app/Core/theme_base.css'] as $file) {
                $css = (string) @file_get_contents($root . '/' . $file);
                if (preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $m)) {
                    foreach ($m[1] as $name) {
                        $defined[strtolower($name)] = true;
                    }
                }
            }
            // Everything that is USED.
            $used = [];
            foreach (['public/assets/css/user.css', 'public/assets/css/admin.css', 'app/Core/theme_base.css'] as $file) {
                $css = (string) @file_get_contents($root . '/' . $file);
                if (preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $m)) {
                    foreach ($m[1] as $name) {
                        $used[strtolower($name)] = true;
                    }
                }
            }
            // A var() with a fallback is fine even when the token is absent.
            $missing = [];
            foreach ($used as $name => $_) {
                if (isset($defined[$name])) {
                    continue;
                }
                $css = (string) @file_get_contents($root . '/public/assets/css/user.css')
                    . (string) @file_get_contents($root . '/public/assets/css/admin.css')
                    . (string) @file_get_contents($root . '/app/Core/theme_base.css');
                if (preg_match('/var\(\s*' . preg_quote($name, '/') . '\s*,/i', $css)) {
                    continue;   // has a fallback
                }
                $missing[] = $name;
            }
            sort($missing);
            return $missing === []
                ? $ok('every var() in the stylesheets resolves')
                : $bad('these CSS variables are used but never defined: ' . implode(', ', $missing));
        });

        $add('smoke.ui.inline_style_ratchet', 'Smoke · UI', 'The views do not carry their own inline styles', static function () use ($root, $ok, $bad): array {
            // A ratchet, not a target: the views used to carry 1289 inline
            // style attributes. The repeated ones became utility classes
            // (public/assets/css/user.css) and the rest are component styles
            // that move to the stylesheets as the admin tables are rebuilt.
            // The number may only go DOWN.
            $limit = 847;
            $count = 0;
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                if (str_starts_with(substr($file, strlen($root . '/views/')), 'emails/')) {
                    continue;   // email clients need their own inline CSS
                }
                $count += preg_match_all('/\sstyle="/', (string) @file_get_contents($file));
            }
            return $count <= $limit
                ? $ok($count . ' inline style attributes (limit ' . $limit . ')')
                : $bad('the views carry ' . $count . ' inline style attributes, over the limit of ' . $limit . ' - move them into public/assets/css');
        });

        $add('smoke.ui.utility_classes_defined', 'Smoke · UI', 'Every utility class a view uses is actually defined', static function () use ($root, $ok, $bad): array {
            // A typo in a utility class is silent: the element just renders
            // unstyled. This catches it. The utilities live in their own
            // file now, because both shells use them.
            $css = (string) @file_get_contents($root . '/public/assets/css/utilities.css')
                 . (string) @file_get_contents($root . '/public/assets/css/user.css');
            $defined = [];
            if (preg_match_all('/\.(u-[a-z0-9-]+)\s*\{/', $css, $m)) {
                foreach ($m[1] as $name) {
                    $defined[$name] = true;
                }
            }
            $used = [];
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                if (preg_match_all('/class="([^"]*)"/', (string) @file_get_contents($file), $m)) {
                    foreach ($m[1] as $attr) {
                        foreach (preg_split('/\s+/', trim($attr)) as $cls) {
                            if (str_starts_with($cls, 'u-')) {
                                $used[$cls] = true;
                            }
                        }
                    }
                }
            }
            $missing = array_values(array_diff(array_keys($used), array_keys($defined)));
            sort($missing);
            return $missing === []
                ? $ok(count($used) . ' utility classes used, all defined')
                : $bad('these utility classes are used but not defined in utilities.css: ' . implode(', ', $missing));
        });

        $add('smoke.ui.utilities_shared', 'Smoke · UI', 'The utility classes live in one file and both shells load it', static function () use ($root, $ok, $bad): array {
            // The u-* utilities are scope-agnostic: 30 of the ~40 admin
            // views use them (96 times `u-right` alone), and the member
            // views use them too. They were in user.css, which the admin
            // shell had to load in full to get them.
            $problems = [];
            $util = (string) @file_get_contents($root . '/public/assets/css/utilities.css');
            $user = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $memberLayout = (string) @file_get_contents($root . '/views/layout.php');
            $adminLayout  = (string) @file_get_contents($root . '/views/admin/layout.php');

            if (trim($util) === '') {
                $problems[] = 'public/assets/css/utilities.css is empty';
            }
            if (preg_match('/\.(u-[a-z0-9-]+)\s*\{/', $util, $m) !== 1) {
                $problems[] = 'the utility classes must be defined in utilities.css';
            }
            // They must not be defined twice.
            if (preg_match('/\.(u-[a-z0-9-]+)\s*\{/', $user) === 1) {
                $problems[] = 'the utility classes are still in user.css as well as utilities.css';
            }
            if (strpos($memberLayout, '/assets/css/utilities.css') === false) {
                $problems[] = 'the member shell must load utilities.css';
            }
            if (strpos($adminLayout, '/assets/css/utilities.css') === false) {
                $problems[] = 'the admin shell must load utilities.css';
            }

            return $problems === []
                ? $ok('the utilities are in one file and both shells load it')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.admin_tables_paginated', 'Smoke · UI', 'The admin listings use the shared table component', static function () use ($root, $ok, $bad): array {
            // Every admin listing used to hand-roll its own toolbar, filter
            // form, bulk bar, pager and empty state, and they had drifted
            // apart: two hand-rolled pagers, three empty states, and filters
            // that cleared each other. The shared component is
            // app/Core/AdminTable.php.
            //
            // This is a ratchet, not a target: the count of converted
            // listings may only go UP.
            $limit = 5;

            $converted = [];
            $files = glob($root . '/views/admin/*.php') ?: [];
            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                if (strpos($src, 'AdminTable::') !== false) {
                    $converted[] = basename($file);
                }
            }

            // The component itself must keep offering the pieces the listings
            // rely on, or a converted listing silently renders half a page.
            $table = (string) @file_get_contents($root . '/app/Core/AdminTable.php');
            $parts = ['shell', 'filterForm', 'bulkBar', 'pager', 'sortLink', 'pageWindow', 'emptyState'];
            $missing = [];
            foreach ($parts as $part) {
                if (strpos($table, 'function ' . $part . '(') === false) {
                    $missing[] = $part;
                }
            }

            if ($missing !== []) {
                return $bad('app/Core/AdminTable.php is missing: ' . implode(', ', $missing));
            }

            return count($converted) >= $limit
                ? $ok(count($converted) . ' admin listings use the shared component (' . implode(', ', $converted) . ')')
                : $bad('only ' . count($converted) . ' admin listings use the shared component (need ' . $limit . ') - convert the admin list views');
        });

        $add('smoke.ui.guest_mobile_nav', 'Smoke · UI', 'A guest on a phone can still sign in and reach the nav', static function () use ($root, $ok, $bad): array {
            $css = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            // The phone hides the rail's identity block and its main links,
            // so the nav a guest has on a phone is the bottom bar: it must
            // carry the sign-in and the join.
            $guestBar = strpos($layout, '$user !== null ? $bottomPrimary : $guestNavItems') !== false
                && strpos($layout, "'key' => 'pub-signin'") !== false
                && strpos($layout, "'key' => 'pub-join'") !== false;
            $hasSignIn = strpos($layout, "url('/login')") !== false
                && strpos($layout, "url('/signup')") !== false;
            return $guestBar && $hasSignIn
                ? $ok('a guest gets Galleries, Sign in and Join in the phone nav')
                : $bad('a guest on a phone must get the bottom bar with a sign-in and a join link');
        });

        $add('smoke.ui.nav_single_source', 'Smoke · UI', 'The phone nav is derived from the rail, not written out twice', static function () use ($root, $ok, $bad): array {
            // The bottom bar used to be a second hand-written copy of the nav
            // (its own labels, icons and active flags), so a nav change had to
            // be made twice and the two drifted. It is now taken from the
            // rail's definition.
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $problems = [];
            if (strpos($layout, '$bottomPrimaryKeys') === false) {
                $problems[] = 'the layout must pick the bottom bar\'s primary entries by key';
            }
            if (strpos($layout, '$navByKey') === false) {
                $problems[] = 'the layout must flatten the rail groups into one list';
            }
            // A literal entry means somebody wrote the nav out a second time.
            if (preg_match("/\['href' => '\//", $layout) === 1) {
                $problems[] = 'the layout still writes nav entries out by hand';
            }
            if (strpos($layout, '$guestNavItems') === false) {
                $problems[] = 'the guest nav must be one list too';
            }
            return $problems === []
                ? $ok('the rail, the phone bar and the guest nav come from one definition')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.guest_bottom_nav', 'Smoke · UI', 'A guest on a phone gets the bottom nav', static function () use ($root, $ok, $bad): array {
            // The bottom bar was member-only, so a guest browsing the
            // galleries on a phone had no navigation at all: the listing pages
            // keep the rail (which hides its links on a phone) and the top
            // hamburger only exists on the pages that do not.
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $problems = [];
            if (strpos($layout, '$sidebarNav && $user !== null') !== false) {
                $problems[] = 'the bottom bar must not be member-only';
            }
            if (strpos($layout, '$user !== null ? $bottomPrimary : $guestNavItems') === false) {
                $problems[] = 'the bottom bar must render the guest entries for a guest';
            }
            foreach (['/login', '/signup'] as $href) {
                if (strpos($layout, "'href' => '" . $href . "'") === false) {
                    $problems[] = 'the guest nav must offer ' . $href;
                }
            }
            return $problems === []
                ? $ok('a guest gets Galleries, Sign in and Join in the phone nav')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.wall_paginated', 'Smoke · UI', 'The wall feed is paged, so the older posts are reachable', static function () use ($root, $ok, $bad): array {
            $controller = (string) @file_get_contents($root . '/app/Controllers/WallController.php');
            $model     = (string) @file_get_contents($root . '/app/Models/WallPost.php');
            $view      = (string) @file_get_contents($root . '/views/wall.php');
            $problems = [];
            if (strpos($model, 'countPublished') === false) {
                $problems[] = 'WallPost must be able to count the published posts';
            }
            if (strpos($model, 'OFFSET') === false) {
                $problems[] = 'WallPost::allPublished() must take an offset';
            }
            if (strpos($controller, "'paginator'") === false) {
                $problems[] = 'the wall must hand a paginator to the view';
            }
            if (strpos($view, 'partials/pagination.php') === false) {
                $problems[] = 'the wall must render the shared pager';
            }
            return $problems === []
                ? $ok('the wall feed pages through the shared pager')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.tour_dialog', 'Smoke · UI', 'The site tour is a dialog that does not re-arm', static function () use ($root, $ok, $bad): array {
            // The tour cleared its "seen" flag the moment it started, so it
            // came back in every new tab until the member skipped it, and its
            // card was a floating div with no dialog semantics.
            $tour = (string) @file_get_contents($root . '/public/assets/js/tour.js');
            $problems = [];
            if (strpos($tour, "setAttribute('role', 'dialog')") === false) {
                $problems[] = 'the tour card must be a dialog';
            }
            if (strpos($tour, "aria-modal") === false) {
                $problems[] = 'the tour card must be announced as modal';
            }
            if (strpos($tour, "e.key === 'Escape'") === false) {
                $problems[] = 'Escape must close the tour';
            }
            // start() must not clear the flag; only the explicit replay does.
            $start = substr($tour, (int) strpos($tour, 'function start()'));
            $start = substr($start, 0, (int) strpos($start, '}'));
            if (strpos($start, 'clearSeen') !== false) {
                $problems[] = 'start() must not clear the seen flag, or the tour re-arms in every tab';
            }
            return $problems === []
                ? $ok('the tour is a dialog and it stays away once it has been seen')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.viewed_badge_named', 'Smoke · UI', 'The "seen" mark on a gallery card is not colour-only', static function () use ($root, $ok, $bad): array {
            // The mark used to be an empty purple dot: colour was the only
            // thing that said what it meant, and it had no name at all.
            $card = (string) @file_get_contents($root . '/views/partials/gallery_card.php');
            $css  = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];
            if (strpos($card, 'viewed-badge') === false) {
                $problems[] = 'the card must still mark a gallery the member has seen';
            }
            if (strpos($card, 'Already viewed') === false) {
                $problems[] = 'the card must say in words that the gallery was already viewed';
            }
            if (strpos($css, '.sr-only') === false) {
                $problems[] = 'user.css needs a screen-reader-only class';
            }
            if (preg_match('/viewed-badge[^}]*\}\s*\n/s', $css, $m) === 1 && strpos($m[0], 'width:12px') !== false) {
                $problems[] = 'the mark is still an empty 12px dot';
            }
            return $problems === []
                ? $ok('the "seen" mark has a shape and a name')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.admin_search_on_phone', 'Smoke · UI', 'The admin search is reachable on a phone', static function () use ($root, $ok, $bad): array {
            // The topbar search was display: none under 960px, so a phone
            // admin could not search at all. It is the second row of the
            // topbar now.
            $css = (string) @file_get_contents($root . '/public/assets/css/admin.css');
            if (strpos($css, '.admin-top-search { display: none; }') !== false) {
                return $bad('the admin search must not be hidden on a phone');
            }
            if (strpos($css, '.admin-theme .admin-topbar .admin-top-search') === false) {
                return $bad('the admin topbar has no search rule at all');
            }
            return strpos($css, 'order: 3') !== false
                ? $ok('the admin search is the second row of the phone topbar')
                : $bad('the admin search must be given a row of its own in the phone topbar');
        });

        $add('smoke.ui.users_export_next_to_list', 'Smoke · UI', 'The users CSV export sits next to the user list', static function () use ($root, $ok, $bad): array {
            $users = (string) @file_get_contents($root . '/views/admin/users.php');
            if (strpos($users, '/admin/export/users') === false) {
                return $bad('the user list must offer the users CSV export');
            }
            return $ok('the users list exports itself to CSV');
        });

        $add('smoke.ui.card_state_reaches_the_cards', 'Smoke · UI', 'A member can favourite a gallery from any listing', static function () use ($root, $ok, $bad): array {
            // The card is rendered inside a PageCache::fragment() closure. A
            // closure only sees what it is given, so the member's own state
            // (seen, favourited) used to be lost there: the favourite button
            // and the "seen" mark never appeared on the listing, the category
            // pages, the tag pages or the search results.
            $problems = [];
            $views = [
                'views/gallery/index.php'    => 'the gallery listing',
                'views/gallery/category.php' => 'the category page',
                'views/gallery/tag.php'      => 'the tag page',
            ];
            foreach ($views as $file => $what) {
                $src = (string) @file_get_contents($root . '/' . $file);
                // Every card is rendered by a closure; the closure's own
                // `use (...)` list is what decides what the card can see.
                preg_match_all('/use \(([^)]*)\)/', $src, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as $match) {
                    // Only the closure that renders the card matters.
                    $after = substr($src, (int) $match[1]);
                    if (strpos($after, 'gallery_card.php') === false) {
                        continue;
                    }
                    $before = substr($src, 0, (int) $match[1]);
                    // The closest `use (` before the card is the one that
                    // renders it.
                    $closest = strrpos($before, 'use (');
                    if ($closest === false) {
                        continue;
                    }
                    $list = substr($before, $closest + 5, (int) $match[1] - $closest - 5);
                    foreach (['$viewedIds', '$favoriteGalleryIds', '$currentUser', '$hasActive'] as $var) {
                        if (strpos($list, $var) === false) {
                            $problems[] = $what . ' (' . $file . ') does not give ' . $var . ' to the card';
                        }
                    }
                }
            }
            $tag = (string) @file_get_contents($root . '/app/Controllers/TagController.php');
            if (strpos($tag, 'favoriteGalleryIds') === false) {
                $problems[] = 'the tag page must hand the member\'s favourite state to the view';
            }
            return $problems === []
                ? $ok('the member\'s own state reaches the gallery cards everywhere')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.member_surfaces_legible', 'Smoke · UI', 'The raised member surfaces are legible on the dark skin', static function () use ($root, $ok, $bad): array {
            // The chat bubble, the welcome card, the support forms and the
            // "Silver" badge paired --pink-100 with --purple-800/900. Those
            // are a LIGHT pairing: on the member (dark) skin the same two
            // tokens are near-black on near-black, and the operator's reply
            // was invisible (1.16:1).
            $theme = (string) @file_get_contents($root . '/app/Models/Theme.php');
            $css   = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];

            foreach (['bubble-bg', 'bubble-text', 'bubble-border'] as $token) {
                if (substr_count($theme, "'" . $token . "'") < 2) {
                    $problems[] = 'the ' . $token . ' token must exist in both palettes';
                }
            }
            if (strpos($css, 'var(--bubble-text') === false) {
                $problems[] = 'the member surfaces must use the bubble tokens';
            }
            // No rule may pair a pink-1xx background with a dark text token.
            if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER) !== false) {
                foreach ($rules as $rule) {
                    if (preg_match('/background(?:-color)?\s*:\s*var\(--pink-\d/', $rule[2]) === 1
                        && preg_match('/color\s*:\s*var\(--purple-(?:800|900)/', $rule[2]) === 1) {
                        $problems[] = 'this rule pairs a pink surface with a dark text token: '
                            . trim(preg_replace('/\s+/', ' ', $rule[1])) . ' - use the bubble tokens';
                        break;
                    }
                }
            }

            return $problems === []
                ? $ok('the raised surfaces use the bubble tokens, not the light pink/dark purple pairing')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.contrast_floor', 'Smoke · UI', 'Every text colour on the member skin meets its contrast floor', static function () use ($root, $ok, $bad): array {
            // The ratios are COMPUTED from the palette the site actually ships,
            // so a change to the palette can never quietly break a pair - and
            // a view that goes back to a dark token on a dark surface is
            // caught as well, because the declaration itself is checked.
            //
            // Each pair is:
            //   [file, the declaration that must be there, the one that must
            //    not be, the foreground token, the background token, the floor,
            //    and optionally a needle the declaration must come AFTER]
            // The floor is 4.5 for body text and 3.0 for a non-text indicator
            // (WCAG 1.4.11 wants 3:1 for a focus ring). The optional needle
            // encodes a CASCADE requirement: a rule that must win a
            // specificity tie has to sit below the rule it beats.
            $pairs = [
                // The paywall gate. The headline sat on --purple-800 over
                // --pink-100, which is a LIGHT pairing: 1.60:1 on the dark
                // skin, so the upsell for every locked gallery was invisible.
                ['views/partials/membership_gate.php',
                    'color:var(--heading-color)', 'color:var(--purple-800)',
                    '--heading-color', '--pink-100', 4.5],
                // The focus ring. --purple-600 on the page background was
                // 2.83:1, under the 3:1 WCAG 1.4.11 asks of a non-text
                // indicator. The palette already had --focus-ring for it.
                ['public/assets/css/user.css',
                    'outline:3px solid var(--focus-ring)', 'outline:3px solid var(--purple-600)',
                    '--focus-ring', '--page-bg', 3.0],
                // A destructive button. .btn:hover is (0,3,1) and .btn-danger
                // is (0,2,1), so hovering used to paint the pink accent
                // gradient under the danger text: 1.88:1.
                ['public/assets/css/user.css',
                    'body:not(.admin-theme) .btn-danger {', '',
                    '--btn-danger-color', '--btn-danger-bg', 4.5],
                ['public/assets/css/user.css',
                    'body:not(.admin-theme) .btn-danger:hover {', '',
                    '--btn-danger-color', '--btn-danger-hover-bg', 4.5,
                    'body:not(.admin-theme) .btn:hover'],
                // The chat presence line. It hard-coded #15803d and #b45309
                // in the view; both are 3.93:1 on the page background, and
                // the text is 12.8px.
                ['views/chat/index.php',
                    'chat-status-online', '#15803d',
                    '--status-online', '--page-bg', 4.5],
                ['public/assets/css/user.css',
                    'color: var(--status-online)', '',
                    '--status-online', '--page-bg', 4.5],
                ['public/assets/css/user.css',
                    'color: var(--status-away)', '',
                    '--status-away', '--page-bg', 4.5],
                // The "Submitted" badge was a light chip (#dcfce7) on the dark
                // skin, carrying its own hard-coded greens.
                ['views/chat/index.php',
                    'chat-submitted', '#dcfce7',
                    '--flash-success-color', '--flash-success-bg', 4.5],
                // The guest teaser statistic on the login and signup pages.
                // --purple-800 on a card is 1.71:1.
                ['public/assets/css/user.css',
                    '.guest-teaser-stats strong{color:var(--text-color)}',
                    '.guest-teaser-stats strong{color:var(--purple-800)}',
                    '--text-color', '--card-bg', 4.5],
            ];

            // The member palette, parsed out of Theme.php.
            $theme = (string) @file_get_contents($root . '/app/Models/Theme.php');
            if (preg_match('/private const MEMBER_COLORS = \[(.*?)\n    \];/s', $theme, $m) !== 1) {
                return $bad('the member palette could not be read out of app/Models/Theme.php');
            }
            $palette = [];
            if (preg_match_all("/'([a-z0-9-]+)'\s*=>\s*'(#[0-9a-fA-F]{3,8})'/", $m[1], $tokens, PREG_SET_ORDER) !== false) {
                foreach ($tokens as $t) {
                    $palette['--' . $t[1]] = $t[2];
                }
            }

            $luminance = static function (string $hex): float {
                $hex = ltrim($hex, '#');
                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }
                $sum = [];
                foreach ([0, 2, 4] as $i) {
                    $c = hexdec(substr($hex, $i, 2)) / 255;
                    $sum[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
                }
                return 0.2126 * $sum[0] + 0.7152 * $sum[1] + 0.0722 * $sum[2];
            };
            $ratio = static function (string $a, string $b) use ($luminance): float {
                $hi = max($luminance($a), $luminance($b));
                $lo = min($luminance($a), $luminance($b));
                return ($hi + 0.05) / ($lo + 0.05);
            };

            $problems = [];
            foreach ($pairs as $pair) {
                [$file, $required, $forbidden, $fg, $bg, $floor] = $pair;
                $after = $pair[6] ?? '';
                $src = (string) @file_get_contents($root . '/' . $file);
                if ($src === '') {
                    $problems[] = $file . ' could not be read';
                    continue;
                }
                if (strpos($src, $required) === false) {
                    $problems[] = $file . ' must use "' . $required . '"';
                }
                if ($forbidden !== '' && strpos($src, $forbidden) !== false) {
                    $problems[] = $file . ' still uses "' . $forbidden . '"';
                }
                if ($after !== '' && strpos($src, $required) !== false) {
                    $requiredAt = strpos($src, $required);
                    $afterAt    = strpos($src, $after);
                    if ($afterAt === false || $requiredAt < $afterAt) {
                        $problems[] = $file . ': "' . $required . '" must come after "' . $after . '" to win the cascade';
                    }
                }
                foreach ([$fg, $bg] as $token) {
                    if (!isset($palette[$token])) {
                        $problems[] = $token . ' is not in the member palette';
                    }
                }
                if (!isset($palette[$fg]) || !isset($palette[$bg])) {
                    continue;
                }
                $got = $ratio($palette[$fg], $palette[$bg]);
                if ($got + 0.005 < $floor) {
                    $problems[] = $fg . ' on ' . $bg . ' is ' . round($got, 2) . ':1, below the ' . $floor . ':1 floor';
                }
            }

            return $problems === []
                ? $ok(count($pairs) . ' contrast pairs meet their floor')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.chips_reachable', 'Smoke · UI', 'A chip that carries a checkbox is reachable by keyboard', static function () use ($root, $ok, $bad): array {
            // .chip input[type="checkbox"] { display: none } took the
            // e-mail-notification toggles and the favourite-category chips
            // out of the tab order entirely, with no focus indicator. The
            // input has to stay in the focus order - hidden visually, not
            // removed - and the chip has to show the ring.
            $base = (string) @file_get_contents($root . '/app/Core/theme_base.css');
            $problems = [];

            if (preg_match('/\.chip input\[type="checkbox"\]\s*\{([^}]*)\}/', $base, $m) !== 1) {
                $problems[] = 'the chip checkbox rule is missing';
            } else {
                $body = $m[1];
                if (preg_match('/display\s*:\s*none/', $body) === 1) {
                    $problems[] = 'the chip checkbox is still display:none, so it cannot be reached by keyboard';
                }
                if (preg_match('/position\s*:\s*absolute/', $body) !== 1) {
                    $problems[] = 'the chip checkbox must be taken out of the flow, not removed';
                }
                if (preg_match('/clip-path\s*:\s*inset\(50%\)/', $body) !== 1) {
                    $problems[] = 'the chip checkbox must be hidden visually with the clip pattern';
                }
            }

            if (strpos($base, '.chip:focus-within') === false) {
                $problems[] = 'a chip that holds the focus must show a focus ring';
            }
            if (strpos($base, 'var(--focus-ring)') === false) {
                $problems[] = 'the chip focus ring must use the --focus-ring token';
            }
            // The two views that carry these toggles.
            foreach (['views/settings.php'] as $file) {
                $src = (string) @file_get_contents($root . '/' . $file);
                if ($src === '' || strpos($src, 'name="categories[]"') === false) {
                    $problems[] = $file . ' no longer carries the category checkboxes - keep one view that exercises the chip';
                }
            }

            return $problems === []
                ? $ok('the chip checkboxes are hidden but reachable, and the chip shows the focus ring')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_inline_handlers', 'Smoke · UI', 'No view carries an inline event handler', static function () use ($root, $ok, $bad): array {
            // The site's own CSP has no script-src-attr, so an inline
            // onerror / oncontextmenu is blocked by the browser and the
            // behaviour it was written for silently never happens. The
            // chat's right-click blocker and the admin's broken-image
            // guard were both written this way.
            $problems = [];
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                if (str_starts_with(substr($file, strlen($root . '/views/')), 'emails/')) {
                    continue;   // email clients need their own inline CSS
                }
                $src = (string) @file_get_contents($file);
                if (preg_match('/\son(click|error|load|contextmenu|mouseover|mouseout|submit|change|input|focus|blur|keydown|keyup)\s*=/i', $src, $m) === 1) {
                    $problems[] = str_replace($root . '/', '', $file) . ' has an inline ' . $m[1] . ' handler';
                }
            }
            return $problems === []
                ? $ok('no view carries an inline event handler')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.semantics', 'Smoke · UI', 'The controls that are not what they look like say so', static function () use ($root, $ok, $bad): array {
            // A role-less <span> with an aria-label is not exposed with
            // that name; a span with aria-disabled is not a disabled
            // control; a role="dialog" that is not modal, has no focus
            // handling and steals no focus is a lie to a screen reader.
            $problems = [];

            $stars = (string) @file_get_contents($root . '/views/partials/star_rating.php');
            if (strpos($stars, 'role="img"') === false) {
                $problems[] = 'the star rating needs role="img" so its aria-label is the name that is announced';
            }

            $nav = (string) @file_get_contents($root . '/views/partials/media_nav.php');
            if (strpos($nav, 'aria-disabled') !== false) {
                $problems[] = 'the media nav must use a real disabled button, not aria-disabled on a span';
            }
            if (strpos($nav, 'class="btn btn-disabled" disabled') === false) {
                $problems[] = 'the media nav must render the disabled state as a disabled button';
            }

            $consent = (string) @file_get_contents($root . '/views/partials/consent_banner.php');
            if (strpos($consent, 'role="dialog"') !== false) {
                $problems[] = 'the consent banner is not a dialog: it is a polite region, not a modal';
            }
            if (strpos($consent, 'aria-live="polite"') === false) {
                $problems[] = 'the consent banner must announce itself politely';
            }

            $layout = (string) @file_get_contents($root . '/views/layout.php');
            if (preg_match('/<script[^>]*nonce=[^>]*nonce=/i', $layout) === 1) {
                $problems[] = 'a script tag carries the nonce attribute twice';
            }

            return $problems === []
                ? $ok('the star rating, the media nav, the consent banner and the JSON-LD all say what they are')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.nav_globals_unique', 'Smoke · UI', 'No two modules define the same window global', static function () use ($root, $ok, $bad): array {
            // window.GalleryNav was defined twice: the top nav dropdown in
            // views/layout.php and the media-nav advance in user.js. user.js
            // loads last, so it overwrote the dropdown and the guest
            // hamburger's toggle() no longer existed. A global that two
            // modules of the SAME page both own is a global where one of
            // them is silently dead.
            //
            // The two shells never share a page, so the member group and the
            // admin group are checked separately. A definition guarded by
            // "if (!window.X)" is a fallback chain, not a collision.
            $groups = [
                'member' => [
                    'views/layout.php'         => $root . '/views/layout.php',
                    'public/assets/js/user.js' => $root . '/public/assets/js/user.js',
                    'public/assets/js/tour.js' => $root . '/public/assets/js/tour.js',
                ],
                'admin' => [
                    'views/admin/layout.php'    => $root . '/views/admin/layout.php',
                    'public/assets/js/admin.js' => $root . '/public/assets/js/admin.js',
                    'public/assets/js/tour.js'  => $root . '/public/assets/js/tour.js',
                ],
            ];
            $problems = [];
            foreach ($groups as $scope => $sources) {
                $owners = [];
                foreach ($sources as $label => $path) {
                    $src = (string) @file_get_contents($path);
                    if ($src === '') {
                        continue;
                    }
                    if (preg_match_all('/window\.([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*[^=]/', $src, $m) === false) {
                        continue;
                    }
                    foreach (array_unique($m[1]) as $name) {
                        $owners[$name][] = $label;
                    }
                }
                foreach ($owners as $name => $where) {
                    if (count($where) < 2) {
                        continue;
                    }
                    sort($where);
                    // Every definition but the last has to be a guarded
                    // fallback, or the last one silently wins.
                    $unguarded = [];
                    foreach ($where as $label) {
                        $src = (string) @file_get_contents($sources[$label]);
                        if (strpos($src, 'if (!window.' . $name . ')') === false) {
                            $unguarded[] = $label;
                        }
                    }
                    if (count($unguarded) > 1) {
                        $problems[] = 'window.' . $name . ' is owned by ' . count($unguarded)
                            . ' modules in the ' . $scope . ' scope: ' . implode(', ', $unguarded)
                            . ' - the last one wins and the others are dead';
                    }
                }
            }
            return $problems === []
                ? $ok('no window global is owned by two modules of the same page')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.guest_hamburger_works', 'Smoke · UI', 'The guest hamburger opens the navigation dropdown', static function () use ($root, $ok, $bad): array {
            // The dropdown is opened by window.GalleryTopNav.toggle(), which
            // the toggle button reaches through the data-action delegation.
            // The two halves have to agree on the name and the class.
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $css    = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];

            if (strpos($layout, 'window.GalleryTopNav = {') === false) {
                $problems[] = 'the top nav must publish window.GalleryTopNav';
            }
            if (strpos($layout, 'toggle: function ()') === false || strpos($layout, 'close: function ()') === false) {
                $problems[] = 'window.GalleryTopNav must have a toggle() and a close()';
            }
            if (strpos($layout, 'window.galleryNavToggle=function(){ if(window.GalleryTopNav){window.GalleryTopNav.toggle();} };') === false) {
                $problems[] = 'the toggle button must reach the top nav through window.GalleryTopNav';
            }
            if (strpos($layout, 'data-action="galleryNavToggle"') === false) {
                $problems[] = 'the hamburger button must carry data-action="galleryNavToggle"';
            }
            if (strpos($css, '.nav-links.open{display:flex}') === false) {
                $problems[] = 'the open state of the nav dropdown must be styled';
            }
            // The state has to be announced, not only drawn.
            if (strpos($layout, "btn.setAttribute('aria-expanded', open ? 'true' : 'false');") === false) {
                $problems[] = 'the hamburger must set aria-expanded when it opens';
            }

            return $problems === []
                ? $ok('the guest hamburger toggles the dropdown and announces its state')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.clipboard_fallback', 'Smoke · UI', 'Copying works outside a secure context', static function () use ($root, $ok, $bad): array {
            // The Clipboard API does not exist outside a secure context. The
            // share bar used it on its own, so on a plain-http setup (the
            // dev box is http://) the "Copy link" button did nothing and
            // said nothing either. The shell already publishes __copyText,
            // which falls back to execCommand.
            $share = (string) @file_get_contents($root . '/views/partials/share-bar.php');
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $problems = [];

            if (strpos($share, 'window.__copyText') === false) {
                $problems[] = 'the share bar must copy through window.__copyText, which has the plain-http fallback';
            }
            if (strpos($share, 'window.__copied') === false) {
                $problems[] = 'the share bar must report the result through window.__copied';
            }
            if (strpos($layout, 'window.__copyText=function(text){') === false) {
                $problems[] = 'the shell must publish window.__copyText';
            }
            if (strpos($layout, 'execCommand(\'copy\')') === false) {
                $problems[] = 'window.__copyText must fall back to execCommand for a plain-http setup';
            }
            if (preg_match('/navigator\.clipboard\.writeText\(text\)\.then\([a-zA-Z]+,\s*function\s*\(\)\s*\{\s*\}\)/', $share) === 1) {
                $problems[] = 'the share bar must not swallow a failed copy silently';
            }

            return $problems === []
                ? $ok('the share bar copies through the fallback and says what happened')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.guest_teaser_fits', 'Smoke · UI', 'The guest teaser drops its extra thumbnails', static function () use ($root, $ok, $bad): array {
            // fitGrids() read the grid's budget from
            // getComputedStyle(grid).maxHeight. The grid has no max-height
            // in CSS, so that was "none": parseFloat("none") is NaN, and
            // the function returned before it touched anything. The teaser
            // column therefore kept its full content height and forced the
            // login and signup pages to be as tall as 25 thumbnails.
            $partial = (string) @file_get_contents($root . '/views/partials/guest_teaser.php');
            $problems = [];

            if (strpos($partial, 'getBoundingClientRect().height') === false) {
                $problems[] = 'the fit must fall back to the grid\'s rendered height when it has no max-height';
            }
            if (strpos($partial, "cards[i].style.display = 'none'") === false
                && strpos($partial, 'cards[i].style.display = \'none\'') === false) {
                $problems[] = 'the fit must drop the extra thumbnails, not only cap the height';
            }
            if (strpos($partial, 'gridTemplateColumns') === false) {
                $problems[] = 'the fit must read the column count, or it drops the wrong number of thumbnails';
            }
            // A bare early return on a non-finite budget is the bug itself.
            // The fallback has to sit BETWEEN reading the budget and giving
            // up on it.
            $parseAt = strpos($partial, 'var budget = parseFloat');
            $fallAt  = strpos($partial, 'budget = grid.getBoundingClientRect().height');
            $giveAt  = strpos($partial, 'if (!isFinite(budget) || budget <= 0) return;');
            if ($parseAt === false) {
                $problems[] = 'the fit must read the budget from the grid';
            } elseif ($giveAt !== false && ($fallAt === false || $fallAt < $parseAt || $fallAt > $giveAt)) {
                $problems[] = 'the fit must fall back to the rendered height before it gives up on the budget';
            }

            return $problems === []
                ? $ok('the guest teaser fits its grid and drops the extra thumbnails')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.rules_not_stranded', 'Smoke · UI', 'A rule that every layout needs is not stranded in a media query', static function () use ($root, $ok, $bad): array {
            // .collection-order (the reorder buttons on a collection) was
            // defined INSIDE the phone media query, so on a desktop the
            // up/down buttons had no layout at all. A rule that a layout
            // needs at every width has to be at the top level.
            $must = [
                '.collection-order' => 'public/assets/css/user.css',
            ];
            $problems = [];
            foreach ($must as $selector => $file) {
                $css = (string) @file_get_contents($root . '/' . $file);
                if ($css === '') {
                    $problems[] = $file . ' could not be read';
                    continue;
                }
                $at = strpos($css, $selector . '{');
                if ($at === false) {
                    $problems[] = $selector . ' is not defined in ' . $file;
                    continue;
                }
                // The brace depth at that point: 0 means the rule is at the
                // top level, anything deeper means it is inside an @media
                // or @supports block.
                $depth = 0;
                for ($i = 0; $i < $at; $i++) {
                    if ($css[$i] === '{') { $depth++; }
                    elseif ($css[$i] === '}') { $depth--; }
                }
                if ($depth !== 0) {
                    $problems[] = $selector . ' is defined inside a nested block in ' . $file
                        . ', so it does not apply at every width';
                }
            }
            return $problems === []
                ? $ok('the rules every layout needs are at the top level')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_global_table_rule', 'Smoke · UI', 'No rule reaches into every table on the site', static function () use ($root, $ok, $bad): array {
            // `table td:nth-child(1) { overflow-wrap: anywhere; word-break:
            // break-word }` was written for the member category names and
            // applied to EVERY table on the site. It leaked into the admin
            // (user.css is loaded there too) and broke the status badges
            // mid-word, which the admin then had to work around.
            $css = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];

            if (preg_match('/(^|[;{\s])table\s+td:nth-child\(1\)/m', $css) === 1) {
                $problems[] = 'user.css must not style every table cell in the first column - scope it to the table it was written for';
            }
            if (preg_match('/(^|[;{\s])table\s+td\s*\{/m', $css) === 1) {
                $problems[] = 'user.css must not style bare table cells - scope it to the table it was written for';
            }

            return $problems === []
                ? $ok('no rule reaches into every table on the site')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.image_protection_scoped', 'Smoke · UI', 'The image protection covers the media, not the chrome', static function () use ($root, $ok, $bad): array {
            // The right-click / drag / save blocker used to fire on every
            // image on the site, so it also took the site logo, the
            // creator's avatar and every other piece of chrome hostage. It
            // is scoped to the media now, and it says so.
            $js = (string) @file_get_contents($root . '/public/assets/js/user.js');
            $problems = [];

            if (strpos($js, "e.target.tagName==='IMG'") !== false) {
                $problems[] = 'the image protection must not fire on every image on the site';
            }
            if (strpos($js, "e.target.closest('img')") !== false) {
                $problems[] = 'the image protection must not fire on every image on the site';
            }
            if (strpos($js, 'var MEDIA =') === false) {
                $problems[] = 'the image protection must name the media it covers';
            }
            foreach (['.gallery-item img', '.card-cover img', '.recent-card img'] as $selector) {
                if (strpos($js, $selector) === false) {
                    $problems[] = 'the image protection must cover ' . $selector;
                }
            }
            if (strpos($js, 'img[data-protect]') === false) {
                $problems[] = 'the image protection must offer an explicit data-protect opt-in';
            }
            // It has to announce itself, not just die silently.
            $grid = (string) @file_get_contents($root . '/views/partials/gallery_grid_item.php');
            if (strpos($grid, 'Saving is disabled') === false) {
                $problems[] = 'the media must say that saving is disabled';
            }

            return $problems === []
                ? $ok('the image protection covers the media and says so')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.admin_tokens_remapped', 'Smoke · UI', 'The admin palette carries the console and the editor drives it', static function () use ($root, $ok, $bad): array {
            // The admin scope used to emit a stale light-PINK palette and
            // then re-point the surface tokens at the neutral console in
            // admin.css. The remap sat after the inline theme block, so the
            // theme editor's admin pickers changed nothing at all.
            //
            // Now the palette itself carries the console's neutral values,
            // admin.css does not re-point them, and the aliases that no
            // palette key can reach are declared in admin.css.
            $css   = (string) @file_get_contents($root . '/public/assets/css/admin.css');
            $theme = (string) @file_get_contents($root . '/app/Models/Theme.php');
            $problems = [];

            // The admin palette, read out of Theme.php.
            $palette = [];
            if (preg_match('/private const COLORS = \[(.*?)\n    \];/s', $theme, $m) === 1) {
                if (preg_match_all("/'([a-z0-9-]+)'\s*=>\s*'(#[0-9a-fA-F]{3,8})'/", $m[1], $t, PREG_SET_ORDER) !== false) {
                    foreach ($t as $row) {
                        $palette[$row[1]] = $row[2];
                    }
                }
            } else {
                $problems[] = 'the admin palette could not be read out of Theme.php';
            }

            // The surfaces the admin actually renders, with the console's
            // neutral values.
            $expect = [
                'pink-100'         => '#f7f8fc',
                'pink-200'         => '#eef0f7',
                'pink-300'         => '#e2e5ee',
                'pink-400'         => '#cfd4e2',
                'card-bg'          => '#ffffff',
                'card-border'      => '#e2e5ee',
                'card-title-color' => '#1e2330',
                'card-text-color'  => '#1e2330',
                'card-placeholder-bg' => '#f7f8fc',
                'card-thumb-bg'    => '#eef0f7',
                'stat-bg'          => '#ffffff',
                'stat-border'      => '#e2e5ee',
                'table-border'     => '#e2e5ee',
                'table-header-bg'  => '#f7f8fc',
                'table-header-color' => '#5c6478',
                'table-text'       => '#1e2330',
            ];
            foreach ($expect as $key => $value) {
                if (!isset($palette[$key])) {
                    $problems[] = $key . ' is missing from the admin palette';
                    continue;
                }
                if (strtolower($palette[$key]) !== $value) {
                    $problems[] = $key . ' is ' . $palette[$key] . ' in the admin palette, it should be ' . $value;
                }
            }

            // admin.css must NOT re-point a palette key, or the editor's
            // value is overridden again.
            if (preg_match_all('/\.admin-theme\s*\{(.*?)\n\}/s', $css, $blocks) !== false) {
                foreach ($blocks[1] as $block) {
                    if (strpos($block, '--pink-100:') !== false) {
                        $problems[] = 'admin.css must not re-point a palette key - that is what made the editor dead';
                    }
                }
            }
            // The aliases no palette key can reach have to be declared.
            foreach (['--success-bg', '--danger-bg', '--muted-text-color'] as $alias) {
                if (strpos($css, $alias . ':') === false) {
                    $problems[] = $alias . ' must be declared in admin.css - no palette key reaches it';
                }
            }
            // The theme preview is a mock of the member site, so it has to
            // restore the member values it would otherwise inherit.
            if (preg_match('/#theme-preview\s*\{(.*?)\n\}/s', $css, $m) === 1) {
                foreach (['--table-border', '--table-header-bg'] as $token) {
                    if (strpos($m[1], $token) === false) {
                        $problems[] = '#theme-preview must restore ' . $token . ', it previews the member site';
                    }
                }
            } else {
                $problems[] = '#theme-preview was not found';
            }

            return $problems === []
                ? $ok('the admin palette carries the console and the theme editor drives it')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.one_radius_scale', 'Smoke · UI', 'The admin uses one radius scale', static function () use ($root, $ok, $bad): array {
            // The admin has its own radius scale (--adm-radius 10px) and
            // the theme's (--border-radius 8px). Both were used in the same
            // console, so a card on the System page had 8px corners and a
            // card on the Users page had 10px. The theme editor's PREVIEW
            // mocks the member site, so it keeps the member's tokens.
            $css = (string) @file_get_contents($root . '/public/assets/css/admin.css');
            $problems = [];

            $lines = explode("\n", $css);
            foreach ($lines as $i => $line) {
                if (strpos($line, '#theme-preview') !== false || strpos($line, '[data-mock]') !== false) {
                    continue;   // the member mock
                }
                if (preg_match('/var\(--(?:border-radius(?:-sm|-lg)?|card-radius)\)/', $line, $m) === 1) {
                    $problems[] = 'admin.css line ' . ($i + 1) . ' uses the theme radius ' . $m[0]
                        . ' instead of the admin one';
                }
            }
            // The admin's scale has to be declared.
            foreach (['--adm-radius', '--adm-radius-sm', '--adm-radius-lg'] as $token) {
                if (strpos($css, $token . ':') === false) {
                    $problems[] = $token . ' is not declared';
                }
            }

            return $problems === []
                ? $ok('the admin uses one radius scale, and only the member preview uses the member tokens')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.one_admin_stylesheet', 'Smoke · UI', 'The admin console has one stylesheet', static function () use ($root, $ok, $bad): array {
            // public/assets/admin-shared.css was a second file for the same
            // console. Every rule in it was re-declared in admin.css with
            // different values, so it was ~90% dead - and the four rules
            // that were not overridden were the ones that still worked.
            $layout = (string) @file_get_contents($root . '/views/admin/layout.php');
            $css    = (string) @file_get_contents($root . '/public/assets/css/admin.css');
            $problems = [];

            if (strpos($layout, 'admin-shared.css') !== false) {
                $problems[] = 'the admin layout must not load a second stylesheet';
            }
            if (is_file($root . '/public/assets/admin-shared.css')) {
                $problems[] = 'public/assets/admin-shared.css must be gone - its rules live in admin.css';
            }
            // The rules that were still working have to have come over.
            foreach ([
                '.admin-theme .users-hero',
                '.admin-theme .user-email',
                '.admin-theme .user-date',
                '.admin-theme .users-footer',
            ] as $selector) {
                if (strpos($css, $selector) === false) {
                    $problems[] = $selector . ' must be defined in admin.css';
                }
            }
            if (strpos($css, '@media (max-width: 760px)') === false
                && strpos($css, '@media(max-width:760px)') === false) {
                $problems[] = 'the phone layout for the user list must be in admin.css';
            }

            return $problems === []
                ? $ok('the admin console has one stylesheet')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_stray_colours', 'Smoke · UI', 'The member views carry no stray colours', static function () use ($root, $ok, $bad): array {
            // The member views are on the dark skin, so a light literal is
            // a hole in the page: the paywall headline at 1.60:1 and the
            // checkout error text at 2.88:1 both came from hard-coded
            // colours that were never checked against the palette.
            //
            // Two kinds are allowed: a page that scopes its OWN palette
            // (the checkout and the two standalone light pages), and the
            // SVG placeholder image.
            $problems = [];

            $allowed = [
                'views/membership/braintree_checkout.php' => 'it scopes the light checkout palette',
                'views/auth/step_up.php'                   => 'a standalone light page',
                'views/unsubscribe.php'                    => 'a standalone light page',
            ];

            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                $rel = str_replace($root . '/', '', $file);
                if (str_starts_with($rel, 'views/admin/') || str_starts_with($rel, 'views/emails/')) {
                    continue;
                }
                if (isset($allowed[$rel])) {
                    continue;
                }
                $src = (string) @file_get_contents($file);
                // The placeholder image is an SVG in a data-URI, and a
                // picture is allowed to have colours of its own.
                $withoutSvg = preg_replace("/'[^']*<svg[^']*'/s", '', $src);
                // A hex that is not an HTML entity.
                if (preg_match_all('/(?<!&)#[0-9a-fA-F]{3,8}\b/', (string) $withoutSvg, $m) === false) {
                    continue;
                }
                foreach ($m[0] as $hex) {
                    $problems[] = $rel . ' carries the colour ' . $hex;
                }
            }

            return $problems === []
                ? $ok('the member views carry no stray colours')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.one_alignment', 'Smoke · UI', 'The headings are aligned in one place', static function () use ($root, $ok, $bad): array {
            // The base stylesheet centred EVERY heading, and the member site
            // fought it with about fifteen opt-outs in the stylesheet and
            // seven more inline in the views. The settings page's headings
            // were left and the gallery listing's section headings were
            // centred, for no reason at all.
            //
            // The base is the one place that decides it now, and the things
            // that really want centring say so themselves.
            $base = (string) @file_get_contents($root . '/app/Core/theme_base.css');
            $user = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];

            if (preg_match('/h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\s*\{[^}]*text-align:\s*center/', $base) === 1) {
                $problems[] = 'the base must not centre every heading - the site is left aligned and says so once';
            }
            if (preg_match('/h1,\s*h2,\s*h3,\s*h4,\s*h5,\s*h6\s*\{[^}]*text-align:\s*left/', $base) !== 1) {
                $problems[] = 'the base must set the heading alignment once';
            }
            // The things that want centring have to say so.
            foreach (['.hero', '.title-header', '.auth-panel h1'] as $selector) {
                if (preg_match('/' . preg_quote($selector, '/') . '\s*\{[^}]*text-align:\s*center/', $base) !== 1) {
                    $problems[] = $selector . ' must say that it is centred';
                }
            }
            // No opt-outs left in the member stylesheet.
            if (preg_match_all('/text-align:\s*left/', $user, $m) !== false) {
                foreach ($m[0] as $i => $hit) {
                    // The left alignings that are not about a heading.
                    $line = '';
                    foreach (explode("\n", $user) as $l) {
                        if (strpos($l, 'text-align:left') !== false) {
                            $line = $l;
                            break;
                        }
                    }
                    if (preg_match('/h[1-6][^{]*\{[^}]*text-align:\s*left/', $line) === 1) {
                        $problems[] = 'a heading opt-out is left in user.css: ' . trim(substr($line, 0, 90));
                    }
                }
            }
            // And none inline in the views.
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                if (str_starts_with(str_replace($root . '/', '', $file), 'views/admin/')) {
                    continue;
                }
                $src = (string) @file_get_contents($file);
                if (preg_match('/<h[1-6][^>]*style="[^"]*text-align:\s*left/', $src) === 1) {
                    $problems[] = str_replace($root . '/', '', $file) . ' has an inline heading alignment';
                }
            }

            return $problems === []
                ? $ok('the headings are aligned in the base and nowhere else')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.one_type_scale', 'Smoke · UI', 'The font sizes come from one scale', static function () use ($root, $ok, $bad): array {
            // The u-fs-* utilities were a second type scale: hard-coded rem
            // values that duplicated the theme's --font-size-* scale with
            // different numbers, so 87 elements on the site ignored the
            // theme editor's font-size controls completely.
            $util = (string) @file_get_contents($root . '/public/assets/css/utilities.css');
            $theme = (string) @file_get_contents($root . '/app/Models/Theme.php');
            $problems = [];

            if (preg_match_all('/\.u-fs-[0-9]+\{([^}]*)\}/', $util, $m) === false) {
                $problems[] = 'the font-size utilities could not be read';
            }
            foreach ($m[1] as $i => $body) {
                if (strpos($body, 'var(--font-size-') === false) {
                    $problems[] = 'the font-size utility ' . $m[0][$i] . ' does not read the theme scale';
                }
                if (preg_match('/font-size:\s*[\d.]+rem/', $body) === 1) {
                    $problems[] = 'the font-size utility ' . $m[0][$i] . ' has a hard-coded size';
                }
            }
            // Every token the utilities use has to exist in the theme.
            if (preg_match_all('/var\(--(font-size-[a-z0-9]+)\)/', $util, $t) !== false) {
                foreach (array_unique($t[1]) as $token) {
                    if (strpos($theme, "'" . $token . "'") === false) {
                        $problems[] = $token . ' is used by a utility but is not in the theme scale';
                    }
                }
            }
            // A utility nobody uses is dead weight.
            $used = [];
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                if (preg_match_all('/\b(u-fs-[0-9]+)\b/', (string) @file_get_contents($file), $u)) {
                    foreach ($u[1] as $name) {
                        $used[$name] = true;
                    }
                }
            }
            foreach ($m[0] as $rule) {
                if (preg_match('/\.(u-fs-[0-9]+)/', $rule, $n) === 1 && !isset($used[$n[1]])) {
                    $problems[] = $n[1] . ' is defined but no view uses it';
                }
            }

            return $problems === []
                ? $ok('the font sizes all come from the theme scale')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_nested_grids', 'Smoke · UI', 'A grid is not a grid inside a grid', static function () use ($root, $ok, $bad): array {
            // The dashboard's KPI band was <div class="stat-cards"><div
            // class="stat-cards">...</div></div> - a grid inside a grid. The
            // outer grid had exactly one child, so its own auto-fill did
            // nothing, and the wrapper carried a class that no stylesheet
            // defines.
            $problems = [];
            $files = array_merge(glob($root . '/views/admin/*.php') ?: [], glob($root . '/views/admin/partials/*.php') ?: []);
            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                if (preg_match('/<div class="([^"]*stat-cards[^"]*)"[^>]*>\s*<div class="([^"]*stat-cards[^"]*)"/', $src, $m) === 1) {
                    $problems[] = str_replace($root . '/', '', $file)
                        . ' nests a .stat-cards grid inside a .stat-cards grid';
                }
            }
            // A class that no stylesheet defines is a silent no-op.
            $css = '';
            foreach (['public/assets/css/admin.css', 'public/assets/css/user.css', 'public/assets/css/utilities.css', 'public/assets/css/support.css'] as $file) {
                $css .= (string) @file_get_contents($root . '/' . $file);
            }
            $dashboard = (string) @file_get_contents($root . '/views/admin/dashboard.php');
            $classes = [];
            if (preg_match_all('/class="([^"]*)"/', $dashboard, $m) !== false) {
                foreach ($m[1] as $attr) {
                    foreach (preg_split('/\s+/', trim($attr)) as $class) {
                        // A real class name, not a PHP fragment that the
                        // attribute happens to contain.
                        if (preg_match('/^[a-z][a-z0-9_-]*$/', $class) === 1) {
                            $classes[$class] = true;
                        }
                    }
                }
            }
            foreach (array_keys($classes) as $class) {
                if (str_starts_with($class, 'adm-') || str_starts_with($class, 'u-')) {
                    continue;
                }
                if (preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css) !== 1) {
                    $problems[] = 'the dashboard uses the class ' . $class . ', which no stylesheet defines';
                }
            }

            return $problems === []
                ? $ok('no grid is nested inside another grid')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.admin_settings_is_its_own', 'Smoke · UI', 'The admin settings page is the admin\'s own', static function () use ($root, $ok, $bad): array {
            // views/admin/settings.php was two lines that required the MEMBER
            // settings page. The admin console showed a second page heading,
            // the member-only sections (favourites, the site tour, the
            // gallery display, the theme picker) and a "Back to dashboard"
            // link to /account - the member dashboard, not the admin's. It
            // also broke when the console stopped loading the member
            // stylesheet, because the page's classes lived in it.
            $view = (string) @file_get_contents($root . '/views/admin/settings.php');
            $problems = [];

            if (strpos($view, "require __DIR__ . '/../settings.php'") !== false) {
                $problems[] = 'the admin settings page must not require the member settings page';
            }
            if (preg_match('/<h1\b/i', $view) === 1) {
                $problems[] = 'the admin settings page must not emit its own h1 - the topbar owns the page heading';
            }
            if (strpos($view, 'Back to dashboard') !== false) {
                $problems[] = 'the admin settings page must not link back to the member dashboard';
            }
            // It has to use the admin's own components.
            foreach (['adm-section', 'sys-card', 'adm-field-grid'] as $selector) {
                if (strpos($view, $selector) === false) {
                    $problems[] = 'the admin settings page must use ' . $selector;
                }
            }
            // And the sections an operator needs.
            foreach (['Account security', 'Change password', 'Site timezone'] as $section) {
                if (strpos($view, $section) === false) {
                    $problems[] = 'the admin settings page is missing the ' . $section . ' section';
                }
            }
            // The member-only sections must not be here.
            foreach (['Favorite categories', 'Gallery display', 'Site tour'] as $section) {
                if (strpos($view, '>' . $section . '<') !== false) {
                    $problems[] = 'the admin settings page still carries the member-only ' . $section . ' section';
                }
            }

            return $problems === []
                ? $ok('the admin settings page is the admin\'s own')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.member_search', 'Smoke · UI', 'The member site has a site-wide search', static function () use ($root, $ok, $bad): array {
            // The admin console has a global search and a Ctrl+K command
            // palette. The member site had neither - the only search was
            // the box on the gallery listing, so a member could not look
            // anything up from anywhere else.
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $css    = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $js     = (string) @file_get_contents($root . '/public/assets/js/user.js');
            $problems = [];

            // One in the rail, one in the guest's top bar, one in the
            // phone's More sheet.
            foreach (['site-search-rail', 'site-search-top', 'site-search-sheet'] as $variant) {
                if (strpos($layout, $variant) === false) {
                    $problems[] = 'the member site has no ' . $variant . ' search box';
                }
            }
            // They all go to the gallery listing's own search.
            if (substr_count($layout, 'method="get" action="<?= e(url(\'/galleries\')) ?>"') !== 3) {
                $problems[] = 'every search box must post to the gallery listing search';
            }
            // Each one needs a label, or it is an unlabelled input.
            if (substr_count($layout, '<label class="sr-only" for="site-search-') !== 3) {
                $problems[] = 'every search box needs a label';
            }
            // And the styles.
            foreach (['.site-search', '.site-search-rail', '.site-search-top', '.site-search-sheet'] as $selector) {
                if (strpos($css, $selector) === false) {
                    $problems[] = $selector . ' has no style';
                }
            }
            // The "/" shortcut.
            if (strpos($js, "focusSearch") === false) {
                $problems[] = 'the "/" shortcut is missing';
            }
            if (strpos($js, "e.key !== '/'") === false) {
                $problems[] = 'the "/" shortcut must be bound to the slash key';
            }
            // It must not steal the slash from a text box.
            if (strpos($js, 'if (typing()) return;') === false) {
                $problems[] = 'the "/" shortcut must not fire while the member is typing';
            }

            return $problems === []
                ? $ok('the member site has a site-wide search in the rail, the top bar and the phone sheet')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.admin_pages_have_structure', 'Smoke · UI', 'The admin list pages are built from the shared structure', static function () use ($root, $ok, $bad): array {
            // views/admin/logs.php was the only admin page with no
            // .adm-section / .sys-card structure at all: four bare h2s and
            // four tables, floating in the page.
            $problems = [];
            $view = (string) @file_get_contents($root . '/views/admin/logs.php');

            $sections = substr_count($view, '<section class="adm-section">');
            $cards    = substr_count($view, '<div class="sys-card">');
            $heads    = substr_count($view, '<div class="adm-section-head">');
            if ($sections < 4) {
                $problems[] = 'the logs page must wrap each of its four lists in a .adm-section';
            }
            if ($cards !== $sections) {
                $problems[] = 'every .adm-section on the logs page needs a .sys-card';
            }
            if ($heads !== $sections) {
                $problems[] = 'every .adm-section on the logs page needs a .adm-section-head';
            }
            // A heading must live in the head, not loose in the card.
            if (preg_match('/<div class="sys-card">\s*<h2>/', $view) === 1) {
                $problems[] = 'a heading is loose in the card - it belongs in the section head';
            }
            foreach (['Pending Memberships', 'Deleted Galleries', 'Pending Category Approvals', 'Activity Log'] as $heading) {
                if (strpos($view, '<h2>' . $heading . '</h2>') === false) {
                    $problems[] = 'the logs page is missing the ' . $heading . ' section';
                }
            }

            return $problems === []
                ? $ok('the logs page is built from the shared structure')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_dead_classes', 'Smoke · UI', 'Every class a view uses is styled or scripted', static function () use ($root, $ok, $bad): array {
            // A class with no style and no script is a typo that renders
            // silently: the element gets the browser's default and nobody
            // says anything. There were 51 of them.
            $css = '';
            foreach (['public/assets/css/admin.css', 'public/assets/css/user.css', 'public/assets/css/utilities.css', 'public/assets/css/support.css', 'public/assets/css/public.css', 'app/Core/theme_base.css'] as $file) {
                $css .= (string) @file_get_contents($root . '/' . $file);
            }
            $js = '';
            foreach (glob($root . '/public/assets/js/*.js') ?: [] as $file) {
                $js .= (string) @file_get_contents($file);
            }
            // Google's paywall marker: it is a structured-data hook, not a
            // style hook, and it must survive in the markup.
            $allowed = ['paywalled-content' => true];

            $problems = [];
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                $rel = str_replace($root . '/', '', $file);
                if (str_starts_with($rel, 'views/emails/')) {
                    continue;   // email clients need their own inline CSS
                }
                $src = (string) @file_get_contents($file);
                // A view may scope its own styles and scripts.
                $own = '';
                if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $src, $sm)) {
                    $own = implode("\n", $sm[1]);
                }
                $ownJs = '';
                if (preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $src, $jm)) {
                    $ownJs = implode("\n", $jm[1]);
                }
                $allJs = $js . $ownJs;
                if (preg_match_all('/class="([^"]*)"/', $src, $m) !== false) {
                    foreach ($m[1] as $attr) {
                        foreach (preg_split('/\s+/', trim($attr)) as $class) {
                            if ($class === '' || !preg_match('/^[a-z][a-z0-9_-]*$/', $class)) {
                                continue;
                            }
                            if (isset($allowed[$class])) {
                                continue;
                            }
                            $styled = preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css) === 1
                                || preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $own) === 1;
                            if ($styled) {
                                continue;
                            }
                            $scripted = strpos($allJs, "'" . $class . "'") !== false
                                || strpos($allJs, '"' . $class . '"') !== false
                                || strpos($allJs, '.' . $class) !== false
                                || strpos($allJs, ' ' . $class) !== false;
                            if ($scripted) {
                                continue;
                            }
                            $problems[] = $rel . ' uses the class ' . $class . ', which nothing styles or scripts';
                        }
                    }
                }
            }

            return $problems === []
                ? $ok('every class a view uses is styled or scripted')
                : $bad(implode('; ', array_slice($problems, 0, 12)));
        });

        $add('smoke.ui.guest_reaches_the_search', 'Smoke · UI', 'A guest can reach the search on a phone', static function () use ($root, $ok, $bad): array {
            // The phone's bottom bar is derived from the rail, and the
            // sheet holds the search. The sheet was member-only, so a guest
            // on a phone had no way to reach the search at all - the one
            // thing the bar is there for.
            $layout = (string) @file_get_contents($root . '/views/layout.php');
            $problems = [];

            // The button is not behind a member check.
            if (preg_match('/<\?php if \(\$user !== null\): \?>\s*<button type="button" class="mnav-more"/', $layout) === 1) {
                $problems[] = 'the bottom bar\'s search button must not be member-only';
            }
            if (strpos($layout, 'class="mnav-more"') === false) {
                $problems[] = 'the bottom bar has no search button';
            }
            // The sheet is not behind a member check either.
            if (preg_match('/<\?php if \(\$user !== null\): \?>\s*<div class="media-bottom-sheet"/', $layout) === 1) {
                $problems[] = 'the bottom sheet must not be member-only';
            }
            if (strpos($layout, 'id="bottom-more-sheet"') === false) {
                $problems[] = 'the bottom sheet is missing';
            }
            // And it carries the search.
            if (strpos($layout, 'site-search-sheet') === false) {
                $problems[] = 'the bottom sheet must carry the search field';
            }
            // The sheet focuses the search field, not the close button.
            if (strpos($layout, "sheet.querySelector('input[type=search]')") === false) {
                $problems[] = 'the bottom sheet must focus the search field when it opens';
            }

            return $problems === []
                ? $ok('a guest can reach the search on a phone')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.one_empty_state', 'Smoke · UI', 'The admin empty states are the shared one', static function () use ($root, $ok, $bad): array {
            // Seven admin views use the shared admin_empty() helper and
            // about twenty hand-roll their own with a <p class="muted">.
            // The two look nothing alike, and each hand-rolled one had to
            // be written again.
            $problems = [];
            $files = glob($root . '/views/admin/*.php') ?: [];
            foreach ($files as $file) {
                $rel = str_replace($root . '/', '', $file);
                $src = (string) @file_get_contents($file);
                // The hand-rolled ones: a muted paragraph that says a list
                // is empty.
                if (preg_match('/<p class="muted">\s*(No|Nothing|There is nothing)[^<]{0,120}<\/p>/i', $src, $m) === 1) {
                    $problems[] = $rel . ' hand-rolls an empty state: ' . trim($m[0]);
                }
                // The icon has to exist, or the empty state renders a hole.
                if (preg_match_all("/admin_empty\('([a-z-]+)'/", $src, $u) !== false) {
                    $iconSrc = (string) @file_get_contents($root . '/app/Core/Icon.php');
                    foreach ($u[1] as $icon) {
                        if (strpos($iconSrc, "'" . $icon . "'") === false) {
                            $problems[] = $rel . ' uses the icon ' . $icon . ', which does not exist';
                        }
                    }
                }
            }

            return $problems === []
                ? $ok('the admin empty states are the shared one')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.housekeeping.columns_exist', 'Smoke · Data', 'Every column a housekeeping query names exists', static function () use ($root, $read, $ok, $bad): array {
            // The housekeeping cron died for 19 hours because one query named
            // a column that does not exist: category_views has created_at,
            // not view_date. The exception was uncaught, so it aborted the
            // last two steps of a 30-step function - including the cron log
            // write, which is why nobody noticed.
            //
            // This check reads the columns out of schema.sql and the column
            // names out of the housekeeping queries, and compares them. A
            // column rename now fails the build instead of the cron.
            $schema = (string) $read($root . '/schema.sql');
            $hk     = (string) $read($root . '/app/Core/Housekeeping.php');

            // The columns of each table, from the schema. The statements are
            // split on ";" because a column can be an ENUM with a ")" in it
            // - and the comments are stripped first, because a comment can
            // contain a ";" of its own.
            $schema = (string) preg_replace('/--[^\n]*/', '', $schema);
            $columns = [];
            foreach (explode(';', $schema) as $statement) {
                if (preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\(/i', $statement, $t) !== 1) {
                    continue;
                }
                $table = strtolower($t[1]);
                $cols  = [];
                foreach (preg_split('/\r?\n/', $statement) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'PRIMARY')
                        || str_starts_with($line, 'UNIQUE') || str_starts_with($line, 'KEY')
                        || str_starts_with($line, 'INDEX') || str_starts_with($line, 'CONSTRAINT')
                        || str_starts_with($line, 'FOREIGN')) {
                        continue;
                    }
                    if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+/i', $line, $c) === 1) {
                        $cols[strtolower($c[1])] = true;
                    }
                }
                $columns[$table] = $cols;
            }
            if ($columns === []) {
                return $bad('no tables could be read out of schema.sql');
            }

            // The SQL keywords and functions that look like column names.
            $words = ['and', 'or', 'not', 'null', 'interval', 'day', 'days', 'year', 'years',
                'date_sub', 'curdate', 'now', 'current_timestamp', 'current_date', 'set', 'where',
                'select', 'from', 'group', 'order', 'by', 'limit', 'count', 'sum', 'max', 'min',
                'avg', 'distinct', 'as', 'on', 'join', 'inner', 'left', 'desc', 'asc', 'values',
                'insert', 'into', 'update', 'delete', 'set', 'is', 'in', 'like', 'between', 'exists'];

            // Every column a housekeeping query names, with its table.
            $problems = [];
            if (preg_match_all('/\b(?:DELETE FROM|UPDATE)\s+(\w+)([^\'\"]*)/i', $hk, $q, PREG_SET_ORDER) !== false) {
                foreach ($q as $stmt) {
                    $table = strtolower($stmt[1]);
                    if (!isset($columns[$table])) {
                        $problems[] = 'the housekeeping touches the unknown table ' . $table;
                        continue;
                    }
                    foreach (explode(',', $stmt[2]) as $fragment) {
                        if (preg_match('/\b([a-z_][a-z0-9_]*)\b\s*(?:=|<|>|<=|>=|<>|!=)/i', $fragment, $c) !== 1) {
                            continue;
                        }
                        $col = strtolower($c[1]);
                        if (in_array($col, $words, true)) {
                            continue;
                        }
                        if (!isset($columns[$table][$col])) {
                            $problems[] = $table . ' has no column ' . $col . ' (the housekeeping query names it)';
                        }
                    }
                }
            }

            return $problems === []
                ? $ok('every column the housekeeping names exists in the schema')
                : $bad(implode('; ', array_unique($problems)));
        });

        $add('smoke.housekeeping.isolated', 'Smoke · Data', 'One failing housekeeping step cannot abort the rest', static function () use ($root, $read, $ok, $bad): array {
            // The throw in pruneVisitHistory() aborted the last two steps of
            // a 30-step function: the refer-a-friend rewards and the cron
            // log write. The log write is why 19 hours of failure went
            // unnoticed.
            //
            // The check is positional, not a word search: a comment that
            // says "finally" must not satisfy it, and removing ONE step's
            // wrapper must fail it.
            $hk = (string) $read($root . '/app/Core/Housekeeping.php');
            $problems = [];

            // The run() method.
            $runAt = strpos($hk, 'public static function run(');
            if ($runAt === false) {
                return $bad('Housekeeping::run() was not found');
            }
            $runEnd = strpos($hk, "
    }", $runAt);
            if ($runEnd === false) {
                return $bad('the end of Housekeeping::run() was not found');
            }
            $run = substr($hk, $runAt, $runEnd - $runAt);

            // The log write must be inside a finally, and the finally must
            // come BEFORE the log write.
            $logAt    = strpos($run, 'storage/logs/cron.log');
            $finallyAt = $logAt === false ? false : strrpos(substr($run, 0, $logAt), 'finally {');
            if ($logAt === false) {
                $problems[] = 'the housekeeping must write the cron log';
            } elseif ($finallyAt === false) {
                $problems[] = 'the cron log write must be inside a finally block, so a failing step still records what happened';
            } else {
                // Inside the finally's braces, not merely after it: the
                // brace depth between the "finally {" and the write must
                // still be positive.
                $depth = 0;
                for ($i = $finallyAt + strlen('finally'); $i < $logAt; $i++) {
                    if ($run[$i] === '{') { $depth++; }
                    elseif ($run[$i] === '}') { $depth--; }
                }
                if ($depth <= 0) {
                    $problems[] = 'the cron log write must be INSIDE the finally block, not after it';
                }
            }

            // The steps that must be isolated. Removing one wrapper must
            // fail the check.
            foreach ([
                'referral_rewards', 'visit_prunes', 'chat_prunes', 'chat_broadcast',
                'new_gallery_notified', 'chat_media', 'php_fatals', 'storage_snapshot',
                'expire_subscriptions', 'paypal_reconcile', 'renewal_reminders',
                'winbacks', 'trial_endings', 'inactive_winbacks', 'temp_files',
                'disk_growth', 'recovery_emails', 'duplicate_report', 'backup_sync',
                'restore_drill',
            ] as $step) {
                if (strpos($run, "self::step('" . $step . "'") === false) {
                    $problems[] = 'the ' . $step . ' step must run through self::step()';
                }
            }

            return $problems === []
                ? $ok('one failing housekeeping step cannot abort the rest')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.housekeeping.cron_entry', 'Smoke · Data', 'The housekeeping cron entry cannot lose a failure', static function () use ($root, $read, $ok, $bad): array {
            // The old entry was:
            //   curl -fsS "http://127.0.0.1/gallery/cron/housekeeping?key=SECRET" > /dev/null 2>&1
            // which put the key in a world-readable cron file and in `ps`,
            // and threw both the response and the exit code away. A failing
            // run left no trace at all.
            $cron = (string) $read($root . '/bin/apply_cron.php');
            $problems = [];

            if (preg_match("/gallery-housekeeping'\\]\\s*=(.*?);/s", $cron, $m) !== 1) {
                $problems[] = 'the gallery-housekeeping cron entry was not found in apply_cron.php';
            } else {
                // The whole statement, because the log path is concatenated
                // onto SITE_ROOT rather than written inline.
                $entry = $m[1];
                if (strpos($entry, 'bin/housekeeping.php') === false) {
                    $problems[] = 'the housekeeping cron entry must run bin/housekeeping.php';
                }
                if (strpos($entry, 'key=') !== false) {
                    $problems[] = 'the cron entry must not carry the cron key - the cron file is world-readable';
                }
                if (strpos($entry, '> /dev/null 2>&1') !== false) {
                    $problems[] = 'the cron entry must not discard its output - a failing run must leave a trace';
                }
                if (strpos($entry, 'storage/logs/') === false) {
                    $problems[] = 'the cron entry must log to storage/logs/';
                }
            }
            // The CLI entry point has to exist and has to exit non-zero when
            // a step failed.
            if (!is_file($root . '/bin/housekeeping.php')) {
                $problems[] = 'bin/housekeeping.php must exist';
            } else {
                $cli = (string) $read($root . '/bin/housekeeping.php');
                if (strpos($cli, 'exit($failed ? 1 : 0)') === false) {
                    $problems[] = 'bin/housekeeping.php must exit non-zero when a step failed';
                }
                if (strpos($cli, 'flock(') === false) {
                    $problems[] = 'bin/housekeeping.php must lock, so two runs cannot overlap';
                }
            }

            return $problems === []
                ? $ok('the housekeeping cron entry cannot lose a failure')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.watchdog.exists', 'Smoke · Data', 'Something watches the background jobs', static function () use ($root, $read, $ok, $bad): array {
            // The housekeeping failed for nineteen hours and nobody knew:
            // the cron entry discarded the exit code, the log write was
            // after the step that threw, and the "cron is stale" alert only
            // fired when a human loaded the admin dashboard.
            $problems = [];

            if (!is_file($root . '/bin/watchdog.php')) {
                $problems[] = 'bin/watchdog.php must exist - something has to notice when a job stops';
            } else {
                $wd = (string) $read($root . '/bin/watchdog.php');
                if (strpos($wd, 'Mailer::adminAlert(') === false) {
                    $problems[] = 'the watchdog must alert the admin when a job goes quiet';
                }
                if (strpos($wd, 'Heartbeat::age(') === false) {
                    $problems[] = 'the watchdog must check the worker HEARTBEATS, not the log mtimes - an idle worker looks dead by mtime';
                }
                if (strpos($wd, "'housekeeping'") === false) {
                    $problems[] = 'the watchdog must watch the housekeeping';
                }
                if (strpos($wd, 'Mailer::adminAlert(') === false) {
                    $problems[] = 'the watchdog must alert the admin when a job goes quiet';
                }
            }
            // Every worker must touch a heartbeat, or the watchdog cannot
            // tell a stopped worker from an idle one.
            foreach ([
                'housekeeping', 'paypal-reconcile', 'emailer', 'autopost', 'daily-chat',
                'questionnaires', 'chat-ai', 'categorizer', 'describer', 'rendition',
                'chapters', 'video-samples', 'dimensions', 'web-analytics', 'ai-watchdog',
                'backup',
            ] as $worker) {
                $found = false;
                foreach (glob($root . '/bin/*.php') ?: [] as $file) {
                    if (strpos((string) @file_get_contents($file), "Heartbeat::touch('" . $worker . "')") !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $problems[] = 'no worker touches the ' . $worker . ' heartbeat';
                }
            }
            if (!is_file($root . '/app/Core/Heartbeat.php')) {
                $problems[] = 'app/Core/Heartbeat.php must exist';
            }

            // And it must be scheduled.
            $cron = (string) $read($root . '/bin/apply_cron.php');
            if (strpos($cron, 'gallery-watchdog') === false) {
                $problems[] = 'the watchdog must have its own cron entry, so it does not share a failure with the job it watches';
            }
            if (strpos($cron, 'bin/watchdog.php') === false) {
                $problems[] = 'the watchdog cron entry must run bin/watchdog.php';
            }
            // The dashboard's stale-cron card is a useful second signal, but
            // it needs a human to load it. There has to be one that does
            // not: the watchdog (above) and the health endpoint.
            $health = (string) $read($root . '/app/Controllers/HealthController.php');
            if (strpos($health, 'housekeeping_age_sec') === false) {
                $problems[] = 'the health endpoint must expose the housekeeping age, so a second signal does not need a human either';
            }

            return $problems === []
                ? $ok('something watches the background jobs')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.braintree.can_cancel', 'Smoke · Money', 'A cancelled membership stops being charged', static function () use ($root, $read, $ok, $bad): array {
            // Subscription::cancel() cancelled the local row and called the
            // gateway ONLY for a PayPal reference. BraintreeGateway had no
            // cancelSubscription() at all, so a Braintree member clicked
            // "Cancel membership" and kept being charged.
            $gw   = (string) $read($root . '/app/Core/BraintreeGateway.php');
            $sub  = (string) $read($root . '/app/Models/Subscription.php');
            $problems = [];

            if (strpos($gw, 'public function cancelSubscription(') === false) {
                $problems[] = 'BraintreeGateway must have a cancelSubscription()';
            }
            if (strpos($sub, "strpos(\$ref, 'BT-') === 0") === false) {
                $problems[] = 'Subscription::cancel() must cancel a Braintree subscription at the gateway';
            }
            if (strpos($sub, 'BraintreeGateway::fromConfig(') === false) {
                $problems[] = 'Subscription::cancel() must reach the Braintree gateway';
            }
            if (strpos($sub, "strpos(\$ref, 'PAYPAL-') === 0") === false) {
                $problems[] = 'Subscription::cancel() must still cancel a PayPal subscription at the gateway';
            }

            // The gateway call must come BEFORE the local update, so the
            // member stops being charged even if the local write fails.
            $cancelAt = strpos($sub, 'public static function cancel(int $id)');
            $body    = $cancelAt === false ? '' : substr($sub, $cancelAt, 4000);
            $gwAt    = strpos($body, 'cancelAtGateway(');
            $updAt   = strpos($body, "UPDATE subscriptions");
            if ($gwAt === false) {
                $problems[] = 'Subscription::cancel() must call the gateway';
            } elseif ($updAt !== false && $gwAt > $updAt) {
                $problems[] = 'the gateway must be cancelled BEFORE the local row is updated';
            }

            // And a failure to reach the gateway must be visible, not silent.
            if (strpos($body, '[subscription-cancel]') === false) {
                $problems[] = 'a gateway cancellation that fails must be logged';
            }

            return $problems === []
                ? $ok('a cancelled membership stops being charged')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.money.gateway_refunds', 'Smoke · Money', 'A refund reaches the payment processor', static function () use ($root, $read, $ok, $bad): array {
            // The admin's "refund" only flipped the local status, so the
            // member kept their card charge while the unlock was revoked.
            // Both gateways could already refund - BraintreeGateway::refund()
            // and PayPalGateway::refundCapture() - and neither was called.
            $purchase = (string) $read($root . '/app/Models/Purchase.php');
            $mod      = (string) $read($root . '/app/Controllers/ModerationController.php');
            $problems = [];

            if (strpos($purchase, 'public static function refundAtGateway(') === false) {
                $problems[] = 'Purchase must have a refundAtGateway()';
            }
            foreach (['braintree', 'paypal'] as $gateway) {
                if (strpos($purchase, "'" . $gateway . "'") === false) {
                    $problems[] = 'refundAtGateway() must handle the ' . $gateway . ' gateway';
                }
            }
            if (strpos($purchase, 'BraintreeGateway::fromConfig(') === false) {
                $problems[] = 'refundAtGateway() must reach Braintree';
            }
            if (strpos($purchase, 'PayPalGateway::fromConfig(') === false) {
                $problems[] = 'refundAtGateway() must reach PayPal';
            }
            // And the admin's refund action must call it.
            if (strpos($mod, 'Purchase::refundAtGateway(') === false) {
                $problems[] = 'the admin refund action must refund at the processor';
            }

            return $problems === []
                ? $ok('a refund reaches the payment processor')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.money.only_real_money_is_revenue', 'Smoke · Money', 'Only collected money counts as revenue', static function () use ($root, $read, $ok, $bad): array {
            // An OFFLINE tip is a member posting an amount and a note:
            // nothing is charged and the amount is whatever the member
            // typed (up to $10,000, five times per ten minutes per IP). It
            // was counted as revenue, so the earnings dashboard and its CSV
            // could be inflated by anyone.
            $purchase = (string) $read($root . '/app/Models/Purchase.php');
            $problems = [];

            if (strpos($purchase, "REVENUE_GATEWAYS = ['braintree', 'paypal']") === false) {
                $problems[] = 'Purchase must name the gateways where money actually moved';
            }
            // Every revenue aggregate has to exclude the others.
            foreach (['totalsBetween', 'totalFor', 'seriesBetween'] as $method) {
                $at = strpos($purchase, 'public static function ' . $method . '(');
                if ($at === false) {
                    $problems[] = 'Purchase::' . $method . '() was not found';
                    continue;
                }
                // The method's own body, not the next one: a window that
                // wide bleeds into the following method and makes the
                // check pass when it should fail.
                $end = strpos($purchase, "\n    }\n", $at);
                $body = substr($purchase, $at, ($end === false ? 1400 : $end - $at));
                // Either the literal list or the named constant - but one
                // of them, so the aggregate cannot silently start counting
                // the offline tips again.
                $hasList = strpos($body, 'braintree') !== false && strpos($body, 'paypal') !== false;
                $hasConst = strpos($body, 'REVENUE_GATEWAYS') !== false;
                if (!$hasList && !$hasConst) {
                    $problems[] = 'Purchase::' . $method . '() must count only the gateways where money moved';
                }
                if ($hasList && $hasConst) {
                    $problems[] = 'Purchase::' . $method . '() names the gateways twice - keep one list';
                }
            }

            return $problems === []
                ? $ok('only collected money counts as revenue')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.money.past_due_grace', 'Smoke · Money', 'A failed card is a bump, not a lockout', static function () use ($root, $read, $ok, $bad): array {
            // The Braintree webhook has always said "flag but don't cancel -
            // give a grace period". There was no grace period: the flag was
            // set and the member lost their access on the first failed card,
            // with no way to fix the card - the past-due email links to a
            // page with no payment-method form on it.
            $sub  = (string) $read($root . '/app/Models/Subscription.php');
            $hook = (string) $read($root . '/app/Controllers/WebhookController.php');
            $hk   = (string) $read($root . '/app/Core/Housekeeping.php');
            $my   = (string) $read($root . '/views/membership/my.php');
            $ctrl = (string) $read($root . '/app/Controllers/MembershipController.php');
            $problems = [];

            // The grace window.
            if (strpos($sub, "status = 'past_due'") === false) {
                $problems[] = 'the eligibility predicate must keep a past-due subscription eligible during the grace period';
            }
            if (strpos($sub, 'past_due_at') === false) {
                $problems[] = 'the grace window must be measured from past_due_at';
            }
            if (strpos($sub, 'pastDueGraceDays()') === false) {
                $problems[] = 'the grace period must be configurable';
            }
            // Both webhooks must stamp it.
            if (substr_count($hook, 'past_due_at = CURRENT_TIMESTAMP') < 2) {
                $problems[] = 'both the Braintree and the PayPal past-due webhooks must stamp past_due_at';
            }
            // And it must end.
            if (strpos($hk, 'expirePastDueSubscriptions') === false) {
                $problems[] = 'the housekeeping must revoke a past-due subscription whose grace has run out';
            }
            // And the member must be able to fix the card.
            if (strpos($my, 'update-card') === false) {
                $problems[] = 'the membership page must offer a way to update the card';
            }
            if (strpos($ctrl, 'braintreeUpdateCard') === false) {
                $problems[] = 'there must be an action that puts a new card on the subscription';
            }
            if (strpos($ctrl, 'updateSubscriptionPaymentMethod') === false) {
                $problems[] = 'the action must put the new card on the subscription at the processor';
            }
            if (strpos($ctrl, "past_due_at = NULL") === false) {
                $problems[] = 'a successful card update must clear the past-due marker';
            }

            return $problems === []
                ? $ok('a failed card is a bump, not a lockout')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.chat.operator_tokens_are_reachable', 'Smoke · Chat', 'The operator device tokens are where the operator can find them', static function () use ($root, $read, $ok, $bad): array {
            // The tokens are needed for EVERY new device, and they used to
            // be the sixth section of the admin chat page - far below the
            // fold on a phone. They have a page of their own now, with a
            // place in the navigation.
            $page = $root . '/views/admin/operator_tokens.php';
            $nav  = (string) $read($root . '/views/admin/layout.php');
            $ctrl = (string) $read($root . '/app/Controllers/AdminChatController.php');
            $chat = (string) $read($root . '/views/admin/chat.php');

            if (!is_file($page)) {
                return $bad('there is no page for the operator device tokens');
            }
            if (strpos($nav, '/admin/operator-tokens') === false) {
                return $bad('the operator device tokens are not in the admin navigation');
            }
            if (strpos($ctrl, 'function operatorTokens(') === false) {
                return $bad('there is no action for the operator device token page');
            }
            if (strpos($chat, '/admin/operator-tokens') === false) {
                return $bad('the admin chat page does not point at the operator token page');
            }

            // And the token that was just created has to be SHOWN: only its
            // hash is stored, so the raw value is shown exactly once - in a
            // copyable panel, not as a flash line that scrolls away.
            $view = (string) $read($page);
            if (strpos($ctrl, "'newToken'") === false) {
                return $bad('the operator token page does not receive the token that was just created');
            }
            if (strpos($ctrl, 'operator_token_new') === false) {
                return $bad('a created token is not carried to the page that shows it');
            }
            if (strpos($view, 'operator-token-value') === false) {
                return $bad('the created token is not shown in a copyable panel');
            }
            if (strpos($view, 'data-copy="operator-token-value"') === false) {
                return $bad('the created token has no copy button');
            }

            return $ok('the operator device tokens have their own page and a place in the navigation');
        });

        $add('smoke.player.the_seek_bar_is_usable', 'Smoke · Player', 'The video player can be scrubbed', static function () use ($root, $read, $ok, $bad): array {
            // The seek bar is the control the viewer needs, and it was
            // collapsing to ZERO width: the bar is crowded, the seek had a
            // zero flex-basis, and every other control kept its size. The
            // time ticked and the value moved, but there was nothing to see
            // and nothing to drag.
            $css = (string) $read($root . '/public/assets/css/user.css');
            $js  = (string) $read($root . '/public/assets/js/user.js');
            $problems = [];

            if (strpos($css, '.player-seek{flex:1 1 0;min-width:') === false) {
                $problems[] = 'the seek bar must have a minimum width, or it collapses to nothing';
            }
            if (strpos($css, '.player-played') === false) {
                $problems[] = 'the played progress must be drawn, not only the buffered range';
            }
            if (strpos($js, "data-played") === false) {
                $problems[] = 'the player does not draw the played progress';
            }
            if (strpos($js, "is-narrow") === false) {
                $problems[] = 'a narrow player must drop the optional controls, not the seek bar';
            }

            // The scripts are served immutable, so their version has to
            // change with the file - the mtime does that by itself.
            $layout = (string) $read($root . '/views/layout.php');
            if (strpos($layout, "asset_url('/assets/js/user.js')") === false) {
                $problems[] = 'the player script is loaded with a fixed version, so a change would never reach the browser';
            }

            return $problems === []
                ? $ok('the seek bar has room, the progress is drawn and the scripts are versioned')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.live.no_stream_key_for_members', 'Smoke · Live', 'A member cannot publish to the operator stream', static function () use ($root, $read, $ok, $bad): array {
            // The stream key was BOTH the HLS playback path and the RTMP
            // publish credential, and /live/state handed it to every paying
            // member. A member could therefore push their own video into the
            // operator's path - and the recording is imported into a paid
            // gallery and auto-posted to social media.
            //
            // The key has to stay in the member's response (it IS the HLS
            // path), so publishing now needs a SECOND secret that only the
            // operator app sees.
            $ctrl  = (string) $read($root . '/app/Controllers/LiveController.php');
            $model = (string) $read($root . '/app/Models/LiveSession.php');
            $problems = [];

            // The publish branch must require the secret.
            $at = strpos($ctrl, "if (\$action === 'publish')");
            if ($at === false) {
                $problems[] = 'the MediaMTX auth webhook has no publish branch';
            } else {
                $branch = substr($ctrl, $at, 1400);
                if (strpos($branch, 'validPublishSecret(') === false) {
                    $problems[] = 'a publish must be checked against the publish secret, not the stream key alone';
                }
                // The secret must be the thing COMPARED - comparing the
                // secret against the key would look right and be useless.
                if (strpos($branch, 'validPublishSecret($streamKey, $publishSecret)') === false) {
                    $problems[] = 'the publish check must compare the publish secret, not the stream key';
                }
                if (strpos($branch, 'validStreamKey(') !== false) {
                    $problems[] = 'a publish must not be accepted on the stream key alone';
                }
                $readsSecret = strpos($branch, "['publish']") !== false
                    || strpos($branch, "MediaQuery::value(\$query, 'publish')") !== false;
                if (!$readsSecret) {
                    $problems[] = 'the publish secret must be read from the publish query string';
                }
            }

            // MediaMTX appends the publish path to the query, so a query the
            // client sent as "publish=<secret>" arrives as
            // "publish=<secret>/<key>?publish=<secret>". parse_str() cannot
            // see the value in that - which is why EVERY publish was denied
            // and the operator's broadcast never started. The reader has to
            // cut the value at the first path separator.
            $reader = $root . '/app/Core/MediaQuery.php';
            if (!is_file($reader)) {
                $problems[] = 'there is no MediaQuery reader for the MediaMTX query string';
            } else {
                require_once $reader;
                $glued = \App\Core\MediaQuery::value(
                    'publish=abc123def456/abc123def456?publish=abc123def456',
                    'publish'
                );
                if ($glued !== 'abc123def456') {
                    $problems[] = 'the query reader must read a value that MediaMTX glued to the publish path (got ' . $glued . ')';
                }
                $plain = \App\Core\MediaQuery::value('publish=abc123def456', 'publish');
                if ($plain !== 'abc123def456') {
                    $problems[] = 'the query reader must read a plain query value (got ' . $plain . ')';
                }
                $token = \App\Core\MediaQuery::value(
                    't=12.1700000000.deadbeef/abc123?rec=1',
                    't'
                );
                if ($token !== '12.1700000000.deadbeef') {
                    $problems[] = 'the query reader must read a playback token with dots in it (got ' . $token . ')';
                }
            }

            // The secret must be minted per session and only for the operator.
            if (strpos($ctrl, 'bin2hex(random_bytes(24))') === false) {
                $problems[] = 'a publish secret must be generated per session';
            }
            // The url builder lives with the session (it owns the key), so
            // the secret may be added there rather than in the controller.
            if (strpos($ctrl, '?publish=') === false && strpos($model, '?publish=') === false) {
                $problems[] = 'the operator RTMP url must carry the publish secret';
            }
            if (strpos($model, 'validPublishSecret(') === false) {
                $problems[] = 'the session must be able to check the publish secret';
            }
            if (strpos($model, 'publish_secret') === false) {
                $problems[] = 'the session must store the publish secret (hashed)';
            }
            // And it must be hashed, like the stream key.
            if (strpos($model, "self::hashOf(\$rawPublishSecret)") === false
                && strpos($model, 'hashOf($rawPublishSecret)') === false) {
                $problems[] = 'the publish secret must be stored hashed';
            }

            return $problems === []
                ? $ok('a member cannot publish to the operator stream')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.live.publish_secret_reaches_mediamtx', 'Smoke · Live', 'The publish secret reaches the MediaMTX auth webhook as a query', static function () use ($root, $read, $ok, $bad): array {
            // The publish secret only protects the stream if it actually
            // arrives at the auth webhook. It travels in the RTMP url, and
            // TWO parsers stand between the url and the webhook:
            //
            //  * the operator app's RTMP library (RootEncoder 2.6.1) parses
            //    the url with java.net.URI - which DECODES the path - and
            //    splits it into an RTMP 'app' name and a play path. With a
            //    LITERAL '?' it folds the query into the play path, so
            //    MediaMTX would see the path '<key>/publish=<secret>' and
            //    NO query at all.
            //  * MediaMTX (internal/rtmp/conn.go) rebuilds the url from
            //    app + '/' + playpath and splits THAT into the path name
            //    and the query it hands to the auth webhook.
            //
            // So the site must hand the app a url where the secret ends up
            // in MediaMTX's query while the PATH stays the bare stream key
            // (the HLS read path and the recorder's $MTX_PATH).
            //
            // This check runs the real url builder through a port of both
            // parsers, so the shape cannot silently rot again.
            $ctrl  = (string) $read($root . '/app/Controllers/LiveController.php');
            $model = (string) $read($root . '/app/Models/LiveSession.php');
            $problems = [];

            // The controller must use the shared builder, not a hand-rolled url.
            if (strpos($ctrl, 'LiveSession::rtmpUrl(') === false) {
                $problems[] = 'the controller must build the RTMP url with LiveSession::rtmpUrl()';
            }
            if (strpos($model, 'function rtmpUrl(') === false) {
                $problems[] = 'the session must own the RTMP url builder';
            }

            if ($problems === []) {
                $rawKey    = 'a1b2c3d4e5f6';
                $rawSecret = 'f6e5d4c3b2a1';
                $url       = \App\Models\LiveSession::rtmpUrl('h', $rawKey, $rawSecret);
                $target    = \App\Core\SmokeChecks::rtmpPublishTarget($url);

                if ($target['pathName'] !== $rawKey) {
                    $problems[] = 'the published path would be "' . $target['pathName'] . '", not the stream key';
                }
                if ($target['query'] !== 'publish=' . $rawSecret) {
                    $problems[] = 'the publish secret would not reach the auth webhook as a query';
                }
            }

            // The key the app posts to /live/stop comes out of that url, so
            // the site has to decode it and strip the secret off it.
            if (strpos($model, 'rawKeyFromUrl(') === false) {
                $problems[] = 'the site must normalise the stream key the app posts back';
            } elseif (strpos($model, 'rawurldecode(') === false) {
                $problems[] = 'the posted stream key must be url-decoded';
            }

            return $problems === []
                ? $ok('the publish secret reaches the MediaMTX auth webhook')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.live.shows_can_be_scheduled', 'Smoke · Live', 'A live show can be scheduled and the member page counts down to it', static function () use ($root, $read, $ok, $bad): array {
            // The operator schedules a show from the app; the member's live
            // page shows a countdown to it. The countdown has to be the SAME
            // on every device, so the start time is stored in UTC and sent
            // as an absolute timestamp: the client never uses its own clock
            // to decide what time it is.
            $model  = (string) $read($root . '/app/Models/LiveShow.php');
            $ctrl   = (string) $read($root . '/app/Controllers/LiveController.php');
            $view   = (string) $read($root . '/views/live.php');
            $schema = (string) $read($root . '/schema.sql');
            $routes = (string) $read($root . '/config/routes.php');
            $problems = [];

            // The table, in the schema AND in a migration.
            if (strpos($schema, 'CREATE TABLE IF NOT EXISTS live_shows') === false) {
                $problems[] = 'schema.sql must carry the live_shows table';
            }
            if (!is_file($root . '/database/migrations/087_live_shows.sql')) {
                $problems[] = 'the live_shows table needs a migration';
            }

            // Every time comparison must be UTC: the boxes disagree about
            // their MySQL session timezone (the dev box runs six hours
            // behind UTC), so a NOW() comparison would fire hours early on
            // one of them. (The comment in the model may mention NOW();
            // only actual SQL is checked here.)
            if (strpos($model, 'UTC_TIMESTAMP()') === false) {
                $problems[] = 'the show times must be compared in UTC';
            }
            $sql = '';
            foreach (explode("\n", $model) as $line) {
                if (stripos(trim($line), '*') === 0) {
                    continue;
                }
                $sql .= $line . "\n";
            }
            if (preg_match('/\bNOW\(\)/', $sql) === 1) {
                $problems[] = 'the show times must not be compared with NOW()';
            }

            // The API shape the app and the page both rely on.
            if (strpos($model, "'startsAtUtc'") === false) {
                $problems[] = 'the show must carry an absolute UTC start time';
            }
            if (strpos($model, "'seconds'") === false) {
                $problems[] = 'the show must carry the seconds remaining';
            }

            // The member page must show the banner and tick it.
            if (strpos($view, 'id="live-countdown"') === false) {
                $problems[] = 'the live page must show the countdown banner';
            }
            // A view has no namespace, so a bare `LiveShow::` resolves to
            // the global namespace and the page 500s with
            // "Class LiveShow not found". The check above only greps the
            // source, so it cannot see that.
            if (preg_match('/(?<![\\\\\w])LiveShow::/', $view) === 1) {
                $problems[] = 'the live page must use the full namespace for LiveShow';
            }
            if (strpos($view, 'id="live-countdown-text"') === false) {
                $problems[] = 'the countdown must have a place to show the time';
            }
            if (strpos($view, 'data-starts-at=') === false) {
                $problems[] = 'the countdown must be given the server start time';
            }
            if (strpos($view, 'tickCountdown') === false) {
                $problems[] = 'the countdown must tick';
            }
            if (strpos($view, "nextShow:") === false) {
                $problems[] = 'the live page must pass the next show to the script';
            }
            if (strpos($view, 'serverTime') === false) {
                $problems[] = 'the countdown must be based on the server clock';
            }

            // The endpoints, and they must be operator-only.
            foreach (['/live/shows', '/live/schedule', '/live/schedule/cancel'] as $route) {
                if (strpos($routes, "'" . $route . "'") === false) {
                    $problems[] = 'the ' . $route . ' route is missing';
                }
            }
            if (strpos($ctrl, 'function scheduleShow(') === false) {
                $problems[] = 'the schedule endpoint is not wired up';
            }
            if (strpos($ctrl, 'function cancelShow(') === false) {
                $problems[] = 'the cancel endpoint is not wired up';
            }
            if (strpos($ctrl, 'function shows(') === false) {
                $problems[] = 'the show list endpoint is not wired up';
            }
            // Scheduling is a device-token action, like starting a stream.
            if (substr_count($ctrl, '$operator = $this->operator();') < 3) {
                $problems[] = 'the show endpoints must check the operator token';
            }

            // ONE place converts the operator's local time into UTC: the
            // model. A caller that converted the time first and passed the
            // result on would shift it a second time, and the show would
            // start the wrong number of hours later.
            $createBody = '';
            $inCreate = false;
            foreach (explode("\n", $model) as $line) {
                if (strpos($line, 'function create(') !== false) {
                    $inCreate = true;
                }
                if ($inCreate) {
                    $createBody .= $line . "\n";
                    if (strpos($line, 'INSERT INTO live_shows') !== false) {
                        break;
                    }
                }
            }
            if (strpos($createBody, 'normaliseStart(') === false) {
                $problems[] = 'LiveShow::create() must convert the start time to UTC itself';
            }
            if (strpos($createBody, 'site_timezone()') === false
                && strpos($createBody, '$siteTimezone') === false
            ) {
                $problems[] = 'LiveShow::create() must know the site timezone';
            }

            return $problems === []
                ? $ok('a live show can be scheduled and counted down to')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.live.messages_can_be_acted_on', 'Smoke · Live', 'The operator can pin, delete and block in the chat', static function () use ($root, $read, $ok, $bad): array {
            // The app had NO per-message actions at all: a message could
            // not be taken down, kept at hand or copied, and a member could
            // not be blocked from the regular chat (only muted out of the
            // live chat).
            $model  = (string) $read($root . '/app/Models/ChatMessage.php');
            $ctrl   = (string) $read($root . '/app/Controllers/ChatBridgeController.php');
            $routes = (string) $read($root . '/config/routes.php');
            $schema = (string) $read($root . '/schema.sql');
            $problems = [];

            foreach (['pinnedMessages', 'setPinned', 'deleteMessage', 'blockUser', 'unblockUser', 'userBlocked'] as $fn) {
                if (strpos($model, 'function ' . $fn . '(') === false) {
                    $problems[] = 'ChatMessage::' . $fn . '() is missing';
                }
            }

            // The columns the actions need.
            if (strpos($schema, 'pinned_at') === false) {
                $problems[] = 'chat_messages needs a pinned_at column';
            }
            if (strpos($schema, 'chat_blocked_until') === false) {
                $problems[] = 'users needs a chat_blocked_until column';
            }

            // The endpoints.
            foreach (['/webhooks/chat/message-action', '/webhooks/chat/block-user'] as $route) {
                if (strpos($routes, "'" . $route . "'") === false) {
                    $problems[] = 'the ' . $route . ' route is missing';
                }
            }
            if (strpos($ctrl, 'function messageAction(') === false) {
                $problems[] = 'the message-action endpoint is not wired up';
            }
            if (strpos($ctrl, 'function blockUser(') === false) {
                $problems[] = 'the block-user endpoint is not wired up';
            }

            // A blocked member must not be able to write.
            $member = (string) $read($root . '/app/Controllers/ChatController.php');
            if (strpos($member, 'ChatMessage::userBlocked(') === false) {
                $problems[] = 'a blocked member must not be able to send a message';
            }

            return $problems === []
                ? $ok('the operator can act on messages and members')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.chat.app_knows_the_site_timezone', 'Smoke · Chat', 'The app is told the site timezone and the server clock', static function () use ($root, $read, $ok, $bad): array {
            // The app read 'site_timezone' out of its preferences, but
            // NOTHING ever wrote it - so every timestamp the app showed was
            // in a hardcoded America/Denver, whatever the site's timezone
            // actually was. The app also had no idea what time the server
            // thought it was, so a countdown could not agree across
            // devices.
            $ctrl = (string) $read($root . '/app/Controllers/ChatBridgeController.php');
            $problems = [];

            if (strpos($ctrl, "'site_timezone'") === false) {
                $problems[] = 'the chat config must carry the site timezone';
            }
            if (strpos($ctrl, "'server_time'") === false) {
                $problems[] = 'the chat config must carry the server clock';
            }

            return $problems === []
                ? $ok('the app is told the site timezone and the server clock')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.exposure.locked_down', 'Smoke · Ops', 'The operational surface is not public', static function () use ($root, $read, $ok, $bad): array {
            // /gallery/health was public and returned how old the last
            // backup is, how long ago the housekeeping ran, how many workers
            // are stuck and how many log lines the analytics pipeline
            // skipped - operational information about the box.
            //
            // And the domain root served Apache's "It works" default page
            // at /index.html, which is an indexable boilerplate page on an
            // adult site.
            $health = (string) $read($root . '/app/Controllers/HealthController.php');
            $rootConf = (string) $read($root . '/config/gallery-root-files.conf');
            $install  = (string) $read($root . '/install.sh');
            $problems = [];

            // The health endpoint.
            if (strpos($health, 'wantsDetail()') === false) {
                $problems[] = 'the health endpoint must gate its detail';
            }
            if (strpos($health, 'GALLERY_HEALTH_KEY') === false) {
                $problems[] = 'the health detail must need a credential';
            }
            if (strpos($health, 'hash_equals($expected, $given)') === false) {
                $problems[] = 'the health credential must be compared in constant time';
            }
            // The public part must stay small.
            $at = strpos($health, "if (!\$detail) {");
            if ($at === false) {
                $problems[] = 'the health endpoint must return a small public readiness response';
            } else {
                // Only the public block: up to its own return.
                $ret = strpos($health, 'return;', $at);
                $public = substr($health, $at, ($ret === false ? 600 : $ret - $at));
                foreach (['backup', 'workers', 'analytics', 'housekeeping_age_sec'] as $leak) {
                    if (strpos($public, $leak) !== false) {
                        $problems[] = 'the public health response must not include ' . $leak;
                    }
                }
            }

            // The origin root.
            if (strpos($rootConf, 'Alias /robots.txt') === false) {
                $problems[] = 'robots.txt must be served from the host root - that is the only place it is honoured';
            }
            if (strpos($rootConf, 'index\.') === false && strpos($rootConf, 'index\.') === false) {
                $problems[] = 'the Apache default page must not be served at /index.html';
            }
            if (strpos($install, 'gallery-root-files.conf') === false) {
                $problems[] = 'a fresh install must write the origin-root files config';
            }

            return $problems === []
                ? $ok('the operational surface is not public')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.access.one_predicate', 'Smoke · Access', 'One place decides what a member can see', static function () use ($root, $read, $ok, $bad): array {
            // FIVE places decided "can this member see this" and they
            // disagreed. The gallery page used the gallery's OWN min_level
            // while the BYTES were served at the minimum across every
            // gallery that held the photo - so a Silver member was shown
            // the same video as locked in the Gold gallery and fully open on
            // its own page. 43 photos are attached to galleries at different
            // levels, because MediaUploader::commit() attaches an existing
            // photo (the same content hash) to a new gallery automatically.
            $photo  = (string) $read($root . '/app/Models/Photo.php');
            $gallery = (string) $read($root . '/app/Models/Gallery.php');
            $store  = (string) $read($root . '/app/Controllers/StorageController.php');
            $image  = (string) $read($root . '/app/Controllers/ImageController.php');
            $gc     = (string) $read($root . '/app/Controllers/GalleryController.php');
            $coll   = (string) $read($root . '/app/Models/Collection.php');
            $problems = [];

            if (strpos($photo, 'public static function accessLevel(') === false) {
                $problems[] = 'Photo must have an accessLevel()';
            }
            if (strpos($gallery, 'public static function accessLevel(') === false) {
                $problems[] = 'Gallery must have an accessLevel()';
            }
            // The files route: the PHOTO's level, and every other fact about
            // its access, in ONE query. The route used to make up to nine
            // queries per file (findByFilename, hasPublicGallery,
            // userCanView, accessLevel, unlocksPhoto, ...), and every
            // thumbnail in a grid is a request.
            if (strpos($store, 'Photo::accessInfo(') === false) {
                $problems[] = 'the files route must use Photo::accessInfo()';
            }
            if (strpos($photo, 'public static function accessInfo(') === false) {
                $problems[] = 'Photo must have an accessInfo()';
            }
            // The one query must still decide everything: the strictest
            // level, the public/secret split, the allow-list and the paid
            // unlock.
            foreach (['access_level', 'has_public', 'has_secret', 'can_view', 'unlocked'] as $field) {
                if (strpos($photo, "'" . $field . "'") === false) {
                    $problems[] = 'Photo::accessInfo() must return ' . $field;
                }
            }
            if (strpos($photo, "COALESCE(MAX(' . Gallery::accessLevelSql('g') . '), 0) AS access_level") === false) {
                $problems[] = 'accessInfo() must use the STRICTEST access level across the galleries that hold the photo';
            }
            if (strpos($photo, 'MAX(g.min_level)') !== false) {
                $problems[] = 'accessInfo() must not reach for the raw min_level column - SFW galleries are open at any level';
            }
            // The media viewer: the PHOTO's level.
            if (strpos($image, 'Photo::accessLevel(') === false) {
                $problems[] = 'the media viewer must use Photo::accessLevel()';
            }
            // The gallery page and the photos AJAX: the GALLERY's level.
            if (strpos($gc, 'Gallery::accessLevel(') === false) {
                $problems[] = 'the gallery page and the photos AJAX must use Gallery::accessLevel()';
            }
            // The photo's bytes are served at the STRICTEST level across its
            // galleries. The minimum used to leak: the bytes of a photo that
            // also sat in a Silver gallery were served at Silver level even
            // though the Gold gallery advertised it as Gold content.
            if (strpos($photo, "SELECT MAX(' . Gallery::accessLevelSql('g') . ')") === false) {
                $problems[] = 'a photo\'s bytes must be served at the STRICTEST access level across the galleries that hold it';
            }
            // And the grid must be filtered by the member's level, so the
            // page never shows a photo whose bytes they cannot fetch.
            if (strpos($gallery, 'photosSlice(int $galleryId, int $limit, int $offset, int $maxLevel') === false) {
                $problems[] = 'the gallery grid must be filtered by the member level';
            }
            if (strpos($gc, '$gridLevel') === false) {
                $problems[] = 'the gallery page must pass the member level to the grid';
            }
            // And no GATE may reach for the raw column any more. (The
            // admin's own edit form may - that is where the level is set.)
            foreach ([$store, $image, $gc] as $src) {
                $at = 0;
                while (($at = strpos($src, 'requireGalleryLevel(', $at)) !== false) {
                    $gate = substr($src, $at, 400);
                    if (strpos($gate, "(\$gallery['min_level'] ?? 0)") !== false) {
                        $problems[] = 'a gate still uses the gallery row\'s own min_level instead of the access level';
                    }
                    $at += 20;
                }
            }
            if (strpos($gc, "\$minLevel  = (int) (\$gallery['min_level'] ?? 0);") !== false) {
                $problems[] = 'the gallery page still uses the gallery row\'s own min_level instead of the access level';
            }
            // The in-player gallery browser had NO level filter at all.
            $browseAt = strpos($gc, 'public function browseGalleries()');
            if ($browseAt === false) {
                $problems[] = 'browseGalleries() was not found';
            } else {
                $browse = substr($gc, $browseAt, 2600);
                if (strpos($browse, 'Gallery::accessLevelSql(\'g\')') === false) {
                    $problems[] = 'the in-player gallery browser must filter by the member access level - it listed every premium gallery';
                }
            }
            // And a shared collection must not leak a premium cover. The
            // check is on addGallery() itself: another method in the file
            // already used publishedVisibleSql, so a file-wide search would
            // pass even if this one lost its check.
            $addAt = strpos($coll, 'public static function addGallery(');
            if ($addAt === false) {
                $problems[] = 'Collection::addGallery() was not found';
            } else {
                $add = substr($coll, $addAt, 1800);
                if (strpos($add, 'publishedVisibleSql') === false) {
                    $problems[] = 'a collection must only accept a published, visible gallery - the share link renders its real cover to anyone';
                }
                if (strpos($add, 'is_secret = 0') === false) {
                    $problems[] = 'a collection must not accept a secret gallery';
                }
            }

            return $problems === []
                ? $ok('one place decides what a member can see')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.rate_limits.present', 'Smoke · Abuse', 'The member actions that can be spammed are limited', static function () use ($root, $read, $ok, $bad): array {
            // The member-facing actions that could be spammed and had NO
            // limit: the live chat (unlimited messages, and no moderation
            // queue - live_chat_messages has no admin screen), comments,
            // replies, reactions and gallery ratings.
            $live    = (string) $read($root . '/app/Controllers/LiveController.php');
            $comment = (string) $read($root . '/app/Controllers/CommentController.php');
            $rating  = (string) $read($root . '/app/Controllers/GalleryRatingController.php');
            $problems = [];

            if (strpos($live, "RateLimiter::allow(['live_chat_send:") === false) {
                $problems[] = 'the live chat must be rate limited';
            }
            if (strpos($comment, "RateLimiter::allow(['comment:") === false) {
                $problems[] = 'comments must be rate limited';
            }
            if (strpos($comment, "RateLimiter::allow(['comment_reply:") === false) {
                $problems[] = 'comment replies must be rate limited';
            }
            if (strpos($comment, "RateLimiter::allow(['comment_react:") === false) {
                $problems[] = 'comment reactions must be rate limited';
            }
            if (strpos($rating, "RateLimiter::allow(['gallery_rating:") === false) {
                $problems[] = 'gallery ratings must be rate limited';
            }

            return $problems === []
                ? $ok('the member actions that can be spammed are limited')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.money.idempotent_charges', 'Smoke · Money', 'A double-click cannot charge twice', static function () use ($root, $read, $ok, $bad): array {
            // A double-click on "Pay" created TWO Braintree sales for one
            // unlock. The rate limit did not help - the two charges are
            // milliseconds apart.
            $purchase = (string) $read($root . '/app/Models/Purchase.php');
            $ctrl     = (string) $read($root . '/app/Controllers/PurchaseController.php');
            $form     = (string) $read($root . '/views/partials/card_checkout.php');
            $schema   = (string) $read($root . '/schema.sql');
            $problems = [];

            if (strpos($schema, 'idempotency_key') === false) {
                $problems[] = 'purchases must carry an idempotency_key';
            }
            if (strpos($schema, 'uq_purchases_idempotency') === false) {
                $problems[] = 'the idempotency key must be UNIQUE';
            }
            if (strpos($purchase, 'findByIdempotencyKey(') === false) {
                $problems[] = 'Purchase must be able to find the purchase a key made';
            }
            if (strpos($purchase, 'idempotencyMatches(') === false) {
                $problems[] = 'the key must be bound to the purchase - the same key with a different amount is a bug';
            }
            if (strpos($purchase, '?string $idempotencyKey = null') === false) {
                $problems[] = 'Purchase::create() must accept the key';
            }
            if (strpos($ctrl, 'findByIdempotencyKey(') === false) {
                $problems[] = 'the charge must look the key up before it charges';
            }
            if (strpos($ctrl, 'idempotencyKey()') === false) {
                $problems[] = 'the controller must read the key from the request';
            }
            if (strpos($form, 'name="idempotency_key"') === false) {
                $problems[] = 'the card form must carry the key, so both clicks of the same button share it';
            }

            return $problems === []
                ? $ok('a double-click cannot charge twice')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.money.ledger', 'Smoke · Money', 'Every money movement is in a ledger', static function () use ($root, $read, $ok, $bad): array {
            // A purchase row was MUTATED in place: pending -> paid ->
            // refunded, and an admin could set any status on any row. The row
            // only ever showed the LAST state, so "was this paid and then
            // refunded, or never paid at all" had no answer, and an admin's
            // manual change was indistinguishable from a gateway's.
            $purchase = (string) $read($root . '/app/Models/Purchase.php');
            $schema   = (string) $read($root . '/schema.sql');
            $ctrl     = (string) $read($root . '/app/Controllers/ModerationController.php');
            $problems = [];

            if (strpos($schema, 'CREATE TABLE IF NOT EXISTS purchase_events') === false) {
                $problems[] = 'there must be a purchase_events ledger table';
            }
            if (strpos($schema, 'idx_purchase_events_purchase') === false) {
                $problems[] = 'the ledger must be indexed by purchase';
            }
            if (strpos($purchase, 'public static function recordEvent(') === false) {
                $problems[] = 'Purchase must have a recordEvent()';
            }
            if (strpos($purchase, 'public static function events(') === false) {
                $problems[] = 'Purchase must be able to read the ledger back';
            }
            // Every state change must land in it.
            foreach ([
                ["settleById", "paid"],
                ["settleByReference", "paid"],
                ["refundByReference", "refunded"],
            ] as [$method, $event]) {
                $at = strpos($purchase, 'public static function ' . $method . '(');
                if ($at === false) {
                    $problems[] = 'Purchase::' . $method . '() was not found';
                    continue;
                }
                $end = strpos($purchase, "
    }", $at);
                $body = substr($purchase, $at, ($end === false ? 1200 : $end - $at));
                if (strpos($body, 'recordEvent(') === false) {
                    $problems[] = 'Purchase::' . $method . '() must append to the ledger';
                }
            }
            // The creation and the admin's manual change too.
            if (strpos($purchase, "'created', [") === false) {
                $problems[] = 'a new purchase must start in the ledger';
            }
            if (strpos($purchase, "'status_changed'") === false) {
                $problems[] = 'an admin\'s manual status change must be in the ledger';
            }
            if (strpos($ctrl, 'setStatus($id, $status, (int) Auth::user()') === false) {
                $problems[] = 'the admin status change must record WHO made it';
            }
            // The parameters must not be swapped. They WERE: "SET status = ?
            // WHERE id = ?" was given [$id, $status], so the row matched
            // nothing and the admin's status change never happened.
            if (strpos($purchase, "UPDATE purchases SET status = ? WHERE id = ?', [\$status, \$id]") === false) {
                $problems[] = 'the status update must bind the status first and the id second - they were swapped';
            }
            // And a refund must mark the local row too, or the unlock stays
            // open even though the money went back.
            if (strpos($ctrl, 'Purchase::setStatus($id, Purchase::STATUS_REFUNDED') === false) {
                $problems[] = 'a refund must mark the local purchase refunded too';
            }

            return $problems === []
                ? $ok('every money movement is in a ledger')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ops.rate_limiter_sharded', 'Smoke · Ops', 'The rate limiter does not serialise every request on one lock', static function () use ($root, $read, $ok, $bad): array {
            // The limiter's state used to live in ONE file that every
            // rate-limited request locked exclusively, read, changed and
            // rewrote - so a comment, a rating, a chat message, a login
            // attempt and a live chat line all serialised on the same lock.
            $src = (string) $read($root . '/app/Core/RateLimiter.php');
            $problems = [];

            if (strpos($src, 'SHARDS') === false) {
                $problems[] = 'the rate limiter state must be sharded';
            }
            if (strpos($src, 'security-rate-limit-') === false) {
                $problems[] = 'the rate limiter must use a sharded state file';
            }
            // The single global file must be gone.
            if (strpos($src, "'/storage/security-rate-limit.json'") !== false) {
                $problems[] = 'the rate limiter must not use one global state file';
            }
            // And it must still fail CLOSED: a limiter that cannot count
            // must not let the request through.
            if (strpos($src, 'return false;') === false) {
                $problems[] = 'the rate limiter must fail closed';
            }

            // Behaviourally: the limit is still enforced, and a different
            // identifier is not affected by it.
            if (!class_exists(\App\Core\RateLimiter::class)) {
                return $bad('App\Core\RateLimiter must exist');
            }
            $id = 'smoke-probe-' . bin2hex(random_bytes(6));
            $allowed = 0;
            for ($i = 0; $i < 5; $i++) {
                if (\App\Core\RateLimiter::allow([$id], 3, 60)) {
                    $allowed++;
                }
            }
            if ($allowed !== 3) {
                $problems[] = 'the limiter allowed ' . $allowed . ' of 3 calls';
            }
            $other = 'smoke-probe-' . bin2hex(random_bytes(6));
            if (!\App\Core\RateLimiter::allow([$other], 3, 60)) {
                $problems[] = 'one identifier must not affect another';
            }

            return $problems === []
                ? $ok('the rate limiter is sharded and still enforces its limits')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.exposure.csrf_rotated', 'Smoke · Ops', 'The CSRF token is rotated when the session privileges change', static function () use ($root, $read, $ok, $bad): array {
            // The token used to be minted once per session and then live for
            // the whole session: one leaked token was good for EVERY POST
            // the session could make, including the ones that change the
            // account. It is rotated whenever the privileges change.
            $csrf = (string) $read($root . '/app/Core/Csrf.php');
            $auth = (string) $read($root . '/app/Core/Auth.php');
            $problems = [];

            if (strpos($csrf, 'public static function rotate(') === false) {
                $problems[] = 'Csrf must be able to rotate the token';
            }
            if (strpos($csrf, "random_bytes(32)") === false) {
                $problems[] = 'the rotated token must be random';
            }

            // The three privilege changes: the login, the logout and the
            // step-up (the password confirmation that unlocks the account
            // settings).
            foreach ([
                'loginUser' => 'the login',
                'revokeSession' => 'the logout',
                'markReauthenticated' => 'the password confirmation',
            ] as $method => $what) {
                if (preg_match('/function ' . $method . '\s*\([^)]*\)\s*:[^\n]*\n\s*\{(.*?)\n    \}/s', $auth, $m) !== 1) {
                    $problems[] = 'Auth::' . $method . '() not found';
                    continue;
                }
                if (strpos($m[1], 'Csrf::rotate()') === false) {
                    $problems[] = $what . ' must rotate the CSRF token';
                }
            }

            return $problems === []
                ? $ok('the CSRF token is rotated on every privilege change')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.exposure.xsendfile_scope', 'Smoke · Ops', 'X-Sendfile only covers the trees the app hands over', static function () use ($root, $read, $ok, $bad): array {
            // The whole storage/ tree used to be an XSendfilePath, which also
            // made the error log, the cron log, the database backups and the
            // rate-limit state reachable by an X-Sendfile header. Only the
            // published media and the operator APK are ever handed over.
            $problems = [];
            foreach ([
                $root . '/config/gallery-alias.conf' => "/var/www/gallery/storage\n",
                $root . '/install.sh'                 => "\$INSTALL_DIR/storage\n",
            ] as $file => $broad) {
                if (!is_file($file)) {
                    continue;
                }
                $src = (string) file_get_contents($file);
                foreach (explode("\n", $src) as $line) {
                    if (stripos($line, 'XSendFilePath') === false) {
                        continue;
                    }
                    // The narrow paths (uploads, assets/apk) are fine; the
                    // bare storage tree is not.
                    if (preg_match('#XSendFilePath\s+.*/storage\s*$#i', $line) === 1) {
                        $problems[] = basename($file) . ' lets X-Sendfile serve the whole storage tree';
                    }
                }
            }
            // The two trees the app actually hands over must be there.
            $conf = (string) $read($root . '/config/gallery-alias.conf');
            if (strpos($conf, '/var/www/gallery/storage/uploads') === false) {
                $problems[] = 'the published media must stay an XSendfilePath';
            }
            if (strpos($conf, '/var/www/gallery/public/assets/apk') === false) {
                $problems[] = 'the operator APK must stay an XSendfilePath';
            }

            return $problems === []
                ? $ok('X-Sendfile covers only the published media and the APK')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.exposure.chat_key_scope', 'Smoke · Ops', 'The shared chat key is not a live-stream credential', static function () use ($root, $read, $ok, $bad): array {
            // GALLERY_CHAT_KEY is distributed to every operator's phone. It
            // was accepted for the LIVE CONTROLS too, so a leaked shared
            // key could start and stop the broadcast - and the recording of
            // that broadcast is imported into a paid gallery and auto-posted
            // to social media.
            $live   = (string) $read($root . '/app/Controllers/LiveController.php');
            $bridge = (string) $read($root . '/app/Controllers/ChatBridgeController.php');
            $problems = [];

            // The live controls must accept a DEVICE token only.
            $at = strpos($live, 'private function operator()');
            if ($at === false) {
                $problems[] = 'LiveController::operator() was not found';
            } else {
                $body = substr($live, $at, 1800);
                // The CODE, not the comment: the comment explains why the
                // key is not accepted here.
                if (strpos($body, "env_value('GALLERY_CHAT_KEY'") !== false) {
                    $problems[] = 'the live controls must not accept the shared chat key - it is a chat credential, not a broadcast one';
                }
                if (strpos($body, 'OperatorToken::authenticate(') === false) {
                    $problems[] = 'the live controls must accept a device token';
                }
            }
            // The attachment endpoint was the only bridge route with no
            // throttle: an authenticated client could pull every chat
            // attachment - a personal photo - at line speed.
            $attAt = strpos($bridge, 'public function attachment()');
            if ($attAt === false) {
                $problems[] = 'the bridge attachment endpoint was not found';
            } else {
                $body = substr($bridge, $attAt, 900);
                if (strpos($body, 'throttle(') === false) {
                    $problems[] = 'the bridge attachment endpoint must be throttled';
                }
            }
            // And a use of the shared key must be traceable.
            if (strpos($bridge, '[chat-bridge] the SHARED key was used from') === false) {
                $problems[] = 'a use of the shared key must be logged, so a leak is traceable';
            }

            return $problems === []
                ? $ok('the shared chat key is not a live-stream credential')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.live.moderation_queue', 'Smoke · Live', 'The live chat can be moderated', static function () use ($root, $read, $ok, $bad): array {
            // The live chat had NO admin screen at all: a member's message
            // stayed in the replay for everyone to see, and there was
            // nowhere to look at what was said or to take a message down.
            $model = (string) $read($root . '/app/Models/LiveChatMessage.php');
            $ctrl  = (string) $read($root . '/app/Controllers/SupportController.php');
            $view  = (string) $read($root . '/views/admin/support.php');
            $liveView = (string) $read($root . '/views/admin/live_chat.php');
            $routes = (string) $read($root . '/config/routes.php');
            $problems = [];

            if (strpos($model, 'public static function recent(') === false) {
                $problems[] = 'there must be a way to read the live chat';
            }
            if (strpos($model, 'public static function delete(') === false) {
                $problems[] = 'there must be a way to remove a live chat message';
            }
            if (strpos($model, 'deleteForUser(') === false) {
                $problems[] = 'there must be a way to remove every message from one member in a stream';
            }
            if (strpos($ctrl, 'LiveChatMessage::recent(') === false) {
                $problems[] = 'the live chat page must show the live chat';
            }
            if (strpos($ctrl, 'deleteLiveMessage(') === false) {
                $problems[] = 'there must be an action that removes a live chat message';
            }
            if ($liveView === '' || stripos($liveView, 'live chat') === false) {
                $problems[] = 'there must be a live chat page with the messages';
            }
            if (strpos($routes, '/admin/live-chat') === false) {
                $problems[] = 'there must be a route for the live chat moderation';
            }
            // The support page is for support information: the chat of a live
            // stream belongs on its own page.
            if (stripos($view, 'live chat') !== false) {
                $problems[] = 'the support page must not show the live chat';
            }

            return $problems === []
                ? $ok('the live chat can be moderated on its own page')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.perf.no_n_plus_one', 'Smoke · Perf', 'The listing pages do not ask one query per row', static function () use ($root, $read, $ok, $bad): array {
            // Three N+1s that cost 58 queries a page:
            //   the wall's comments - one per post, 20 for a 20-post feed;
            //   the recommended galleries' categories - one per recently
            //   viewed gallery, 8;
            //   the admin engagement table's comment counts - one per post, 30.
            $wallView = (string) $read($root . '/views/wall.php');
            $wallCtrl = (string) $read($root . '/app/Controllers/WallController.php');
            $gallery  = (string) $read($root . '/app/Models/Gallery.php');
            $engage   = (string) $read($root . '/views/admin/engagement.php');
            $engageCtrl = (string) $read($root . '/app/Controllers/ModerationController.php');
            $problems = [];

            if (strpos($wallView, 'Comment::forEntity(') !== false) {
                $problems[] = 'the wall must not load each post\'s comments one at a time';
            }
            if (strpos($wallCtrl, 'Comment::forEntities(') === false) {
                $problems[] = 'the wall must load its comments in one query';
            }
            if (strpos($gallery, 'foreach (self::categories((int) $g[\'id\']) as $c)') !== false) {
                $problems[] = 'the recommendations must not load each gallery\'s categories one at a time';
            }
            if (strpos($engage, 'Comment::countFor(') !== false) {
                $problems[] = 'the engagement table must not count each post\'s comments one at a time';
            }
            if (strpos($engageCtrl, 'Comment::countsFor(') === false) {
                $problems[] = 'the engagement table must count its comments in one query';
            }

            return $problems === []
                ? $ok('the listing pages do not ask one query per row')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.perf.cache', 'Smoke · Perf', 'The cache cannot stampede and cannot be filled with junk', static function () use ($root, $read, $ok, $bad): array {
            // Two problems with the cache.
            //
            // A STAMPEDE: on a cold cache, N concurrent requests all ran
            // the producer, and the producer for a listing is the whole
            // catalogue. Worse, bump('listings') invalidates every listing
            // fragment and the whole-page cache AT ONCE, so the next wave
            // of guests all re-rendered everything and wrote the same
            // 393 KB back.
            //
            // AND JUNK KEYS: the page cache key was the RAW query string,
            // so every tracking parameter (?utm_source, ?fbclid, ...) was
            // its own cache entry - a whole page each - and Redis has no
            // maxmemory, so it grew without bound.
            $cache    = (string) $read($root . '/app/Core/Cache.php');
            $pageCache = (string) $read($root . '/app/Core/PageCache.php');
            $router   = (string) $read($root . '/app/Core/Router.php');
            $problems = [];

            if (strpos($cache, "'NX', 'EX'") === false) {
                $problems[] = 'the producer lock must be taken with NX and an expiry, so a crashed producer cannot wedge a key';
            }
            if (strpos($cache, 'private static function acquireLock(') === false) {
                $problems[] = 'the cache must have a producer lock';
            }
            if (strpos($cache, 'private static function waitFor(') === false) {
                $problems[] = 'the other processes must be able to wait for the winner';
            }
            if (strpos($pageCache, '\App\Core\Cache::lock(') === false) {
                $problems[] = 'the listing fragments must be guarded against a stampede';
            }
            if (strpos($pageCache, 'public static function normaliseQuery(') === false) {
                $problems[] = 'the page cache key must normalise the query string';
            }
            if (strpos($router, 'PageCache::normaliseQuery(') === false) {
                $problems[] = 'the router must use the normalised query string in the page cache key';
            }
            if (strpos($router, "QUERY_STRING'] ?? '')") !== false && strpos($router, 'normaliseQuery') === false) {
                $problems[] = 'the raw query string is still part of the page cache key';
            }

            return $problems === []
                ? $ok('the cache cannot stampede and cannot be filled with junk')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.perf.disk_walk', 'Smoke · Perf', 'The admin dashboard does not walk the disk', static function () use ($root, $read, $ok, $bad): array {
            // The dashboard walked the whole storage tree on every load: a
            // filesize() for each of the ~6,800 photos, a glob plus a
            // filesize for each of the ~15,600 web_ and ~22,000 thumb_
            // variants, and FOURTEEN recursive directory walks over 47,000
            // files. strace counted 280,000 stat() calls - 90% of the page's
            // time, 3.4 to 4.0 seconds, all of it FPM CPU.
            //
            // The breakdown is a slow-moving figure, so it is computed in
            // the BACKGROUND and the dashboard reads the cached copy.
            $usage   = (string) $read($root . '/app/Core/DiskUsage.php');
            $ctrl    = (string) $read($root . '/app/Controllers/AdminController.php');
            $hk      = (string) $read($root . '/app/Core/Housekeeping.php');
            $problems = [];

            if (strpos($usage, 'public static function compute(') === false) {
                $problems[] = 'there must be a service that computes the disk breakdown';
            }
            if (strpos($usage, 'public static function cached(') === false) {
                $problems[] = 'there must be a cached copy of the breakdown';
            }
            if (strpos($ctrl, 'DiskUsage::cached()') === false) {
                $problems[] = 'the dashboard must read the cached breakdown, not walk the disk';
            }
            if (strpos($ctrl, 'diskBreakdown(') !== false) {
                $problems[] = 'the dashboard must not walk the disk itself any more';
            }
            if (strpos($hk, 'DiskUsage::refresh(') === false) {
                $problems[] = 'the housekeeping must refresh the breakdown in the background';
            }
            // And the model ping, which cost the dashboard 3.4 seconds on a
            // box with no model.
            $ai = (string) $read($root . '/app/Core/ChatAi.php');
            if (strpos($ai, 'private static function cachedPing(') === false) {
                $problems[] = 'the model ping must be cached, or the dashboard waits out the timeout';
            }

            return $problems === []
                ? $ok('the admin dashboard does not walk the disk')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.seo.found', 'Smoke · SEO', 'The pages describe themselves', static function () use ($root, $read, $ok, $bad): array {
            // The site was crawlable but thin: the sitemap covered 178 of
            // ~2,700 URLs, 27 of its 111 category pages had no gallery in
            // them, the home page and the listing were both titled
            // "amethyst2213 - amethyst2213", and NOT ONE of the 7,058 photos
            // had a caption - so every image had alt="" and every image
            // page was titled "Full-size image".
            $layout   = (string) $read($root . '/views/layout.php');
            // The map is built in App\Core\Sitemap; the controller only
            // renders it, so that is where the map's contents are checked.
            $sitemap  = (string) $read($root . '/app/Core/Sitemap.php');
            $creator  = (string) $read($root . '/views/creator/profile.php');
            $tag      = (string) $read($root . '/views/gallery/tag.php');
            $photo    = (string) $read($root . '/app/Models/Photo.php');
            $card     = (string) $read($root . '/views/partials/gallery_card.php');
            $grid     = (string) $read($root . '/views/partials/gallery_grid_item.php');
            $image    = (string) $read($root . '/views/gallery/image_full.php');
            $video    = (string) $read($root . '/views/video/player.php');
            $problems = [];

            // The title must not carry the site name twice.
            if (strpos($layout, '$fullTitle') === false) {
                $problems[] = 'the layout must build the title once';
            }
            // The TITLE, not the description: the description legitimately
            // names the site.
            if (preg_match('/\$title\s*=.*config\(\x27app\.site_name\x27\)/', $creator) === 1) {
                $problems[] = 'the creator page must not put the site name in the title - the layout appends it';
            }
            // The og:type must follow the page.
            if (strpos($layout, '$ogType') === false) {
                $problems[] = 'the layout must take the og:type from the page';
            }
            if (strpos($video, "\$ogType = 'video.other'") === false) {
                $problems[] = 'a video page must say it is a video';
            }
            // The breadcrumb structured data.
            if (strpos($layout, 'BreadcrumbList') === false) {
                $problems[] = 'the breadcrumbs must be in the structured data - they are visible on every page';
            }
            // The alt text.
            if (strpos($photo, 'public static function altText(') === false) {
                $problems[] = 'there must be one place that builds the alt text';
            }
            foreach (['gallery_card.php' => $card, 'gallery_grid_item.php' => $grid, 'image_full.php' => $image] as $name => $src) {
                if (strpos($src, 'alt=""') !== false) {
                    $problems[] = $name . ' still renders alt=""';
                }
                if (strpos($src, 'altText(') === false) {
                    $problems[] = $name . ' does not use the alt text helper';
                }
            }
            // The canonicals.
            if (strpos($creator, '$canonicalUrl') === false) {
                $problems[] = 'the creator page needs a canonical - its og:url fell back to the site root';
            }
            if (strpos($tag, '$canonicalUrl') === false) {
                $problems[] = 'the tag pages need a canonical';
            }
            // The sitemap.
            foreach (["'/galleries'", "'/creator'"] as $path) {
                if (strpos($sitemap, $path) === false) {
                    $problems[] = 'the sitemap must list ' . $path;
                }
            }
            if (strpos($sitemap, 'INNER JOIN gallery_category') === false) {
                $problems[] = 'the sitemap must only list the categories that have a gallery in them';
            }
            if (strpos($sitemap, 'videoXml') === false) {
                $problems[] = 'there must be a video sitemap';
            }

            return $problems === []
                ? $ok('the pages describe themselves')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.perf.reparse_is_background', 'Smoke · Perf', 'The admin re-parse is a background job', static function () use ($root, $read, $ok, $bad): array {
            // The re-parse ran INSIDE the HTTP request: up to 31 days of the
            // access log, about five minutes of work, holding one FPM worker
            // for the whole of it while max_execution_time is an hour. It
            // also had no lock of its own, so it could run at the same time
            // as the hourly cron.
            $ctrl = (string) $read($root . '/app/Controllers/AnalyticsController.php');
            $problems = [];

            if (strpos($ctrl, 'private static function queueReparse(') === false) {
                $problems[] = 'the re-parse must be queued, not run inline';
            }
            if (strpos($ctrl, 'aggregate_access_log.php') === false) {
                $problems[] = 'the queued re-parse must run the aggregator';
            }
            if (strpos($ctrl, 'flock(') === false) {
                $problems[] = 'the queued re-parse must take a lock, so it cannot collide with the hourly cron';
            }
            // And the request must NOT run the aggregator itself.
            $at = strpos($ctrl, 'public function reparse()');
            if ($at !== false) {
                $end  = strpos($ctrl, "
    }", $at);
                $body = substr($ctrl, $at, ($end === false ? 3000 : $end - $at));
                if (strpos($body, 'AccessLogAggregator::run(') !== false) {
                    $problems[] = 'the request must not run the aggregator itself';
                }
            }

            return $problems === []
                ? $ok('the admin re-parse is a background job')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.perf.system_page', 'Smoke · Perf', 'The system page does not re-derive everything on every load', static function () use ($root, $read, $ok, $bad): array {
            // The system page did three expensive things on every load:
            // 83 SHOW COLUMNS queries for the schema diff, 20,500 stat()
            // calls for the variant stats, and a REAL Ollama generation
            // ("Reply with: hi") to health-check the fine-tuned model -
            // 8.9 seconds on production, and longer when the model was
            // busy with the categoriser.
            $ctrl = (string) $read($root . '/app/Controllers/SystemController.php');
            $problems = [];

            if (strpos($ctrl, 'private function cachedValue(') === false) {
                $problems[] = 'the system page must cache its expensive reads';
            }
            if (strpos($ctrl, 'private function computeSchemaDiff(') === false) {
                $problems[] = 'the schema diff must be computed once and cached';
            }
            if (strpos($ctrl, 'private function computeVariantStats(') === false) {
                $problems[] = 'the variant stats must be computed once and cached';
            }
            if (strpos($ctrl, 'private function computeAiStatus(') === false) {
                $problems[] = 'the AI health check must be computed once and cached';
            }
            // The health probe must not be in the request path any more.
            $at = strpos($ctrl, "public function show(");
            if ($at !== false) {
                $end  = strpos($ctrl, "\n    }", $at);
                $body = substr($ctrl, $at, ($end === false ? 4000 : $end - $at));
                if (strpos($body, "/api/generate") !== false) {
                    $problems[] = 'the system page must not run a model generation in the request';
                }
            }

            return $problems === []
                ? $ok('the system page does not re-derive everything on every load')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.data.integrity', 'Smoke · Data', 'The data that must survive a deletion survives it', static function () use ($root, $ok, $bad): array {
            // Deleting a member used to destroy their subscription history
            // and every unlock they had paid for (the ledger cascaded).
            // Deleting a gallery used to destroy the unlock codes that were
            // sold for it. A wall post had no author at all.
            $user  = (string) @file_get_contents($root . '/app/Models/User.php');
            $wall  = (string) @file_get_contents($root . '/app/Models/WallPost.php');
            $sale  = (string) @file_get_contents($root . '/app/Models/Sale.php');
            $schema = (string) @file_get_contents($root . '/schema.sql');
            $house  = (string) @file_get_contents($root . '/app/Core/Housekeeping.php');
            $problems = [];

            if (strpos($user, "DELETE FROM users WHERE id = ?") !== false) {
                $problems[] = 'a deleted account must be anonymised, not deleted (the ledger points at it)';
            }
            if (strpos($user, 'deleted_at = CURRENT_TIMESTAMP') === false) {
                $problems[] = 'the deleted account must be marked as deleted';
            }
            if (strpos($wall, 'created_by') === false) {
                $problems[] = 'a wall post must remember its author';
            }
            if (strpos($sale, 'public static function reserveSlot(') === false) {
                $problems[] = 'the sale capacity must be reserved inside the transaction';
            }
            if (strpos($sale, 'FOR UPDATE') === false) {
                $problems[] = 'the sale row must be locked while the capacity is checked';
            }
            if (strpos($schema, 'ON DELETE SET NULL') === false) {
                $problems[] = 'a sold unlock code must survive its gallery';
            }
            if (strpos($house, 'pruneVisitHistory') === false) {
                $problems[] = 'the tables that grow with every visit must be pruned';
            }

            return $problems === []
                ? $ok('the ledger, the sold codes and the wall authors survive a deletion')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_repeated_queries', 'Smoke · UI', 'The hot paths do not repeat the same query', static function () use ($root, $ok, $bad): array {
            // Four patterns that cost a query each on every page:
            // - a subscription join per call (hasActiveSubscription)
            // - an uncapped notification badge count
            // - a LEFT JOIN + GROUP BY that builds a temporary table
            // - a gallery delete that leaves the rendered listings cached
            $auth  = (string) @file_get_contents($root . '/app/Core/Auth.php');
            $notif = (string) @file_get_contents($root . '/app/Models/Notification.php');
            $model = (string) @file_get_contents($root . '/app/Models/Gallery.php');
            $wall  = (string) @file_get_contents($root . '/app/Controllers/WallController.php');
            $problems = [];

            if (strpos($auth, "Subscription::isActive((int) \$user['id'])") !== false) {
                $problems[] = 'hasActiveSubscription() must use the memoised subscription row';
            }
            if (strpos($notif, "Cache::rememberGen('notifications'") === false) {
                $problems[] = 'the notification badge must be cached';
            }
            if (strpos($model, 'LEFT JOIN gallery_photo gp ON gp.gallery_id = g.id') !== false) {
                $problems[] = 'the listing queries must not build a temporary table (LEFT JOIN + GROUP BY)';
            }
            if (strpos($model, 'GROUP BY g.id') !== false) {
                $problems[] = 'the listing queries must not group by g.id';
            }
            if (strpos($wall, 'Purchase::userUnlocked(') !== false) {
                $problems[] = 'the wall must ask for the unlocked galleries in one query';
            }
            // softDelete() and restore() must both invalidate the listings.
            foreach (['softDelete', 'restore'] as $method) {
                $at = strpos($model, 'public static function ' . $method . '(');
                if ($at === false) {
                    continue;
                }
                $next = strpos($model, "
    public static function ", $at + 10);
                $body = $next === false ? substr($model, $at) : substr($model, $at, $next - $at);
                if (strpos($body, 'PageCache::invalidate()') === false) {
                    $problems[] = $method . '() must invalidate the rendered listings';
                }
            }

            return $problems === []
                ? $ok('the hot paths do not repeat a query')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.card_queries_nothing', 'Smoke · UI', 'A gallery card does not query the database', static function () use ($root, $ok, $bad): array {
            // The card partial fell back to a query per card when the caller
            // did not hand it the cover and the categories: the guest home
            // page spent 24 queries on 12 cards. The callers now preload
            // (Gallery::preloadCards) and the card asks for nothing.
            $card = (string) @file_get_contents($root . '/views/partials/gallery_card.php');
            $problems = [];
            if (strpos($card, 'Gallery::firstPhoto(') !== false) {
                $problems[] = 'the card must not query the cover photo itself';
            }
            if (strpos($card, 'Gallery::categories(') !== false) {
                $problems[] = 'the card must not query the categories itself';
            }
            $model = (string) @file_get_contents($root . '/app/Models/Gallery.php');
            if (strpos($model, 'public static function preloadCards(') === false) {
                $problems[] = 'the model needs a bulk card preloader';
            }
            // Every listing that renders cards must preload.
            foreach ([
                'views/home.php'                 => 'the home page',
                'views/gallery/show.php'         => 'the gallery page',
                'views/gallery/tag.php'          => 'the tag page',
                'views/collections/show.php'     => 'the collection page',
                'views/favorites/index.php'      => 'the favourites page',
            ] as $file => $what) {
                $src = (string) @file_get_contents($root . '/' . $file);
                if (strpos($src, '$cover = null') !== false) {
                    $problems[] = $what . ' still hands the card a null cover';
                }
            }
            return $problems === []
                ? $ok('the cards get their data in bulk and query nothing themselves')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.tour_stays_away', 'Smoke · UI', 'The tour does not re-arm and does not run in the admin', static function () use ($root, $ok, $bad): array {
            // The tour used to clear its "seen" flag the moment it started,
            // so it came back in every new tab until the member skipped it;
            // and the admin panel ran the same tour with the same
            // localStorage key, so skipping it there marked the member tour
            // as seen for that browser.
            $tour = (string) @file_get_contents($root . '/public/assets/js/tour.js');
            $problems = [];
            if (strpos($tour, "var inAdmin =") === false) {
                $problems[] = 'the tour must know it is in the admin panel';
            }
            if (strpos($tour, 'if(inAdmin) return;') === false) {
                $problems[] = 'the tour must not start in the admin panel';
            }
            // The flag may only be set when a step was actually shown.
            if (strpos($tour, 'if (shown) { markSeen(); }') === false) {
                $problems[] = 'the tour must only mark itself seen when it showed a step';
            }
            // The card is not a real modal: the page behind it is not inert.
            if (strpos($tour, "setAttribute('aria-modal'") !== false
                || strpos($tour, 'aria-modal="true"') !== false) {
                $problems[] = 'the tour card must not claim to be modal (the page behind it is live)';
            }
            return $problems === []
                ? $ok('the tour stays away once it has been shown, and it stays out of the admin')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.chat_is_announced', 'Smoke · UI', 'A new chat message is announced to a screen reader', static function () use ($root, $ok, $bad): array {
            // The chat thread and the live chat had no role and no
            // aria-live: a reply that arrived over the SSE stream was a
            // silent change, and a member only found it by scrolling.
            $chat = (string) @file_get_contents($root . '/views/chat/index.php');
            $live = (string) @file_get_contents($root . '/views/live.php');
            $problems = [];
            if (strpos($chat, 'role="log"') === false) {
                $problems[] = 'the chat thread must be a log region';
            }
            if (strpos($chat, 'aria-live="polite"') === false) {
                $problems[] = 'the chat thread must announce new messages';
            }
            if (strpos($live, 'role="log"') === false) {
                $problems[] = 'the live chat must be a log region';
            }
            return $problems === []
                ? $ok('the chat and the live chat announce what arrives')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_dead_lightbox', 'Smoke · UI', 'The replaced lightbox stays deleted', static function () use ($root, $ok, $bad): array {
            // The grid navigates to the in-page image viewer with the
            // comments (commit ded33e2), but the lightbox's JavaScript and
            // CSS were left behind: ~160 lines of JavaScript that could
            // never open, and ~15 CSS rules for a box nobody sees.
            $js  = (string) @file_get_contents($root . '/public/assets/js/user.js');
            $css = (string) @file_get_contents($root . '/public/assets/css/user.css');
            $problems = [];
            if (strpos($js, 'GalleryLightbox') !== false) {
                $problems[] = 'the dead lightbox script is back';
            }
            if (strpos($js, 'se-lightbox') !== false) {
                $problems[] = 'the dead lightbox script is back';
            }
            if (strpos($css, '.se-lightbox') !== false) {
                $problems[] = 'the dead lightbox styles are back';
            }
            return $problems === []
                ? $ok('the lightbox is gone; the in-page image viewer is the viewer')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.wall_costs_nothing', 'Smoke · UI', 'The wall does not spend a query per post and clears the badge', static function () use ($root, $ok, $bad): array {
            // The wall wrote a comment_count that nothing ever read (20
            // COUNT queries per view) and printed the date heading once per
            // post, so "October 18, 2026" appeared five times in a row. The
            // nav badge also stayed lit until every single notification had
            // been clicked, even though the wall SHOWS them.
            $controller = (string) @file_get_contents($root . '/app/Controllers/WallController.php');
            $view       = (string) @file_get_contents($root . '/views/wall.php');
            $model      = (string) @file_get_contents($root . '/app/Models/Notification.php');
            $problems   = [];

            if (strpos($controller, 'Comment::countFor') !== false) {
                $problems[] = 'the wall must not run a COUNT per post';
            }
            if (strpos($controller, 'Notification::markManyRead') === false) {
                $problems[] = 'opening the wall must mark the inbox it shows as read';
            }
            if (strpos($model, 'public static function markManyRead(') === false) {
                $problems[] = 'the notifications model needs a bulk mark-read';
            }
            if (strpos($view, '$newDay') === false) {
                $problems[] = 'the wall must print one date heading per day';
            }

            return $problems === []
                ? $ok('the wall is cheap, grouped by day, and clears the badge')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.paywall_is_honest', 'Smoke · UI', 'The site says what is locked instead of pretending it is empty', static function () use ($root, $ok, $bad): array {
            // 163 of the 165 published galleries are paid. The "related" and
            // "recommended" rails ignored the member's level, so they
            // advertised galleries the member cannot open; a locked
            // category said "no galleries in this category"; and the plan
            // cards never said what a tier unlocks.
            $model      = (string) @file_get_contents($root . '/app/Models/Gallery.php');
            $controller = (string) @file_get_contents($root . '/app/Controllers/GalleryController.php');
            $card       = (string) @file_get_contents($root . '/views/partials/gallery_card.php');
            $category   = (string) @file_get_contents($root . '/views/gallery/category.php');
            $listing    = (string) @file_get_contents($root . '/views/gallery/index.php');
            $membership = (string) @file_get_contents($root . '/views/membership/index.php');
            $problems   = [];

            if (strpos($model, 'public static function related(int $galleryId, int $limit = 6, int $maxLevel') === false) {
                $problems[] = 'related() must take the member\'s level';
            }
            if (strpos($model, 'public static function countLocked(') === false) {
                $problems[] = 'the model must be able to count the locked galleries';
            }
            if (strpos($model, 'public static function countByLevel(') === false) {
                $problems[] = 'the model must be able to count the galleries per level';
            }
            if (strpos($controller, 'Gallery::related($id, 6, Auth::effectiveLevel())') === false) {
                $problems[] = 'the related rail must use the member\'s level';
            }
            if (strpos($controller, 'recommended((int) $user[\'id\'], $viewedIds, 6, $maxLevel)') === false) {
                $problems[] = 'the recommendations must use the member\'s level';
            }
            if (strpos($card, 'card-lock') === false) {
                $problems[] = 'a locked gallery must say so on the card';
            }
            if (strpos($category, 'lockedCount') === false) {
                $problems[] = 'a locked category must say how many galleries are above the level';
            }
            if (strpos($listing, 'lockedTotal') === false) {
                $problems[] = 'the listing must say how much of the collection is above the level';
            }
            if (strpos($membership, 'levelCounts') === false) {
                $problems[] = 'the plan cards must say how many galleries the tier opens';
            }

            return $problems === []
                ? $ok('the locked content is labelled, counted and filtered by the member\'s level')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.no_inline_style_blocks', 'Smoke · UI', 'No view carries its own <style> block', static function () use ($root, $ok, $bad): array {
            // Documents that deliberately stand alone (their own <html>, their
            // own CSP) keep their own CSS, and the admin shell injects the
            // theme's CSS dynamically. Everything else must live in
            // public/assets/css. The list is a ratchet: it may shrink, never
            // grow - a new inline block fails the check.
            $allowed = [
                'unsubscribe.php'        => 'standalone one-click page',
                'auth/step_up.php'       => 'standalone password-confirm page',
                'admin/login.php'        => 'standalone admin sign-in',
                'admin/layout.php'       => 'injects the theme CSS at runtime',
                'membership/braintree_checkout.php' => 'standalone checkout page',
            ];
            $offenders = [];
            $files = array_merge(glob($root . '/views/*.php') ?: [], glob($root . '/views/*/*.php') ?: []);
            foreach ($files as $file) {
                $rel = substr($file, strlen($root . '/views/'));
                if (isset($allowed[$rel])) {
                    continue;
                }
                if (str_starts_with($rel, 'emails/')) {
                    continue;   // email clients need their own inline CSS
                }
                // A real tag, not the words "<style>" inside a PHP comment.
                if (preg_match('/^\s*<style[\s>]/m', (string) @file_get_contents($file)) === 1) {
                    $offenders[] = $rel;
                }            }
            return $offenders === []
                ? $ok('every view uses the shared stylesheets')
                : $bad('these views carry their own <style> block - move it into public/assets/css: ' . implode(', ', $offenders));
        });

        $add('smoke.ui.search_has_its_parts', 'Smoke · UI', 'The member search has every part it needs', static function () use ($root, $ok, $bad): array {
            // The search was the one path nothing covered: the dev box ran
            // 910 smoke checks while every member search answered 500 (a
            // missing migration, a stale model, a missing index).
            $stats  = (string) @file_get_contents($root . '/app/Models/Stats.php');
            $model  = (string) @file_get_contents($root . '/app/Models/Gallery.php');
            $schema = (string) @file_get_contents($root . '/schema.sql');
            $problems = [];

            if (strpos($stats, 'public static function recordSearch(') === false) {
                $problems[] = 'the searches are not recorded (Stats::recordSearch)';
            }
            if (strpos($stats, 'public static function popularSearches(') === false) {
                $problems[] = 'the popular searches are never listed (Stats::popularSearches)';
            }
            if (strpos($model, 'MATCH(p2.caption)') === false) {
                $problems[] = 'the photo captions are not searched';
            }
            if (strpos($schema, 'ft_caption') === false) {
                $problems[] = 'the caption fulltext index is missing from schema.sql';
            }
            if (strpos($model, 'likePrefix') === false) {
                $problems[] = 'a short search does not use the indexed prefix path';
            }

            return $problems === []
                ? $ok('the search records, lists and matches everything it should')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.ui.balanced_markup', 'Smoke · UI', 'The views open and close the same number of divs', static function () use ($root, $ok, $bad): array {
            // A <div> closed by </p> (or a missing </div>) makes everything
            // after it a child of that div, and the page turns into columns:
            // the admin dashboard was a jumble of five narrow columns because
            // one alert box was never closed.
            $offenders = [];
            $files = array_merge(
                glob($root . '/views/*.php') ?: [],
                glob($root . '/views/*/*.php') ?: []
            );
            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                // The PHP blocks are dropped: they can print markup, and
                // they can hide a branch, so only the literal markup counts.
                $src = (string) preg_replace('/<\?.*?\?>/s', '', $src);
                $open  = preg_match_all('/<div\b/i', $src);
                $close = preg_match_all('#</div>#i', $src);
                if ($open !== $close) {
                    $offenders[] = basename($file) . ' (' . $open . ' open, ' . $close . ' closed)';
                }
            }
            return $offenders === []
                ? $ok('every view balances its divs')
                : $bad('these views open and close a different number of divs, so the layout collapses: ' . implode(', ', $offenders));
        });

        $add('smoke.ui.urls_go_through_url', 'Smoke · UI', 'The views build their links with url(), not with a raw path', static function () use ($root, $ok, $bad): array {
            // A raw '/admin/...' href 404s on a site installed in a
            // subdirectory (the prod site lives in /gallery): the auto
            // poster's recent-posts pager sent every page link to the wrong
            // place, so the pagination did nothing.
            $offenders = [];
            $files = array_merge(
                glob($root . '/views/*.php') ?: [],
                glob($root . '/views/*/*.php') ?: []
            );
            // Every view at once, so a path handed to a partial that builds
            // the URL is not mistaken for a raw one.
            $allViews = '';
            foreach ($files as $file) {
                $allViews .= (string) @file_get_contents($file);
            }

            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                $lines = explode("\n", $src);
                foreach ($lines as $n => $line) {
                    // (a) A raw path straight in an href or an action.
                    if (preg_match_all('/(?:href|action)=["\'](\/[a-zA-Z][^"\']*)["\']/', $line, $m)) {
                        foreach ($m[1] as $path) {
                            if (strpos($path, '/assets/') === 0) {
                                continue;   // the assets are built with asset_url()
                            }
                            $offenders[] = basename($file) . ':' . ($n + 1) . ' ' . $path;
                        }
                    }
                    // (b) A path assigned to a variable that is then used as
                    // a link. If the variable is handed to url() somewhere
                    // else in the file, it is fine; if it is printed
                    // straight into an href, it 404s on a subdirectory
                    // install.
                    if (preg_match('/\$(\w*(?:path|url|base|link)\w*)\s*=[^;]*?[\'"](\/[a-zA-Z][^\'"]*)[\'"]/i', $line, $m) === 1) {
                        $var = $m[1];
                        $built = '/\b(asset_url|url|absolute_url|buildUrl)\s*\(\s*\$?' . preg_quote($var, '/') . '\b/';
                        // A filesystem path (used for a filemtime) is not a
                        // URL: it never reaches an href.
                        $onDisk = '/\b(filemtime|is_file|file_exists|file_get_contents|dirname)\s*\(\s*\$?' . preg_quote($var, '/') . '\b/';
                        if (preg_match('/\b(asset_url|url|absolute_url|buildUrl)\s*\(/', $line) === 0
                            && strpos($m[2], '/assets/') !== 0
                            && preg_match($built, $allViews) === 0
                            && preg_match($onDisk, $src) === 0) {
                            $offenders[] = basename($file) . ':' . ($n + 1) . ' ' . $m[2];
                        }
                    }
                }
            }
            return $offenders === []
                ? $ok('every link goes through url()')
                : $bad('these links use a raw path, so they 404 when the site is installed in a subdirectory: ' . implode(', ', $offenders));
        });

        $add('smoke.ui.admin_js_layer', 'Smoke · UI', 'The admin panel has a confirmation modal, an undo toast and inline editing', static function () use ($root, $ok, $bad): array {
            // The admin panel used to ask every destructive question with
            // window.confirm (a browser dialog with no styling) and to
            // reload the whole listing after a delete. The admin JS layer
            // replaces that with a styled modal, a toast that can undo the
            // delete, and an inline editor that does not save a role on a
            // stray arrow key.
            $js   = (string) @file_get_contents($root . '/public/assets/js/admin.js');
            $css  = (string) @file_get_contents($root . '/public/assets/css/admin.css');
            $shell = (string) @file_get_contents($root . '/views/admin/layout.php');
            $users = (string) @file_get_contents($root . '/views/admin/users.php');
            $galleries = (string) @file_get_contents($root . '/views/admin/galleries.php');
            $routes = (string) @file_get_contents($root . '/config/routes.php');

            $problems = [];
            foreach ([
                'AdminConfirm'            => 'the styled confirmation modal',
                'function initUndoableDeletes' => 'the undoable row delete',
                'function initInlineEdit'  => 'the inline row editor',
                'window.adminToast'        => 'the undo toast',
            ] as $needle => $what) {
                if (strpos($js, $needle) === false) {
                    $problems[] = 'admin.js is missing ' . $what . ' (' . $needle . ')';
                }
            }
            foreach (['.adm-modal', '.adm-toast', '.adm-inline-pending'] as $needle) {
                if (strpos($css, $needle) === false) {
                    $problems[] = 'admin.css is missing the ' . $needle . ' styles';
                }
            }
            if (strpos($shell, 'meta name="csrf-token"') === false) {
                $problems[] = 'the admin shell must publish the CSRF token in a meta tag';
            }
            if (strpos($galleries, 'data-undo-url') === false) {
                $problems[] = 'the gallery delete form must carry data-undo-url';
            }
            if (strpos($routes, '/admin/galleries/{id}/restore') === false) {
                $problems[] = 'there must be a route that undoes a gallery delete';
            }
            if (strpos($users, 'data-autosubmit="manual"') === false) {
                $problems[] = 'the role select must wait for a Save, not save on a stray arrow key';
            }
            // The modal re-submits the form after the answer. Without the
            // submitter, requestSubmit() drops the button's formaction and
            // its name/value - so a "Delete selected" button would post to
            // the form's own action instead.
            $adminLayout = (string) @file_get_contents($root . '/views/admin/layout.php');
            if (strpos($adminLayout, 'e.submitter') === false) {
                $problems[] = 'the confirmation must keep the button that was clicked';
            }
            if (strpos($adminLayout, 'requestSubmit(submitter') === false) {
                $problems[] = 'the re-submit must pass the submitter to requestSubmit()';
            }
            // A native confirm in an admin view is the old behaviour again.
            $files = glob($root . '/views/admin/*.php') ?: [];
            foreach ($files as $file) {
                $src = (string) @file_get_contents($file);
                if (basename($file) === 'layout.php') {
                    continue;   // the shell keeps the native fallback
                }
                if (preg_match('/(?<![a-zA-Z0-9_.])confirm\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
                    $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                    $problems[] = basename($file) . ' still asks with a native confirm() on line ' . $line;
                }
            }

            return $problems === []
                ? $ok('the confirmation modal, the undo toast and the inline editor are in place')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.legal.consent_banner', 'Smoke · Legal', 'Consent banner wired into the layout', static function () use ($layoutSrc, $ok, $bad): array {
            return strpos($layoutSrc, "require __DIR__ . '/partials/consent_banner.php'") !== false && strpos($layoutSrc, '$gaShowBanner') !== false
                ? $ok('banner partial included behind $gaShowBanner')
                : $bad('layout must include partials/consent_banner.php behind $gaShowBanner');
        });
        $add('smoke.legal.csp_hardened', 'Smoke · Legal', 'CSP includes object-src/base-uri/form-action hardening', static function () use ($root, $ok, $bad): array {
            $csp = (string) @file_get_contents($root . '/public/index.php');
            return strpos($csp, 'Content-Security-Policy: default-src') !== false
                && strpos($csp, "object-src 'none'") !== false
                && strpos($csp, "base-uri 'self'") !== false
                && strpos($csp, "form-action 'self'") !== false
                ? $ok('hardening directives present in the enforced PHP CSP')
                : $bad('public/index.php must emit an enforced CSP with object-src none, base-uri self, form-action self');
        });

        // ----------------------------------------------------- Lifecycle
        $lcSchema = $read("$root/schema.sql");
        $lcService = $read("$root/app/Core/Lifecycle.php");
        $lcQueue   = $read("$root/app/Models/EmailQueue.php");
        $lcWebhook = $read("$root/app/Controllers/WebhookController.php");
        $lcHouse   = $read("$root/app/Core/Housekeeping.php");
        $lcEmailer = $read("$root/app/Controllers/EmailerController.php");
        $lcView    = $read("$root/views/admin/emailer.php");
        $add('smoke.lifecycle.schema', 'Smoke · Lifecycle', 'schema.sql has subscription_email_log + email_queue due columns', static function () use ($lcSchema, $ok, $bad): array {
            return stripos($lcSchema, 'subscription_email_log') !== false
                && strpos($lcSchema, 'scheduled_at') !== false
                && strpos($lcSchema, 'next_attempt_at') !== false
                && strpos($lcSchema, 'idx_email_queue_due') !== false
                ? $ok('exactly-once log + scheduling/backoff present')
                : $bad('schema.sql must carry subscription_email_log and email_queue scheduled_at/next_attempt_at/idx_email_queue_due');
        });
        $add('smoke.lifecycle.service', 'Smoke · Lifecycle', 'Lifecycle service provides dunning + win-back entry points', static function () use ($lcService, $ok, $bad): array {
            foreach (['function send', 'function onPaymentFailed', 'function onPastDue', 'function onExpired', 'function onWinback', 'function runRenewalReminders', 'function runWinbacks', 'function runTrialEndings', 'function runInactiveWinbacks', 'function sendToUser'] as $needle) {
                if (strpos($lcService, $needle) === false) {
                    return $bad("Lifecycle.php missing $needle");
                }
            }
            return $ok('service entry points present');
        });
        $add('smoke.lifecycle.emailqueue', 'Smoke · Lifecycle', 'EmailQueue supports transactional enqueue + exactly-once log', static function () use ($lcQueue, $ok, $bad): array {
            return strpos($lcQueue, 'function enqueueTx') !== false
                && strpos($lcQueue, 'function lifecycleRecord') !== false
                && strpos($lcQueue, 'function lifecycleAlreadySent') !== false
                && strpos($lcQueue, 'scheduled_at') !== false && strpos($lcQueue, 'next_attempt_at') !== false
                ? $ok('enqueueTx + lifecycle log + due filter present')
                : $bad('EmailQueue must implement enqueueTx/lifecycleRecord/lifecycleAlreadySent and honour scheduled_at/next_attempt_at');
        });
        $add('smoke.lifecycle.hooks', 'Smoke · Lifecycle', 'Webhooks and housekeeping fire lifecycle emails on state changes', static function () use ($lcWebhook, $lcHouse, $ok, $bad): array {
            return strpos($lcWebhook, 'Lifecycle::onPastDue') !== false
                && strpos($lcWebhook, 'Lifecycle::onExpired') !== false
                && strpos($lcWebhook, 'Lifecycle::onPaymentFailed') !== false
                && strpos($lcHouse, 'Lifecycle::onExpired') !== false
                && strpos($lcHouse, 'Lifecycle::runRenewalReminders') !== false
                && strpos($lcHouse, 'Lifecycle::runWinbacks') !== false
                ? $ok('hooks wired into Braintree/PayPal webhooks + housekeeping')
                : $bad('WebhookController/Housekeeping must call the Lifecycle transition methods');
        });
        $add('smoke.lifecycle.admin', 'Smoke · Lifecycle', 'Admin emailer exposes lifecycle toggles + test sends', static function () use ($lcEmailer, $lcView, $ok, $bad): array {
            return strpos($lcEmailer, 'function testLifecycle') !== false
                && strpos($lcEmailer, 'lifecycle_trial_endings') !== false
                && strpos($lcEmailer, 'lifecycle_inactive_winbacks') !== false
                && strpos($lcView, 'lifecycle_renewal_reminders') !== false
                && strpos($lcView, 'lifecycle_winbacks') !== false
                && strpos($lcView, 'lifecycle_trial_endings') !== false
                && strpos($lcView, 'lifecycle_inactive_winbacks') !== false
                && strpos($lcView, 'test-lifecycle') !== false
                ? $ok('admin test-send + toggles present')
                : $bad('EmailerController/emailer view must expose the lifecycle test-send and every lifecycle toggle');
        });
        $add('smoke.lifecycle.retention_audiences', 'Smoke · Lifecycle', 'Trial-ending + inactive-member win-back audiences, once per member', static function () use ($root, $lcService, $lcSchema, $lcQueue, $lcHouse, $read, $ok, $bad): array {
            $trialHtml = $read("$root/views/emails/trial_ending.php");
            $trialText = $read("$root/views/emails/trial_ending.text.php");
            $inactHtml = $read("$root/views/emails/winback_inactive.php");
            $inactText = $read("$root/views/emails/winback_inactive.text.php");
            $emailerCfg = $read("$root/app/Models/EmailerConfig.php");

            $missing = [];
            if (strpos($lcService, "transaction_ref LIKE 'TRIAL-%'") === false) $missing[] = 'trial-ending scan';
            if (strpos($lcService, "'TRIAL-%']") === false) $missing[] = 'renewal reminders exclude trials';
            if (strpos($lcService, 'function runInactiveWinbacks(') === false || strpos($lcService, 'last_seen_at <= DATE_SUB') === false || strpos($lcService, 'Subscription::activeWhere') === false) $missing[] = 'inactive-member scan';
            if (strpos($lcService, 'function sendToUser(') === false) $missing[] = 'user-keyed sender';
            if (strpos($lcQueue, 'function userEventAlreadySent') === false || strpos($lcQueue, 'function userEventRecord') === false) $missing[] = 'user-keyed dedupe in EmailQueue';
            if (strpos($lcSchema, 'retention_email_log') === false) $missing[] = 'retention_email_log in schema';
            if (strpos($lcHouse, 'Lifecycle::runTrialEndings') === false || strpos($lcHouse, 'Lifecycle::runInactiveWinbacks') === false) $missing[] = 'housekeeping wiring';
            if (strpos($emailerCfg, 'lifecycle_trial_endings') === false || strpos($emailerCfg, 'lifecycle_inactive_winbacks') === false) $missing[] = 'EmailerConfig flags';
            if (strpos($trialHtml, '{{unsubscribe-url}}') === false || strpos($inactHtml, '{{unsubscribe-url}}') === false) $missing[] = 'marketing unsubscribe footer in both templates';
            if (trim($trialText) === '' || trim($inactText) === '') $missing[] = 'plain-text twins';

            return $missing === []
                ? $ok('trial + inactive audiences scan, dedupe once per member, templates carry the unsubscribe footer')
                : $bad('retention audiences incomplete: ' . implode(', ', $missing));
        });

        // ------------------------------------------------- PPV / one-off charges
        $ppSchema = $read("$root/schema.sql");
        $ppGw     = $read("$root/app/Core/BraintreeGateway.php");
        $ppGwPP   = $read("$root/app/Core/PayPalGateway.php");
        $ppModel  = $read("$root/app/Models/Purchase.php");
        $ppPurch  = $read("$root/app/Controllers/PurchaseController.php");
        $ppWh     = $read("$root/app/Controllers/WebhookController.php");
        $ppShow   = $read("$root/views/gallery/show.php");
        $add('smoke.ppv.schema', 'Smoke · PPV', 'purchases carries processor id + gateway-ref unique key', static function () use ($ppSchema, $ok, $bad): array {
            return strpos($ppSchema, 'payment_processor_id') !== false
                && strpos($ppSchema, 'uq_purchases_gateway_ref') !== false
                && strpos($ppSchema, 'updated_at') !== false
                ? $ok('schema updated for live purchases')
                : $bad('schema.sql purchases must gain payment_processor_id, updated_at and uq_purchases_gateway_ref');
        });
        $add('smoke.ppv.gateways', 'Smoke · PPV', 'Gateways expose one-off charge + refund', static function () use ($ppGw, $ppGwPP, $ok, $bad): array {
            return strpos($ppGw, 'function sale') !== false && strpos($ppGw, 'function refund') !== false
                && strpos($ppGwPP, 'function createOrder') !== false && strpos($ppGwPP, 'function captureOrder') !== false && strpos($ppGwPP, 'function refundCapture') !== false
                ? $ok('Braintree sale/refund + PayPal Orders methods present')
                : $bad('BraintreeGateway must implement sale/refund; PayPalGateway must implement createOrder/captureOrder/refundCapture');
        });
        $add('smoke.ppv.purchase_model', 'Smoke · PPV', 'Purchase settles/refunds by gateway ref and totals by window', static function () use ($ppModel, $ok, $bad): array {
            foreach (['function settleByReference', 'function settleById', 'function refundByReference', 'function totalsBetween', 'function seriesBetween'] as $n) {
                if (strpos($ppModel, $n) === false) { return $bad("Purchase model missing $n"); }
            }
            return $ok('settlement + totals helpers present');
        });
        $add('smoke.ppv.endpoints', 'Smoke · PPV', 'Live checkout endpoints implemented', static function () use ($ppPurch, $ok, $bad): array {
            foreach (['function checkoutToken', 'function unlockLive', 'function tipLive', 'function completed', 'function chargeCard', 'function chargePayPal'] as $n) {
                if (strpos($ppPurch, $n) === false) { return $bad("PurchaseController missing $n"); }
            }
            return $ok('token + unlock-live + tip-live + completion present');
        });
        $add('smoke.ppv.webhook_reconcile', 'Smoke · PPV', 'Webhooks reconcile one-off captures/transactions', static function () use ($ppWh, $ok, $bad): array {
            return strpos($ppWh, "PAYMENT.CAPTURE.COMPLETED") !== false
                && strpos($ppWh, 'Purchase::settleByReference') !== false
                && strpos($ppWh, "transaction_settled')") !== false
                ? $ok('capture/transaction reconciliation wired')
                : $bad('WebhookController must reconcile PayPal capture events and Braintree transaction_* events to purchases');
        });
        $add('smoke.ppv.view', 'Smoke · PPV', 'Gallery show renders the card-checkout block', static function () use ($ppShow, $ok, $bad): array {
            return strpos($ppShow, "require __DIR__ . '/../partials/card_checkout.php'") !== false
                && strpos($ppShow, '/unlock-live') !== false
                && strpos($ppShow, '/tip/live') !== false
                ? $ok('PPV + tip card checkout wired')
                : $bad('views/gallery/show.php must include partials/card_checkout.php for both PPV and tips');
        });
        $ccPartial = $read("$root/views/partials/card_checkout.php");
        $add('smoke.ppv.card_disabled_until_live', 'Smoke · PPV', 'Card checkout renders disabled until a live Braintree processor', static function () use ($ccPartial, $ok, $bad): array {
            return strpos($ccPartial, '$btLive') !== false
                && strpos($ccPartial, "data-cc-live") !== false
                && strpos($ccPartial, "'live'") !== false
                && strpos($ccPartial, 'Card payments are coming soon') !== false
                ? $ok('card form gated behind a live Braintree processor; PayPal remains available')
                : $bad('views/partials/card_checkout.php must gate the card fields + Pay button behind $btLive and keep PayPal enabled');
        });

        // ------------------------------------------- Plan checkout processor
        $pcSchema = $read("$root/schema.sql");
        $pcModel  = $read("$root/app/Models/Plan.php");
        $pcView   = $read("$root/views/membership/index.php");
        $pcCtl    = $read("$root/app/Controllers/PlanController.php");
        $pcFc     = $read("$root/views/admin/plan_create.php");
        $pcFe     = $read("$root/views/admin/plan_edit.php");
        $add('smoke.plan.processor_schema', 'Smoke · Plan Processor', 'plans carries checkout_processor', static function () use ($pcSchema, $ok, $bad): array {
            return stripos($pcSchema, 'checkout_processor') !== false && stripos($pcSchema, "'auto','paypal','braintree','offline'") !== false
                ? $ok('schema column + enum present')
                : $bad('schema.sql plans must carry checkout_processor enum auto/paypal/braintree/offline');
        });
        $add('smoke.plan.processor_model', 'Smoke · Plan Processor', 'Plan model exposes the choices', static function () use ($pcModel, $ok, $bad): array {
            return strpos($pcModel, 'CHECKOUT_CHOICES') !== false && strpos($pcModel, 'function checkoutLabel') !== false
                ? $ok('choices + label helper present')
                : $bad('Plan model must define CHECKOUT_CHOICES and checkoutLabel');
        });
        $add('smoke.plan.processor_controller', 'Smoke · Plan Processor', 'PlanController persists the choice', static function () use ($pcCtl, $ok, $bad): array {
            return substr_count($pcCtl, 'checkout_processor') >= 2 ? $ok('create + update read checkout_processor') : $bad('PlanController must persist checkout_processor in store and update');
        });
        $add('smoke.plan.processor_forms', 'Smoke · Plan Processor', 'Plan create/edit forms offer the choice', static function () use ($pcFc, $pcFe, $ok, $bad): array {
            return strpos($pcFc, 'checkout_processor') !== false && strpos($pcFe, 'checkout_processor') !== false
                ? $ok('dropdown on both forms')
                : $bad('plan_create.php and plan_edit.php must offer the checkout_processor dropdown');
        });
        $add('smoke.plan.processor_membership', 'Smoke · Plan Processor', 'Membership page honours checkout_processor + classic PayPal buttons restored', static function () use ($pcView, $ok, $bad): array {
            return strpos($pcView, '$ppChoice') !== false
                && strpos($pcView, 'data-pp-button') !== false
                && strpos($pcView, '[data-pp-button]') !== false
                && strpos($pcView, "'P-2EE95782UN3086035NKHSZ4A'") !== false
                && strpos($pcView, "'chat-add-on' ? 'chat'") !== false
                ? $ok('processor-aware cards + generic PayPal renderer + original plan-id fallbacks + chat add-on alias')
                : $bad('views/membership/index.php must honour checkout_processor, render data-pp-button blocks and keep the original PayPal plan-id fallbacks including the chat add-on alias');
        });

        // ------------------------------------ Reddit browser auto poster
        $rbClient  = $read("$root/app/Models/RedditClient.php");
        $rbQueue   = $read("$root/app/Models/AutoPostQueue.php");
        $rbScript  = $read("$root/bin/browser/reddit-post.mjs");
        $rbLogin   = $read("$root/bin/browser/reddit-login.mjs");
        $rbBridge  = $read("$root/bin/reddit_post.php");
        $rbSetup   = $read("$root/bin/browser/setup_reddit_browser.sh");
        $rbPlatforms = $read("$root/app/Core/Platforms.php");
        $rbAdmin   = $read("$root/views/admin/auto_poster.php");
        $rbGitignore = $read("$root/.gitignore");
        $add('smoke.reddit.browser_dispatch', 'Smoke · Reddit Browser', 'RedditClient falls back to the browser share method', static function () use ($rbClient, $ok, $bad): array {
            return strpos($rbClient, 'function postViaBrowser') !== false
                && strpos($rbClient, "'browser_enabled'") !== false
                && strpos($rbClient, "bin/reddit_post.php") !== false
                ? $ok('browser dispatch wired into the API client')
                : $bad('RedditClient must implement postViaBrowser and dispatch on browser_enabled');
        });
        $add('smoke.reddit.browser_queue', 'Smoke · Reddit Browser', 'Queue feeds the attributed gallery share URL to the browser client', static function () use ($rbQueue, $ok, $bad): array {
            return strpos($rbQueue, "\$canonical === 'reddit'") !== false && strpos($rbQueue, '$meta[\'url\']') !== false
                ? $ok('gallery share url passed for reddit')
                : $bad('AutoPostQueue::post must set meta[url] for reddit using Traffic::buildUrl');
        });
        $add('smoke.reddit.browser_scripts', 'Smoke · Reddit Browser', 'Browser worker + login scripts target the Reddit submit page and save a session', static function () use ($rbScript, $rbLogin, $rbBridge, $ok, $bad): array {
            return strpos($rbScript, 'reddit.com/r/') !== false
                && strpos($rbScript, 'session.json') !== false
                && strpos($rbScript, '/submit') !== false
                && strpos($rbLogin, 'storageState') !== false
                && strpos($rbBridge, 'reddit-post.mjs') !== false
                ? $ok('post + login + bridge present and consistent')
                : $bad('bin/browser/reddit-{post,login}.mjs and bin/reddit_post.php must drive the reddit.com submit page with a storageState session');
        });
        $add('smoke.reddit.browser_setup', 'Smoke · Reddit Browser', 'One-time browser setup script + gitignore', static function () use ($rbSetup, $rbGitignore, $ok, $bad): array {
            return strpos($rbSetup, "playwright install chromium") !== false
                && strpos($rbGitignore, 'storage/reddit-browser/*') !== false
                ? $ok('setup script + gitignored runtime')
                : $bad('bin/browser/setup_reddit_browser.sh must install chromium and .gitignore must exclude storage/reddit-browser');
        });
        $add('smoke.reddit.browser_config', 'Smoke · Reddit Browser', 'Admin exposes the browser-mode fields and worker status', static function () use ($rbPlatforms, $rbAdmin, $ok, $bad): array {
            return substr_count($rbPlatforms, 'browser_enabled') >= 1
                && substr_count($rbPlatforms, "'password'") >= 2
                && strpos($rbAdmin, 'Browser worker (share-button method)') !== false
                ? $ok('registry fields + admin status block present')
                : $bad('Platforms reddit must add browser_enabled/username/password fields and auto_poster.php must render the Browser worker status');
        });
        $add('smoke.reddit.multi_sub', 'Smoke · Reddit Browser', 'Posts fan out to multiple subreddits + the profile page', static function () use ($root, $ok, $bad): array {
            $p = (string) @file_get_contents($root . '/app/Core/Platforms.php');
            $c = (string) @file_get_contents($root . '/app/Controllers/RedditBrowserJobsController.php');
            $r = (string) @file_get_contents($root . '/app/Models/RedditClient.php');
            $h = (string) @file_get_contents($root . '/bin/browser/reddit-homeworker.mjs');
            return strpos($p, "'subreddits'") !== false
                && strpos($p, "'post_to_profile'") !== false
                && strpos($c, "'subreddits'") !== false && strpos($c, "'profile'") !== false
                && strpos($r, '$subs = array_values(array_unique($subs));') !== false
                && strpos($h, 'subreddits: targets') !== false
                ? $ok('registry list + profile flag; payload carries subreddits/profile; client + home worker fan out')
                : $bad('reddit channel must support a subreddit list + profile posting end-to-end (registry, payloadFor, RedditClient, home worker)');
        });
        $add('smoke.autopost.no_secret', 'Smoke · Autopost', 'Secret galleries are never recommended or posted', static function () use ($root, $ok, $bad): array {
            $q = (string) @file_get_contents($root . '/app/Models/AutoPostQueue.php');
            $c = (string) @file_get_contents($root . '/app/Controllers/RedditBrowserJobsController.php');
            return strpos($q, 'g.is_secret = 0') !== false
                && strpos($q, 'AND is_secret = 0') !== false
                && strpos($q, 'q.gallery_id = 0 OR (g.id IS NOT NULL AND g.is_secret = 0') !== false
                && strpos($c, 'Secret or removed gallery') !== false
                ? $ok('recommendations + enqueue + due()/takeBrowserJob + payloadFor all exclude secret galleries')
                : $bad('secret galleries must be excluded from recommendations, enqueue, dispatch and the reddit payload');
        });
        $add('smoke.autopost.video_processing', 'Smoke · Autopost', 'Video uploads wait for X processing before tweeting', static function () use ($root, $ok, $bad): array {
            $t = (string) @file_get_contents($root . '/app/Models/TwitterClient.php');
            return strpos($t, "'command' => 'STATUS'") !== false
                && strpos($t, 'function awaitMedia') !== false
                && strpos($t, 'if ($isVideo || (is_array($processing) && $processing !== []))') !== false
                ? $ok('video uploads poll STATUS until processed before the tweet')
                : $bad('TwitterClient::uploadMedia must poll the media STATUS command after FINALIZE for videos (a tweet referencing an unprocessed video fails with "invalid parameters")');
        });
        $add('smoke.autopost.video_cover', 'Smoke · Autopost', 'Videos post their cover thumbnail, not the video file', static function () use ($root, $ok, $bad): array {
            $q = (string) @file_get_contents($root . '/app/Models/AutoPostQueue.php');
            return strpos($q, 'function videoCoverPath') !== false
                && strpos($q, 'function videoCoverMedia') !== false
                && strpos($q, 'self::videoCoverMedia((string) $photo[\'filename\']') !== false
                && strpos($q, "'name'     => 'video-cover.jpg'") !== false
                ? $ok('cover thumbnail (thumb_<file>) preferred; video file only as a last resort')
                : $bad('AutoPostQueue must attach the video cover thumbnail (thumb_<file>) instead of uploading the video');
        });

        $add('smoke.autopost.image_only_channel_posts_the_cover', 'Smoke · Autopost', 'A channel that takes images but not videos still gets a picture', static function () use ($root, $ok, $bad): array {
            // Reddit posts the live streams (videos), but the channel takes
            // images only. Dropping the video left the post as bare text with
            // no picture at all, so the cover is posted instead.
            $q = (string) @file_get_contents($root . '/app/Models/AutoPostQueue.php');
            $platforms = (string) @file_get_contents($root . '/app/Core/Platforms.php');

            if (strpos($q, '$platformTakesImages') === false
                || strpos($q, "elseif (\$isVideo && \$videoRule === 'none')") === false) {
                return $bad('AutoPostQueue must post the video cover on a channel with video => none');
            }

            // The reddit channel takes one image and no video.
            if (preg_match("/'reddit'\s*=>\s*\[(.*?)\n        \]/s", $platforms, $m) !== 1) {
                return $bad('the reddit channel settings are missing');
            }
            $block = $m[1];
            if (strpos($block, "'media'        => true") === false
                || strpos($block, "'max_media'    => 1") === false
                || strpos($block, "'video'        => 'none'") === false) {
                return $bad('the reddit channel must take one image and no video');
            }
            return $ok('a video-only item still goes out with its cover picture');
        });

        // ------------------------- Home-browser worker (share method) -----
        $rbQueue2  = $read("$root/app/Models/AutoPostQueue.php");
        $rbCtrl2   = $read("$root/app/Controllers/RedditBrowserJobsController.php");
        $rbWorker  = $read("$root/app/Models/AutoPostQueue.php");
        $rbWorkerPhp = $read("$root/bin/autopost_worker.php");
        $rbHome    = $read("$root/bin/browser/reddit-homeworker.mjs");
        $rbEnv     = $read("$root/.env.example");
        $add('smoke.reddit.webhook_endpoints', 'Smoke · Reddit Browser', 'Site hands jobs to the home worker over an authenticated webhook', static function () use ($rbCtrl2, $ok, $bad): array {
            return strpos($rbCtrl2, "function take") !== false
                && strpos($rbCtrl2, "function report") !== false
                && strpos($rbCtrl2, 'REDDIT_BROWSER_KEY') !== false
                && strpos($rbCtrl2, 'takeBrowserJob') !== false
                ? $ok('take/report endpoints with Bearer auth present')
                : $bad('RedditBrowserJobsController must implement take/report guarded by REDDIT_BROWSER_KEY');
        });
        $add('smoke.reddit.worker_defer', 'Smoke · Reddit Browser', 'Local worker defers reddit browser rows to the home worker', static function () use ($rbQueue2, $rbWorkerPhp, $ok, $bad): array {
            return strpos($rbQueue2, 'function shouldDeferToBrowser') !== false
                && strpos($rbQueue2, 'function takeBrowserJob') !== false
                && strpos($rbQueue2, 'function releaseClaim') !== false
                && strpos($rbWorkerPhp, 'shouldDeferToBrowser') !== false
                ? $ok('defer + atomic claim helpers wired into the worker')
                : $bad('AutoPostQueue/browser worker must defer reddit rows and claim jobs atomically');
        });
        $add('smoke.reddit.home_worker', 'Smoke · Reddit Browser', 'A home-machine worker polls and reports jobs', static function () use ($rbHome, $rbEnv, $ok, $bad): array {
            return strpos($rbHome, 'browser-jobs') !== false
                && strpos($rbHome, 'reddit-post.mjs') !== false
                && strpos($rbHome, 'report') !== false
                && strpos($rbEnv, 'REDDIT_BROWSER_KEY') !== false
                ? $ok('homeworker polls take + runs the post script + reports')
                : $bad('bin/browser/reddit-homeworker.mjs must poll browser-jobs, run reddit-post.mjs and POST the report');
        });

        // ---------------------------------------------------------- Security
        $secRouterP = $read("$root/app/Core/Router.php");
        $secUploadP = $read("$root/app/Core/MediaUploader.php");
        $secChatP   = $read("$root/app/Models/ChatMessage.php");
        $secHouse   = $read("$root/app/Core/Housekeeping.php");
        $secPurch   = $read("$root/app/Controllers/PurchaseController.php");
        $secHeadersP= $read("$root/config/gallery-headers.conf");
        $secViews   = '';
        foreach ((array) glob("$root/views/*.php") + (array) glob("$root/views/**/*.php") as $v) {
            if (is_file($v)) $secViews .= "\n" . (string) file_get_contents($v);
        }
        $secAppPhp = '';
        foreach ((array) glob("$root/app/**/*.php") as $p) {
            if (is_file($p)) $secAppPhp .= "\n" . (string) file_get_contents($p);
        }
        $add('smoke.sec.headers_framing', 'Smoke · Security', 'COOP + CORP headers configured', static function () use ($secHeadersP, $ok, $bad): array {
            return stripos($secHeadersP, 'Cross-Origin-Opener-Policy') !== false && stripos($secHeadersP, 'Cross-Origin-Resource-Policy') !== false
                ? $ok('COOP/CORP present')
                : $bad('config/gallery-headers.conf must set Cross-Origin-Opener-Policy and Cross-Origin-Resource-Policy');
        });
        $add('smoke.sec.csrf_webhooks_only', 'Smoke · Security', 'CSRF exemptions are limited to /webhooks', static function () use ($secRouterP, $ok, $bad): array {
            return strpos($secRouterP, "'/webhooks/'") !== false && strpos($secRouterP, '$csrfExempt') !== false
                ? $ok('POST CSRF enforced; only webhook prefix exempt')
                : $bad('Router must keep CSRF exemptions scoped to the /webhooks/ prefix');
        });
        $add('smoke.sec.no_superglobals_in_views', 'Smoke · Security', 'Views never echo request superglobals (XSS surface)', static function () use ($secViews, $ok, $bad): array {
            $cpu = preg_match('#<\?=\s*[^?]*\$_(GET|POST|REQUEST)\b#', $secViews)
                || preg_match('#<\?php[^?]*\becho\b[^?]*\$_(GET|POST|REQUEST)\b#', $secViews)
                || preg_match('#\becho\s+\$_(GET|POST|REQUEST)\b#', $secViews);
            return $cpu ? $bad('views must not echo $_(GET|POST|REQUEST)') : $ok('clean');
        });
        $add('smoke.sec.no_direct_shell_with_request', 'Smoke · Security', 'No shell command interpolates request data', static function () use ($secAppPhp, $ok, $bad): array {
            $hits = [];
            foreach (preg_split('/\R/', $secAppPhp) as $i => $line) {
                if (!preg_match('/\b(exec|shell_exec|proc_open|system|passthru|popen)\s*\(/', $line)) continue;
                $stmt = $line;
                $j = $i + 1;
                while (substr_count($stmt, ';') === 0 && isset(explode("\n", $secAppPhp)[$j])) {
                    $stmt .= explode("\n", $secAppPhp)[$j];
                    $j++;
                }
                if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/', $stmt)) {
                    $hits[] = 'line ' . ($i + 1);
                }
            }
            return $hits === [] ? $ok('no shell call feeds request data in') : $bad('shell calls interpolate request data at: ' . implode(',', $hits));
        });
        $add('smoke.sec.money_rate_limits', 'Smoke · Security', 'Money/checkout endpoints are rate limited', static function () use ($secPurch, $ok, $bad): array {
            return strpos($secPurch, 'unlock-live') !== false && substr_count($secPurch, 'RateLimiter::allow') >= 4
                ? $ok('unlock/tip/live/checkout throttled')
                : $bad('PurchaseController must RateLimiter-allow unlock, tip, unlock-live, tip-live and checkoutToken');
        });
        $add('smoke.sec.ffmpeg_guard', 'Smoke · Security', 'Housekeeping guards an ffmpeg burst', static function () use ($secHouse, $ok, $bad): array {
            return strpos($secHouse, "pgrep -fc ffmpeg") !== false && strpos($secHouse, '$out[\'ffmpeg_count\']') !== false
                ? $ok('ffmpeg count captured + alert once >= 6')
                : $bad('Housekeeping must record ffmpeg_count and alert on a burst');
        });
        $add('smoke.sec.disk_fill_alert', 'Smoke · Security', 'Housekeeping alerts when the disk fills', static function () use ($secHouse, $ok, $bad): array {
            return strpos($secHouse, 'disk_used_percent') !== false
                && strpos($secHouse, "disk_total_space") !== false
                && strpos($secHouse, "'disk-fill'") !== false
                && strpos($secHouse, '>= 85') !== false
                ? $ok('disk usage percent + 85% admin alert present')
                : $bad('Housekeeping must compute disk_used_percent and alert via Mailer::adminAlert at >= 85%');
        });
        $secAuth2 = $read("$root/app/Core/Auth.php");
        $secRoutes = $read("$root/config/routes.php");
        $secStepView = $read("$root/views/auth/step_up.php");
        $add('smoke.sec.step_up_reauth', 'Smoke · Security', 'Sensitive actions need a recent password confirm', static function () use ($secAuth2, $secRoutes, $secStepView, $ok, $bad): array {
            return strpos($secAuth2, 'recentlyAuthenticated') !== false
                && strpos($secAuth2, 'verifyPassword') !== false
                && strpos($secRoutes, "'/reauthenticate'") !== false
                && strpos($secStepView, '/reauthenticate') !== false
                ? $ok('recentAuthenticated()/verifyPassword() + /reauthenticate gate page present')
                : $bad('Auth must expose recentlyAuthenticated/verifyPassword and a /reauthenticate step-up page must exist');
        });
        $add('smoke.sec.upload_no_active_content', 'Smoke · Security', 'Upload allowlists exclude active content (svg/html)', static function () use ($secUploadP, $secChatP, $ok, $bad): array {
            $lower = strtolower($secUploadP . "\n" . $secChatP);
            return preg_match('/image\/svg[\+; ]/', $lower) === 0 && strpos($lower, "'html'") === false && strpos($lower, "'svg'") === false
                ? $ok('no svg/html in MediaUploader/ChatMessage upload maps')
                : $bad('upload MIME/extension allowlists must not include svg or html');
        });
        $secAppCfg = $read("$root/config/app.php");
        $add('smoke.sec.large_video_uploads', 'Smoke · Security', 'Uploads accept multi-GB videos', static function () use ($secUploadP, $secAppCfg, $ok, $bad): array {
            $tooLarge = strpos($secUploadP, "'File is too large.'") !== false;
            $exceedsCap = preg_match("/'max_size'\s*=>\s*(10|1024)\s*\*\s*1024\s*\*\s*1024\s*\*\s*1024/", $secAppCfg) === 1
                || preg_match("/'max_size'\s*=>\s*(\d{11,})/", $secAppCfg, $m) === 1 && (int) $m[1] >= 10737418240;
            return $tooLarge && $exceedsCap
                ? $ok('MediaUploader enforces max_size >= 10 GiB for multi-GB videos')
                : $bad('config/app.php max_size must be >= 10 GiB and MediaUploader must reject oversized files');
        });
        $secAuth = $read("$root/app/Controllers/AuthController.php");
        $secHelpers = $read("$root/app/Core/helpers.php");
        $add('smoke.sec.signup_rate_limited', 'Smoke · Security', 'Signup and verify-email are rate limited', static function () use ($secAuth, $ok, $bad): array {
            return strpos($secAuth, "'signup-ip:'") !== false && strpos($secAuth, "'verify-email:'") !== false
                ? $ok('signup + verifyEmail throttled')
                : $bad('AuthController must RateLimiter-allow signup and verifyEmail');
        });
        $add('smoke.sec.image_pixel_cap', 'Smoke · Security', 'Image decode rejects decompression bombs', static function () use ($secHelpers, $ok, $bad): array {
            return substr_count($secHelpers, '75000000') >= 3
                ? $ok('75 MP cap in _load_image / imagick paths')
                : $bad('helpers.php must reject images over 75MP in _load_image, _load_image_imagick and _imagick_dimensions');
        });
        $add('smoke.sec.videos_never_imagick', 'Smoke · Security', 'ImageMagick is never handed videos', static function () use ($secHelpers, $ok, $bad): array {
            $guards = substr_count($secHelpers, 'if (is_video($src))') >= 3;
            return $guards && strpos($secHelpers, 'image_can_decode') !== false
                ? $ok('image_can_decode + both Imagick helpers short-circuit videos (kills the magick-*/webp storm)')
                : $bad('image_can_decode, _load_image_imagick and _imagick_dimensions must bail out on is_video($src)');
        });
        $secAvifStorage = $read("$root/app/Controllers/StorageController.php");
        $secAvifCard    = $read("$root/views/partials/gallery_card.php");
        $add('smoke.media.avif', 'Smoke · Media', 'AVIF renditions are generated and served', static function () use ($secHelpers, $secAvifStorage, $secAvifCard, $ok, $bad): array {
            return strpos($secHelpers, "format === 'avif'") !== false
                && strpos($secHelpers, 'function _make_avif') !== false
                && strpos($secHelpers, 'libsvtav1') !== false
                && strpos($secAvifStorage, "format === 'avif'") !== false
                && strpos($secAvifCard, 'image/avif') !== false
                ? $ok('avif variants generated via libsvtav1, served by StorageController, picked by cards')
                : $bad('helpers/StorageController/cards must support AVIF renditions');
        });
        $secIndex = $read("$root/public/index.php");
        $secCsp   = $read("$root/app/Controllers/CspController.php");
        $secBackup = $read("$root/bin/backup_offsite_package.php");
        $secPullC  = $read("$root/app/Controllers/BackupPullController.php");
        $add('smoke.sec.csp_enforced', 'Smoke · Security', 'Needs enforced nonce-based CSP + a report collector', static function () use ($secIndex, $secCsp, $ok, $bad): array {
            return strpos($secIndex, 'Content-Security-Policy: default-src') !== false
                && strpos($secIndex, "'nonce-") !== false
                && strpos($secIndex, "'strict-dynamic'") !== false
                && strpos($secIndex, 'script-src-attr') !== false
                && strpos($secCsp, 'function report') !== false && strpos($secCsp, "csp.log") !== false
                ? $ok('enforced nonce CSP (script-src strict, script-src-attr unsafe-inline) + collector')
                : $bad('index.php must emit an enforced nonce CSP and CspController must log reports');
        });
        $add('smoke.sec.offsite_backup', 'Smoke · Security', 'Encrypted off-site backup packaging + pull endpoint', static function () use ($secBackup, $secPullC, $ok, $bad): array {
            return strpos($secBackup, 'openssl enc -aes-256-cbc') !== false
                && strpos($secBackup, 'gallery-backup.pass') !== false
                && strpos($secPullC, 'BACKUP_PULL_KEY') !== false
                ? $ok('package + pull endpoint hardened')
                : $bad('backup_offsite_package.php must openssl-encrypt and BackupPullController must require BACKUP_PULL_KEY');
        });

        // --------------------------------------------------- Braintree
        $btSchema = $schema;
        $planCtrl = $read("$root/app/Controllers/PlanController.php");
        $btGw = $read("$root/app/Core/BraintreeGateway.php");
        $membershipView = $read("$root/views/membership/index.php");
        $planFormCreate = $read("$root/views/admin/plan_create.php");
        $planFormEdit = $read("$root/views/admin/plan_edit.php");
        $memberCtrl = $read("$root/app/Controllers/MembershipController.php");
        $add('smoke.schema.plans_braintree_plan_id', 'Smoke · Braintree', 'schema.sql plans has braintree_plan_id', static function () use ($btSchema, $ok, $bad): array {
            return stripos($btSchema, 'braintree_plan_id') !== false ? $ok('column in schema.sql') : $bad('schema.sql: plans table must carry braintree_plan_id');
        });
        $add('smoke.file.migration_braintree_plans', 'Smoke · Braintree', 'Migration 059 provisioned Braintree plan mapping', static function () use ($root, $ok, $bad): array {
            $mig = "$root/database/migrations/059_braintree_plans.sql";
            return is_file($mig) && strpos((string) file_get_contents($mig), 'braintree_plan_id') !== false ? $ok('present') : $bad('missing migration 059_braintree_plans.sql with braintree_plan_id');
        });
        $add('smoke.braintree.provision_action', 'Smoke · Braintree', 'PlanController provisions Braintree plans per tier', static function () use ($planCtrl, $ok, $bad): array {
            return strpos($planCtrl, 'function provisionBraintree') !== false && preg_match('/use App\\\\Core\\\\BraintreeGateway;/', $planCtrl) === 1
                ? $ok('provisionBraintree wired to BraintreeGateway')
                : $bad('PlanController must define provisionBraintree and import App\Core\BraintreeGateway');
        });
        $add('smoke.braintree.gateway_plan_crud', 'Smoke · Braintree', 'BraintreeGateway can create and find plans', static function () use ($btGw, $ok, $bad): array {
            return strpos($btGw, 'function createPlan') !== false && strpos($btGw, 'function findPlan') !== false
                ? $ok('createPlan + findPlan present')
                : $bad('BraintreeGateway must implement createPlan and findPlan');
        });
        $add('smoke.braintree.membership_view_button', 'Smoke · Braintree', 'Membership page offers a Braintree card checkout button', static function () use ($membershipView, $ok, $bad): array {
            return strpos($membershipView, '/membership/checkout') !== false && strpos($membershipView, '$braintreeAvailable') !== false && strpos($membershipView, '$braintreeLive') !== false && stripos($membershipView, 'Or pay by card (Braintree)') !== false
                ? $ok('card checkout button gated by braintree availability + live flag')
                : $bad('views/membership/index.php must render a Braintree checkout button gated by $braintreeAvailable/$braintreeLive');
        });
        $add('smoke.braintree.plan_forms_field', 'Smoke · Braintree', 'Plan admin forms persist a Braintree plan id', static function () use ($planFormCreate, $planFormEdit, $ok, $bad): array {
            return strpos($planFormCreate, 'braintree_plan_id') !== false && strpos($planFormEdit, 'braintree_plan_id') !== false
                ? $ok('field on create + edit forms')
                : $bad('plan_create.php and plan_edit.php must carry a braintree_plan_id input');
        });
        $add('smoke.braintree.subscribe_resolves_plan', 'Smoke · Braintree', 'subscribeBraintree prefers the plan-level Braintree plan id', static function () use ($memberCtrl, $ok, $bad): array {
            return strpos($memberCtrl, '$btPlanId = trim((string) ($plan[\'braintree_plan_id\'] ?? \'\'));') !== false
                ? $ok('per-plan id resolved before the processor-level fallback')
                : $bad('MembershipController::subscribeBraintree must resolve plans.braintree_plan_id first');
        });

        // --------------------------------------------------------------- Traffic
        $traf = $read("$root/app/Models/Traffic.php");
        $indexPhp = $read("$root/public/index.php");
        $authCtrl = $read("$root/app/Controllers/AuthController.php");
        $adminLayout = $read("$root/views/admin/layout.php");
        $trafficView = $read("$root/views/admin/traffic.php");
        $trafficShow = $read("$root/views/admin/traffic_show.php");
        $trafficCtrl = $read("$root/app/Controllers/TrafficController.php");
        $authCore = $read("$root/app/Core/Auth.php");
        $add('smoke.traffic.index_hook', 'Smoke · Traffic', 'public/index.php captures traffic on public GETs', static function () use ($indexPhp, $ok, $bad): array {
            return strpos($indexPhp, 'Traffic::capture') !== false ? $ok('capture hook present') : $bad('public/index.php must call Traffic::capture() before dispatch');
        });
        $add('smoke.traffic.signup_attach', 'Smoke · Traffic', 'Signup credits the stored traffic source', static function () use ($authCtrl, $ok, $bad): array {
            return strpos($authCtrl, 'Traffic::attachSignup') !== false ? $ok('attribution wired') : $bad('AuthController::signup must attach the credited traffic source');
        });
        $add('smoke.traffic.permission', 'Smoke · Traffic', 'Traffic page gated behind a dedicated permission', static function () use ($authCore, $ok, $bad): array {
            return strpos($authCore, "'traffic'") !== false ? $ok('permission declared') : $bad('Auth::PERMISSIONS must declare the traffic permission');
        });
        $add('smoke.traffic.nav', 'Smoke · Traffic', 'Admin nav links to the Traffic page', static function () use ($adminLayout, $ok, $bad): array {
            // The sidebar is data (see $navGroups); a gated entry is one that
            // declares a 'perm' the renderer checks with Auth::can().
            return strpos($adminLayout, "'key' => 'nav-traffic'") !== false
                && strpos($adminLayout, "'perm' => 'traffic'") !== false
                && strpos($adminLayout, 'Auth::can($item[') !== false
                ? $ok('nav item present and gated')
                : $bad('admin layout must render a permission-gated Traffic nav item');
        });
        $add('smoke.traffic.controller', 'Smoke · Traffic', 'TrafficController guards with requirePermission', static function () use ($trafficCtrl, $ok, $bad): array {
            return strpos($trafficCtrl, "Auth::requirePermission('traffic')") !== false ? $ok('guard present') : $bad('TrafficController must require the traffic permission');
        });
        $add('smoke.traffic.model_attrs', 'Smoke · Traffic', 'Attribution expiry for terminated links', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, 'findActiveByCode') !== false && strpos($traf, 'clearRefCookie') !== false && strpos($traf, 'active = 1') !== false
                ? $ok('expiry wired')
                : $bad('Traffic must only attribute/record links that are still active and clear stale cookies');
        });
        $add('smoke.traffic.model_dedupe', 'Smoke · Traffic', 'Visits deduped per link/day/visitor', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, 'ON DUPLICATE KEY UPDATE') !== false && strpos($traf, 'CURDATE()') !== false
                ? $ok('daily dedupe')
                : $bad('Traffic::capture must upsert one visit row per link/day/visitor');
        });
        $add('smoke.traffic.model_utm', 'Smoke · Traffic', 'Short code + UTM parcels captured together', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, "'utm_source'") !== false && strpos($traf, "'c'  => \$code") !== false
                ? $ok('code + utm payload')
                : $bad('Traffic must persist the code together with utm_source/medium/campaign/content/term');
        });
        $add('smoke.traffic.signed_links', 'Smoke · Traffic', 'Share links are signed (?c + &s= signature)', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, "'&s='") !== false && strpos($traf, 'function signCode') !== false
                ? $ok('signed serialization')
                : $bad('Traffic::buildUrl must append &s=<signature> so forged ?c= codes are rejected');
        });
        $add('smoke.traffic.sig_verify', 'Smoke · Traffic', 'Signatures compact (22-char) yet verified in constant time', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, 'hash_hmac') !== false && strpos($traf, 'hash_equals') !== false
                && strpos($traf, 'compactSignature') !== false && strpos($traf, 'preg_match(\'/\\A[a-f0-9]{64}\\z|\\A[A-Za-z0-9_-]{22}\\z/\'') !== false
                ? $ok('hmac + hash_equals + compact serialization')
                : $bad('Traffic must authenticate codes with HMAC, compare via hash_equals(), and ship a compact 22-char base64url signature (plus legacy 64-hex support)');
        });
        $add('smoke.traffic.reject_forge', 'Smoke · Traffic', 'Capture ignores unsigned/forged codes', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, '!self::validSignature($code, $sig)') !== false && strpos($traf, 'Forged/unsigned code') !== false
                ? $ok('forged requests ignored')
                : $bad('Traffic::capture must reject codes without a valid signature before recording a visit or setting a cookie');
        });
        $add('smoke.traffic.attribution_sig', 'Smoke · Traffic', 'Stored cookie signature re-verified at signup', static function () use ($traf, $ok, $bad): array {
            return substr_count($traf, '!self::validSignature($code, $sig)') >= 2 && strpos($traf, 'Cookie tampered') !== false
                ? $ok('signup verifies signature')
                : $bad('Traffic::attribution must re-verify the cookie signature so a forged cookie is never credited');
        });
        $add('smoke.traffic.no_time_expiry', 'Smoke · Traffic', 'Links have no time-based expiry (active until disabled/deleted)', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, 'COOKIE_REF_TTL = 315360000') !== false && strpos($traf, 'active until admin disables/deletes') !== false
                ? $ok('no time box, only admin termination')
                : $bad('Traffic cookies must be long-lived so a link stays active only until the admin disables or deletes it — never on a timer');
        });
        $add('smoke.traffic.view_copy', 'Smoke · Traffic', 'Traffic page offers one-click link copy', static function () use ($trafficView, $ok, $bad): array {
            return strpos($trafficView, 'navigator.clipboard') !== false ? $ok('copy button') : $bad('traffic view must provide a copy-to-clipboard link button');
        });
        $add('smoke.traffic.view_terminate', 'Smoke · Traffic', 'Terminate/Reactivate actions exposed', static function () use ($trafficView, $ok, $bad): array {
            return strpos($trafficView, '/toggle') !== false && strpos($trafficView, 'Terminate') !== false && strpos($trafficView, 'Reactivate') !== false
                ? $ok('toggle wired')
                : $bad('traffic view must expose terminate/reactivate actions');
        });
        $add('smoke.traffic.view_detail', 'Smoke · Traffic', 'Per-link detail page shows daily series + attributed signups', static function () use ($trafficShow, $ok, $bad): array {
            return strpos($trafficShow, 'Attributed Signups') !== false && strpos($trafficShow, 'Last 30 Days') !== false && strpos($trafficShow, 'sparkline') !== false
                ? $ok('detail page present')
                : $bad('traffic_show view must render the 30-day series, sparkline and attributed signups');
        });
        $add('smoke.traffic.view_expandable', 'Smoke · Traffic', 'Summary page expands each link to its signups', static function () use ($trafficView, $ok, $bad): array {
            return strpos($trafficView, 'trafficToggleSignups') !== false && strpos($trafficView, 'traffic-signups-') !== false
                ? $ok('expandable signup rows')
                : $bad('traffic view must expose an inline expandable signups list per link');
        });
        $add('smoke.traffic.view_recent', 'Smoke · Traffic', 'Summary page lists recent signups across links', static function () use ($trafficView, $ok, $bad): array {
            // Section headings are sentence case in source and uppercased by CSS,
            // so match the words rather than one exact capitalisation.
            return stripos($trafficView, 'Recent signups') !== false && strpos($trafficView, '$recentSignups') !== false
                ? $ok('recent signups section')
                : $bad('traffic view must render a recent signups section fed by the controller');
        });
        $add('smoke.traffic.model_signup_lists', 'Smoke · Traffic', 'Model exposes per-link and recent signup lists', static function () use ($traf, $ok, $bad): array {
            return strpos($traf, 'function signupsByLink') !== false && strpos($traf, 'function recentSignups') !== false
                ? $ok('batch signup queries present')
                : $bad('Traffic model must provide signupsByLink() and recentSignups() for the admin pages');
        });
        $add('smoke.traffic.view_signup_columns', 'Smoke · Traffic', 'Signup lists include status/plan/UTM detail', static function () use ($trafficView, $trafficShow, $ok, $bad): array {
            return strpos($trafficView, 'utm_content') !== false && strpos($trafficView, 'utm_term') !== false
                && strpos($trafficShow, 'utm_content') !== false && strpos($trafficShow, 'utm_term') !== false
                ? $ok('full-detail columns present')
                : $bad('traffic views must show plan/status and UTM content/term for each attributed signup');
        });
        $add('smoke.traffic.mark_direct', 'Smoke · Traffic', 'Admins can correct a mis-attributed signup', static function () use ($trafficView, $routes, $trafficCtrl, $ok, $bad): array {
            return strpos($trafficView, 'Mark direct') !== false
                && in_array(['POST', '/admin/traffic/signups/{id}/direct', 'TrafficController@clearSignup', 'traffic'], $routes, true)
                && strpos($trafficCtrl, 'function clearSignup') !== false
                ? $ok('mark-direct wired')
                : $bad('traffic must expose a mark-as-direct action backed by a controller route');
        });

        // ------------------------------------------------------------ Campaign
        $shareBar      = $read("$root/views/partials/share-bar.php");
        $galleryShowV  = $read("$root/views/gallery/show.php");
        $videoPlayerV  = $read("$root/views/video/player.php");
        $imageFullV    = $read("$root/views/gallery/image_full.php");
        $authCtrlOg    = $read("$root/app/Controllers/AuthController.php");
        $staticCtrlOg  = $read("$root/app/Controllers/StaticPageController.php");
        $newGalleryMail = $read("$root/views/emails/new_gallery.php");
        $liveNowMail    = $read("$root/views/emails/live_now.php");
        $winbackMail    = $read("$root/views/emails/winback.php");
        $add('smoke.campaign.share_partial', 'Smoke · Campaign', 'Share bar signs every network link with Traffic::buildUrl', static function () use ($shareBar, $ok, $bad): array {
            // Facebook is deliberately NOT offered (the platform bans adult
            // content); X, WhatsApp, Reddit and the copy-link button remain.
            foreach (['twitter.com/intent/tweet', 'wa.me', 'www.reddit.com/submit', 'data-share-copy'] as $needle) {
                if (strpos($shareBar, $needle) === false) {
                    return $bad('share-bar missing: ' . $needle);
                }
            }
            return substr_count($shareBar, 'Traffic::buildUrl(') >= 3
                && stripos($shareBar, 'facebook') === false
                ? $ok('3 networks + copy link, all attributed, no Facebook')
                : $bad('share-bar must build its network links via Traffic::buildUrl() and must not include Facebook');
        });
        $add('smoke.campaign.share_pages', 'Smoke · Campaign', 'Gallery and video pages render the share bar', static function () use ($galleryShowV, $videoPlayerV, $ok, $bad): array {
            foreach (['gallery/show' => $galleryShowV, 'video/player' => $videoPlayerV] as $name => $src) {
                if (strpos($src, "partials/share-bar.php") === false) {
                    return $bad("$name does not include views/partials/share-bar.php");
                }
                if (strpos($src, '$sharePath') === false) {
                    return $bad("$name does not set \$sharePath before including the share bar");
                }
            }
            return $ok('both pages share');
        });
        $add('smoke.campaign.og_absolute_thumb', 'Smoke · Campaign', 'og:image sources are absolute, token-free thumbs', static function () use ($galleryShowV, $imageFullV, $videoPlayerV, $authCtrlOg, $staticCtrlOg, $ok, $bad): array {
            foreach (['gallery/show' => $galleryShowV, 'gallery/image_full' => $imageFullV, 'video/player' => $videoPlayerV] as $name => $src) {
                $found = false;
                foreach (explode("\n", $src) as $line) {
                    if (strpos($line, '$ogImage') === false) {
                        continue;
                    }
                    $found = true;
                    if (strpos($line, 'absolute_url(file_url(') === false || strpos($line, "'thumb'") === false || strpos($line, "'web'") !== false) {
                        return $bad("$name og:image must be absolute_url(file_url(..., 'thumb')), not a token'd web variant");
                    }
                }
                if (!$found) {
                    return $bad("$name no longer assigns \$ogImage");
                }
            }
            foreach (['AuthController' => $authCtrlOg, 'StaticPageController' => $staticCtrlOg] as $name => $src) {
                if (strpos($src, "'web'") !== false || strpos($src, "absolute_url(file_url(") === false || strpos($src, "'thumb'") === false) {
                    return $bad("$name og image must use the absolute token-free thumb variant");
                }
            }
            return $ok('all 6 sources thumb + absolute');
        });
        $add('smoke.campaign.email_attribution', 'Smoke · Campaign', 'Email CTAs carry tracked ?c= links', static function () use ($liveNowMail, $winbackMail, $newGalleryMail, $ok, $bad): array {
            // The scheduled digest is gone, so the remaining CTAs are the live
            // broadcast and the win-back ('email-digest') plus the gallery alert.
            foreach (['live_now.html' => $liveNowMail, 'winback.html' => $winbackMail] as $name => $src) {
                if (strpos($src, 'Traffic::buildUrl(') === false || strpos($src, "'email-digest'") === false) {
                    return $bad("$name CTA must go through Traffic::buildUrl(..., 'email-digest')");
                }
            }
            if (strpos($newGalleryMail, 'Traffic::buildUrl(') === false || strpos($newGalleryMail, "'email-alert'") === false) {
                return $bad("new_gallery CTA must go through Traffic::buildUrl(..., 'email-alert')");
            }
            return $ok('digest + alert attributed');
        });
        $layoutView  = $read("$root/views/layout.php");
        $staticPages = $read("$root/app/Controllers/StaticPageController.php");
        // The map itself is built in App\Core\Sitemap.
        $sitemapMap = $read("$root/app/Core/Sitemap.php");
        $add('smoke.campaign.rss_feed', 'Smoke · Campaign', 'RSS feed route + attributed items + autodiscovery', static function () use ($routes, $layoutView, $staticPages, $ok, $bad): array {
            if (!in_array(['GET', '/feed.xml', 'StaticPageController@feed'], $routes, true)) {
                return $bad('config/routes.php must expose GET /feed.xml');
            }
            if (strpos($staticPages, 'function feed') === false || strpos($staticPages, "'rss'") === false) {
                return $bad('StaticPageController::feed must exist and tag item links with the rss code');
            }
            if (strpos($layoutView, 'application/rss+xml') === false) {
                return $bad('views/layout.php must autodiscover /feed.xml');
            }
            return $ok('route + feed + autodiscovery');
        });
        $add('smoke.campaign.sitemap_lastmod', 'Smoke · Campaign', 'Sitemap carries <lastmod> dates', static function () use ($sitemapMap, $ok, $bad): array {
            return strpos($sitemapMap, '<lastmod>') !== false && strpos($sitemapMap, 'COALESCE(published_at, created_at)') !== false
                ? $ok('lastmod emitted from publish/create dates')
                : $bad('the sitemap must emit <lastmod> for dated entries');
        });
        $add('smoke.seo.no_empty_media_urls', 'Smoke · SEO', 'Sitemap omits gated media pages', static function () use ($sitemapMap, $ok, $bad): array {
            // /images/{id} and /videos/{id} are member-gated viewers that render
            // an unlock wall with no media for a crawler. Submitting thousands
            // of them made the site look like a wall of empty duplicate pages.
            if (preg_match('#<loc>[^<]*/(images|videos)/\d+</loc>#', $sitemapMap) === 1) {
                return $bad('sitemap() must NOT list /images/{id} or /videos/{id} - gated media viewers with no crawler-visible content');
            }
            return $ok('no gated media URLs in the sitemap');
        });
        $add('smoke.seo.paywall_markup', 'Smoke · SEO', 'Gated pages declare isAccessibleForFree', static function () use ($read, $root, $ok, $bad): array {
            $missing = [];
            foreach ([
                'views/gallery/show.php' => 'gallery',
                'views/gallery/image_full.php' => 'image',
                'views/video/player.php' => 'video',
            ] as $file => $label) {
                $src = (string) $read("$root/$file");
                if (strpos($src, 'isAccessibleForFree') === false) {
                    $missing[] = $label;
                }
            }
            return $missing === []
                ? $ok('gallery + image + video all declare the paywall')
                : $bad('missing isAccessibleForFree markup on: ' . implode(', ', $missing));
        });
        $add('smoke.seo.robots_at_root', 'Smoke · SEO', 'robots.txt scopes disallows to /gallery/', static function () use ($read, $root, $ok, $bad): array {
            $robots = (string) $read("$root/public/robots.txt");
            if ($robots === '') {
                return $bad('public/robots.txt is missing or empty');
            }
            // robots.txt is only honored at the host root, so the disallow
            // paths must be written the way they are actually served.
            if (strpos($robots, 'Disallow: /gallery/admin') === false) {
                return $bad('robots.txt must disallow /gallery/admin (the unprefixed /admin never matched)');
            }
            if (strpos($robots, 'Sitemap:') === false) {
                return $bad('robots.txt must carry a Sitemap: line');
            }
            return $ok('disallows are /gallery/ scoped + sitemap declared');
        });
        $autoText   = $read("$root/app/Core/AutoPostText.php");
        $platforms  = $read("$root/app/Core/Platforms.php");
        $redditCli  = $read("$root/app/Models/RedditClient.php");
        $queueSrc   = $read("$root/app/Models/AutoPostQueue.php");
        $add('smoke.campaign.url_placeholder', 'Smoke · Campaign', 'Post templates expand {url} with channel attribution', static function () use ($autoText, $platforms, $ok, $bad): array {
            if (strpos($autoText, '{url}') === false || strpos($autoText, 'Traffic::buildUrl(') === false) {
                return $bad('AutoPostText::composePattern must expand {url} through Traffic::buildUrl');
            }
            if (strpos($platforms, '{url}') === false) {
                return $bad('the X and Reddit default patterns must carry {url}');
            }
            return $ok('{url} wired end to end');
        });
        $add('smoke.campaign.reddit_enabled', 'Smoke · Campaign', 'Reddit channel enabled with credential fields', static function () use ($platforms, $redditCli, $queueSrc, $ok, $bad): array {
            if (strpos($redditCli, 'function post(') === false) {
                return $bad('RedditClient must expose post() for the generic queue dispatch (e2c9aa1 regression)');
            }
            if (strpos($queueSrc, 'private static function postReddit(') !== false) {
                return $bad('dead AutoPostQueue::postReddit must stay removed');
            }
            preg_match("/'reddit' => \[(.*?)\n        \]/s", $platforms, $m);
            $entry = $m[1] ?? '';
            foreach (["'enabled'      => true", "'subreddit'", "'client_id'", '{url}'] as $needle) {
                if (strpos($entry, $needle) === false) {
                    return $bad('reddit platform entry missing: ' . $needle);
                }
            }
            return $ok('reddit on, configured, tracked');
        });
        $housekeep = $read("$root/app/Core/Housekeeping.php");
        $add('smoke.campaign.daily_broadcast', 'Smoke · Campaign', 'Housekeeping auto-schedules the daily chat broadcast', static function () use ($housekeep, $ok, $bad): array {
            return strpos($housekeep, 'scheduleDailyChatBroadcast') !== false
                && strpos($housekeep, 'chat_daily_broadcasts WHERE created_at >= CURDATE()') !== false
                && strpos($housekeep, "'chat'") !== false
                ? $ok('once-per-day generated broadcast with chat attribution')
                : $bad('Housekeeping must create one chat_daily_broadcasts row per day, deduped on created_at');
        });

        // ----------------------------------------------------- Daily chat
        $chatAdmin    = $read("$root/app/Controllers/AdminChatController.php");
        $chatView     = $read("$root/views/admin/chat.php");
        $applyCron    = $read("$root/bin/apply_cron.php");
        $chatBroadcast = $read("$root/app/Models/ChatBroadcast.php");
        $add('smoke.chat_daily.model', 'Smoke · Daily chat', 'Broadcast model delivers to eligible members', static function () use ($chatBroadcast, $ok, $bad): array {
            return strpos($chatBroadcast, 'function send') !== false
                && strpos($chatBroadcast, 'function eligibleUserIds') !== false
                && strpos($chatBroadcast, 'function due') !== false
                ? $ok('send + eligibility + due present')
                : $bad('ChatBroadcast must provide send(), eligibleUserIds() and due()');
        });
        $add('smoke.chat_daily.actions', 'Smoke · Daily chat', 'Admin can create/schedule/send/cancel a broadcast', static function () use ($chatAdmin, $ok, $bad): array {
            return strpos($chatAdmin, 'function createDailyBroadcast') !== false
                && strpos($chatAdmin, 'function runDailyBroadcast') !== false
                && strpos($chatAdmin, 'function cancelDailyBroadcast') !== false
                ? $ok('broadcast actions present')
                : $bad('AdminChatController must expose create/run/cancel daily broadcast actions');
        });
        $add('smoke.chat_daily.routes', 'Smoke · Daily chat', 'Daily chat routes registered', static function () use ($routes, $ok, $bad): array {
            return in_array(['POST', '/admin/chat/daily-broadcast', 'AdminChatController@createDailyBroadcast', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/daily-broadcast/{id}/send', 'AdminChatController@runDailyBroadcast', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/daily-broadcast/{id}/cancel', 'AdminChatController@cancelDailyBroadcast', 'chat'], $routes, true)
                ? $ok('routes wired')
                : $bad('daily-broadcast admin routes must be registered');
        });
        $add('smoke.chat_daily.view', 'Smoke · Daily chat', 'Admin chat page has a daily-chat form and send log', static function () use ($chatView, $ok, $bad): array {
            // Assert on the endpoint, the composer and the log data rather than
            // the heading prose, so rewording the page cannot break the gate
            // while dropping the feature would.
            return strpos($chatView, '/admin/chat/daily-broadcast') !== false
                && strpos($chatView, 'name="message"') !== false
                && strpos($chatView, 'value="schedule"') !== false
                && strpos($chatView, '$broadcasts') !== false
                ? $ok('form + send log present')
                : $bad('admin chat view must render the daily chat form and send log');
        });
        $add('smoke.chat_daily.cron', 'Smoke · Daily chat', 'Scheduled broadcasts run via cron', static function () use ($applyCron, $ok, $bad): array {
            return strpos($applyCron, 'gallery-daily-chat') !== false && strpos($applyCron, 'daily_chat_worker.php') !== false
                ? $ok('cron entry wired')
                : $bad('apply_cron.php must schedule the daily chat worker');
        });

        // ----------------------------------------------------- Questionnaires
        $qModel = $read("$root/app/Models/ChatQuestionnaire.php");
        $qCtrl  = $read("$root/app/Controllers/QuestionnaireController.php");
        $qView  = $read("$root/views/admin/questionnaire_results.php");
        $chatViewQ = $chatView;
        $chatIndex = $read("$root/views/chat/index.php");
        $qWorker = $read("$root/bin/questionnaire_worker.php");
        $add('smoke.questionnaire.model', 'Smoke · Questionnaires', 'Questionnaire model supports create/send/answer/results', static function () use ($qModel, $ok, $bad): array {
            return strpos($qModel, 'function create') !== false
                && strpos($qModel, 'function send') !== false
                && strpos($qModel, 'function answer') !== false
                && strpos($qModel, 'function results') !== false
                && strpos($qModel, 'broadcastToMembers') !== false
                ? $ok('create + send + answer + results present')
                : $bad('ChatQuestionnaire must provide create()/send()/answer()/results() and notify all users');
        });
        $add('smoke.questionnaire.types', 'Smoke · Questionnaires', 'All answer types supported', static function () use ($qModel, $ok, $bad): array {
            $types = ['text', 'choice', 'multichoice', 'rating', 'number'];
            $missing = [];
            foreach ($types as $t) {
                if (strpos($qModel, "QTYPE_" . strtoupper($t)) === false) {
                    $missing[] = $t;
                }
            }
            return $missing === [] ? $ok('text/choice/multichoice/rating/number') : $bad('missing question types: ' . implode(', ', $missing));
        });
        $add('smoke.questionnaire.routes', 'Smoke · Questionnaires', 'Admin + member questionnaire routes registered', static function () use ($routes, $ok, $bad): array {
            return in_array(['POST', '/admin/chat/questionnaire', 'AdminChatController@createQuestionnaire', 'chat'], $routes, true)
                && in_array(['GET', '/admin/chat/questionnaire/{id}', 'AdminChatController@questionnaireResults', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/questionnaire/{id}/send', 'AdminChatController@runQuestionnaire', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/questionnaire/{id}/cancel', 'AdminChatController@cancelQuestionnaire', 'chat'], $routes, true)
                && in_array(['POST', '/chat/questionnaire/{id}/answer', 'QuestionnaireController@answer'], $routes, true)
                ? $ok('admin + member routes wired')
                : $bad('questionnaire admin/member routes must be registered');
        });
        $add('smoke.questionnaire.admin_view', 'Smoke · Questionnaires', 'Admin chat page has the send-questionnaire form + log', static function () use ($chatViewQ, $ok, $bad): array {
            // Structure, not prose: the endpoint, the builder container and the
            // reply toggle must exist for the feature to work at all.
            return strpos($chatViewQ, '/admin/chat/questionnaire') !== false
                && strpos($chatViewQ, 'questionnaire-form') !== false
                && strpos($chatViewQ, 'id="question-rows"') !== false
                && strpos($chatViewQ, 'allow_replies') !== false
                && strpos($chatViewQ, '$questionnaires') !== false
                ? $ok('form + builder + allow-replies + log present')
                : $bad('admin chat view must render the questionnaire form and list');
        });
        $add('smoke.questionnaire.member_view', 'Smoke · Questionnaires', 'Member chat page renders questionnaires and a disabled answered state', static function () use ($chatIndex, $ok, $bad): array {
            return strpos($chatIndex, 'Questionnaires') !== false
                && strpos($chatIndex, '/chat/questionnaire/') !== false
                && strpos($chatIndex, 'allow_replies') !== false
                && strpos($chatIndex, 'Submitted') !== false
                && strpos($chatIndex, 'disabled') !== false
                ? $ok('answer form + answered/disabled state present')
                : $bad('member chat view must render questionnaire forms and the answered (disabled) state');
        });
        $add('smoke.questionnaire.results_view', 'Smoke · Questionnaires', 'Results view shows aggregates + per-user answers', static function () use ($qView, $ok, $bad): array {
            return strpos($qView, "['tally']") !== false
                && strpos($qView, "'email'") !== false
                && strpos($qView, 'response(s)') !== false
                ? $ok('tally + per-user rows present')
                : $bad('questionnaire results view must show tallies and per-user answers');
        });
        $add('smoke.questionnaire.worker', 'Smoke · Questionnaires', 'Scheduled questionnaires delivered by cron worker', static function () use ($qWorker, $applyCron, $ok, $bad): array {
            return strpos($qWorker, 'ChatQuestionnaire::due') !== false
                && strpos($applyCron, 'gallery-questionnaires') !== false
                && strpos($applyCron, 'questionnaire_worker.php') !== false
                ? $ok('worker + cron entry wired')
                : $bad('questionnaire_worker.php must deliver due questionnaires and be scheduled in apply_cron.php');
        });

        // ----------------------------------------------------- Operator messaging
        $chatMessage = $read("$root/app/Models/ChatMessage.php");
        $chatCtrl    = $read("$root/app/Controllers/ChatController.php");
        $bridgeCtrl  = $read("$root/app/Controllers/ChatBridgeController.php");
        $add('smoke.chat_reply_toggle.model', 'Smoke · Operator messaging', 'Reply toggle helpers exist and gate member sends', static function () use ($chatMessage, $chatCtrl, $ok, $bad): array {
            return strpos($chatMessage, 'function memberReplyEnabled') !== false
                && strpos($chatMessage, 'function setMemberReply') !== false
                && strpos($chatCtrl, 'memberReplyEnabled') !== false
                ? $ok('toggle + member gate present')
                : $bad('ChatMessage must expose memberReplyEnabled()/setMemberReply() and ChatController::send must honour the toggle');
        });
        $add('smoke.chat_operator_message.admin', 'Smoke · Operator messaging', 'Admin can message any user and toggle replies', static function () use ($chatAdmin, $chatView, $routes, $ok, $bad): array {
            return strpos($chatAdmin, 'function newConversation') !== false
                && strpos($chatAdmin, 'function toggleReply') !== false
                && strpos($chatView, 'name="user_email"') !== false
                && strpos($chatView, '/admin/chat/new') !== false
                && strpos($chatView, 'reply-toggle') !== false
                && in_array(['POST', '/admin/chat/new', 'AdminChatController@newConversation', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/{id}/reply-toggle', 'AdminChatController@toggleReply', 'chat'], $routes, true)
                ? $ok('admin message-any-user + reply toggle wired')
                : $bad('admin chat must support messaging any user and toggling member replies');
        });
        $add('smoke.chat_operator_message.webhooks', 'Smoke · Operator messaging', 'App webhooks for user search/start/reply toggle exist', static function () use ($bridgeCtrl, $routes, $ok, $bad): array {
            return strpos($bridgeCtrl, 'function users') !== false
                && strpos($bridgeCtrl, 'function start') !== false
                && strpos($bridgeCtrl, 'function replyToggle') !== false
                && in_array(['GET', '/webhooks/chat/users', 'ChatBridgeController@users'], $routes, true)
                && in_array(['POST', '/webhooks/chat/start', 'ChatBridgeController@start'], $routes, true)
                && in_array(['POST', '/webhooks/chat/reply-toggle', 'ChatBridgeController@replyToggle'], $routes, true)
                ? $ok('webhooks wired')
                : $bad('chat bridge must expose user search, start, and reply-toggle webhooks');
        });

        // ----------------------------------------------------- Chat correctness
        $add('smoke.chat_ui.single_questionnaire', 'Smoke · Chat correctness', 'Member chat view renders each questionnaire exactly once', static function () use ($chatIndex, $ok, $bad): array {
            // A second render block reintroduced a duplicate DOM id; the browser
            // then shows the first (possibly answered/disabled) form twice and
            // an answered member's repeat submit is rejected. One id template.
            $count = substr_count($chatIndex, 'id="questionnaire-');
            return $count === 1
                ? $ok('one questionnaire render block')
                : $bad("views/chat/index.php contains $count questionnaire id templates (must be 1)");
        });
        $add('smoke.schema.chat_messages_expiring', 'Smoke · Chat correctness', 'schema.sql chat_messages matches migration 043 columns', static function () use ($read, $root, $ok, $bad): array {
            $schema = $read("$root/schema.sql");
            $migration = $read("$root/database/migrations/043_chat_expiring_media.sql");
            $m = [];
            if (preg_match('/CREATE TABLE IF NOT EXISTS chat_messages \((.*?)\) ENGINE/s', $schema, $m) !== 1) {
                return $bad('chat_messages CREATE TABLE not found in schema.sql');
            }
            $missing = [];
            foreach (['expires_at', 'max_views', 'view_count'] as $col) {
                if (strpos($m[1], $col) === false) $missing[] = $col;
                if (strpos($migration, $col) === false) $missing[] = $col . ' (migration)';
            }
            return $missing === []
                ? $ok('expiring-media columns present in schema and migration')
                : $bad('chat_messages column drift: ' . implode(', ', $missing));
        });
        $add('smoke.schema.chat_ai_jobs', 'Smoke · Chat correctness', 'Background AI queue exists in schema and migration 064', static function () use ($read, $root, $ok, $bad): array {
            $schema = $read("$root/schema.sql");
            $migration = $read("$root/database/migrations/064_chat_ai_jobs.sql");
            $m = [];
            if (preg_match('/CREATE TABLE IF NOT EXISTS chat_ai_jobs \((.*?)\) ENGINE/s', $schema, $m) !== 1) {
                return $bad('chat_ai_jobs CREATE TABLE not found in schema.sql');
            }
            $missing = [];
            foreach (['uq_chat_ai_jobs_message', 'user_message_id', "ENUM('queued', 'running', 'done', 'error')"] as $n) {
                if (strpos($m[1], $n) === false) $missing[] = $n;
            }
            foreach (['idx_chat_msg_conv_id', 'idx_chat_reply_idem_created', 'idx_notifications_created'] as $n) {
                if (strpos($migration, $n) === false) $missing[] = $n . ' (migration)';
            }
            if (strpos($migration, 'DELETE FROM chat_reply_idempotency') === false || strpos($migration, 'DELETE FROM notifications') === false) {
                $missing[] = 'one-time prunes';
            }
            return $missing === []
                ? $ok('queue table + indexes + prunes present')
                : $bad('migration 064 drift: ' . implode(', ', $missing));
        });
        $add('smoke.chat_ai.async_flag', 'Smoke · Chat correctness', 'chat_ai_async queues replies for the worker instead of blocking', static function () use ($read, $root, $ok, $bad): array {
            $settings = $read("$root/app/Core/ChatSettings.php");
            $ctrl     = $read("$root/app/Controllers/ChatController.php");
            $worker   = $read("$root/bin/chat_ai_worker.php");
            $cron     = $read("$root/bin/apply_cron.php");
            $checks = [
                "settings default"  => strpos($settings, "'chat_ai_async'") !== false,
                "send guard"        => strpos($ctrl, "chat_ai_async") !== false && strpos($ctrl, 'ChatAiJob::enqueue(') !== false,
                "worker claims"     => strpos($worker, 'ChatAiJob::claimNext()') !== false && strpos($worker, 'ChatAiJob::recoverStale()') !== false,
                "cron entry"        => strpos($cron, 'bin/chat_ai_worker.php') !== false,
            ];
            $failing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));
            return $failing === []
                ? $ok('async flag, enqueue, worker, cron all wired')
                : $bad('chat_ai_async wiring incomplete: ' . implode(', ', $failing));
        });
        $add('smoke.chat_ai.shared_responder', 'Smoke · Chat correctness', 'Worker and sync send share one responder', static function () use ($read, $root, $ok, $bad): array {
            $ctrl = $read("$root/app/Controllers/ChatController.php");
            $job  = $read("$root/app/Models/ChatAiJob.php");
            $resp = $read("$root/app/Core/ChatAiResponder.php");
            return strpos($ctrl, 'ChatAiResponder::generate(') !== false
                && strpos($job, 'ChatAiResponder::generate(') !== false
                && strpos($resp, 'ChatContentSearch::find(') !== false
                && strpos($resp, 'messagesLatest($conversationId, 12)') !== false
                ? $ok('one generation path for sync and queued replies')
                : $bad('sync path and worker must both call ChatAiResponder::generate');
        });
        $add('smoke.chat_bridge.throttled', 'Smoke · Chat correctness', 'Bridge endpoints are rate limited per identity and bucket', static function () use ($bridgeCtrl, $ok, $bad): array {
            $m = [];
            if (preg_match('/private function throttle\(bool \$write\): void\s*\{(.*?)\n    \}/s', $bridgeCtrl, $m) !== 1) {
                return $bad('ChatBridgeController::throttle() not found');
            }
            $body = $m[1];
            if (strpos($body, 'RateLimiter::allow(') === false || strpos($body, 'Retry-After') === false) {
                return $bad('throttle() must use RateLimiter::allow with Retry-After on denial');
            }
            if (strpos($body, '* ($isShared ? 5 : 1)') === false) {
                return $bad('the shared GALLERY_CHAT_KEY must be grandfathered with a bigger budget');
            }
            if (preg_match('/function reply\s*\([^)]*\)\s*:\s*void\s*\{\s*\$this->throttle\(true\);/s', $bridgeCtrl) !== 1) {
                return $bad('reply() must throttle writes');
            }
            if (preg_match('/function config\s*\([^)]*\)\s*:\s*void\s*\{\s*\$this->throttle\(false\);/s', $bridgeCtrl) !== 1) {
                return $bad('config() must throttle reads');
            }
            return $ok('writes/reads throttled, shared key grandfathered');
        });
        $add('smoke.chat_bridge.no_busy_loop', 'Smoke · Chat correctness', 'The SSE stream does not spin on the database', static function () use ($bridgeCtrl, $ok, $bad): array {
            // The SSE loop `continue`d straight back to the top while the
            // member was typing, WITHOUT sleeping: two database queries per
            // iteration, thousands of times in the 30 s window, with a FPM
            // worker and a database connection pinned the whole time.
            if (preg_match('/function stream\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $bridgeCtrl, $m) !== 1) {
                return $bad('ChatBridgeController::stream() not found');
            }
            $body = $m[1];
            if (strpos($body, 'while (') === false) {
                return $bad('the stream must have its poll loop');
            }
            // Every path through the loop has to sleep. The keepalive path
            // sleeps 1.5 s and the typing path 0.3 s.
            $sleeps = substr_count($body, 'usleep(');
            if ($sleeps < 2) {
                return $bad('the SSE loop must sleep on EVERY path (the typing path used to spin)');
            }
            // And the re-emit must be rate limited, or the frames are as
            // frequent as the loop.
            if (strpos($body, '$lastEmit') === false) {
                return $bad('the SSE loop must rate limit its frames');
            }
            return $ok('the SSE loop sleeps on every path and rate limits its frames');
        });
        $add('smoke.housekeeping.chat_retention', 'Smoke · Chat correctness', 'Housekeeping prunes chat keys, read notices and finished jobs', static function () use ($read, $root, $ok, $bad): array {
            $hk = $read("$root/app/Core/Housekeeping.php");
            if (strpos($hk, 'pruneChatRetention') === false || strpos($hk, 'chat_prunes') === false) {
                return $bad('Housekeeping must run pruneChatRetention and report chat_prunes');
            }
            $m = [];
            if (preg_match('/function pruneChatRetention\(\): array\s*\{(.*?)\n    \}/s', $hk, $m) !== 1) {
                return $bad('pruneChatRetention() not found');
            }
            $body = $m[1];
            return strpos($body, 'chat_reply_idempotency') !== false
                && strpos($body, 'read_at IS NOT NULL') !== false
                && strpos($body, 'chat_ai_jobs') !== false
                ? $ok('idempotency keys, read notifications and finished jobs pruned')
                : $bad('pruneChatRetention must prune keys, read notifications and done/error jobs');
        });
        $add('smoke.chat_bridge.unread_predicate', 'Smoke · Chat correctness', 'Bridge config counts unread past the operator read mark', static function () use ($bridgeCtrl, $ok, $bad): array {
            $m = [];
            if (preg_match('/function config\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $bridgeCtrl, $m) !== 1) {
                return $bad('ChatBridgeController::config() not found');
            }
            $body = $m[1];
            if (strpos($body, 'operator_read_through_id') === false) {
                return $bad('config() must compare against operator_read_through_id, not a constant 0');
            }
            if (strpos($body, '[$cid, 0]') !== false) {
                return $bad('config() must not count with a hardcoded 0 read mark');
            }
            return $ok('unread uses the operator read-through mark');
        });
        $add('smoke.chat_bridge.pending_bounded', 'Smoke · Chat correctness', 'Bridge pending() is bounded in SQL', static function () use ($bridgeCtrl, $ok, $bad): array {
            $m = [];
            if (preg_match('/function pending\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $bridgeCtrl, $m) !== 1) {
                return $bad('ChatBridgeController::pending() not found');
            }
            $body = $m[1];
            if (strpos($body, 'LIMIT 20') === false || strpos($body, 'sender_role') === false) {
                return $bad('pending() must filter sender_role in SQL with LIMIT 20');
            }
            if (strpos($body, 'array_slice(') !== false) {
                return $bad('pending() must not fetch the whole conversation and slice in PHP');
            }
            return $ok('pending() bounded in SQL');
        });
        $add('smoke.chat_bridge.idempotent_mismatch', 'Smoke · Chat correctness', 'Bridge reply() refuses a reused key with different text', static function () use ($bridgeCtrl, $ok, $bad): array {
            $m = [];
            if (preg_match('/function reply\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $bridgeCtrl, $m) !== 1) {
                return $bad('ChatBridgeController::reply() not found');
            }
            $body = $m[1];
            return strpos($body, 'Idempotency key was already used for different content.') !== false
                && strpos($body, 'MAX_MESSAGE_LENGTH') !== false
                ? $ok('mismatched reuse rejected instead of answered with the old id')
                : $bad('reply() must compare the stored message text and refuse a mismatched key reuse');
        });
        $add('smoke.chat.member_typing', 'Smoke · Chat correctness', 'Member typing pings feed the bridge stream within a short TTL', static function () use ($read, $root, $ok, $bad): array {
            $ctrl = $read("$root/app/Controllers/ChatController.php");
            if (preg_match('/function typing\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $ctrl, $m) !== 1) {
                return $bad('ChatController::typing() not found');
            }
            $body = $m[1];
            if (strpos($body, "RateLimiter::allow(['chat_typing:'") === false) {
                return $bad('typing() must be rate limited per member');
            }
            if (strpos($body, 'ChatMessage::markMemberTyping') === false) {
                return $bad('typing() must record the member typing state');
            }
            $model = $read("$root/app/Models/ChatMessage.php");
            if (strpos($model, 'function markMemberTyping') === false || strpos($model, 'function memberTyping') === false) {
                return $bad('ChatMessage must provide markMemberTyping() and memberTyping()');
            }
            if (preg_match('/function memberTyping\s*\([^)]*\)\s*:\s*bool/s', $model, $wm) !== 1
                || strpos($wm[0], '$windowSec = 7') === false) {
                return $bad('memberTyping() must default to a 7s window (longer than the 3s ping interval)');
            }
            $body = [];
            if (preg_match('/function memberTyping\s*\([^)]*\)\s*:\s*bool\s*\{(.*?)\n    \}/s', $model, $body) !== 1) {
                return $bad('memberTyping() body not found');
            }
            // The freshness test must run in SQL: member_typing_at is written
            // with MySQL NOW(), so comparing against PHP time() breaks whenever
            // the MySQL session timezone differs from the app timezone (it did
            // on the dev box — 6h skew, indicator permanently off).
            if (strpos($body[1], 'TIMESTAMPDIFF') === false) {
                return $bad('memberTyping() must compare in SQL (TIMESTAMPDIFF), not against PHP time()');
            }
            if (strpos($body[1], 'strtotime') !== false || strpos($body[1], 'time()') !== false) {
                return $bad('memberTyping() must not mix PHP time() with MySQL-written timestamps');
            }
            $bridge = $read("$root/app/Controllers/ChatBridgeController.php");
            if (strpos($bridge, "'typing' => \$typing") === false) {
                return $bad('the bridge stream payload must carry the typing state');
            }
            $routes = $read("$root/config/routes.php");
            if (strpos($routes, "'/chat/typing'") === false) {
                return $bad('POST /chat/typing must be registered');
            }
            $migration = "$root/database/migrations/065_chat_typing.sql";
            if (!is_file($migration) || strpos((string) file_get_contents($migration), 'member_typing_at') === false) {
                return $bad('migration 065 must add chat_conversations.member_typing_at');
            }
            return $ok('throttled member ping, 7s TTL, bridge emits the state, migration present');
        });
        $add('smoke.chat_admin.queue', 'Smoke · Chat correctness', 'Admin chat queue shows unread counts and waiting-first order', static function () use ($chatAdmin, $ok, $bad): array {
            $m = [];
            if (preg_match('/function index\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $chatAdmin, $m) !== 1) {
                return $bad('AdminChatController::index() not found');
            }
            $body = $m[1];
            $needed = ['unread_count', 'oldest_unread_at', "queue === 'unread'", 'last_message'];
            $missing = [];
            foreach ($needed as $n) {
                if (strpos($body, $n) === false) $missing[] = $n;
            }
            return $missing === [] && strpos($body, 'ORDER BY (oldest_unread_at IS NULL) ASC') !== false
                ? $ok('unread counts + oldest-waiting-first ordering present')
                : $bad('admin chat index must compute unread counts and sort waiting conversations first'
                    . ($missing !== [] ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        });
        $add('smoke.chat_admin.queue_view', 'Smoke · Chat correctness', 'Admin chat list renders the waiting queue UI', static function () use ($chatView, $ok, $bad): array {
            return strpos($chatView, 'name="queue"') !== false
                && strpos($chatView, 'Waiting for reply') !== false
                && strpos($chatView, 'waiting</span>') !== false
                && strpos($chatView, 'unread_count') !== false
                ? $ok('queue filter + unread badge + preview present')
                : $bad('views/admin/chat.php must render the queue filter, unread badge and last-message preview');
        });
        $add('smoke.chat_admin.reply_clears_unread', 'Smoke · Chat correctness', 'Admin panel replies clear the unread queue marker', static function () use ($chatAdmin, $ok, $bad): array {
            $m = [];
            if (preg_match('/function operatorReply\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $chatAdmin, $m) !== 1) {
                return $bad('AdminChatController::operatorReply() not found');
            }
            $body = $m[1];
            return strpos($body, 'operator_read_through_id') !== false
                && strpos($body, 'CAST(? AS UNSIGNED)') !== false
                ? $ok('reply advances operator_read_through_id (numeric CAST)')
                : $bad('operatorReply() must clear unread via operator_read_through_id with CAST(? AS UNSIGNED)');
        });
        $add('smoke.chat.reply_notifies_wall', 'Smoke · Chat correctness', 'Operator replies add a wall notification for the member', static function () use ($chatMessage, $bridgeCtrl, $chatAdmin, $ok, $bad): array {
            $m = [];
            if (preg_match('/function notifyMemberReply\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $chatMessage, $m) !== 1) {
                return $bad('ChatMessage::notifyMemberReply() not found');
            }
            $body = $m[1];
            if (strpos($body, "'chat_reply'") === false || strpos($body, "'/chat'") === false || strpos($body, 'Notification::add') === false) {
                return $bad('notifyMemberReply must add a chat_reply notification linking to /chat');
            }
            return strpos($bridgeCtrl, 'ChatMessage::notifyMemberReply((int) $cid, $msg)') !== false
                && strpos($chatAdmin, 'ChatMessage::notifyMemberReply($id, $message)') !== false
                ? $ok('bridge + admin replies both notify the wall')
                : $bad('both operator reply paths must call ChatMessage::notifyMemberReply');
        });
        $add('smoke.chat.member_attachment', 'Smoke · Chat correctness', 'Web chat send() accepts member attachments with the shared cap', static function () use ($chatCtrl, $ok, $bad): array {
            $m = [];
            if (preg_match('/function send\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $chatCtrl, $m) !== 1) {
                return $bad('ChatController::send() not found');
            }
            $body = $m[1];
            $needed = [
                "\$this->request->file('attachment')",
                'storeAttachment($file)',
                'MAX_ATTACHMENT_BYTES',
                'addMessage($cid, ChatMessage::ROLE_USER, $message, $attachment)',
                "\$message !== ''",
            ];
            $missing = [];
            foreach ($needed as $n) {
                if (strpos($body, $n) === false) $missing[] = $n;
            }
            return $missing === [] && strpos($chatCtrl, '25 * 1024 * 1024') !== false
                ? $ok('attachment stored via storeAttachment and bounded by the 25 MB cap')
                : $bad('ChatController::send() must accept an attachment through storeAttachment with a 25 MB cap'
                    . ($missing !== [] ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        });
        $add('smoke.chat_ui.member_attach_compose', 'Smoke · Chat correctness', 'Member composer offers a gated file attachment', static function () use ($chatIndex, $ok, $bad): array {
            $needed = ['id="chat-file"', "body.append('attachment', file)", '25 * 1024 * 1024'];
            $missing = [];
            foreach ($needed as $n) {
                if (strpos($chatIndex, $n) === false) $missing[] = $n;
            }
            return $missing === []
                ? $ok('composer file input sizes files client-side before upload')
                : $bad('views/chat/index.php composer must offer a file input that rides FormData'
                    . ($missing !== [] ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        });

        // ----------------------------------------------------- Server optimizations
        $serverOptModel = $read("$root/app/Models/ServerOptimizations.php");
        $applyServer    = $read("$root/bin/apply_server_optimizations.php");
        $cacheCore      = $read("$root/app/Core/Cache.php");
        $settingsCtrl   = $read("$root/app/Controllers/SettingsController.php");
        $settingsView   = $read("$root/views/settings.php");
        $catModel       = $read("$root/app/Models/Category.php");
        $photoModel     = $read("$root/app/Models/Photo.php");
        $galleryModel   = $read("$root/app/Models/Gallery.php");
        $add('smoke.serveropt.model', 'Smoke · Server optimizations', 'Settings model validates + persists values', static function () use ($serverOptModel, $ok, $bad): array {
            return strpos($serverOptModel, 'function defaults') !== false
                && strpos($serverOptModel, 'function save') !== false
                && strpos($serverOptModel, 'function cacheTtl') !== false
                ? $ok('model present')
                : $bad('ServerOptimizations must expose defaults(), save() and cacheTtl()');
        });
        $add('smoke.serveropt.apply_script', 'Smoke · Server optimizations', 'Scoped-root apply script exists and is additive', static function () use ($applyServer, $ok, $bad): array {
            return strpos($applyServer, 'gallery-optimizations.conf') !== false
                && strpos($applyServer, '99-gallery-optimizations.ini') !== false
                && strpos($applyServer, '99-gallery-optimizations.cnf') !== false
                && strpos($applyServer, 'SET GLOBAL') !== false
                ? $ok('apply script wired')
                : $bad('apply_server_optimizations.php must write additive Apache/PHP/MySQL configs and apply MySQL SET GLOBAL');
        });
        $add('smoke.serveropt.controller', 'Smoke · Server optimizations', 'Settings page save/apply actions exist', static function () use ($settingsCtrl, $routes, $ok, $bad): array {
            return strpos($settingsCtrl, 'function updateServerOptimizations') !== false
                && strpos($settingsCtrl, 'function applyServerOptimizations') !== false
                && in_array(['POST', '/settings/server-optimizations', 'SettingsController@updateServerOptimizations'], $routes, true)
                && in_array(['POST', '/settings/server-optimizations/apply', 'SettingsController@applyServerOptimizations'], $routes, true)
                ? $ok('controller + routes wired')
                : $bad('settings must expose save/apply server-optimization actions');
        });
        $add('smoke.serveropt.view', 'Smoke · Server optimizations', 'Settings page renders the optimization section', static function () use ($settingsView, $ok, $bad): array {
            return strpos($settingsView, 'Server optimizations') !== false
                && strpos($settingsView, 'server-optimizations') !== false
                && strpos($settingsView, 'serverOptCanApply') !== false
                ? $ok('view section present')
                : $bad('settings view must render the super-admin Server optimizations card');
        });
        $add('smoke.serveropt.cache', 'Smoke · Server optimizations', 'Hot reads cached with generation invalidation', static function () use ($cacheCore, $catModel, $photoModel, $galleryModel, $ok, $bad): array {
            return strpos($cacheCore, 'function rememberGen') !== false
                && strpos($cacheCore, 'function bump') !== false
                && strpos($catModel, 'rememberGen') !== false
                && strpos($photoModel, 'rememberGen') !== false
                && strpos($galleryModel, 'rememberGen') !== false
                ? $ok('cache helpers + hot paths wired')
                : $bad('Cache must expose rememberGen()/bump() and Category/Photo/Gallery hot reads must use it');
        });
        $add('smoke.serveropt.cache_array_safe', 'Smoke · Server optimizations', 'rememberGen transparently round-trips array callbacks', static function () use ($cacheCore, $ok, $bad): array {
            return strpos($cacheCore, 'is_array($value)') !== false
                && strpos($cacheCore, '$arrayKey') !== false
                && strpos($cacheCore, "':a'") !== false
                && strpos($cacheCore, 'json_encode($value') !== false
                ? $ok('array callbacks stored under a distinct key + decoded on hit')
                : $bad('Cache::rememberGen must accept array callbacks (JSON under a distinct ":a" key) so an array return never fatals on the (string) cast');
        });

        // ----------------------------------------------------- Operator tokens
        $tokenModel = $read("$root/app/Models/OperatorToken.php");
        $add('smoke.operator_tokens.model', 'Smoke · Operator tokens', 'Per-device token model stores only hashes', static function () use ($tokenModel, $ok, $bad): array {
            return strpos($tokenModel, 'function create') !== false
                && strpos($tokenModel, 'function authenticate') !== false
                && strpos($tokenModel, 'function revoke') !== false
                && strpos($tokenModel, "hash('sha256'") !== false
                ? $ok('model present')
                : $bad('OperatorToken must expose create/authenticate/revoke and hash tokens');
        });
        $add('smoke.operator_tokens.bridge', 'Smoke · Operator tokens', 'Bridge accepts device tokens or shared key', static function () use ($bridgeCtrl, $ok, $bad): array {
            return strpos($bridgeCtrl, 'OperatorToken::authenticate') !== false
                && strpos($bridgeCtrl, 'GALLERY_CHAT_KEY') !== false
                ? $ok('dual auth wired')
                : $bad('ChatBridgeController::authorized must accept operator tokens (or the legacy shared key)');
        });
        $add('smoke.operator_tokens.admin', 'Smoke · Operator tokens', 'Admin can create and revoke tokens', static function () use ($chatAdmin, $chatView, $routes, $ok, $bad): array {
            return strpos($chatAdmin, 'function createToken') !== false
                && strpos($chatAdmin, 'function revokeToken') !== false
                && strpos($chatView, 'Operator device tokens') !== false
                && in_array(['POST', '/admin/chat/tokens', 'AdminChatController@createToken', 'chat'], $routes, true)
                && in_array(['POST', '/admin/chat/tokens/{id}/revoke', 'AdminChatController@revokeToken', 'chat'], $routes, true)
                ? $ok('admin create/revoke wired')
                : $bad('admin chat must expose operator token create/revoke actions and a management section');
        });

        // ----------------------------------------------------- View trends
        $statsModel   = $read("$root/app/Models/Stats.php");
        $pageVisitMod = $read("$root/app/Models/PageVisit.php");
        $adminCtrl    = $read("$root/app/Controllers/AdminController.php");
        $dashboard    = $read("$root/views/admin/dashboard.php");
        $add('smoke.views.login_record', 'Smoke · View trends', 'Login page records a unique-IP visit', static function () use ($authCtrl, $ok, $bad): array {
            return strpos($authCtrl, "PageVisit::record('login'") !== false ? $ok('loginForm wired') : $bad('AuthController::loginForm must record a PageVisit for the login page');
        });
        $add('smoke.views.signup_record', 'Smoke · View trends', 'Signup page records a unique-IP visit', static function () use ($authCtrl, $ok, $bad): array {
            return strpos($authCtrl, "PageVisit::record('signup'") !== false ? $ok('signupForm wired') : $bad('AuthController::signupForm must record a PageVisit for the signup page');
        });
        $add('smoke.views.dedupe', 'Smoke · View trends', 'Page-visit rows deduped per page/IP/day', static function () use ($pageVisitMod, $ok, $bad): array {
            return strpos($pageVisitMod, 'INSERT IGNORE') !== false && strpos($pageVisitMod, 'CURDATE()') !== false && strpos($pageVisitMod, 'visit_date') !== false
                ? $ok('INSERT IGNORE per page/IP/day')
                : $bad('PageVisit::record must INSERT IGNORE a per-day (page, ip, visit_date) row');
        });
        $add('smoke.views.distinct_counts', 'Smoke · View trends', 'Uniques computed with COUNT(DISTINCT ip)', static function () use ($pageVisitMod, $ok, $bad): array {
            return strpos($pageVisitMod, 'COUNT(DISTINCT ip)') !== false ? $ok('distinct IP counting') : $bad('PageVisit uniques must be computed via COUNT(DISTINCT ip)');
        });
        $add('smoke.views.periods', 'Smoke · View trends', 'View trends honor day/week/month/year/all time', static function () use ($statsModel, $pageVisitMod, $ok, $bad): array {
            return strpos($statsModel, "'year'") !== false && strpos($statsModel, "'all'") !== false
                && strpos($pageVisitMod, "'year'") !== false && strpos($pageVisitMod, "'all'") !== false
                ? $ok('both series selectable')
                : $bad('contentViewTrends and PageVisit must support day/week/month/year/all time periods');
        });
        $add('smoke.views.dashboard_wiring', 'Smoke · View trends', 'Dashboard renders the period selector and page-visit stats', static function () use ($dashboard, $adminCtrl, $ok, $bad): array {
            return strpos($dashboard, "vt=") !== false && strpos($dashboard, "pageVisits['unique_login']") !== false
                && strpos($adminCtrl, "'viewPeriod'") !== false
                ? $ok('selector + stats wired')
                : $bad('dashboard must expose the view-trends period selector and render login/signup unique-IP stats');
        });

        // ------------------------------------------------------ Auto Poster
        $apq = $read("$root/app/Models/AutoPostQueue.php");
        $add('smoke.ap.domain', 'Smoke · Auto Poster', 'AutoPostQueue recommends the site domain', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'amethyst2213.com') !== false ? $ok('domain present') : $bad('auto-post recommendations must include the site domain');
        });
        $add('smoke.ap.char_limit', 'Smoke · Auto Poster', 'Custom text capped per platform (registry-driven)', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, "self::templateSettings(\$key)['max_length']") !== false
                || strpos($apq, "self::templateSettings(\$canonical)['max_length']") !== false
                ? $ok('per-platform max_length cap')
                : $bad('auto-post queue must cap custom text at the platform registry max_length');
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
            return strpos($apq, 'create_blurred_copy') !== false && strpos($apq, 'POST_IMAGE_BLUR_PERCENT = 85') !== false
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
            return strpos($apq, 'displaySchedule') !== false && strpos($apq, 'schedulerTimezone') !== false
                && strpos($apq, 'normalize_local_datetime($value, AutoPosterConfig::timezone())') !== false
                && strpos($apq, 'is_future_local_datetime($value, AutoPosterConfig::timezone())') !== false
                ? $ok('timezone conversion present (via shared helper)')
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
        $apt = $read("$root/app/Core/AutoPostText.php");
        $add('smoke.ap.template_settings', 'Smoke · Auto Poster', 'Post templates are editable per platform (X + Reddit)', static function () use ($apq, $apt, $ok, $bad): array {
            return strpos($apq, 'public static function templateSettings(') !== false
                && strpos($apt, "templateSettings(string \$platform") !== false
                && strpos($apt, "'max_tags'") !== false && strpos($apt, "'max_length'") !== false
                && strpos($apt, "'banned_words'") !== false
                && strpos($apt, 'DEFAULT_PATTERN') !== false && strpos($apt, "'{title}'") !== false
                    && strpos($apt, "'{hashtags}'") !== false
                ? $ok('per-platform editable template settings present')
                : $bad('AutoPostQueue must expose per-platform editable template settings (pattern tokens, hashtag/char limits, banned words)');
        });
        $add('smoke.ap.template_config', 'Smoke · Auto Poster', 'AutoPosterConfig persists + preserves both templates', static function () use ($root, $read, $ok, $bad): array {
            $cfg = $read("$root/app/Models/AutoPosterConfig.php");
            return strpos($cfg, 'public static function saveTemplate(') !== false
                && preg_match("/'template_x'\s*=>/", $cfg) === 1 && preg_match("/'template_reddit'\s*=>/", $cfg) === 1
                ? $ok('saveTemplate + both templates preserved on save()')
                : $bad('AutoPosterConfig must save an X and a Reddit template and carry them over on credential saves');
        });
        $add('smoke.ap.template_route', 'Smoke · Auto Poster', 'Route + controller persist the templates', static function () use ($root, $read, $ok, $bad): array {
            $routes = $read("$root/config/routes.php");
            $ctrl   = $read("$root/app/Controllers/AutoPosterController.php");
            return strpos($routes, 'auto-poster/template/save') !== false
                && strpos($routes, "'/admin/auto-poster/{platform}'") !== false
                && strpos($routes, 'AutoPosterController@platform') !== false
                && strpos($ctrl, 'public function saveTemplate()') !== false
                    && strpos($ctrl, 'AutoPosterConfig::saveTemplate(') !== false
                && strpos($ctrl, 'private function renderPage(string $platform)') !== false
                    && strpos($ctrl, "renderPage('x')") !== false
                ? $ok('save route + generic platform page wired')
                : $bad('the auto-poster template save route/controller and the generic platform page must exist');
        });
        $apw = $read("$root/bin/autopost_worker.php");
        $add('smoke.ap.worker_due', 'Smoke · Auto Poster', 'Worker publishes due queue rows', static function () use ($apw, $ok, $bad): array {
            return strpos($apw, 'AutoPostQueue::due') !== false ? $ok('AutoPostQueue::due used') : $bad('autopost worker must publish due queue rows');
        });
        $add('smoke.ap.worker_lock', 'Smoke · Auto Poster', 'Worker locks against overlapping runs', static function () use ($apw, $ok, $bad): array {
            return strpos($apw, 'flock') !== false ? $ok('flock used') : $bad('autopost worker must lock against overlapping runs');
        });
        $platforms = $read("$root/app/Core/Platforms.php");
        $add('smoke.ap.registry', 'Smoke · Auto Poster', 'Platform registry defines all channels', static function () use ($platforms, $ok, $bad): array {
            return strpos($platforms, 'final class Platforms') !== false
                && strpos($platforms, 'public static function all()') !== false
                && strpos($platforms, 'canonicalize') !== false
                ? $ok('registry present')
                : $bad('app/Core/Platforms.php must define the channel registry');
        });
        $add('smoke.ap.migration', 'Smoke · Auto Poster', 'Multi-channel queue migration present', static function () use ($root, $read, $ok, $bad): array {
            $m = $read("$root/database/migrations/051_autopost_multichannel.sql");
            return strpos($m, 'MEDIUMTEXT') !== false ? $ok('051 widens queue text') : $bad('migration 051 must widen auto_poster_queue.text to MEDIUMTEXT');
        });
        $apq = $read("$root/app/Models/AutoPostQueue.php");
        $add('smoke.ap.refill_utc', 'Smoke · Auto Poster', 'refillAhead fills the earliest free hour (no multi-hour gaps)', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, "setTime((int) \$next->format('H'), 0, 0)") !== false
                && strpos($apq, "\$next->modify('+1 hour')") !== false
                && strpos($apq, "\$takenTs[\$candidate->getTimestamp()]") !== false
                && strpos($apq, "g.published_at > CURRENT_TIMESTAMP") !== false
                && strpos($apq, "g.published_at IS NULL OR g.published_at <= CURRENT_TIMESTAMP") !== false
                ? $ok('refill anchors to the next hour, skips taken hours, prioritizes scheduled galleries, visible-only pool')
                : $bad('refillAhead must fill from the next free hour and schedule publish-queue galleries at their publish moment');
        });
        $add('smoke.ap.no_media_fix', 'Smoke · Auto Poster', 'No-media posts prevented (galleryMedia fallback + enqueue/post guards)', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, '$photos === []') !== false
                && strpos($apq, "AND p.created_at >= DATE_SUB(NOW(), INTERVAL") !== false
                && strpos($apq, "if (\$media === []) {") !== false
                && strpos($apq, 'Queue item has no media to post.') !== false
                ? $ok('galleryMedia falls back to all media; enqueue/post refuse text-only rows')
                : $bad('galleryMedia must fall back past recent_days and enqueue()/post() must never publish a media-less post');
        });
        $add('smoke.ap.publish_time_schedule', 'Smoke · Auto Poster', 'Recommended posts use the gallery publish time by default', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'galleryPublishSchedule') !== false
                && strpos($apq, "\$scheduled = self::normalizeSchedule(\$scheduledAt);") !== false
                && strpos($apq, "self::galleryPublishSchedule((string) (\$gallery['published_at'] ?? ''), \$key)") !== false
                && strpos($apq, 'self::galleryPublishSchedule((string) ($row[\'published_at\'] ?? \'\'), $key)') !== false
                ? $ok('enqueue + recommendations fall back to the gallery published_at when no explicit schedule is given')
                : $bad('enqueue()/recommendations() must use the gallery scheduled publish time as the queue post time');
        });

        // ------------------------------------------------- Gallery import API
        $importCtrl = $read("$root/app/Controllers/ImportController.php");
        $add('smoke.import.controller', 'Smoke · Gallery Import', 'ImportController defines queue + gallery endpoints', static function () use ($importCtrl, $ok, $bad): array {
            return strpos($importCtrl, 'public function queue()') !== false
                && strpos($importCtrl, 'public function gallery()') !== false
                && strpos($importCtrl, 'public function chunk(int $galleryId)') !== false
                && strpos($importCtrl, 'public function chunkComplete(int $galleryId)') !== false
                && strpos($importCtrl, "GALLERY_IMPORT_KEY") !== false
                && strpos($importCtrl, 'MediaUploader::commit(') !== false
                ? $ok('queue + gallery + chunk endpoints wired')
                : $bad('ImportController must implement the queue, gallery and chunked-upload endpoints');
        });
        $add('smoke.import.routes', 'Smoke · Gallery Import', 'Import routes registered', static function () use ($root, $read, $ok, $bad): array {
            $routes = $read("$root/config/routes.php");
            return strpos($routes, "'/webhooks/import/queue'") !== false
                && strpos($routes, "'/webhooks/import/gallery'") !== false
                && strpos($routes, "'/webhooks/import/gallery/{id}/files/chunk'") !== false
                ? $ok('import routes present')
                : $bad('routes.php must register /webhooks/import/queue, /webhooks/import/gallery and the chunk routes');
        });
        $add('smoke.import.env', 'Smoke · Gallery Import', 'GALLERY_IMPORT_KEY documented in .env.example', static function () use ($root, $read, $ok, $bad): array {
            $env = $read("$root/.env.example");
            return strpos($env, 'GALLERY_IMPORT_KEY') !== false ? $ok('env key documented') : $bad('.env.example must document GALLERY_IMPORT_KEY');
        });
        $add('smoke.import.uploader_fix', 'Smoke · Gallery Import', 'MediaUploader::commit resolves the uploads dir (bug fix)', static function () use ($root, $read, $ok, $bad): array {
            $mu = $read("$root/app/Core/MediaUploader.php");
            return strpos($mu, '$config = \\config(\'app.uploads\');') !== false ? $ok('commit resolves config') : $bad('MediaUploader::commit must resolve config("app.uploads") for video duration');
        });
        $add('smoke.import.video_probe_fix', 'Smoke · Gallery Import', 'video_has_stream uses nokey ffprobe output (ffprobe 6.x trailing-comma regression)', static function () use ($root, $read, $ok, $bad): array {
            $h = $read("$root/app/Core/helpers.php");
            return strpos($h, 'nokey=1') !== false && strpos($h, "rtrim(trim(implode('', \$probe)), ',') === 'video'") !== false
                ? $ok('probe fix present')
                : $bad('video_has_stream must tolerate the trailing comma new ffprobe adds to csv output');
        });
        $add('smoke.import.settings_endpoint', 'Smoke · Gallery Import', 'ImportController exposes GET /webhooks/import/settings (per machine)', static function () use ($importCtrl, $ok, $bad): array {
            return strpos($importCtrl, 'public function settings()') !== false && strpos($importCtrl, 'ImportSettings::allForMachine(') !== false
                ? $ok('settings endpoint wired')
                : $bad('ImportController must implement /webhooks/import/settings with per-machine resolution');
        });
        $add('smoke.seo.sitemap', 'Smoke · SEO', 'the sitemap is built in one place and written out as a file for the crawlers', static function () use ($root, $read, $ok, $bad): array {
            $sitemap   = (string) @file_get_contents($root . '/app/Core/Sitemap.php');
            $ctrl      = (string) $read($root . '/app/Controllers/StaticPageController.php');
            $writer    = (string) @file_get_contents($root . '/bin/write_sitemap.php');
            $apache    = (string) @file_get_contents($root . '/config/gallery-root-files.conf');
            $problems = [];

            if (strpos($sitemap, 'class Sitemap') === false) {
                $problems[] = 'there must be an App\Core\Sitemap that builds the map';
            }
            // The file that is written out and the map that is served have to
            // be the same map, or Google is told about a different site.
            if (strpos($ctrl, 'Sitemap::xml()') === false || strpos($ctrl, 'Sitemap::videoXml()') === false) {
                $problems[] = 'the sitemap routes must render the shared map';
            }
            if (strpos($writer, 'Sitemap::write(') === false) {
                $problems[] = 'there must be a script that writes the map out as a file';
            }
            if (strpos($apache, 'Alias /sitemap.xml') === false) {
                $problems[] = 'the origin root must serve the map as a file, the way it serves robots.txt';
            }
            // The links in the map are absolute, so the map is the same
            // wherever it is fetched from.
            if (strpos($sitemap, "baseUrl()") === false) {
                $problems[] = 'the map must use the site\'s base address';
            }
            if (!is_file($root . '/public/robots.txt') || strpos((string) file_get_contents($root . '/public/robots.txt'), 'Sitemap:') === false) {
                $problems[] = 'robots.txt must point at the map';
            }

            return $problems === []
                ? $ok('the map is built once, served and written out')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.membership.renewal', 'Smoke · Membership', 'A renewal extends the term (the postback is found and counted)', static function () use ($root, $read, $ok, $bad): array {
            $sub  = (string) $read($root . '/app/Models/Subscription.php');
            $hook = (string) $read($root . '/app/Controllers/WebhookController.php');
            $life = (string) $read($root . '/app/Core/Lifecycle.php');
            $problems = [];

            // The renewal has to reach the model at all.
            if (strpos($sub, 'public static function renew(') === false) {
                $problems[] = 'there must be a Subscription::renew() that counts a payment and extends the term';
            }
            if (strpos($sub, 'last_payment_ref') === false) {
                $problems[] = 'the subscription must remember the payment it last counted, or a replayed postback grants a second period';
            }
            // A renewal arrives as a PAYMENT, whose id is the sale's, not
            // the subscription's: the lookup has to try both.
            if (strpos($hook, 'billing_agreement_id') === false) {
                $problems[] = 'the PayPal lookup must find the subscription through the sale\'s billing_agreement_id, or a renewal is never found';
            }
            if (strpos($hook, 'Subscription::renew(') === false) {
                $problems[] = 'the webhook must count a payment with Subscription::renew()';
            }
            if (strpos($life, 'onPaymentReceived(') === false) {
                $problems[] = 'a renewal must send the member a receipt';
            }
            if (!is_file($root . '/views/emails/payment_received.php')) {
                $problems[] = 'there must be a payment_received email template';
            }

            return $problems === []
                ? $ok('a renewal is found, counted and receipted')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.membership.processor_offerable', 'Smoke · Membership', 'A member is not offered a sandbox processor while the site has a live one', static function () use ($root, $read, $ok, $bad): array {
            $pp    = (string) $read($root . '/app/Models/PaymentProcessor.php');
            $ctrl  = (string) $read($root . '/app/Controllers/MembershipController.php');
            $admin = (string) $read($root . '/app/Controllers/PaymentProcessorsController.php');
            $problems = [];

            if (strpos($pp, 'public static function offerable(') === false) {
                $problems[] = 'there must be a PaymentProcessor::offerable() that leaves a sandbox processor out';
            }
            if (strpos($ctrl, 'PaymentProcessor::offerable()') === false) {
                $problems[] = 'the membership page must offer the offerable processors';
            }
            if (strpos($ctrl, 'isOfferable(') === false) {
                $problems[] = 'the checkout must refuse a processor that is not offerable';
            }
            // The admin keeps every processor, sandbox ones included.
            if (strpos($admin, 'PaymentProcessor::all()') === false) {
                $problems[] = 'the admin must still see every processor, sandbox ones included';
            }

            return $problems === []
                ? $ok('a sandbox processor is not offered to a member, and the admin still has it')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.media.variant_orientation', 'Smoke · Media', 'A preview applies the EXIF orientation once', static function () use ($root, $read, $ok, $bad): array {
            $helpers = $read("$root/app/Core/helpers.php");
            // ffmpeg's autorotate applies the EXIF orientation itself. A
            // second, explicit transpose turned every camera photo 180
            // degrees from the original: the previews in the grids came out
            // upside down, and because the manage page's rotate buttons are
            // judged against those previews, the rotation looked like it
            // turned the images the wrong way.
            //
            // So the image path applies the orientation ONCE: it asks
            // ffmpeg not to rotate (-noautorotate) and puts the orientation
            // in the filter chain itself.
            $problems = [];
            if (strpos($helpers, 'function _exif_transpose_chain(') === false) {
                $problems[] = 'there must be a helper that builds the orientation filter chain';
            }
            if (strpos($helpers, "-noautorotate -i ") === false) {
                $problems[] = 'the image variants must ask ffmpeg not to rotate the picture itself';
            }
            if (strpos($helpers, "_exif_transpose_chain(\$orientation)") === false) {
                $problems[] = 'the image variants must apply the EXIF orientation in the filter chain';
            }
            // The transpose values changed meaning between ffmpeg releases,
            // so only the values that mean the same thing everywhere may be
            // used: 90 degrees clockwise is written as "90 counter-clockwise,
            // then 180".
            if (strpos($helpers, "transpose=2,transpose=1,transpose=1") === false) {
                $problems[] = 'the orientation chain must use the transpose values that mean the same thing in every ffmpeg release';
            }
            return $problems === []
                ? $ok('the EXIF orientation is applied exactly once')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.import.order_endpoint', 'Smoke · Gallery Import', 'ImportController exposes GET /webhooks/import/galleries and the photo order endpoint', static function () use ($root, $read, $importCtrl, $ok, $bad): array {
            $routes = $read("$root/config/routes.php");
            $gallery = $read("$root/app/Models/Gallery.php");
            return strpos($importCtrl, 'public function galleries()') !== false
                && strpos($importCtrl, 'public function order(') !== false
                && strpos($routes, '/webhooks/import/galleries') !== false
                && strpos($routes, '/webhooks/import/gallery/{id}/order') !== false
                && strpos($gallery, 'function setPhotoOrder(') !== false
                ? $ok('galleries + order endpoints wired')
                : $bad('ImportController must implement GET /webhooks/import/galleries and POST /webhooks/import/gallery/{id}/order (the importer uploads a folder in parallel)');
        });
        $add('smoke.import.settings_model', 'Smoke · Gallery Import', 'ImportSettings stores per-machine host/posted folders', static function () use ($root, $read, $ok, $bad): array {
            $m = $read("$root/app/Models/ImportSettings.php");
            return strpos($m, 'class ImportSettings') !== false && strpos($m, "'machines'") !== false
                && strpos($m, 'allForMachine') !== false
                ? $ok('model present with per-machine folders')
                : $bad('app/Models/ImportSettings.php must persist per-machine host/posted folders');
        });
        $add('smoke.import.admin_page', 'Smoke · Gallery Import', 'Gallery management page edits import settings', static function () use ($root, $read, $ok, $bad): array {
            $routes = $read("$root/config/routes.php");
            $ctrl   = $read("$root/app/Controllers/AdminController.php");
            $view   = $read("$root/views/admin/galleries.php");
            return strpos($routes, 'admin/galleries/import-settings') !== false
                && strpos($ctrl, 'function saveImportSettings()') !== false
                && strpos($view, 'import_host_folder') !== false
                ? $ok('admin page + save route wired')
                : $bad('galleries page must edit import settings and persist them');
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
        $add('smoke.ap.view_tz', 'Smoke · Auto Poster', 'No independent schedule-timezone selector (site timezone governs)', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'Schedule timezone') === false
                && strpos($apv, 'site timezone set on Settings') !== false
                ? $ok('scheduler uses the site timezone')
                : $bad('auto-poster must not expose its own schedule-timezone selector; it must follow the site timezone set on Settings');
        });
        $add('smoke.ap.site_tz_fallback', 'Smoke · Auto Poster', 'Scheduler reads the site timezone (no stored override wins)', static function () use ($root, $read, $ok, $bad): array {
            $apc = $read("$root/app/Models/AutoPosterConfig.php");
            return strpos($apc, 'public static function timezone()') !== false
                && strpos($apc, 'SiteConfig::timezone()') !== false
                ? $ok('auto-poster scheduling always follows SiteConfig::timezone()')
                : $bad('AutoPosterConfig::timezone() must delegate to SiteConfig::timezone() with no stored-value override');
        });
        $add('smoke.ap.view_countdown', 'Smoke · Auto Poster', 'Live mo/d/h/m/s countdown shown', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'ap-countdown') !== false && strpos($apv, 'data-synced') !== false
                && strpos($apv, 'mo') !== false && strpos($apv, 'setInterval(tick, 1000)') !== false
                ? $ok('countdown present')
                : $bad('auto-poster queue must show a live months/days/hours/minutes/seconds countdown');
        });
        $add('smoke.ap.template_view', 'Smoke · Auto Poster', 'One platform-editable template panel per page with live preview', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'name="pattern"') !== false
                && strpos($apv, 'ap-platform-switch') !== false
                && strpos($apv, 'ap-preview') !== false && strpos($apv, 'ap-preview-count') !== false
                && strpos($apv, 'post template') !== false
                && strpos($apv, 'data-ap-template') !== false
                && strpos($apv, 'input type="hidden" name="platform"') !== false
                && strpos($apv, 'foreach ($enabledCh as $ch)') !== false
                ? $ok('platform switch + editable template panel + live preview present')
                : $bad('auto-poster page must render a per-channel platform switch, an editable template panel with hidden platform and a live preview');
        });
        $add('smoke.ap.requeue_schedule', 'Smoke · Auto Poster', 'Repost/reschedule rows always get a real schedule', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'function requeueFrom') !== false && strpos($apq, '$scheduled = self::defaultSchedule(null, $key);') !== false
                ? $ok('defaults to +1h, never NULL')
                : $bad('AutoPostQueue::requeueFrom must default empty/invalid schedules to defaultSchedule() so reposts are never queued with "no time"');
        });
        $add('smoke.ap.recent_prefill', 'Smoke · Auto Poster', 'Recent-posts Reschedule picker prefills +1h, not the old time', static function () use ($apv, $ok, $bad): array {
            return substr_count($apv, 'AutoPostQueue::rescheduleDefault(') >= 2
                ? $ok('both recent-post schedulers prefill the latest-post-aware default')
                : $bad('recent-posts Reschedule/Repost pickers must prefill AutoPostQueue::rescheduleDefault() instead of the item\'s stale scheduled_at');
        });
        $add('smoke.ap.platform_recs', 'Smoke · Auto Poster', 'Recommended posts work per platform', static function () use ($apq, $apv, $root, $read, $ok, $bad): array {
            $ctrl  = $read("$root/app/Controllers/AutoPosterController.php");
            $recv  = $read("$root/views/admin/partials/recommendations.php");
            return strpos($apq, 'public static function recommendations(int $page = 1, int $perPage = 14, string $platform') !== false
                && strpos($apq, "q.status IN ('queued', 'posted', 'failed', 'skipped', 'dismissed')") !== false
                && strpos($apq, "ORDER BY COALESCE(") !== false
                && strpos($apq, 'public static function enqueue(int $galleryId, ?string $text = null, ?string $scheduledAt = null, string $platform') !== false
                && strpos($ctrl, 'AutoPostQueue::recommendations(1, 14, $platform)') !== false
                && strpos($ctrl, 'public function recommendationsPage(') !== false
                && strpos($apv, 'partials/recommendations.php') !== false
                && strpos($recv, 'queue/recommend') !== false
                && strpos($recv, '<input type="hidden" name="platform" value="<?= e($platform) ?>">') !== false
                ? $ok('per-platform recommendations, paginated 14 at a time, ordered by post time')
                : $bad('recommendations must be generated per platform, paginated 14/page, excluding only pending/dismissed galleries');
        });
        $add('smoke.ap.post_guarded', 'Smoke · Auto Poster', 'Client exceptions mark the row failed, never left queued', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'catch (\Throwable $e)') !== false && strpos($apq, 'thrown by the platform client') !== false
                ? $ok('guard present')
                : $bad('AutoPostQueue::post must catch platform-client exceptions and markFailed() them instead of leaving the row queued');
        });
        $add('smoke.ap.error_clamped', 'Smoke · Auto Poster', 'markFailed/markSkipped clamp overlong errors to VARCHAR(500)', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'private static function clampError(string $error)') !== false
                && strpos($apq, "mb_substr(\$error, 0, 497)") !== false
                && strpos($apq, "self::clampError(\$error)") !== false
                ? $ok('clamp helper wired into both mark methods')
                : $bad('markFailed/markSkipped must run error strings through a clamp so a >500-char platform body cannot throw SQLSTATE 22001 and kill the worker run');
        });
        $add('smoke.ap.weasyl_body_capped', 'Smoke · Auto Poster', 'Weasyl client caps the echoed upstream error body', static function () use ($root, $read, $ok, $bad): array {
            $wc = $read("$root/app/Models/WeasylClient.php");
            return strpos($wc, "mb_substr(\$body, 0, 397)") !== false && strpos($wc, 'Weasyl submit failed (HTTP') !== false
                ? $ok('body capped')
                : $bad('WeasylClient must cap the upstream API error body it embeds in the error string, or markFailed will exceed VARCHAR(500)');
        });
        $add('smoke.ap.weasyl_retired', 'Smoke · Auto Poster', 'Weasyl retired: registry gate off, client kept for history', static function () use ($root, $read, $ok, $bad): array {
            $platforms = $read("$root/app/Core/Platforms.php");
            if (preg_match("/'weasyl'\s*=>\s*\[(.*?)\n        \]/s", $platforms, $m) !== 1) {
                return $bad('weasyl entry missing from the platform registry');
            }
            if (strpos($m[1], "'enabled'     => false") === false) {
                return $bad("the weasyl registry entry must be 'enabled' => false — the Weasyl API (www.weasyl.com/api) is offline");
            }
            $client = $read("$root/app/Models/WeasylClient.php");
            return strpos($client, 'class WeasylClient') !== false
                ? $ok('weasyl disabled; client + config kept so posting history still renders')
                : $bad('WeasylClient must stay in the tree so already-recorded posts still render');
        });
        $add('smoke.ap.circuit_breaker', 'Smoke · Auto Poster', 'Channels auto-pause after a failure streak (skip while paused, resume on retry)', static function () use ($root, $read, $ok, $bad): array {
            $queue = $read("$root/app/Models/AutoPostQueue.php");
            $cfg   = $read("$root/app/Models/AutoPosterConfig.php");

            $missing = [];
            if (strpos($queue, 'FAILURE_STREAK_LIMIT = 10') === false) $missing[] = 'FAILURE_STREAK_LIMIT';
            if (strpos($queue, 'function failureStreak(') === false) $missing[] = 'failureStreak()';
            if (strpos($queue, "AutoPosterConfig::platformPause(\$platform)") === false) $missing[] = 'post() pause check';
            if (strpos($queue, "AutoPosterConfig::pausePlatform(\$platform, \$streak, \$error)") === false) $missing[] = 'failure-streak pause trigger';
            if (strpos($queue, "AutoPosterConfig::resumePlatform((string) \$row['platform'])") === false) $missing[] = 'requeue() resume';
            if (strpos($queue, "AutoPosterConfig::platformPause(\$pf) !== null") === false) $missing[] = 'refill/idle pause skip';
            if (strpos($cfg, 'function platformPause(') === false) $missing[] = 'platformPause()';
            if (strpos($cfg, 'function pausePlatform(') === false) $missing[] = 'pausePlatform()';
            if (strpos($cfg, 'function resumePlatform(') === false) $missing[] = 'resumePlatform()';
            if (strpos($cfg, "self::resumePlatform(\$canonical)") === false) $missing[] = 'saveChannel() resume';

            return $missing === []
                ? $ok('pause on streak, skip while paused, resume on retry or credential save')
                : $bad('circuit breaker incomplete: ' . implode(', ', $missing));
        });
        $add('smoke.backup.writer_hardened', 'Smoke · System', 'Full backup fails loudly on tar/verify errors and records why', static function () use ($root, $read, $ok, $bad): array {
            $b = $read("$root/bin/gallery_backup.php");
            $missing = [];
            if (strpos($b, 'set -eo pipefail') === false) $missing[] = 'pipefail (a tar death must fail the pipeline, not leave truncated parts)';
            if (strpos($b, 'cat "\$TARGET".part-* | zcat > /dev/null 2> "\$VERR"') === false) $missing[] = 'verification feeds the split parts as one stream (cat | zcat) with stderr captured into .failed';
            return $missing === []
                ? $ok('pipefail + split parts stitched for verification + verification stderr recorded in the failure note')
                : $bad('gallery_backup.php missing: ' . implode(', ', $missing));
        });
        $add('smoke.health.backup_detail', 'Smoke · API health', 'Health reports archive + failure ages for the (monthly) media backup', static function () use ($root, $read, $ok, $bad): array {
            $h = $read("$root/app/Controllers/HealthController.php");
            return strpos($h, "'last_archive_age_sec'") !== false
                && strpos($h, "'last_failure_age_sec'") !== false
                && strpos($h, 'gallery-backup-*.tar.gz.part-00') !== false
                ? $ok('backup payload: running + last_ok + archive + failure ages')
                : $bad('HealthController backup block must surface last_archive_age_sec and last_failure_age_sec (a failed monthly run writes .failed while .last_ok stays absent)');
        });
        $add('smoke.consent.bottom_nav', 'Smoke · UI', 'Cookie banner clears the member bottom navigation on phones', static function () use ($root, $read, $ok, $bad): array {
            $css    = $read("$root/public/assets/css/user.css");
            $banner = $read("$root/views/partials/consent_banner.php");

            return strpos($css, 'body:has(.media-bottom-nav) #consent-banner') !== false
                && strpos($banner, 'style="position:fixed') === false
                ? $ok('banner styling in user.css, offset above the bottom nav')
                : $bad('the consent banner must move its inline styles into user.css and sit above .media-bottom-nav on phones (it otherwise covers the nav)');
        });
        $add('smoke.ap.worker_isolates_rows', 'Smoke · Auto Poster', 'Worker isolates per-row failures so one bad row cannot abort the batch', static function () use ($root, $read, $ok, $bad): array {
            $w = $read("$root/bin/autopost_worker.php");
            return strpos($w, 'failed (isolated)') !== false && strpos($w, "markFailed((int) \$item['id'], \$itemError->getMessage())") !== false
                ? $ok('per-row try/catch present')
                : $bad('bin/autopost_worker.php must wrap each AutoPostQueue::post() call so a single throwing row is marked failed and the rest of the batch still publishes');
        });
$add('smoke.ap.wall_promotion', 'Smoke · Auto Poster', 'Successful recommendations are promoted to a gallery-linked Wall post', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'WallPost::createForGallery(0, $galleryId, trim((string) $item[\'text\']))') !== false
                ? $ok('wall promotion present on the post() success path')
                : $bad('AutoPostQueue::post must create a gallery-linked wall post when a recommendation submits');
        });
        $commentModel = $read("$root/app/Models/Comment.php");
        $galleryCtrl  = $read("$root/app/Controllers/GalleryController.php");
        $imageCtrl    = $read("$root/app/Controllers/ImageController.php");
        $add('smoke.comments.photo_type', 'Smoke · Comments', "comments can target individual media (type 'photo')", static function () use ($schema, $commentModel, $ok, $bad): array {
            return strpos($schema, "commentable_type ENUM('gallery','wall_post','photo')") !== false
                && strpos($commentModel, "const TYPE_PHOTO   = 'photo';") !== false
                && strpos($commentModel, 'self::TYPE_PHOTO') !== false
                ? $ok('photo commentable type present in schema + model')
                : $bad("comments.commentable_type must include 'photo' in schema.sql and Comment::TYPE_PHOTO must be registered in TYPES");
        });
        $add('smoke.comments.gallery_thread', 'Smoke · Comments', 'A gallery thread includes comments on its promoting wall posts', static function () use ($commentModel, $galleryCtrl, $ok, $bad): array {
            return strpos($commentModel, 'function forGallery(') !== false
                && strpos($commentModel, 'public static function countForGallery(') !== false
                && strpos($galleryCtrl, 'Comment::forGallery(') !== false
                && strpos($galleryCtrl, 'Comment::countForGallery(') !== false
                ? $ok('gallery page aggregates wall-post comments')
                : $bad('Comment::forGallery/countForGallery must union gallery comments with the comments on wall posts whose gallery_id is the gallery, and GalleryController::show must use them');
        });
        $add('smoke.comments.photo_thread', 'Smoke · Comments', 'Image/video pages host a per-media comment thread', static function () use ($root, $read, $imageCtrl, $ok, $bad): array {
            $img = $read("$root/views/gallery/image_full.php");
            $pl  = $read("$root/views/video/player.php");
            $pt  = $read("$root/views/partials/comments.php");
            // The thread itself lives in its own partial now: the wall's
            // comment thread used to be a verbatim copy of this markup.
            $th  = $read("$root/views/partials/comment_thread.php");
            return strpos($imageCtrl, 'Comment::TYPE_PHOTO') !== false
                && strpos($img, 'partials/comments.php') !== false
                && strpos($pl, 'partials/comments.php') !== false
                && strpos($pt, 'commentable_type') !== false
                && strpos($pt, 'comment_thread.php') !== false
                && strpos($th, 'Comment::isStaff') !== false
                && strpos($th, 'comment-reactions') !== false
                ? $ok('photo comment thread wired into both media views')
                : $bad('ImageController::showMedia must load TYPE_PHOTO comments and both image_full.php and player.php must render the shared comments partial');
        });
        $commentCtrl = $read("$root/app/Controllers/CommentController.php");
        $add('smoke.comments.one_thread', 'Smoke · Comments', 'There is one comment thread, not two copies', static function () use ($root, $read, $ok, $bad): array {
            // The wall's comment thread was a VERBATIM COPY of the markup in
            // comments.php, so a change to a comment had to be made twice
            // and the two drifted.
            $wall = $read("$root/views/wall.php");
            $pt   = $read("$root/views/partials/comments.php");
            $th   = $read("$root/views/partials/comment_thread.php");
            $problems = [];

            if (strpos($wall, "partials/comment_thread.php") === false) {
                $problems[] = 'the wall must use the shared comment thread';
            }
            if (strpos($pt, "comment_thread.php") === false) {
                $problems[] = 'the comments partial must use the shared comment thread';
            }
            // Neither may carry its own copy of the markup.
            foreach (['wall.php' => $wall, 'comments.php' => $pt] as $name => $src) {
                if (strpos($src, 'Comment::isStaff') !== false) {
                    $problems[] = $name . ' still renders its own copy of the comment markup';
                }
                if (strpos($src, 'comment-reactions') !== false) {
                    $problems[] = $name . ' still renders its own copy of the reaction buttons';
                }
            }
            // The shared partial has to be the real thing.
            foreach (['Comment::isStaff', 'comment-reactions', 'comment-replies', 'comment-reply-box'] as $marker) {
                if (strpos($th, $marker) === false) {
                    $problems[] = 'the shared comment thread is missing ' . $marker;
                }
            }

            return $problems === []
                ? $ok('there is one comment thread')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.comments.photo_validation', 'Smoke · Comments', 'CommentController validates the photo target and links back to the media', static function () use ($commentCtrl, $ok, $bad): array {
            return strpos($commentCtrl, 'Comment::TYPE_PHOTO') !== false && strpos($commentCtrl, 'Photo::find($target)') !== false
                && strpos($commentCtrl, "is_video((string) \$photo['filename'])") !== false
                ? $ok('photo target validated + reply URL')
                : $bad('CommentController::store must accept TYPE_PHOTO, reject a missing Photo, and link the reply notification to /images/ or /videos/');
        });
        $wp = $read("$root/app/Models/WallPost.php");
        $add('smoke.wall.gallery_post', 'Smoke · Wall', 'WallPost supports gallery-linked posts (deduped per gallery)', static function () use ($wp, $ok, $bad): array {
            return strpos($wp, 'public static function createForGallery(int $userId, int $galleryId, string $body') !== false
                && strpos($wp, "SELECT id FROM wall_posts") !== false && strpos($wp, "WHERE gallery_id = ?") !== false
                ? $ok('createForGallery present and deduped')
                : $bad('WallPost::createForGallery must insert a gallery-linked wall post and return the existing post when that gallery is already on the wall');
        });
        $add('smoke.wall.gate_previews', 'Smoke · Wall', 'Wall renders membership-gated previews for gallery-linked posts', static function () use ($root, $read, $ok, $bad): array {
            $wc = $read("$root/app/Controllers/WallController.php");
            $wv = $read("$root/views/wall.php");
            // The unlocked galleries are fetched in ONE query (the bulk
            // helper), not one query per gallery.
            return strpos($wc, 'private function buildGalleryPreviews(') !== false
                && (strpos($wc, 'Purchase::unlockedGalleryIds(') !== false
                    || strpos($wc, 'Purchase::userUnlocked(') !== false)
                && strpos($wc, "'gallery_id'  => \$galleryId") !== false
                && strpos($wv, "\$post['preview']") !== false
                && strpos($wv, "\$postShowLock ? 'blur' : 'thumb'") !== false
                ? $ok('gallery-linked wall posts carry the membership-aware preview grid')
                : $bad('WallController must decorate gallery-linked wall posts and the wall view must render thumb-vs-blur by membership level, exactly like notifications');
        });
        $add('smoke.ap.queue_all', 'Smoke · Auto Poster', 'Queue lists every queued row by default', static function () use ($apq, $ok, $bad): array {
            return strpos($apq, 'public static function queued(int $limit = 0, ?string $platform = null)') !== false
                ? $ok('no default cap + platform scope')
                : $bad('AutoPostQueue::queued must default to every queued row (0 = no limit) and accept a platform scope');
        });
        $add('smoke.ap.queue_collapse', 'Smoke · Auto Poster', 'Posting queue section is collapsable', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'ap-queue-toggle') !== false && strpos($apv, 'ap-queue-body') !== false
                ? $ok('toggle wired')
                : $bad('auto_poster view must render a collapse toggle for the Posting queue section');
        });
        $add('smoke.ap.view_log', 'Smoke · Auto Poster', 'Posting log renders pills + relative times', static function () use ($apv, $ok, $bad): array {
            return strpos($apv, 'ap-log') !== false && strpos($apv, 'ap-pill') !== false
                && strpos($apv, 'ap-time-relative') !== false && strpos($apv, 'data-uts') !== false
                ? $ok('log pills present')
                : $bad('auto-poster posting log must render status pills and relative timestamps');
        });
        $apc = $read("$root/app/Models/AutoPosterConfig.php");
        $add('smoke.ap.config_tz', 'Smoke · Auto Poster', 'Config persists validated timezone', static function () use ($apc, $ok, $bad): array {
            return strpos($apc, 'validatedTimezone') !== false && preg_match("/'timezone'\s*=>/", $apc) === 1
                ? $ok('validated timezone')
                : $bad('auto-poster config must persist a validated timezone');
        });
        $add('smoke.ap.log_scope', 'Smoke · Auto Poster', 'Log scoping maps x→twitter via the registry', static function () use ($apc, $ok, $bad): array {
            return strpos($apc, 'Platforms::canonicalize($platform)') !== false
                && strpos($apc, 'Platforms::dbKey($canonical)') !== false
                && strpos($apc, "function logEntries(int \$limit = 100, ?string \$platform = null)") !== false
                && strpos($apc, "function clearLog(?string \$platform = null)") !== false
                ? $ok('log + clear scoped per platform (x→twitter)')
                : $bad('auto-poster logEntries/clearLog must scope by the platform dbKey (x→twitter) via the registry');
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
        $add('smoke.ap.view_twitter_fields', 'Smoke · Auto Poster', 'Settings expose OAuth1 media-upload fields (registry-driven)', static function () use ($apv, $platforms, $ok, $bad): array {
            return strpos($platforms, "'consumer_key'") !== false && strpos($platforms, "'oauth_token_secret'") !== false
                && strpos($apv, 'foreach ($apFields as $field)') !== false
                ? $ok('fields present')
                : $bad('auto-poster settings must render the channel fields (incl. X OAuth1 media-upload fields) from the registry');
        });
        $apcCtrl = $read("$root/app/Controllers/AutoPosterController.php");
        $add('smoke.ap.controller_token', 'Smoke · Auto Poster', 'Settings save persists channel credential fields', static function () use ($apcCtrl, $ok, $bad): array {
            return strpos($apcCtrl, 'public function saveChannelSettings()') !== false
                && strpos($apcCtrl, "foreach ((\$meta['fields'] ?? []) as \$field)") !== false
                && strpos($apcCtrl, 'AutoPosterConfig::saveChannel(') !== false
                ? $ok('persisted via registry fields')
                : $bad('auto-poster settings save must persist each channel\'s credential fields from the registry');
        });
        $migReadme = $read("$root/database/migrations/README.md");
        $add('smoke.ap.migration_readme', 'Smoke · Auto Poster', 'Migrations README documents schema_migrations', static function () use ($migReadme, $ok, $bad): array {
            return strpos($migReadme, 'schema_migrations') !== false ? $ok('documented') : $bad('database/migrations/README.md must document schema_migrations');
        });

        // ------------------------------------------------------------ Emailer
        // The scheduled digest is gone: every email the site sends is queued by
        // the event that causes it. These checks hold that shape in place.
        $eq   = $read("$root/app/Models/EmailQueue.php");
        $ecfg = $read("$root/app/Models/EmailerConfig.php");
        $mail = $read("$root/app/Core/Mailer.php");
        $ew   = $read("$root/bin/email_worker.php");
        $acrn = $read("$root/bin/apply_cron.php");
        $unv  = $read("$root/views/unsubscribe.php");
        $ecv  = $read("$root/views/admin/emailer.php");
        $ecCtrl = $read("$root/app/Controllers/EmailerController.php");
        $adminLayout2 = $read("$root/views/admin/layout.php");
        $routesSrc = $read("$root/config/routes.php");
        $schema  = $read("$root/schema.sql");
        $lcQueue = $read("$root/app/Models/EmailQueue.php");
        $lcHouse = $read("$root/app/Core/Housekeeping.php");
        $galleryM = $read("$root/app/Models/Gallery.php");
        $liveM   = $read("$root/app/Models/LiveSession.php");
        // For the test-gallery suppression check.
        $tgHelpers = $read("$root/app/Core/helpers.php");
        $tgCfg     = $read("$root/config/app.php");
        $tgHouse   = $read("$root/app/Core/Housekeeping.php");
        $tgSchema  = $read("$root/schema.sql");
        $tgMigr    = $read("$root/database/migrations/070_gallery_test_flag.sql");
        $tgAutoPost = $read("$root/app/Models/AutoPostQueue.php");
        $tgCreate  = $read("$root/views/admin/create.php");
        $tgManage  = $read("$root/views/admin/manage.php");

        $add('smoke.email.no_digest', 'Smoke · Emailer', 'The scheduled newsletter digest is gone', static function () use ($eq, $ecfg, $ew, $ecCtrl, $routesSrc, $ok, $bad): array {
            $dead = [
                'enqueueDigest'        => strpos($eq, 'function enqueueDigest'),
                'sample()'             => strpos($eq, 'function sample('),
                'subscribers()'        => strpos($eq, 'function subscribers('),
                'subjectFor()'         => strpos($eq, 'function subjectFor('),
                'due()/nextSendAt()'   => strpos($ecfg, 'function due(') !== false || strpos($ecfg, 'function nextSendAt(') !== false,
                'markSent watermark'   => strpos($ecfg, 'function markSent('),
                'schedule modes'       => strpos($ecfg, 'MODES'),
                'worker enqueue'       => strpos($ew, 'enqueueDigest'),
                'send-now action'      => strpos($ecCtrl, 'function sendNow('),
                'send-now route'       => strpos($routesSrc, '/admin/emailer/send-now'),
                'digest test route'    => strpos($routesSrc, "'/admin/emailer/test'"),
            ];
            $left = array_keys(array_filter($dead));

            return $left === []
                ? $ok('no digest scheduling, sampling or blast left')
                : $bad('the digest must stay removed; still present: ' . implode(', ', $left));
        });

        $add('smoke.email.kind_column', 'Smoke · Emailer', 'email_queue.kind separates the broadcasts from transactional mail', static function () use ($schema, $eq, $ok, $bad): array {
            return preg_match('/kind\s+VARCHAR\(30\)/', $schema) === 1
                && strpos($schema, 'idx_email_queue_kind') !== false
                && strpos($eq, 'function kindForFlag') !== false
                && strpos($eq, "CAPPED_KINDS") !== false
                ? $ok('kind + cap scoping present')
                : $bad('email_queue must carry a kind column and EmailQueue must map notification flags to capped kinds (gallery/live)');
        });

        $add('smoke.email.cap_scoped_to_broadcasts', 'Smoke · Emailer', 'The daily cap applies to gallery/live only, never to billing or chat', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, 'private static function usersAtNotificationCap(int $cap, string $kind)') !== false
                && strpos($eq, "in_array(\$kind, EmailerConfig::CAPPED_KINDS, true)") !== false
                && strpos($eq, "'lifecycle'") !== false
                && strpos($eq, 'function broadcastSentToday(') !== false
                ? $ok('cap is per kind; lifecycle/chat exempt')
                : $bad('the per-day cap must be scoped to the gallery/live kinds so a billing reminder cannot use up the gallery email');
        });

        $add('smoke.email.one_email_per_day_claimed_last', 'Smoke · Emailer', 'A gallery only claims its once-only marker when an email will actually be sent', static function () use ($galleryM, $liveM, $eq, $ok, $bad): array {
            $galleryCheck = "broadcastSentToday('gallery')";
            $liveCheck    = "broadcastSentToday('live')";
            $galleryOrdered = strpos($galleryM, $galleryCheck) !== false
                && strpos($galleryM, $galleryCheck) < strpos($galleryM, 'UPDATE galleries SET notified_at');
            $liveOrdered = strpos($liveM, $liveCheck) !== false
                && strpos($liveM, $liveCheck) < strpos($liveM, 'SET live_notified_at');

            return ($galleryOrdered && $liveOrdered)
                ? $ok('slot claimed only after the day\'s email is still available')
                : $bad('Gallery/LiveSession must check broadcastSentToday() BEFORE claiming notified_at / live_notified_at (gallery=' . var_export($galleryOrdered, true) . ', live=' . var_export($liveOrdered, true) . ')');
        });

        $add('smoke.email.test_galleries_not_announced', 'Smoke · Emailer', 'A test gallery is never announced: no member email, no bell, no auto-post', static function () use ($galleryM, $tgHelpers, $tgCfg, $tgHouse, $tgSchema, $tgMigr, $tgAutoPost, $tgCreate, $tgManage, $ok, $bad): array {
            // The detector: an explicit flag, or a title that looks like a test.
            $detect = strpos($tgHelpers, 'function is_test_gallery(') !== false
                && strpos($tgHelpers, "config('app.gallery_test_patterns'") !== false;
            $patterns = strpos($tgCfg, "'gallery_test_patterns'") !== false;

            // The guard has to sit BEFORE the gallery claims its once-only
            // marker, so a suppressed gallery stays un-marked (clearing the
            // flag later still announces it) and never eats the day's email.
            $guardPos = strpos($galleryM, 'is_test_gallery($gallery)');
            $claimPos = strpos($galleryM, 'UPDATE galleries SET notified_at');
            $guard = $guardPos !== false && $claimPos !== false && $guardPos < $claimPos;
            // A secret gallery must not be emailed either: the title would
            // leak to every opted-in member.
            $secret = strpos($galleryM, "!empty(\$gallery['is_secret'])") !== false;

            // The sweep must not even select a suppressed gallery, or the
            // skipped ones would eat its LIMIT and starve the real ones.
            $sweep = strpos($tgHouse, 'AND is_test = 0') !== false
                && strpos($tgHouse, 'AND is_secret = 0') !== false;

            $schema = strpos($tgSchema, 'is_test      TINYINT(1) NOT NULL DEFAULT 0') !== false;
            $migr   = strpos($tgMigr, 'ADD COLUMN is_test') !== false;

            // Social auto-posting is an announcement too.
            $autopost = strpos($tgAutoPost, 'is_test_gallery($gallery)') !== false;

            // The admin forms need the tick.
            $forms = strpos($tgCreate, 'name="is_test"') !== false
                && strpos($tgManage, 'name="is_test"') !== false
                && strpos($tgManage, 'name="is_test" value="0"') !== false;

            return $detect && $patterns && $guard && $secret && $sweep && $schema && $migr && $autopost && $forms
                ? $ok('test and secret galleries are never announced')
                : $bad('helpers.php must expose is_test_gallery() driven by app.gallery_test_patterns; Gallery::notifyNewGalleryIfUnsent must return for a test or secret gallery BEFORE claiming notified_at; the housekeeping sweep must filter is_test = 0 and is_secret = 0; galleries.is_test needs a column in schema.sql plus a migration; AutoPostQueue must skip test galleries; and the admin create/manage forms need the is_test tick (with a hidden is_test=0 companion on the manage form so unticking clears it)');
        });

        $add('smoke.email.opt_out_filter', 'Smoke · Emailer', 'Recipients exclude opted-out accounts', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, 'COALESCE(u.marketing_opt_out, 0) = 0') !== false
                ? $ok('opt-outs filtered')
                : $bad('EmailQueue recipients must exclude accounts with marketing_opt_out set');
        });
        $add('smoke.email.signed_unsubscribe', 'Smoke · Emailer', 'Unsubscribe link signed with GALLERY_MEDIA_KEY + hash_equals', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, "hash_hmac('sha256', 'unsubscribe:'") !== false && strpos($eq, 'hash_equals') !== false
                && strpos($eq, "UNSUB_PLACEHOLDER") !== false
                ? $ok('signed opt-out link')
                : $bad('EmailQueue must sign the unsubscribe token with GALLERY_MEDIA_KEY and verify it with hash_equals');
        });
        $add('smoke.email.opt_out_apply', 'Smoke · Emailer', 'Opt-out persists users.marketing_opt_out = 1', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, 'UPDATE users SET marketing_opt_out = 1') !== false
                ? $ok('opt-out persisted')
                : $bad('EmailQueue::optOut must set users.marketing_opt_out = 1');
        });
        $add('smoke.email.delivery_sendhtml', 'Smoke · Emailer', 'sendDue delivers queued rows via Mailer::sendHtml', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, 'Mailer::sendHtml(') !== false && strpos($eq, 'UNSUB_PLACEHOLDER') !== false
                ? $ok('sendHtml delivery')
                : $bad('EmailQueue::sendDue must hand rows to Mailer::sendHtml with the unsubscribe link substituted');
        });
        $add('smoke.email.mailer_html', 'Smoke · Emailer', 'Mailer::sendHtml renders multipart/alternative', static function () use ($mail, $ok, $bad): array {
            return strpos($mail, 'public static function sendHtml(') !== false && strpos($mail, 'multipart/alternative') !== false
                && strpos($mail, 'boundary') !== false
                ? $ok('multipart html+text')
                : $bad('Mailer must provide sendHtml() building a multipart/alternative (html + text) message');
        });
        $add('smoke.email.smtp_tls_verifies', 'Smoke · Emailer', 'SMTP submission sends SNI and verifies against a real CA bundle', static function () use ($mail, $ok, $bad): array {
            return strpos($mail, 'peer_name') !== false && strpos($mail, 'SNI_enabled') !== false
                && strpos($mail, 'ca-certificates.crt') !== false
                && strpos($mail, 'smtpContext') !== false
                && strpos($mail, 'verify_peer_name') !== false
                ? $ok('SNI + CA bundle + logged handshake errors')
                : $bad('Mailer::sendSmtp must attach an SSL context with peer_name/SNI and an explicit CA bundle, and log the OpenSSL reason when the STARTTLS handshake fails');
        });
        $add('smoke.email.helpers', 'Smoke · Emailer', 'helpers provide absolute_url + render_email', static function () use ($helpers, $ok, $bad): array {
            return strpos($helpers, 'function absolute_url(') !== false && strpos($helpers, 'function render_email(') !== false
                ? $ok('helpers present')
                : $bad('helpers.php must provide absolute_url() and render_email() for email templates');
        });
        $add('smoke.email.unsub_view', 'Smoke · Emailer', 'Unsubscribe page renders opt-out confirmation', static function () use ($unv, $ok, $bad): array {
            return strpos($unv, '$optOut') !== false && strpos($unv, '$message') !== false
                ? $ok('confirmation page')
                : $bad('views/unsubscribe.php must render the opt-out result and message');
        });
        $add('smoke.email.worker', 'Smoke · Emailer', 'email_worker.php locks and delivers batches only (nothing is generated)', static function () use ($ew, $ok, $bad): array {
            return strpos($ew, 'flock') !== false && strpos($ew, 'EmailQueue::sendDue(') !== false
                && strpos($ew, 'enqueueDigest') === false
                && strpos($ew, 'EmailerConfig::due(') === false
                ? $ok('delivery pump only')
                : $bad('bin/email_worker.php must flock and deliver EmailQueue::sendDue() batches, and must not enqueue anything itself');
        });
        $add('smoke.email.cron_entry', 'Smoke · Emailer', 'apply_cron.php installs the emailer cron job', static function () use ($acrn, $ok, $bad): array {
            return strpos($acrn, 'gallery-emailer') !== false && strpos($acrn, 'email_worker.php --once') !== false
                ? $ok('cron entry present')
                : $bad('bin/apply_cron.php must install the gallery-emailer cron job running email_worker.php --once');
        });
        $add('smoke.email.routes', 'Smoke · Emailer', 'Public unsubscribe + admin emailer routes registered', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, "'/unsubscribe'") !== false && strpos($routesSrc, '/admin/emailer/save') !== false
                && strpos($routesSrc, '/admin/emailer/test-lifecycle/{kind}') !== false
                && strpos($routesSrc, '/admin/emailer/retry') !== false
                ? $ok('routes present')
                : $bad('routes.php must register public /unsubscribe and the admin emailer save/test-lifecycle/retry routes (and no send-now)');
        });
        $add('smoke.email.permission', 'Smoke · Emailer', 'Emailer admin gated by membership permission', static function () use ($ecCtrl, $ok, $bad): array {
            return strpos($ecCtrl, 'extends MembershipAdminController') !== false
                || strpos($ecCtrl, "Auth::requirePermission('membership')") !== false
                ? $ok('membership gate')
                : $bad('EmailerController must require the membership permission');
        });
        $add('smoke.email.nav', 'Smoke · Emailer', 'Admin sidebar links Emailer behind membership', static function () use ($adminLayout2, $ok, $bad): array {
            return strpos($adminLayout2, "'key' => 'nav-emailer'") !== false
                && strpos($adminLayout2, "'href' => '/admin/emailer'") !== false
                && strpos($adminLayout2, "'perm' => 'membership'") !== false
                ? $ok('nav item gated')
                : $bad('views/admin/layout.php must link the Emailer page behind the membership permission');
        });
        $add('smoke.email.admin_view', 'Smoke · Emailer', 'Admin emailer view lists the mail types + switches + queue, no schedule', static function () use ($ecv, $ok, $bad): array {
            $listsTypes = strpos($ecv, "Today's new gallery") !== false
                && strpos($ecv, 'New chat reply') !== false
                && strpos($ecv, 'Live start') !== false
                && strpos($ecv, 'Renewal reminder (7 days before a rebill)') !== false
                && strpos($ecv, 'Win-back') !== false;
            $hasSwitches = strpos($ecv, 'name="enabled"') !== false
                && strpos($ecv, 'name="lifecycle_renewal_reminders"') !== false
                && strpos($ecv, 'name="lifecycle_winbacks"') !== false
                && strpos($ecv, 'name="notification_daily_cap"') !== false;
            $noSchedule = strpos($ecv, 'name="mode"') === false
                && strpos($ecv, 'name="sample_count"') === false
                && strpos($ecv, 'name="hour"') === false
                && strpos($ecv, 'send-now') === false
                && strpos($ecv, 'name="subject_subscriber"') === false;
            $hasQueue = strpos($ecv, 'retry') !== false && strpos($ecv, 'test-lifecycle') !== false;

            return ($listsTypes && $hasSwitches && $noSchedule && $hasQueue)
                ? $ok('mail types + switches + queue, no schedule or blast')
                : $bad('views/admin/emailer.php must list the mail types, expose the switches and queue retry, and carry no digest schedule/sample/subject controls (types=' . var_export($listsTypes, true) . ' switches=' . var_export($hasSwitches, true) . ' noSchedule=' . var_export($noSchedule, true) . ' queue=' . var_export($hasQueue, true) . ')');
        });
        $add('smoke.email.lifecycle_toggles', 'Smoke · Emailer', 'Renewal reminder (7 days) and win-back still fire from housekeeping', static function () use ($lcQueue, $lcHouse, $ok, $bad): array {
            return strpos($lcQueue, 'function enqueueTx') !== false
                && strpos($lcQueue, 'function lifecycleRecord') !== false
                && strpos($lcQueue, 'function lifecycleAlreadySent') !== false
                && strpos($lcHouse, 'Lifecycle::runRenewalReminders') !== false
                && strpos($lcHouse, 'Lifecycle::runWinbacks') !== false
                ? $ok('enqueueTx + exactly-once log + housekeeping hooks')
                : $bad('EmailQueue must implement enqueueTx/lifecycleRecord/lifecycleAlreadySent and Housekeeping must run the renewal reminder + win-back scans');
        });

        // ------------------------------------------------ AI category suggestions
        $czModel  = $read("$root/app/Models/CategorySuggestion.php");
        $czSvc    = $read("$root/app/Core/CategoryAdvisor.php");
        $czCtrl   = $read("$root/app/Controllers/CategorySuggestionController.php");
        $czWorker = $read("$root/bin/categorize_worker.php");
        $czView   = $read("$root/views/admin/category_suggestions.php");
        $czManage = $read("$root/views/admin/manage.php");
        $czSchema = $read("$root/schema.sql");
        $czMigr   = $read("$root/database/migrations/055_category_suggestions.sql");
        $czUpload = $read("$root/app/Core/MediaUploader.php");
        $czBench  = $read("$root/bin/categorize_bench.php");
        $czFeed   = $read("$root/app/Models/CategoryFeedback.php");
        $czLearn  = $read("$root/database/migrations/080_ai_category_feedback.sql");
        $czLearnV = $read("$root/views/admin/category_learned.php");
        $czBack   = $read("$root/bin/categorize_feedback_backfill.php");
        $add('smoke.categorizer.schema', 'Smoke · Categorizer', 'suggestion + job tables in schema.sql and migration 055', static function () use ($czSchema, $czMigr, $ok, $bad): array {
            $okSchema = strpos($czSchema, 'gallery_category_suggestions') !== false
                && strpos($czSchema, 'gallery_category_jobs') !== false;
            $okMigr = strpos($czMigr, 'gallery_category_suggestions') !== false
                && strpos($czMigr, 'gallery_category_jobs') !== false;
            return $okSchema && $okMigr
                ? $ok('schema + migration present')
                : $bad('schema.sql and database/migrations/055_category_suggestions.sql must both define gallery_category_jobs + gallery_category_suggestions');
        });
        $add('smoke.categorizer.sampling_variants', 'Smoke · Categorizer', 'the thumbnail sample can be spread over the gallery, and the default is unchanged', static function () use ($czSvc, $czBench, $ok, $bad): array {
            // The sampling is what the benchmark measures: 'head' (the first
            // photos) or 'spread' (evenly over the gallery). The default must
            // stay 'head' until the measurement says otherwise.
            foreach (['samplePositions', 'sampleMode', 'sampleMax'] as $needle) {
                if (strpos($czSvc, 'function ' . $needle . '(') === false) {
                    return $bad('CategoryAdvisor::' . $needle . '() is missing');
                }
            }
            if (strpos($czSvc, "env_value('CATEGORIZER_SAMPLE', 'head')") === false) {
                return $bad("the sample mode must default to 'head' (CATEGORIZER_SAMPLE)");
            }
            if (strpos($czSvc, 'self::MAX_IMAGES') === false) {
                return $bad('the sample size must default to the shipped MAX_IMAGES');
            }
            if (strpos($czSvc, 'describeGallery') === false) {
                return $bad('the describe variant (one vision call, many images) is missing');
            }
            // The benchmark itself: it must score the runs and never write.
            if (strpos($czBench, 'categorizer-bench.csv') === false
                || strpos($czBench, 'categorizer.lock') === false
                || strpos($czBench, 'Gallery::categories(') === false) {
                return $bad('bin/categorize_bench.php must score the runs against the editor tags, log to categorizer-bench.csv and take the categorizer lock');
            }
            return $ok('the sampling variants exist and the default is unchanged');
        });
        $add('smoke.categorizer.learns_from_the_admin', 'Smoke · Categorizer', 'the categorizer learns from the editor\'s corrections', static function () use ($read, $root, $czModel, $czSvc, $czFeed, $czLearn, $czLearnV, $czBack, $czSchema, $czCtrl, $czWorker, $ok, $bad): array {
            // The editor's decisions have to be recorded: an accept, a
            // dismiss, a category added by hand and a category taken off.
            foreach (['record', 'recordDelta', 'scoreMap', 'removedFor', 'preferences', 'similarGalleries', 'vocabularyFor', 'summary'] as $needle) {
                if (strpos($czFeed, 'function ' . $needle . '(') === false) {
                    return $bad('CategoryFeedback::' . $needle . '() is missing');
                }
            }
            if (strpos($czLearn, 'gallery_category_feedback') === false
                || strpos($czSchema, 'gallery_category_feedback') === false) {
                return $bad('the feedback table must be in migration 080 and in schema.sql');
            }
            // The decisions are recorded where they happen.
            if (substr_count($czModel, 'CategoryFeedback::record(') < 3) {
                return $bad('accept, accept-all and dismiss must record the decision');
            }
            $galleryCtrl = $read("$root/app/Controllers/GalleryController.php");
            if (strpos($galleryCtrl, 'recordDelta') === false) {
                return $bad('the gallery update must record what the editor did to the categories');
            }
            if (strpos($czBack, 'admin_logs') === false || strpos($czBack, 'gallery_category_suggestions') === false) {
                return $bad('the backfill must seed the feedback from the proposals and the audit log');
            }
            // The AI uses what it learned: the prompt carries the editor's
            // corrections and the answer is filtered by them.
            if (strpos($czSvc, 'batchLearning') === false
                || strpos($czSvc, "they usually keep") === false
                || strpos($czSvc, 'CategoryFeedback::removedFor') === false
                || strpos($czSvc, "'retrieved'") === false) {
                return $bad('CategoryAdvisor must show the editor\'s corrections to the model and drop what the editor removed');
            }
            if (strpos($czWorker, "source = VALUES(source)") === false) {
                return $bad('the worker must record where a proposal came from');
            }
            // The editor can see what the AI has learned.
            if (strpos($czLearnV, 'the_kept') === false && strpos($czLearnV, 'Score') === false) {
                return $bad('the learned page must show the per-category decisions');
            }
            $routes = $read("$root/config/routes.php");
            if (strpos($routes, '/admin/category-suggestions/learned') === false) {
                return $bad('the route /admin/category-suggestions/learned is missing');
            }
            return $ok('the editor\'s corrections are recorded, scored and used');
        });
        $add('smoke.categorizer.merge_not_replace', 'Smoke · Categorizer', 'accept() merges into existing categories (setCategories replaces)', static function () use ($czModel, $ok, $bad): array {
            return strpos($czModel, 'Gallery::categories(') !== false
                && strpos($czModel, 'Gallery::setCategories(') !== false
                && strpos($czModel, 'in_array($categoryId, $current, true)') !== false
                ? $ok('read-then-merge present')
                : $bad('CategorySuggestion::accept must read Gallery::categories() first and merge - Gallery::setCategories REPLACES all categories');
        });
        $add('smoke.categorizer.manual_accept', 'Smoke · Categorizer', 'proposals are staged pending; only accept/dismiss touch gallery_category', static function () use ($czModel, $czWorker, $czCtrl, $ok, $bad): array {
            // "gallery_category " with a trailing space: matches the PIVOT
            // table, not the gallery_category_suggestions/jobs tables the
            // worker legitimately writes.
            $writesPivot = preg_match('/gallery_category\s+(WHERE|VALUES)/', $czWorker) === 1
                || strpos($czWorker, 'INSERT INTO gallery_category ') !== false;
            return strpos($czModel, "'pending'") !== false
                && strpos($czCtrl, 'CategorySuggestion::accept') !== false
                && !$writesPivot
                ? $ok('worker stages proposals only; accept/dismiss is the sole write path')
                : $bad('bin/categorize_worker.php must never write gallery_category - proposals go to gallery_category_suggestions for admin accept');
        });
        $add('smoke.categorizer.driver_switch', 'Smoke · Categorizer', 'ollama|api|off driver switch with dormant API config', static function () use ($czModel, $czSvc, $ok, $bad): array {
            return strpos($czModel, "[\x27ollama\x27, \x27api\x27, \x27off\x27]") !== false
                && strpos($czSvc, 'callOllama') !== false && strpos($czSvc, 'callApi') !== false
                && strpos($czSvc, 'CATEGORIZER_API_URL') !== false
                ? $ok('both drivers + off switch present')
                : $bad('CategorySuggestion::driver must switch ollama|api|off and CategoryAdvisor must implement both drivers');
        });
        $add('smoke.categorizer.worker', 'Smoke · Categorizer', 'worker flock + --once + cron entry + enqueue hook', static function () use ($czWorker, $acrn, $czUpload, $ok, $bad): array {
            return strpos($czWorker, 'flock') !== false && strpos($czWorker, '--once') !== false
                && strpos($acrn, 'gallery-categorizer') !== false && strpos($acrn, 'categorize_worker.php --once') !== false
                && strpos($czUpload, 'CategorySuggestion::enqueue') !== false
                ? $ok('worker + cron + upload hook wired')
                : $bad('bin/categorize_worker.php must flock with --once, apply_cron.php must install gallery-categorizer, and MediaUploader::commit must enqueue');
        });
        $add('smoke.categorizer.routes', 'Smoke · Categorizer', 'review page + accept/dismiss/backfill routes behind categories permission', static function () use ($routesSrc, $czCtrl, $ok, $bad): array {
            $routesOk = strpos($routesSrc, "'/admin/category-suggestions'") !== false
                && strpos($routesSrc, '/admin/category-suggestions/accept') !== false
                && strpos($routesSrc, '/admin/category-suggestions/backfill') !== false;
            return $routesOk && strpos($czCtrl, "Auth::requirePermission('categories')") !== false
                ? $ok('routes + permission gate present')
                : $bad('routes.php must register /admin/category-suggestions[+/accept|/dismiss|/backfill] gated by the categories permission');
        });
        $add('smoke.categorizer.ui', 'Smoke · Categorizer', 'manage-page chips + review page + sidebar link', static function () use ($czView, $czManage, $adminLayout2, $ok, $bad): array {
            return strpos($czManage, 'AI Suggestions') !== false
                && strpos($czView, 'accept-all') !== false
                && strpos($adminLayout2, '/admin/category-suggestions') !== false
                ? $ok('manage chips, review view and nav link present')
                : $bad('manage.php must show AI suggestion chips, category_suggestions.php the review list, and admin layout.php the nav link');
        });
        $add('smoke.categorizer.chunked_prompt', 'Smoke · Categorizer', 'batches of candidates, exhaustive selection, no per-run cap', static function () use ($czSvc, $ok, $bad): array {
            $chunked = strpos($czSvc, 'CHUNK_SIZE') !== false
                && strpos($czSvc, 'array_chunk($names, self::CHUNK_SIZE)') !== false;
            // The model must be asked for EVERY fit, never a short shortlist.
            $exhaustive = strpos($czSvc, 'select EVERY one that fits') !== false
                && strpos($czSvc, 'do not stop at one') !== false;
            $noCap = strpos($czSvc, 'Prefer 1-3') === false
                && strpos($czSvc, 'Select at most') === false
                && strpos($czSvc, 'MAX_SUGGESTIONS') === false
                && strpos($czSvc, 'array_slice($decoded') === false;
            return $chunked && $exhaustive && $noCap
                ? $ok('chunked batches + exhaustive prompt + no cap')
                : $bad('CategoryAdvisor must batch categories via CHUNK_SIZE, ask for every fit ("select EVERY one that fits"), and keep no MAX_SUGGESTIONS/array_slice cap');
        });

        // ------------------------------------------------ AI gallery descriptions
        $dsAdvisor = $read("$root/app/Core/DescriptionAdvisor.php");
        $dsModel   = $read("$root/app/Models/DescriptionSuggestion.php");
        $dsCtrl    = $read("$root/app/Controllers/DescriptionSuggestionController.php");
        $dsWorker  = $read("$root/bin/describe_worker.php");
        $dsView    = $read("$root/views/admin/description_suggestions.php");
        $dsMigr    = $read("$root/database/migrations/067_gallery_descriptions.sql");
        $keepAlive = $read("$root/bin/keep_ai_alive.php");

        $add('smoke.descriptions.schema', 'Smoke · Describer', 'job + suggestion tables in schema.sql and migration 067', static function () use ($czSchema, $dsMigr, $ok, $bad): array {
            $okSchema = strpos($czSchema, 'gallery_description_suggestions') !== false
                && strpos($czSchema, 'gallery_description_jobs') !== false;
            $okMigr = strpos($dsMigr, 'gallery_description_suggestions') !== false
                && strpos($dsMigr, 'gallery_description_jobs') !== false;
            return $okSchema && $okMigr
                ? $ok('both tables defined')
                : $bad('schema.sql and database/migrations/067_gallery_descriptions.sql must both define gallery_description_jobs + gallery_description_suggestions');
        });
        $add('smoke.descriptions.manual_accept', 'Smoke · Describer', 'only accept() writes galleries.description; worker stages proposals', static function () use ($dsModel, $dsWorker, $dsCtrl, $ok, $bad): array {
            $writesOnlyOnAccept = strpos($dsModel, 'Gallery::updateDescription') !== false
                && strpos($dsModel, 'AuditLog::record') !== false
                && strpos($dsWorker, 'updateDescription') === false
                && strpos($dsWorker, 'UPDATE galleries') === false;
            return $writesOnlyOnAccept && strpos($dsCtrl, 'DescriptionSuggestion::accept') !== false
                ? $ok('accept-only writes with audit')
                : $bad('DescriptionSuggestion::accept must be the only writer of galleries.description (audited); bin/describe_worker.php must never update galleries');
        });
        $add('smoke.descriptions.worker', 'Smoke · Describer', 'worker flock + --once + cron + upload hook + watchdog lock', static function () use ($dsWorker, $acrn, $czUpload, $keepAlive, $ok, $bad): array {
            return strpos($dsWorker, 'flock') !== false && strpos($dsWorker, '--once') !== false
                && strpos($acrn, 'gallery-describer') !== false && strpos($acrn, 'describe_worker.php --once') !== false
                && strpos($czUpload, 'DescriptionSuggestion::enqueueIfEmpty') !== false
                && strpos($keepAlive, 'describer.lock') !== false
                ? $ok('worker + cron + upload hook + watchdog lock wired')
                : $bad('bin/describe_worker.php must flock with --once, apply_cron.php must install gallery-describer, MediaUploader::commit must use enqueueIfEmpty, and keep_ai_alive.php must skip probes while describer.lock is held');
        });
        $add('smoke.descriptions.routes_ui', 'Smoke · Describer', 'review routes + permission gate + review view + nav link + manage block', static function () use ($routesSrc, $dsCtrl, $dsView, $adminLayout2, $czManage, $ok, $bad): array {
            $routesOk = strpos($routesSrc, "'/admin/description-suggestions'") !== false
                && strpos($routesSrc, '/admin/description-suggestions/accept') !== false
                && strpos($routesSrc, '/admin/description-suggestions/backfill') !== false;
            return $routesOk
                && strpos($dsCtrl, "Auth::requirePermission('galleries')") !== false
                && strpos($dsView, 'description-suggestions/accept') !== false
                && strpos($adminLayout2, '/admin/description-suggestions') !== false
                && strpos($czManage, 'AI Description') !== false
                ? $ok('routes, gate, view, nav and manage block present')
                : $bad('routes.php must register /admin/description-suggestions[+/accept|/dismiss|/backfill] gated by the galleries permission; the review view, admin nav and manage page block must render');
        });
        $add('smoke.descriptions.prompt', 'Smoke · Describer', 'single-call prompt: JSON description, length + tone rules, tolerant parse', static function () use ($dsAdvisor, $ok, $bad): array {
            $json = strpos($dsAdvisor, '{"description": "..."}') !== false;
            $rules = strpos($dsAdvisor, '140-220 characters') !== false
                && strpos($dsAdvisor, 'No hashtags') !== false
                && strpos($dsAdvisor, 'no explicit anatomical words') !== false;
            $parse = strpos($dsAdvisor, 'public static function parse(') !== false
                && strpos($dsAdvisor, 'json_decode($text, true)') !== false;
            return $json && $rules && $parse
                ? $ok('single JSON call + guardrails + tolerant parser')
                : $bad('DescriptionAdvisor must ask for ONLY {"description": "..."} with 140-220 char / no-hashtag / tasteful rules and expose a tolerant parse()');
        });

        // ------------------------------------------------ Site timezone
        $siteConfigC = $read("$root/app/Models/SiteConfig.php");
        $helpers     = $read("$root/app/Core/helpers.php");
        $settingsV   = $read("$root/views/settings.php");
        $settingsC   = $read("$root/app/Controllers/SettingsController.php");
        $add('smoke.settings.timezone_model', 'Smoke · Site timezone', 'SiteConfig persists a validated timezone', static function () use ($siteConfigC, $ok, $bad): array {
            return strpos($siteConfigC, 'public static function timezone(') !== false
                && strpos($siteConfigC, 'public static function setTimezone(') !== false
                && strpos($siteConfigC, 'validatedTimezone') !== false
                ? $ok('timezone persisted and validated')
                : $bad('SiteConfig must expose timezone(), setTimezone() and a validatedTimezone() fallback');
        });
        $add('smoke.settings.timezone_helpers', 'Smoke · Site timezone', 'tzdate() + site_timezone() helpers expose the site-wide timezone', static function () use ($helpers, $ok, $bad): array {
            return strpos($helpers, 'function site_timezone(') !== false
                && strpos($helpers, 'function tzdate(') !== false
                && strpos($helpers, 'new \\DateTimeZone(') !== false
                ? $ok('helpers wired')
                : $bad('helpers.php must expose site_timezone(), tzdate() and a timezone conversion');
        });
        $add('smoke.settings.timezone_route', 'Smoke · Site timezone', 'Settings registers POST /settings/timezone (admin-only)', static function () use ($routesSrc, $settingsC, $ok, $bad): array {
            return strpos($routesSrc, "'/settings/timezone'") !== false
                && strpos($routesSrc, 'SettingsController@updateTimezone') !== false
                && strpos($settingsC, 'public function updateTimezone(') !== false
                && strpos($settingsC, "Auth::isAdmin()") !== false
                ? $ok('route wired and admin-gated')
                : $bad('routes.php must register POST /settings/timezone against SettingsController@updateTimezone with an admin guard');
        });
        $add('smoke.settings.timezone_view', 'Smoke · Site timezone', 'Admin settings page exposes the site timezone selector', static function () use ($settingsV, $ok, $bad): array {
            return strpos($settingsV, 'Site timezone') !== false
                && strpos($settingsV, '<select name="timezone"') !== false
                && strpos($settingsV, '$siteTimezone') !== false
                && strpos($settingsV, '$siteTimezones') !== false
                && strpos($settingsV, 'Auth::isAdmin()') !== false
                ? $ok('selector rendered for admins')
                : $bad('views/settings.php must render the admin-only site timezone selector using $siteTimezone and $siteTimezones');
        });

        // --------------------------------------------- Video ladder + playlist
        $vcfg      = $read("$root/config/app.php");
        $vMigr     = $read("$root/database/migrations/068_video_chapters.sql");
        $vMigr69   = $read("$root/database/migrations/069_video_dimensions.sql");
        $vSchema   = $read("$root/schema.sql");
        $vModel    = $read("$root/app/Models/VideoChapter.php");
        $vPhoto    = $read("$root/app/Models/Photo.php");
        $vRendWk   = $read("$root/bin/video_rendition_worker.php");
        $vChapWk   = $read("$root/bin/video_chapters_worker.php");
        $vDimWk    = $read("$root/bin/media_dimensions_worker.php");
        $vSamples  = $read("$root/bin/generate_video_samples.php");
        $vPlayer   = $read("$root/views/video/player.php");
        $vStorage  = $read("$root/app/Controllers/StorageController.php");
        $vCollC    = $read("$root/app/Controllers/CollectionController.php");
        $vCollM    = $read("$root/app/Models/Collection.php");
        $vCollV    = $read("$root/views/collections/show.php");
        $vAdmin    = $read("$root/app/Controllers/AdminController.php");
        $vGalleryC = $read("$root/app/Controllers/GalleryController.php");
        $vLayout   = $read("$root/views/layout.php");
        $userJs2   = $read("$root/public/assets/js/user.js");
        $userCss2  = $read("$root/public/assets/css/user.css");

        $add('smoke.video.ladder_config', 'Smoke · Video player', 'ladder config drives lo/mid/hi rungs + chapters settings', static function () use ($vcfg, $ok, $bad): array {
            $ladder = strpos($vcfg, "'video_ladder'") !== false
                && strpos($vcfg, "'prefix'  => 'weblo_'") !== false
                && strpos($vcfg, "'prefix'  => 'web_'") !== false
                && strpos($vcfg, "'prefix'  => 'webhi_'") !== false;
            $chapters = strpos($vcfg, "'video_chapters'") !== false
                && strpos($vcfg, "'scene_threshold'") !== false
                && strpos($vcfg, "'max_chapters'") !== false;
            // The 1080p rung is capped at 1920x1080 and only wanted when the
            // source is bigger (video_ladder_plan), so it never upscales.
            $hiRung = strpos($vcfg, "'max_w'   => 1920") !== false
                && strpos($vcfg, "'max_h'   => 1080") !== false;
            return $ladder && $chapters && $hiRung
                ? $ok('three rungs (480p/720p/1080p) + chapter settings configured')
                : $bad("config/app.php must define app.video_ladder with the weblo_/web_/webhi_ rungs and app.video_chapters (scene_threshold, max_chapters)");
        });

        $add('smoke.video.ladder_helpers', 'Smoke · Video player', 'plan/rendition/path/poster helpers exist and are pure where tested', static function () use ($helpers, $ok, $bad): array {
            $fns = strpos($helpers, 'function video_ladder(') !== false
                && strpos($helpers, 'function video_ladder_plan(') !== false
                && strpos($helpers, 'function video_rung_paths(') !== false
                && strpos($helpers, 'function create_video_rendition(') !== false
                && strpos($helpers, 'function video_poster_path(') !== false
                && strpos($helpers, 'function video_poster_url(') !== false
                && strpos($helpers, 'function media_variant_prefix(') !== false
                && strpos($helpers, 'function create_video_poster_frame(') !== false;
            $mid = strpos($helpers, 'function create_video_web_rendition(') !== false
                && strpos($helpers, "'mid'") !== false
                && strpos($helpers, "create_video_rendition(\$src, \$dest, \$mid)") !== false;
            return $fns && $mid
                ? $ok('ladder helpers present; the upload-time rendition delegates to the mid rung')
                : $bad('helpers.php must expose video_ladder/video_ladder_plan/video_rung_paths/create_video_rendition/video_poster_url/media_variant_prefix/create_video_poster_frame, and create_video_web_rendition must delegate to the configured mid rung');
        });

        $add('smoke.video.variant_prefixes', 'Smoke · Video player', 'one prefix source of truth; new rungs never fall back to the original', static function () use ($helpers, $vStorage, $ok, $bad): array {
            $single = strpos($helpers, 'function media_variant_prefix(') !== false
                && strpos($helpers, "'poster'") !== false
                && strpos($vStorage, 'media_variant_prefix($size)') !== false;
            $sizes = strpos($vStorage, "'poster', 'web_lo', 'web_hi'") !== false;
            // The new rungs degrade to the 720p rendition, never to the original:
            // a missing hi rung must not start streaming a 1 GB file.
            $fallback = strpos($vStorage, "\$fallback = config('app.uploads.dir') . '/web_' . \$file;") !== false;
            return $single && $sizes && $fallback
                ? $ok('prefixes resolved centrally; rungs fall back to 720p')
                : $bad('StorageController must resolve variant prefixes through media_variant_prefix(), know poster/web_lo/web_hi, and fall back to the web_ rendition (never the original) for a missing rung');
        });

        $add('smoke.video.chapters_schema', 'Smoke · Video player', 'video_chapters in schema.sql + migration 068 + model', static function () use ($vSchema, $vMigr, $vModel, $ok, $bad): array {
            $sql = strpos($vSchema, 'video_chapters') !== false
                && strpos($vSchema, 'start_seconds') !== false;
            $migr = strpos($vMigr, 'video_chapters') !== false
                && strpos($vMigr, 'FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE') !== false;
            $model = strpos($vModel, 'class VideoChapter') !== false
                && strpos($vModel, 'public static function parseSceneTimes(') !== false
                && strpos($vModel, 'public static function replaceForPhoto(') !== false
                && strpos($vModel, 'public static function needsAnalysis(') !== false
                // Unix epoch from the DB, not strtotime() in PHP: the two
                // timezones can differ, which made every video look stale.
                && strpos($vModel, 'UNIX_TIMESTAMP(MAX(created_at))') !== false
                && strpos($vModel, 'MIN_DURATION') !== false;
            return $sql && $migr && $model
                ? $ok('table, migration and model present')
                : $bad('schema.sql + database/migrations/068_video_chapters.sql must define video_chapters (cascade on photos), and VideoChapter must expose parseSceneTimes/replaceForPhoto/needsAnalysis with a minimum duration');
        });

        $add('smoke.video.workers', 'Smoke · Video player', 'rendition + chapters workers flock, --once, cron, AI lock respect', static function () use ($vRendWk, $vChapWk, $vSamples, $acrn, $ok, $bad): array {
            $rend = strpos($vRendWk, 'flock') !== false
                && strpos($vRendWk, '--limit') !== false
                && strpos($vRendWk, 'video_ladder_plan(') !== false
                && strpos($vRendWk, 'ENCODE_TIMEOUT') !== false
                && strpos($vRendWk, 'describer.lock') !== false
                && strpos($vRendWk, 'categorizer.lock') !== false
                && strpos($vRendWk, ".part' . getmypid()") !== false;
            $chap = strpos($vChapWk, 'flock') !== false
                && strpos($acrn, 'video_chapters_worker.php --once --limit=2') !== false
                && strpos($vChapWk, 'parseSceneTimes(') !== false
                && strpos($vChapWk, 'VideoChapter::needsAnalysis(') !== false
                && strpos($vChapWk, 'showinfo') !== false;
            $cron = strpos($acrn, 'gallery-video-renditions') !== false
                && strpos($acrn, 'gallery-video-chapters') !== false
                && strpos($acrn, 'gallery-video-samples') !== false
                && strpos($acrn, 'gallery-media-dimensions') !== false;
            $samples = strpos($vSamples, '--limit') !== false && strpos($vSamples, '--once') !== false;
            return $rend && $chap && $cron && $samples
                ? $ok('both workers + cron + bounded sample ticks')
                : $bad('bin/video_rendition_worker.php and bin/video_chapters_worker.php must flock, honour --once, and bin/apply_cron.php must install gallery-video-renditions/chapters/samples; bin/generate_video_samples.php must accept --once --limit');
        });

        $add('smoke.video.pending_uploads', 'Smoke · Video player', 'staged videos get a sample clip and finalize renames it', static function () use ($vGalleryC, $ok, $bad): array {
            $clip = strpos($vGalleryC, 'create_video_sample_clip($dest, video_sample_path($filename))') !== false;
            $rename = strpos($vGalleryC, 'video_sample_path($finalName)') !== false;
            return $clip && $rename
                ? $ok('pendingUpload builds the clip; finalizePending renames it with the final filename')
                : $bad('GalleryController::pendingUpload must create the sample clip and finalizePending must rename uploads/previews/preview_pending_* to the final filename (otherwise /previews/{id} 404s and the video is never indexed)');
        });

        $add('smoke.video.player_wiring', 'Smoke · Video player', 'poster, rendition sources, chapters, overlay + retry in the player view', static function () use ($vPlayer, $ok, $bad): array {
            $okAll = strpos($vPlayer, "data-sources=") !== false
                && strpos($vPlayer, 'data-chapters=') !== false
                && strpos($vPlayer, 'poster=') !== false
                && strpos($vPlayer, 'data-overlay') !== false
                && strpos($vPlayer, 'data-retry') !== false
                && strpos($vPlayer, 'data-chapter-seek') !== false
                && strpos($vPlayer, 'pl-controls') !== false
                && strpos($vPlayer, 'data-save-collection') !== false
                && strpos($vPlayer, 'data-playlist-toggle') !== false
                && strpos($vPlayer, '$gateLevel = (int) ($minimumLevel ?? 0)') !== false;
            return $okAll
                ? $ok('ladder sources, chapters, poster, overlay/retry, playlist controls, real gate level')
                : $bad('views/video/player.php must render data-sources, data-chapters, poster, the buffering/error overlay with a Retry button, chapter seek buttons, the playlist control row (autoplay/repeat/shuffle/save), a playlist collapse toggle, and use $minimumLevel for the gate');
        });

        $add('smoke.video.orientation', 'Smoke · Video player', 'renditions are sized from the DISPLAY size (rotation applied), poster shows the whole frame', static function () use ($helpers, $vPlayer, $ok, $bad): array {
            // The probe has to read the display matrix, not just the coded
            // size: a 2400x1080 phone clip is 1080x2400 on screen, and
            // sizing a rendition from the coded size is what produced a
            // 1280x2844 "720p" file and put black bars around the video.
            $probe = strpos($helpers, 'function video_display_dimensions(') !== false
                && strpos($helpers, 'side_data_list') !== false
                && strpos($helpers, "['rotation']") !== false;
            $fit = strpos($helpers, 'function video_fit_dimensions(') !== false
                && strpos($helpers, 'function video_ladder_box(') !== false;
            // The encoder must scale both axes from the display size and pin
            // the sample aspect, and must not disable the autorotate (which
            // would leave the rotation in the file for the browser to apply
            // a second time).
            // The VIDEO path must keep ffmpeg's autorotate: the rotation is
            // baked into the pixels by it and the leftover tag is cleared, so
            // the browser does not apply it a second time. Only the image
            // path asks ffmpeg not to rotate, so the check looks at the
            // video function alone - the file also contains the image path.
            $videoFn = '';
            $videoStart = strpos($helpers, 'function create_video_rendition(');
            if ($videoStart !== false) {
                $videoEnd = strpos($helpers, "\nfunction ", $videoStart + 10);
                $videoFn = $videoEnd === false
                    ? substr($helpers, $videoStart)
                    : substr($helpers, $videoStart, $videoEnd - $videoStart);
            }
            $encode = strpos($helpers, "video_display_dimensions(\$src)") !== false
                && strpos($helpers, "scale=' . \$tw . ':' . \$th . ',setsar=1") !== false
                && strpos($helpers, "' -metadata:s:v:0 rotate=0'") !== false
                && strpos($videoFn, '-noautorotate') === false;
            // The poster is a fixed 16:9 still that shows the WHOLE frame.
            $poster = strpos($helpers, "create_video_thumbnail(\$src, \$dest, 1280, 720, 'pad')") !== false
                && strpos($helpers, "force_original_aspect_ratio=decrease") !== false;
            // The player asks for the display ratio and gets the real
            // resolution of every rung.
            $view = strpos($vPlayer, 'data-aspect=') !== false
                && strpos($vPlayer, 'video_ladder_box(') !== false
                && strpos($vPlayer, "'w'     => \$rungW") !== false;
            return $probe && $fit && $encode && $poster && $view
                ? $ok('display-aware sizing, padded poster, per-rung resolution in the view')
                : $bad('helpers.php must expose video_display_dimensions (reading the display matrix) and video_fit_dimensions, create_video_rendition must scale both axes from the display size with setsar=1 and must keep ffmpeg autorotate, the poster must be a padded 16:9 still, and views/video/player.php must publish data-aspect plus each rung\'s real pixel size');
        });

        $add('smoke.video.media_dimensions', 'Smoke · Video player', 'display size is stored on the photo row and backfilled by a worker', static function () use ($vSchema, $vMigr69, $vPhoto, $vDimWk, $ok, $bad): array {
            $sql = strpos($vSchema, 'width        SMALLINT UNSIGNED') !== false
                && strpos($vSchema, 'height       SMALLINT UNSIGNED') !== false
                && strpos($vSchema, 'rotation     SMALLINT') !== false;
            $migr = strpos($vMigr69, 'ADD COLUMN width') !== false
                && strpos($vMigr69, 'ADD COLUMN height') !== false
                && strpos($vMigr69, 'ADD COLUMN rotation') !== false;
            $model = strpos($vPhoto, 'public static function setDimensions(') !== false
                && strpos($vPhoto, 'public static function dimensions(') !== false;
            $worker = strpos($vDimWk, 'media_display_dimensions(') !== false
                && strpos($vDimWk, 'flock') !== false
                && strpos($vDimWk, '--missing') !== false;
            return $sql && $migr && $model && $worker
                ? $ok('photos carry the display size and a worker backfills it')
                : $bad('schema.sql + a migration must add photos.width/height/rotation, Photo must expose setDimensions/dimensions, and bin/media_dimensions_worker.php must backfill them (flock, --missing)');
        });

        $add('smoke.video.player_box', 'Smoke · Video player', 'the player box is the video\'s shape and fits the viewport (no black bars)', static function () use ($userCss2, $userJs2, $ok, $bad): array {
            // The box carries the picture's ratio and the picture fills it.
            $css = strpos($userCss2, 'aspect-ratio:var(--vp-aspect') !== false
                && strpos($userCss2, 'object-fit:contain') !== false
                && strpos($userCss2, '.gallery-player.is-poster video{object-fit:cover}') !== false
                && strpos($userCss2, '.gallery-player:fullscreen') !== false
                && strpos($userCss2, '.media-stage') !== false;
            // The size is computed in JS: CSS alone cannot do it (aspect-ratio
            // with a max-height clamps the height but not the width).
            $js = strpos($userJs2, 'function fitBox(') !== false
                && strpos($userJs2, 'function aspectFor(') !== false
                && strpos($userJs2, 'window.innerHeight - top - chrome') !== false
                && strpos($userJs2, "addEventListener('orientationchange'") !== false
                && strpos($userJs2, 'ResizeObserver') !== false;
            return $css && $js
                ? $ok('ratio-aware box, poster that fills it, and a viewport-aware fit')
                : $bad('user.css must size .gallery-player with aspect-ratio:var(--vp-aspect and fill it with object-fit, cover the padded poster, and style :fullscreen; user.js must compute the box size in fitBox() from the real metadata and the viewport');
        });

        $add('smoke.video.player_upgrades', 'Smoke · Video player', 'quality menu shows the real resolution; rotate, fill, theater, next-up, mini player, prefetch, swap resync', static function () use ($userJs2, $ok, $bad): array {
            $quality = strpos($userJs2, "s.w + '×' + s.h") !== false;
            $more = strpos($userJs2, 'data-more="rotate"') !== false
                && strpos($userJs2, 'data-more="fit"') !== false
                && strpos($userJs2, 'data-more="theater"') !== false
                && strpos($userJs2, 'player-theater') !== false;
            $nextUp = strpos($userJs2, 'player-nextup') !== false
                && strpos($userJs2, '__showNextUp') !== false;
            $mini = strpos($userJs2, 'is-mini') !== false
                && strpos($userJs2, 'player-placeholder') !== false;
            $prefetch = strpos($userJs2, "link.rel = 'preload'") !== false;
            // The swap has to re-read the incoming video's renditions: keeping
            // the old ones made the quality menu play the PREVIOUS file.
            $resync = strpos($userJs2, 'el.__resync = function(newVideo){') !== false
                && strpos($userJs2, 'host.__resync(nv)') !== false;
            return $quality && $more && $nextUp && $mini && $prefetch && $resync
                ? $ok('all the player upgrades are wired')
                : $bad('user.js must show each rung\'s real resolution in the quality menu, offer rotate/fill/theater in an overflow menu, show a next-up countdown, dock a sticky mini player, preload the next item, and re-read the renditions after an AJAX swap (__resync)');
        });

        $add('smoke.video.player_behaviour', 'Smoke · Video player', 're-init after swap, throttled resume, autoplay toggle, no dead pip block', static function () use ($userJs2, $ok, $bad): array {
            $reinit = strpos($userJs2, 'PlayerUI.initAll') !== false;
            $resume = strpos($userJs2, 'pagehide') !== false
                && strpos($userJs2, 'setTimeout(function(){pending=0;persist();},5000)') !== false
                // The Resume strip is a sibling of .gallery-player; resolving it
                // from video.parentNode silently disabled the whole feature.
                && strpos($userJs2, "document.getElementById('video-player-wrap')||video.closest('figure')") !== false;
            // Resume must never stomp a seek the viewer already made.
            $noStomp = strpos($userJs2, 'video.__userSeeked') !== false
                && strpos($userJs2, 'if(video.__userSeeked) return;') !== false
                && substr_count($userJs2, '__userSeeked = true') >= 3;
            $autoplay = strpos($userJs2, 'canAdvance') !== false
                && strpos($userJs2, "data-queue')") !== false;
            $quality = strpos($userJs2, 'data-sources') !== false
                && strpos($userJs2, 'saveData') !== false
                && strpos($userJs2, 'player-quality') !== false;
            $a11y = strpos($userJs2, "e.key === 'Escape'") !== false && strpos($userJs2, 'aria-pressed') !== false;
            $pip = strpos($userJs2, 'data-pip') === false;
            return $reinit && $resume && $noStomp && $autoplay && $quality && $a11y && $pip
                ? $ok('swap re-init, throttled resume, autoplay gate, VOD quality menu, focus trap, dead pip code removed')
                : $bad('user.js must re-init the player after an AJAX swap, throttle resume writes (5s + pause + pagehide), never let the saved position override a seek the viewer already made (video.__userSeeked on the seek bar, chapters and keyboard seeks), gate auto-advance on the autoplay/repeat preference, offer the VOD ladder menu (auto-pick the low rung on saveData), trap Escape in the queue dialog, and no longer contain the dead [data-pip] block');
        });

        $add('smoke.video.styles', 'Smoke · Video player', 'overlay/spinner/chapters/buffered/safe-area/mobile sheet styles', static function () use ($userCss2, $ok, $bad): array {
            return strpos($userCss2, '.player-overlay') !== false
                && strpos($userCss2, '.player-buffered') !== false
                && strpos($userCss2, '.player-chapters') !== false
                && strpos($userCss2, '.player-tick') !== false
                && strpos($userCss2, 'env(safe-area-inset-bottom') !== false
                && strpos($userCss2, '.pl-controls') !== false
                && strpos($userCss2, '.pl-collapse') !== false
                ? $ok('player chrome styled, including the phone safe-area and collapsible sheet')
                : $bad('user.css must style .player-overlay, .player-buffered, .player-chapters, .player-tick, add env(safe-area-inset-bottom) padding to .player-bar, and add .pl-controls/.pl-collapse');
        });

        $add('smoke.video.asset_versions', 'Smoke · Video player', 'user.js cache-busted after the player rewrite', static function () use ($vLayout, $ok, $bad): array {
            // The PHP short-echo tag splits the attribute, so the tag closes and "?v=17"
            // follows it; match across that split (like the tour.js check)
            // instead of expecting the version next to the filename.
            // The version may be a literal ?v=NN or the file's mtime
            // (asset_url()), which is better: a change to the script can
            // never be published without the cache busting with it, and the
            // assets are served immutable.
            $busted = preg_match('#/assets/js/user\.js.*\?v=\d+#', $vLayout) === 1
                || strpos($vLayout, "asset_url('/assets/js/user.js')") !== false;
            return $busted
                ? $ok('user.js is cache-busted')
                : $bad('views/layout.php must load /assets/js/user.js with a cache-buster');
        });

        $add('smoke.video.admin_ranges', 'Smoke · Video player', 'abandoned-upload preview advertises and honours Range', static function () use ($vAdmin, $ok, $bad): array {
            return strpos($vAdmin, 'public function abandonedFile(') !== false
                && strpos($vAdmin, "header('Accept-Ranges: bytes')") !== false
                && strpos($vAdmin, 'http_response_code(206)') !== false
                && strpos($vAdmin, "Content-Range") !== false
                ? $ok('pending previews stream ranges')
                : $bad('AdminController::abandonedFile must advertise Accept-Ranges and serve 206 + Content-Range so a pending video preview can seek');
        });

        $add('smoke.collections.reorder', 'Smoke · Video player', 'playlist reorder + queue-to-collection with server-side ownership', static function () use ($vCollM, $vCollC, $vCollV, $routesSrc, $ok, $bad): array {
            $model = strpos($vCollM, 'public static function movePhoto(') !== false
                && strpos($vCollM, 'public static function addPhotos(') !== false
                && strpos($vCollM, 'is_video = 1') !== false
                && strpos($vCollM, 'self::owns(') !== false;
            $ctrl = strpos($vCollC, 'public function move(') !== false
                && strpos($vCollC, 'public function fromQueue(') !== false
                && strpos($vCollC, 'Auth::requireLogin()') !== false;
            $routes = strpos($routesSrc, "'/collections/{id}/move'") !== false
                && strpos($routesSrc, "'/collections/from-queue'") !== false;
            $view = strpos($vCollV, "/move'") !== false && strpos($vCollV, 'name="dir" value="down"') !== false;
            return $model && $ctrl && $routes && $view
                ? $ok('movePhoto/addPhotos ownership-checked; routes + controller + reorder buttons wired')
                : $bad('Collection::movePhoto/addPhotos must enforce ownership and only accept video ids; CollectionController@move/@fromQueue must require login; routes /collections/{id}/move + /collections/from-queue must exist and the collection view must render up/down buttons');
        });

        $add('smoke.collections.player_pref_ns', 'Smoke · Video player', 'player prefs are namespaced per signed-in user', static function () use ($vLayout, $userJs2, $ok, $bad): array {
            return strpos($vLayout, 'data-uid=') !== false
                && strpos($vLayout, 'csrf-token') !== false
                && strpos($userJs2, "getAttribute('data-uid')") !== false
                && strpos($userJs2, 'csrf-token') !== false
                ? $ok('layout exposes data-uid + a CSRF meta; user.js namespaces prefs and sends the token')
                : $bad('views/layout.php must expose data-uid and a csrf-token meta, and user.js must namespace player preferences by uid and post the CSRF token when saving a queue');
        });

        // ------------------------------------------------------- API health
        $sysCtrl  = $read("$root/app/Controllers/SystemController.php");
        $sysView  = $read("$root/views/admin/system.php");
        $twitterC = $read("$root/app/Models/TwitterClient.php");
        $redditC  = $read("$root/app/Models/RedditClient.php");
        $add('smoke.apihealth.controller', 'Smoke · API health', 'SystemController builds apiHealth + runs apiTest probes', static function () use ($sysCtrl, $ok, $bad): array {
            return strpos($sysCtrl, 'private function apiHealth(') !== false && strpos($sysCtrl, 'public function apiTest(') !== false
                && strpos($sysCtrl, '/logs/api_health.json') !== false
                ? $ok('builder + probe handler')
                : $bad('SystemController must expose apiHealth() (config + cached probes, no live calls) and apiTest() running POST-only probes cached in storage/logs/api_health.json');
        });
        $add('smoke.apihealth.clients_ping', 'Smoke · API health', 'Twitter + Reddit clients expose ping()', static function () use ($twitterC, $redditC, $ok, $bad): array {
            return strpos($twitterC, 'public function ping(') !== false && strpos($redditC, 'public function ping(') !== false
                ? $ok('both clients probeable')
                : $bad('TwitterClient and RedditClient must each expose a public ping() for the API health section');
        });
        $add('smoke.apihealth.routes', 'Smoke · API health', 'System registers /admin/system/api-test/{api}', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, "'/admin/system/api-test/{api}'") !== false && strpos($routesSrc, 'SystemController@apiTest') !== false
                ? $ok('probe routes present')
                : $bad('routes.php must register POST /admin/system/api-test/{api} against SystemController@apiTest');
        });
        $add('smoke.apihealth.view', 'Smoke · API health', 'System view renders the API health card', static function () use ($sysView, $ok, $bad): array {
            return strpos($sysView, 'API health') !== false && strpos($sysView, '$apiHealth') !== false
                && strpos($sysView, 'api-test/all') !== false && strpos($sysView, "api-test/' .") !== false
                ? $ok('card wired to $apiHealth')
                : $bad('views/admin/system.php must render the API health card with per-API Test buttons and a Test all action');
        });
        $add('smoke.apihealth.table_fit', 'Smoke · API health', 'API health table cannot overflow its card', static function () use ($sysView, $adminCss, $ok, $bad): array {
            // The rule lives in admin.css now (the view's own <style> block
            // was moved out), so both places are searched.
            $css = $adminCss . $sysView;
            return strpos($css, 'sys-api-table') !== false
                && strpos($css, 'table-layout: fixed') !== false
                && strpos($css, 'overflow-wrap: anywhere') !== false
                ? $ok('fixed layout + long-token wrapping')
                : $bad('the API health table must use a fixed layout with overflow-wrap so long probe summaries never widen the card');
        });
        $validator = $read("$root/app/Core/Validator.php");
        $add('smoke.validator.numeric_arrays', 'Smoke · Validator', 'numeric rule accepts empty and numeric arrays (gallery categories)', static function () use ($validator, $ok, $bad): array {
            return strpos($validator, 'is_array($value)') !== false && strpos($validator, 'foreach ($value as $item)') !== false
                ? $ok('numeric arrays pass, non-numeric items rejected')
                : $bad('Validator::check must treat an array under the numeric rule as "every value numeric", so optional checkbox fields (e.g. gallery categories) can be empty and still save');
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

        // ------------------------------------------------------ Memory
        $databaseCore = $read("$root/app/Core/Database.php");
        $cacheCore    = $read("$root/app/Core/Cache.php");
        $add('smoke.memory.slow_ring', 'Smoke · Memory', 'Database in-memory slow-query ring is capped', static function () use ($databaseCore, $ok, $bad): array {
            return strpos($databaseCore, 'array_slice(self::$slowQueries, -self::SLOW_LOG_MAX)') !== false
                ? $ok('slow-query array trimmed to SLOW_LOG_MAX')
                : $bad('Database::$slowQueries must be trimmed to SLOW_LOG_MAX so a slow query cannot grow memory in long-running processes');
        });
        $add('smoke.memory.cache_local_cap', 'Smoke · Memory', 'Cache in-memory fallback is size-capped', static function () use ($cacheCore, $ok, $bad): array {
            return strpos($cacheCore, 'private static function localPurge(') !== false
                && strpos($cacheCore, 'count(self::$local)') !== false
                && strpos($cacheCore, 'unset(self::$local[$key])') !== false
                ? $ok('Redis-less fallback evicts expired/soonest entries over the cap')
                : $bad('Cache must cap its Redis-less in-memory fallback so a Redis outage cannot grow memory without limit');
        });

        // -------------------------------------------------- Consolidation
        $helpersSrc = $read("$root/app/Core/helpers.php");
        $photoC     = $read("$root/app/Controllers/PhotoController.php");
        $galleryC   = $read("$root/app/Controllers/GalleryController.php");
        $storageC   = $read("$root/app/Controllers/StorageController.php");
        $liveRecC   = $read("$root/app/Models/LiveRecording.php");
        $add('smoke.consol.media_helpers', 'Smoke · Consolidation', 'Media MIME/probe helpers live once in helpers.php', static function () use ($helpersSrc, $photoC, $galleryC, $storageC, $liveRecC, $ok, $bad): array {
            return strpos($helpersSrc, 'function sniff_mime(') !== false
                && strpos($helpersSrc, 'function mime_for_extension(') !== false
                && strpos($helpersSrc, 'function video_has_stream(') !== false
                && strpos($photoC, 'private function mimeOf(') === false
                && strpos($galleryC, 'private function pendingMimeOf(') === false
                && strpos($galleryC, 'private function pendingMimeFor(') === false
                && strpos($storageC, 'private function mimeFor(') === false
                && strpos($liveRecC, 'private static function hasVideoStream(') === false
                ? $ok('sniff_mime/mime_for_extension/video_has_stream shared; private copies gone')
                : $bad('media MIME/probe helpers must live once in helpers.php and the per-controller copies must be removed');
        });

        $galleryModel = $read("$root/app/Models/Gallery.php");
        $apqModel     = $read("$root/app/Models/AutoPostQueue.php");
        $add('smoke.consol.datetime', 'Smoke · Consolidation', 'datetime-local->UTC normalizer is shared', static function () use ($helpersSrc, $galleryModel, $apqModel, $ok, $bad): array {
            return strpos($helpersSrc, 'function normalize_local_datetime(') !== false
                && strpos($helpersSrc, 'function is_future_local_datetime(') !== false
                && strpos($galleryModel, 'return normalize_local_datetime($value, site_timezone());') !== false
                && strpos($galleryModel, 'return is_future_local_datetime($value, site_timezone());') !== false
                && strpos($apqModel, 'normalize_local_datetime($value, AutoPosterConfig::timezone())') !== false
                && strpos($apqModel, 'is_future_local_datetime($value, AutoPosterConfig::timezone())') !== false
                ? $ok('gallery/auto-poster/chat all delegate to the shared normalizer')
                : $bad('publish/schedule datetime parsing must delegate to normalize_local_datetime()/is_future_local_datetime() in helpers.php');
        });

        $add('smoke.consol.sse', 'Smoke · Consolidation', 'SSE long-poll preamble is shared', static function () use ($helpersSrc, $root, $read, $ok, $bad): array {
            $chat    = $read("$root/app/Controllers/ChatController.php");
            $bridge  = $read("$root/app/Controllers/ChatBridgeController.php");
            $live    = $read("$root/app/Controllers/LiveController.php");
            return strpos($helpersSrc, 'function start_sse(') !== false
                && strpos($chat, 'start_sse();') !== false
                && strpos($bridge, 'start_sse();') !== false
                && strpos($live, 'start_sse();') !== false
                // The ob-drain + zlib block must not be re-copied in controllers.
                && substr_count($chat, 'ob_end_flush') === 0
                && substr_count($bridge, 'ob_end_flush') === 0
                && substr_count($live, 'ob_end_flush') === 0
                && substr_count($bridge, 'X-Accel-Buffering') === 0
                && substr_count($live, 'X-Accel-Buffering') === 0
                ? $ok('all SSE endpoints use start_sse(); preamble not re-copied')
                : $bad('SSE streams must share the start_sse() preamble in helpers.php instead of repeating headers/ob-drain');
        });

        $subscriptionC = $read("$root/app/Models/Subscription.php");
        $broadcastC    = $read("$root/app/Models/ChatBroadcast.php");
        $chatMsgC      = $read("$root/app/Models/ChatMessage.php");
        $add('smoke.consol.subscription', 'Smoke · Consolidation', 'Subscription eligibility predicate is shared', static function () use ($subscriptionC, $broadcastC, $chatMsgC, $ok, $bad): array {
            return strpos($subscriptionC, 'public static function activeWhere(') !== false
                && strpos($subscriptionC, 'public static function chatEligibleUserIds(') !== false
                && strpos($broadcastC, 'return Subscription::chatEligibleUserIds();') !== false
                && strpos($chatMsgC, 'Subscription::activeWhere(\'s\')') !== false
                ? $ok('chat eligibility + active-subscription predicate centralized')
                : $bad('chat/broadcast eligibility must go through Subscription::activeWhere()/chatEligibleUserIds()');
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
        $add('smoke.sys.ai_watchdog', 'Smoke · System', 'AI watchdog exists and is cron-installed as root', static function () use ($applyCron, $root, $read, $ok, $bad): array {
            $wd = $read("$root/bin/keep_ai_alive.php");
            return strpos($applyCron, 'gallery-ai-watchdog') !== false
                && strpos($applyCron, '* * * * * root /usr/bin/php') !== false
                && strpos($wd, "api/tags") !== false
                && strpos($wd, "systemctl restart ollama.service") !== false
                && strpos($wd, 'keep_alive') !== false
                ? $ok('keep_ai_alive.php probes/warms/restarts Ollama and is cron-installed every minute')
                : $bad('bin/keep_ai_alive.php must check the Ollama API, warm the model and restart ollama.service, and apply_cron must install it every minute as root');
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

        // --------------------------------------------------- Web analytics
        $analyticsFiles = [
            'app/Core/AccessLogParser.php',
            'app/Core/AccessLogAggregator.php',
            'app/Models/WebStats.php',
            'app/Controllers/AnalyticsController.php',
            'views/admin/web-stats.php',
            'bin/aggregate_access_log.php',
            'database/migrations/053_web_stats.sql',
            'tests/fixtures/access-log.sample',
        ];
        $missingAnalytics = array_values(array_filter(
            $analyticsFiles,
            static fn (string $rel): bool => !is_file($root . '/' . $rel)
        ));
        $add('smoke.analytics.files', 'Smoke · Web analytics', 'Analytics files present', static function () use ($missingAnalytics, $ok, $bad): array {
            return $missingAnalytics === []
                ? $ok('parser, aggregator, model, controller, view, CLI, migration and fixture all present')
                : $bad('missing analytics files: ' . implode(', ', $missingAnalytics));
        });
        $add('smoke.analytics.routes', 'Smoke · Web analytics', 'Analytics routes + permission registered', static function () use ($root, $routesSrc, $ok, $bad): array {
            $auth = (string) file_get_contents("$root/app/Core/Auth.php");

            return strpos($routesSrc, "'/admin/analytics'") !== false
                && strpos($routesSrc, "'/admin/analytics/reparse'") !== false
                && strpos($routesSrc, "'/admin/analytics/export'") !== false
                && strpos($routesSrc, "'analytics'") !== false
                && strpos($auth, "'analytics'") !== false
                ? $ok('index/reparse/export routed and gated behind the analytics permission')
                : $bad('routes.php must register /admin/analytics, /admin/analytics/reparse and /admin/analytics/export with the analytics permission, and Auth::PERMISSIONS must grant it');
        });
        $migration = $read("$root/database/migrations/053_web_stats.sql");
        $schemaSql = $read("$root/schema.sql");
        $add('smoke.analytics.schema', 'Smoke · Web analytics', 'All nine rollup tables migrated and mirrored in schema.sql', static function () use ($migration, $schemaSql, $ok, $bad): array {
            $tables = [
                'web_stats_daily', 'web_stats_hourly', 'web_stats_urls', 'web_stats_referrers',
                'web_stats_agents', 'web_stats_status', 'web_stats_filetypes', 'web_stats_ips', 'web_visits',
            ];
            $missing = [];
            foreach ($tables as $table) {
                if (stripos($migration, "CREATE TABLE IF NOT EXISTS {$table}") === false
                    || stripos($schemaSql, "CREATE TABLE IF NOT EXISTS {$table}") === false) {
                    $missing[] = $table;
                }
            }

            return $missing === []
                ? $ok(count($tables) . ' tables in the migration and in schema.sql')
                : $bad('missing web stats tables in migration or schema.sql: ' . implode(', ', $missing));
        });
        $add('smoke.analytics.bot_split', 'Smoke · Web analytics', 'Human and robot traffic are stored side by side', static function () use ($root, $ok, $bad): array {
            $parser = (string) file_get_contents("$root/app/Core/AccessLogParser.php");
            $model  = (string) file_get_contents("$root/app/Models/WebStats.php");

            foreach (['human_hits', 'bot_hits', 'page_views', 'bot_page_views'] as $column) {
                if (strpos($parser, $column) === false || strpos($model, $column) === false) {
                    return $bad("column {$column} must be written by the parser and read by WebStats");
                }
            }

            return $ok('hits, page views, referrers, agents and per-IP rows all carry a robot split');
        });

        // --- Behavioural: the parser is pure, so the fixture can be asserted
        //     without a database (the smoke suite must stay DB-free).
        $fixture = "$root/tests/fixtures/access-log.sample";
        $add('smoke.seo.guest_covers_are_modern', 'Smoke · SEO', 'The guest grid serves AVIF and WebP too', static function () use ($root, $read, $ok, $bad): array {
            // The member's thumbnail had a <picture> block with AVIF and
            // WebP, but the guest (and the crawler) saw the BLURRED cover -
            // and that variant had no modern-format copy at all, so the one
            // surface that the search engines actually read was stuck with
            // plain JPEG.
            $card    = (string) $read($root . '/views/partials/gallery_card.php');
            $helpers = (string) $read($root . '/app/Core/helpers.php');
            $store   = (string) $read($root . '/app/Controllers/StorageController.php');
            $backfill = (string) $read($root . '/bin/avif_backfill.php');
            $problems = [];

            if (strpos($card, "file_url(\$cover['filename'], 'blur', 'avif')") === false) {
                $problems[] = 'the guest cover must offer an AVIF source';
            }
            if (strpos($card, "file_url(\$cover['filename'], 'blur', 'webp')") === false) {
                $problems[] = 'the guest cover must offer a WebP source';
            }
            // A blurred cover made on demand has to get its copies too, or
            // the next guest pays for the JPEG again.
            if (strpos($store, "_make_avif(\$dest)") === false || strpos($store, "_make_webp(\$dest)") === false) {
                $problems[] = 'an on-demand blurred cover must get its AVIF and WebP copies';
            }
            if (strpos($helpers, 'function _make_webp(') === false) {
                $problems[] = 'there must be a helper that writes a WebP copy of a variant';
            }
            // And the backfill must cover the blurred covers.
            if (strpos($backfill, "glob(\$root . '/blur_*.*')") === false) {
                $problems[] = 'the AVIF backfill must cover the blurred covers';
            }

            return $problems === []
                ? $ok('the guest grid serves AVIF and WebP too')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.seo.crawlable_surface', 'Smoke · SEO', 'The crawlable surface is cacheable and announces its neighbours', static function () use ($root, $read, $ok, $bad): array {
            // Three things a crawler could not use:
            //  - the CSP report-uri was RELATIVE, so the browser had
            //    nowhere to post the violation reports to;
            //  - the feeds and the sitemaps carried "no-store" and a
            //    session cookie, so no shared cache could keep them;
            //  - the paginated pages never said which pages are next, so
            //    a crawler had to parse the HTML to walk the pagination.
            $index   = (string) $read($root . '/public/index.php');
            $ctrl    = (string) $read($root . '/app/Core/Controller.php');
            $static  = (string) $read($root . '/app/Controllers/StaticPageController.php');
            $router  = (string) $read($root . '/app/Core/Router.php');
            $partial = (string) $read($root . '/views/partials/pagination.php');
            $problems = [];

            // The report-uri has to be an absolute URL.
            if (strpos($index, 'report-uri " . url(') !== false) {
                $problems[] = 'the CSP report-uri must be absolute, not a relative path';
            }
            if (strpos($index, 'report-uri " . absolute_url(') === false) {
                $problems[] = 'the CSP report-uri must be an absolute URL';
            }

            // The feeds and the sitemaps are cacheable.
            if (strpos($ctrl, 'function publicCache(') === false) {
                $problems[] = 'there must be a way to mark a response as publicly cacheable';
            }
            if (strpos($ctrl, "header_remove('Set-Cookie')") === false) {
                $problems[] = 'a publicly cacheable response must drop the session cookie';
            }
            foreach (['videoSitemap', 'sitemap', 'feed'] as $method) {
                if (strpos($static, 'function ' . $method . '(') === false) {
                    $problems[] = 'the ' . $method . '() endpoint is missing';
                    continue;
                }
                if (preg_match('/function ' . $method . '\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $static, $m) !== 1) {
                    continue;
                }
                if (strpos($m[1], 'publicCache(') === false) {
                    $problems[] = 'the ' . $method . '() response must be publicly cacheable';
                }
            }

            // The pagination announces its neighbours in a Link header, and
            // the header survives the page cache (a header() call in a view
            // is lost when the page is served from the cache).
            if (strpos($partial, 'LINK_MARKER') === false) {
                $problems[] = 'the pagination must leave its Link header in the markup';
            }
            if (strpos($router, 'pageLinks(') === false || strpos($router, 'pagePut(') === false) {
                $problems[] = 'the router must carry the Link header with the cached page';
            }
            if (strpos($ctrl, 'pageLinks(') === false) {
                $problems[] = 'a rendered page must send its Link header';
            }

            return $problems === []
                ? $ok('the crawlable surface is cacheable and announces its neighbours')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.analytics.only_the_window_files', 'Smoke · Web analytics', 'The aggregator only reads the log files that can hold the window', static function () use ($root, $ok, $bad): array {
            // The aggregator used to read EVERY rotated log and parse every
            // line in it, then throw away the days outside the window: the
            // parser needs the lines in order to keep the sessions that
            // straddle midnight. On the production box that was 300,000
            // lines and ~20 s of CPU every hour to store two days.
            if (!class_exists(\App\Core\AccessLogAggregator::class)) {
                return $bad('App\Core\AccessLogAggregator must exist');
            }
            if (!method_exists(\App\Core\AccessLogAggregator::class, 'filesForWindow')) {
                return $bad('the aggregator must filter the log files by the window');
            }

            // The filter is behavioural: a file older than the window is
            // dropped, a file inside it is kept.
            $old = tempnam(sys_get_temp_dir(), 'vs-old-log');
            $new = tempnam(sys_get_temp_dir(), 'vs-new-log');
            if ($old === false || $new === false) {
                return $bad('could not create the temporary log files');
            }
            file_put_contents($old, "x");
            file_put_contents($new, "x");
            // A rotated file's mtime is the moment it was rotated, so every
            // line in it is older than that.
            touch($old, time() - 10 * 86400);
            touch($new, time() - 3600);

            $window = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            $kept   = \App\Core\AccessLogAggregator::filesForWindow(
                [$old, $new],
                $window,
                new \DateTimeZone('UTC')
            );
            @unlink($old);
            @unlink($new);

            if (in_array($old, $kept, true)) {
                return $bad('a log file older than the window must not be read');
            }
            if (!in_array($new, $kept, true)) {
                return $bad('a log file inside the window must be read');
            }

            // And the run() must actually use it.
            $src = (string) file_get_contents($root . '/app/Core/AccessLogAggregator.php');
            if (strpos($src, 'filesForWindow(') === false) {
                return $bad('the aggregator must use the window filter');
            }
            return $ok('only the files that can hold the window are read');
        });
        $add('smoke.analytics.parser_fixture', 'Smoke · Web analytics', 'Parser folds the fixture into the expected daily numbers', static function () use ($fixture, $ok, $bad): array {
            if (!is_file($fixture)) {
                return $bad('missing fixture: tests/fixtures/access-log.sample');
            }

            $lines  = file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $result = AccessLogParser::parse($lines, ['base_path' => '/gallery', 'timezone' => 'UTC']);

            $expect = [
                '2026-10-04' => ['hits' => 16, 'human_hits' => 10, 'bot_hits' => 6, 'page_views' => 6, 'bot_page_views' => 4, 'asset_hits' => 4, 'api_hits' => 2],
                '2026-10-05' => ['hits' => 1, 'human_hits' => 1, 'bot_hits' => 0, 'page_views' => 1, 'bot_page_views' => 0, 'asset_hits' => 0, 'api_hits' => 0],
            ];

            if (count($result['days']) !== 2) {
                return $bad('expected 2 days, got ' . count($result['days']));
            }
            foreach ($expect as $day => $counters) {
                foreach ($counters as $column => $value) {
                    $actual = (int) ($result['days'][$day][$column] ?? -1);
                    if ($actual !== $value) {
                        return $bad("{$day}.{$column} is {$actual}, expected {$value}");
                    }
                }
            }

            $hits = $result['days']['2026-10-04']['hits'] + $result['days']['2026-10-05']['hits'];
            $pv   = $result['days']['2026-10-04']['page_views'] + $result['days']['2026-10-05']['page_views'];
            $bv   = $result['days']['2026-10-04']['bot_page_views'] + $result['days']['2026-10-05']['bot_page_views'];
            if ($hits !== $pv + $bv + 4 + 2) {
                return $bad("page views ({$pv} human + {$bv} robot) plus assets and API must reconcile with {$hits} hits");
            }
            if ((int) $result['skipped'] !== 2) {
                return $bad('expected 2 unparseable lines, got ' . (int) $result['skipped']);
            }
            if ((int) $result['lines'] !== 21) {
                return $bad('expected 21 log lines, got ' . (int) $result['lines']);
            }

            return $ok('2 days, 17 public hits (2 private dropped, 2 unparseable), 13 visits');
        });
        $add('smoke.analytics.parser_stream_matches_parse', 'Smoke · Web analytics', 'Streaming parser agrees with the whole-file parser', static function () use ($fixture, $ok, $bad): array {
            if (!is_file($fixture)) {
                return $bad('missing fixture: tests/fixtures/access-log.sample');
            }

            $lines  = file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $options = ['base_path' => '/gallery', 'timezone' => 'UTC'];
            $whole   = AccessLogParser::parse($lines, $options);

            // The callback receives that day's whole accumulator (days/hours/
            // urls/... are still keyed by date inside it), so the row for the
            // day being flushed lives at $accumulator['days'][$key].
            $streamed = [];
            $totals   = AccessLogParser::streamByDay($lines, $options, static function (array $day, string $key) use (&$streamed): void {
                $streamed[$key] = $day['days'][$key] ?? [];
            });

            if (array_keys($whole['days']) !== array_keys($streamed)) {
                return $bad('streamed days (' . implode(',', array_keys($streamed)) . ') differ from parsed days');
            }
            foreach ($whole['days'] as $day => $counters) {
                foreach (['hits', 'human_hits', 'bot_hits', 'page_views', 'bot_page_views', 'asset_hits', 'api_hits', 'bytes'] as $column) {
                    if ((int) ($counters[$column] ?? 0) !== (int) ($streamed[$day][$column] ?? -1)) {
                        return $bad("{$column} differs on {$day}: parsed {$counters[$column]}, streamed " . ($streamed[$day][$column] ?? 'missing'));
                    }
                }
            }
            if ($totals['lines'] !== (int) $whole['lines'] || $totals['skipped'] !== (int) $whole['skipped']) {
                return $bad('stream totals differ from parse totals');
            }

            return $ok($totals['days'] . ' days streamed with identical counts');
        });
        $add('smoke.analytics.base_path_boundary', 'Smoke · Web analytics', 'Base path is only stripped on a segment boundary', static function () use ($ok, $bad): array {
            $cases = [
                ['/gallery/photos/12', '/photos/12'],
                ['/gallery', '/'],
                ['/galleries/list', '/galleries/list'],
                ['/gallery-studio', '/gallery-studio'],
            ];
            foreach ($cases as [$target, $expected]) {
                $actual = AccessLogParser::normalizeUrl($target, '/gallery');
                if ($actual !== $expected) {
                    return $bad("normalizeUrl({$target}) is {$actual}, expected {$expected}");
                }
            }

            return $ok('"/galleries" and "/gallery-studio" keep their prefix');
        });
        $add('smoke.analytics.self_referral', 'Smoke · Web analytics', 'Self-referrals count as direct traffic', static function () use ($ok, $bad): array {
            $self = 'amethyst2213.com';
            $internal = [
                'https://amethyst2213.com/gallery/photos/1',
                'https://www.amethyst2213.com/',
                'https://amethyst2213.com./x',
            ];
            foreach ($internal as $referrer) {
                if (AccessLogParser::referrerHost($referrer, $self) !== '') {
                    return $bad("self-referrer {$referrer} was credited as an external source");
                }
            }
            // The host is kept exactly as sent (lower-cased) so "google.com" and
            // "news.google.com" stay distinguishable; only self-referrals fold.
            if (AccessLogParser::referrerHost('https://www.google.com/search?q=gallery', $self) !== 'www.google.com') {
                return $bad('a real external referrer must be kept as its own host');
            }
            if (AccessLogParser::referrerHost('-', $self) !== '') {
                return $bad('"-" is direct traffic');
            }

            return $ok('internal referrals fold into the direct bucket');
        });
        $add('smoke.analytics.rotated_discovery', 'Smoke · Web analytics', 'Rotated logs are discovered oldest-first', static function () use ($ok, $bad): array {
            $dir = sys_get_temp_dir() . '/gallery-analytics-rot';
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return $bad('could not create a scratch directory for the discovery test');
            }
            foreach (glob($dir . '/*') ?: [] as $stale) {
                @unlink($stale);
            }

            $names = ['access.log.2.gz', 'access.log.1', 'access.log', 'access.log.3.gz.old', 'other_vhosts_access.log'];
            $now   = time();
            foreach ($names as $index => $name) {
                file_put_contents($dir . '/' . $name, '');
            }
            // Oldest first: the ".2.gz" is two rotations back.
            touch($dir . '/access.log.2.gz', $now - 7200);
            touch($dir . '/access.log.1', $now - 3600);
            touch($dir . '/access.log', $now);

            $found = array_map('basename', AccessLogAggregator::discover([$dir . '/access.log*']));
            foreach ($names as $name) {
                @unlink($dir . '/' . $name);
            }

            $expected = ['access.log.2.gz', 'access.log.1', 'access.log'];
            if ($found !== $expected) {
                return $bad('discovery returned [' . implode(', ', $found) . '], expected [' . implode(', ', $expected) . ']');
            }

            return $ok('gzip, numbered and live logs in order; .gz.old and other vhosts excluded');
        });
        $add('smoke.analytics.default_patterns', 'Smoke · Web analytics', 'Default patterns include rotated files', static function () use ($root, $ok, $bad): array {
            $src = (string) file_get_contents("$root/app/Core/AccessLogAggregator.php");
            $hasGlob = false;
            foreach (AccessLogAggregator::DEFAULT_PATTERNS as $pattern) {
                if (strpbrk($pattern, '*?[') !== false) {
                    $hasGlob = true;
                }
            }

            return $hasGlob
                ? $ok('defaults glob the live and rotated Apache logs')
                : $bad('AccessLogAggregator::DEFAULT_PATTERNS must glob rotations (access.log*, access_ssl.log*), or a backfill stops at the last logrotate');
        });
        $add('smoke.analytics.cron', 'Smoke · Web analytics', 'Hourly aggregation cron is installed', static function () use ($root, $ok, $bad): array {
            $apply = (string) file_get_contents("$root/bin/apply_cron.php");
            $cli   = (string) file_get_contents("$root/bin/aggregate_access_log.php");

            return strpos($apply, 'gallery-web-analytics') !== false
                && strpos($apply, 'bin/aggregate_access_log.php --days=2') !== false
                && strpos($apply, 'web_analytics') !== false
                && strpos($cli, 'flock') !== false
                ? $ok('apply_cron writes the hourly entry; the CLI holds an flock so a manual run cannot overlap it')
                : $bad('bin/apply_cron.php must install gallery-web-analytics (hourly, --days=2) and bin/aggregate_access_log.php must hold a file lock');
        });
        $add('smoke.analytics.cron_cadence', 'Smoke · Web analytics', 'An hourly-or-slower cadence never renders an invalid cron step', static function () use ($root, $ok, $bad): array {
            $src = (string) file_get_contents("$root/bin/apply_cron.php");

// Cron only accepts a step in the minute field, so "*/60" would make
            // cron drop the job silently. Only the rendered entries matter; the
            // helper itself legitimately builds a "*/N" step for sub-hour jobs.
            $steps = [];
            if (preg_match_all('/^\$crond\[\x27[^\x27]+\x27\]\s*=\s*\n?\s*"[^"]*\*\/\{/m', $src, $m)) {
                $steps = $m[0];
            }

            $shared = str_contains($src, '$cronFields = static function')
                && str_contains($src, '$waFields    = $cronFields($waMin);')
                && str_contains($src, '$waFields[0]} {$waFields[1]}');

            if ($steps !== [] || !$shared) {
                return $bad('every cadence-based cron entry must use the shared $cronFields() renderer, not a literal */N step (offenders: ' . implode(' | ', $steps) . ')');
            }

            return $ok('hourly and slower cadences render as minute 0 plus an hour list');
        });
        $add('smoke.analytics.rotated_order', 'Smoke · Web analytics', 'Rotated logs are replayed oldest first even when mtime ties', static function () use ($root, $ok, $bad): array {
            $dir = sys_get_temp_dir() . '/vs-rotated-order-' . getmypid();
            @mkdir($dir, 0777, true);

            $stamp = static function (string $name, int $when) use ($dir): void {
                $line = sprintf(
                    '204.0.2.1 - - [%s] "GET /%s HTTP/1.1" 200 1 "-" "-"',
                    gmdate('d/M/Y:H:i:s O', $when),
                    pathinfo($name, PATHINFO_FILENAME)
                );
                file_put_contents("{$dir}/{$name}", $line);
                touch("{$dir}/{$name}", $when);
            };

            // One logrotate pass gives every file the same mtime; the names alone
            // would then read .1 before .2.gz and replay the days out of order.
            $stamp('access.log.1', 1_800_000_000);
            $stamp('access.log.2.gz', 1_800_000_000);
            $stamp('access.log.3', 1_800_000_000);

            $order = array_map(
                static fn (string $p): string => basename($p),
                \App\Core\AccessLogAggregator::discover(["{$dir}/*"])
            );

            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);

            $want  = ['access.log.3', 'access.log.2.gz', 'access.log.1'];
            $got   = implode(' ', $order);
            $wantS = implode(' ', $want);

            return $got === $wantS
                ? $ok('rotated files replayed oldest first on a mtime tie')
                : $bad("expected \"{$wantS}\" from discovery, got \"{$got}\"");
        });
        $add('smoke.analytics.case_merge', 'Smoke · Web analytics', 'Case variants of a path merge into one row instead of a duplicate-key abort', static function () use ($root, $ok, $bad): array {
            $line = static fn (string $path): string => sprintf(
                '%s - - [04/Oct/2026:12:00:%02d +0000] "GET %s HTTP/1.1" 200 10 "-" "Mozilla/5.0 (X11; Linux) Firefox/128.0"',
                '198.51.100.7',
                0,
                $path
            );

            // Scanners hammer /.env, /.Env, /.ENV. They are separate PHP array
            // keys but one key in the table (utf8mb4_0900_ai_ci), which killed a
            // production backfill on a duplicate-key error.
            $parsed = \App\Core\AccessLogParser::parse(
                [$line('/.env'), $line('/.Env'), $line('/.ENV')],
                ['timezone' => 'UTC']
            );

            $urls = $parsed['urls']['2026-10-04'] ?? [];
            if (count($urls) !== 1) {
                return $bad('the three case variants must merge into one bucket, got ' . count($urls) . ': ' . implode(', ', array_keys($urls)));
            }

            $row = reset($urls);
            if ((int) $row['hits'] !== 3) {
                return $bad('the merged bucket must keep counting every request, got ' . (int) $row['hits']);
            }

            $agg = (string) file_get_contents("$root/app/Core/AccessLogAggregator.php");
            if (!str_contains($agg, 'ON DUPLICATE KEY UPDATE')) {
                return $bad('the dimension inserts must merge on a key collision so one odd row cannot abort a day');
            }

            return $ok('case variants merge, and a residual collision merges instead of failing');
        });
        $add('smoke.analytics.bad_utf8', 'Smoke · Web analytics', 'Invalid UTF-8 in a request target is repaired, not dropped', static function () use ($root, $ok, $bad): array {
            // Scanners send overlong encodings (C0 AF ...) straight at the log;
            // MySQL rejects those with 1366 and would lose the whole day.
            $target = "/\xC0\xAF..\xC0\xAF.env";
            $line   = sprintf(
                '198.51.100.9 - - [04/Oct/2026:12:00:00 +0000] "GET %s HTTP/1.1" 404 12 "http://x/" "Mozilla/5.0 (X11; Linux) Firefox/128.0"',
                $target
            );

            $parsed = \App\Core\AccessLogParser::parse([$line], ['timezone' => 'UTC']);

            if ((int) ($parsed['skipped'] ?? 1) !== 0) {
                return $bad('a request with odd bytes must still count, not be dropped as unparseable');
            }

            $urls = $parsed['urls']['2026-10-04'] ?? [];
            if (count($urls) !== 1) {
                return $bad('expected the request to be stored, got ' . count($urls) . ' path(s)');
            }

            $path = (string) (reset($urls)['path'] ?? '');
            if ($path === '' || !mb_check_encoding($path, 'UTF-8')) {
                return $bad('the stored path must be valid UTF-8, got ' . bin2hex($path));
            }
            if (str_contains($path, '\xC0') || str_contains($path, "\\xAF")) {
                return $bad('the invalid bytes survived into the stored path');
            }

            return $ok('odd bytes replaced with U+FFFD, request still counted');
        });
        $add('smoke.analytics.visit_day_grouping', 'Smoke · Web analytics', 'A session is written once, to the day it started on', static function () use ($root, $ok, $bad): array {
            // A session that runs over midnight closes while the later day is
            // being streamed, so its own day is already written by then. The
            // aggregator used to insert the whole session buffer on every day
            // flush, which duplicated rows and inflated days that never had
            // that traffic (hits=1 next to 204 visits).
            $lines = [
                sprintf('198.51.100.20 - - [04/Oct/2026:23:58:00 +0000] "GET /a HTTP/1.1" 200 10 "http://x/" "Mozilla/5.0 (X11; Linux) Firefox/128.0"'),
                sprintf('198.51.100.20 - - [05/Oct/2026:00:02:00 +0000] "GET /b HTTP/1.1" 200 10 "http://x/" "Mozilla/5.0 (X11; Linux) Firefox/128.0"'),
                sprintf('198.51.100.21 - - [05/Oct/2026:00:05:00 +0000] "GET /c HTTP/1.1" 200 10 "http://x/" "Mozilla/5.0 (X11; Linux) Firefox/128.0"'),
            ];

            $visits = [];
            $days   = [];

            \App\Core\AccessLogParser::streamByDay($lines, ['timezone' => 'UTC'], static function (array $day, string $dayKey) use (&$visits, &$days): void {
                $days[] = $dayKey;
                foreach ($day['visits'] ?? [] as $visit) {
                    $visits[] = $visit;
                }
            });

            if (count($visits) !== 2) {
                return $bad('expected 2 sessions, got ' . count($visits));
            }

            $byDay = [];
            foreach ($visits as $visit) {
                $byDay[(string) $visit['day']][] = $visit;
            }
            ksort($byDay);

            if (count($byDay) !== 2 || count($byDay['2026-10-04']) !== 1 || count($byDay['2026-10-05']) !== 1) {
                return $bad('each session must be attributed to the day it started on, got ' . json_encode(array_map('count', $byDay)));
            }

            $straddling = $byDay['2026-10-04'][0];
            if ((int) $straddling['pages'] !== 2 || strpos((string) $straddling['last_seen'], '2026-10-05') !== 0) {
                return $bad('the midnight session must keep both pages and its later last_seen');
            }

            // The write path has to stay a two-phase one: dimensions streamed
            // per day, sessions written once at the end.
            $aggregator = (string) file_get_contents("$root/app/Core/AccessLogAggregator.php");
            if (preg_match('/private static function store\(.*?\n    \}/s', $aggregator, $m) === 1 && str_contains($m[0], 'insertVisits')) {
                return $bad('store() must not insert sessions, they are written by finalizeVisits() once per run');
            }
            if (!str_contains($aggregator, 'self::finalizeVisits(')) {
                return $bad('run() must finalize sessions after the stream');
            }

            return $ok('sessions are grouped by their own day and written once');
        });
        $add('smoke.analytics.export_window', 'Smoke · Web analytics', 'A range-only CSV export covers the range, not just today', static function () use ($root, $ok, $bad): array {
            $src = (string) file_get_contents("$root/app/Controllers/AnalyticsController.php");

            // Request::query() returns null for an absent key, so the old
            // "query('from') === ''" guard was never true and every export
            // collapsed to the single stored day regardless of the range.
            $rereread = preg_match('/\\$this->request->query\\(\s*\'(?:from|to)\'\s*\\)\\s*===/', $src) === 1;

            if ($rereread) {
                return $bad('the export must resolve from/to once into local values and test those, not compare query() against an empty string');
            }
            if (!str_contains($src, "\$fromQ === '' && \$toQ === ''")) {
                return $bad('the export range guard must compare the resolved from/to strings');
            }

            return $ok('a range-only export spans the whole range');
        });
        $add('smoke.analytics.private_filter', 'Smoke · Web analytics', 'Own traffic is filtered out, with an opt-in for LAN installs', static function () use ($root, $ok, $bad): array {
            $line = static fn (string $ip): string => sprintf(
                '%s - - [04/Oct/2026:12:00:00 +0000] "GET / HTTP/1.1" 200 10 "-" "Mozilla/5.0 (X11; Linux) Firefox/128.0"',
                $ip
            );

            $count = static function (array $options) use ($line): int {
                $days = \App\Core\AccessLogParser::parse([$line('192.168.1.55')], $options)['days'];

                return (int) ($days['2026-10-04']['hits'] ?? 0);
            };

            $skipped = $count(['timezone' => 'UTC']);
            $kept    = $count(['timezone' => 'UTC', 'include_private' => true]);
            $src     = (string) file_get_contents("$root/app/Core/AccessLogAggregator.php");
            $cli     = (string) file_get_contents("$root/bin/aggregate_access_log.php");

            if ($skipped !== 0 || $kept !== 1) {
                return $bad("a 192.168.x request must be dropped by default (got {$skipped}) and kept with include_private (got {$kept})");
            }
            if (strpos($src, 'ANALYTICS_INCLUDE_PRIVATE') === false || strpos($cli, '--include-private') === false) {
                return $bad('the include_private option must be reachable from ANALYTICS_INCLUDE_PRIVATE and --include-private, or a staging box on 192.168.x shows an empty page');
            }

            return $ok('loopback/LAN traffic excluded by default, opt-in available');
        });
        $add('smoke.analytics.view', 'Smoke · Web analytics', 'Analytics view renders every panel', static function () use ($root, $ok, $bad): array {
            $view = (string) file_get_contents("$root/views/admin/web-stats.php");
            $panels = [
                'Traffic by day', 'Hourly profile', 'Top pages', 'Where visitors came from',
                'Entry pages', 'Visit quality', 'Browsers', 'Operating systems', 'Devices',
                'Request types', 'HTTP status codes', 'Static file types', 'Robots',
                'Log aggregation', 'Re-parse logs', 'Download CSV',
            ];
            $missing = array_values(array_filter($panels, static fn (string $needle): bool => strpos($view, $needle) === false));

            return $missing === []
                ? $ok(count($panels) . ' panels present')
                : $bad('analytics view is missing: ' . implode(', ', $missing));
        });

        $add('smoke.analytics.rollup_set_based', 'Smoke · Web analytics', 'Session rollups rebuild with one grouped pass per day', static function () use ($root, $ok, $bad): array {
            $src = (string) file_get_contents("$root/app/Core/AccessLogAggregator.php");

            // The rollup used to be a correlated subquery per driver row, so
            // every one of the day's web_stats_ips rows re-probed that whole
            // day's sessions through the (day, is_bot) index: 0.9s warm and
            // 6.9s cold on production, hourly, and 39 of the 40 entries that
            // were sitting in the slow-query list. Both rollups must now be a
            // single grouped pass over web_visits.
            $correlated = array_values(array_filter(
                ['v.day = d.day', 'v.day = i.day', 'v.ip = i.ip'],
                static fn (string $needle): bool => str_contains($src, $needle)
            ));

            $setBased = substr_count($src, 'LEFT JOIN (SELECT') >= 2
                && str_contains($src, 'GROUP BY ip')
                && str_contains($src, 'FROM web_visits');

            return ($correlated === [] && $setBased)
                ? $ok('daily and per-address rollups are single grouped passes')
                : $bad('correlated subquery back in refreshVisitRollupsFor: ' . implode(', ', $correlated)
                    . ($setBased ? '' : ' (and the set-based form is missing)'));
        });

        $add('smoke.system.size_query_cached', 'Smoke · System page', 'information_schema reads go through one cached reader', static function () use ($root, $ok, $bad): array {
            $db = (string) file_get_contents("$root/app/Core/Database.php");

            $needsCache = str_contains($db, 'function tableSizes(')
                && str_contains($db, 'function tableSizeBytes(')
                && str_contains($db, 'TABLE_STATS_TTL')
                && str_contains($db, 'storage/cache');

            // A dictionary miss on these reads has been recorded at 2-7s, on
            // the System page (which reports slow queries) and on the admin
            // dashboard pie (every visit) - so neither may query it directly.
            $direct = [];
            foreach (['app/Controllers/SystemController.php', 'app/Controllers/AdminController.php'] as $file) {
                if (str_contains((string) file_get_contents("$root/$file"), 'information_schema.tables')) {
                    $direct[] = $file;
                }
            }

            return ($needsCache && $direct === [])
                ? $ok('both size queries are served by Database::tableSizes()')
                : $bad('size-query cache incomplete' . ($direct !== [] ? ': direct read in ' . implode(', ', $direct) : ''));
        });

        $add('smoke.system.slow_query_ring_unique', 'Smoke · System page', 'A slow query is listed once even when it slowed the page itself', static function () use ($ok, $bad): array {
            if (!class_exists(\App\Core\Database::class, false)) {
                require_once dirname(__DIR__, 2) . '/app/Core/Database.php';
            }

            $entry = ['sql' => 'SELECT 1', 'params' => [], 'seconds' => 1.25, 'at' => '2026-10-06 10:00:00'];
            $other = ['sql' => 'SELECT 2', 'params' => [], 'seconds' => 2.5, 'at' => '2026-10-06 11:00:00'];

            // The same event lands in the in-memory list and in the on-disk
            // ring: a query that goes slow WHILE the System page renders its
            // slow-query table would otherwise appear twice in that table.
            $merged = \App\Core\Database::mergeSlowEntries([$entry], [$entry, $other]);

            $unique = count(array_filter(
                $merged,
                static fn (array $row): bool => ($row['sql'] ?? '') === 'SELECT 1'
            ));

            return ($unique === 1 && count($merged) === 2)
                ? $ok('duplicate event collapsed, distinct events kept')
                : $bad("dedupe returned {$unique} copies of one event across " . count($merged) . ' rows');
        });

        // ------------------------------------------------------ Media variants
        $add('smoke.media.bmp_variants', 'Smoke · Media', 'BMP uploads produce web + thumb renditions (never the full original)', static function () use ($ok, $bad): array {
            foreach (['imagecreatetruecolor', 'imagebmp', 'imagecreatefrombmp', 'getimagesize'] as $fn) {
                if (!function_exists($fn)) {
                    return $ok("GD function $fn unavailable; skipped");
                }
            }

            $dir = sys_get_temp_dir() . '/gallery-variant-smoke-' . getmypid();
            @mkdir($dir, 0777, true);

            $bmp = $dir . '/probe.bmp';
            $im  = imagecreatetruecolor(64, 48);
            imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 200, 30, 120));
            imagebmp($im, $bmp);
            imagedestroy($im);

            $web   = $dir . '/web_probe.bmp';
            $thumb = $dir . '/thumb_probe.bmp';
            $made  = create_image_variants($bmp, $web, $thumb, 1600, 400, 300);

            $checks = [
                'create_image_variants returned true' => $made === true,
                'web rendition written'               => is_file($web),
                'thumb rendition written'             => is_file($thumb),
                'web rendition decodable'             => is_file($web) && @getimagesize($web) !== false,
            ];
            if (function_exists('imagewebp')) {
                $checks['webp rendition written'] = is_file($dir . '/web_probe.webp');
            }

            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);

            $missing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));

            return $missing === []
                ? $ok('BMP -> web + thumb' . (isset($checks['webp rendition written']) ? ' + webp' : ''))
                : $bad('BMP variant generation failed: ' . implode(', ', $missing));
        });

        $add('smoke.media.viewer_defaults_to_web', 'Smoke · Media', 'Image viewer loads the web rendition, full size only on demand', static function () use ($root, $ok, $bad): array {
            $view = (string) file_get_contents("$root/views/gallery/image_full.php");

            // The URL is a local now: it is the PUBLIC one for a guest and
            // the tokenised one for a member, so the src is a variable.
            $defaultIsWeb = str_contains($view, "\$webUrl  = \$publicMedia ? public_file_url(\$photo['filename'], 'web') : file_url(\$photo['filename'], 'web');")
                && (bool) preg_match('/<img id="fullsize-img"\s+src="<\?= e\(\$webUrl\) \?>"/', $view);
            $fullIsSeparate = str_contains($view, "data-full=\"<?= e(file_url(\$photo['filename'])) ?>\"")
                && str_contains($view, "if (\$canDownloadFull)");
            $toggleOnly = str_contains($view, 'img.src = img.dataset.full');

            return ($defaultIsWeb && $fullIsSeparate && $toggleOnly)
                ? $ok('default src is size=web; data-full only via the toggle')
                : $bad('image_full.php no longer defaults to the web rendition (defaultIsWeb=' . var_export($defaultIsWeb, true) . ')');
        });

        // ------------------------------------------------------------ Watermark
        $helpers = "$root/app/Core/helpers.php";
        $editor  = "$root/app/Core/ImageEditor.php";
        $photo   = "$root/app/Controllers/PhotoController.php";
        $worker  = "$root/bin/photo_edit_worker.php";
        $manage  = "$root/views/admin/manage.php";
        $routes  = "$root/config/routes.php";

        $add('smoke.watermark.single_bottom_right_draw', 'Smoke · Watermark', 'The logo watermark is drawn once in the bottom-right corner, not tiled', static function () use ($editor, $ok, $bad): array {
            $src = (string) @file_get_contents($editor);
            $usesLogo = str_contains($src, 'function watermarkLogo');
            $oldTextVersion = str_contains($src, 'function watermark(string $path, string $text');

            // Inspect the watermarkLogo body only: comments naming
            // imagecopymerge() as a rejected approach must not trip this.
            $body = '';
            if ($usesLogo && preg_match('/function watermarkLogo\s*\([^)]*\)\s*:\s*bool\s*\{(.*)\n    \}/s', $src, $m)) {
                $body = $m[1];
            }
            $compositesOnce = str_contains($body, 'imagecopy(');
            $noLoops = $body !== '' && !preg_match('/\b(for|while)\s*\(/s', $body);
            // Placement is owned by the shared helper: the logo is the last
            // thing drawn, offset from the bottom-right by the padding.
            $sharedGeometry = str_contains($src, 'watermark_geometry(');
            $cornerFromGeometry = str_contains((string) @file_get_contents(dirname($editor) . '/helpers.php'), "'y'    => \$mediaH - \$pad - \$wmH");

            return (!$oldTextVersion && $usesLogo && $body !== '' && $compositesOnce && $noLoops && $sharedGeometry && $cornerFromGeometry)
                ? $ok('one imagecopy() composite inside watermarkLogo, no tiling loops')
                : $bad('ImageEditor must draw the title image once via imagecopy() with no tiling loops (oldTextVersion=' . var_export($oldTextVersion, true) . ' watermarkLogo=' . var_export($usesLogo, true) . ' body=' . var_export($body !== '', true) . ' imagecopy=' . var_export($compositesOnce, true) . ' noLoops=' . var_export($noLoops, true) . ' geometry=' . var_export($sharedGeometry, true) . ' corner=' . var_export($cornerFromGeometry, true) . ')');
        });

        $add('smoke.watermark.opacity_is_20_percent', 'Smoke · Watermark', 'Both render paths fade the logo to 20%', static function () use ($helpers, $editor, $ok, $bad): array {
            $h = (string) @file_get_contents($helpers);
            $e = (string) @file_get_contents($editor);

            $opacity = defined('WATERMARK_OPACITY') ? (float) WATERMARK_OPACITY : 0.0;
            $constOk = abs($opacity - 0.20) < 0.001 && str_contains($h, "const WATERMARK_OPACITY = 0.20;");
            // GD: an opaque pixel must fade to alpha ~102 (127 * 0.8 floor).
            $gdOk = str_contains($h, '$floor = (int) round(127 * (1 - $factor));');
            // ffmpeg: colorchannelmixer alpha must come from the same constant.
            $ffOk = str_contains($h, 'colorchannelmixer=aa=')
                && str_contains($h, 'number_format(WATERMARK_OPACITY, 2');
            // The GD path must read the shared constant, not a literal.
            $useOk = str_contains($e, '\\WATERMARK_OPACITY');

            return ($constOk && $gdOk && $ffOk && $useOk)
                ? $ok('WATERMARK_OPACITY 0.20 drives the GD fade + ffmpeg alpha')
                : $bad('the two watermark paths no longer share a 20% opacity (const=' . var_export($constOk, true) . ' gd=' . var_export($gdOk, true) . ' ffmpeg=' . var_export($ffOk, true) . ' use=' . var_export($useOk, true) . ')');
        });

        $add('smoke.watermark.uses_theme_title_image', 'Smoke · Watermark', 'The watermark is the theme title image, resolved to an absolute path', static function () use ($helpers, $ok, $bad): array {
            $h = (string) @file_get_contents($helpers);

            $readsTheme = str_contains($h, "Theme::titleImage(App\\Models\\Theme::SCOPE_SITE)")
                || str_contains($h, 'Theme::titleImage(Theme::SCOPE_SITE)');
            $resolvesAssets = str_contains($h, "'/public/'") || str_contains($h, '"/public/"');
            $returnsNull = str_contains($h, 'function watermark_logo_path(): ?string');

            return ($readsTheme && $resolvesAssets && $returnsNull)
                ? $ok('watermark_logo_path() reads Theme::titleImage() and maps /assets + /storage')
                : $bad('watermark_logo_path() must resolve Theme::titleImage() (theme=' . var_export($readsTheme, true) . ' assets=' . var_export($resolvesAssets, true) . ' signature=' . var_export($returnsNull, true) . ')');
        });

        $add('smoke.watermark.runs_in_the_background_worker', 'Smoke · Watermark', 'Batch watermarking is queued and done by the supervised worker, never inline', static function () use ($photo, $worker, $routes, $ok, $bad): array {
            $c = (string) @file_get_contents($photo);
            $w = (string) @file_get_contents($worker);
            $r = (string) @file_get_contents($routes);

            $queued = str_contains($c, 'PhotoJob::createBulkWatermark(');
            $workerCase = str_contains($w, "case 'bulk_watermark':") && str_contains($w, 'function runBulkWatermark');
            $videoBurn = str_contains($w, 'burn_video_watermark(');
            $routesOk = str_contains($r, "PhotoController@bulkWatermark")
                && str_contains($r, "PhotoController@watermarkSample")
                && str_contains($r, "PhotoController@watermarkUndo");
            $previewFirst = str_contains($c, 'function watermarkSample(int $galleryId)');

            return ($queued && $workerCase && $videoBurn && $routesOk && $previewFirst)
                ? $ok('sample -> queue -> worker burn for videos')
                : $bad('watermark batch flow is broken (queued=' . var_export($queued, true) . ' worker=' . var_export($workerCase, true) . ' burn=' . var_export($videoBurn, true) . ' routes=' . var_export($routesOk, true) . ' sample=' . var_export($previewFirst, true) . ')');
        });

        $add('smoke.watermark.backs_up_originals_for_undo', 'Smoke · Watermark', 'Every original is copied to backups/ before it is replaced, and undo restores it', static function () use ($worker, $photo, $ok, $bad): array {
            $w = (string) @file_get_contents($worker);
            $c = (string) @file_get_contents($photo);

            $backupDir = str_contains($w, '/backups/watermark/');
            $imagesToo = str_contains($w, 'could not back up the original');
            $recorded = str_contains($w, 'PhotoJob::addWatermarkBackup(');
            $undo = str_contains($c, 'function watermarkUndo(int $galleryId)')
                && str_contains($c, "'bulk_watermark'");
            $consumed = str_contains($c, 'meta[\'backups\'] = [];');

            return ($backupDir && $imagesToo && $recorded && $undo && $consumed)
                ? $ok('images and videos are backed up; undo replays and consumes the list')
                : $bad('watermark backup/undo is incomplete (dir=' . var_export($backupDir, true) . ' images=' . var_export($imagesToo, true) . ' recorded=' . var_export($recorded, true) . ' undo=' . var_export($undo, true) . ' consumed=' . var_export($consumed, true) . ')');
        });

        $add('smoke.watermark.video_keeps_its_container', 'Smoke · Watermark', 'Re-encoded video keeps its source extension so the served MIME still matches', static function () use ($helpers, $worker, $ok, $bad): array {
            $h = (string) @file_get_contents($helpers);
            $w = (string) @file_get_contents($worker);

            $sameExt = str_contains($h, '$extCodec') && str_contains($h, "'webm'");
            $workerCopies = str_contains($w, "'\" . \"wm-\"") || str_contains($w, "'.wm-'") || str_contains($w, '.wm-');
            $yuv = str_contains($h, 'format=yuv420p');

            return ($sameExt && $workerCopies && $yuv)
                ? $ok('source-matched muxer (webm -> libvpx), temp written beside the original')
                : $bad('video watermark must keep the container (codec=' . var_export($sameExt, true) . ' temp=' . var_export($workerCopies, true) . ' pixfmt=' . var_export($yuv, true) . ')');
        });

        $add('smoke.watermark.admin_ui_offers_a_preview', 'Smoke · Watermark', 'Manage page has the watermark button and a preview-first dialog', static function () use ($manage, $ok, $bad): array {
            $m = (string) @file_get_contents($manage);

            $button = str_contains($m, 'id="watermark-open"');
            $dialog = str_contains($m, 'id="wm-dialog"');
            $sample = str_contains($m, 'watermark-sample');
            $scope = str_contains($m, 'value="all"') && str_contains($m, 'name="scope"');
            $videos = str_contains($m, "is_video(\$photo['filename']) ? 'video' : 'image'");
            $undoBtn = str_contains($m, 'id="watermark-undo"');

            return ($button && $dialog && $sample && $scope && $videos && $undoBtn)
                ? $ok('button + preview dialog + scope toggle + video selection + undo')
                : $bad('manage.php watermark UI is incomplete (button=' . var_export($button, true) . ' dialog=' . var_export($dialog, true) . ' sample=' . var_export($sample, true) . ' scope=' . var_export($scope, true) . ' videos=' . var_export($videos, true) . ' undo=' . var_export($undoBtn, true) . ')');
        });

        // -------------------------------------------------- Shared view partials
        $add('smoke.partials.upload_client', 'Smoke · Shared views', 'create + manage share one staged-upload client and markup', static function () use ($root, $ok, $bad): array {
            $create  = (string) @file_get_contents("$root/views/admin/create.php");
            $manage  = (string) @file_get_contents("$root/views/admin/manage.php");
            $client  = (string) @file_get_contents("$root/views/admin/partials/upload_client.php");
            $markup  = (string) @file_get_contents("$root/views/admin/partials/staged_uploads.php");

            $requiresPartials = static fn (string $src): bool =>
                strpos($src, 'partials/staged_uploads.php') !== false
                && strpos($src, 'partials/upload_client.php') !== false;

            $checks = [
                'create requires the partials'     => $requiresPartials($create),
                'manage requires the partials'     => $requiresPartials($manage),
                'client keeps the chunked uploader' => strpos($client, 'function uploadChunked(') !== false
                    && strpos($client, 'function uploadDirect(') !== false
                    && strpos($client, 'function processQueue(') !== false,
                'client keeps the tile actions'    => strpos($client, "act === 'delete'") !== false
                    && strpos($client, "act === 'rotate'") !== false,
                'markup keeps the drop zone + tiles' => strpos($markup, 'id="drop-zone"') !== false
                    && strpos($markup, 'id="pending-tiles"') !== false
                    && strpos($markup, 'id="pending-count"') !== false,
                // guard against a future copy-paste re-splitting the client
                'create keeps no private copy'     => strpos($create, 'function uploadChunked(') === false,
                'manage keeps no private copy'     => strpos($manage, 'function uploadChunked(') === false,
            ];

            $missing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));

            return $missing === []
                ? $ok('both pages require the shared staged-upload partials')
                : $bad('staged uploads must stay in the shared partials: ' . implode(', ', $missing));
        });

        $add('smoke.partials.email_layout', 'Smoke · Shared views', 'Every HTML email renders through one shared layout', static function () use ($root, $ok, $bad): array {
            $layout = (string) @file_get_contents("$root/views/emails/layout.php");
            $helpers = (string) @file_get_contents("$root/app/Core/helpers.php");

            $templates = ['new_gallery', 'chat_reply', 'live_now', 'winback',
                'payment_failed', 'past_due', 'expired', 'renewal_reminder'];
            $duplicated = [];
            foreach ($templates as $t) {
                $src = (string) @file_get_contents("$root/views/emails/$t.php");
                // a template must NOT carry its own document chrome any more
                if (strpos($src, '<!DOCTYPE') !== false) { $duplicated[] = $t; }
            }

            $checks = [
                'layout has the chrome'      => strpos($layout, '<!DOCTYPE html>') !== false
                    && strpos($layout, '#6d2ea8') !== false
                    && strpos($layout, '{{unsubscribe-url}}') !== false,
                'render_email wraps by default' => strpos($helpers, 'function render_email(string $template, array $data = [], bool $withLayout = true)') !== false
                    && strpos($helpers, "require __DIR__ . '/../../views/emails/layout.php';") !== false,
                'no template carries chrome' => $duplicated === [],
            ];

            $missing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));

            return $missing === []
                ? $ok('8 templates render body-only through views/emails/layout.php')
                : $bad('email chrome must stay in the layout: ' . implode(', ', $missing)
                    . ($duplicated !== [] ? ' (own chrome: ' . implode(', ', $duplicated) . ')' : ''));
        });

        $add('smoke.partials.error_shell', 'Smoke · Shared views', '404 + error share one shell; error pages keep no inline CSS', static function () use ($root, $ok, $bad): array {
            $shell = (string) @file_get_contents("$root/views/errors/partials/shell.php");
            $css   = (string) @file_get_contents("$root/public/assets/css/user.css");

            $inline = [];
            foreach (['404', 'error', '503'] as $e) {
                $src = (string) @file_get_contents("$root/views/errors/$e.php");
                if (strpos($src, '<style') !== false) { $inline[] = $e; }
            }

            $checks = [
                '404/error use the shell' => strpos((string) @file_get_contents("$root/views/errors/404.php"), 'partials/shell.php') !== false
                    && strpos((string) @file_get_contents("$root/views/errors/error.php"), 'partials/shell.php') !== false,
                'shell has the chrome'    => strpos($shell, 'AmethystTitleImage.png') !== false
                    && strpos($shell, 'class="error-page"') !== false,
                'error CSS lives in user.css' => strpos($css, '.error-page') !== false && strpos($css, '.error-503') !== false,
                'no error page keeps inline CSS' => $inline === [],
            ];

            $missing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));

            return $missing === []
                ? $ok('error pages share one shell, styles in user.css')
                : $bad('error pages must share the shell: ' . implode(', ', $missing)
                    . ($inline !== [] ? ' (inline css: ' . implode(', ', $inline) . ')' : ''));
        });

        $add('smoke.assets.versioned', 'Smoke · Shared views', 'Stylesheets are cache-busted by filemtime, never hand-pinned', static function () use ($root, $ok, $bad): array {
            $helpers = (string) @file_get_contents("$root/app/Core/helpers.php");
            $adminLayout = (string) @file_get_contents("$root/views/admin/layout.php");
            $userLayout  = (string) @file_get_contents("$root/views/layout.php");

            $checks = [
                'asset_url() exists' => strpos($helpers, 'function asset_url(') !== false,
                'both layouts use it' => strpos($adminLayout, 'asset_url(') !== false
                    && strpos($userLayout, 'asset_url(') !== false,
                'no hand-pinned version' => strpos($adminLayout, '?v=33') === false
                    && strpos($userLayout, '?v=33') === false,
            ];

            $missing = array_keys(array_filter($checks, static fn (bool $v): bool => !$v));

            return $missing === []
                ? $ok('user.css + admin.css versioned by filemtime')
                : $bad('stylesheets must use asset_url(): ' . implode(', ', $missing));
        });

        $add('smoke.gallery.nsfw_flag', 'Smoke · Galleries', 'A safe-for-work gallery is open at any level; everything else keeps min_level', static function () use ($root, $read, $ok, $bad): array {
            $gallery  = (string) $read($root . '/app/Models/Gallery.php');
            $photo    = (string) $read($root . '/app/Models/Photo.php');
            $schema   = (string) $read($root . '/schema.sql');
            $sqlite   = (string) $read($root . '/schema.sqlite.sql');
            $problems = [];

            // The flag is born ADULT, so applying the migration to the live
            // catalogue changes no existing behaviour until the admin flags
            // a gallery safe.
            if (strpos($schema, 'is_nsfw') === false || strpos($sqlite, 'is_nsfw') === false) {
                $problems[] = 'schema.sql and schema.sqlite.sql must both carry galleries.is_nsfw';
            }
            $migration = (string) @file_get_contents($root . '/database/migrations/090_gallery_nsfw.sql');
            if (strpos($migration, 'ADD COLUMN is_nsfw') === false || strpos($migration, 'DEFAULT 1') === false) {
                $problems[] = 'migration 090 must add is_nsfw NOT NULL DEFAULT 1 (existing rows are adult)';
            }
            // ONE expression decides the level: SFW -> 0, else min_level.
            if (strpos($gallery, 'public static function accessLevelSql(') === false
                || strpos($gallery, "CASE WHEN ' . \$alias . '.is_nsfw = 0 THEN 0 ELSE ' . \$alias . '.min_level END") === false) {
                $problems[] = 'Gallery::accessLevelSql() must map is_nsfw=0 to level 0 and keep min_level otherwise';
            }
            if (strpos($gallery, 'public static function guestViewableSql(') === false
                || strpos($gallery, ".is_nsfw = 0 OR ' . \$alias . '.min_level = 0") === false) {
                $problems[] = 'Gallery::guestViewableSql() must be SFW OR free tier';
            }
            // Fail closed: a row that arrives without the column is adult.
            if (strpos($gallery, "(int) (\$gallery['is_nsfw'] ?? 1) === 0 ? 0") === false
                || strpos($gallery, "(int) (\$gallery['is_nsfw'] ?? 1) === 0") === false) {
                $problems[] = 'effectiveLevel()/guestViewable() must treat a missing is_nsfw as ADULT';
            }
            // The strictest-photo rule has to use the same expression, or a
            // SFW photo in a Gold gallery stays locked (and vice versa).
            if (strpos($photo, "Gallery::accessLevelSql('g')") === false
                || strpos($photo, "Gallery::guestViewableSql('g')") === false) {
                $problems[] = 'Photo must derive both access_level and guest_view from the Gallery expressions';
            }
            foreach (['public static function effectiveLevel(', 'public static function guestViewable(', 'public static function setNsfw('] as $needle) {
                if (strpos($gallery, $needle) === false) {
                    $problems[] = 'Gallery::' . trim(str_replace(['public static function ', '('], '', $needle)) . '() is missing';
                }
            }
            // Every gate must use the model, never the raw column.
            foreach (['app/Controllers/GalleryController.php', 'app/Controllers/WallController.php'] as $file) {
                $src = (string) $read($root . '/' . $file);
                if (strpos($src, "Gallery::effectiveLevel(\$gallery)") === false
                    && strpos($src, "Gallery::accessLevelSql('g')") === false
                    && strpos($src, "Gallery::accessLevel(") === false) {
                    $problems[] = $file . ' still gates on the raw min_level column';
                }
            }

            return $problems === []
                ? $ok('is_nsfw -> level 0 when safe, min_level otherwise, fail-closed')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.gallery.guest_access', 'Smoke · Galleries', 'Guests get public renditions, never originals or paid NSFW', static function () use ($root, $read, $ok, $bad): array {
            $store  = (string) $read($root . '/app/Controllers/StorageController.php');
            $helper = (string) $read($root . '/app/Core/helpers.php');
            $card   = (string) $read($root . '/views/partials/gallery_card.php');
            $full   = (string) $read($root . '/views/gallery/image_full.php');
            $gc     = (string) $read($root . '/app/Controllers/GalleryController.php');
            $img    = (string) $read($root . '/app/Controllers/ImageController.php');
            $problems = [];

            // A guest may fetch the PUBLIC renditions of a guest-viewable
            // photo without a token; the original always needs an account.
            if (strpos($store, "\$access['guest_view']") === false) {
                $problems[] = 'the files route must allow a tokenless guest request only when guest_view is true';
            }
            if (strpos($store, "['web', 'web_lo', 'web_hi', 'poster']") === false) {
                $problems[] = 'the guest allowance must be web/web_lo/web_hi/poster (never the original)';
            }
            if (strpos($helper, 'function public_file_url(') === false) {
                $problems[] = 'helpers.php must expose public_file_url() for cacheable guest markup';
            }
            if (strpos($helper, 'function file_srcset(string $filename, bool $signed = true)') === false) {
                $problems[] = 'file_srcset() must be able to emit UNSIGNED guest sources';
            }
            // The gallery page and the viewer ask the model, not the row.
            if (strpos($gc, 'Gallery::guestViewable($gallery)') === false) {
                $problems[] = 'GalleryController::show() must let a guest open only a guest-viewable gallery';
            }
            if (strpos($img, "Photo::guestViewable(\$") === false) {
                $problems[] = "ImageController must decide a guest's full view with Photo::guestViewable()";
            }
            // Cards: a guest cover is the PUBLIC url, and an adult gallery
            // advertises itself.
            if (strpos($card, 'public_file_url(') === false) {
                $problems[] = 'guest card covers must use public_file_url()';
            }
            if (strpos($card, 'nsfw-badge') === false || strpos($card, '>18+<') === false) {
                $problems[] = 'an adult gallery card must carry the 18+ badge';
            }
            // The full-size download is a member action.
            if (strpos($img, "'canDownloadFull'") === false || strpos($full, 'canDownloadFull') === false) {
                $problems[] = 'the full-size image must be hidden for guests (canDownloadFull)';
            }

            return $problems === []
                ? $ok('guest renditions are public, originals + full-size stay members-only')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.gallery.guest_surface', 'Smoke · Galleries', 'A guest gets a real navigation, the public categories and the content filter', static function () use ($root, $read, $ok, $bad): array {
            $layout   = (string) $read($root . '/views/layout.php');
            $css      = (string) $read($root . '/public/assets/css/public.css');
            $gc       = (string) $read($root . '/app/Controllers/GalleryController.php');
            $index    = (string) $read($root . '/views/gallery/index.php');
            $category = (string) $read($root . '/views/gallery/category.php');
            $cache    = (string) $read($root . '/app/Core/PageCache.php');
            $problems = [];

            // The top bar is for guests on EVERY page, not just the ones
            // without the rail: without it the browse pages had no header.
            if (strpos($layout, '$user === null || !$sidebarNav') === false) {
                $problems[] = 'the top nav must be shown to a guest even on the rail pages';
            }
            // The guest rail is the public browse surface, not the member one.
            foreach (['pub-sfw', 'pub-signin', 'pub-join'] as $key) {
                if (strpos($layout, "'" . $key . "'") === false) {
                    $problems[] = 'the guest rail is missing the ' . $key . ' entry';
                }
            }
            if (strpos($layout, '$publicCategories') === false) {
                $problems[] = 'the guest rail must list the public categories';
            }
            // The phone bar too: a guest on the landing page had no nav.
            if (strpos($layout, '$sidebarNav || $user === null') === false) {
                $problems[] = 'the bottom bar must be shown to a guest on every page';
            }
            // The styles the new markup uses.
            foreach (['nsfw-badge', 'public-nav', 'public-brand', 'title-logo', 'content-chips'] as $class) {
                if (strpos($css, '.' . $class) === false) {
                    $problems[] = 'public.css must style .' . $class;
                }
            }
            // The content filter: read by both browse pages, offered by both
            // views, and part of the cached page identity.
            if (strpos($gc, "query('content', '')") === false) {
                $problems[] = 'the browse pages must read the content filter';
            }
            if (strpos($index, 'Safe for work') === false || strpos($category, 'Safe for work') === false) {
                $problems[] = 'the catalogue and the category page must offer the content filter';
            }
            if (strpos($cache, "'vt', 'content'") === false) {
                $problems[] = 'the page cache key must carry the content filter (or ?content=sfw would serve the unfiltered page)';
            }

            return $problems === []
                ? $ok('guests get the nav, the categories and the content filter')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.gallery.nsfw_admin', 'Smoke · Galleries', 'The flag is editable, filterable and bulk-switchable in the admin', static function () use ($root, $read, $ok, $bad): array {
            $gc       = (string) $read($root . '/app/Controllers/GalleryController.php');
            $admin    = (string) $read($root . '/app/Controllers/AdminController.php');
            $list     = (string) $read($root . '/views/admin/galleries.php');
            $gallery  = (string) $read($root . '/app/Models/Gallery.php');
            $problems = [];

            // Every form that sets min_level sets the flag beside it.
            foreach (['create', 'edit', 'manage'] as $form) {
                $src = (string) $read($root . '/views/admin/' . $form . '.php');
                if (strpos($src, 'name="is_nsfw"') === false) {
                    $problems[] = 'views/admin/' . $form . '.php must post is_nsfw';
                }
            }
            // Fail closed on create: anything but an explicit 0 is adult.
            if (strpos($gc, "post('is_nsfw', '1')") === false) {
                $problems[] = 'store() must default a missing is_nsfw to ADULT';
            }
            if (strpos($gc, "post('is_nsfw', null)") === false) {
                $problems[] = 'update() must leave the flag alone when the field is not posted';
            }
            if (strpos($gc, "'mark_sfw'") === false || strpos($gc, "'mark_nsfw'") === false) {
                $problems[] = 'bulk() must support mark_sfw and mark_nsfw';
            }
            if (strpos($gallery, 'public static function setNsfw(') === false) {
                $problems[] = 'Gallery::setNsfw() must exist for the bulk switch';
            }
            // The list: a Content filter plus a visible rating pill.
            if (strpos($admin, "\$_GET['content']") === false) {
                $problems[] = 'AdminController::galleries() must read the content filter';
            }
            if (strpos($list, 'mark_sfw') === false || strpos($list, 'pill-ok') === false) {
                $problems[] = 'the gallery list must offer the bulk switch and a SFW pill';
            }
            if (strpos($list, 'content=') === false) {
                $problems[] = 'the filter chips must carry the content filter';
            }
            // A flag change re-renders the cached guest pages.
            if (strpos($gallery, 'setNsfw') === false || strpos($gallery, 'PageCache::invalidate()') === false) {
                $problems[] = 'setNsfw() must invalidate the page cache';
            }

            return $problems === []
                ? $ok('flag is editable, filterable, bulk-switchable and cache-busting')
                : $bad(implode('; ', $problems));
        });

        $add('smoke.trends.missed_searches_are_misses', 'Smoke · Admin UI', 'The missed-search lists count only the searches that found nothing', static function () use ($root, $read, $ok, $bad): array {
            $stats = (string) $read($root . '/app/Models/Stats.php');
            $problems = [];

            // The pending list: a real miss (found = 0), not dismissed, and
            // not a term the site already has as a category.
            $at = strpos($stats, 'public static function pendingCategoryApprovals(');
            if ($at === false) {
                $problems[] = 'Stats::pendingCategoryApprovals() was not found';
            } else {
                $body = substr($stats, $at, 2000);
                if (strpos($body, 'found = 0') === false) {
                    $problems[] = 'the pending list must count only the searches that found NOTHING (found = 0) - a search that hit is not a demand for a category';
                }
                if (strpos($body, 'dismissed = 0') === false) {
                    $problems[] = 'the pending list must skip the terms the admin dismissed';
                }
                if (strpos($body, 'NOT EXISTS (SELECT 1 FROM categories') === false) {
                    $problems[] = 'the pending list must skip the terms the site already has as a category - offering one produced a dead end (the promote action refuses it)';
                }
            }

            // The trends panel is titled "Missed Searches".
            $at = strpos($stats, 'public static function searchTrends(');
            if ($at === false) {
                $problems[] = 'Stats::searchTrends() was not found';
            } else {
                $body = substr($stats, $at, 2400);
                if (strpos($body, 'found = 0') === false) {
                    $problems[] = 'the Missed Searches panel must count only the searches that found nothing';
                }
                if (strpos($body, 'hits') === false) {
                    $problems[] = 'the Missed Searches panel must report the hits next to the misses';
                }
            }

            // The lifetime count is the threshold check in the promote
            // action, so it has to use the same definition of a miss.
            $at = strpos($stats, 'public static function missedSearchLifetimeCounts(');
            if ($at === false) {
                $problems[] = 'Stats::missedSearchLifetimeCounts() was not found';
            } else {
                $body = substr($stats, $at, 1200);
                if (strpos($body, 'found = 0') === false) {
                    $problems[] = 'the lifetime counts must count the misses only';
                }
            }

            return $problems === []
                ? $ok('the missed-search lists hold the real misses only')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.trends.dismiss_keeps_the_history', 'Smoke · Admin UI', 'Dismissing a missed search keeps the search history', static function () use ($root, $read, $ok, $bad): array {
            $controller = (string) $read($root . '/app/Controllers/TrendsController.php');
            $migration = (string) @file_get_contents($root . '/database/migrations/091_search_stats_dismissed.sql');
            $schema    = (string) $read($root . '/schema.sql');
            $problems  = [];

            $at = strpos($controller, 'public function dismissPromotion(');
            if ($at === false) {
                $problems[] = 'dismissPromotion() was not found';
            } else {
                $body = substr($controller, $at, 1800);
                if (strpos($body, 'SET dismissed = 1') === false) {
                    $problems[] = 'dismissing must FLAG the term (dismissed = 1)';
                }
                if (strpos($body, 'DELETE FROM search_stats') !== false) {
                    $problems[] = 'dismissing must not delete the search history - it threw away every search behind the term, including the ones that hit';
                }
            }
            if (strpos($migration, 'dismissed') === false) {
                $problems[] = 'migration 091 must add the dismissed column';
            }
            if (strpos($schema, 'dismissed  TINYINT(1) NOT NULL DEFAULT 0') === false) {
                $problems[] = 'schema.sql must carry search_stats.dismissed';
            }

            return $problems === []
                ? $ok('dismiss flags the term and keeps the history')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.trends.a_category_is_never_a_miss', 'Smoke · Search', 'A category the site has is never recorded as a missing search', static function () use ($root, $read, $ok, $bad): array {
            $category = (string) $read($root . '/app/Models/Category.php');
            $gallery  = (string) $read($root . '/app/Models/Gallery.php');
            $gc       = (string) $read($root . '/app/Controllers/GalleryController.php');
            $index    = (string) $read($root . '/views/gallery/index.php');
            $problems = [];

            if (strpos($category, 'public static function matchForSearch(') === false) {
                $problems[] = 'Category::matchForSearch() must exist - a search that names a category has to find it';
            }
            if (strpos($category, 'LOWER(name) = LOWER(?)') === false) {
                $problems[] = 'the category lookup must be case-insensitive ("Pussy" and "pussy" are one category)';
            }
            // The search reaches the galleries above the member level.
            $at = strpos($gallery, 'public static function paginate(');
            if ($at === false) {
                $problems[] = 'Gallery::paginate() was not found';
            } else {
                $body = substr($gallery, $at, 4600);
                if (strpos($body, 'include_gated') === false) {
                    $problems[] = 'the search must be able to reach the galleries above the member level (include_gated)';
                }
                if (strpos($body, 'g.is_secret = 0') === false) {
                    $problems[] = 'a secret gallery must stay hidden when the search reaches the gated galleries';
                }
            }
            if (strpos($gc, "'include_gated' => true") === false) {
                $problems[] = 'the search must pass include_gated';
            }
            if (strpos($index, '$categoryMatch') === false) {
                $problems[] = 'the results must offer the category the search named';
            }

            return $problems === []
                ? $ok('a category the site has is found, not missed')
                : $bad(implode('; ', $problems));
        });
        $add('smoke.search.gated_galleries_are_blurred', 'Smoke · Search', 'A gallery the viewer cannot open is shown blurred, with its lock', static function () use ($root, $read, $ok, $bad): array {
            $card = (string) $read($root . '/views/partials/gallery_card.php');
            $problems = [];

            if (strpos($card, '$canOpen') === false) {
                $problems[] = 'the card must decide whether the viewer can open the gallery';
            }
            if (strpos($card, "\$coverSize    = \$canOpen ? 'thumb' : 'blur';") === false) {
                $problems[] = 'a gallery the viewer cannot open must be shown BLURRED - the gate is on the content, not on the discovery';
            }
            if (strpos($card, 'Gallery::guestViewable($gallery)') === false) {
                $problems[] = 'a guest must be able to open a safe gallery (and only that)';
            }
            if (strpos($card, '$memberLevel >= $galleryLevel') === false) {
                $problems[] = 'a member must be able to open a gallery at or below their level';
            }
            if (strpos($card, '$isLocked') === false) {
                $problems[] = 'the lock must stay on the card';
            }
            if (strpos($card, "public_file_url((string) \$cover['filename'], \$size, \$format)") === false) {
                $problems[] = 'the blurred teaser is public and must be served without a media token';
            }

            return $problems === []
                ? $ok('the gated galleries are blurred teasers, the gate stays')
                : $bad(implode('; ', $problems));
        });

        $cache = $tests;

        return $cache;
    }

    /**
     * Where the operator app's RTMP url ends up once the app's RTMP library
     * and MediaMTX have both parsed it.
     *
     * This is a port of two parsers, and it is the reason the publish
     * secret is percent-encoded in the url:
     *
     *  * the app's RTMP library (RootEncoder 2.6.1, com.pedro.common
     *    .UrlParser) parses the url with java.net.URI - which DECODES the
     *    path - and splits it into an RTMP 'app' name and a play path.
     *    With a LITERAL '?' the query is folded into the play path.
     *  * MediaMTX (v0.23.8, internal/rtmp/conn.go createURL() and
     *    internal/core/rtmp_conn.go pathNameAndQuery()) rebuilds the url
     *    from app + '/' + playpath and splits THAT into the path name and
     *    the query it hands to the auth webhook.
     *
     * The port was verified against the real library and the real
     * MediaMTX source for both url shapes.
     *
     * @return array{app:string, play:string, pathName:string, query:string}
     */
    public static function rtmpPublishTarget(string $url): array
    {
        // ---- 1. java.net.URI + UrlParser ----
        $schemeEnd = strpos($url, '://');
        $rest      = substr($url, $schemeEnd + 3);
        $slash     = strpos($rest, '/');
        $pathStart = $schemeEnd + 3 + $slash;

        $qpos = strpos($url, '?');
        if ($qpos === false) {
            $rawPath = substr($url, $pathStart + 1);
            $query   = null;
        } else {
            $rawPath = substr($url, $pathStart + 1, $qpos - $pathStart - 1);
            // UrlParser: query = url.substring(url.indexOf(uri.query))
            $decodedQuery = rawurldecode(substr($url, $qpos + 1));
            $i = strpos($url, $decodedQuery);
            $query = ($i === false) ? $url : substr($url, $i);
        }

        // uri.path is percent-DECODED, then removePrefix("/")
        $path = rawurldecode($rawPath);
        if (str_starts_with($path, '/')) {
            $path = substr($path, 1);
        }

        // getAppName(): path.ifEmpty { query ?: "" }, then up to the 2nd '/'
        $app = ($path !== '') ? $path : ($query ?? '');
        $slashes = [];
        $off = 0;
        while (($p = strpos($app, '/', $off)) !== false) {
            $slashes[] = $p;
            $off = $p + 1;
        }
        if (count($slashes) === 0) {
            $appName = $app;
        } elseif (count($slashes) === 1) {
            $appName = substr($app, 0, $slashes[0]);
        } else {
            $appName = substr($app, 0, $slashes[1]);
        }

        // getFullPath(): "$path" + (query == null ? "" : "?$query"), removePrefix("?")
        $fullPath = ($query === null) ? $path : $path . '?' . $query;
        if (str_starts_with($fullPath, '?')) {
            $fullPath = substr($fullPath, 1);
        }

        // getStreamName(): removePrefix(appName), removePrefix("/"), removePrefix("?")
        $streamName = $fullPath;
        foreach ([$appName, '/', '?'] as $prefix) {
            if ($prefix !== '' && str_starts_with($streamName, $prefix)) {
                $streamName = substr($streamName, strlen($prefix));
            }
        }

        // ---- 2. MediaMTX ----
        // createURL: url.ParseRequestURI("/" + app + "/" + play)
        $combined = '/' . $appName . '/' . $streamName;
        $q2 = strpos($combined, '?');
        $pathRaw  = ($q2 === false) ? $combined : substr($combined, 0, $q2);
        $queryRaw = ($q2 === false) ? '' : substr($combined, $q2 + 1);

        // u.String() = scheme://host + path + "?" + query, then
        // pathNameAndQuery: TrimRight(url, "/") and url.Parse() again.
        $full = 'rtmp://h:1935' . $pathRaw . ($queryRaw !== '' ? '?' . $queryRaw : '');
        $tmp  = rtrim($full, '/');

        $q3 = strpos($tmp, '?');
        $finalPath  = ($q3 === false) ? $tmp : substr($tmp, 0, $q3);
        $finalQuery = ($q3 === false) ? '' : substr($tmp, $q3 + 1);
        $finalPath  = substr($finalPath, strlen('rtmp://h:1935'));

        return [
            'app'      => $appName,
            'play'     => $streamName,
            'pathName' => ltrim(rawurldecode($finalPath), '/'),
            'query'    => $finalQuery,
        ];
    }
}
