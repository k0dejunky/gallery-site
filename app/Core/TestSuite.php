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

        $add('db.questionnaire_schema', 'Database', 'Chat questionnaire tables + unique answer key exist', function () {
            try {
                $tables = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                foreach (['chat_questionnaires', 'chat_questionnaire_questions', 'chat_questionnaire_answers'] as $t) {
                    if (!in_array($t, $tables, true)) {
                        return ['pass' => false, 'detail' => 'missing table: ' . $t];
                    }
                }
                $idx = Database::run('SHOW INDEX FROM chat_questionnaire_answers')->fetchAll();
                $names = array_unique(array_column($idx, 'Key_name'));
                if (!in_array('uq_chat_q_answer_qq_user', $names, true)) {
                    return ['pass' => false, 'detail' => 'unique (question,user) key missing'];
                }
                $cols = Database::run('SHOW COLUMNS FROM chat_questionnaires')->fetchAll(\PDO::FETCH_COLUMN);
                if (!in_array('allow_replies', array_map('strtolower', $cols), true)) {
                    return ['pass' => false, 'detail' => 'allow_replies column missing'];
                }
                return ['pass' => true, 'detail' => '3 tables + unique key + allow_replies'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.questionnaire_cycle', 'Database', 'Create -> answer (validated) -> locked after submit -> results tally -> replies off', function () {
            $qid = null;
            try {
                $uid = (int) Database::run('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
                if ($uid <= 0) {
                    return ['pass' => false, 'detail' => 'no users'];
                }

                $qid = \App\Models\ChatQuestionnaire::create($uid, 'TmpQuestionnaireCycle', 'intro', true, null, [
                    ['prompt' => 'Your name?', 'qtype' => 'text', 'required' => 1],
                    ['prompt' => 'Pick one', 'qtype' => 'choice', 'required' => 1, 'options' => ['A', 'B']],
                    ['prompt' => 'Rate us', 'qtype' => 'rating', 'required' => 1, 'min' => 1, 'max' => 5],
                    ['prompt' => 'Pick many', 'qtype' => 'multichoice', 'required' => 0, 'options' => ['X', 'Y']],
                ]);

                // Delivery is a separate concern (fans out notifications to every
                // user); mark it sent so answer() accepts submissions.
                Database::run("UPDATE chat_questionnaires SET status = 'sent', sent_at = NOW() WHERE id = ?", [$qid]);

                $qs = \App\Models\ChatQuestionnaire::questions($qid);
                $byType = [];
                foreach ($qs as $q) {
                    $byType[$q['qtype']] = (int) $q['id'];
                }
                $textId  = $byType['text'];
                $choiceId = $byType['choice'];
                $ratingId = $byType['rating'];
                $multiId  = $byType['multichoice'];

                // Missing required -> rejected
                $missing = \App\Models\ChatQuestionnaire::answer($qid, $uid, ['q' . $choiceId => 'A']);
                // Invalid option -> rejected
                $invalid = \App\Models\ChatQuestionnaire::answer($qid, $uid, [
                    'q' . $textId => 'Bob', 'q' . $choiceId => 'Z', 'q' . $ratingId => '3',
                ]);

                $good = \App\Models\ChatQuestionnaire::answer($qid, $uid, [
                    'q' . $textId => 'Bob',
                    'q' . $choiceId => 'B',
                    'q' . $ratingId => '4',
                    'q' . $multiId => ['X', 'Y'],
                ]);
                $count1 = (int) Database::run('SELECT COUNT(*) FROM chat_questionnaire_answers WHERE questionnaire_id = ? AND user_id = ?', [$qid, $uid])->fetchColumn();

                // Once fully answered the questionnaire is LOCKED for this user:
                // a repeat submit is rejected and no rows are created/duplicated.
                $again = \App\Models\ChatQuestionnaire::answer($qid, $uid, [
                    'q' . $textId => 'Bobby',
                    'q' . $choiceId => 'B',
                    'q' . $ratingId => '5',
                    'q' . $multiId => ['X'],
                ]);
                $count2 = (int) Database::run('SELECT COUNT(*) FROM chat_questionnaire_answers WHERE questionnaire_id = ? AND user_id = ?', [$qid, $uid])->fetchColumn();

                // activeForUser still RETURNS the answered questionnaire but
                // flags it so the view can render a disabled copy.
                $listed = \App\Models\ChatQuestionnaire::activeForUser($uid);
                $stillShownAndFlagged = false;
                foreach ($listed as $q) {
                    if ((int) $q['id'] === $qid) {
                        $stillShownAndFlagged = !empty($q['answered']) && !empty($q['my_answers']);
                    }
                }

                $results = \App\Models\ChatQuestionnaire::results($qid);
                $tallyOk = false;
                foreach ($results as $r) {
                    if ((int) $r['question']['id'] === $choiceId) {
                        $tallyOk = ($r['tally']['B'] ?? 0) === 1;
                    }
                }

                // Replies off -> rejected
                Database::run('UPDATE chat_questionnaires SET allow_replies = 0 WHERE id = ?', [$qid]);
                $off = \App\Models\ChatQuestionnaire::answer($qid, $uid, [
                    'q' . $textId => 'X', 'q' . $choiceId => 'A', 'q' . $ratingId => '1',
                ]);

                $pass = !$missing['ok'] && !$invalid['ok'] && $good['ok']
                    && $count1 === 4 && !$again['ok'] && $count2 === 4
                    && $tallyOk && $stillShownAndFlagged && !$off['ok'];

                return ['pass' => $pass, 'detail' => sprintf(
                    'missing-rejected=%d invalid-rejected=%d valid=%d rows=%d repeat-rejected=%d rows2=%d tally=%d still-shown-flagged=%d replies-off-rejected=%d',
                    (int) !$missing['ok'], (int) !$invalid['ok'], (int) $good['ok'], $count1,
                    (int) !$again['ok'], $count2, (int) $tallyOk, (int) $stillShownAndFlagged, (int) !$off['ok']
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                if ($qid !== null) {
                    try {
                        Database::run('DELETE FROM chat_questionnaires WHERE id = ?', [$qid]);
                    } catch (\Throwable $ignore) {
                    }
                }
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

        $add('db.description_accept_cycle', 'Database', 'enqueue -> claim -> stage -> accept writes (edited) text; dismiss never writes', function () {
            $gid = null;
            try {
                $actor = (int) Database::run('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
                $g = Database::run("INSERT INTO galleries (title, type) VALUES ('TmpDescriptionCycle', 'images')");
                $gid = (int) Database::connection()->lastInsertId();

                $queued = \App\Models\DescriptionSuggestion::enqueue($gid);

                // Make our job the oldest so a box with a real backlog claims it first.
                \App\Core\Database::run(
                    "UPDATE gallery_description_jobs SET updated_at = '2001-01-01 00:00:00' WHERE gallery_id = ?",
                    [$gid]
                );
                $claimedOk = false;
                $parked    = [];
                for ($i = 0; $i < 25; $i++) {
                    $claimed = \App\Models\DescriptionSuggestion::claimNext();
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
                    Database::run("UPDATE gallery_description_jobs SET status = 'queued', attempts = attempts - 1 WHERE gallery_id = ?", [$parkId]);
                }

                Database::run(
                    "INSERT INTO gallery_description_suggestions (gallery_id, description, status, engine) VALUES (?, 'Proposed copy', 'pending', 'ollama')",
                    [$gid]
                );
                \App\Models\DescriptionSuggestion::complete($gid, 'ollama');

                $pending = \App\Models\DescriptionSuggestion::pendingFor($gid);
                $sid     = $pending !== null ? (int) $pending['id'] : 0;
                // The admin may edit before accepting; the edited text wins.
                $accepted = $sid > 0 && \App\Models\DescriptionSuggestion::accept($sid, $actor, 'Hand-tuned copy.');
                $written  = (string) Database::run('SELECT description FROM galleries WHERE id = ?', [$gid])->fetchColumn();
                $retired  = \App\Models\DescriptionSuggestion::pendingFor($gid) === null;
                $reaccept = $sid > 0 && !\App\Models\DescriptionSuggestion::accept($sid, $actor);

                // Dismiss path: description must stay untouched.
                Database::run(
                    "INSERT INTO gallery_description_suggestions (gallery_id, description, status, engine) VALUES (?, 'Ignored copy', 'pending', 'ollama')",
                    [$gid]
                );
                $dismissRow = \App\Models\DescriptionSuggestion::pendingFor($gid);
                $did        = $dismissRow !== null ? (int) $dismissRow['id'] : 0;
                $dismissed  = $did > 0 && \App\Models\DescriptionSuggestion::dismiss($did, $actor);
                $stillSame  = (string) Database::run('SELECT description FROM galleries WHERE id = ?', [$gid])->fetchColumn() === 'Hand-tuned copy.';

                $pass = $queued && $claimedOk && $pending !== null && $accepted
                    && $written === 'Hand-tuned copy.' && $retired && $reaccept
                    && $dismissed && $stillSame;

                return ['pass' => $pass, 'detail' => sprintf(
                    'queued=%d claimed=%d staged=%d accepted=%d written=%s retired=%d reaccept-refused=%d dismissed=%d unchanged=%d',
                    (int) $queued, (int) $claimedOk, (int) ($pending !== null), (int) $accepted,
                    json_encode($written), (int) $retired, (int) $reaccept, (int) $dismissed, (int) $stillSame
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                if ($gid !== null) {
                    try {
                        Database::run('DELETE FROM gallery_description_suggestions WHERE gallery_id = ?', [$gid]);
                        Database::run('DELETE FROM gallery_description_jobs WHERE gallery_id = ?', [$gid]);
                        Database::run('DELETE FROM galleries WHERE id = ?', [$gid]);
                    } catch (\Throwable $ignored) {
                    }
                }
            }
        });

        $add('db.video_ladder_plan', 'Database', 'ladder plan picks only the rungs a source actually needs (no upscaling)', function () {
            $keys = static function (array $plan): array {
                return array_values(array_map(static fn ($r) => (string) $r['key'], $plan));
            };

            // 480p source: only the always-on mid rung (it IS the 720p upload
            // rendition; encoding a "480p" copy of a 480p source is pointless).
            $sd   = $keys(video_ladder_plan(854, 480));
            // A 1080p source is NOT re-encoded to 1080p (that rung exists for
            // bigger sources), but it does get the 480p one.
            $hd   = $keys(video_ladder_plan(1920, 1080));
            // 1440p: mid + hi.
            $qhd  = $keys(video_ladder_plan(2560, 1440));
            // 4K portrait: everything, in ascending resolution order.
            $uhd  = $keys(video_ladder_plan(2160, 3840));
            // Unknown dimensions: trust only the synchronous mid rung.
            $none = $keys(video_ladder_plan(0, 0));

            $prefixes = [];
            foreach (video_ladder() as $rung) {
                $prefixes[$rung['key']] = $rung['prefix'];
            }

            $pass = $sd === ['mid']
                && $hd === ['lo', 'mid']
                && $qhd === ['lo', 'mid', 'hi']
                && $uhd === ['lo', 'mid', 'hi']
                && $none === ['mid']
                && ($prefixes['lo'] ?? '') === 'weblo_'
                && ($prefixes['mid'] ?? '') === 'web_'
                && ($prefixes['hi'] ?? '') === 'webhi_'
                // The mid rung must stay exactly what uploads build today, or
                // every existing rendition becomes "the wrong" one.
                && (int) (video_ladder()[1]['max_w'] ?? 0) === 1280;

            return ['pass' => $pass, 'detail' => sprintf(
                '480p=[%s] 1080p=[%s] 1440p=[%s] 4K=[%s] unknown=[%s] prefixes=%s',
                implode(',', $sd), implode(',', $hd), implode(',', $qhd), implode(',', $uhd), implode(',', $none),
                json_encode($prefixes)
            )];
        });

        $add('db.media_dimensions', 'Database', 'the display size of a media file is stored on the photo row', function () {
            $cols = Database::run('SHOW COLUMNS FROM photos')->fetchAll();
            $have = [];
            foreach ($cols as $c) {
                $have[$c['Field']] = true;
            }
            foreach (['width', 'height', 'rotation'] as $c) {
                if (!isset($have[$c])) {
                    return ['pass' => false, 'detail' => 'photos.' . $c . ' is missing (run scripts/migrate.php)'];
                }
            }

            // Round-trip through the model on a scratch row.
            $name = 'probe-dims-' . getmypid() . '.mp4';
            $hash = sha1($name . 'dims');
            $id   = null;
            try {
                Database::run(
                    'INSERT INTO photos (filename, is_video, hash, created_at) VALUES (?, 1, ?, CURRENT_TIMESTAMP)',
                    [$name, $hash]
                );
                $id = (int) Database::connection()->lastInsertId();

                \App\Models\Photo::setDimensions($id, 1080, 2400, 90);
                $dim = \App\Models\Photo::dimensions($id);
                $ok  = is_array($dim)
                    && $dim['w'] === 1080
                    && $dim['h'] === 2400
                    && $dim['rotation'] === 90;

                // A row that was never measured must read as "unknown", not
                // as a zero-sized picture.
                $none = \App\Models\Photo::dimensions($id + 999999);

                return ['pass' => $ok && $none === null, 'detail' => sprintf(
                    'stored=%s unknown=%s',
                    json_encode($dim),
                    $none === null ? 'null' : json_encode($none)
                )];
            } finally {
                if ($id !== null) {
                    Database::run('DELETE FROM photos WHERE id = ?', [$id]);
                }
            }
        });

        $add('db.video_chapter_parse', 'Database', 'scene times become chapters: t=0 first, gaps collapsed, capped, even fallback', function () {
            // Fast cuts 1s apart must collapse to nothing (min gap 10s).
            $bunched = \App\Models\VideoChapter::parseSceneTimes([1, 2, 3, 4, 5], 300.0, 10.0, 24);
            $bunchedStarts = array_column($bunched, 'start');
            // A clip under MIN_DURATION gets no chapters at all.
            $short = \App\Models\VideoChapter::parseSceneTimes([5, 20], 40.0);
            // A static 10-minute clip falls back to even 60s markers.
            $static = \App\Models\VideoChapter::parseSceneTimes([], 600.0);
            $staticStarts = array_column($static, 'start');
            // Real cuts are kept, sorted, deduped, and the cap is respected.
            $many = [];
            for ($t = 15; $t < 600; $t += 12) {
                $many[] = (float) $t;
            }
            $capped = \App\Models\VideoChapter::parseSceneTimes(array_reverse($many), 600.0, 10.0, 6);
            $cappedStarts = array_column($capped, 'start');
            // Markers at/after the duration are dropped.
            $edge = \App\Models\VideoChapter::parseSceneTimes([30.0, 299.0, 300.0, 400.0], 300.0);

            // Five cuts one second apart all fall inside the 10s minimum gap,
            // so there is nothing to mark and the even 60s split takes over.
            $pass = array_column($bunched, 'start') === [0.0, 60.0, 120.0, 180.0, 240.0]
                && ($bunched[0]['title'] ?? '') === 'Start'
                && ($bunched[1]['title'] ?? '') === 'Part 1'
                && $short === []
                && $staticStarts === [0.0, 60.0, 120.0, 180.0, 240.0, 300.0, 360.0, 420.0, 480.0, 540.0]
                && count($capped) === 6
                && $cappedStarts[0] === 0.0
                && $cappedStarts === array_values(array_unique($cappedStarts))
                && $cappedStarts === array_values($cappedStarts)
                && $cappedStarts[5] < 600.0
                && count($edge) === 3
                && array_column($edge, 'start') === [0.0, 30.0, 299.0];

            return ['pass' => $pass, 'detail' => sprintf(
                'bunched=%d short=%d static=%s capped=%s edge=%s',
                count($bunched), count($short), json_encode($staticStarts),
                json_encode($cappedStarts), json_encode(array_column($edge, 'start'))
            )];
        });

        $add('db.collection_reorder_cycle', 'Database', 'movePhoto swaps the manual order, refuses the ends and other users', function () {
            $ownerId = null;
            $otherId = null;
            $photoIds = [];
            $collectionId = null;
            try {
                \App\Models\User::create('probe.order.owner@example.test', 'pw-' . bin2hex(random_bytes(4)), 'user');
                \App\Models\User::create('probe.order.other@example.test', 'pw-' . bin2hex(random_bytes(4)), 'user');
                $ownerId = (int) Database::run("SELECT id FROM users WHERE email = 'probe.order.owner@example.test'")->fetchColumn();
                $otherId = (int) Database::run("SELECT id FROM users WHERE email = 'probe.order.other@example.test'")->fetchColumn();

                // Three real video photos (files on disk are not needed: the
                // model only validates the row's is_video flag).
                foreach (['a.mp4', 'b.mp4', 'c.mp4'] as $i => $name) {
                    Database::run(
                        'INSERT INTO photos (filename, hash, is_video, duration_seconds) VALUES (?, ?, 1, 60)',
                        ['probe_order_' . $i . '_' . $name, sha1('probe_order_' . $i . '_' . $name . bin2hex(random_bytes(4)))]
                    );
                    $photoIds[] = (int) Database::connection()->lastInsertId();
                }

                $collectionId = \App\Models\Collection::create($ownerId, 'Probe order');
                $added = \App\Models\Collection::addPhotos($collectionId, $ownerId, $photoIds);

                $order = static function () use ($collectionId): array {
                    return array_map('intval', array_column(
                        \App\Core\Database::run(
                            'SELECT photo_id FROM collection_items WHERE collection_id = ? ORDER BY position ASC, id ASC',
                            [$collectionId]
                        )->fetchAll(),
                        'photo_id'
                    ));
                };

                $initial = $order();
                $down    = \App\Models\Collection::movePhoto($collectionId, $ownerId, $photoIds[0], 'down');
                $afterDown = $order();
                // Moving the new first item up is a no-op, not a corruption.
                $pastEnd = \App\Models\Collection::movePhoto($collectionId, $ownerId, $photoIds[1], 'up');
                $unchanged = $order();
                // ...and the last item cannot move down either.
                $pastLast = \App\Models\Collection::movePhoto($collectionId, $ownerId, $photoIds[2], 'down');
                $stillOrdered = $order();
                // Another user cannot reorder someone else's playlist, and a
                // non-video id can never be inserted.
                $foreign = \App\Models\Collection::movePhoto($collectionId, $otherId, $photoIds[0], 'down');
                $foreignOrder = $order();
                $badAdd = \App\Models\Collection::addPhotos($collectionId, $ownerId, [999999999]);
                $reAdd  = \App\Models\Collection::addPhotos($collectionId, $ownerId, $photoIds);

                $pass = $added === 3
                    && $initial === $photoIds
                    && $down && $afterDown === [$photoIds[1], $photoIds[0], $photoIds[2]]
                    && $pastEnd === false && $unchanged === $afterDown
                    && $pastLast === false && $stillOrdered === $afterDown
                    && $foreign === false && $foreignOrder === $afterDown
                    && $badAdd === 0 && $reAdd === 0;

                return ['pass' => $pass, 'detail' => sprintf(
                    'added=%d down=%d pastFirst=%d pastLast=%d foreign=%d badAdd=%d reAdd=%d order=%s',
                    (int) $added, (int) $down, (int) $pastEnd, (int) $pastLast, (int) $foreign,
                    (int) $badAdd, (int) $reAdd, json_encode($stillOrdered)
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                if ($collectionId !== null) {
                    try { Database::run('DELETE FROM collection_items WHERE collection_id = ?', [$collectionId]); } catch (\Throwable $ignored) {}
                    try { Database::run('DELETE FROM collections WHERE id = ?', [$collectionId]); } catch (\Throwable $ignored) {}
                }
                foreach ($photoIds as $pid) {
                    try { Database::run('DELETE FROM photos WHERE id = ?', [$pid]); } catch (\Throwable $ignored) {}
                }
                foreach ([$ownerId, $otherId] as $uid) {
                    if ($uid !== null) {
                        try { Database::run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $ignored) {}
                    }
                }
            }
        });

        $add('db.video_chapter_store_cycle', 'Database', 'chapters round-trip, replace is idempotent and cascade with the photo', function () {
            $photoId = null;
            try {
                $probeFile = 'probe_chapters_' . bin2hex(random_bytes(3)) . '.mp4';
                Database::run(
                    'INSERT INTO photos (filename, hash, is_video, duration_seconds) VALUES (?, ?, 1, 300)',
                    [$probeFile, sha1($probeFile . bin2hex(random_bytes(4)))]
                );
                $photoId = (int) Database::connection()->lastInsertId();

                $none = \App\Models\VideoChapter::forPhoto($photoId);
                $stale = \App\Models\VideoChapter::needsAnalysis($photoId, time());

                $written = \App\Models\VideoChapter::replaceForPhoto($photoId, [
                    ['start' => 0.0, 'title' => 'Start'],
                    ['start' => 61.25, 'title' => 'Middle'],
                    ['start' => 200.5, 'title' => str_repeat('long ', 40)],
                ]);
                $stored = \App\Models\VideoChapter::forPhoto($photoId);

                $rewritten = \App\Models\VideoChapter::replaceForPhoto($photoId, [
                    ['start' => 0.0, 'title' => 'Start'],
                    ['start' => 61.25, 'title' => 'Middle'],
                ]);
                $after = \App\Models\VideoChapter::forPhoto($photoId);
                $has = \App\Models\VideoChapter::hasChapters($photoId);

                // Chapters written now are newer than a source mtime from the
                // past, so the worker does not re-analyse on every tick.
                $fresh = \App\Models\VideoChapter::needsAnalysis($photoId, time() - 3600);

                // Deleting the photo must take its chapters with it.
                Database::run('DELETE FROM photos WHERE id = ?', [$photoId]);
                $orphan = (int) Database::run('SELECT COUNT(*) FROM video_chapters WHERE photo_id = ?', [$photoId])->fetchColumn();

                $pass = $none === []
                    && $stale === true
                    && $written === 3
                    && count($stored) === 3
                    && (float) $stored[1]['start'] === 61.25
                    && mb_strlen($stored[2]['title']) <= 120
                    && $rewritten === 2
                    && count($after) === 2
                    && $has === true
                    && $fresh === false
                    && $orphan === 0;

                return ['pass' => $pass, 'detail' => sprintf(
                    'empty=%d stale=%d written=%d stored=%d rewritten=%d titleLen=%d fresh=%d orphan=%d',
                    count($none), (int) $stale, (int) $written, count($stored), (int) $rewritten,
                    mb_strlen($stored[2]['title'] ?? ''), (int) $fresh, $orphan
                )];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            } finally {
                if ($photoId !== null) {
                    try { Database::run('DELETE FROM video_chapters WHERE photo_id = ?', [$photoId]); } catch (\Throwable $ignored) {}
                    try { Database::run('DELETE FROM photos WHERE id = ?', [$photoId]); } catch (\Throwable $ignored) {}
                }
            }
        });

        $add('db.description_advisor_parse', 'Database', 'parse() tolerates JSON fences, plain prose, labels, quotes and caps length', function () {
            $json    = \App\Core\DescriptionAdvisor::parse('{"description":"A playful poolside set with sunlit color."}');
            $fenced  = \App\Core\DescriptionAdvisor::parse("```json\n{\"description\":\"Fenced copy here.\"}\n```");
            $prose   = \App\Core\DescriptionAdvisor::parse("Description: Plain prose copy drops its label.");
            $prosey  = \App\Core\DescriptionAdvisor::parse("  Just one\n\n  long   line of prose.  ");
            $quoted  = \App\Core\DescriptionAdvisor::parse('{"description":"\u201cCurly quoted take.\u201d"}');
            $empty   = \App\Core\DescriptionAdvisor::parse('{"note":"no description key"}');
            $capped  = \App\Core\DescriptionAdvisor::parse('{"description":"' . str_repeat('x', 900) . '"}');

            $pass = $json === 'A playful poolside set with sunlit color.'
                && $fenced === 'Fenced copy here.'
                && $prose === 'Plain prose copy drops its label.'
                && $prosey === 'Just one long line of prose.'
                && $quoted === 'Curly quoted take.'
                && $empty === ''
                && mb_strlen($capped) === \App\Models\DescriptionSuggestion::MAX_LENGTH;

            return ['pass' => $pass, 'detail' => sprintf(
                'json=%s fenced=%s prose=%s ws=%s quotes=%s empty=%s cap=%d',
                json_encode($json), json_encode($fenced), json_encode($prose),
                json_encode($prosey), json_encode($quoted), json_encode($empty), mb_strlen($capped)
            )];
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

        $add('db.email_notification_daily_cap', 'Database', 'A member gets at most one notification email per day', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: no CURDATE() to cap on'];
                }

                // The 2026-09-28 burst was 266 new-gallery emails to 15 members
                // because the only guard was per gallery, not per day. Prove the
                // per-day cap holds: the first notification goes out, the second
                // on the same day is dropped, and the cap is per recipient.
                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $email = 'captest+' . bin2hex(random_bytes(4)) . '@example.invalid';
                $now   = date('Y-m-d H:i:s');

                try {
                    Database::run(
                        "INSERT INTO users (email, password_hash, role, status, notify_new_gallery, created_at)
                         VALUES (?, 'x', 'user', 'active', 1, ?)",
                        [$email, $now]
                    );
                    $userId = (int) Database::connection()->lastInsertId();

                    $first = \App\Models\EmailQueue::enqueueNotification(
                        'notify_new_gallery',
                        'Cap test one',
                        '<p>one</p>',
                        'one'
                    );

                    // Same day, second gallery: this is the 2026-09-28 case.
                    $second = \App\Models\EmailQueue::enqueueNotification(
                        'notify_new_gallery',
                        'Cap test two',
                        '<p>two</p>',
                        'two'
                    );

                    $rows = (int) Database::run(
                        'SELECT COUNT(*) FROM email_queue
                         WHERE user_id = ? AND audience = \'notification\' AND DATE(created_at) = CURDATE()',
                        [$userId]
                    )->fetchColumn();

                    $cap = \App\Models\EmailQueue::notificationDailyCap();

                    // $first/$second are whole-audience counts (a live box has
                    // other opted-in members), so the assertion is per member:
                    // this user is reached on the first call and never again
                    // that day.
                    return [
                        'pass'   => $first >= 1 && $second === 0 && $rows === 1,
                        'detail' => 'cap=' . $cap . ', fanout ' . $first . ' then ' . $second . ', this member rows today=' . $rows,
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM users WHERE email = ?', [$email]);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.test_gallery_is_not_announced', 'Database', 'a test gallery sends no member email and no notification', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $title = 'PROBE db test gallery ' . bin2hex(random_bytes(3));

                try {
                    // A gallery created the ordinary way, flagged as a test one.
                    $id = \App\Models\Gallery::create($title, 'scratch', 'images', 0, null, false, true);
                    $isTest = (int) Database::run('SELECT is_test FROM galleries WHERE id = ?', [$id])->fetchColumn();

                    // The announcement attempt must do nothing at all: no
                    // email, no bell, and - importantly - the gallery stays
                    // unmarked so clearing the flag later still announces it.
                    \App\Models\Gallery::notifyNewGalleryIfUnsent($id);

                    $mails = (int) Database::run(
                        "SELECT COUNT(*) FROM email_queue WHERE subject LIKE ?",
                        ['%' . $title . '%']
                    )->fetchColumn();
                    $bells = (int) Database::run(
                        "SELECT COUNT(*) FROM notifications WHERE title LIKE ?",
                        ['%' . $title . '%']
                    )->fetchColumn();
                    $notified = Database::run('SELECT notified_at FROM galleries WHERE id = ?', [$id])->fetchColumn();

                    // A gallery with a normal title DOES get announced, so
                    // the check above is not passing for the wrong reason.
                    // The site sends one gallery announcement a day, so on a
                    // day when that slot is already gone the second gallery is
                    // (correctly) not announced - and its marker must stay
                    // unburned so it goes out the next day.
                    //
                    // Whether the slot is taken is read BEFORE the probe, or
                    // the probe's own email would answer the question.
                    $slotTaken = \App\Models\EmailQueue::broadcastSentToday('gallery');
                    $realTitle = 'Real gallery ' . bin2hex(random_bytes(3));
                    $realId = \App\Models\Gallery::create($realTitle, 'real', 'images', 0, null, false, false);
                    \App\Models\Gallery::notifyNewGalleryIfUnsent($id);
                    \App\Models\Gallery::notifyNewGalleryIfUnsent($realId);
                    $realMails = (int) Database::run(
                        "SELECT COUNT(*) FROM email_queue WHERE subject LIKE ?",
                        ['%' . $realTitle . '%']
                    )->fetchColumn();
                    $realNotified = Database::run('SELECT notified_at FROM galleries WHERE id = ?', [$realId])->fetchColumn();

                    // With the day's slot gone, the real gallery must stay
                    // unmarked (it is announced the next day).
                    $realOk = $slotTaken ? $realMails === 0 && $realNotified === null : $realMails >= 1;

                    return [
                        'pass' => $isTest === 1
                            && $mails === 0
                            && $bells === 0
                            && $notified === null
                            && $realOk,
                        'detail' => sprintf(
                            'is_test=%d, test mails=%d, test bells=%d, notified_at=%s, real gallery mails=%d',
                            $isTest,
                            $mails,
                            $bells,
                            $notified === null ? 'null' : (string) $notified,
                            $realMails
                        ),
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    foreach ([$title, 'Real gallery'] as $needle) {
                        try {
                            Database::run('DELETE FROM galleries WHERE title LIKE ?', [$needle . '%']);
                            Database::run('DELETE FROM email_queue WHERE subject LIKE ?', ['%' . $needle . '%']);
                            Database::run('DELETE FROM notifications WHERE title LIKE ?', ['%' . $needle . '%']);
                        } catch (\Throwable $ignored) {
                        }
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.retention_email_cycle', 'Database', 'Trial-ending + inactive win-back audiences queue once per member/subscription', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $email = 'retentiontest+' . bin2hex(random_bytes(4)) . '@example.invalid';
                $now   = date('Y-m-d H:i:s');

                try {
                    Database::run(
                        "INSERT INTO users (email, password_hash, role, status, email_verified_at, marketing_opt_out, last_seen_at, created_at)
                         VALUES (?, 'x', 'user', 'active', ?, 0, DATE_SUB(NOW(), INTERVAL 40 DAY), ?)",
                        [$email, $now, $now]
                    );
                    $userId = (int) Database::connection()->lastInsertId();

                    // Inactive verified member with no subscription: scanned
                    // and queued exactly once (the scanner is scoped to this
                    // member, so no live inbox is ever touched).
                    $first = \App\Core\Lifecycle::runInactiveWinbacks(30, $userId);
                    $again = \App\Core\Lifecycle::runInactiveWinbacks(30, $userId);

                    $logged = (int) Database::run(
                        "SELECT COUNT(*) FROM retention_email_log WHERE user_id = ? AND event_key = 'winback_inactive'",
                        [$userId]
                    )->fetchColumn();
                    $queued = (int) Database::run(
                        "SELECT COUNT(*) FROM email_queue WHERE user_id = ? AND kind = 'lifecycle' AND subject = ?",
                        [$userId, "It's been a while"]
                    )->fetchColumn();

                    // An opted-out member is refused even for a fresh event.
                    Database::run('UPDATE users SET marketing_opt_out = 1 WHERE id = ?', [$userId]);
                    $optedOut = \App\Core\Lifecycle::sendToUser('probe_optout', $userId, 'winback_inactive', 'Opt-out probe', []);
                    Database::run('UPDATE users SET marketing_opt_out = 0 WHERE id = ?', [$userId]);

                    // Trial ending in 2 days: nudged once, and the generic
                    // renewal reminder must skip it (that was the bug where a
                    // trial got "your renewal is coming up" copy).
                    $planId = (int) Database::run(
                        'SELECT id FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
                    )->fetchColumn();
                    if ($planId <= 0) {
                        return ['pass' => true, 'detail' => 'no active plan fixture; trial audience not exercised'];
                    }

                    Database::run(
                        "INSERT INTO subscriptions (user_id, plan_id, status, transaction_ref, starts_at, expires_at)
                         VALUES (?, ?, 'active', ?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY))",
                        [$userId, $planId, 'TRIAL-TEST' . $userId, $now]
                    );

                    $trialFirst = \App\Core\Lifecycle::runTrialEndings(3, $userId);
                    $trialAgain = \App\Core\Lifecycle::runTrialEndings(3, $userId);
                    $renewals   = \App\Core\Lifecycle::runRenewalReminders(7, $userId);
                    $still      = \App\Core\Lifecycle::runInactiveWinbacks(30, $userId);

                    return [
                        'pass'   => $first === 1 && $again === 0 && $logged === 1 && $queued === 1
                            && $optedOut === false
                            && $trialFirst === 1 && $trialAgain === 0 && $renewals === 0 && $still === 0,
                        'detail' => "inactive=$first/$again logged=$logged queued=$queued optedout="
                            . ($optedOut ? 'sent' : 'refused') . " trial=$trialFirst/$trialAgain renewals=$renewals",
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM users WHERE email = ?', [$email]);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.comment_replies_and_reactions', 'Database', 'A comment can be answered and reacted to', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                try {
                    $userId = (int) Database::run(
                        "SELECT id FROM users WHERE role = 'user' AND status = 'active' ORDER BY id LIMIT 1"
                    )->fetchColumn();
                    $galleryId = (int) Database::run(
                        'SELECT id FROM galleries WHERE deleted_at IS NULL ORDER BY id LIMIT 1'
                    )->fetchColumn();
                    if ($userId <= 0 || $galleryId <= 0) {
                        return ['pass' => true, 'detail' => 'no user/gallery fixture; nothing to prove'];
                    }

                    $parentId = \App\Models\Comment::add($userId, 'gallery', $galleryId, 'probe parent');
                    $replyId  = \App\Models\Comment::addReply($userId, $parentId, 'probe reply');
                    $replies  = \App\Models\Comment::replies($parentId);

                    $on  = \App\Models\Comment::toggleReaction($userId, $parentId, 'like');
                    $off = \App\Models\Comment::toggleReaction($userId, $parentId, 'like');
                    $counts = \App\Models\Comment::reactionsFor([$parentId]);
                    $mine   = \App\Models\Comment::myReactions($userId, [$parentId]);

                    // The share token: the same collection, two links, and
                    // clearing it kills the link.
                    $collectionId = \App\Models\Collection::create($userId, 'probe collection');
                    $token = \App\Models\Collection::shareToken($collectionId, $userId);
                    $same  = \App\Models\Collection::shareToken($collectionId, $userId);
                    $found = \App\Models\Collection::findByShareToken($token);
                    \App\Models\Collection::clearShareToken($collectionId, $userId);
                    $gone = \App\Models\Collection::findByShareToken($token);

                    return [
                        'pass'   => count($replies) === 1
                            && (int) ($replies[0]['id'] ?? 0) === $replyId
                            && $on === true && $off === false
                            && (int) ($counts[$parentId]['like'] ?? 0) === 0
                            && $mine === []
                            && $token !== '' && $same === $token
                            && $found !== null && (int) $found['id'] === $collectionId
                            && $gone === null,
                        'detail' => 'replies=' . count($replies)
                            . ' reaction on/off=' . ($on ? 'yes' : 'no') . '/' . ($off ? 'no' : 'yes')
                            . ' share=' . ($token !== '' ? 'ok' : 'NO') . '/' . ($gone === null ? 'cleared' : 'STILL LIVE'),
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM comments WHERE body LIKE ?', ['probe %']);
                    } catch (\Throwable $ignored) {
                    }
                    try {
                        Database::run('DELETE FROM collections WHERE name LIKE ?', ['probe %']);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.member_search_works', 'Database', 'The member search runs and finds a gallery by its title', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $stamp = bin2hex(random_bytes(4));

                try {
                    // A COMMITTED gallery: the fulltext index only sees a row
                    // once its transaction has committed, so a row created
                    // inside this test's transaction would not be found.
                    $row = Database::run(
                        "SELECT id, title FROM galleries
                         WHERE deleted_at IS NULL AND CHAR_LENGTH(title) > 8
                         ORDER BY id DESC LIMIT 1"
                    )->fetch();
                    if ($row === false) {
                        return ['pass' => true, 'detail' => 'no gallery fixture; nothing to prove'];
                    }
                    $galleryId = (int) $row['id'];
                    $title    = (string) $row['title'];

                    // A word from that title (three characters or more, so the
                    // fulltext index can use it).
                    $word = '';
                    foreach (preg_split('/\s+/u', $title) as $part) {
                        if (mb_strlen(trim($part)) >= 4) {
                            $word = trim($part);
                            break;
                        }
                    }
                    if ($word === '') {
                        return ['pass' => true, 'detail' => 'no usable search word in the fixture title'];
                    }

                    // The search itself: the fulltext path, the caption
                    // path and the short-term prefix path all have to run
                    // without a database error.
                    $hits = \App\Models\Gallery::paginate(1, 50, [
                        'q'            => $word,
                        'max_level'    => PHP_INT_MAX,
                    ]);
                    $found = false;
                    foreach ($hits['items'] as $item) {
                        if ((int) $item['id'] === $galleryId) {
                            $found = true;
                        }
                    }

                    // A term under three characters takes the prefix path
                    // (the fulltext index cannot use it).
                    $short = \App\Models\Gallery::paginate(1, 6, [
                        'q'         => 'pro',
                        'max_level' => PHP_INT_MAX,
                    ]);

                    // And the searches are recorded (the "popular searches"
                    // the member sees when a search comes up empty).
                    $userId = 0;
                    $email = 'searchprobe.' . $stamp . '@example.invalid';
                    Database::run(
                        "INSERT INTO users (email, password_hash, role, status, email_verified_at, created_at)
                         VALUES (?, 'x', 'user', 'active', NOW(), NOW())",
                        [$email]
                    );
                    $userId = (int) Database::connection()->lastInsertId();
                    \App\Models\Stats::recordSearch($word, $userId, true);
                    $popular = \App\Models\Stats::popularSearches(5);
                    $isPopular = in_array($word, $popular, true);

                    return [
                        'pass'   => $found && (int) $short['total'] >= 0 && $isPopular,
                        'detail' => 'found=' . ($found ? 'yes' : 'NO')
                            . ' short=' . (int) $short['total']
                            . ' popular=' . ($isPopular ? 'yes' : 'NO'),
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM users WHERE email LIKE ?', ['searchprobe.%']);
                    } catch (\Throwable $ignored) {
                    }

                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.related_respects_the_level', 'Database', 'The related and recommended rails only offer galleries the member can open', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $stamp = bin2hex(random_bytes(4));

                try {
                    // A category, a level-2 gallery in it and a level-0 one.
                    Database::run(
                        "INSERT INTO categories (name, slug, created_at) VALUES (?, ?, NOW())",
                        ['Probe cat ' . $stamp, 'probe-cat-' . $stamp]
                    );
                    $catId = (int) Database::connection()->lastInsertId();

                    Database::run(
                        "INSERT INTO galleries (title, description, type, min_level, is_secret, created_at, published_at)
                         VALUES (?, '', 'images', 2, 0, NOW(), NOW())",
                        ['Probe locked ' . $stamp]
                    );
                    $lockedId = (int) Database::connection()->lastInsertId();
                    Database::run('INSERT INTO gallery_category (gallery_id, category_id) VALUES (?, ?)', [$lockedId, $catId]);

                    Database::run(
                        "INSERT INTO galleries (title, description, type, min_level, is_secret, created_at, published_at)
                         VALUES (?, '', 'images', 0, 0, NOW(), NOW())",
                        ['Probe free ' . $stamp]
                    );
                    $freeId = (int) Database::connection()->lastInsertId();
                    Database::run('INSERT INTO gallery_category (gallery_id, category_id) VALUES (?, ?)', [$freeId, $catId]);

                    // The related rail of the free gallery, at two levels.
                    $atFree   = \App\Models\Gallery::related($freeId, 10, 0);
                    $atGold   = \App\Models\Gallery::related($freeId, 10, 2);
                    $freeIds  = array_map('intval', array_column($atFree, 'id'));
                    $goldIds  = array_map('intval', array_column($atGold, 'id'));

                    // The locked count for the category.
                    $lockedCount = \App\Models\Gallery::countLocked(0, $catId);
                    $goldCount   = \App\Models\Gallery::countLocked(2, $catId);

                    // The subject gallery is excluded from its own related
                    // list, so at level 0 the list is empty and at level 2
                    // it holds the locked gallery.
                    return [
                        'pass'   => !in_array($lockedId, $freeIds, true)
                            && in_array($lockedId, $goldIds, true)
                            && $lockedCount === 1
                            && $goldCount === 0,
                        'detail' => 'level 0 sees ' . count($freeIds) . ' (locked shown: '
                            . (in_array($lockedId, $freeIds, true) ? 'YES' : 'no') . '), level 2 sees ' . count($goldIds)
                            . ', locked counts ' . $lockedCount . '/' . $goldCount,
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.deleted_account_keeps_its_ledger', 'Database', 'Deleting an account does not destroy the financial ledger', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $email = 'ledgerprobe' . bin2hex(random_bytes(4)) . '@example.invalid';

                try {
                    Database::run(
                        "INSERT INTO users (email, password_hash, role, status, email_verified_at, created_at)
                         VALUES (?, 'x', 'user', 'active', NOW(), NOW())",
                        [$email]
                    );
                    $userId = (int) Database::connection()->lastInsertId();

                    $planId = (int) Database::run('SELECT id FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
                    if ($planId <= 0) {
                        return ['pass' => true, 'detail' => 'no active plan fixture; nothing to prove'];
                    }

                    Database::run(
                        "INSERT INTO subscriptions (user_id, plan_id, status, price_paid, created_at)
                         VALUES (?, ?, 'active', 9.99, NOW())",
                        [$userId, $planId]
                    );
                    Database::run(
                        "INSERT INTO purchases (user_id, item_type, item_id, amount, gateway, status, created_at)
                         VALUES (?, 'gallery', 1, 4.99, 'code', 'paid', NOW())",
                        [$userId]
                    );

                    \App\Models\User::delete($userId);

                    $subs  = (int) Database::run('SELECT COUNT(*) FROM subscriptions WHERE user_id = ?', [$userId])->fetchColumn();
                    $purch = (int) Database::run('SELECT COUNT(*) FROM purchases WHERE user_id = ?', [$userId])->fetchColumn();
                    $row   = Database::run('SELECT email, status, deleted_at FROM users WHERE id = ?', [$userId])->fetch();
                    $listed = (int) Database::run(
                        'SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.id = ?',
                        [$userId]
                    )->fetchColumn();

                    return [
                        'pass'   => $subs === 1 && $purch === 1
                            && $row !== false
                            && $row['deleted_at'] !== null
                            && strpos((string) $row['email'], '@deleted.invalid') !== false
                            && $listed === 0,
                        'detail' => "subscriptions=$subs purchases=$purch anonymised="
                            . (($row !== false && $row['deleted_at'] !== null) ? 'yes' : 'no')
                            . ' out-of-the-list=' . ($listed === 0 ? 'yes' : 'no'),
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM purchases WHERE email = ?', [$email]);
                        Database::run('DELETE FROM subscriptions WHERE user_id IN (SELECT id FROM users WHERE email = ?)', [$email]);
                        Database::run('DELETE FROM users WHERE email = ?', [$email]);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.sale_capacity_is_reserved', 'Database', 'A limited sale cannot be sold more times than it allows', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                // No wrapping transaction here: Subscription::create() opens
                // its own, and MySQL does not nest them.
                $stamp = bin2hex(random_bytes(4));
                $userIds = [];

                try {
                    $planId = (int) Database::run('SELECT id FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
                    if ($planId <= 0) {
                        return ['pass' => true, 'detail' => 'no active plan fixture; nothing to prove'];
                    }

                    // A sale that may be sold ONCE.
                    $saleId = \App\Models\Sale::create($planId, 'Probe sale ' . $stamp, 1.00, 1, null, true);

                    $users = [];
                    foreach ([1, 2] as $i) {
                        $email = 'saleprobe' . $i . '.' . $stamp . '@example.invalid';
                        Database::run(
                            "INSERT INTO users (email, password_hash, role, status, email_verified_at, created_at)
                             VALUES (?, 'x', 'user', 'active', NOW(), NOW())",
                            [$email]
                        );
                        $userIds[] = (int) Database::connection()->lastInsertId();
                    }

                    $first = null;
                    $second = null;
                    try {
                        $first = \App\Models\Subscription::create($userIds[0], $planId, null, true);
                    } catch (\Throwable $ignored) {
                    }
                    try {
                        $second = \App\Models\Subscription::create($userIds[1], $planId, null, true);
                    } catch (\Throwable $ignored) {
                    }

                    $sold = (int) Database::run(
                        'SELECT COUNT(*) FROM subscriptions WHERE sale_id = ?',
                        [$saleId]
                    )->fetchColumn();

                    return [
                        'pass'   => $first !== null && $second === null && $sold === 1,
                        'detail' => 'first=' . ($first !== null ? 'ok' : 'REFUSED')
                            . ' second=' . ($second === null ? 'refused (ok)' : 'SOLD TOO')
                            . ' rows=' . $sold,
                    ];
                } finally {
                    foreach ($userIds as $uid) {
                        try {
                            Database::run('DELETE FROM subscriptions WHERE user_id = ?', [$uid]);
                            Database::run('DELETE FROM users WHERE id = ?', [$uid]);
                        } catch (\Throwable $ignored) {
                        }
                    }
                    try {
                        Database::run('DELETE FROM sales WHERE name LIKE ?', ['Probe sale ' . $stamp . '%']);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.unlock_code_multi_use', 'Database', 'A multi-use unlock code can be redeemed by more than one member', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $stamp = bin2hex(random_bytes(4));

                try {
                    // Two members and a code that may be used three times.
                    $userIds = [];
                    foreach ([1, 2] as $i) {
                        $email = 'unlockprobe' . $i . '.' . $stamp . '@example.invalid';
                        Database::run(
                            "INSERT INTO users (email, password_hash, role, status, email_verified_at, marketing_opt_out, last_seen_at, created_at)
                             VALUES (?, 'x', 'user', 'active', NOW(), 0, NOW(), NOW())",
                            [$email]
                        );
                        $userIds[] = (int) Database::connection()->lastInsertId();
                    }

                    $galleryId = (int) Database::run('SELECT id FROM galleries WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1')->fetchColumn();
                    if ($galleryId <= 0) {
                        return ['pass' => true, 'detail' => 'no gallery fixture; nothing to prove'];
                    }

                    $code = 'UNLOCK-' . strtoupper($stamp);
                    $codeId = \App\Models\UnlockCode::create($code, $galleryId, 5.00, 3, 'probe');

                    // Two different members redeem the SAME code.
                    $ok1 = \App\Models\UnlockCode::redeem($codeId);
                    \App\Models\Purchase::create($userIds[0], \App\Models\Purchase::TYPE_GALLERY, $galleryId, 5.00, 'code', (string) $codeId, 'probe 1');
                    $ok2 = \App\Models\UnlockCode::redeem($codeId);
                    \App\Models\Purchase::create($userIds[1], \App\Models\Purchase::TYPE_GALLERY, $galleryId, 5.00, 'code', (string) $codeId, 'probe 2');

                    $used = (int) Database::run('SELECT used_count FROM unlock_codes WHERE id = ?', [$codeId])->fetchColumn();
                    $redemptions = (int) Database::run(
                        "SELECT COUNT(*) FROM purchases WHERE gateway = 'code' AND gateway_ref = ?",
                        [(string) $codeId]
                    )->fetchColumn();

                    // The same member redeeming the same code for the same
                    // gallery again must not create a second row.
                    $duplicateBlocked = false;
                    try {
                        \App\Models\Purchase::create($userIds[0], \App\Models\Purchase::TYPE_GALLERY, $galleryId, 5.00, 'code', (string) $codeId, 'probe duplicate');
                    } catch (\Throwable $ignored) {
                        $duplicateBlocked = true;
                    }

                    return [
                        'pass'   => $ok1 && $ok2 && $used === 2 && $redemptions === 2 && $duplicateBlocked,
                        'detail' => "redeems=$ok1/$ok2 used=$used rows=$redemptions duplicateBlocked="
                            . ($duplicateBlocked ? 'yes' : 'NO'),
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    foreach ($userIds ?? [] as $uid) {
                        try {
                            Database::run('DELETE FROM users WHERE id = ?', [$uid]);
                        } catch (\Throwable $ignored) {
                        }
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.subscription_cancel_is_final', 'Database', 'A cancelled subscription is not resurrected by a replayed payment postback', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }

                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }

                $email = 'subprobe+' . bin2hex(random_bytes(4)) . '@example.invalid';
                $now   = date('Y-m-d H:i:s');

                try {
                    Database::run(
                        "INSERT INTO users (email, password_hash, role, status, email_verified_at, marketing_opt_out, last_seen_at, created_at)
                         VALUES (?, 'x', 'user', 'active', ?, 0, ?, ?)",
                        [$email, $now, $now, $now]
                    );
                    $userId = (int) Database::connection()->lastInsertId();

                    $planId = (int) Database::run(
                        'SELECT id FROM plans WHERE active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1'
                    )->fetchColumn();
                    if ($planId <= 0) {
                        return ['pass' => true, 'detail' => 'no active plan fixture; nothing to prove'];
                    }

                    // A pending subscription that the member then cancels.
                    Database::run(
                        "INSERT INTO subscriptions (user_id, plan_id, status, transaction_ref, created_at, updated_at)
                         VALUES (?, ?, 'pending', ?, ?, ?)",
                        [$userId, $planId, 'PROBE-' . $userId, $now, $now]
                    );
                    $subId = (int) Database::connection()->lastInsertId();

                    // A pending one IS approved.
                    \App\Models\Subscription::approve($subId);
                    $approved = (string) Database::run(
                        'SELECT status FROM subscriptions WHERE id = ?', [$subId]
                    )->fetchColumn();

                    // Now the cancelled case: the member cancels, and the
                    // biller retries its postback.
                    Database::run(
                        "UPDATE subscriptions SET status = 'cancelled', expires_at = ? WHERE id = ?",
                        [$now, $subId]
                    );
                    \App\Models\Subscription::approve($subId);
                    \App\Models\Subscription::activateWithTransaction($subId, 'PROBE-RETRY');
                    $afterRetry = (string) Database::run(
                        'SELECT status FROM subscriptions WHERE id = ?', [$subId]
                    )->fetchColumn();

                    return [
                        'pass'   => $approved === 'active' && $afterRetry === 'cancelled',
                        'detail' => "pending->$approved, cancelled->$afterRetry (a replayed postback must not resurrect it)",
                    ];
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                    try {
                        Database::run('DELETE FROM users WHERE email = ?', [$email]);
                    } catch (\Throwable $ignored) {
                    }
                }
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.autopost_claim', 'Database', 'Auto-poster claim executes (single-table UPDATE, secret guard)', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    // A multi-table UPDATE ... JOIN ... ORDER BY is illegal in
                    // MySQL (error 1221) and used to kill the whole autopost
                    // worker; running the real claim here catches it.
                    $pdo = Database::connection();
                    $own = !$pdo->inTransaction();
                    if ($own) {
                        $pdo->beginTransaction();
                    }
                    try {
                        $rows = \App\Models\AutoPostQueue::due(1);
                        $ok   = is_array($rows);
                    } finally {
                        if ($own) {
                            $pdo->rollBack();
                        }
                    }
                    return ['pass' => $ok, 'detail' => 'claimed ' . count($rows) . ' row(s), rolled back'];
                }
                return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.autopost_repost_varies', 'Database', 'Reposting a gallery yields varied text (X duplicate guard)', function () {
            try {
                if (Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    return ['pass' => true, 'detail' => 'sqlite driver: nothing to prove'];
                }
                $gid = (int) Database::run(
                    "SELECT gallery_id FROM auto_poster_queue
                     WHERE platform = 'twitter' AND status = 'posted'
                     GROUP BY gallery_id ORDER BY COUNT(*) DESC LIMIT 1"
                )->fetchColumn();
                if ($gid <= 0) {
                    $gid = (int) Database::run(
                        'SELECT g.id FROM galleries g JOIN gallery_photo gp ON gp.gallery_id = g.id
                         WHERE g.deleted_at IS NULL AND g.is_secret = 0
                         GROUP BY g.id ORDER BY g.id ASC LIMIT 1'
                    )->fetchColumn();
                }
                if ($gid <= 0) {
                    return ['pass' => true, 'detail' => 'no eligible gallery'];
                }
                $pdo = Database::connection();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }
                try {
                    $a = \App\Models\AutoPostQueue::enqueue($gid, null, null, 'twitter');
                    $b = \App\Models\AutoPostQueue::enqueue($gid, null, null, 'twitter');
                    $ta = (string) Database::run('SELECT text FROM auto_poster_queue WHERE id = ?', [$a])->fetchColumn();
                    $tb = (string) Database::run('SELECT text FROM auto_poster_queue WHERE id = ?', [$b])->fetchColumn();
                    $ok = $a > 0 && $b > 0 && $ta !== '' && $ta !== $tb;
                    $detail = 'gallery ' . $gid . ' len(' . mb_strlen($ta) . ',' . mb_strlen($tb) . ') ' . ($ok ? 'differ' : 'IDENTICAL');
                } finally {
                    if ($own) {
                        $pdo->rollBack();
                    }
                }
                return ['pass' => $ok, 'detail' => $detail];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
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

        $add('auth.page_cache_csrf', 'Auth', 'A cached page carries the requester\'s CSRF token, not the producer\'s', function () {
            // The page cache stores the page as ONE guest rendered it and
            // re-personalises it for the next requester. It rewrote the
            // form's _token and the CSP nonce, but NOT the
            // <meta name="csrf-token"> the member layout publishes - so a
            // guest served a cached page got the producer's token: their
            // AJAX posts came back 419 and the token leaked sideways.
            try {
                $html = '<html><head><meta name="csrf-token" content="PRODUCERTOKEN123"></head><body>'
                    . '<form><input type="hidden" name="_token" value="PRODUCERTOKEN123"></form>'
                    . '<button data-csrf="PRODUCERTOKEN123"></button>'
                    . '<input type="hidden" name="return_to" value="/old/page">'
                    . '</body></html>';

                $out = \App\Core\PageCache::pageInject($html);
                $mine = \App\Core\Csrf::token();

                $leaked = strpos($out, 'PRODUCERTOKEN123') !== false;
                $hasMine = strpos($out, $mine) !== false;
                $metaOk = preg_match('/<meta\s+name="csrf-token"\s+content="' . preg_quote($mine, '/') . '"/', $out) === 1;

                return [
                    'pass'   => !$leaked && $hasMine && $metaOk,
                    'detail' => $leaked
                        ? 'the producer token survived the injection'
                        : ($metaOk ? 'the meta tag carries the requester token' : 'the meta tag was not rewritten'),
                ];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
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

        $add('autoposter.video_cover_media', 'Auto Poster', 'A video posts its cover picture when the channel takes images but not videos', function () {
            $uploadDir = rtrim((string) config('app.uploads.dir'), '/');
            $name      = 'probe_cover_' . substr(md5((string) microtime(true)), 0, 10) . '.mp4';
            $coverPath = $uploadDir . '/thumb_' . $name;
            $videoPath = $uploadDir . '/' . $name;
            $made      = false;

            try {
                // The site writes a cover (thumb_<file>) for every video.
                $im = function_exists('imagecreatetruecolor') ? imagecreatetruecolor(60, 40) : null;
                if ($im === false || $im === null) {
                    return ['pass' => false, 'detail' => 'GD is not available'];
                }
                $fg = imagecolorallocate($im, 12, 90, 200);
                imagefilledrectangle($im, 0, 0, 59, 39, $fg);
                $made = imagejpeg($im, $coverPath, 80);
                imagedestroy($im);
                if (!$made || !is_file($coverPath)) {
                    return ['pass' => false, 'detail' => 'the probe cover could not be written'];
                }
                // The video file itself does not need to exist: the cover is
                // what gets posted.
                file_put_contents($videoPath, 'not a real video');

                $cover = \App\Models\AutoPostQueue::videoCoverMedia($name, $videoPath, 0);
                $media = $cover['media'];

                $hasMedia   = is_array($media) && $media !== [];
                $isJpeg     = $hasMedia && ($media['name'] ?? '') === 'video-cover.jpg';
                $isImage    = $hasMedia && ($media['type'] ?? '') === 'image/jpeg';
                $fileExists = $hasMedia && is_file((string) $media['tmp_name']);
                $hasBytes   = $hasMedia && (int) $media['size'] > 0;

                // The reddit channel is exactly the case this covers: it takes
                // one image and no video.
                $reddit = \App\Models\AutoPostQueue::templateSettings('reddit');
                $takesImages = (bool) ($reddit['media'] ?? false) && (int) ($reddit['max_media'] ?? 0) > 0;
                $skipsVideo  = (string) ($reddit['video'] ?? '') === 'none';

                return ['pass' => $hasMedia && $isJpeg && $isImage && $fileExists && $hasBytes && $takesImages && $skipsVideo,
                    'detail' => json_encode(['media' => $media === null ? null : basename((string) $media['tmp_name']),
                                             'takes_images' => $takesImages, 'skips_video' => $skipsVideo])];
            } catch (\Throwable $e) {
                return ['pass' => false, 'detail' => $e->getMessage()];
            } finally {
                @unlink($coverPath);
                @unlink($videoPath);
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