<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\SaleCode;

/**
 * Admin: membership plan management. Plans define the tiers a user can
 * subscribe to; payments are manual/placeholder, so a plan is just an
 * offering the admin prices, describes and switches on or off.
 */
class PlanController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }

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
        $active   = (bool) $this->request->input('active', false);

        $error = $this->validate($name, $cycle, $price, $level);

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/admin/plans');
        }

        $id = Plan::create($name, $cycle, (float) $price, $desc, $sort, $level, $active);
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
        $active   = (bool) $this->request->input('active', false);

        $error = $this->validate($name, $cycle, $price, $level);

        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect('/admin/plans/' . $id . '/edit');
        }

        Plan::update($id, $name, $cycle, (float) $price, $desc, $sort, $level, $active);
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
