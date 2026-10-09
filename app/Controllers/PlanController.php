<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BraintreeGateway;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\PaymentProcessor;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\SaleCode;

/**
 * Admin: membership plan management. Plans define the tiers a user can
 * subscribe to; payments are manual/placeholder, so a plan is just an
 * offering the admin prices, describes and switches on or off.
 */
class PlanController extends MembershipAdminController
{
    /**
     * The plan list, with how many subscriptions each plan currently has.
     */
    public function index(): void
    {
        $sales = Sale::all();
        $codes = [];
        foreach ($sales as $sale) {
            $codes[(int) $sale['id']] = SaleCode::forSale((int) $sale['id']);
        }
        $this->viewAdmin('plans', [
            'plans' => Plan::all(),
            'sales' => $sales,
            'saleCodes' => $codes,
            'allCodes' => SaleCode::all(),
        ]);
    }

    /**
     * Show the create-plan form.
     */
    public function create(): void
    {
        $this->viewAdmin('plan_create', []);
    }

    /**
     * Create a plan after validating its fields.
     */
    public function store(): void
    {
        $name     = trim($this->request->input('name'));
        $cycle    = $this->request->input('billing_cycle');
        $price    = $this->request->input('price');
        $desc     = trim($this->request->input('description'));
        $sort     = (int) $this->request->input('sort_order', 0);
        $level    = (int) $this->request->input('level', Plan::SILVER_LEVEL);
        $active   = $this->request->input('active') === '1';
        $btPlanId = trim((string) $this->request->input('braintree_plan_id', ''));

        $error = $this->validate($name, $cycle, $price, $level);

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/admin/plans');
        }

        $id = Plan::create($name, $cycle, (float) $price, $desc, $sort, $level, $active, $btPlanId);
        AuditLog::record((int) Auth::user()['id'], 'create', 'plan', $id, 'Created plan "' . $name . '"', null, ['name' => $name, 'cycle' => $cycle, 'price' => $price, 'level' => $level]);

