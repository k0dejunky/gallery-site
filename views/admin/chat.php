<?php $title = 'Chat Admin'; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <h1 style="margin:0;">Chat</h1>
    <div>
        <span class="muted" style="font-size:.85rem;">
            AI master switch: <strong><?= !empty($state['ai_enabled']) ? 'ON' : 'OFF' ?></strong> &middot;
            Training: <strong><?= (int) ($trainingCount ?? 0) ?></strong> pairs (<?= (int) ($cleanedCount ?? 0) ?> cleaned) &middot;
            <?php if (!empty($ai['hasBase'])): ?>AI base online<?php else: ?>AI base <span style="color:var(--danger,#c62828);">offline</span><?php endif; ?>
            <?php if (!empty($ai['hasFine'])): ?> &middot; fine-tuned loaded (<?= e((string) $finetunedModel) ?>)<?php elseif (!empty($adapterInstalled)): ?> &middot; adapter installed — model <span style="color:var(--danger,#c62828);">not built yet</span><?php else: ?> &middot; no adapter installed (retrieval mode only)<?php endif; ?>
        </span>
        <form class="inline" method="post" action="<?= url('/admin/chat/ai-toggle') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm <?= !empty($state['ai_enabled']) ? '' : 'btn-outline' ?>"><?= !empty($state['ai_enabled']) ? 'Turn AI off' : 'Turn AI on' ?></button>
        </form>
        <a class="btn btn-sm" href="<?= url('/assets/apk/OperatorChat-v2.18.apk') ?>" download>Download operator app (Android APK v2.18)</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat/export-training') ?>" onclick="return confirm('Write the cleaned training export?');">Export training</a>
    </div>
</div>

<?php // Operator device tokens (per-device auth for the Android app) ?>
<div class="card" style="border-left:4px solid var(--purple-500);padding:1rem;margin-bottom:1.25rem;">
    <h2 class="section-title">Operator device tokens</h2>
    <p class="muted" style="margin-top:0;">Per-device tokens replace the shared bridge key for the Android app. Only the hash is stored; revoking a token immediately blocks that device. The legacy shared key still works during migration.</p>
    <form method="post" action="<?= url('/admin/chat/tokens') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;">
        <?= csrf_field() ?>
        <input type="text" name="label" placeholder="Device label, e.g. Operator phone" required style="flex:1;min-width:180px;">
        <label class="muted" style="font-size:.85rem;">Expires in
            <input type="number" name="expires_days" min="0" max="3650" value="0" style="width:4.5rem;"> days (0 = never)
        </label>
        <button type="submit" class="btn btn-sm">Create token</button>
    </form>
    <?php if (empty($tokens)): ?>
        <p class="muted">No device tokens yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Label</th><th>Scopes</th><th>Expires</th><th>Last used</th><th>Created by</th><th>Status</th><th style="text-align:right;">Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tokens as $t): ?>
                    <?php $revoked = !empty($t['revoked']); ?>
                    <tr>
                        <td><strong><?= e((string) $t['label']) ?></strong></td>
                        <td class="muted"><?= e((string) $t['scopes']) ?></td>
                        <td class="muted"><?= !empty($t['expires_at']) ? e(tzdate('M j, Y', (string) $t['expires_at'])) : '<span class="muted">never</span>' ?></td>
                        <td class="muted"><?= !empty($t['last_used_at']) ? e(tzdate('M j, Y g:i A', (string) $t['last_used_at'])) : '<span class="muted">never</span>' ?></td>
                        <td class="muted"><?= e((string) ($t['created_by_email'] ?? '—')) ?></td>
                        <td><span class="pill <?= $revoked ? 'pill-muted' : '' ?>"><?= $revoked ? 'revoked' : 'active' ?></span></td>
                        <td style="text-align:right;">
                            <?php if (!$revoked): ?>
                                <form class="inline" method="post" action="<?= url('/admin/chat/tokens/' . (int) $t['id'] . '/revoke') ?>" onsubmit="return confirm('Revoke token &quot;<?= e((string) $t['label']) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php // AI settings (site default mode) ?>
