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
        <a class="btn btn-sm" href="<?= url('/assets/apk/OperatorChat-v1.9.apk') ?>" download>Download operator app (Android APK v1.9)</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat/export-training') ?>" onclick="return confirm('Write the cleaned training export?');">Export training</a>
    </div>
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
            <tr><th>#</th><th>User</th><th>Mode</th><th>Status</th><th>Messages</th><th>Updated</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($conversations as $c): ?>
                <tr>
                    <td>#<?= (int) $c['id'] ?></td>
                    <td><?= e((string) $c['user_email']) ?></td>
                    <td><span class="pill <?= $c['ai_mode'] === 'finetuned' ? 'pill-info' : ($c['ai_mode'] === 'operator' ? 'pill-warn' : 'pill-muted') ?>"><?= e((string) $c['ai_mode']) ?></span></td>
                    <td><?= e((string) $c['status']) ?></td>
                    <td><?= (int) $c['message_count'] ?> (<?= (int) $c['user_count'] ?> user)</td>
                    <td class="muted"><?= e(tzdate('M j, Y g:i A', (string) $c['updated_at'])) ?></td>
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