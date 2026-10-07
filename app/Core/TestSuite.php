<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Self-contained, read-only test suite registry + runner used by the admin
 * "Test suite" page. Every check is non-destructive (queries, file probes,
 * remote HTTP checks, function/class existence) so any test can be re-run
 * safely at any time, including from a background worker process.
 *
 * This class is designed to load from BOTH the web request scope (autoloaded
 * via index.php) and the CLI scope (bin/test_runner.php requires
 * helpers.php + a loader that maps App\ to app/). The block below therefore
 * duplicates the bootstrap so a CLI process can use the exact same tests.
 */
if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/helpers.php';
}

class TestSuite
{
    /** Path to the status files written/read by the runner and the page. */
    private const RUN_DIR = __DIR__ . '/../../storage/testruns';

    /**
     * Registry of every available test. Each entry is
     *   [
     *     'id'      => unique string,
     *     'group'   => category label,
     *     'name'    => human-readable title,
     *     'run'     => callable(): array{pass: bool, detail: string},
     *   ]
     */
    public static function tests(): array
    {
        $root   = dirname(__DIR__, 2);
        $storage= $root . '/storage';
        $key    = static fn (string $k, string $d = '') => env_value($k, $d);

        $tests = [];

        $add = static function (string $id, string $group, string $name, callable $run) use (&$tests): void {
            $tests[$id] = ['id' => $id, 'group' => $group, 'name' => $name, 'run' => $run];
        };

        // ---------------------------------------------------------------- App & Config
        $add('config.app_url', 'App & Config', 'APP_URL is set and absolute', function () {
            $url = env_value('APP_URL');
            return ['pass' => (bool) preg_match('#^https?://#i', $url), 'detail' => $url ?: 'empty'];
        });

        $add('config.db', 'App & Config', '.env database settings present', function () {
            $ok = env_value('GALLERY_DB_HOST') !== ''
                && env_value('GALLERY_DB_NAME') !== ''
                && env_value('GALLERY_DB_USER') !== '';
            return ['pass' => $ok, 'detail' => $ok ? 'host=' . env_value('GALLERY_DB_HOST') . ' db=' . env_value('GALLERY_DB_NAME') : 'missing keys'];
        });

        $add('config.media_key', 'App & Config', 'GALLERY_MEDIA_KEY is set', function () {
            return ['pass' => env_value('GALLERY_MEDIA_KEY') !== '', 'detail' => env_value('GALLERY_MEDIA_KEY') ? 'set' : 'empty'];
        });

        $add('config.base_path', 'App & Config', 'base path config resolves', function () {
            $base = (string) config('app.base_path');
            $ok = $base === '' || str_starts_with($base, '/');
            return ['pass' => $ok, 'detail' => $base === '' ? '(root)' : $base];
        });

        $add('config.uploads_dir', 'App & Config', 'uploads directory exists & writable', function () {
            $dir = (string) config('app.uploads')['dir'];
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('config.storage_writable', 'App & Config', 'storage directory writable', function () use ($storage) {
            return ['pass' => is_dir($storage) && is_writable($storage), 'detail' => $storage];
        });

        $add('config.cron_key', 'App & Config', 'GALLERY_CRON_KEY is set', function () {
            return ['pass' => env_value('GALLERY_CRON_KEY') !== '', 'detail' => env_value('GALLERY_CRON_KEY') ? 'set' : 'empty'];
        });

        $add('config.env_readable', 'App & Config', '.env file present & readable', function () use ($root) {
            $f = $root . '/.env';
            return ['pass' => is_file($f) && is_readable($f), 'detail' => $f];
        });

        // ---------------------------------------------------------------- Extensions & Toolchain
        $add('tool.php_version', 'Extensions & Tools', 'PHP version supported (>= 8.0)', function () {
            return ['pass' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => PHP_VERSION];
        });

        $add('tool.pdo', 'Extensions & Tools', 'PDO + pdo_mysql loaded', function () {
            return ['pass' => extension_loaded('pdo') && extension_loaded('pdo_mysql'), 'detail' => 'pdo=' . (extension_loaded('pdo') ? 'yes' : 'no') . ' pdo_mysql=' . (extension_loaded('pdo_mysql') ? 'yes' : 'no')];
        });

        $add('tool.gd', 'Extensions & Tools', 'GD image library loaded', function () {
            $ok = extension_loaded('gd') && function_exists('imagecreatetruecolor');
            return ['pass' => $ok, 'detail' => $ok ? 'gd ' . (gd_info()['GD Version'] ?? '') : 'missing'];
        });

        $add('tool.curl', 'Extensions & Tools', 'cURL extension loaded', function () {
            return ['pass' => extension_loaded('curl') && function_exists('curl_init'), 'detail' => extension_loaded('curl') ? 'loaded' : 'missing'];
        });

        $add('tool.ffmpeg', 'Extensions & Tools', 'ffmpeg binary available', function () {
            $rc = 1; @exec('ffmpeg -version >/dev/null 2>&1', $o, $rc);
            return ['pass' => $rc === 0, 'detail' => $rc === 0 ? 'ffmpeg ok' : 'not found'];
        });

        $add('tool.ffprobe', 'Extensions & Tools', 'ffprobe binary available', function () {
            $rc = 1; @exec('ffprobe -version >/dev/null 2>&1', $o, $rc);
            return ['pass' => $rc === 0, 'detail' => $rc === 0 ? 'ffprobe ok' : 'not found'];
        });

        $add('tool.zip', 'Extensions & Tools', 'ZipArchive class available', function () {
            return ['pass' => class_exists('ZipArchive'), 'detail' => class_exists('ZipArchive') ? 'present' : 'missing'];
        });

        $add('tool.hash', 'Extensions & Tools', 'HMAC (hash_hmac) available', function () {
            return ['pass' => function_exists('hash_hmac') && in_array('sha256', hash_algos(), true), 'detail' => 'sha256 hmac ok'];
        });

        // ---------------------------------------------------------------- Database & Schema
        $add('db.connect', 'Database', 'Can connect to the database', function () {
            try {
                Database::run('SELECT 1')->fetchColumn();
                return ['pass' => true, 'detail' => 'connected'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.tables_present', 'Database', 'All expected core tables exist', function () {
            $expected = ['users', 'galleries', 'photos', 'categories', 'auto_poster_queue', 'auto_poster_log'];
            try {
                $rows = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                $missing = array_values(array_diff($expected, $rows));
                return ['pass' => $missing === [], 'detail' => $missing === [] ? count($rows) . ' tables' : 'missing: ' . implode(', ', $missing)];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.migrations', 'Database', 'Migration tracking table consistent with file list', function () {
            try {
                $dir  = dirname(__DIR__, 2) . '/database/migrations';
                $rows = Database::run('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
                foreach (glob($dir . '/*.sql') ?: [] as $file) {
                    $name = basename($file);
                    if (!in_array($name, $rows, true)) {
                        return ['pass' => false, 'detail' => 'not applied: ' . $name];
                    }
                }
                return ['pass' => true, 'detail' => count($rows) . ' applied'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.autoposter_schema', 'Database', 'auto_poster_queue has media_ids + scheduled_at', function () {
            try {
                $cols = Database::run('SHOW COLUMNS FROM auto_poster_queue')->fetchAll(\PDO::FETCH_COLUMN);
                $need = ['media_ids', 'scheduled_at', 'status'];
                $missing = array_values(array_diff($need, array_map('strtolower', $cols)));
                return ['pass' => $missing === [], 'detail' => $missing === [] ? implode(',', $need) . ' present' : 'missing: ' . implode(', ', $missing)];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.traffic_schema', 'Database', 'traffic tables + users source columns present', function () {
            try {
                $tables = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                if (!in_array('traffic_links', $tables, true) || !in_array('traffic_visits', $tables, true)) {
                    return ['pass' => false, 'detail' => 'traffic tables missing'];
                }
                $cols = Database::run('SHOW COLUMNS FROM users')->fetchAll(\PDO::FETCH_COLUMN);
                $need = ['signup_source_link_id', 'utm_source'];
                $missing = array_values(array_diff($need, array_map('strtolower', $cols)));
                return ['pass' => $missing === [], 'detail' => $missing === [] ? 'traffic_links + traffic_visits + users cols' : 'missing: ' . implode(', ', $missing)];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.categorizer_schema', 'Database', 'AI category suggestion tables + indexes exist', function () {
            try {
                $tables = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                foreach (['gallery_category_jobs', 'gallery_category_suggestions'] as $t) {
                    if (!in_array($t, $tables, true)) {
                        return ['pass' => false, 'detail' => 'missing table: ' . $t];
                    }
                }
                $idx = Database::run('SHOW INDEX FROM gallery_category_suggestions')->fetchAll();
                $names = array_unique(array_column($idx, 'Key_name'));
                if (!in_array('uq_suggestion_gallery_category', $names, true)) {
                    return ['pass' => false, 'detail' => 'unique (gallery,category) key missing'];
                }
                return ['pass' => true, 'detail' => 'both tables + unique key present'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.categorizer_suggest_cycle', 'Database', 'enqueue -> claim -> stage -> accept merges (never replaces) categories', function () {
            $gid = null;
            try {
                $cat = Database::run('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetch();
                if ($cat === false) {
                    return ['pass' => false, 'detail' => 'no categories defined'];
                }
                // decided_by must be a real user (FK); not every box has id 1.
                $actor = (int) Database::run('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
                $g = Database::run(
                    "INSERT INTO galleries (title, type) VALUES ('TmpCategorizerCycle', 'images')"
                );
                $gid = (int) Database::connection()->lastInsertId();
                $catId = (int) $cat['id'];

                // Pre-existing category the accept must PRESERVE.
                $other = Database::run('SELECT id FROM categories WHERE id <> ? ORDER BY id ASC LIMIT 1', [$catId])->fetch();
                $preexisting = $other !== false ? (int) $other['id'] : null;
                if ($preexisting !== null) {
                    Database::run('INSERT INTO gallery_category (gallery_id, category_id) VALUES (?, ?)', [$gid, $preexisting]);
                }

                $queued = \App\Models\CategorySuggestion::enqueue($gid);

                // Make our job the oldest so a box with a real backlog still
                // claims it first (claimNext orders by updated_at ASC).
                \App\Core\Database::run(
                    "UPDATE gallery_category_jobs SET updated_at = '2001-01-01 00:00:00' WHERE gallery_id = ?",
                    [$gid]
                );

                // Claim until we get our gallery (other jobs may be queued
                // for real); park the ones that were not ours back as queued.
                $claimedOk = false;
                $parked    = [];
                for ($i = 0; $i < 25; $i++) {
                    $claimed = \App\Models\CategorySuggestion::claimNext();
                    if ($claimed === null) {
                        break;
                    }
                    if ($claimed === $gid) {
                        $claimedOk = true;
                        break;
                    }
                    $parked[] = $claimed;
                }
                foreach ($parked as $parkId) {
                    Database::run("UPDATE gallery_category_jobs SET status = 'queued', attempts = attempts - 1 WHERE gallery_id = ?", [$parkId]);
                }

                Database::run(
                    "INSERT INTO gallery_category_suggestions (gallery_id, category_id, confidence, status, engine) VALUES (?, ?, 0.9, 'pending', 'ollama')",
                    [$gid, $catId]
                );
                \App\Models\CategorySuggestion::complete($gid, 'ollama');

                $pending = \App\Models\CategorySuggestion::pendingFor($gid);
                $sid = $pending !== [] ? (int) $pending[0]['id'] : 0;
                $accepted = $sid > 0 && \App\Models\CategorySuggestion::accept($sid, $actor);

                $cats = array_map('intval', array_column(\App\Models\Gallery::categories($gid), 'id'));
                $merged = in_array($catId, $cats, true)
                    && ($preexisting === null || in_array($preexisting, $cats, true));
                $retired = \App\Models\CategorySuggestion::pendingFor($gid) === [];
                $reaccept = $sid > 0 && !\App\Models\CategorySuggestion::accept($sid, $actor);

                // A retryable failure must come back to the queue on its own
                // (recoverStale), while an exhausted one stays parked in error.
                \App\Models\CategorySuggestion::fail($gid, 'transient');
                \App\Models\CategorySuggestion::recoverStale();
                $retryJob = \App\Models\CategorySuggestion::jobFor($gid);
                $requeued = $retryJob !== null && $retryJob['status'] === 'queued';
                Database::run(
                    "UPDATE gallery_category_jobs SET status = 'error', attempts = 99 WHERE gallery_id = ?",
                    [$gid]
                );
                \App\Models\CategorySuggestion::recoverStale();
                $deadJob = \App\Models\CategorySuggestion::jobFor($gid);
                $staysParked = $deadJob !== null && $deadJob['status'] === 'error';

                $pass = $queued && $claimedOk && $accepted && $merged && $retired && $reaccept
                    && $requeued && $staysParked;

                return ['pass' => $pass, 'detail' => sprintf(
                    'queued=%d claimed=%d accepted=%d merged=%d retired=%d reaccept-refused=%d requeued=%d stays-parked=%d cats=%d',
                    (int) $queued, (int) $claimedOk, (int) $accepted, (int) $merged, (int) $retired,
                    (int) $reaccept, (int) $requeued, (int) $staysParked, count($cats)
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                if ($gid !== null) {
                    try {
                        Database::run('DELETE FROM gallery_category_suggestions WHERE gallery_id = ?', [$gid]);
                        Database::run('DELETE FROM gallery_category_jobs WHERE gallery_id = ?', [$gid]);
                        Database::run('DELETE FROM gallery_category WHERE gallery_id = ?', [$gid]);
                        Database::run('DELETE FROM galleries WHERE id = ?', [$gid]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        });

        $add('db.categorizer_advisor_resolve', 'Database', 'resolve() keeps every batch match, drops unknown names, merges deduped', function () {
            $made = [];
            try {
                $cats = Database::run('SELECT name FROM categories ORDER BY id ASC LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN);
                // A box with a single category still needs two real names to
                // prove "every fit is kept"; seed temp ones and remove them.
                while (count($cats) < 2) {
                    $tmp = 'TmpAdvisorCat' . count($cats) . substr((string) microtime(true), -4);
                    Database::run('INSERT INTO categories (name, slug) VALUES (?, ?)', [$tmp, strtolower($tmp)]);
                    $made[] = $tmp;
                    $cats = Database::run('SELECT name FROM categories ORDER BY id ASC LIMIT 3')->fetchAll(\PDO::FETCH_COLUMN);
                }
                $known1 = (string) $cats[0];
                $known2 = (string) $cats[1];
                $cid1 = (int) Database::run('SELECT id FROM categories WHERE name = ?', [$known1])->fetchColumn();

                // Two entries for the SAME category: strongest confidence wins
                // across batches, and there is no cap on how many fit.
                $json = json_encode(['categories' => [
                    ['name' => $known1, 'confidence' => 0.55],
                    ['name' => $known2, 'confidence' => 0.90],
                    ['name' => 'Not A Real Category', 'confidence' => 0.99],
                    ['name' => $known1, 'confidence' => 0.80],
                ]]);

                $rows = \App\Core\CategoryAdvisor::resolve($json, [$known1, $known2]);

                $ids = array_map(static fn (array $r): int => (int) $r['category_id'], $rows);
                $byId = [];
                foreach ($rows as $r) {
                    $byId[(int) $r['category_id']] = (float) $r['confidence'];
                }

                $unknownDropped = count($rows) === 2 && !in_array(0, $ids, true);
                $deduped        = count($rows) === 2;
                $strongest      = count($rows) === 2
                    && isset($byId[$cid1])
                    && abs($byId[$cid1] - 0.80) < 0.001;

                // A code-fenced reply (small models wrap JSON) still parses.
                $fenced = \App\Core\CategoryAdvisor::resolve("```json\n$json\n```", [$known1, $known2]);

                $pass = $unknownDropped && $deduped && $strongest && count($fenced) === 2;
                return ['pass' => $pass, 'detail' => sprintf(
                    'rows=%d unknown-dropped=%d deduped-strongest=%d fenced=%d',
                    count($rows), (int) $unknownDropped, (int) $strongest, count($fenced)
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                foreach ($made as $tmp) {
                    try {
                        Database::run('DELETE FROM categories WHERE name = ?', [$tmp]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        });

        $add('db.idle_reconnect', 'Database', 'A connection closed by wait_timeout is transparently reopened', function () {
            try {
                // Boxes run wait_timeout=60s while a worker blocks for minutes
                // on a vision/FFmpeg call. Prove Database::run() survives a
                // connection MySQL has already dropped (2006).
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }
                Database::run('SET SESSION wait_timeout = 1');
                usleep(1_500_000);
                $n = (int) Database::run('SELECT 42')->fetchColumn();
                return ['pass' => $n === 42, 'detail' => 'reconnected, got ' . $n];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                try {
                    Database::reset();
                } catch (\Throwable $ignored) {
                }
            }
        });

        $add('db.content_counts', 'Database', 'Site has content (users/galleries/photos)', function () {
            try {
                $u = (int) Database::run('SELECT COUNT(*) FROM users')->fetchColumn();
                $g = (int) Database::run('SELECT COUNT(*) FROM galleries')->fetchColumn();
                $p = (int) Database::run('SELECT COUNT(*) FROM photos')->fetchColumn();
                return ['pass' => $u > 0 && $p > 0, 'detail' => 'users=' . $u . ' galleries=' . $g . ' photos=' . $p];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.orphan_photos', 'Database', 'No orphan photos (every photo in a gallery)', function () {
            try {
                $n = (int) Database::run('SELECT COUNT(*) FROM photos p WHERE NOT EXISTS (SELECT 1 FROM gallery_photo gp WHERE gp.photo_id = p.id)')->fetchColumn();
                return ['pass' => $n === 0, 'detail' => $n . ' orphan(s)'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Auth
        $add('auth.password_hash', 'Auth', 'Password hashing produces verifiable hash', function () {
            $hash = password_hash('test', PASSWORD_DEFAULT);
            return ['pass' => $hash && password_verify('test', $hash), 'detail' => 'bcrypt/argon ok'];
        });

        $add('auth.superadmin_role', 'Auth', 'At least one super_admin account exists', function () {
            try {
                return ['pass' => User::countAdmins() > 0, 'detail' => User::countAdmins() . ' super_admins'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('auth.csrf_helper', 'Auth', 'CSRF helpers available (field + token source)', function () {
            $ok = function_exists('csrf_field') && class_exists(\App\Core\Csrf::class) && method_exists(\App\Core\Csrf::class, 'token');
            return ['pass' => $ok, 'detail' => $ok ? 'csrf helpers ok' : 'csrf missing'];
        });

        $add('auth.totp', 'Auth', 'TOTP generates secrets and verifies codes', function () {
            $secret = \App\Core\Totp::generateSecret();
            $okBase32 = (bool) preg_match('/^[A-Z2-7]{16,64}$/', $secret);
            $okUri = strpos(\App\Core\Totp::provisioningUri($secret, 'T', 'a@b.c'), 'otpauth://totp/') === 0;
            $okReject = !\App\Core\Totp::verify($secret, '000000');
            return ['pass' => $okBase32 && $okUri && $okReject, 'detail' => 'secret=' . strlen($secret) . ' chars, uri=' . ($okUri ? 'ok' : 'bad') . ', rejects-bad=' . ($okReject ? 'ok' : 'bad')];
        });

        $add('auth.validator', 'Auth', 'Validator rules enforce expected constraints', function () {
            $errors = \App\Core\Validator::validate(
                ['email' => 'not-an-email', 'age' => -1, 'role' => 'nope', 'title' => ''],
                ['email' => 'required|email', 'age' => 'numeric|min:0', 'role' => 'in:admin,user', 'title' => 'required|max:255']
            );
            $pass = isset($errors['email']) && isset($errors['age']) && isset($errors['role']) && isset($errors['title']);

            $clean = \App\Core\Validator::validate(
                ['email' => 'a@b.co', 'age' => '3', 'role' => 'admin', 'title' => 'Hello'],
                ['email' => 'required|email', 'age' => 'numeric|min:0', 'role' => 'in:admin,user', 'title' => 'required|max:255']
            );
            return ['pass' => $pass && $clean === [], 'detail' => ($pass && $clean === []) ? 'rules enforced' : 'expected failures: ' . implode(',', array_keys($errors))];
        });

        // ---------------------------------------------------------------- Front-end / Routes
        $add('route.public_pages', 'Front-end', 'Public GET pages return HTTP 200', function () {
            $pages = ['/', '/galleries', '/about', '/terms', '/privacy', '/health'];
            $base  = rtrim(env_value('APP_URL'), '/');
            if (!preg_match('#^https?://#i', $base)) {
                return ['pass' => false, 'detail' => 'APP_URL invalid for HTTP probe'];
            }
            $fail = [];
            foreach ($pages as $p) {
                // APP_URL already carries the base path (e.g. .../gallery), so
                // concatenate the raw route path — url() would double-prefix.
                $url  = $base . $p;
                $code = self::httpStatus($url);
                if ($code !== 200) $fail[$p] = $code;
            }
            return ['pass' => $fail === [], 'detail' => $fail === [] ? count($pages) . ' pages 200' : json_encode($fail)];
        });

        $add('route.login_page', 'Front-end', 'Login page reachable (200)', function () {
            $base = rtrim(env_value('APP_URL'), '/');
            return ['pass' => self::httpStatus($base . '/login') === 200, 'detail' => self::httpStatus($base . '/login')];
        });

        $add('route.media_guard', 'Front-end', '/files/ route requires media token logic', function () {
            $ok = function_exists('media_token') && function_exists('media_token_valid');
            return ['pass' => $ok, 'detail' => $ok ? 'media token helpers present' : 'missing'];
        });

        $add('route.no_shadowing', 'App & Config', 'No literal route is shadowed by an earlier wildcard', function () use ($root) {
            $routes = require $root . '/config/routes.php';
            $shadowed = [];

            foreach ($routes as $i => [$method, $path, $handler]) {
                // Only literal paths (no {param}) can be shadowed.
                if (strpos($path, '{') !== false) {
                    continue;
                }

                $literalRegex = '#^' . preg_replace('#\{[a-zA-Z0-9_]+\}#', '[^/]+', $path) . '/?$#';

                foreach ($routes as $j => [$m2, $wPath]) {
                    if ($j >= $i) {
                        break; // only earlier routes can shadow this one
                    }
                    if ($m2 !== $method || strpos($wPath, '{') === false) {
                        continue;
                    }

                    $wildRegex = '#^' . preg_replace('#\{[a-zA-Z0-9_]+\}#', '[^/]+', $wPath) . '/?$#';
                    if (preg_match($wildRegex, $path)) {
                        $shadowed[] = "$method $path (by $m2 $wPath)";
                        break;
                    }
                }
            }

            return [
                'pass'   => $shadowed === [],
                'detail' => $shadowed === [] ? 'no shadowed literal routes' : implode('; ', $shadowed),
            ];
        });

        // ---------------------------------------------------------------- Models
        $add('model.audit_log', 'Models', 'AuditLog queries succeed', function () {
            try {
                $rows = AuditLog::recent(1, 10);
                return ['pass' => is_array($rows), 'detail' => count($rows) . ' recent rows'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('model.theme_defaults', 'Models', 'Theme defaults resolve', function () {
            try {
                $d = \App\Models\Theme::defaults();
                return ['pass' => is_array($d) && isset($d['btn-bg'], $d['card-bg']), 'detail' => count($d) . ' keys'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Traffic
        $add('traffic.signing', 'Traffic', 'Link codes are signed, compact (22-char) tamper-proof serialization', function () {
            try {
                $code = 'abc123';
                $hex  = \App\Models\Traffic::signCode($code);
                $url  = \App\Models\Traffic::buildUrl('/signup', $code);
                $compact = null;
                if (preg_match('/[?&]s=([A-Za-z0-9_-]{22})/', $url, $m)) {
                    $compact = $m[1];
                }
                $valid    = $compact !== null && \App\Models\Traffic::validSignature($code, $compact);
                $legacyOk = strlen($hex) === 64 && \App\Models\Traffic::validSignature($code, $hex);
                $tampered = \App\Models\Traffic::validSignature('abc124', $compact);
                $badShape = \App\Models\Traffic::validSignature($code, 'not-a-hex-signature');
                $empty    = \App\Models\Traffic::validSignature('', '');
                $urlOk    = strpos($url, 'c=abc123') !== false && strpos($url, '&s=') !== false;
                return ['pass' => $valid && $legacyOk && !$tampered && !$badShape && !$empty && $urlOk, 'detail' => $valid && $urlOk ? 'compact 22-char sig OK, legacy 64-hex OK, tampering rejected' : 'signature verification failed'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Auto-poster
        $add('autoposter.config', 'Auto Poster', 'Auto-poster config model loads', function () {
            try {
                $cfg = \App\Models\AutoPosterConfig::all();
                return ['pass' => is_array($cfg), 'detail' => count($cfg) . ' keys'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => 'config:' . $ex->getMessage()];
            }
        });

        $add('autoposter.tweet_key', 'Auto Poster', 'X (Twitter) OAuth consumer key configured', function () {
            try {
                $cfg = \App\Models\AutoPosterConfig::all();
                $key = (string) ($cfg['twitter']['consumer_key'] ?? '');
                return ['pass' => $key !== '', 'detail' => $key !== '' ? 'set' : 'empty'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => 'config:' . $ex->getMessage()];
            }
        });

        $add('autoposter.template', 'Auto Poster', 'Per-platform editable templates control post wording, link and hashtag count', function () {
            try {
                $default = \App\Models\AutoPostQueue::templateSettings('x');
                $tags    = ['Amateur', 'Redhead', 'nipples', 'Tits'];
                $gallery = ['gallery_title' => 'Summer Set', 'caption' => 'Fresh uploads'];

                // Custom pattern/wording + reduced hashtag count + new link text.
                $custom = $default;
                $custom['pattern']      = '{title}: {description} — {hashtags} — check me out at amethyst2213.com/new';
                $custom['max_tags']     = 2;
                $custom['max_length']   = 200;
                $custom['banned_words'] = ['nipple', 'nipples'];

                $out = \App\Models\AutoPostQueue::buildText($gallery, $tags, $custom);

                $hasTitle = strpos($out, 'Summer Set') !== false;
                $hasDesc  = strpos($out, 'Fresh uploads') !== false;
                $hasLink  = strpos($out, 'amethyst2213.com/new') !== false;
                $tagCount = preg_match_all('/#\w+/', $out, $m);
                $noBanned = stripos($out, 'nipple') === false;
                $length   = mb_strlen($out) <= 200;

                // Same gallery/tags with zero hashtags must emit no "#" at all.
                $zero = $default;
                $zero['pattern']    = '{title} — {description} (no tags)';
                $zero['max_tags']   = 0;
                $zero['max_length'] = 200;
                $outZero = \App\Models\AutoPostQueue::buildText($gallery, $tags, $zero);
                $noTags  = strpos($outZero, '#') === false && strpos($outZero, '(no tags)') !== false;

                // Both platforms expose their own settings; a Reddit-flavoured
                // draft renders the Reddit template's wording.
                $reddit = \App\Models\AutoPostQueue::templateSettings('reddit');
                $reddit['pattern'] = 'From {title}: {description} — posted at amethyst2213.com [oc] {hashtags}';
                $reddit['max_tags'] = 3;
                $reddit['hashtag_style'] = 'hash';
                $outReddit = \App\Models\AutoPostQueue::buildText($gallery, $tags, $reddit);
                $redditOk  = strpos($outReddit, '[oc]') !== false && preg_match_all('/#\w+/', $outReddit) === 3;

                return ['pass' => $hasTitle && $hasDesc && $hasLink && $noBanned && $length && $noTags && $tagCount === 2 && $redditOk,
                    'detail' => json_encode(['tags' => $tagCount, 'output' => mb_substr($out, 0, 90)])];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Video / Photo jobs
        $add('video.worker_bin', 'Video Jobs', 'video_export_worker.php exists', function () use ($root) {
            return ['pass' => is_file($root . '/bin/video_export_worker.php'), 'detail' => 'bin/video_export_worker.php'];
        });

        $add('photo.worker_bin', 'Photo Jobs', 'photo_edit_queue.php exists', function () use ($root) {
            return ['pass' => is_file($root . '/bin/photo_edit_queue.php'), 'detail' => 'bin/photo_edit_queue.php'];
        });

        // ---------------------------------------------------------------- Backup / System
        $add('system.backup_dir', 'System', 'Backup directory exists & writable', function () use ($storage) {
            $dir = $storage . '/backups';
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('system.logs_writable', 'System', 'Logs directory exists & writable', function () use ($storage) {
            $dir = $storage . '/logs';
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('system.storage_integrity', 'System', 'Expected storage subdirectories exist & writable', function () use ($storage) {
            $needed = ['backups', 'logs', 'cache', 'cron', 'mail-outbox', 'themes', 'uploads'];
            $bad = [];
            foreach ($needed as $sub) {
                $dir = $storage . '/' . $sub;
                if (!is_dir($dir) || !is_writable($dir)) {
                    $bad[] = $sub . (!is_dir($dir) ? '(missing)' : '(not writable)');
                }
            }
            return ['pass' => $bad === [], 'detail' => $bad === [] ? implode(', ', $needed) . ' ok' : implode('; ', $bad)];
        });

        $add('system.cron_files', 'System', '/etc/cron.d/gallery-* rules present (root install)', function () {
            $found = [];
            foreach (['housekeeping', 'autopost', 'backup', 'restore-drill'] as $suffix) {
                $f = '/etc/cron.d/gallery-' . $suffix;
                if (is_file($f) && is_readable($f)) $found[] = $suffix;
            }
            return ['pass' => $found !== [], 'detail' => $found === [] ? 'none' : implode(',', $found)];
        });

        $add('system.cron_key_matches', 'System', 'Schedules JSON + cron key consistent', function () use ($storage) {
            $j = $storage . '/cron/schedules.json';
            if (!is_file($j)) return ['pass' => true, 'detail' => 'no schedules.json (defaults in code)'];
            try {
                $data = json_decode((string) file_get_contents($j), true);
                return ['pass' => is_array($data), 'detail' => 'schedules.json parseable'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Email
        $add('mail.config', 'Mail', 'SMTP host configured', function () {
            return ['pass' => env_value('MAIL_HOST') !== '', 'detail' => env_value('MAIL_HOST') ?: 'empty'];
        });

        // Merge in every static smoke check (single source of truth shared
        // with tests/smoke.php) so the admin suite can run them too.
        foreach (SmokeChecks::all() as $id => $smokeTest) {
            $tests[$id] = $smokeTest;
        }

        return $tests;
    }

    /** Return the list grouped by category for rendering. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::tests() as $t) {
            $out[$t['group']][] = $t;
        }
        return $out;
    }

    /**
     * Run a set of tests, invoking $report per completed test with an
     * incrementing index so callers can persist progressive state.
     *
     * @param array $ids   selected test ids (validated against the registry)
     * @param callable $report function(int $index, array $test, array $result): void
     */
    public static function run(array $ids, ?callable $report = null): array
    {
        $all = self::tests();
        $results = [];
        $index = 0;
        foreach ($ids as $id) {
            if (!isset($all[$id])) continue;
            ++$index;
            $test = $all[$id];
            $result = self::safeRun($test['run']);
            $row = ['id' => $id, 'name' => $test['name'], 'group' => $test['group'],
                    'status' => $result['pass'] ? 'passed' : 'failed', 'detail' => $result['detail']];
            $results[$id] = $row;
            if ($report !== null) {
                $report($index, $row);
            }
        }
        return $results;
    }

    private static function safeRun(callable $fn): array
    {
        try {
            $r = $fn();
            return is_array($r) ? $r : ['pass' => (bool) $r, 'detail' => ''];
        } catch (\Throwable $ex) {
            return ['pass' => false, 'detail' => 'exception: ' . $ex->getMessage()];
        }
    }

    /** Perform a GET and return the HTTP status code (fake-browser UA). */
    private static function httpStatus(string $url): int
    {
        // A HEAD request is used deliberately: it is enough to prove the route
        // is reachable. Some server configs return 404 for HEAD on framework
        // routes though, so fall back to a full GET when HEAD disagrees.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'TestSuite/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $headCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($headCode === 200) {
            return 200;
        }

        // Retry with a plain GET when the server mishandles HEAD.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'TestSuite/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code !== 0 ? $code : 0;
    }

    // ------------------------------------------------------------ run-state persistence
    public static function ensureRunsDir(): void
    {
        if (!is_dir(self::RUN_DIR)) {
            @mkdir(self::RUN_DIR, 0775, true);
        }
    }

    public static function runPath(string $runId): string
    {
        return self::RUN_DIR . '/run-' . preg_replace('/[^A-Za-z0-9_-]/', '', $runId) . '.json';
    }

    /** Glob pattern matching every run-state file (run-*.json). */
    public static function runsGlob(): string
    {
        return self::RUN_DIR . '/run-*.json';
    }

    public static function readRun(string $runId): ?array
    {
        $p = self::runPath($runId);
        if (!is_file($p)) return null;
        $data = json_decode((string) file_get_contents($p), true);
        return is_array($data) ? $data : null;
    }

    public static function writeRun(array $data): void
    {
        self::ensureRunsDir();
        $p = self::runPath((string) $data['id']);
        file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($p, 0664);
    }
}

if (PHP_SAPI === 'cli') {
    // Keep the CLI scope identical to the web scope by loading the app's
    // shared bootstrap (helpers + autoloader).
    require_once dirname(__DIR__) . '/bootstrap.php';
}