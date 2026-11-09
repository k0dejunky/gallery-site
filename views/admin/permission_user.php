<?php
/**
 * Per-account access overrides.
 *
 * The role already decides the baseline ($roleSet); this screen lets the super
 * admin pin individual permissions for ONE account either way - useful when two
 * moderators should not have identical reach. Every state is posted back, so an
 * untouched form rewrites exactly what it displayed.
 *
 * Locked permissions (PermissionMap::mayHold) are shown but not editable: an
 * override must not become a way around the role-level locks.
 */
$target    = $target ?? [];
$catalog   = $catalog ?? [];
$roleSet   = $roleSet ?? [];
$overrides = $overrides ?? [];
$effective = $effective ?? [];
$adminOnly = $adminOnly ?? [];

$role     = (string) ($target['role'] ?? '');
$email    = (string) ($target['email'] ?? '');
$roleName = match ($role) {
    'super_admin' => 'Super admin',
    'admin'       => 'Administrator',
    'editor'      => 'Editor',
    'moderator'   => 'Moderator',
    'viewer'      => 'Viewer',
    default       => ucfirst(str_replace('_', ' ', $role)),
};

$stateLabel = static fn (string $state): string => match ($state) {
    '1'    => 'Always allow',
    '0'    => 'Never allow',
    default=> 'Same as role',
};
?>

<div class="adm-section-head">
    <h2 class="adm-h1">Access overrides</h2>
    <p>
        Individual permissions for <strong><?= e($email) ?></strong>, on top of the
        <?= e($roleName) ?> role. Leave a row on <em>Same as role</em> to follow the
        role, or pin it either way.
    </p>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <span class="muted">
        Role baseline: <?= count($roleSet) ?> permission<?= count($roleSet) === 1 ? '' : 's' ?>
        &middot; overrides set: <?= count($overrides) ?>
    </span>
    <span style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/users/' . (int) ($target['id'] ?? 0)) ?>">&larr; Back to account</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/roles') ?>">Role matrix</a>
    </span>
</div>

<form method="post" action="<?= url('/admin/users/' . (int) ($target['id'] ?? 0) . '/permissions') ?>">
    <?= csrf_field() ?>

    <div class="adm-list-scroll">
        <table class="adm-list-table perm-matrix">
            <thead>
                <tr>
                    <th class="adm-list-th" scope="col">Permission</th>
                    <th class="adm-list-th perm-col" scope="col">Role gives</th>
                    <th class="adm-list-th perm-col" scope="col">Override</th>
                    <th class="adm-list-th perm-col" scope="col">Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalog as $group => $permissions): ?>
                    <tr class="perm-group">
                        <th colspan="4" scope="colgroup"><?= e($group) ?></th>
                    </tr>
                    <?php foreach ($permissions as $permission => $info): ?>
                        <?php
                        $locked  = !\App\Models\PermissionMap::mayHold($permission, $role);
                        $state   = (string) ($overrides[$permission] ?? '');
                        $hasRole = in_array($permission, $roleSet, true);
                        $on      = in_array($permission, $effective, true);
                        ?>
                        <tr>
                            <th scope="row">
                                <span class="perm-name"><?= e($info[0]) ?></span>
                                <span class="perm-desc"><?= e($info[1]) ?></span>
                            </th>
                            <td class="perm-col">
                                <span class="status-badge <?= $hasRole ? '' : 'cancelled' ?>">
                                    <?= $hasRole ? 'yes' : 'no' ?>
                                </span>
                            </td>
                            <td class="perm-col">
                                <?php if ($locked): ?>
                                    <span class="muted" title="This permission cannot be overridden for the <?= e($roleName) ?> role">
                                        locked &#128274;
                                    </span>
                                <?php else: ?>
                                    <select name="state[<?= e($permission) ?>]" class="adm-inline-select">
                                        <?php foreach (['', '1', '0'] as $option): ?>
                                            <option value="<?= e($option) ?>"<?= $state === $option ? ' selected' : '' ?>>
                                                <?= e($stateLabel($option)) ?>
                                            </option>
                                        <?php endforeach ?>
                                    </select>
                                <?php endif ?>
                            </td>
                            <td class="perm-col">
                                <span class="status-badge <?= $on ? '' : 'cancelled' ?>">
                                    <?= $on ? 'allowed' : 'denied' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach ?>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <div class="adm-actions" style="margin-top:1rem;">
        <button type="submit" class="btn btn-primary">Save overrides</button>
        <a class="btn btn-outline" href="<?= url('/admin/users/' . (int) ($target['id'] ?? 0)) ?>">Cancel</a>
    </div>
</form>

<?php if ($overrides !== []): ?>
    <form method="post" action="<?= url('/admin/users/' . (int) ($target['id'] ?? 0) . '/permissions/reset') ?>"
          class="inline" data-confirm="Clear every override for <?= e($email) ?> and follow the role again?"
          style="margin-top:1rem;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline">Clear all overrides</button>
    </form>
<?php endif ?>
