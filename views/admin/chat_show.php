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

<div id="chat-thread" style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem;max-height:60vh;overflow-y:auto;padding:.5rem;">
    <?php if (empty($messages)): ?>
        <p class="muted">No messages yet.</p>
    <?php else: ?>
        <?php foreach ($messages as $m): ?>
            <div style="display:flex;flex-direction:column;gap:.2rem;width:fit-content;max-width:80%;<?= $m['sender_role'] === 'user' ? 'align-self:flex-end;align-items:flex-end;' : 'align-self:flex-start;align-items:flex-start;' ?>">
                <small style="font-size:.7rem;opacity:.7;text-transform:uppercase;letter-spacing:.04em;">
                    <?= $m['sender_role'] === 'user' ? 'Member' : ($m['sender_role'] === 'model' ? 'AI' : 'Operator') ?> &middot; <?= e(tzdate('g:i A', (string) $m['created_at'])) ?>
                </small>
                <div style="padding:.3rem .65rem;border-radius:12px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;
                     <?= $m['sender_role'] === 'user' ? 'background:var(--purple-600,#9333ea);color:#fff;border-bottom-right-radius:3px;' : 'background:var(--pink-100,#fdf2f8);color:var(--purple-900,#4a044e);border:1px solid var(--pink-300,#f9a8d4);border-bottom-left-radius:3px;' ?>">
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
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<h2>Reply as operator</h2>
<form method="post" action="<?= url('/admin/chat/' . (int) $conversation['id'] . '/reply') ?>" enctype="multipart/form-data" id="reply-form" data-no-progress>
    <?= csrf_field() ?>

    <div id="emoji-bar" style="display:none;flex-wrap:wrap;gap:.2rem;margin-bottom:.5rem;padding:.4rem;border:1px solid var(--pink-300,#f9a8d4);border-radius:8px;background:var(--pink-100,#fdf2f8);max-width:520px;">
        <?php foreach (['😀','😁','😂','🤣','😊','😍','😘','😗','😚','🙂','🤗','🤩','😎','😏','😉','🙃','😋','🤪','😜','🥰','😇','🤓','😴','🤤','😌','😔','😢','😭','😤','😡','🤬','😱','😳','🤯','😷','🤒','🥳','🤠','👽','👍','👎','👌','✌️','🤞','👊','✊','🤛','🤜','🙏','👏','💪','🖤','❤️','🧡','💛','💚','💙','💜','💖','💗','💕','💞','💘','💝','💋','👅','🔥','✨','⭐','🌟','💫','🎉','🎊','🎂','🎁','💯','🎯','🚀','💎','🌸','🌹','🥀','🌺','🍑','🍆','🍒','🍓','🍉','🥂','🍾','☕','🌞','🌙','⚡','☀️','🌈','❄️','💦','🍀','🐱','🐶','🦄','🦋','🐝','🐣'] as $emoji): ?>
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
    replyForm.addEventListener('submit', function (ev) {
        var hasText = replyText.value.trim() !== '';
        var hasFile = attachInput.files && attachInput.files.length > 0;
        if (!hasText && !hasFile) {
            alert('Type a reply or attach a file.');
            ev.preventDefault();
        }
    });

    // Lazy-load older messages when the user scrolls to the top of the thread.
    var thread = document.getElementById('chat-thread');
    var convId = <?= (int) ($conversation['id'] ?? 0) ?>;
    var oldestId = <?= !empty($messages) ? (int) $messages[0]['id'] : 0 ?>;
    var hasMore = <?= !empty($hasMore) ? 'true' : 'false' ?>;
    var loadingOlder = false;

    function appendOlderMessage(m) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;gap:.2rem;width:fit-content;max-width:80%;' +
            (m.sender_role === 'user' ? 'align-self:flex-end;align-items:flex-end;' : 'align-self:flex-start;align-items:flex-start;');
        var who = m.sender_role === 'user' ? 'Member' : (m.sender_role === 'model' ? 'AI' : 'Operator');
        var time = (m.created_at || '').replace('T', ' ').substring(0, 16);
        wrap.innerHTML =
            '<small style="font-size:.7rem;opacity:.7;text-transform:uppercase;letter-spacing:.04em;">' + esc(who) + ' &middot; ' + esc(time) + '</small>' +
            '<div style="padding:.3rem .65rem;border-radius:12px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;' +
            (m.sender_role === 'user' ? 'background:var(--purple-600,#9333ea);color:#fff;border-bottom-right-radius:3px;' : 'background:var(--pink-100,#fdf2f8);color:var(--purple-900,#4a044e);border:1px solid var(--pink-300,#f9a8d4);border-bottom-left-radius:3px;') +
            '">' + esc(m.message) +
            (m.attachment_name ? '<div style="margin-top:.5rem;">📎 ' + esc(m.attachment_name) + '</div>' : '') +
            '</div>';
        thread.insertBefore(wrap, thread.firstChild);
    }

    function loadOlder() {
        if (loadingOlder || !hasMore || oldestId <= 0) return;
        loadingOlder = true;
        fetch('<?= url('/admin/chat/history') ?>?conversation=' + convId + '&before=' + oldestId + '&limit=50', {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.messages && res.messages.length) {
                    var prevHeight = thread.scrollHeight;
                    res.messages.forEach(function (m) {
                        if ((m.id || 0) < oldestId || oldestId === 0) appendOlderMessage(m);
                    });
                    oldestId = res.messages[0].id || oldestId;
                    thread.scrollTop += thread.scrollHeight - prevHeight;
                }
                hasMore = !!(res && res.has_more);
            })
            .catch(function () { /* transient; allow retry */ })
            .finally(function () { loadingOlder = false; });
    }

    thread.addEventListener('scroll', function () {
        if (thread.scrollTop < 60) loadOlder();
    });

    // Start scrolled to the bottom so the newest messages are visible.
    setTimeout(function () {
        thread.scrollTop = thread.scrollHeight;
    }, 50);

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
</script>

<p style="margin-top:.75rem;"><a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">Back to conversations</a></p>