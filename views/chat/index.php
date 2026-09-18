<?php $title = 'Chat'; ?>

<style>
    .chat-page { width: 100%; max-width: none; }
    .chat-thread { display: flex; flex-direction: column; gap: .6rem; padding: 1rem 0; max-height: 60vh; overflow-y: auto; }
    .chat-msg { width: fit-content; max-width: 78%; padding: .3rem .65rem; border-radius: 12px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word; }
    .chat-msg.user { align-self: flex-end; background: var(--purple-600, #9333ea); color: #fff; border-bottom-right-radius: 3px; }
    .chat-msg.model, .chat-msg.operator { align-self: flex-start; background: var(--pink-100, #fdf2f8); color: var(--purple-900, #4a044e); border: 1px solid var(--pink-300, #f9a8d4); border-bottom-left-radius: 3px; }
    .chat-msg .who { display: block; font-size: .7rem; opacity: .7; margin-bottom: .15rem; text-transform: uppercase; letter-spacing: .04em; }
    .chat-attachment { display: inline-block; margin-top: .45rem; color: inherit; text-decoration: none; font-size: .85rem; }
    .chat-image { display: block; max-width: 100%; max-height: 420px; width: auto; height: auto; margin-top: .5rem; border-radius: 10px; border: 1px solid rgba(0,0,0,.08); background: #fff; object-fit: contain; user-select: none; -webkit-user-drag: none; pointer-events: none; }
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
                            <?php if (!empty($m['attachment_thumb_url']) && str_starts_with((string) $m['attachment_type'], 'image/')): ?>
                                <img class="chat-image" loading="lazy" src="<?= e($m['attachment_url']) ?>" alt="<?= e($m['attachment_name']) ?>" draggable="false" oncontextmenu="return false">
                            <?php else: ?>
                                <span class="chat-attachment">📎 <?= e($m['attachment_name']) ?></span>
                            <?php endif; ?>
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
    // Messages we optimistically rendered that the stream has not yet echoed.
    var pendingSends = [];
    // Oldest message id currently rendered; used for lazy scroll-up loading.
    var oldestId = <?= !empty($messages) ? (int) $messages[0]['id'] : 0 ?>;
    var hasMore = <?= !empty($hasMore) ? 'true' : 'false' ?>;
    var loadingOlder = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function append(msg, atTop) {
        var div = document.createElement('div');
        var isUser = msg.sender_role === 'user';
        div.className = 'chat-msg ' + esc(msg.sender_role);
        var html = (isUser ? '<span class="who">You</span>' : '') + esc(msg.message);
        if (msg.attachment_name && msg.attachment_url) {
            if (msg.attachment_thumb_url) {
                html += ' <img class="chat-image" loading="lazy" src="' + esc(msg.attachment_url) + '" alt="' + esc(msg.attachment_name) + '" draggable="false" oncontextmenu="return false">';
            } else {
                html += ' <span class="chat-attachment">📎 ' + esc(msg.attachment_name) + '</span>';
            }
        }
        div.innerHTML = html;
        if (atTop) {
            thread.insertBefore(div, thread.firstChild);
        } else {
            thread.appendChild(div);
            thread.scrollTop = thread.scrollHeight;
        }
    }

    /** Prepend a batch of older messages, keeping the scroll position stable. */
    function prependOlder(messages) {
        if (!messages || messages.length === 0) return;
        var prevHeight = thread.scrollHeight;
        var first = thread.firstChild;
        messages.forEach(function (m) {
            if ((m.id || 0) < oldestId || oldestId === 0) {
                append(m, true);
            }
        });
        oldestId = messages[0].id || oldestId;
        // Restore scroll so the view doesn't jump.
        thread.scrollTop += thread.scrollHeight - prevHeight;
        return first;
    }

    /** Lazy-load older messages when the user scrolls to the top. */
    function loadOlder() {
        if (loadingOlder || !hasMore || oldestId <= 0) return;
        loadingOlder = true;
        fetch('<?= url('/chat/history') ?>?before=' + oldestId + '&limit=50', {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.messages && res.messages.length) {
                    prependOlder(res.messages);
                }
                hasMore = !!(res && res.has_more);
            })
            .catch(function () { /* transient; allow retry */ })
            .finally(function () { loadingOlder = false; });
    }

    thread.addEventListener('scroll', function () {
        if (thread.scrollTop < 60) loadOlder();
    });

    function handleIncoming(messages) {
        if (!messages) { return; }
        messages.forEach(function (m) {
            // Skip the stream echo of a message we already rendered optimistically.
            if (m.sender_role === 'user') {
                var idx = pendingSends.indexOf(m.message);
                if (idx >= 0) {
                    pendingSends.splice(idx, 1);
                    latestId = Math.max(latestId, m.id);
                    return;
                }
            }
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
        // Optimistic: show your message immediately, don't wait for the POST.
        append({ id: 0, sender_role: 'user', message: text, attachment_name: null, attachment_url: null, attachment_thumb_url: null });
        pendingSends.push(text);
        hideBadge();
        fetch('<?= url('/chat') ?>', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { alert(res.error || 'Could not send.'); input.value = text; return; }
            })
            .catch(function () { alert('Network error.'); });
    });

    // Clear the sidebar unread badge once the member is reading the chat.
    function hideBadge() {
        document.querySelectorAll('.nav-item[href$="/chat"] .nav-unread, .nav-unread').forEach(function (b) {
            if (b && b.closest('a') && /\/chat$/.test(b.closest('a').getAttribute('href') || '')) { b.remove(); }
        });
    }
    hideBadge();

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