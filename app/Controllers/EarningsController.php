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
        $subRevenue  = $this->subscriptionRevenue($from, $to);
        $mrr         = $this->mrr();
        $industrySubs = $this->subscriptionRows($from, $to, 8);
        $recent      = Purchase::recent(50);

        $this->viewAdmin('earnings', [
            'from'        => substr($from, 0, 10),
            'to'          => substr($to, 0, 10),
            'oneOff'      => $oneOff,
            'series'      => $series,
            'subRevenue'  => $subRevenue,
            'mrr'         => $mrr,
            'industrySubs'=> $industrySubs,
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
     * Recurring revenue in the window: subscriptions whose created_at falls in
     * it and which are (or were) paid — active, cancelled or expired. A free
     * trial never took money, so it contributes nothing.
     */
    private function subscriptionRevenue(string $from, string $to): float
    {
        return (float) Database::run(
            "SELECT COALESCE(SUM(" . Subscription::paidAmountSql() . "), 0)
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.created_at >= ? AND s.created_at < ?
               AND s.status IN ('active', 'cancelled', 'expired')
               AND " . Subscription::notPendingSql(),
            [$from, $to]
        )->fetchColumn();
    }

    /**
     * Current monthly recurring revenue: active subscriptions normalised to a
     * monthly figure (monthly -> whole, yearly -> /12, lifetime -> 0). A free
     * trial contributes nothing; see Subscription::mrrSql().
     */
    private function mrr(): float
    {
        return (float) Database::run(
            "SELECT COALESCE(SUM(" . Subscription::mrrSql() . "), 0)
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'active' AND " . Subscription::notPendingSql() . "
               AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)"
        )->fetchColumn();
    }

    private function subscriptionRows(string $from, string $to, int $limit): array
    {
        return Database::run(
            "SELECT s.id, s.status, s.transaction_ref,
                    " . Subscription::paidAmountSql() . " AS amount,
                    p.name AS plan, u.email, s.created_at
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.created_at >= ? AND s.created_at < ?
               AND " . Subscription::notPendingSql() . "
             ORDER BY s.id DESC LIMIT " . max(1, $limit),
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