<form method="post" action="<?= url('/admin/chat/settings') ?>" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Default mode for <strong>new</strong> conversations (existing ones keep their own mode):</label>
    <select name="default_ai_mode">
        <option value="retrieval" <?= ($state['default_ai_mode'] ?? 'retrieval') === 'retrieval' ? 'selected' : '' ?>>Retrieval (AI, few-shot over operator replies)</option>
        <option value="finetuned" <?= ($state['default_ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned (AI, LoRA adapter)</option>
        <option value="operator" <?= ($state['default_ai_mode'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator only (no AI)</option>
    </select>
    <button type="submit" class="btn btn-sm">Save default</button>
</form>

<?php // Daily broadcast to users without the chat feature ?>
<form method="post" action="<?= url('/admin/chat/daily-message') ?>" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;max-width:640px;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Daily message to users without the chat feature (shown on their Chat page; they cannot reply until they add the chat plan):</label>
    <textarea name="daily_message" rows="3" maxlength="5000" placeholder="e.g. Chat is available on Platinum, Yearly, Lifetime, or the Chat add-on plan."><?= e((string) ($state['daily_message'] ?? '')) ?></textarea>
    <div><button type="submit" class="btn btn-sm">Save daily message</button></div>
</form>

<?php // Daily chat broadcast: schedule or send now, plus the send log ?>
<form method="post" action="<?= url('/admin/chat/daily-broadcast') ?>" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:.5rem;max-width:640px;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Daily chat broadcast — deliver this message to every chat-eligible member's conversation now, or schedule it for later:</label>
    <textarea name="message" rows="3" maxlength="5000" placeholder="e.g. Good morning! New content is live — open today's galleries and tell me what you think."></textarea>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <input type="datetime-local" name="scheduled_at">
        <button type="submit" name="action" value="now" class="btn btn-sm">Send now</button>
        <button type="submit" name="action" value="schedule" class="btn btn-sm btn-outline">Schedule</button>
    </div>
</form>

<h3>Daily Chat Send Log</h3>
<?php if (empty($broadcasts)): ?>
    <p class="muted" style="margin-top:0;">No daily chat broadcasts yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>#</th><th>Message</th><th>Status</th><th>Scheduled</th><th>Sent</th><th>Recipients</th><th>Created by</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($broadcasts as $b): ?>
                <tr>
                    <td>#<?= (int) $b['id'] ?></td>
                    <td style="max-width:320px;"><?= e(mb_strimwidth((string) $b['message'], 0, 90, '…')) ?></td>
                    <td><span class="pill <?= $b['status'] === 'sent' ? '' : ($b['status'] === 'partial' ? 'pill-warn' : ($b['status'] === 'cancelled' ? 'pill-muted' : 'pill-info')) ?>"><?= e((string) $b['status']) ?></span></td>
                    <td class="muted"><?= !empty($b['scheduled_at']) ? e(tzdate('M j, Y H:i', (string) $b['scheduled_at'])) : '<span class="muted">&mdash;</span>' ?></td>
                    <td class="muted"><?= !empty($b['sent_at']) ? e(tzdate('M j, Y H:i', (string) $b['sent_at'])) : '<span class="muted">&mdash;</span>' ?></td>
                    <td><?= (int) $b['sent_count'] ?> / <?= (int) $b['recipients'] ?></td>
                    <td class="muted"><?= e((string) ($b['created_by_email'] ?? '—')) ?></td>
                    <td style="text-align:right;">
                        <?php if (in_array($b['status'], ['scheduled', 'failed'], true)): ?>
                            <form class="inline" method="post" action="<?= url('/admin/chat/daily-broadcast/' . (int) $b['id'] . '/send') ?>" onsubmit="return confirm('Send this daily chat now?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Send now</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($b['status'], ['scheduled', 'sending'], true)): ?>
                            <form class="inline" method="post" action="<?= url('/admin/chat/daily-broadcast/' . (int) $b['id'] . '/cancel') ?>" onsubmit="return confirm('Cancel this scheduled daily chat?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($b['error'])): ?>
                            <span class="muted" title="<?= e((string) $b['error']) ?>">⚠</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php // Message any user: open a conversation with any account and send an operator message ?>
<form method="post" action="<?= url('/admin/chat/new') ?>" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1.25rem;max-width:640px;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Message any user — start (or continue) a conversation with an account and send an operator message:</label>
    <input type="email" name="user_email" placeholder="user@example.com" required>
    <textarea name="message" rows="2" maxlength="2000" placeholder="Message to send…" required></textarea>
    <div><button type="submit" class="btn btn-sm">Send to this user</button></div>
</form>

<?php // Conversation list filter ?>
<form method="get" action="<?= url('/admin/chat') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;">
    <label>Search<br><input type="text" name="q" value="<?= e($filterQ) ?>" placeholder="email or #id" size="24"></label>
    <label>Mode<br>
        <select name="mode">
            <option value="">— all —</option>
            <option value="retrieval" <?= $filterMode === 'retrieval' ? 'selected' : '' ?>>Retrieval</option>
            <option value="finetuned" <?= $filterMode === 'finetuned' ? 'selected' : '' ?>>Fine-tuned</option>
            <option value="operator" <?= $filterMode === 'operator' ? 'selected' : '' ?>>Operator only</option>
        </select>
    </label>
    <button type="submit" class="btn btn-sm">Filter</button>
</form>

<?php if (empty($conversations)): ?>
    <p class="muted">No conversations yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>#</th><th>User</th><th>Mode</th><th>Status</th><th>Messages</th><th>Updated</th><th style="text-align:center;">Member replies</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($conversations as $c): ?>
                <?php $replyEnabled = (int) ($c['member_reply_enabled'] ?? 1) === 1; ?>
                <tr>
                    <td>#<?= (int) $c['id'] ?></td>
                    <td><?= e((string) $c['user_email']) ?></td>
                    <td><span class="pill <?= $c['ai_mode'] === 'finetuned' ? 'pill-info' : ($c['ai_mode'] === 'operator' ? 'pill-warn' : 'pill-muted') ?>"><?= e((string) $c['ai_mode']) ?></span></td>
                    <td><?= e((string) $c['status']) ?></td>
                    <td><?= (int) $c['message_count'] ?> (<?= (int) $c['user_count'] ?> user)</td>
                    <td class="muted"><?= e(tzdate('M j, Y g:i A', (string) $c['updated_at'])) ?></td>
                    <td style="text-align:center;">
                        <form class="inline" method="post" action="<?= url('/admin/chat/' . (int) $c['id'] . '/reply-toggle') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $replyEnabled ? '' : 'btn-outline' ?>"
                                    title="<?= $replyEnabled ? 'Member can reply — click to disable' : 'Member cannot reply — click to enable' ?>">
                                <?= $replyEnabled ? 'ON' : 'OFF' ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align:right;">
                        <a class="btn btn-sm" href="<?= url('/admin/chat/' . (int) $c['id']) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pages > 1): ?>
        <div style="display:flex;gap:.35rem;margin-top:.75rem;">
            <?php for ($p = 1; $p <= $pages; $p++): ?>
                <a class="btn btn-sm <?= $p === $page ? 'btn' : 'btn-outline' ?>" href="<?= e(url('/admin/chat?page=' . $p . ($filterQ !== '' ? '&q=' . rawurlencode($filterQ) : '') . ($filterMode !== '' ? '&mode=' . rawurlencode($filterMode) : ''))) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
