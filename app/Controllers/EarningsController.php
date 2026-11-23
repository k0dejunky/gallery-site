<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Models\Purchase;
use App\Models\Subscription;

/**
 * Admin: creator earnings dashboard (single-creator brand). Shows recurring
 * (subscription) revenue and one-off revenue (PPV unlocks + tips) for a date
 * window, with a per-month series and a CSV export. Everything is a read over
 * the subscriptions and purchases ledgers; no payout processing ships yet.
 */
class EarningsController extends MembershipAdminController
{
    public function index(): void
    {
        [$from, $to] = $this->window();

        if ($this->request->query('export') === 'csv') {
            $this->csv($from, $to);
            return;
        }

        $oneOff      = Purchase::totalsBetween($from, $to);
        $series      = Purchase::seriesBetween($from, $to);
        // The owner's definition (Nov 17 2026): subscription revenue IS the
        // monthly value of the active paid memberships - monthly plans only,
        // real member accounts only. It is a snapshot, not a cash-in-window
        // figure, so both cards show the same number.
        $mrr         = $this->mrr();
        $subRevenue  = $mrr;
        $charges     = $this->chargeRows($from, $to, 20);
        $recent      = Purchase::recent(50);

        $this->viewAdmin('earnings', [
            'from'        => substr($from, 0, 10),
            'to'          => substr($to, 0, 10),
            'oneOff'      => $oneOff,
            'series'      => $series,
            'subRevenue'  => $subRevenue,
            'mrr'         => $mrr,
            'charges'     => $charges,
            'recent'      => $recent,
        ]);
    }

    private function csv(string $from, string $to): void
    {
        $rows = Database::run(
            "SELECT p.id, p.item_type, p.item_id, g.title, p.amount, p.currency, p.gateway,
                    p.gateway_ref, p.status, u.email, p.created_at
             FROM purchases p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN galleries g ON g.id = p.item_id AND p.item_type = 'gallery'
             WHERE p.created_at >= ? AND p.created_at < ?
             ORDER BY p.id ASC",
            [$from, $to]
        )->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="earnings-' . date('Ymd') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'type', 'item_id', 'gallery', 'amount', 'currency', 'gateway', 'gateway_ref', 'status', 'email', 'created_at']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['item_type'], $r['item_id'], $r['gallery'],
                $r['amount'], $r['currency'], $r['gateway'], $r['gateway_ref'],
                $r['status'], $r['email'], $r['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * Monthly recurring revenue: the monthly value of the active paid
     * memberships on real member accounts (monthly plans only - see
     * Subscription::recurringWhereSql()). This is also what "Subscription
     * revenue" shows: the owner's definition is the same number, not a
     * cash-in-window figure that counts cancelled and expired memberships.
     */
    private function mrr(): float
    {
        return (float) Database::run(
            "SELECT COALESCE(SUM(" . Subscription::paidAmountSql() . "), 0)
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             JOIN users u ON u.id = s.user_id
             WHERE " . Subscription::recurringWhereSql()
        )->fetchColumn();
    }

    /**
     * The charges recorded in the window, newest first.
     *
     * This used to list SUBSCRIPTIONS created in the window, so a renewal -
     * the money that actually keeps the site running - never appeared. It
     * reads the subscription_charges ledger now, the same source as the
     * revenue cards.
     */
    private function chargeRows(string $from, string $to, int $limit): array
    {
        return Database::run(
            "SELECT c.id, c.amount, c.kind, c.gateway, c.paid_at,
                    p.name AS plan, u.email
             FROM subscription_charges c
             JOIN subscriptions s ON s.id = c.subscription_id
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.paid_at >= ? AND c.paid_at < ?
             ORDER BY c.paid_at DESC, c.id DESC
             LIMIT " . max(1, $limit),
            [$from, $to]
        )->fetchAll();
    }

    /**
     * Resolve the requested (or default 30-day) window as [from, to).
     */
    private function window(): array
    {
        $toRaw = (string) $this->request->query('to', '');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $toRaw)) {
            $to = $toRaw . ' 23:59:59';
        } else {
            $to = date('Y-m-d 23:59:59');
        }

        $fromRaw = (string) $this->request->query('from', '');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $fromRaw)) {
            $from = $fromRaw . ' 00:00:00';
        } else {
            $from = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }
}