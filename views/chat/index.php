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
    <?php if (empty($eligible)): ?>
        <h1>Chat</h1>
        <?php if (trim((string) $dailyMessage) !== ''): ?>
            <div style="border:1px solid var(--pink-300,#f9a8d4);border-radius:var(--card-radius,8px);background:var(--pink-100,#fdf2f8);padding:1rem;margin-bottom:1rem;">
                <strong>Message from the site</strong>
                <p style="margin:.5rem 0 0;white-space:pre-wrap;"><?= e((string) $dailyMessage) ?></p>
            </div>
        <?php endif; ?>
        <p class="muted">Chat is available to members on the <strong>Platinum</strong>, <strong>Yearly</strong>, <strong>Lifetime</strong>, or <strong>Chat add-on</strong> plans. Upgrade in <a href="<?= url('/membership') ?>">Membership</a> to start chatting.</p>
    <?php else: ?>
        <h1>Chat</h1>
        <p class="chat-status">Messages are answered as quickly as possible.</p>

        <div class="chat-thread" id="chat-thread">
            <?php if (empty($messages)): ?>
                <p class="muted">Say hello to start chatting.</p>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="chat-msg <?= e((string) $m['sender_role']) ?>">
                        <?php if ($m['sender_role'] === 'user'): ?>
                            <span class="who">You</span>
                        <?php endif; ?>
                        <?= e((string) $m['message']) ?>
                        <?php if (!empty($m['attachment_name']) && !empty($m['attachment_url'])): ?>
                            <a class="chat-attachment" target="_blank" rel="noopener" href="<?= e($m['attachment_url']) ?>">📎 <?= e($m['attachment_name']) ?></a>
                        <?php endif; ?>
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
        var isUser = msg.sender_role === 'user';
        div.className = 'chat-msg ' + esc(msg.sender_role);
        var html = (isUser ? '<span class="who">You</span>' : '') + esc(msg.message);
        if (msg.attachment_name && msg.attachment_url) {
            html += ' <a class="chat-attachment" target="_blank" rel="noopener" href="' + esc(msg.attachment_url) + '">📎 ' + esc(msg.attachment_name) + '</a>';
        }
        div.innerHTML = html;
        thread.appendChild(div);
        thread.scrollTop = thread.scrollHeight;
    }

    function handleIncoming(messages) {
        if (!messages) { return; }
        messages.forEach(function (m) {
            if ((m.id || 0) > latestId) { append(m); }
            if ((m.id || 0) > latestId) { latestId = m.id; }
        });
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
            })
            .catch(function () { alert('Network error.'); });
    });

    // Real-time push via Server-Sent Events (no manual refresh / polling).
    var es = new EventSource('<?= url('/chat/stream') ?>?since=' + latestId);
    es.onmessage = function (e) {
        try {
            var data = JSON.parse(e.data);
            if (data && data.messages) {
                handleIncoming(data.messages);
                latestId = data.latestId || latestId;
            }
        } catch (err) { /* ignore malformed */ }
    };
    es.onerror = function () {
        // EventSource auto-reconnects; nothing to do here.
    };
})();
</script>