<?php $title = 'Chat'; ?>

<style>
    .chat-page { width: 100%; max-width: none; }
    .chat-thread { display: flex; flex-direction: column; gap: .6rem; padding: 1rem 0; max-height: 60vh; overflow-y: auto; }
    .chat-msg { max-width: 78%; padding: .6rem .9rem; border-radius: 12px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
    .chat-msg.user { align-self: flex-end; background: var(--purple-600, #9333ea); color: #fff; border-bottom-right-radius: 3px; }
    .chat-msg.model, .chat-msg.operator { align-self: flex-start; background: var(--pink-100, #fdf2f8); color: var(--purple-900, #4a044e); border: 1px solid var(--pink-300, #f9a8d4); border-bottom-left-radius: 3px; }
    .chat-msg .who { display: block; font-size: .7rem; opacity: .7; margin-bottom: .15rem; text-transform: uppercase; letter-spacing: .04em; }
    .chat-composer { display: flex; gap: .5rem; margin-top: .75rem; }
    .chat-composer textarea { flex: 1; resize: vertical; }
    .chat-status { font-size: .8rem; color: var(--text-muted, #888); margin: .5rem 0; }
</style>

<div class="chat-page">
    <?php if (!empty($denied)): ?>
        <h1>Chat</h1>
        <p class="muted">Chat is available to members on the <strong>Platinum yearly</strong>, <strong>Lifetime</strong>, or <strong>Chat add-on</strong> plans. Upgrade in <a href="<?= url('/membership') ?>">Membership</a> to start chatting.</p>
    <?php else: ?>
        <h1>Chat</h1>
        <p class="chat-status">
            <?php if (!empty($conversation['ai_mode'])): ?>
                AI mode: <strong><?= e((string) $conversation['ai_mode']) ?></strong>
            <?php endif; ?>
            <?php if (isset($aiStatus) && is_array($aiStatus) && !empty($aiStatus['hasBase'])): ?>
                &middot; <span style="color:var(--success,#2e7d32);">AI online</span>
            <?php elseif (isset($aiStatus) && is_array($aiStatus) && !empty($aiStatus['ok'])): ?>
                &middot; <span style="color:var(--danger,#c62828);">AI model not loaded — an operator will answer</span>
            <?php endif; ?>
        </p>

        <div class="chat-thread" id="chat-thread">
            <?php if (empty($messages)): ?>
                <p class="muted">Say hello to start chatting.</p>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="chat-msg <?= e((string) $m['sender_role']) ?>">
                        <span class="who"><?= $m['sender_role'] === 'user' ? 'You' : (($m['sender_role'] === 'model') ? 'AI' : 'Operator') ?></span>
                        <?= e((string) $m['message']) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form class="chat-composer" id="chat-form">
            <?= csrf_field() ?>
            <textarea id="chat-input" rows="2" maxlength="2000" placeholder="Type a message…"></textarea>
            <button type="submit" class="btn" id="chat-send">Send</button>
        </form>
    <?php endif; ?>
</div>

<script>
(function () {
    var thread = document.getElementById('chat-thread');
    var form = document.getElementById('chat-form');
    var input = document.getElementById('chat-input');
    if (!form || !input) { return; }

    var csrf = document.querySelector('#chat-form input[name="_token"]').value;
    var latestId = <?= (int) ($latestId ?? 0) ?>;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function append(msg) {
        var div = document.createElement('div');
        var who = msg.sender_role === 'user' ? 'You' : (msg.sender_role === 'model' ? 'AI' : 'Operator');
        div.className = 'chat-msg ' + esc(msg.sender_role);
        div.innerHTML = '<span class="who">' + esc(who) + '</span>' + esc(msg.message);
        thread.appendChild(div);
        thread.scrollTop = thread.scrollHeight;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) { return; }
        var body = new FormData();
        body.append('_token', csrf);
        body.append('message', text);
        input.value = '';
        fetch('<?= url('/chat') ?>', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { alert(res.error || 'Could not send.'); input.value = text; return; }
                // server appended user msg + (likely) AI reply; refresh
                poll(true);
            })
            .catch(function () { alert('Network error.'); });
    });

    function poll(force) {
        var url = '<?= url('/chat/messages') ?>?since=' + latestId;
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok || !res.messages) { return; }
                res.messages.forEach(function (m) {
                    if ((m.id || 0) > latestId) { append(m); }
                });
                latestId = res.latestId || latestId;
                if (force) { thread.scrollTop = thread.scrollHeight; }
            })
            .catch(function () { /* transient; retry next tick */ });
    }

    // Poll every 4s so AI/operator responses appear live.
    setInterval(poll, 4000);
    poll(true);
})();
</script>