        $this->flash('success', 'Plan "' . $name . '" created.');
        $this->redirect('/admin/plans');
    }

    /**
     * The plan edit form.
     */
    public function edit(int $id): void
    {
        $plan = Plan::find($id);

        if ($plan === null) {
            $this->notFound();
            return;
        }

        $this->viewAdmin('plan_edit', [
            'plan' => $plan,
        ]);
    }

    /**
     * Save changes to a plan.
     */
    public function update(int $id): void
    {
        $plan = Plan::find($id);

        if ($plan === null) {
            $this->notFound();
            return;
        }

        $name     = trim($this->request->input('name'));
        $cycle    = $this->request->input('billing_cycle');
        $price    = $this->request->input('price');
        $desc     = trim($this->request->input('description'));
        $sort     = (int) $this->request->input('sort_order', 0);
        $level    = (int) $this->request->input('level', $plan['level'] ?? Plan::SILVER_LEVEL);
        $active   = $this->request->input('active') === '1';
        $btPlanId = trim((string) $this->request->input('braintree_plan_id', ''));

        $error = $this->validate($name, $cycle, $price, $level);

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/admin/plans/' . $id . '/edit');
        }

        Plan::update($id, $name, $cycle, (float) $price, $desc, $sort, $level, $active, $btPlanId);
        AuditLog::record((int) Auth::user()['id'], 'update', 'plan', $id, 'Updated plan "' . $name . '"', ['name' => $plan['name'], 'cycle' => $plan['billing_cycle'], 'price' => $plan['price'], 'description' => $plan['description'], 'sort_order' => $plan['sort_order'], 'level' => $plan['level'] ?? Plan::SILVER_LEVEL, 'active' => $plan['active']], ['name' => $name, 'cycle' => $cycle, 'price' => $price, 'level' => $level, 'active' => $active]);

        $this->flash('success', 'Plan "' . $name . '" updated.');
        $this->redirect('/admin/plans');
    }

    /**
     * Delete a plan. Subscriptions attached to it are removed by the
     * database's cascade, so the admin is warned in the confirmation step.
     */
    public function destroy(int $id): void
    {
        $plan = Plan::find($id);

        if ($plan === null) {
            $this->notFound();
            return;
        }

        Plan::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'plan', $id, 'Deleted plan "' . $plan['name'] . '"', ['name' => $plan['name'], 'cycle' => $plan['billing_cycle'], 'price' => $plan['price'], 'description' => $plan['description'], 'sort_order' => $plan['sort_order'], 'level' => $plan['level'] ?? Plan::SILVER_LEVEL, 'active' => $plan['active']]);

        $this->flash('success', 'Plan "' . $plan['name'] . '" deleted.');
        $this->redirect('/admin/plans');
    }

    /**
     * Toggle a plan's active status.
     */
    public function toggleActive(int $id): void
    {
        $plan = Plan::find($id);

        if ($plan === null) {
            $this->notFound();
            return;
        }

        Plan::toggleActive($id);
        $newStatus = !(int) $plan['active'] ? 'activated' : 'deactivated';
        AuditLog::record((int) Auth::user()['id'], 'update', 'plan', $id, ucfirst($newStatus) . ' plan "' . $plan['name'] . '"', ['active' => $plan['active']], ['active' => !(int) $plan['active']]);

        $this->flash('success', 'Plan "' . $plan['name'] . '" ' . $newStatus . '.');
        $this->redirect('/admin/plans');
    }

    /**
     * Provision one Braintree subscription plan per active recurring
     * (non-lifetime) membership plan. Creates the Braintree plan when it is
     * missing and stores its id on the plans row. Requires an enabled
     * Braintree payment processor with credentials configured.
     */
    public function provisionBraintree(): void
    {
        $processor = null;
        foreach (PaymentProcessor::enabled() as $candidate) {
            if (strtolower((string) $candidate['provider']) === 'braintree') {
                $processor = $candidate;
                break;
            }
        }

        if ($processor === null) {
            $this->flash('error', 'No enabled Braintree processor is configured. Add one on the Payment Processors page first.');
            $this->redirect('/admin/plans');
            return;
        }

        $gateway = BraintreeGateway::fromConfig($processor);

        if ($gateway === null) {
            $this->flash('error', 'Braintree credentials are incomplete. Check the Payment Processors page.');
            $this->redirect('/admin/plans');
            return;
        }

        $plans    = Plan::all();
        $created  = [];
        $existing = [];
        $skipped  = [];

        foreach ($plans as $plan) {
            if (strtolower((string) $plan['billing_cycle']) === 'lifetime') {
                $skipped[] = (string) $plan['name'] . ' (lifetime has no Braintree subscription)';
                continue;
            }

            $yearly    = strtolower((string) $plan['billing_cycle']) === 'yearly';
            $frequency = $yearly ? 12 : 1;
            $desiredId = $this->braintreePlanIdFor($plan);

            // Already mapped: the Braintree side exists (GET /plans is not
            // exposed to this API, so we trust the stored id).
            if ((string) ($plan['braintree_plan_id'] ?? '') !== '') {
                $existing[] = (string) $plan['name'] . ' (' . $desiredId . ')';
                continue;
            }

            try {
                $gateway->createPlan(
                    (string) $plan['name'],
                    $desiredId,
                    (string) $plan['price'],
                    $frequency,
                    'USD',
                    1
                );
                $created[] = (string) $plan['name'] . ' (' . $desiredId . ')';
            } catch (\Throwable $e) {
                // A duplicate id means the Braintree plan already exists, which
                // is exactly the mapping we want to record.
                if (stripos($e->getMessage(), 'already in use') !== false || stripos($e->getMessage(), 'already been taken') !== false) {
                    $existing[] = (string) $plan['name'] . ' (' . $desiredId . ')';
                } else {
                    $skipped[] = (string) $plan['name'] . ': ' . $e->getMessage();
                    continue;
                }
            }

            Plan::setBraintreePlanId((int) $plan['id'], $desiredId);
        }

        AuditLog::record((int) Auth::user()['id'], 'update', 'plan', 0, 'Provisioned Braintree subscription plans', null, [
            'created'  => $created,
            'existing' => $existing,
            'skipped'  => $skipped,
        ]);

        $summary = [];
        if ($created !== [])  { $summary[] = 'created ' . count($created); }
        if ($existing !== []) { $summary[] = 'reused ' . count($existing); }
        if ($skipped !== [])  { $summary[] = 'skipped ' . count($skipped); }

        $this->flash('success', 'Braintree plans: ' . ($summary === [] ? 'nothing to do.' : implode(', ', $summary) . '.'));
        $this->redirect('/admin/plans');
    }

    /**
     * Build the Braintree plan id for a site plan, preferring a stored
     * mapping and defaulting to "<slug>_<cycle>".
     */
    private function braintreePlanIdFor(array $plan): string
    {
        $stored = trim((string) ($plan['braintree_plan_id'] ?? ''));
        if ($stored !== '') {
            return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $stored);
        }

        $slug  = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($plan['slug'] ?? ''));
        $cycle = strtolower((string) ($plan['billing_cycle'] ?? 'monthly')) === 'yearly' ? 'yearly' : 'monthly';

        return $slug . '_' . $cycle;
    }

    /**
     * Shared validation for plan names, billing cycles and prices. Returns
     * an error message or null when everything is valid.
     */
    private function validate(string $name, string $cycle, string $price, int $level): ?string
    {
        if ($name === '') {
            return 'A plan name is required.';
        }

        if (!in_array($cycle, ['monthly', 'yearly', 'lifetime'], true)) {
            return 'Choose a valid billing cycle.';
        }

        if (!is_numeric($price) || (float) $price < 0) {
            return 'A valid price is required.';
        }

        if ($level < 1) {
            return 'Plan level must be at least 1.';
        }

        return null;
    }
}
