<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BraintreeGateway;
use App\Core\Controller;
use App\Core\Database;
use App\Core\PayPalGateway;
use App\Core\RateLimiter;
use App\Models\Gallery;
use App\Models\Notification;
use App\Models\PaymentProcessor;
use App\Models\Purchase;
use App\Models\UnlockCode;

/**
 * One-off commerce: gallery unlocks (redeem a creator-sold code, or pay a
 * PPV price live with a card / PayPal) and tips (recorded, or charged live).
 * Live checkout uses the enabled Braintree (cards) and PayPal (Orders v2)
 * processors; the settlement webhook reconciles async PayPal captures.
 */
class PurchaseController extends Controller
{
    public function unlock(int $id): void
    {
        Auth::requireLogin();

        // Form flow: browse-then-return routes through the password-confirm
        // page (a bare JSON 428 used to render raw JSON in the browser when a
        // member with a stale session submitted the unlock-code form).
        if (!$this->requireStepUp($this->request->header('HTTP_REFERER') ?: url('/galleries/' . $id))) {
            return;
        }

        $user = Auth::user();

        if (!RateLimiter::allow(['unlock-code:' . $this->request->ip()], 10, 900)) {
            $this->json(['error' => 'Too many attempts. Please wait a few minutes.'], 429);
            return;
        }

        $gallery = Gallery::find($id);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $code = strtoupper(trim((string) $this->request->post('unlock_code', '')));

        if ($code === '') {
            $this->flash('error', 'Please enter the unlock code you were given.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        if (Purchase::userUnlocked((int) $user['id'], $id)) {
            $this->flash('success', 'This gallery is already unlocked on your account.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        $valid = UnlockCode::findValid($code, $id);
        if ($valid === null) {
            $this->flash('error', 'That unlock code is invalid, has expired, or is for a different gallery.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        $amount = $valid['amount'] ?? null;
        if ($amount === null) {
            $amount = $gallery['ppv_price'] ?? 0;
        }

        // The code is consumed and the unlock is written in ONE transaction:
        // if the purchase insert fails, the use of the code must not be
        // burnt (the member paid and would get nothing).
        $pdo = Database::connection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }

        try {
            if (!UnlockCode::redeem((int) $valid['id'])) {
                if ($own) {
                    $pdo->rollBack();
                }
                $this->flash('error', 'That unlock code has already been used.');
                $this->redirect('/galleries/' . $id);
                return;
            }

            Purchase::create(
                (int) $user['id'],
                Purchase::TYPE_GALLERY,
                $id,
                (float) $amount,
                'code',
                (string) $valid['id'],
                'Unlocked "' . $gallery['title'] . '" with code ' . $valid['code']
            );

            Notification::add(
                (int) $user['id'],
                'purchase',
                'Gallery unlocked',
                'You unlocked "' . $gallery['title'] . '". Enjoy!',
                '/galleries/' . $id
            );

            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $ex) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[unlock] the redemption failed: ' . $ex->getMessage());
            $this->flash('error', 'That unlock could not be completed. The code has not been used — please try again.');
            $this->redirect('/galleries/' . $id);
            return;
        }

        $this->flash('success', 'Gallery unlocked — enjoy full access.');
        $this->redirect('/galleries/' . $id);
    }

    public function tip(): void
    {
        Auth::requireLogin();

        // Form flow: route the user through the password-confirm page instead
        // of a bare JSON rejection.
        if (!$this->requireStepUp($this->request->header('HTTP_REFERER') ?: url('/membership'))) {
            return;
        }

        $user = Auth::user();

        if (!RateLimiter::allow(['tip-offline:' . $this->request->ip()], 5, 600)) {
            $this->flash('error', 'Too many tips from your connection right now. Please try again shortly.');
            $this->redirect($this->request->header('HTTP_REFERER') ?: '/account');
            return;
        }

        $amount = (float) str_replace(',', '.', trim((string) $this->request->post('amount', '0')));
        $note   = trim((string) $this->request->post('note', ''));
        $back   = $this->request->header('HTTP_REFERER') ?: '/account';

        if ($amount <= 0 || $amount > 10000) {
            $this->flash('error', 'Please enter a tip amount between 0.01 and 10,000.');
            $this->redirect($back);
            return;
        }

        if ($note === '') {
            $this->flash('error', 'Please add a short note with your tip.');
            $this->redirect($back);
            return;
        }

        Purchase::create(
            (int) $user['id'],
            Purchase::TYPE_TIP,
            0,
            round($amount, 2),
            'offline',
            null,
            $note,
            Purchase::STATUS_PAID
        );

        $this->flash('success', 'Thank you! Your tip has been recorded and will be acknowledged.');
        $this->redirect($back);
    }

    // ------------------------------------------------------------------
    // Live card / PayPal checkout (PPV unlocks and tips)
    // ------------------------------------------------------------------

    /**
     * Landed on after a PayPal one-off capture. Shows a brief confirmation;
     * access is actually granted when the capture webhook settles the
     * pending purchase (usually within seconds).
     *
     * GET /checkout/complete?purchase=N
     */
    public function completed(): void
    {
        Auth::requireLogin();

        $purchaseId = (int) $this->request->query('purchase', 0);
        $purchase   = $purchaseId > 0
            ? Database::run('SELECT * FROM purchases WHERE id = ? AND user_id = ? LIMIT 1', [$purchaseId, (int) Auth::user()['id']])->fetch()
            : false;

        $this->view('checkout_complete', [
            'title'    => 'Payment received',
            'purchase' => $purchase ?: null,
        ]);
    }

    /**
     * Braintree client token for one-off card checkout (no plan involved).
     * GET /checkout/token  (login required)
     */
    public function checkoutToken(): void
    {
        if (!Auth::check()) {
            $this->json(['error' => 'Login required'], 401);
            return;
        }

        if (!Auth::recentlyAuthenticated()) {
            $this->json(['error' => 'Please confirm your password first (it expires every 30 minutes).'], 428);
            return;
        }

        if (!RateLimiter::allow(['checkout-token:' . $this->request->ip()], 30, 300)) {
            $this->json(['error' => 'Too many payment form requests.'], 429);
            return;
        }

        $gateway = $this->braintreeGateway();

        if ($gateway === null) {
            $this->json(['error' => 'Card payments are not available right now.'], 500);
            return;
        }

        try {
            $this->json([
                'client_token' => $gateway->clientToken($this->btCustomerIdForUser((int) Auth::user()['id'])),
                'environment'  => $gateway->environment(),
            ]);
        } catch (\Throwable $e) {
            error_log('[checkout-token] ' . $e->getMessage());
            $this->json(['error' => 'Could not load the payment form.'], 500);
        }
    }

    /**
     * Pay the PPV price of a gallery with a card (Braintree nonce) or PayPal.
     * POST /galleries/{id}/unlock-live
     *   provider=braintree&payment_method_nonce=...   -> card, charged now
     *   provider=paypal                               -> returns {ok, redirect}
     */
    public function unlockLive(int $id): void
    {
        Auth::requireLogin();

        if (!Auth::recentlyAuthenticated()) {
            $this->json(['error' => 'Please confirm your password first.'], 428);
            return;
        }

        $user = Auth::user();

        if (!RateLimiter::allow(['unlock-live:' . $this->request->ip(), 'unlock-live:u' . (int) $user['id']], 5, 900)) {
            $this->json(['error' => 'Too many attempts. Please wait before trying again.'], 429);
            return;
        }

        $gallery = Gallery::find($id);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if ((float) ($gallery['ppv_price'] ?? 0) <= 0) {
            $this->json(['error' => 'This gallery is not available for PPV purchase.'], 400);
            return;
        }

        if (Purchase::userUnlocked((int) $user['id'], $id)) {
            $this->json(['error' => 'This gallery is already unlocked on your account.'], 409);
            return;
        }

        $provider = strtolower((string) $this->request->post('provider', 'braintree'));
        $amount   = round((float) $gallery['ppv_price'], 2);

        if ($provider === 'paypal') {
            $this->chargePayPal(
                (int) $user['id'],
                Purchase::TYPE_GALLERY,
                $id,
                $amount,
                'PPV unlock "' . $gallery['title'] . '"',
                '/galleries/' . $id
            );
            return;
        }

        $nonce = trim((string) $this->request->post('payment_method_nonce', ''));
        if ($nonce === '') {
            $this->json(['error' => 'Payment details are missing.'], 400);
            return;
        }

        $result = $this->chargeCard(
            (int) $user['id'],
            Purchase::TYPE_GALLERY,
            $id,
            $amount,
            'PPV unlock "' . $gallery['title'] . '"',
            $nonce,
            $this->idempotencyKey()
        );

        if ($result['ok']) {
            Notification::add(
                (int) $user['id'],
                'purchase',
                'Gallery unlocked',
                'You unlocked "' . $gallery['title'] . '". Enjoy!',
                '/galleries/' . $id
            );
        }

        $this->respondCheckout($result, '/galleries/' . $id);
    }

    /**
     * Send a tip with a card (Braintree nonce) or PayPal.
     * POST /tip/live  (provider + amount + note [+ payment_method_nonce])
     */
    public function tipLive(): void
    {
        Auth::requireLogin();

        if (!Auth::recentlyAuthenticated()) {
            $this->json(['error' => 'Please confirm your password first.'], 428);
            return;
        }

        $user = Auth::user();

        if (!RateLimiter::allow(['tip-live:' . $this->request->ip(), 'tip-live:u' . (int) $user['id']], 5, 900)) {
            $this->json(['error' => 'Too many tips from your connection right now.'], 429);
            return;
        }

        $amount = round((float) str_replace(',', '.', trim((string) $this->request->post('amount', '0'))), 2);
        $note   = trim((string) $this->request->post('note', ''));
        $back   = $this->request->header('HTTP_REFERER') ?: '/account';

        if ($amount < 0.01 || $amount > 10000) {
            $this->flash('error', 'Please enter a tip amount between 0.01 and 10,000.');
            $this->redirect($back);
            return;
        }
        if ($note === '') {
            $this->flash('error', 'Please add a short note with your tip.');
            $this->redirect($back);
            return;
        }

        $provider = strtolower((string) $this->request->post('provider', 'braintree'));

        if ($provider === 'paypal') {
            $this->chargePayPal((int) $user['id'], Purchase::TYPE_TIP, 0, $amount, $note, $back);
            return;
        }

        $nonce = trim((string) $this->request->post('payment_method_nonce', ''));
        $result = $nonce === ''
            ? ['ok' => false, 'error' => 'Payment details are missing.']
            : $this->chargeCard((int) $user['id'], Purchase::TYPE_TIP, 0, $amount, $note, $nonce, $this->idempotencyKey());

        $this->respondCheckout($result, $back);
    }

    /**
     * Charge a PPV/tip amount to a card via Braintree and settle the purchase
     * ledger row. Returns ['ok' => bool, 'error' => ?string].
     */
    private function chargeCard(int $userId, string $itemType, int $itemId, float $amount, string $note, string $nonce, string $idempotencyKey = ''): array
    {
        $gateway = $this->braintreeGateway();
        $processor = $this->enabledProcessor('braintree');

        if ($gateway === null || $processor === null) {
            return ['ok' => false, 'error' => 'Card payments are not available right now.'];
        }

        // A double-click on "Pay" charged TWICE. The key travels with the
        // form, so the second click of the same button is answered with the
        // purchase the first click made.
        if ($idempotencyKey !== '') {
            $existing = Purchase::findByIdempotencyKey($idempotencyKey);

            if ($existing !== null) {
                if (!Purchase::idempotencyMatches($existing, $itemType, $itemId, $amount)) {
                    return ['ok' => false, 'error' => 'This payment has already been made for something else.'];
                }
                if ((string) ($existing['status'] ?? '') === Purchase::STATUS_PAID
                    || (string) ($existing['status'] ?? '') === Purchase::STATUS_GRANTED) {
                    return ['ok' => true, 'purchase_id' => (int) $existing['id']];
                }
            }
        }

        $purchaseId = Purchase::create(
            $userId,
            $itemType,
            $itemId,
            $amount,
            'braintree',
            null,
            $note,
            Purchase::STATUS_PENDING,
            (int) $processor['id'],
            null,
            $idempotencyKey !== '' ? $idempotencyKey : null
        );

        try {
            $customerId = $this->btCustomerIdForUser($userId);
            $customer   = $customerId !== null ? $gateway->findCustomer($customerId) : null;

            if ($customer === null) {
                $email = (string) Auth::user()['email'];
                $first = (string) (Auth::user()['billing_first_name'] ?? '');
                $last  = (string) (Auth::user()['billing_last_name'] ?? '');
                $customer = $gateway->createCustomer($email, $first ?: null, $last ?: null);
                $customerId = (string) $customer['id'];
                Database::run('UPDATE purchases SET note = ? WHERE id = ?', [$note . ' (bt_customer_id: ' . $customerId . ')', $purchaseId]);
            }

            $pm    = $gateway->createPaymentMethod($customerId, $nonce, true);
            $token = (string) ($pm['token'] ?? '');

            if ($token === '') {
                return ['ok' => false, 'error' => 'Could not save your card details.'];
            }

            $tx = $gateway->sale($token, $amount);
            $txId = (string) ($tx['id'] ?? '');

            if ($txId === '') {
                return ['ok' => false, 'error' => 'The card charge was declined.'];
            }

            Database::run(
                'UPDATE purchases SET gateway_ref = ?, payment_processor_id = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [$txId, (int) $processor['id'], Purchase::STATUS_PAID, $purchaseId]
            );

            return ['ok' => true];
        } catch (\Throwable $e) {
            error_log('[purchase/braintree] ' . $e->getMessage());
            Database::run(
                "UPDATE purchases SET status = 'refunded', updated_at = NOW() WHERE id = ?",
                [$purchaseId]
            );
            return ['ok' => false, 'error' => 'Payment failed: ' . $e->getMessage()];
        }
    }

    /**
     * Start a PayPal one-off order for a PPV/tip. Creates a pending purchase,
     * creates the PayPal order (custom_id = purchase id), stores the order id
     * as gateway_ref and answers with the approve link. The webhook completes
     * the capture.
     */
    private function chargePayPal(int $userId, string $itemType, int $itemId, float $amount, string $note, string $backUrl): void
    {
        $processor = $this->enabledProcessor('paypal');
        $gateway   = $processor !== null ? PayPalGateway::fromConfig($processor) : null;

        if ($gateway === null) {
            $this->json(['ok' => false, 'error' => 'PayPal is not configured for one-off payments.'], 500);
            return;
        }

        $purchaseId = Purchase::create($userId, $itemType, $itemId, $amount, 'paypal', null, $note, Purchase::STATUS_PENDING, (int) $processor['id']);

        try {
            $order = $gateway->createOrder(
                $amount,
                absolute_url('/checkout/complete?purchase=' . $purchaseId),
                $backUrl,
                (string) $purchaseId,
                $note
            );

            $approve = '';
            foreach (($order['links'] ?? []) as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approve = (string) ($link['href'] ?? '');
                    break;
                }
            }

            if ($approve === '') {
                throw new \RuntimeException('No approve link in PayPal order response');
            }

            Database::run(
                'UPDATE purchases SET gateway_ref = ?, updated_at = NOW() WHERE id = ?',
                [(string) $order['id'], $purchaseId]
            );

            $this->json(['ok' => true, 'redirect' => $approve]);
        } catch (\Throwable $e) {
            error_log('[purchase/paypal] ' . $e->getMessage());
            Database::run("UPDATE purchases SET status = 'refunded', updated_at = NOW() WHERE id = ?", [$purchaseId]);
            $this->json(['ok' => false, 'error' => 'PayPal could not start the payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * The idempotency key for a one-off charge.
     *
     * The form carries one, so the second click of the same button has the
     * same key. A key that is not a plain hex string is ignored rather
     * than trusted.
     */
    private function idempotencyKey(): string
    {
        $key = trim((string) $this->request->post('idempotency_key', ''));

        return preg_match('/\A[a-f0-9]{16,64}\z/', $key) === 1 ? $key : '';
    }

    /**
     * Redirect-and-flash for form posts, or JSON for fetch requests.
     */
    private function respondCheckout(array $result, string $redirectTo): void
    {
        if ($this->wantsJson()) {
            $this->json(['ok' => !empty($result['ok']), 'error' => $result['error'] ?? null]);
            return;
        }

        if (!empty($result['ok'])) {
            $this->flash('success', 'Payment successful — thank you!');
        } else {
            $this->flash('error', (string) ($result['error'] ?? 'Payment failed.'));
        }

        $this->redirect($redirectTo);
    }

    private function wantsJson(): bool
    {
        return stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
            || stripos((string) ($this->request->header('HTTP_X_REQUESTED_WITH') ?? ''), 'json') !== false;
    }

    private function enabledProcessor(string $provider): ?array
    {
        foreach (PaymentProcessor::enabled() as $pp) {
            if (strtolower((string) $pp['provider']) === $provider) {
                return $pp;
            }
        }

        return null;
    }

    private function braintreeGateway(): ?BraintreeGateway
    {
        $processor = $this->enabledProcessor('braintree');

        return $processor !== null ? BraintreeGateway::fromConfig($processor) : null;
    }

    /**
     * Look up a previously stored Braintree customer id for the user (from
     * admin_logs.after_json on subscription creates) so one-off charges reuse
     * their vaulted cards.
     */
    private function btCustomerIdForUser(int $userId): ?string
    {
        $row = Database::run(
            "SELECT after_json FROM admin_logs
             WHERE user_id = ? AND after_json LIKE '%bt_customer_id%'
             ORDER BY id DESC LIMIT 1",
            [$userId]
        )->fetch();

        $details = $row !== false ? json_decode((string) $row['after_json'], true) : null;

        if (!is_array($details)) {
            return null;
        }

        $id = (string) ($details['bt_customer_id'] ?? '');

        return $id !== '' ? $id : null;
    }
}