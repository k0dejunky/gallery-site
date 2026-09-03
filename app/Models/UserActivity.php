<?php

namespace App\Models;

use App\Core\Database;

/**
 * Data access for the user-activity feed that backs the admin "User Monitor"
 * tab. One row per discrete event: a member logging in, logging out, or
 * opening a gallery. Kept independent of admin_logs so per-user behaviour is
 * easy to inspect without cluttering the admin audit trail.
 */
class UserActivity
{
    public const ACTION_LOGIN  = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_VIEW   = 'gallery_view';

    /**
     * Append one activity event. Gallery views carry the gallery id/name;
     * login and logout events just identify the user. Failures are swallowed
     * (logged) so a recording problem never blocks the actual request.
     */
    public static function record(int $userId, string $action, ?int $galleryId = null, ?string $galleryName = null, ?string $ip = null): void
    {
        try {
            Database::run(
                'INSERT INTO user_activity (user_id, action, gallery_id, gallery_name, ip)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, $action, $galleryId, $galleryName, $ip]
            );
        } catch (\Throwable $error) {
            error_log('[user_activity] record failed: ' . $error->getMessage());
        }
    }

    /**
     * Paginated activity listing with optional filters: free-text search
     * across user email/gallery name, exact action, and a specific user.
     * A per-activity clip of the last 30 rows for the page is also returned
     * via $recentUsers so the view can show a quick "now" summary.
     */
    public static function search(
        int $page = 1,
        int $perPage = 50,
        string $q = '',
        string $action = '',
        int $userId = 0
    ): array {
        $page   = max(1, $page);
        $where  = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(u.email LIKE ? OR a.gallery_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like);
        }
        if ($action !== '') {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($userId > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $userId;
        }

        $from = 'FROM user_activity a LEFT JOIN users u ON u.id = a.user_id';
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $total = (int) Database::run('SELECT COUNT(*) ' . $from . $whereSql, $params)->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $items = Database::run(
            'SELECT a.*, u.email AS user_email ' . $from . $whereSql .
            ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        )->fetchAll();

        return compact('items', 'total', 'page', 'pages', 'perPage');
    }

    /**
     * Distinct values for the filter dropdowns.
     */
    public static function facets(): array
    {
        return [
            'actions' => Database::run('SELECT DISTINCT action FROM user_activity ORDER BY action')->fetchAll(\PDO::FETCH_COLUMN),
        ];
    }

    /**
     * The most recent distinct activity row per user (used for the profile
     * header on the monitor page, e.g. "last seen" snapshot).
     */
    public static function lastSeenByUser(int $limit = 25): array
    {
        return Database::run(
            'SELECT a.*, u.email AS user_email
             FROM user_activity a
             JOIN users u ON u.id = a.user_id
             WHERE a.id IN (
                 SELECT MAX(x.id) FROM user_activity x GROUP BY x.user_id
             )
             ORDER BY a.id DESC LIMIT ' . max(1, $limit)
        )->fetchAll();
    }
}
