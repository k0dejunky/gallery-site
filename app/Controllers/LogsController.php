<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Theme;
use App\Models\User;

class LogsController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('logs');
    }

    public function index(): void
    {
        $q = trim((string) $this->request->query('q', ''));
        $action = trim((string) $this->request->query('action', ''));
        $entityType = trim((string) $this->request->query('entity', ''));

        if ($q !== '' || $action !== '' || $entityType !== '') {
            $paginator = AuditLog::search((int) $this->request->query('page', 1), 30, $q, $action, $entityType);
            $facets = AuditLog::facets();
        } else {
            $paginator = AuditLog::recent((int) $this->request->query('page', 1));
            $facets = AuditLog::facets();
        }
        $categoryNames = [];

        foreach (Category::all() as $category) {
            $categoryNames[(int) $category['id']] = (string) $category['name'];
        }

        foreach ($paginator['items'] as &$log) {
            $log['changes'] = AuditLog::diff(
                (string) $log['entity_type'],
                $log['before_json'],
                $log['after_json'],
                $categoryNames
            );
        }
        unset($log);

        // Pending subscription requests awaiting admin approval.
        $pendingSubs = \App\Core\Database::run(
            'SELECT s.*, u.email AS user_email, p.name AS plan_name, p.billing_cycle, p.price
             FROM subscriptions s
             JOIN users u ON u.id = s.user_id
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status = ?
             ORDER BY s.created_at ASC',
            ['pending']
        )->fetchAll();

        // Deleted galleries not yet restored or purged.
        $pendingDeletes = \App\Core\Database::run(
            'SELECT l.*, u.email AS admin_email
             FROM admin_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.action = ? AND l.entity_type = ? AND l.rolled_back_at IS NULL
             ORDER BY l.created_at DESC',
            ['delete', 'gallery']
        )->fetchAll();

        // Pending category promotions from missed searches.
        $pendingApprovals = \App\Models\Stats::pendingCategoryApprovals(5);

        $this->viewAdmin('logs', [
            'paginator'          => $paginator,
            'pendingSubs'        => $pendingSubs,
            'pendingDeletes'     => $pendingDeletes,
            'pendingApprovals'   => $pendingApprovals,
            'facets'             => $facets,
            'filterQ'            => $q,
            'filterAction'       => $action,
            'filterEntity'       => $entityType,
        ]);
    }

    public function errorIndex(): void
    {
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = 50;

        $errors = [];
        $failedExports = Database::run(
            'SELECT e.id, e.error, e.created_at, e.finished_at, p.title AS project_title
             FROM video_export_jobs e
             JOIN video_projects p ON p.id = e.project_id
             WHERE e.status = ?
             ORDER BY e.id DESC LIMIT 200',
            ['failed']
        )->fetchAll();
        foreach ($failedExports as $failure) {
            $errors[] = [
                'source' => 'Video export #' . (int) $failure['id'],
                'time' => (string) ($failure['finished_at'] ?: $failure['created_at'] ?: ''),
                'message' => '[' . (string) ($failure['project_title'] ?: 'Untitled project') . '] ' . (string) ($failure['error'] ?: 'Export failed without an error message.'),
            ];
        }

        $sources = [
            'Apache/PHP' => ['/var/log/apache2/error.log'],
            'PHP' => glob('/var/log/php*.log') ?: [],
            'MySQL' => ['/var/log/mysql/error.log', '/var/log/mysql/mysql.log'],
            'Application' => glob(dirname(__DIR__, 2) . '/storage/logs/*.log') ?: [],
        ];
        foreach ($sources as $label => $files) {
            foreach (array_unique($files) as $file) {
                if (!is_string($file) || !is_readable($file)) continue;
                $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!is_array($lines)) continue;
                foreach (array_reverse(array_slice($lines, -100)) as $line) {
                    $line = substr((string) $line, 0, 4000);
                    $errors[] = [
                        'source' => $label . ' (' . basename($file) . ')',
                        'time' => self::logTimestamp($line),
                        'message' => $line,
                    ];
                }
            }
        }
        foreach ($errors as $i => &$error) {
            $error['__i'] = $i;
        }
        unset($error);

        usort($errors, static function (array $a, array $b): int {
            $ta = (string) ($a['time'] ?? '');
            $tb = (string) ($b['time'] ?? '');
            // Entries with timestamps come first, sorted newest first
            if ($ta !== '' && $tb !== '') {
                return strcmp($tb, $ta); // newer first
            }
            if ($ta !== '') return -1; // a has time, b doesn't -> a first
            if ($tb !== '') return 1;  // b has time, a doesn't -> b first
            // Both have no timestamp: keep original (newest-first) order.
            return $b['__i'] <=> $a['__i'];
        });

        $total = count($errors);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;
        $paginatedErrors = array_slice($errors, $offset, $perPage);

        $this->viewAdmin('error_logs', [
            'errors'    => $paginatedErrors,
            'paginator' => [
                'items'   => $paginatedErrors,
                'total'   => $total,
                'page'    => $page,
                'pages'   => $pages,
                'perPage' => $perPage,
            ],
        ]);
    }

