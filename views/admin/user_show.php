<?php $title = 'User ' . $user['email']; ?>
<?php // Shared listing styles (users-table, badges, hero) come from /assets/admin-shared.css. ?>

<div class="users-hero">
    <div>
        <p>
            <span class="role-badge <?= e($user['role']) ?>"><?= e(str_replace('_', ' ', $user['role'])) ?></span>
            <?php if (!empty($user['status'])): ?>
                <span class="badge-<?= e($user['status']) ?>"><?= e($user['status']) ?></span>
            <?php endif; ?>
            <?php if (!empty($user['flag'])): ?>
                <span class="badge-flag"><?= e($user['flag']) ?></span>
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
        <div class="ud-kv"><span>Created</span><b><?= e(tzdate('F j, Y g:i A', $user['created_at'])) ?></b></div>
        <div class="ud-kv"><span>Last login</span><b><?= !empty($user['last_login_at']) ? e(tzdate('F j, Y g:i A', $user['last_login_at'])) : 'never' ?></b></div>
        <div class="ud-kv"><span>Status</span><b><?= e($user['status'] ?? 'active') ?></b></div>
    </div>
    <div class="ud-card">
        <h3>Signup source</h3>
        <div class="ud-kv"><span>Trial link</span><b>
            <?php if (!empty($trialSource['code'])): ?>
                <code><?= e((string) $trialSource['code']) ?></code>
                <?php if (!empty($trialSource['level'])): ?>
                    · <?= e(\App\Models\TrialLink::levelLabel((int) $trialSource['level'])) ?>
                    <?= (int) ($trialSource['days'] ?? 0) ?>-day
                <?php endif; ?>
                <?php if (!empty($trialSource['id'])): ?> · link #<?= (int) $trialSource['id'] ?><?php endif; ?>
            <?php else: ?>
                <span class="muted">none recorded</span>
            <?php endif; ?>
        </b></div>
        <div class="ud-kv"><span>Traffic link</span><b>
            <?php if (!empty($trafficLink['name'])): ?>
                <?= e((string) $trafficLink['name']) ?>
                <span class="muted">(<?= e((string) $trafficLink['code']) ?>)</span>
            <?php else: ?>
                <span class="muted">none recorded</span>
            <?php endif; ?>
        </b></div>
        <div class="ud-kv"><span>UTM</span><b>
            <?php
            $utmParts = array_filter([
                (string) ($user['utm_source'] ?? ''),
                (string) ($user['utm_medium'] ?? ''),
                (string) ($user['utm_campaign'] ?? ''),
            ], static fn (string $v): bool => $v !== '');
            echo $utmParts !== [] ? e(implode(' / ', $utmParts)) : '<span class="muted">none</span>';
            ?>
        </b></div>
        <div class="ud-kv"><span>Referred by</span><b>
            <?php if (!empty($referrer['email'])): ?>
                <a href="<?= url('/admin/users/' . (int) $referrer['id']) ?>"><?= e((string) $referrer['email']) ?></a>
            <?php else: ?>
                <span class="muted">none</span>
            <?php endif; ?>
        </b></div>
    </div>
    <div class="ud-card">
        <h3>Billing on file</h3>
        <div class="ud-kv"><span>Card</span><b><?= e(trim(($user['card_brand'] ?? '') . ' ··' . ($user['card_last_four'] ?? '')) ?: 'none') ?></b></div>
        <div class="ud-kv"><span>Expires</span><b><?= e(($user['card_exp_month'] ?? '') ? ((int) $user['card_exp_month'] . '/' . $user['card_exp_year']) : '—') ?></b></div>
        <div class="ud-kv"><span>Customer ID</span><b><?= e($user['payment_customer_id'] ?? '—') ?></b></div>
        <div class="ud-kv"><span>Lifetime revenue</span><b>$<?= number_format($lifetimeRevenue, 2) ?></b></div>
    </div>
    <div class="ud-card">
        <h3>Quick actions</h3>
        <div class="ud-actions u-m0">
            <?php if (($user['status'] ?? 'active') === 'suspended'): ?>
                <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/status') ?>">
                    <?= csrf_field() ?><input type="hidden" name="status" value="active">
                    <button class="btn btn-sm" type="submit">Reactivate account</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/status') ?>"
                      data-confirm="Suspend this account? They will be signed out everywhere.">
                    <?= csrf_field() ?><input type="hidden" name="status" value="suspended">
                    <button class="btn btn-sm btn-danger" type="submit">Suspend account</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id'] . '/edit') ?>">Edit details</a>
            <?php if (\App\Core\Auth::can('manage_roles') && \App\Core\Auth::isAdminRole($user)): ?>
                <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id'] . '/permissions') ?>">Access overrides</a>
            <?php endif; ?>
        </div>
        <div class="ud-actions" style="margin:.5rem 0 0;">
            <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/reset-password') ?>"
                  data-confirm="Generate a new temporary password?">
                <?= csrf_field() ?><button class="btn btn-sm" type="submit">Reset password</button>
            </form>
            <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/logout-everywhere') ?>"
                  data-confirm="Sign this account out of every device?">
                <?= csrf_field() ?><button class="btn btn-sm" type="submit">Log out everywhere</button>
            </form>
        </div>
    </div>
    <div class="ud-card">
        <h3>Flag</h3>
        <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/flag') ?>" style="display:flex;gap:.4rem;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <select name="flag" style="flex:1;min-width:8rem;">
                <?php foreach (['', 'chargeback', 'vip', 'watch', 'abuser', 'comped'] as $opt): ?>
                    <option value="<?= e($opt) ?>"<?= ($user['flag'] ?? '') === $opt ? ' selected' : '' ?>>
                        <?= $opt === '' ? 'no flag' : e($opt) ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!empty($user['flag']) && !in_array($user['flag'], ['', 'chargeback', 'vip', 'watch', 'abuser', 'comped'], true)): ?>
                    <option value="<?= e($user['flag']) ?>" selected><?= e($user['flag']) ?> (custom)</option>
                <?php endif; ?>
            </select>
            <button class="btn btn-sm" type="submit">Set</button>
        </form>
        <p class="muted" style="margin:.5rem 0 0;font-size:.8rem;">Flagged accounts are filterable in the users list.</p>
    </div>
