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
            'views/emails/newsletter.php',
            'views/emails/newsletter.text.php',
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
        $add('smoke.legal.consent_banner', 'Smoke · Legal', 'Consent banner wired into the layout', static function () use ($layoutSrc, $ok, $bad): array {
            return strpos($layoutSrc, "require __DIR__ . '/partials/consent_banner.php'") !== false && strpos($layoutSrc, '$gaShowBanner') !== false
                ? $ok('banner partial included behind $gaShowBanner')
                : $bad('layout must include partials/consent_banner.php behind $gaShowBanner');
        });
        $add('smoke.legal.csp_hardened', 'Smoke · Legal', 'CSP includes object-src/base-uri/form-action hardening', static function () use ($headersConf, $ok, $bad): array {
            return stripos($headersConf, "object-src 'none'") !== false && stripos($headersConf, "base-uri 'self'") !== false && stripos($headersConf, "form-action 'self'") !== false
                ? $ok('hardening directives present')
                : $bad('config/gallery-headers.conf must set object-src none, base-uri self, form-action self');
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
            foreach (['function send', 'function onPaymentFailed', 'function onPastDue', 'function onExpired', 'function onWinback', 'function runRenewalReminders', 'function runWinbacks'] as $needle) {
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
                && strpos($lcView, 'lifecycleStats') !== false
                && strpos($lcView, 'lifecycle_renewal_reminders') !== false
                && strpos($lcView, 'lifecycle_winbacks') !== false
                ? $ok('admin test-send + toggles + counts present')
                : $bad('EmailerController/emailer view must expose lifecycle test-send, toggles and stats');
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
            return strpos($membershipView, '/membership/checkout') !== false && strpos($membershipView, '$braintreeAvailable') !== false && stripos($membershipView, 'Or pay by card (Braintree)') !== false
                ? $ok('card checkout button gated by braintree availability')
                : $bad('views/membership/index.php must render a Braintree checkout link gate by $braintreeAvailable');
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
            return strpos($adminLayout, 'nav-traffic') !== false && strpos($adminLayout, "can('traffic')") !== false
                ? $ok('nav item present')
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
            return strpos($trafficView, 'Recent Signups') !== false && strpos($trafficView, '$recentSignups') !== false
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
        $newsletterMail = $read("$root/views/emails/newsletter.php");
        $newsletterText = $read("$root/views/emails/newsletter.text.php");
        $add('smoke.campaign.share_partial', 'Smoke · Campaign', 'Share bar signs every network link with Traffic::buildUrl', static function () use ($shareBar, $ok, $bad): array {
            foreach (['twitter.com/intent/tweet', 'facebook.com/sharer', 'wa.me', 'www.reddit.com/submit', 'data-share-copy'] as $needle) {
                if (strpos($shareBar, $needle) === false) {
                    return $bad('share-bar missing: ' . $needle);
                }
            }
            return substr_count($shareBar, 'Traffic::buildUrl(') >= 4
                ? $ok('4 networks + copy link, all attributed')
                : $bad('share-bar must build its network links via Traffic::buildUrl()');
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
        $add('smoke.campaign.email_attribution', 'Smoke · Campaign', 'Email CTAs carry tracked ?c= links', static function () use ($newsletterMail, $newsletterText, $newGalleryMail, $ok, $bad): array {
            foreach (['newsletter.html' => $newsletterMail, 'newsletter.text' => $newsletterText] as $name => $src) {
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
        $add('smoke.campaign.sitemap_lastmod', 'Smoke · Campaign', 'Sitemap carries <lastmod> dates', static function () use ($staticPages, $ok, $bad): array {
            return strpos($staticPages, '<lastmod>') !== false && strpos($staticPages, 'COALESCE(published_at, created_at)') !== false
                ? $ok('lastmod emitted from publish/create dates')
                : $bad('sitemap() must emit <lastmod> for dated entries');
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
            return strpos($chatView, 'Daily Chat Send Log') !== false
                && strpos($chatView, 'daily-broadcast') !== false
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
            return strpos($chatViewQ, 'Send a questionnaire to all users') !== false
                && strpos($chatViewQ, 'allow_replies') !== false
                && strpos($chatViewQ, 'questionnaire-form') !== false
                && strpos($chatViewQ, 'Questionnaires') !== false
                ? $ok('form + allow-replies + log present')
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
                && strpos($chatView, 'Message any user') !== false
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
            return strpos($imageCtrl, 'Comment::TYPE_PHOTO') !== false
                && strpos($img, 'partials/comments.php') !== false
                && strpos($pl, 'partials/comments.php') !== false
                && strpos($pt, 'commentable_type') !== false
                && strpos($pt, 'Comment::isStaff') !== false
                ? $ok('photo comment thread wired into both media views')
                : $bad('ImageController::showMedia must load TYPE_PHOTO comments and both image_full.php and player.php must render the shared comments partial');
        });
        $commentCtrl = $read("$root/app/Controllers/CommentController.php");
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
            return strpos($wc, 'private function buildGalleryPreviews(') !== false
                && strpos($wc, 'Purchase::userUnlocked(') !== false
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
        $eq   = $read("$root/app/Models/EmailQueue.php");
        $ecfg = $read("$root/app/Models/EmailerConfig.php");
        $mail = $read("$root/app/Core/Mailer.php");
        $ew   = $read("$root/bin/email_worker.php");
        $acrn = $read("$root/bin/apply_cron.php");
        $nlt  = $read("$root/views/emails/newsletter.php");
        $nltt = $read("$root/views/emails/newsletter.text.php");
        $unv  = $read("$root/views/unsubscribe.php");
        $ecv  = $read("$root/views/admin/emailer.php");
        $ecCtrl = $read("$root/app/Controllers/EmailerController.php");
        $adminLayout2 = $read("$root/views/admin/layout.php");
        $routesSrc = $read("$root/config/routes.php");

        $add('smoke.email.config_schedule', 'Smoke · Emailer', 'EmailerConfig exposes MAX_SAMPLE + due/nextSendAt', static function () use ($ecfg, $ok, $bad): array {
            return strpos($ecfg, 'MAX_SAMPLE') !== false && strpos($ecfg, 'public static function due(') !== false
                && strpos($ecfg, 'public static function nextSendAt(') !== false
                ? $ok('schedule engine present')
                : $bad('EmailerConfig must expose MAX_SAMPLE and the due()/nextSendAt() schedule engine');
        });
        $add('smoke.email.config_timezone', 'Smoke · Emailer', 'EmailerConfig evaluates the schedule in the site timezone', static function () use ($ecfg, $ok, $bad): array {
            return strpos($ecfg, 'DateTimeImmutable') !== false && strpos($ecfg, 'setTimezone') !== false
                && strpos($ecfg, 'SiteConfig::timezone()') !== false
                && strpos($ecfg, 'DateTimeZone::listIdentifiers()') !== false
                ? $ok('schedule runs on the site-wide timezone')
                : $bad('EmailerConfig::timezone() must delegate to the site-wide SiteConfig::timezone() (no emailer-specific zone)');
        });
        $add('smoke.email.digest_templates', 'Smoke · Emailer', 'Digest renders subscriber + non-subscriber templates', static function () use ($eq, $ok, $bad): array {
            return strpos($eq, "render_email('newsletter'") !== false && strpos($eq, "render_email('newsletter.text'") !== false
                && strpos($eq, 'include_non_subscribers') !== false
                ? $ok('both audiences rendered')
                : $bad('EmailQueue::enqueueDigest must render separate subscriber/non-subscriber templates');
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
        $add('smoke.email.helpers', 'Smoke · Emailer', 'helpers provide absolute_url + render_email', static function () use ($helpers, $ok, $bad): array {
            return strpos($helpers, 'function absolute_url(') !== false && strpos($helpers, 'function render_email(') !== false
                ? $ok('helpers present')
                : $bad('helpers.php must provide absolute_url() and render_email() for email templates');
        });
        $add('smoke.email.sub_view', 'Smoke · Emailer', 'Newsletter HTML uses public thumb/blur + unsubscribe link', static function () use ($nlt, $ok, $bad): array {
            return strpos($nlt, '$subscriber ? \'thumb\' : \'blur\'') !== false && strpos($nlt, 'absolute_url(') !== false
                && strpos($nlt, '{{unsubscribe-url}}') !== false
                ? $ok('subscriber/guest split + opt-out')
                : $bad('views/emails/newsletter.php must show sharp thumbs to subscribers, blur to guests and carry the unsubscribe link');
        });
        $add('smoke.email.text_view', 'Smoke · Emailer', 'Plain-text newsletter carries the unsubscribe link', static function () use ($nltt, $ok, $bad): array {
            return strpos($nltt, '{{unsubscribe-url}}') !== false
                ? $ok('opt-out in text part')
                : $bad('views/emails/newsletter.text.php must carry the unsubscribe link');
        });
        $add('smoke.email.unsub_view', 'Smoke · Emailer', 'Unsubscribe page renders opt-out confirmation', static function () use ($unv, $ok, $bad): array {
            return strpos($unv, '$optOut') !== false && strpos($unv, '$message') !== false
                ? $ok('confirmation page')
                : $bad('views/unsubscribe.php must render the opt-out result and message');
        });
        $add('smoke.email.worker', 'Smoke · Emailer', 'email_worker.php locks, enqueues when due, sends batches', static function () use ($ew, $ok, $bad): array {
            return strpos($ew, 'flock') !== false && strpos($ew, 'EmailerConfig::due(') !== false
                && strpos($ew, 'EmailQueue::sendDue(') !== false
                ? $ok('worker wired')
                : $bad('bin/email_worker.php must flock, enqueue when EmailerConfig::due() and send EmailQueue::sendDue() batches');
        });
        $add('smoke.email.cron_entry', 'Smoke · Emailer', 'apply_cron.php installs the emailer cron job', static function () use ($acrn, $ok, $bad): array {
            return strpos($acrn, 'gallery-emailer') !== false && strpos($acrn, 'email_worker.php --once') !== false
                ? $ok('cron entry present')
                : $bad('bin/apply_cron.php must install the gallery-emailer cron job running email_worker.php --once');
        });
        $add('smoke.email.routes', 'Smoke · Emailer', 'Public unsubscribe + admin emailer routes registered', static function () use ($routesSrc, $ok, $bad): array {
            return strpos($routesSrc, "'/unsubscribe'") !== false && strpos($routesSrc, '/admin/emailer/save') !== false
                && strpos($routesSrc, '/admin/emailer/send-now') !== false && strpos($routesSrc, '/admin/emailer/test') !== false
                && strpos($routesSrc, '/admin/emailer/retry') !== false
                ? $ok('routes present')
                : $bad('routes.php must register public /unsubscribe and the admin emailer save/send-now/test/retry routes');
        });
        $add('smoke.email.permission', 'Smoke · Emailer', 'Emailer admin gated by membership permission', static function () use ($ecCtrl, $ok, $bad): array {
            return strpos($ecCtrl, 'extends MembershipAdminController') !== false
                || strpos($ecCtrl, "Auth::requirePermission('membership')") !== false
                ? $ok('membership gate')
                : $bad('EmailerController must require the membership permission');
        });
        $add('smoke.email.nav', 'Smoke · Emailer', 'Admin sidebar links Emailer behind membership', static function () use ($adminLayout2, $ok, $bad): array {
            return strpos($adminLayout2, "Auth::can('membership')") !== false && strpos($adminLayout2, '/admin/emailer') !== false
                ? $ok('nav item gated')
                : $bad('views/admin/layout.php must link the Emailer page behind the membership permission');
        });
        $add('smoke.email.admin_view', 'Smoke · Emailer', 'Admin emailer view exposes settings/test/send-now/queue (site timezone, no per-emailer zone)', static function () use ($ecv, $ok, $bad): array {
            return strpos($ecv, 'send-now') !== false && strpos($ecv, 'name="sample_count"') !== false
                && strpos($ecv, 'name="timezone"') === false
                && strpos($ecv, 'site timezone set on Settings') !== false
                && strpos($ecv, 'tzdate(') !== false
                && strpos($ecv, 'retry') !== false && strpos($ecv, 'audience') !== false
                ? $ok('view wired; no per-emailer timezone selector')
                : $bad('views/admin/emailer.php must expose settings, sample count, test/send-now actions and queue retry, display times via tzdate(), and have no own timezone selector');
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
        $add('smoke.categorizer.schema', 'Smoke · Categorizer', 'suggestion + job tables in schema.sql and migration 055', static function () use ($czSchema, $czMigr, $ok, $bad): array {
            $okSchema = strpos($czSchema, 'gallery_category_suggestions') !== false
                && strpos($czSchema, 'gallery_category_jobs') !== false;
            $okMigr = strpos($czMigr, 'gallery_category_suggestions') !== false
                && strpos($czMigr, 'gallery_category_jobs') !== false;
            return $okSchema && $okMigr
                ? $ok('schema + migration present')
                : $bad('schema.sql and database/migrations/055_category_suggestions.sql must both define gallery_category_jobs + gallery_category_suggestions');
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
        $add('smoke.apihealth.table_fit', 'Smoke · API health', 'API health table cannot overflow its card', static function () use ($sysView, $ok, $bad): array {
            return strpos($sysView, 'sys-api-table') !== false
                && strpos($sysView, 'table-layout: fixed') !== false
                && strpos($sysView, 'overflow-wrap: anywhere') !== false
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

            $defaultIsWeb = (bool) preg_match('/<img id="fullsize-img"\s+src="<\?= e\(file_url\(\$photo\[\'filename\'\], \'web\'\)\)/', $view);
            $fullIsSeparate = str_contains($view, "data-full=\"<?= e(file_url(\$photo['filename'])) ?>\"");
            $toggleOnly = str_contains($view, 'img.src = img.dataset.full');

            return ($defaultIsWeb && $fullIsSeparate && $toggleOnly)
                ? $ok('default src is size=web; data-full only via the toggle')
                : $bad('image_full.php no longer defaults to the web rendition (defaultIsWeb=' . var_export($defaultIsWeb, true) . ')');
        });

        $cache = $tests;

        return $cache;
    }
}
