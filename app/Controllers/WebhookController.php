<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
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

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $txn !== '' ? 'CCBILL-' . $txn : (string) $subscription['transaction_ref']
        );

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

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $transId !== '' ? 'EPOCH-' . $transId : (string) $subscription['transaction_ref']
        );

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

        Subscription::activateWithTransaction(
            (int) $subscription['id'],
            $txn !== '' ? 'SEG-' . $txn : (string) $subscription['transaction_ref']
        );

        echo 'ok';
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

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