/**
     * Pull a sortable timestamp out of a raw log line so the error log can
     * be ordered newest-first even for files whose lines have no parsed time
     * field. Supports ISO-8601 (Apache/MySQL) and Apache's [day Mon d HH:MM:SS]
     * bracket format. Returns an empty string when no timestamp is found.
     */
    private static function logTimestamp(string $line): string
    {
        $line = trim($line);

        if (preg_match('/(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $line, $m)) {
            return $m[1] . ' ' . $m[2];
        }

        if (preg_match('/^\[[A-Z][a-z]{2} ([A-Z][a-z]{2}) +(\d{1,2}) (\d{2}:\d{2}:\d{2})\.\d+ (\d{4})\]/', $line, $m)) {
            // Apache default error log: [Fri Aug 21 16:36:52.445756 2026]
            $month = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
                      'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'][$m[1]] ?? '';
            if ($month !== '') {
                return $m[4] . '-' . $month . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . ' ' . $m[3];
            }
        }

        if (preg_match('/^\[(\d{1,2})-([A-Z][a-z]{2})-(\d{4}) (\d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            // PHP-FPM default: [21-Aug-2026 20:22:49]
            $month = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
                      'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'][$m[2]] ?? '';
            if ($month !== '') {
                return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . ' ' . $m[4];
            }
        }

        return '';
    }

    public function rollback(int $id): void
    {
        $log  = AuditLog::find($id);
        $user = Auth::user();

        if ($log === null || $user === null) {
            $this->notFound();
            return;
        }

        if ($log['rolled_back_at'] !== null) {
            $this->flash('error', 'This action has already been rolled back.');
            $this->redirect('/admin/logs');
            return;
        }

        $action   = $log['action'];
        $type     = $log['entity_type'];
        $entityId = (int) ($log['entity_id'] ?? 0);
        $userId   = (int) $user['id'];

        // ---------- CREATE actions: undo = delete the entity ----------

        if ($action === 'create') {
            $this->undoCreate($type, $entityId, $log, $userId);
            return;
        }

        // ---------- UPDATE actions: undo = restore before_json ----------

        if ($action === 'update') {
            $this->undoUpdate($type, $entityId, $log, $userId);
            return;
        }

        // ---------- DELETE actions: undo = recreate from before_json ----------

        if ($action === 'delete') {
            $this->undoDelete($type, $entityId, $log, $userId);
            return;
        }

        $this->flash('error', 'This action cannot be rolled back.');
        $this->redirect('/admin/logs');
    }

    /**
     * Permanently purge a soft-deleted gallery from the logs page.
     */
    public function purgeGallery(int $id): void
    {
        $log  = AuditLog::find($id);
        $user = Auth::user();

        if ($log === null || $user === null) {
            $this->notFound();
            return;
        }

        if ($log['entity_type'] !== 'gallery' || $log['rolled_back_at'] !== null) {
            $this->flash('error', 'This action cannot be purged.');
            $this->redirect('/admin/logs');
        }

        $gallery = Gallery::findIncludingDeleted((int) $log['entity_id']);

        if ($gallery === null) {
            $this->flash('error', 'This gallery no longer exists.');
            $this->redirect('/admin/logs');
        }

        Gallery::delete((int) $gallery['id']);
        AuditLog::markRolledBack($id, (int) $user['id']);
        AuditLog::record((int) $user['id'], 'purge', 'gallery', (int) $gallery['id'], 'Permanently deleted gallery "' . $gallery['title'] . '"');

        $this->flash('success', 'Gallery permanently deleted.');
        $this->redirect('/admin/logs');
    }

    // -----------------------------------------------------------------
    //  Undo create: delete the entity that was created
    // -----------------------------------------------------------------

    private function undoCreate(string $type, int $entityId, array $log, int $userId): void
    {
        switch ($type) {
            case 'gallery':
                $gallery = Gallery::find($entityId);
                if ($gallery === null) {
                    $this->flash('error', 'Gallery no longer exists.');
                    $this->redirect('/admin/logs');
                    return;
                }
                AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — soft-deleted gallery "' . $gallery['title'] . '"');
                Gallery::softDelete($entityId);
                AuditLog::markRolledBack((int) $log['id'], $userId);
                $this->flash('success', 'Gallery soft-deleted to undo creation.');
                break;

            case 'category':
                $cat = Category::find($entityId);
                if ($cat === null) {
                    $this->flash('error', 'Category no longer exists.');
                    $this->redirect('/admin/logs');
                    return;
                }
                AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — deleted category "' . $cat['name'] . '"');
                Category::delete($entityId);
                AuditLog::markRolledBack((int) $log['id'], $userId);
                $this->flash('success', 'Category deleted to undo creation.');
                break;

            case 'plan':
                $plan = Plan::find($entityId);
                if ($plan === null) {
                    $this->flash('error', 'Plan no longer exists.');
                    $this->redirect('/admin/logs');
                    return;
                }
                AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — deleted plan "' . $plan['name'] . '"');
                Plan::delete($entityId);
                AuditLog::markRolledBack((int) $log['id'], $userId);
                $this->flash('success', 'Plan deleted to undo creation.');
                break;

            case 'user':
                $u = User::find($entityId);
                if ($u === null) {
                    $this->flash('error', 'User no longer exists.');
                    $this->redirect('/admin/logs');
                    return;
                }
                AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — deleted user "' . $u['email'] . '"');
                User::delete($entityId);
                AuditLog::markRolledBack((int) $log['id'], $userId);
                $this->flash('success', 'User deleted to undo creation.');
                break;

            case 'subscription':
                $sub = Subscription::find($entityId);
                if ($sub === null) {
                    $this->flash('error', 'Subscription no longer exists.');
                    $this->redirect('/admin/logs');
                    return;
                }
                AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — deleted subscription');
                Subscription::delete($entityId);
                AuditLog::markRolledBack((int) $log['id'], $userId);
                $this->flash('success', 'Subscription deleted to undo creation.');
                break;

            default:
                $this->flash('error', 'Rollback not supported for this action.');
                $this->redirect('/admin/logs');
                return;
        }

        $this->redirect('/admin/logs');
    }

    // -----------------------------------------------------------------
    //  Undo update: restore before_json values
    // -----------------------------------------------------------------

    private function undoUpdate(string $type, int $entityId, array $log, int $userId): void
    {
        $before = json_decode($log['before_json'], true);

        if (!is_array($before)) {
            $this->flash('error', 'No previous state available to restore.');
            $this->redirect('/admin/logs');
            return;
        }

        $ok = false;

        switch ($type) {
            case 'gallery':
                if (isset($before['title'], $before['description'], $before['type'])) {
                    Gallery::update($entityId, (string) $before['title'], (string) $before['description'], (string) $before['type']);
                    if (isset($before['categories'])) {
                        Gallery::setCategories($entityId, (array) $before['categories']);
                    }
                    $ok = true;
                }
                break;

            case 'category':
                if (isset($before['name'])) {
                    Category::update($entityId, (string) $before['name']);
                    $ok = true;
                }
                break;

            case 'photo':
                if (isset($before['caption'], $before['link'])) {
                    Photo::updateCaption($entityId, (string) $before['caption'], (string) $before['link']);
                    $ok = true;
                }
                break;

            case 'plan':
                if (isset($before['name'], $before['cycle'], $before['price'])) {
                    Plan::update(
                        $entityId,
                        (string) $before['name'],
                        (string) $before['cycle'],
                        (float) $before['price'],
                        (string) ($before['description'] ?? ''),
                        (int) ($before['sort_order'] ?? 0),
                        (int) ($before['level'] ?? Plan::SILVER_LEVEL),
                        (bool) ($before['active'] ?? false)
                    );
                    $ok = true;
                }
                break;

            case 'theme':
                if (isset($before['values']) && is_array($before['values'])) {
                    Theme::save($before['values'], (string) ($before['scope'] ?? Theme::SCOPE_SITE));
                    $ok = true;
                }
                break;

            case 'subscription':
                if (isset($before['status']) && $entityId > 0) {
                    $sub = Subscription::find($entityId);
                    if ($sub !== null) {
                        $newStatus = (string) $before['status'];
                        Database::run(
                            'UPDATE subscriptions SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                            [$newStatus, $entityId]
                        );
                        $ok = true;
                    }
                }
                break;
        }

        if ($ok) {
            AuditLog::markRolledBack((int) $log['id'], $userId);
            AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id']);
            $this->flash('success', 'The change was rolled back.');
        } else {
            $this->flash('error', 'The selected change could not be restored.');
        }

        $this->redirect('/admin/logs');
    }

    // -----------------------------------------------------------------
    //  Undo delete: recreate from before_json
    // -----------------------------------------------------------------

    private function undoDelete(string $type, int $entityId, array $log, int $userId): void
    {
        // Special case: gallery soft-delete → restore
        if ($type === 'gallery') {
            $this->restoreDeletedGallery((int) $log['id'], $entityId, $userId);
            return;
        }

        $before = json_decode($log['before_json'], true);

        if (!is_array($before)) {
            $this->flash('error', 'No data available to recreate the deleted entity.');
            $this->redirect('/admin/logs');
            return;
        }

        $ok = false;
        $newId = null;

        switch ($type) {
            case 'category':
                if (isset($before['name'])) {
                    $newId = Category::create((string) $before['name']);
                    $ok = true;
                }
                break;

            case 'plan':
                if (isset($before['name'], $before['cycle'], $before['price'])) {
                    $newId = Plan::create(
                        (string) $before['name'],
                        (string) $before['cycle'],
                        (float) $before['price'],
                        (string) ($before['description'] ?? ''),
                        (int) ($before['sort_order'] ?? 0),
                        (int) ($before['level'] ?? Plan::SILVER_LEVEL),
                        (bool) ($before['active'] ?? false)
                    );
                    $ok = true;
                }
                break;

            case 'subscription':
                if (isset($before['user_id'], $before['plan_id'])) {
                    $newId = Subscription::create((int) $before['user_id'], (int) $before['plan_id']);
                    if (isset($before['status']) && $before['status'] === 'active') {
                        Subscription::approve($newId);
                    }
                    $ok = true;
                }
                break;

            case 'user':
                if (isset($before['email'], $before['role'])) {
                    $tempPassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
                    User::create((string) $before['email'], $tempPassword, (string) $before['role']);
                    $ok = true;
                }
                break;
        }

        if ($ok) {
            AuditLog::markRolledBack((int) $log['id'], $userId);
            AuditLog::record($userId, 'rollback', $type, $entityId, 'Rolled back log #' . $log['id'] . ' — recreated ' . $type);
            $msg = 'The deleted ' . $type . ' was recreated.';
            if ($type === 'user') {
                $msg .= ' A temporary password was set — the user must reset it.';
            }
            $this->flash('success', $msg);
        } else {
            $this->flash('error', 'The deleted entity could not be recreated.');
        }

        $this->redirect('/admin/logs');
    }

    private function restoreDeletedGallery(int $logId, int $galleryId, int $userId): void
    {
        $gallery = Gallery::findIncludingDeleted($galleryId);

        if ($gallery === null) {
            $this->flash('error', 'This gallery no longer exists and cannot be restored.');
            $this->redirect('/admin/logs');
        }

        if ($gallery['deleted_at'] === null) {
            $this->flash('error', 'This gallery has already been restored.');
            $this->redirect('/admin/logs');
        }

        Gallery::restore($galleryId);
        AuditLog::markRolledBack($logId, $userId);
        AuditLog::record($userId, 'restore', 'gallery', $galleryId, 'Restored gallery "' . $gallery['title'] . '"', null, ['title' => $gallery['title']]);

        $this->flash('success', 'Gallery restored.');
        $this->redirect('/admin/logs');
    }
}
