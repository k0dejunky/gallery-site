<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Database;

/**
 * CSV exports for off-platform reporting. Streams straight to the browser
 * with sane filenames; both endpoints are admin-only.
 */
class ExportController extends Controller
{
    public function users(): void
    {
        Auth::requirePermission('users');

        $rows = Database::run(
            'SELECT id, email, role, status, created_at, last_login_at
             FROM users ORDER BY id'
        )->fetchAll();

        $this->csv('users-' . date('Ymd-His') . '.csv', $rows);
    }

    public function subscriptions(): void
    {
        Auth::requirePermission('membership');

        $rows = Database::run(
            'SELECT s.id, u.email, p.name AS plan, p.billing_cycle, p.price,
                    s.status, s.transaction_ref, pp.name AS processor,
                    s.starts_at, s.created_at, s.expires_at
             FROM subscriptions s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN plans p ON p.id = s.plan_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             ORDER BY s.id'
        )->fetchAll();

        $this->csv('subscriptions-' . date('Ymd-His') . '.csv', $rows);
    }

    /**
     * Galleries as CSV (for backup/audit or spreadsheet editing). Photos are
     * not included — media is managed through the normal upload UI.
     */
    public function galleries(): void
    {
        Auth::requirePermission('galleries');

        $rows = Database::run(
            "SELECT g.id, g.title, g.description, g.type, g.min_level, g.is_secret,
                    g.published_at, g.created_at, g.views,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ' | ') AS categories,
                    (SELECT COUNT(*) FROM gallery_photo gp WHERE gp.gallery_id = g.id) AS photo_count
             FROM galleries g
             LEFT JOIN gallery_category gc ON gc.gallery_id = g.id
             LEFT JOIN categories c ON c.id = gc.category_id
             WHERE g.deleted_at IS NULL
             GROUP BY g.id
             ORDER BY g.id"
        )->fetchAll();

        $this->csv('galleries-' . date('Ymd-His') . '.csv', $rows);
    }

    private function csv(string $filename, array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
        } else {
            fputcsv($out, ['no data']);
        }

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => (string) $v, $row));
        }

        fclose($out);
        exit;
    }
}
