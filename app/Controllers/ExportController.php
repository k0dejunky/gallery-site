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
