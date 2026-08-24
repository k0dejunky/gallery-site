<?php $title = 'User ' . $user['email']; ?>
<style>
  .ud-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
  .ud-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: 1rem; }
  .ud-card h3 { margin: 0 0 .5rem; font-size: .95rem; color: var(--heading-color); }
  .ud-kv { display: flex; justify-content: space-between; gap: 1rem; padding: .2rem 0; font-size: .9rem; }
  .ud-kv span:first-child { color: var(--muted-text-color); }
  .badge-active { background: var(--success-bg); color: var(--success-text); padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
  .badge-suspended { background: var(--danger-bg); color: var(--danger-text); padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
  .ud-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
  .ud-actions form { margin: 0; }
  .temp-pass { background: #fffbeb; border: 1px solid #f59e0b; color: #92400e; padding: .75rem 1rem; border-radius: var(--border-radius, 8px); margin-bottom: 1rem; font-size: .95rem; }
  .temp-pass code { font-size: 1.05rem; font-weight: 700; user-select: all; }
  table { width: 100%; }
</style>

<div class="users-hero">
    <div>
        <h1><?= e($user['email']) ?></h1>
        <p>
            <span class="role-badge <?= e($user['role']) ?>"><?= e(str_replace('_', ' ', $user['role'])) ?></span>
            <?php if (!empty($user['status'])): ?>
                <span class="badge-<?= e($user['status']) ?>"><?= e($user['status']) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div><a class="btn" href="<?= url('/admin/users') ?>">← All users</a></div>
</div>

<?php if (!empty($tempPassword)): ?>
    <div class="temp-pass">
        Temporary password for <b><?= e($user['email']) ?></b>:
        <code><?= e($tempPassword) ?></code> — shown only once; share it securely.
        All their previous sessions were signed out.
    </div>
<?php endif; ?>

<div class="ud-grid">
    <div class="ud-card">
        <h3>Account</h3>
        <div class="ud-kv"><span>User ID</span><b>#<?= (int) $user['id'] ?></b></div>
        <div class="ud-kv"><span>Created</span><b><?= e($user['created_at']) ?></b></div>
        <div class="ud-kv"><span>Last login</span><b><?= e($user['last_login_at'] ?? 'never') ?></b></div>
        <div class="ud-kv"><span>Status</span><b><?= e($user['status'] ?? 'active') ?></b></div>
    </div>
    <div class="ud-card">
        <h3>Billing on file</h3>
        <div class="ud-kv"><span>Card</span><b><?= e(trim(($user['card_brand'] ?? '') . ' ··' . ($user['card_last_four'] ?? '')) ?: 'none') ?></b></div>
        <div class="ud-kv"><span>Expires</span><b><?= e(($user['card_exp_month'] ?? '') ? ((int) $user['card_exp_month'] . '/' . $user['card_exp_year']) : '—') ?></b></div>
        <div class="ud-kv"><span>Customer ID</span><b><?= e($user['payment_customer_id'] ?? '—') ?></b></div>
    </div>
    <div class="ud-card">
        <h3>Quick actions</h3>
        <div class="ud-actions" style="margin:0;">
            <?php if (($user['status'] ?? 'active') === 'suspended'): ?>
                <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/status') ?>">
                    <?= csrf_field() ?><input type="hidden" name="status" value="active">
                    <button class="btn btn-sm" type="submit">Reactivate account</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/status') ?>"
                      onsubmit="return confirm('Suspend this account? They will be signed out everywhere.');">
                    <?= csrf_field() ?><input type="hidden" name="status" value="suspended">
                    <button class="btn btn-sm btn-danger" type="submit">Suspend account</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id'] . '/edit') ?>">Edit details</a>
        </div>
        <div class="ud-actions" style="margin:.5rem 0 0;">
            <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/reset-password') ?>"
                  onsubmit="return confirm('Generate a new temporary password?');">
                <?= csrf_field() ?><button class="btn btn-sm" type="submit">Reset password</button>
            </form>
            <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/logout-everywhere') ?>"
                  onsubmit="return confirm('Sign this account out of every device?');">
                <?= csrf_field() ?><button class="btn btn-sm" type="submit">Log out everywhere</button>
            </form>
        </div>
    </div>
</div>

<h2 class="section-title">Memberships</h2>
<div class="users-table-wrap"><table class="users-table">
    <thead><tr><th>Plan</th><th>Processor</th><th>Status</th><th>Reference</th><th>Created</th><th>Expires</th></tr></thead>
    <tbody>
    <?php foreach ($subscriptions as $sub): ?>
        <tr>
            <td><?= e((string) ($sub['plan_name'] ?? '?')) ?></td>
            <td><?= e((string) ($sub['processor_name'] ?? '—')) ?></td>
            <td><span class="status-badge <?= e((string) $sub['status']) ?>"><?= e((string) $sub['status']) ?></span></td>
            <td><code><?= e((string) ($sub['transaction_ref'] ?? '')) ?></code></td>
            <td class="user-date"><?= e((string) $sub['created_at']) ?></td>
            <td class="user-date"><?= e((string) ($sub['expires_at'] ?? '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$subscriptions): ?><tr><td colspan="6" class="muted">No membership history.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<h2 class="section-title">Recent sign-in attempts</h2>
<div class="users-table-wrap"><table class="users-table">
    <thead><tr><th>When</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logins as $attempt): ?>
        <tr>
            <td class="user-date"><?= e((string) $attempt['at']) ?></td>
            <td><code><?= e((string) $attempt['ip']) ?></code></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$logins): ?><tr><td colspan="2" class="muted">No recorded attempts.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<h2 class="section-title">Audit trail</h2>
<div class="users-table-wrap"><table class="users-table">
    <thead><tr><th>When</th><th>Action</th><th>Entity</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($activity as $entry): ?>
        <tr>
            <td class="user-date"><?= e((string) $entry['created_at']) ?></td>
            <td><span class="role-badge"><?= e((string) $entry['action']) ?></span></td>
            <td><?= e((string) $entry['entity_type']) ?>#<?= (int) $entry['entity_id'] ?></td>
            <td><?= e((string) $entry['description']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$activity): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?>
    </tbody>
</table></div>
