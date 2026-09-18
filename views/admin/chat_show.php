<?php $title = 'Chat #' . (int) $conversation['id']; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">Chat #<?= (int) $conversation['id'] ?> &middot; <?= e((string) $user_email) ?></h1>
    <form method="post" action="<?= url('/admin/chat/' . (int) $conversation['id'] . '/mode') ?>" style="display:flex;align-items:center;gap:.5rem;">
        <?= csrf_field() ?>
        <label class="muted" style="font-size:.85rem;">AI mode:</label>
        <select name="ai_mode">
            <option value="retrieval" <?= ($conversation['ai_mode'] ?? '') === 'retrieval' ? 'selected' : '' ?>>Retrieval (AI)</option>
            <option value="finetuned" <?= ($conversation['ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned (AI)</option>
            <option value="operator" <?= ($conversation['ai_mode'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator only (no AI)</option>
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
                <?php if (!empty($m['attachment_name']) && !empty($m['attachment_url'])): ?>
                    <?php if (!empty($m['attachment_thumb_url'])): ?>
                        <a href="<?= e($m['attachment_url']) ?>" target="_blank" rel="noopener" style="display:block;margin-top:.5rem;border-radius:8px;overflow:hidden;border:1px solid rgba(0,0,0,.08);background:#fff;">
                            <img src="<?= e($m['attachment_thumb_url']) ?>" alt="<?= e($m['attachment_name']) ?>" style="display:block;max-width:220px;max-height:220px;width:auto;height:auto;">
                        </a>
                    <?php else: ?>
                        <a href="<?= e($m['attachment_url']) ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:.5rem;">📎 <?= e($m['attachment_name']) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<h2>Reply as operator</h2>
<form method="post" action="<?= url('/admin/chat/' . (int) $conversation['id'] . '/reply') ?>" enctype="multipart/form-data" id="reply-form">
    <?= csrf_field() ?>

    <div id="emoji-bar" style="display:none;flex-wrap:wrap;gap:.2rem;margin-bottom:.5rem;padding:.4rem;border:1px solid var(--pink-300,#f9a8d4);border-radius:8px;background:var(--pink-100,#fdf2f8);max-width:520px;">
        <?php foreach (['😀','😍','😘','❤️','🔥','🥵','😈','💋','👅','😉','👍','🙈','💦','🤤','✨','😊','🖤','🌹','💯','😁','😂','🤗','😎','😏'] as $emoji): ?>
            <button type="button" class="btn btn-sm btn-outline emoji-btn" data-emoji="<?= e($emoji) ?>"><?= e($emoji) ?></button>
        <?php endforeach; ?>
    </div>

    <textarea name="message" id="reply-text" rows="3" maxlength="2000" placeholder="Type an operator reply (emojis welcome)…" style="width:100%;box-sizing:border-box;"></textarea>

    <div style="display:flex;gap:.5rem;margin-top:.5rem;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-sm btn-outline" id="emoji-toggle">😊 Emoji</button>
        <label class="btn btn-sm btn-outline" style="cursor:pointer;">
            📎 Attach file
            <input type="file" name="attachment" id="attachment-input" style="display:none;" accept="image/*,video/*,text/*,.pdf,.txt,.csv,.log">
        </label>
        <span id="attach-name" class="muted" style="font-size:.85rem;"></span>
    </div>

    <button type="submit" class="btn" style="margin-top:.5rem;">Send operator reply</button>
</form>

<script>
(function () {
    var emojiToggle = document.getElementById('emoji-toggle');
    var emojiBar = document.getElementById('emoji-bar');
    var replyText = document.getElementById('reply-text');
    var attachInput = document.getElementById('attachment-input');
    var attachName = document.getElementById('attach-name');
    var replyForm = document.getElementById('reply-form');

    emojiToggle.addEventListener('click', function () {
        emojiBar.style.display = emojiBar.style.display === 'none' ? 'flex' : 'none';
    });
    emojiBar.querySelectorAll('.emoji-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            replyText.value += b.getAttribute('data-emoji');
            replyText.focus();
        });
    });
    attachInput.addEventListener('change', function () {
        attachName.textContent = attachInput.files && attachInput.files[0]
            ? attachInput.files[0].name
            : '';
    });
    replyForm.addEventListener('submit', function () {
        var hasText = replyText.value.trim() !== '';
        var hasFile = attachInput.files && attachInput.files.length > 0;
        if (!hasText && !hasFile) {
            alert('Type a reply or attach a file.');
            event.preventDefault();
        }
    });
})();
</script>

<p style="margin-top:.75rem;"><a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">Back to conversations</a></p>