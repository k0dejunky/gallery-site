<?php $title = 'Chat'; ?>

<div class="chat-page">
            <?php if (!empty($questionnaires)): ?>
                <div class="card u-mb-1">
                    <h2 class="section-title" style="margin-bottom:.5rem;">Questionnaires</h2>
                    <?php foreach ($questionnaires as $q): ?>
                        <?php $qAnswered = !empty($q['answered']); ?>
                        <div id="questionnaire-<?= (int)$q['id'] ?>" style="border:1px solid var(--pink-300,#f9a8d4);border-radius:var(--card-radius,8px);background:var(--pink-100,#fdf2f8);padding:1rem;margin-bottom:.75rem;<?= $qAnswered ? 'opacity:.85;' : '' ?>">
                            <strong><?= e((string)$q['title']) ?></strong>
                            <?php if ($qAnswered): ?>
                                <span style="float:right;font-size:.72rem;font-weight:600;color:#15803d;border:1px solid #86efac;background:#dcfce7;border-radius:999px;padding:2px 10px;">✓ Submitted</span>
                            <?php endif; ?>
                            <?php if (!empty($q['intro'])): ?><p style="margin:.35rem 0 .6rem;white-space:pre-wrap;"><?= e((string)$q['intro']) ?></p><?php endif; ?>

                            <?php if ($qAnswered): ?>
                                <?php foreach ($q['questions'] as $qq): ?>
                                    <?php $qqid=(int)$qq['id']; $my = $q['my_answers'][$qqid] ?? ''; ?>
                                    <div style="margin-bottom:.5rem;">
                                        <label style="display:block;font-weight:600;margin-bottom:.25rem;"><?= e((string)$qq['prompt']) ?></label>
                                        <?php if ($qq['qtype']==='text'): ?>
                                            <textarea rows="2" disabled class="chat-answer"><?= e((string)$my) ?></textarea>
                                        <?php elseif ($qq['qtype']==='choice' || $qq['qtype']==='rating'): ?>
                                            <?php if ($qq['qtype']==='choice'): $opts=$qq['options_decoded']??[]; else: $opts=$qq['options_decoded']??['min'=>1,'max'=>5]; $tmp=[]; for($r=(int)$opts['min'];$r<=(int)$opts['max'];$r++){ $tmp[]=$r; } $opts=$tmp; endif; ?>
                                            <?php foreach ($opts as $opt): ?>
                                                <label style="display:inline-flex;align-items:center;gap:.3rem;margin-right:.75rem;"><input type="radio" disabled <?= (string)$my === (string)$opt ? 'checked' : '' ?>> <?= e((string)$opt) ?></label>
                                            <?php endforeach; ?>
                                        <?php elseif ($qq['qtype']==='multichoice'): ?>
                                            <?php $vals=json_decode((string)$my,true); if(!is_array($vals)){$vals=array_filter([(string)$my]);} ?>
                                            <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                                <label style="display:inline-flex;align-items:center;gap:.3rem;margin-right:.75rem;"><input type="checkbox" disabled <?= in_array((string)$opt, array_map('strval',$vals), true) ? 'checked' : '' ?>> <?= e((string)$opt) ?></label>
                                            <?php endforeach; ?>
                                        <?php elseif ($qq['qtype']==='number'): ?>
                                            <input type="text" disabled value="<?= e((string)$my) ?>" class="chat-answer">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <p class="muted u-m0"><strong>Submitted.</strong> You can view your answers above; they can't be changed.</p>
                            <?php elseif ((int)$q['allow_replies'] === 1): ?>
                                <form method="post" action="<?= url('/chat/questionnaire/' . (int)$q['id'] . '/answer') ?>" style="display:flex;flex-direction:column;gap:.5rem;">
                                    <?= csrf_field() ?>
                                    <?php foreach ($q['questions'] as $qq): ?>
                                        <?php $qqid=(int)$qq['id']; $key='q'.$qqid; ?>
                                        <div>
                                            <label style="display:block;font-weight:600;margin-bottom:.25rem;"><?= e((string)$qq['prompt']) ?><?= (int)$qq['required']===1?'*':'' ?></label>
                                            <?php if ($qq['qtype']==='text'): ?>
                                                <textarea name="answers[<?= $key ?>]" rows="2" aria-label="<?= e((string)$qq['prompt']) ?>" <?= (int)$qq['required']===1?'required':'' ?>></textarea>
                                            <?php elseif ($qq['qtype']==='choice'): ?>
                                                <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                                    <label style="display:inline-flex;align-items:center;gap:.3rem;margin-right:.75rem;"><input type="radio" name="answers[<?= $key ?>]" value="<?= e((string)$opt) ?>" <?= (int)$qq['required']===1?'required':'' ?>> <?= e((string)$opt) ?></label>
                                                <?php endforeach; ?>
                                            <?php elseif ($qq['qtype']==='multichoice'): ?>
                                                <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                                    <label style="display:inline-flex;align-items:center;gap:.3rem;margin-right:.75rem;"><input type="checkbox" name="answers[<?= $key ?>][]" value="<?= e((string)$opt) ?>"> <?= e((string)$opt) ?></label>
                                                <?php endforeach; ?>
                                            <?php elseif ($qq['qtype']==='rating'): ?>
                                                <?php $opts=$qq['options_decoded']??['min'=>1,'max'=>5]; $min=(int)$opts['min']; $max=(int)$opts['max']; ?>
                                                <?php for ($r=$min;$r<=$max;$r++): ?>
                                                    <label style="display:inline-flex;align-items:center;gap:.2rem;margin-right:.5rem;"><input type="radio" name="answers[<?= $key ?>]" value="<?= $r ?>" <?= (int)$qq['required']===1?'required':'' ?>> <?= $r ?></label>
                                                <?php endfor; ?>
                                            <?php elseif ($qq['qtype']==='number'): ?>
                                                <input type="number" name="answers[<?= $key ?>]" aria-label="<?= e((string)$qq['prompt']) ?>" <?= (int)$qq['required']===1?'required':'' ?>>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <div><button type="submit" class="btn btn-sm">Submit answers</button></div>
                                </form>
                            <?php else: ?>
                                <p class="muted">This questionnaire is closed for new replies.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

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

        <p class="chat-status">
            <?php if (!empty($operatorOnline)): ?>
                <span style="color:#15803d;">&#9679; Amethyst is online — you'll get a reply shortly.</span>
            <?php else: ?>
                <span style="color:#b45309;">&#9679; Amethyst is away right now — messages are answered automatically and personally as soon as possible.</span>
            <?php endif; ?>
        </p>

        <?php /* role=log + aria-live: a new message (it arrives over SSE) must
            be announced - before this the thread was a silent region and a
            member only found the reply by scrolling. */ ?>
        <div class="chat-thread" id="chat-thread" role="log" aria-live="polite" aria-relevant="additions" aria-label="Conversation with the studio">
            <?php if (empty($messages)): ?>
                <div class="chat-empty empty-state" style="margin:1rem auto;max-width:420px;text-align:center;">
                    <p class="muted u-mt-0">No messages yet. Say hello to start chatting.</p>
                    <button type="button" class="btn btn-sm" data-action="chatFocusInput">Say hello</button>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="chat-msg <?= e((string) $m['sender_role']) ?>"<?= !empty($m['content_refs']) ? ' data-refs="' . e(json_encode($m['content_refs'], JSON_UNESCAPED_SLASHES)) . '"' : '' ?>>
                        <?php if ($m['sender_role'] === 'user'): ?>
                            <span class="who">You</span>
                        <?php endif; ?>
                        <?= e((string) $m['message']) ?>
                        <?php if (!empty($m['attachment_name'])): ?>
                            <?php if (empty($m['attachment_url'])): ?>
                                <span class="chat-attachment chat-expired">🔒 Media expired</span>
                            <?php elseif (!empty($m['attachment_thumb_url']) && str_starts_with((string) $m['attachment_type'], 'image/')): ?>
                                <a href="<?= e($m['attachment_url']) ?>" target="_blank" rel="noopener" style="display:inline-block;margin-top:.4rem;" title="Open full image">
                                    <img class="chat-image" loading="lazy" src="<?= e($m['attachment_thumb_url']) ?>" alt="<?= e($m['attachment_name']) ?>" draggable="false" data-nocontext>
                                </a>
                            <?php else: ?>
                                <span class="chat-attachment">📎 <?= e($m['attachment_name']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($replyEnabled)): ?>
        <form class="chat-composer" id="chat-form">
            <?= csrf_field() ?>
            <textarea id="chat-input" rows="2" maxlength="2000" placeholder="Type a message…" aria-label="Message"></textarea>
            <label class="btn btn-outline" for="chat-file" title="Attach a file (images, video, audio, PDF, text; max 25 MB)" style="display:flex;align-items:center;">📎</label>
            <input type="file" id="chat-file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mp3,.ogg,.oga,.wav,.pdf,.txt" style="display:none;" aria-label="Attach a file">
            <button type="submit" class="btn" id="chat-send">Send</button>
        </form>
        <p class="muted" id="chat-file-name" style="display:none;margin:.35rem 0 0;"></p>
        <?php else: ?>
        <p class="muted">Replies are currently disabled for this conversation. Messages from the site will still appear here. If this looks wrong, <a href="<?= e(url('/support')) ?>">contact support</a>.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var thread = document.getElementById('chat-thread');
    var form = document.getElementById('chat-form');
    var input = document.getElementById('chat-input');
    var fileInput = document.getElementById('chat-file');
    var fileName = document.getElementById('chat-file-name');
    if (!form || !input) { return; }

    if (fileInput && fileName) {
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0];
            if (f) {
                fileName.textContent = '📎 ' + f.name + ' (' + Math.max(1, Math.round(f.size / 1048576)) + ' MB)';
                fileName.style.display = '';
            } else {
                fileName.textContent = '';
                fileName.style.display = 'none';
            }
        });
    }

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

    // Turn exact gallery titles mentioned in a message into clickable links
    // (longest title first, so a title that is a substring of another still
    // resolves correctly). Refs come from the server; nothing is invented here.
    function linkRefs(el, refs) {
        if (!el || !refs || !refs.length) return;
        var html = el.innerHTML;
        var linked = refs.slice().sort(function (a, b) {
            return String(b.title || '').length - String(a.title || '').length;
        });
        linked.forEach(function (r) {
            if (!r.title || !r.url) return;
            var t = esc(r.title);
            var re = new RegExp(t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
            html = html.replace(re, '<a class="chat-ref" href="' + esc(r.url) + '" target="_blank" rel="noopener">$&</a>');
        });
        el.innerHTML = html;
    }

    function append(msg, atTop) {
        var div = document.createElement('div');
        var isUser = msg.sender_role === 'user';
        div.className = 'chat-msg ' + esc(msg.sender_role);
        var html = (isUser ? '<span class="who">You</span>' : '') + esc(msg.message);
        if (msg.attachment_name) {
            if (msg.media_expired || !msg.attachment_url) {
                html += ' <span class="chat-attachment chat-expired">🔒 Media expired</span>';
            } else if (msg.attachment_thumb_url) {
                html += ' <a href="' + esc(msg.attachment_url) + '" target="_blank" rel="noopener" style="display:inline-block;margin-top:.4rem;" title="Open full image">' +
                    ' <img class="chat-image" loading="lazy" src="' + esc(msg.attachment_thumb_url) + '" alt="' + esc(msg.attachment_name) + '" draggable="false" oncontextmenu="return false"></a>';
            } else {
                html += ' <span class="chat-attachment">📎 ' + esc(msg.attachment_name) + '</span>';
            }
        }
        div.innerHTML = html;
        linkRefs(div, msg.content_refs || []);
        if (atTop) {
            thread.insertBefore(div, thread.firstChild);
        } else {
            thread.appendChild(div);
            thread.scrollTop = thread.scrollHeight;
        }
        return div;
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
                var echoKey = String(m.message || '').trim() || (m.attachment_name ? '\u0000attach' : '');
                var idx = echoKey === '' ? -1 : pendingSends.indexOf(echoKey);
                if (idx >= 0) {
                    pendingSends.splice(idx, 1);
                    latestId = Math.max(latestId, m.id);
                    return;
                }
            }
            if ((m.id || 0) > latestId) {
                append(m);
                latestId = m.id;
            }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        var file = (fileInput && fileInput.files && fileInput.files[0]) ? fileInput.files[0] : null;
        if (!text && !file) { return; }
        if (file && file.size > 25 * 1024 * 1024) {
            alert('Attachments must be 25 MB or smaller.');
            return;
        }
        var body = new FormData();
        body.append('_token', csrf);
        body.append('message', text);
        if (file) { body.append('attachment', file); }
        input.value = '';
        if (fileInput) { fileInput.value = ''; }
        if (fileName) { fileName.textContent = ''; fileName.style.display = 'none'; }
        // Optimistic: show your message immediately, don't wait for the POST.
        var optimistic = append({ id: 0, sender_role: 'user', message: text, attachment_name: file ? file.name : null, attachment_url: null, attachment_thumb_url: null });
        pendingSends.push(text || '\u0000attach');
hideBadge();

    // Start scrolled to the bottom so the newest messages are visible.
    setTimeout(function () {
        thread.scrollTop = thread.scrollHeight;
    }, 50);
        fetch('<?= url('/chat') ?>', { method: 'POST', body: body })
            .then(function (r) {
                if (!r.ok) { throw new Error('send-failed'); }
                return r.json();
            })
            .then(function (res) {
                if (!res.ok) { throw new Error(res.error || 'Could not send.'); }
            })
            .catch(function (err) {
                alert(err && err.message && err.message !== 'send-failed' ? err.message : 'Could not send.');
                if (optimistic && optimistic.parentNode) { optimistic.parentNode.removeChild(optimistic); }
                var pi = pendingSends.indexOf(text);
                if (pi >= 0) { pendingSends.splice(pi, 1); }
                input.value = text;
            });
    });

    // Typing presence: ping the server (throttled to every 3 s) while the
    // member writes, so the operator app can show "Member is typing…".
    var lastTypingPing = 0;
    input.addEventListener('input', function () {
        var now = Date.now();
        if (now - lastTypingPing < 3000 || !input.value) { return; }
        lastTypingPing = now;
        var ping = new FormData();
        ping.append('_token', csrf);
        fetch('<?= url('/chat/typing') ?>', { method: 'POST', body: ping }).catch(function () {});
    });

    // Enter sends the message; Shift+Enter inserts a newline. The isComposing
    // guard keeps CJK/IME Enter-to-confirm from firing a send.
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    // Clear the sidebar unread badge once the member is reading the chat.
    function hideBadge() {
        document.querySelectorAll('.nav-item[href$="/chat"] .nav-unread, .nav-unread').forEach(function (b) {
            if (b && b.closest('a') && /\/chat$/.test(b.closest('a').getAttribute('href') || '')) { b.remove(); }
        });
    }
hideBadge();

    // Link gallery titles already rendered server-side (the AI reply refs
    // are carried in data-refs so they survive a page reload).
    Array.prototype.forEach.call(thread.querySelectorAll('.chat-msg'), function (bubble) {
        var refs = [];
        try { refs = JSON.parse(bubble.getAttribute('data-refs') || '[]'); } catch (err) { refs = []; }
        linkRefs(bubble, refs);
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