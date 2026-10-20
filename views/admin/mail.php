<?php $title = 'Email'; ?>

<?php // The view carried a private <style> block built from member tokens
      // (--pink-300, --border-radius, --table-border), so the mailbox banner
      // and cards did not match the admin palette and the per-row password
      // <details> had no panel width. Everything is now a shared admin
      // component; see .adm-flash / .adm-kv / .adm-pop in admin.css. ?>

<?php if ($server['ok'] === false): ?>
    <div class="adm-flash error">
        <strong>Mail admin unavailable.</strong>
        <span><?= e($server['error']) ?></span>
    </div>
<?php endif; ?>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Delivery</h2>
        <p>Local Postfix, Dovecot and OpenDKIM plus the admin address the site sends from.</p>
    </div>

    <div class="adm-cols">
        <div class="sys-card">
            <h2>Mail server</h2>
            <dl class="adm-kv">
                <div>
                    <dt>Postfix</dt>
                    <dd class="<?= $server['postfix'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['postfix'] === null ? 'unknown' : ($server['postfix'] ? 'running' : 'stopped') ?></dd>
                </div>
                <div>
                    <dt>Dovecot</dt>
                    <dd class="<?= $server['dovecot'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['dovecot'] === null ? 'unknown' : ($server['dovecot'] ? 'running' : 'stopped') ?></dd>
                </div>
                <div>
                    <dt>OpenDKIM</dt>
                    <dd class="<?= $server['opendkim'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['opendkim'] === null ? 'unknown' : ($server['opendkim'] ? 'running' : 'stopped') ?></dd>
                </div>
                <div>
                    <dt>Admin email</dt>
                    <dd><?= e($adminEmail ?: 'not set') ?></dd>
                </div>
            </dl>
        </div>

        <div class="sys-card">
            <h2>Create mailbox</h2>
            <form method="post" action="<?= url('/admin/mail/create') ?>">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>Email address</span>
                    <input type="email" name="email" placeholder="user@amethyst2213.com" required>
                </label>
                <label class="adm-field">
                    <span>Password</span>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password">
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn">Create mailbox</button>
                    <span class="muted">Minimum 8 characters.</span>
                </div>
            </form>

            <details class="adm-create u-mt-1">
                <summary class="btn btn-sm btn-outline">Send a test email</summary>
                <form method="post" action="<?= url('/admin/mail/test') ?>" style="margin-top:.85rem;">
                    <?= csrf_field() ?>
                    <label class="adm-field">
                        <span>Recipient</span>
                        <input type="email" name="to" value="<?= e($adminEmail) ?>" required>
                    </label>
                    <div class="adm-field-actions" style="margin-top:.7rem;">
                        <button type="submit" class="btn">Send test email</button>
                    </div>
                </form>
            </details>
        </div>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Mailboxes</h2>
        <p><?= count($mailboxes) ? number_format(count($mailboxes)) . ' found' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if ($mailboxes === []): ?>
        <?= admin_empty('inbox', 'No mailboxes found', 'Create one above and it appears in Postfix and Dovecot.') ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th class="num">Storage</th>
                    <th>Status</th>
                    <th class="act">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mailboxes as $mb): ?>
                <tr>
                    <td><?= e($mb['email']) ?></td>
                    <td class="num"><?= number_format((float) ($mb['size_kb'] ?? 0) / 1024, 2) ?> MB</td>
                    <td class="<?= $mb['exists'] ? 'sys-ok' : 'sys-bad' ?>"><?= $mb['exists'] ? 'ok' : 'missing maildir' ?></td>
                    <td class="act">
                        <details class="adm-pop">
                            <summary class="btn btn-sm">Password</summary>
                            <div class="adm-pop-panel">
                                <form method="post" action="<?= url('/admin/mail/password') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="email" value="<?= e($mb['email']) ?>">
                                    <label>
                                        <span class="adm-field-label">New password</span>
                                        <input type="password" name="password" minlength="8" required autocomplete="new-password">
                                    </label>
                                    <button type="submit" class="btn btn-sm">Set</button>
                                </form>
                            </div>
                        </details>
                        <form class="inline" method="post" action="<?= url('/admin/mail/delete') ?>"
                              data-confirm="Delete mailbox <?= e($mb['email']) ?> and all of its mail? This cannot be undone.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="email" value="<?= e($mb['email']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>
