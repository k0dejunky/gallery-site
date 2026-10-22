<?php
/**
 * The operator app's device tokens.
 *
 * These used to be the sixth section of the admin chat page - below the
 * overview, the AI settings, the member messages, the questionnaires and
 * the training corpus. On a phone that is a long scroll, and a token is
 * needed for EVERY new device, so the section has a page of its own and a
 * place in the navigation.
 *
 * Only the hash of a token is stored, so the raw value is shown exactly
 * once, when it is created.
 */
$title = 'Operator app';
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <h2 class="adm-h1">Operator app</h2>
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">&larr; Back to chat</a>
</div>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Signing a device in</h2>
        <p>
            The operator app (Android) signs in with one of these tokens, so every phone, tablet
            or device can be given its own and revoked on its own. Only the hash is stored, and
            a token is shown <strong>once</strong>, when it is created - copy it then.
        </p>
    </div>

    <div class="sys-card">
        <p style="margin:0;">
            <strong>What to do:</strong>
            create a token here, copy it, then in the app open <strong>Settings &rarr; Server
            address + Operator token</strong> and paste it in.
            <br>
            <strong>The broadcast needs a device token.</strong> The shared chat key works for the
            chat, but it is refused for the live broadcast - a leaked shared key must not be able
            to take the stream. If the app says the token cannot start a broadcast, this is the
            page it wants.
        </p>
    </div>

    <?php if (!empty($newToken)): ?>
        <div class="sys-card" style="border-color:var(--brand);margin-top:.75rem;">
            <h3 style="margin:0 0 .5rem;">Copy this token now - it is shown only once</h3>
            <p class="muted" style="margin:0 0 .5rem;">
                Sign the operator app in with this token, on this device or any other.
                Only its hash is stored, so it cannot be shown again.
            </p>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <code id="operator-token-value"
                      style="font-size:1rem;word-break:break-all;user-select:all;background:var(--card-bg);padding:.5rem .75rem;border-radius:var(--border-radius);"><?= e((string) $newToken['raw']) ?></code>
                <button type="button" class="btn btn-sm" data-copy="operator-token-value">Copy</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="sys-card" style="margin-top:.75rem;">
        <form class="adm-inline-form" method="post" action="<?= url('/admin/operator-tokens/create') ?>">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Device label</span>
                <input type="text" name="label" placeholder="e.g. Operator phone" required>
            </label>
            <label class="adm-field" style="flex:0 1 190px;">
                <span>Expires in days (0 = never)</span>
                <input type="number" name="expires_days" min="0" max="3650" value="0">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm">Create token</button>
            </div>
        </form>
    </div>

    <?php if (empty($tokens)): ?>
        <p class="muted u-mt-075">No device tokens yet.</p>
    <?php else: ?>
        <div class="users-table-wrap u-mt-1">
            <table>
                <thead>
                    <tr><th>Label</th><th>Scopes</th><th>Expires</th><th>Last used</th><th>Created by</th><th>Status</th><th class="u-right">Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $t): ?>
                        <?php $revoked = !empty($t['revoked']); ?>
                        <tr>
                            <td><strong><?= e((string) $t['label']) ?></strong></td>
                            <td class="muted"><?= e((string) $t['scopes']) ?></td>
                            <td class="muted"><?= !empty($t['expires_at']) ? e(tzdate('M j, Y', (string) $t['expires_at'])) : 'never' ?></td>
                            <td class="muted"><?= !empty($t['last_used_at']) ? e(tzdate('M j, Y g:i A', (string) $t['last_used_at'])) : 'never' ?></td>
                            <td class="muted"><?= e((string) ($t['created_by_email'] ?? '—')) ?></td>
                            <td><span class="pill <?= $revoked ? 'pill-muted' : '' ?>"><?= $revoked ? 'revoked' : 'active' ?></span></td>
                            <td>
                                <?php if (!$revoked): ?>
                                    <form class="inline" method="post" action="<?= url('/admin/operator-tokens/' . (int) $t['id'] . '/revoke') ?>" data-confirm="Revoke token &quot;<?= e((string) $t['label']) ?>&quot;?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>The app itself</h2>
        <p>The newest build, and the phone's copy of it.</p>
    </div>

    <div class="sys-card">
        <?php if (!empty($latestApk['file'])): ?>
            <p style="margin:0 0 .5rem;">
                Published build: <strong>v<?= e((string) $latestApk['version']) ?></strong>
                &middot;
                <a class="btn btn-sm btn-outline" href="<?= url('/assets/apk/' . e((string) $latestApk['file'])) ?>" download>Download the APK</a>
            </p>
            <p class="muted" style="margin:0;">
                The app checks for a new build on its own and offers it in the inbox - nothing to
                do but tap <strong>Install</strong> (and allow the install once, if the phone asks).
            </p>
        <?php else: ?>
            <p class="muted" style="margin:0;">No build has been published yet.</p>
        <?php endif; ?>
    </div>
</section>
