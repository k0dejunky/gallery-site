<?php
/**
 * The role permission matrix.
 *
 * Permissions are rows, the four assignable roles are columns. A checkbox is
 * the desired state; on save the whole posted set becomes the role's access,
 * and anything a role may not hold is locked (see PermissionMap::mayHold).
 *
 * The super admin column is deliberately absent: that role holds '*' and is
 * not something this screen can narrow.
 */
$roles      = $roles ?? [];
$catalog    = $catalog ?? [];
$effective  = $effective ?? [];
$customised = $customised ?? [];
$adminOnly  = $adminOnly ?? [];

$roleLabel = static fn (string $role): string => match ($role) {
    'admin'    => 'Admin',
    'editor'   => 'Editor',
    'moderator'=> 'Moderator',
    'viewer'   => 'Viewer',
    default    => ucfirst(str_replace('_', ' ', $role)),
};

$isAdminRole = static fn (string $role): bool => in_array($role, ['admin', 'super_admin'], true);
?>

<div class="adm-section-head">
    <h2 class="adm-h1">Access &amp; roles</h2>
    <p>
        Choose what each role may reach in the admin panel. These are the defaults
        every account of that role inherits; an individual account can be adjusted
        on its own page. The <strong>super admin</strong> role always holds every
        permission and is not listed here.
    </p>
</div>

<form method="post" action="<?= url('/admin/roles') ?>">
    <?= csrf_field() ?>

    <?php
    // Each editable permission posts an explicit 0 or 1 (see the cell markup
    // below), so a role the form renders always appears in the request even
    // when every one of its boxes is cleared. A role that does not appear at
    // all was not part of this form and must be left alone.
    ?>

    <div class="sys-card" style="margin-bottom:1rem;">
        <p style="margin:0 0 .75rem;">
            <strong>Locked permissions.</strong> Four of these cannot be handed to a
            lower role at all, whatever is ticked:
            the mail server (a root script rewrites the Postfix/Dovecot files),
            system operations, role editing, and managing admins or money - those
            two mint admin accounts and free value, so they stay with
            <strong>admin</strong> and above.
        </p>
        <p class="muted" style="margin:0;">
            Unticking a box that the built-in default grants removes it for that role;
            ticking one it does not grant adds it. Roles you have changed are marked
            <em>edited</em> and can be reset below.
        </p>
    </div>

    <div class="adm-list-scroll">
        <table class="adm-list-table perm-matrix">
            <thead>
                <tr>
                    <th class="adm-list-th" scope="col">Permission</th>
                    <?php foreach ($roles as $role): ?>
                        <th class="adm-list-th perm-col" scope="col">
                            <?= e($roleLabel($role)) ?>
                            <?php if (in_array($role, $customised, true)): ?>
                                <span class="status-badge" title="Changed from the built-in default">edited</span>
                            <?php endif ?>
                        </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catalog as $group => $permissions): ?>
                    <tr class="perm-group">
                        <th colspan="<?= count($roles) + 1 ?>" scope="colgroup"><?= e($group) ?></th>
                    </tr>
                    <?php foreach ($permissions as $permission => $info): ?>
                        <?php
                        $lockedFor = [];
                        foreach ($roles as $role) {
                            if (!\App\Models\PermissionMap::mayHold($permission, $role)) {
                                $lockedFor[] = $role;
                            }
                        }
                        ?>
                        <tr>
                            <th scope="row">
                                <span class="perm-name"><?= e($info[0]) ?></span>
                                <span class="perm-desc"><?= e($info[1]) ?></span>
                            </th>
                            <?php foreach ($roles as $role): ?>
                                <?php $locked = in_array($role, $lockedFor, true); ?>
                                <td class="perm-col">
                                    <?php if ($locked): ?>
                                        <label class="perm-locked"
                                               title="<?= e($info[0]) ?> is not available to the <?= e($roleLabel($role)) ?> role">
                                            <input type="checkbox" disabled
                                                   aria-label="<?= e($info[0]) ?> for <?= e($roleLabel($role)) ?> (locked)"
                                                   <?= in_array($permission, (array) ($effective[$role] ?? []), true) ? 'checked' : '' ?>>
                                            <span class="perm-lock-mark" aria-hidden="true">&#128274;</span>
                                        </label>
                                    <?php else: ?>
                                        <?php
                                        // Every editable permission posts an EXPLICIT value: a hidden 0
                                        // first, then the checkbox 1. PHP keeps the last value for a
                                        // repeated name, so a ticked box posts 1 and an unticked one
                                        // posts 0. Relying on the checkbox's mere presence instead made
                                        // "this role was not in the request" and "every box for this role
                                        // was cleared" the same thing - and a partial request read that
                                        // as "revoke everything".
                                        $fieldName = 'state[' . $role . '][' . $permission . ']';
                                        ?>
                                        <input type="hidden" name="<?= e($fieldName) ?>" value="0">
                                        <label class="perm-cell">
                                            <input type="checkbox"
                                                   name="<?= e($fieldName) ?>"
                                                   value="1"
                                                   aria-label="<?= e($info[0]) ?> for <?= e($roleLabel($role)) ?>"
                                                   <?= in_array($permission, (array) ($effective[$role] ?? []), true) ? 'checked' : '' ?>>
                                        </label>
                                    <?php endif ?>
                                </td>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <div class="adm-actions" style="margin-top:1rem;">
        <button type="submit" class="btn btn-primary">Save access</button>
        <a class="btn btn-outline" href="<?= url('/admin') ?>">Cancel</a>
    </div>
</form>

<?php if ($customised !== []): ?>
    <section class="adm-section" style="margin-top:2rem;">
        <div class="adm-section-head">
            <h2>Reset a role to its default</h2>
            <p>Clears every override for that role so it follows the built-in permissions again.</p>
        </div>
        <div class="adm-actions">
            <?php foreach ($customised as $role): ?>
                <form method="post" action="<?= url('/admin/roles/reset') ?>" class="inline"
                      data-confirm="Reset the <?= e($roleLabel($role)) ?> role to its default access?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="role" value="<?= e($role) ?>">
                    <button type="submit" class="btn btn-sm btn-outline">Reset <?= e($roleLabel($role)) ?></button>
                </form>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>
