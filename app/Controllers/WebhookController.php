<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\Request;
use App\Models\PaymentProcessor;
use App\Models\Subscription;

/**
 * Server-to-server postback endpoints for the hosted-checkout billers
 * (CCBill, Epoch, SegPay). The biller's server calls these after a purchase
 * attempt; there is no session and no CSRF token — authenticity comes from
 * each biller's shared-secret digest where one is configured.
 *
 * Every handler reconciles the postback back to the pending subscription via
 * the reference we embedded in the checkout URL (X-ref / ref / x-ref), falling
 * back to the newest pending subscription for that provider.
 */
class WebhookController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        // Intentionally no Auth::requireLogin(): biller servers have none.
    }

    /**
     * Dispatch a biller postback by provider slug.
     */
    public function handle(string $provider): void
    {
        $provider = strtolower((string) $provider);

        switch ($provider) {
            case 'ccbill':
                $this->ccbill();
                return;
            case 'epoch':
                $this->epoch();
                return;
            case 'segpay':
                $this->segpay();
                return;
        }

        http_response_code(404);
        echo 'unknown provider';
    }

    // ------------------------------------------------------------------
    // CCBill
    //
    // Post Back sends responseCode=1 on an approved signup along with the
    // subscription id. Our X-ref parameter is echoed back when the FlexForm
    // is configured to pass it through; otherwise the newest pending signup
    // for a CCBill processor is used.
    // ------------------------------------------------------------------
    private function ccbill(): void
    {
        $responseCode = (string) $this->payload('responseCode', '');
        $subscription = $this->pendingForProvider('ccbill', [
            (string) $this->payload('X-ref', ''),
            (string) $this->payload('x-ref', ''),
            (string) $this->payload('ref', ''),
        ]);

        // Cancellation / rebill-decline postbacks reference an EXISTING
        // subscription by its biller id; kill that membership immediately
        // instead of only touching the pending signup row.
        if ($this->looksLikeCancellation()) {
            $cancelled = $this->cancelByTransactionPrefix(
                'CCBILL-',
                [(string) $this->payload('subscription_id', ''), (string) $this->payload('transaction_id', '')]
            );

            if ($cancelled > 0) {
                echo 'cancelled';
                return;
            }

            if ($responseCode !== '1' && $subscription !== null) {
                Subscription::cancel((int) $subscription['id']);
            }

            echo 'declined';
            return;
        }

        if ($responseCode !== '1') {
            if ($subscription !== null) {
                Subscription::cancel((int) $subscription['id']);
            }

            echo 'declined';
            return;
        }

        if ($subscription === null) {
            echo 'no matching pending signup';
            return;
        }

        $txn = (string) ($this->payload('subscription_id', '') ?: $this->payload('transaction_id', ''));
        $ref = $txn !== '' ? 'CCBILL-' . $txn : (string) $subscription['transaction_ref'];

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $ref
        );

        $this->notifyPayment('CCBill', (int) $subscription['id'], $ref);
        echo 'ok';
    }

    // ------------------------------------------------------------------
    // Epoch
    //
    // The Postback Service hits the approval URL configured in the Epoch
    // admin with trans_id/pi/co/amount plus epoch_digest. Documented digest
    // orderings differ between integrations, so both common forms are
    // accepted:
    //   md5(trans_id . pi . co . amount . secret)
    //   md5(co . pi . trans_id . amount . secret)
    // When no shared secret is stored yet the post is accepted but flagged
    // in the error log so setup is visible.
    // ------------------------------------------------------------------
    private function epoch(): void
    {
        $transId = (string) $this->payload('trans_id', '');
        $digest  = (string) $this->payload('epoch_digest', '');

        $processors = $this->processorRows('epoch');
        $secret     = '';

        foreach ($processors as $row) {
            $cfg = PaymentProcessor::decodeConfig($row);

            if ((string) ($cfg['secret'] ?? '') !== '') {
                $secret = (string) $cfg['secret'];
                break;
            }
        }

        if ($secret !== '' && $digest !== '') {
            $pi     = (string) $this->payload('pi', '');
            $co     = (string) $this->payload('co', '');
            $amount = (string) $this->payload('amount', '');

            $candidates = [
                md5($transId . $pi . $co . $amount . $secret),
                md5($co . $pi . $transId . $amount . $secret),
            ];

            if (!in_array($digest, $candidates, true)) {
                http_response_code(400);
                echo 'bad digest';
                return;
            }
        } elseif ($secret === '') {
            error_log('[webhooks/epoch] post accepted without digest verification: no shared secret configured');
        }

        $subscription = $this->pendingForProvider('epoch', [
            (string) $this->payload('ref', ''),
            (string) $this->payload('x-ref', ''),
        ]);

        if ($subscription === null) {
            echo 'no matching pending signup';
            return;
        }

        if ($this->looksLikeCancellation()) {
            $this->cancelByTransactionPrefix('EPOCH-', [$transId]);
            echo 'cancelled';
            return;
        }

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $transId !== '' ? 'EPOCH-' . $transId : (string) $subscription['transaction_ref']
        );

        $this->notifyPayment('Epoch', (int) $subscription['id'], (string) $subscription['transaction_ref']);
        echo 'ok';
    }

    // ------------------------------------------------------------------
    // SegPay
    //
    // SegPay posts transaction status notifications including approved,
    // stage, transactionid and (when signed posts are enabled) a hash built
    // from the merchant API credentials. Both SHA-1 and SHA-2 variants of
    // the documented concatenations are accepted.
    // ------------------------------------------------------------------
    private function segpay(): void
    {
        $approved  = strtolower((string) $this->payload('approved', ''));
        $txn       = (string) $this->payload('transactionid', $this->payload('transaction_id', ''));
        $stage     = (string) $this->payload('stage', '');
        $price     = (string) $this->payload('price', '');
        $purchase  = (string) $this->payload('purchaseid', '');
        $userId    = (string) $this->payload('userid', '');
        $hash      = (string) $this->payload('hash', '');

        $apiUser = '';
        $apiPass = '';

        foreach ($this->processorRows('segpay') as $row) {
            $cfg = PaymentProcessor::decodeConfig($row);

            if ((string) ($cfg['auth_key'] ?? '') !== '') {
                $apiUser = (string) ($cfg['api_user'] ?? '');
                $apiPass = (string) ($cfg['api_pass'] ?? '');
                break;
            }
        }

        if ($hash !== '' && $apiUser !== '' && $apiPass !== '') {
            $bases = [
                $apiUser . $apiPass . $txn . $price . $stage,
                $txn . $purchase . $userId . $price . $stage,
            ];

            $candidates = [];

            foreach ($bases as $base) {
                $candidates[] = sha1($base);
                $candidates[] = hash('sha256', $base);
            }

            if (!in_array($hash, $candidates, true)) {
                http_response_code(400);
                echo 'bad hash';
                return;
            }
        } elseif ($hash === '' && $apiPass !== '') {
            error_log('[webhooks/segpay] unsigned post received although a hash secret is configured');
        }

        $isApproved = in_array($approved, ['1', 'true', 'yes'], true);
        $subscription = $this->pendingForProvider('segpay', [
            (string) $this->payload('x-ref', ''),
            (string) $this->payload('ref', ''),
        ]);

        if (!$isApproved) {
            if ($subscription !== null) {
                Subscription::cancel((int) $subscription['id']);
            }

            echo 'declined';
            return;
        }

        if ($subscription === null) {
            echo 'no matching pending signup';
            return;
        }

        if ($this->looksLikeCancellation()) {
            $this->cancelByTransactionPrefix('SEG-', [$txn]);
            echo 'cancelled';
            return;
        }

        $ref = $txn !== '' ? 'SEG-' . $txn : (string) $subscription['transaction_ref'];

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $ref
        );

        $this->notifyPayment('SegPay', (int) $subscription['id'], $ref);
        echo 'ok';
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Heuristic for "this postback tells us a member cancelled": explicit
     * flags some billers send, or a decline-style responseCode arriving
     * together with an existing-subscription reference (rebill declines are
     * de-facto cancellations).
     */
    private function looksLikeCancellation(): bool
    {
        foreach (['action', 'event', 'type', 'status', 'notification'] as $key) {
            $v = strtolower($this->payload($key, ''));

            if (in_array($v, ['cancel', 'cancelled', 'canceled', 'cancellation'], true)) {
                return true;
            }
        }

        if ($this->payload('cancellation', '') !== '' || $this->payload('cancel', '') !== ''
            || strtolower($this->payload('responseCode', '')) === '2') {
            return true;
        }

        return false;
    }

    /**
     * Cancel every ACTIVE subscription whose transaction_ref equals
     * <prefix><billerId> for one of the given biller ids. Returns how many
     * memberships were terminated.
     */
    private function cancelByTransactionPrefix(string $prefix, array $billerIds): int
    {
        $n = 0;

        foreach (array_unique(array_filter(array_map('trim', $billerIds))) as $id) {
            $stmt = Database::run(
                "UPDATE subscriptions
                 SET status = 'cancelled',
                     expires_at = LEAST(COALESCE(expires_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE status = 'active' AND transaction_ref = ?",
                [$prefix . $id]
            );

            if ($stmt) {
                $n += $stmt->rowCount();
            }
        }

        return $n;
    }

    /**
     * Best-effort admin email after an automated (webhook) payment: who,
     * which plan, how much, which reference. Never blocks the postback.
     */
    private function notifyPayment(string $provider, int $subscriptionId, string $ref): void
    {
        try {
            $row = Database::run(
                'SELECT s.price_paid, p.name AS plan, p.billing_cycle, u.email
                 FROM subscriptions s
                 LEFT JOIN plans p ON p.id = s.plan_id
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.id = ?',
                [$subscriptionId]
            )->fetch();

            Mailer::adminAlert(
                'payment-' . $ref,
                sprintf('New %s payment: %s (%s)',
                    $provider,
                    number_format((float) ($row['price_paid'] ?? 0), 2),
                    $row['email'] ?? 'unknown user'),
                sprintf(
                    "%s just paid %s via %s for the \"%s\" plan (%s).\nReference: %s",
                    $row['email'] ?? 'unknown user',
                    number_format((float) ($row['price_paid'] ?? 0), 2),
                    $provider,
                    $row['plan'] ?? '?',
                    $row['billing_cycle'] ?? '?',
                    $ref
                ),
                604800
            );
        } catch (\Throwable $e) {
            error_log('[webhooks] payment notification failed: ' . $e->getMessage());
        }
    }

    /**
     * POST value with GET fallback (some billers send status pings via GET).
     */
    private function payload(string $key, string $default = ''): string
    {
        if (isset($_POST[$key]) && is_scalar($_POST[$key])) {
            return trim((string) $_POST[$key]);
        }

        if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
            return trim((string) $_GET[$key]);
        }

        return $default;
    }

    /**
     * Every processor row configured for a provider (used to locate shared
     * secrets without knowing which row a checkout came from).
     */
    private function processorRows(string $provider): array
    {
        return Database::run(
            'SELECT * FROM payment_processors WHERE LOWER(provider) = ? ORDER BY enabled DESC, id ASC',
            [$provider]
        )->fetchAll();
    }

    /**
     * Find the pending subscription a postback belongs to: first try every
     * reference the biller may have echoed back, then fall back to the most
     * recent pending signup for that provider.
     */
    private function pendingForProvider(string $provider, array $refs): ?array
    {
        $rows = $this->processorRows($provider);

        if ($rows === []) {
            return null;
        }

        $ids         = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;

        $sql    = "SELECT * FROM subscriptions WHERE status = 'pending' AND payment_processor_id IN ($placeholders)";
        $cleanRefs = [];

        foreach ($refs as $ref) {
            if ($ref !== '' && strpos($ref, 'PENDING-') === 0) {
                $cleanRefs[] = $ref;
            }
        }

        if ($cleanRefs !== []) {
            $ph = implode(',', array_fill(0, count($cleanRefs), '?'));
            $sql .= " AND transaction_ref IN ($ph)";
            $params = array_merge($params, $cleanRefs);
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = Database::run($sql, $params)->fetch();

        return $row ?: null;
    }
}
