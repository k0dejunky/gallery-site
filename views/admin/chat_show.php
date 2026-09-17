<?php $title = 'Chat #' . (int) $conversation['id']; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">Chat #<?= (int) $conversation['id'] ?> &middot; <?= e((string) $user_email) ?></h1>
    <form method="post" action="<?= url('/admin/chat/' . (int) $conversation['id'] . '/mode') ?>" style="display:flex;align-items:center;gap:.5rem;">
        <?= csrf_field() ?>
        <label class="muted" style="font-size:.85rem;">AI mode:</label>
        <select name="ai_mode">
            <option value="retrieval" <?= ($conversation['ai_mode'] ?? '') === 'retrieval' ? 'selected' : '' ?>>Retrieval</option>
            <option value="finetuned" <?= ($conversation['ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned</option>
        </select>
        <button type="submit" class="btn btn-sm">Set mode</button>
    </form>
</div>

<div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem;">
    <?php if (empty($messages)): ?>
        <p class="muted">No messages yet.</p>
    <?php else: ?>
        <?php foreach ($messages as $m): ?>
            <div style="max-width:80%;padding:.6rem .9rem;border-radius:12px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;
                 <?= $m['sender_role'] === 'user' ? 'align-self:flex-end;background:var(--purple-600,#9333ea);color:#fff;' : 'align-self:flex-start;background:var(--pink-100,#fdf2f8);color:var(--purple-900,#4a044e);border:1px solid var(--pink-300,#f9a8d4);' ?>">
                <small style="display:block;font-size:.7rem;opacity:.7;margin-bottom:.15rem;text-transform:uppercase;">
                    <?= $m['sender_role'] === 'user' ? 'Member' : ($m['sender_role'] === 'model' ? 'AI' : 'Operator') ?> &middot; <?= e(tzdate('g:i A', (string) $m['created_at'])) ?>
                </small>
                <?= e((string) $m['message']) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<h2>Reply as operator</h2>
<form method="post" action="<?= url('/admin/chat/' . (int) $conversation['id'] . '/reply') ?>">
    <?= csrf_field() ?>
    <textarea name="message" rows="3" maxlength="2000" placeholder="Operator reply (harvested into training data)…" style="width:100%;box-sizing:border-box;"></textarea>
    <button type="submit" class="btn" style="margin-top:.5rem;">Send operator reply</button>
</form>

<p style="margin-top:.75rem;"><a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">Back to conversations</a></p>