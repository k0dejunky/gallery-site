<?php $title = 'Chat'; ?>

<style>
    .chat-page { width: 100%; max-width: none; }
    .chat-thread { display: flex; flex-direction: column; gap: .6rem; padding: 1rem 0; max-height: 60vh; overflow-y: auto; }
    .chat-msg { max-width: 78%; padding: .6rem .9rem; border-radius: 12px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
    .chat-msg.user { align-self: flex-end; background: var(--purple-600, #9333ea); color: #fff; border-bottom-right-radius: 3px; }
    .chat-msg.model, .chat-msg.operator { align-self: flex-start; background: var(--pink-100, #fdf2f8); color: var(--purple-900, #4a044e); border: 1px solid var(--pink-300, #f9a8d4); border-bottom-left-radius: 3px; }
    .chat-msg .who { display: block; font-size: .7rem; opacity: .7; margin-bottom: .15rem; text-transform: uppercase; letter-spacing: .04em; }
    .chat-attachment { display: inline-block; margin-top: .45rem; color: inherit; text-decoration: none; font-size: .85rem; }
    .chat-attachment:hover { text-decoration: underline; }
    .chat-image { display: block; border-radius: 10px; overflow: hidden; border: 1px solid rgba(0,0,0,.08); background: #fff; }
    .chat-image img { display: block; max-width: 220px; max-height: 220px; width: auto; height: auto; object-fit: cover; }
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
                                <a class="chat-attachment chat-image" target="_blank" rel="noopener" href="<?= e($m['attachment_url']) ?>">
                                    <img loading="lazy" src="<?= e($m['attachment_thumb_url']) ?>" alt="<?= e($m['attachment_name']) ?>">
                                </a>
                            <?php else: ?>
                                <a class="chat-attachment" target="_blank" rel="noopener" href="<?= e($m['attachment_url']) ?>">📎 <?= e($m['attachment_name']) ?></a>
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
            if (msg.attachment_thumb_url) {
                html += ' <a class="chat-attachment chat-image" target="_blank" rel="noopener" href="' + esc(msg.attachment_url) + '"><img loading="lazy" src="' + esc(msg.attachment_thumb_url) + '" alt="' + esc(msg.attachment_name) + '"></a>';
            } else {
                html += ' <a class="chat-attachment" target="_blank" rel="noopener" href="' + esc(msg.attachment_url) + '">📎 ' + esc(msg.attachment_name) + '</a>';
            }
        }
        div.innerHTML = html;
        thread.appendChild(div);
        thread.scrollTop = thread.scrollHeight;
    }

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

    // Lightbox: click any image thumbnail to view it full size.
    function openLightbox(src, name) {
        var old = document.getElementById('chat-lightbox');
        if (old) { old.remove(); }
        var box = document.createElement('div');
        box.id = 'chat-lightbox';
        box.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.88);display:flex;align-items:center;justify-content:center;cursor:zoom-out;padding:24px;';
        var img = document.createElement('img');
        img.src = src;
        img.alt = name || '';
        img.style.cssText = 'max-width:100%;max-height:100%;border-radius:6px;box-shadow:0 6px 30px rgba(0,0,0,.5);';
        box.appendChild(img);
        box.addEventListener('click', function () { box.remove(); });
        document.body.appendChild(box);
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a.chat-image');
        if (link) {
            e.preventDefault();
            openLightbox(link.getAttribute('href'), link.querySelector('img') && link.querySelector('img').alt);
        }
    });
})();
</script>