</div>

<details open>
    <summary class="section-title" style="cursor:pointer;list-style:none;">
        <span style="display:flex;align-items:center;gap:.5rem;">
            Memberships
            <small class="muted">(<?= count($subscriptions) ?>)</small>
        </span>
    </summary>
    <div class="users-table-wrap"><table class="users-table">
        <thead><tr><th>Plan</th><th>Processor</th><th>Status</th><th>Reference</th><th>Source</th><th>Created</th><th>Expires</th></tr></thead>
        <tbody>
        <?php foreach ($subscriptions as $sub): ?>
            <tr>
                <td><?= e((string) ($sub['plan_name'] ?? '?')) ?></td>
                <td><?= e((string) ($sub['processor_name'] ?? '—')) ?></td>
                <td><span class="status-badge <?= e((string) $sub['status']) ?>"><?= e((string) $sub['status']) ?></span></td>
                <td><code><?= e((string) ($sub['transaction_ref'] ?? '')) ?></code></td>
                <td>
                    <?php if (str_starts_with((string) ($sub['transaction_ref'] ?? ''), 'TRIAL-') && !empty($trialSource['code'])): ?>
                        Trial link <code><?= e((string) $trialSource['code']) ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="user-date"><?= e(tzdate('Y-m-d H:i', $sub['created_at'])) ?></td>
                <td class="user-date"><?= !empty($sub['expires_at']) ? e(tzdate('Y-m-d H:i', $sub['expires_at'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$subscriptions): ?><tr><td colspan="7" class="muted">No membership history.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</details>

<details>
    <summary class="section-title" style="cursor:pointer;list-style:none;">
        <span style="display:flex;align-items:center;gap:.5rem;">
            Recent sign-in attempts
            <small class="muted">(<?= count($logins) ?>)</small>
        </span>
    </summary>
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
</details>

<details>
    <summary class="section-title" style="cursor:pointer;list-style:none;">
        <span style="display:flex;align-items:center;gap:.5rem;">
            Audit trail
            <small class="muted">(<?= count($activity) ?>)</small>
        </span>
    </summary>
    <div class="users-table-wrap"><table class="users-table">
        <thead><tr><th>When</th><th>Action</th><th>Entity</th><th>Description</th></tr></thead>
        <tbody>
        <?php foreach ($activity as $entry): ?>
            <tr>
                <td class="user-date"><?= e(tzdate('Y-m-d H:i', $entry['created_at'])) ?></td>
                <td><span class="role-badge"><?= e((string) $entry['action']) ?></span></td>
                <td><?= e((string) $entry['entity_type']) ?>#<?= (int) $entry['entity_id'] ?></td>
                <td><?= e((string) $entry['description']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$activity): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</details>

<details open>
    <summary class="section-title" style="cursor:pointer;list-style:none;">
        <span style="display:flex;align-items:center;gap:.5rem;">
            Internal notes
            <small class="muted">(<?= count($notes) ?>)</small>
        </span>
    </summary>
    <div class="users-table-wrap" style="max-width:720px;">
        <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/notes') ?>" style="display:flex;gap:.5rem;margin-bottom:.75rem;">
            <?= csrf_field() ?>
            <textarea name="body" rows="2" required placeholder="Add an internal note (never shown to the member)…" style="flex:1;"></textarea>
            <button class="btn btn-sm" type="submit">Add note</button>
        </form>
        <table class="users-table">
        <thead><tr><th>When</th><th>By</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($notes as $note): ?>
            <tr>
                <td class="user-date" style="white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', $note['created_at'])) ?></td>
                <td><?= e((string) ($note['author'] ?? 'system')) ?></td>
                <td style="white-space:pre-wrap;"><?= e((string) $note['body']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$notes): ?><tr><td colspan="3" class="muted">No notes yet.</td></tr><?php endif; ?>
        </tbody>
        </table>
    </div>
</details>
