<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\SaleCode;

class SalesController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }

    public function index(): void
    {
        $this->redirect('/admin/plans');
    }

    public function store(): void
    {
        $planId = (int) $this->request->input('plan_id', 0);
        $name = trim((string) $this->request->input('name', ''));
        $price = $this->request->input('sale_price', '');
        $maxSubscriptions = trim((string) $this->request->input('max_subscriptions', ''));
        $endsAt = trim((string) $this->request->input('ends_at', ''));
        $plan = Plan::find($planId);

        if ($plan === null || $name === '' || !is_numeric($price) || (float) $price < 0 || ($plan !== null && (float) $price > (float) $plan['price'])) {
            $this->flash('error', 'Choose a plan and enter a valid sale name and price.');
            $this->redirect('/admin/sales');
        }
        if ($maxSubscriptions !== '' && (!ctype_digit($maxSubscriptions) || (int) $maxSubscriptions < 1)) {
            $this->flash('error', 'Maximum subscriptions must be a positive whole number.');
            $this->redirect('/admin/sales');
        }
        if ($endsAt !== '') {
            $endsAt = str_replace('T', ' ', $endsAt);
            if (strtotime($endsAt) === false) {
                $this->flash('error', 'Enter a valid sale end date.');
                $this->redirect('/admin/sales');
            }
        }
        try {
            $saleId = Sale::create($planId, $name, (float) $price, $maxSubscriptions !== '' ? (int) $maxSubscriptions : null, $endsAt !== '' ? $endsAt : null, true);
        } catch (\Throwable $e) {
            $this->flash('error', 'The sale could not be created.');
            $this->redirect('/admin/sales');
        }

        AuditLog::record((int) Auth::user()['id'], 'create', 'sale', $saleId, 'Created sale "' . $name . '"', null, ['plan_id' => $planId, 'sale_price' => $price, 'max_subscriptions' => $maxSubscriptions ?: null, 'ends_at' => $endsAt ?: null]);
        $this->flash('success', 'Sale created.');
        $this->redirect('/admin/sales');
    }

    public function toggleActive(int $id): void
    {
        $sale = Sale::find($id);
        if ($sale === null) { $this->notFound(); return; }
        Sale::toggleActive($id);
        $this->flash('success', 'Sale status updated.');
        $this->redirect('/admin/sales');
    }

    public function generateCode(int $id): void
    {
        $sale = Sale::find($id);
        $maxUses = (int) $this->request->input('max_uses', 0);
        if ($sale === null || $maxUses < 1) {
            $this->flash('error', 'Choose a valid sale and maximum usage count.');
            $this->redirect('/admin/plans');
        }

        $generated = SaleCode::generate($id, $maxUses);
        AuditLog::record((int) Auth::user()['id'], 'create', 'sale_code', (int) $generated['id'], 'Generated sale code ' . $generated['code'], null, ['sale_id' => $id, 'code' => $generated['code'], 'max_uses' => $maxUses]);
        $this->flash('success', 'Generated code ' . $generated['code'] . '.');
        $this->redirect('/admin/plans');
    }

    public function generateStandaloneCode(): void
    {
        $maxUses = (int) $this->request->input('max_uses', 0);
        $discountType = (string) $this->request->input('discount_type', 'none');
        $discountValue = (float) $this->request->input('discount_value', 0);
        $name = trim((string) $this->request->input('name', ''));
        $targetLevel = (int) $this->request->input('target_level', 0);
        $upgrade = trim((string) $this->request->input('upgrade_level', ''));
        $upgradeLevel = $upgrade === '' ? null : (int) $upgrade;

        if ($name === '' || $maxUses < 1 || $targetLevel < 1 || !in_array($discountType, ['none', 'fixed', 'percent'], true)) {
            $this->flash('error', 'Choose a usage limit and valid discount type.');
            $this->redirect('/admin/plans');
        }
        if (($discountType === 'fixed' && $discountValue < 0) || ($discountType === 'percent' && ($discountValue < 0 || $discountValue > 100))) {
            $this->flash('error', 'Enter a valid discount value.');
            $this->redirect('/admin/plans');
        }
        if ($upgradeLevel !== null && $upgradeLevel < 1) {
            $this->flash('error', 'Membership level upgrade must be positive.');
            $this->redirect('/admin/plans');
        }

        $generated = SaleCode::generate(null, $maxUses, $discountType, $discountValue, $upgradeLevel, $targetLevel, $name);
        AuditLog::record((int) Auth::user()['id'], 'create', 'sale_code', (int) $generated['id'], 'Generated standalone promotion code ' . $generated['code'], null, ['name' => $name, 'code' => $generated['code'], 'max_uses' => $maxUses, 'target_level' => $targetLevel, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'upgrade_level' => $upgradeLevel]);
        $this->flash('success', 'Generated code ' . $generated['code'] . '.');
        $this->redirect('/admin/plans');
    }

    public function destroy(int $id): void
    {
        $sale = Sale::find($id);
        if ($sale === null) { $this->notFound(); return; }
        Sale::delete($id);
        $this->flash('success', 'Sale deleted.');
        $this->redirect('/admin/sales');
    }
}
