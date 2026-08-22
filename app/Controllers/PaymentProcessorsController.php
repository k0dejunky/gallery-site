<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\PaymentProcessor;

class PaymentProcessorsController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }

    /**
     * List every configured payment processor with its masked credentials.
     */
    public function index(): void
    {
        $this->viewAdmin('payment_processors', [
            'processors' => PaymentProcessor::all(),
        ]);
    }

    /**
     * Create a new payment processor from the form.
     */
    public function store(): void
    {
        $provider = strtolower(trim((string) $this->request->input('provider', '')));
        $name     = trim((string) $this->request->input('name', ''));
        $mode     = strtolower((string) $this->request->input('mode', 'test')) === 'live' ? 'live' : 'test';
        $apiKey      = trim((string) $this->request->input('api_key', ''));
        $secretKey   = trim((string) $this->request->input('secret_key', ''));
        $webhook     = trim((string) $this->request->input('webhook_secret', ''));
        $currency    = strtoupper(trim((string) $this->request->input('currency', 'USD')));
        $isDefault   = $this->request->input('is_default') === '1';
        $enabled     = $this->request->input('enabled') === '1';

        if ($provider === '' || $name === '') {
            $this->flash('error', 'Choose a payment provider and enter a display name.');
            $this->redirect('/admin/payment-processors');
        }
        if ($secretKey === '' && $provider !== 'bitcoin') {
            $this->flash('error', 'A secret key is required for this provider.');
            $this->redirect('/admin/payment-processors');
        }

        $id = PaymentProcessor::create($provider, $name, $mode, $apiKey !== '' ? $apiKey : null, $secretKey !== '' ? $secretKey : null, $webhook !== '' ? $webhook : null, $currency, $isDefault, $enabled);

        AuditLog::record((int) Auth::user()['id'], 'create', 'payment_processor', $id, 'Configured ' . PaymentProcessor::providerLabel($provider) . ' ("' . $name . '")', null, ['provider' => $provider, 'name' => $name, 'mode' => $mode, 'currency' => $currency, 'is_default' => $isDefault, 'enabled' => $enabled]);

        $this->flash('success', 'Payment processor configured.');
        $this->redirect('/admin/payment-processors');
    }

    /**
     * Update a processor. Blank credential fields leave the saved values intact.
     */
    public function update(int $id): void
    {
        $processor = PaymentProcessor::find($id);

        if ($processor === null) {
            $this->notFound();
            return;
        }

        $provider = strtolower(trim((string) $this->request->input('provider', '')));
        $name     = trim((string) $this->request->input('name', ''));
        $mode     = strtolower((string) $this->request->input('mode', 'test')) === 'live' ? 'live' : 'test';
        $apiKey      = trim((string) $this->request->input('api_key', ''));
        $secretKey   = trim((string) $this->request->input('secret_key', ''));
        $webhook     = trim((string) $this->request->input('webhook_secret', ''));
        $currency    = strtoupper(trim((string) $this->request->input('currency', 'USD')));
        $isDefault   = $this->request->input('is_default') === '1';
        $enabled     = $this->request->input('enabled') === '1';

        if ($provider === '' || $name === '') {
            $this->flash('error', 'Choose a payment provider and enter a display name.');
            $this->redirect('/admin/payment-processors');
        }

        // Keep saved credentials when the fields are left blank.
        if ($apiKey === '')     { $apiKey = (string) ($processor['api_key'] ?? ''); }
        if ($secretKey === '')  { $secretKey = (string) ($processor['secret_key'] ?? ''); }
        if ($webhook === '')    { $webhook = (string) ($processor['webhook_secret'] ?? ''); }

        PaymentProcessor::update($id, $provider, $name, $mode, $apiKey, $secretKey, $webhook, $currency, $isDefault, $enabled);

        AuditLog::record((int) Auth::user()['id'], 'update', 'payment_processor', $id, 'Updated ' . PaymentProcessor::providerLabel($provider) . ' ("' . $name . '")', null, ['provider' => $provider, 'name' => $name, 'mode' => $mode, 'currency' => $currency, 'is_default' => $isDefault, 'enabled' => $enabled]);

        $this->flash('success', 'Payment processor updated.');
        $this->redirect('/admin/payment-processors');
    }

    /**
     * Toggle a processor's enabled state.
     */
    public function toggle(int $id): void
    {
        $processor = PaymentProcessor::find($id);
        if ($processor === null) {
            $this->notFound();
            return;
        }
        PaymentProcessor::toggleEnabled($id);
        $this->flash('success', 'Payment processor status updated.');
        $this->redirect('/admin/payment-processors');
    }

    /**
     * Delete a processor.
     */
    public function destroy(int $id): void
    {
        $processor = PaymentProcessor::find($id);
        if ($processor === null) {
            $this->notFound();
            return;
        }
        PaymentProcessor::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'payment_processor', $id, 'Removed ' . PaymentProcessor::providerLabel($processor['provider']) . ' ("' . $processor['name'] . '")', ['provider' => $processor['provider'], 'name' => $processor['name']], null);
        $this->flash('success', 'Payment processor removed.');
        $this->redirect('/admin/payment-processors');
    }
}
