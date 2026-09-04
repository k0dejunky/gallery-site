<?php $title = 'Email'; ?>

<style>
    .mail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .mail-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--border-radius); padding: 1rem; }
    .mail-card h2 { margin: 0 0 .5rem; font-size: var(--font-size-lg); color: var(--card-title-color); }
    .mail-card table { width: 100%; font-size: var(--font-size-sm); }
    .mail-card td, .mail-card th { padding: .3rem .4rem; text-align: left; border-bottom: 1px solid var(--table-border); }
    .mail-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    .mail-actions form, .mail-actions form p { margin: 0; }
    .sys-ok { color: #15803d; font-weight: bold; }
    .sys-bad { color: var(--btn-danger-color, #b91c1c); font-weight: bold; }
    .mail-msg { padding: .75rem 1rem; border-radius: var(--border-radius); margin-bottom: 1rem; }
    .mail-msg.error { background: var(--pink-300); border-left: 4px solid var(--pink-600); color: var(--purple-900); }
    .mail-msg.ok { background: var(--pink-200); border-left: 4px solid var(--purple-500); color: var(--purple-900); }
    details.mail-card > summary { cursor: pointer; list-style: none; user-select: none; }
    details.mail-card > summary::-webkit-details-marker { display: none; }
</style>

<?php if ($server['ok'] === false): ?>
    <div class="mail-msg error">
        <strong>Mail admin unavailable.</strong>
        <?= e($server['error']) ?>
    </div>
<?php endif; ?>

<div class="mail-grid">

    <!-- Server status -->
    <div class="mail-card">
        <h2>Mail server</h2>
        <table>
            <tr><th>Postfix</th><td class="<?= $server['postfix'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['postfix'] === null ? 'unknown' : ($server['postfix'] ? 'running' : 'stopped') ?></td></tr>
            <tr><th>Dovecot</th><td class="<?= $server['dovecot'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['dovecot'] === null ? 'unknown' : ($server['dovecot'] ? 'running' : 'stopped') ?></td></tr>
            <tr><th>OpenDKIM</th><td class="<?= $server['opendkim'] ? 'sys-ok' : 'sys-bad' ?>"><?= $server['opendkim'] === null ? 'unknown' : ($server['opendkim'] ? 'running' : 'stopped') ?></td></tr>
            <tr><th>Admin email</th><td><?= e($adminEmail ?: 'not set') ?></td></tr>
        </table>

        <div class="mail-actions">
            <details>
                <summary class="muted" style="cursor:pointer;">Send a test email</summary>
                <form method="post" action="<?= url('/admin/mail/test') ?>" style="margin-top:.5rem;">
                    <?= csrf_field() ?>
                    <p>
                        <label for="mail-test-to">Recipient</label><br>
                        <input type="email" name="to" id="mail-test-to" value="<?= e($adminEmail) ?>" required>
                    </p>
                    <button type="submit" class="btn">Send test email</button>
                </form>
            </details>
        </div>
    </div>

    <!-- Create mailbox -->
    <div class="mail-card">
        <h2>Create mailbox</h2>
        <form method="post" action="<?= url('/admin/mail/create') ?>">
            <?= csrf_field() ?>
            <p>
                <label for="mail-email">Email address</label><br>
                <input type="email" name="email" id="mail-email" placeholder="user@amethyst2213.com" required>
            </p>
            <p>
                <label for="mail-password">Password (min 8 chars)</label><br>
                <input type="password" name="password" id="mail-password" minlength="8" required autocomplete="new-password">
            </p>
            <button type="submit" class="btn">Create mailbox</button>
        </form>
    </div>
</div>

<!-- Mailboxes -->
<div class="mail-card" style="margin-top:1rem;">
    <h2>Mailboxes (<?= count($mailboxes) ?>)</h2>

    <?php if ($mailboxes === []): ?>
        <p class="muted">No mailboxes found.</p>
    <?php else: ?>
        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr><th>Email</th><th>Storage</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mailboxes as $mb): ?>
                    <tr>
                        <td><?= e($mb['email']) ?></td>
                        <td><?= number_format((float) ($mb['size_kb'] ?? 0) / 1024, 2) ?> MB</td>
                        <td class="<?= $mb['exists'] ? 'sys-ok' : 'sys-bad' ?>"><?= $mb['exists'] ? 'ok' : 'missing maildir' ?></td>
                        <td style="white-space:nowrap;">
                            <details style="display:inline-block;vertical-align:middle;">
                                <summary class="btn btn-sm" style="display:inline-block;cursor:pointer;list-style:none;">Password</summary>
                                <form method="post" action="<?= url('/admin/mail/password') ?>" style="display:inline-block;margin-left:.25rem;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="email" value="<?= e($mb['email']) ?>">
                                    <input type="password" name="password" minlength="8" placeholder="New password" required>
                                    <button type="submit" class="btn btn-sm">Set</button>
                                </form>
                            </details>
                            <form class="inline" method="post" action="<?= url('/admin/mail/delete') ?>"
                                  onsubmit="return confirm('Delete mailbox <?= e($mb['email']) ?> and all of its mail? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="email" value="<?= e($mb['email']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>