<?php $title = 'Chat Admin'; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <h1 style="margin:0;">Chat</h1>
    <div>
        <span class="muted" style="font-size:.85rem;">
            AI master switch: <strong><?= !empty($state['ai_enabled']) ? 'ON' : 'OFF' ?></strong> &middot;
            Training: <strong><?= (int) ($trainingCount ?? 0) ?></strong> pairs (<?= (int) ($cleanedCount ?? 0) ?> cleaned) &middot;
            <span id="waiting-badge">Waiting to be trained: <strong>…</strong></span> &middot;
            <?php if (!empty($ai['hasBase'])): ?>AI base online<?php else: ?>AI base <span style="color:var(--danger,#c62828);">offline</span><?php endif; ?>
            <?php if (!empty($ai['hasFine'])): ?> &middot; fine-tuned loaded (<?= e((string) $finetunedModel) ?>)<?php elseif (!empty($adapterInstalled)): ?> &middot; adapter installed — model <span style="color:var(--danger,#c62828);">not built yet</span><?php else: ?> &middot; no adapter installed (retrieval mode only)<?php endif; ?>
        </span>
        <form class="inline" method="post" action="<?= url('/admin/chat/ai-toggle') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm <?= !empty($state['ai_enabled']) ? '' : 'btn-outline' ?>"><?= !empty($state['ai_enabled']) ? 'Turn AI off' : 'Turn AI on' ?></button>
        </form>
        <?php if (!empty($latestApk['file'])): ?>
            <a class="btn btn-sm" href="<?= url('/assets/apk/' . e($latestApk['file'])) ?>" download>Download operator app (Android APK v<?= e($latestApk['version']) ?>)</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat/export-training') ?>" onclick="return confirm('Write the cleaned training export?');">Export training</a>
    <div style="margin-top:.5rem;">
        <span class="muted" style="font-size:.85rem;">
            Amethyst chat:
            <?php if (!empty($operatorOnline)): ?>
                <strong style="color:#15803d;">&#9679; Amethyst is online</strong>
            <?php else: ?>
                <strong style="color:#b45309;">&#9679; Amethyst is away</strong>
            <?php endif; ?>
            &middot; while you're on this page you're marked online (members get the away auto-response when nobody is).
        </span>
    </div>
</div>

<?php // Training-corpus import: paste pairs or upload a file. Pairs are cleaned,
// junk-filtered and stored as cleaned so the training PC picks them up. ?>
<div class="card" style="border-left:4px solid var(--purple-500);padding:1rem;margin-bottom:1.25rem;">
    <h2 class="section-title">Import training pairs</h2>
    <p class="muted" style="margin-top:0;">Add operator-style reply pairs for the AI trainer. Data is cleaned on import (emails/phones stripped, whitespace normalized, junk filtered) and marked ready — the training PC picks it up on its next poll (needs &ge;20 new pairs). Formats: JSONL, CSV/TSV, or Q:/A: lines.</p>
    <form method="post" action="<?= url('/admin/chat/import-training') ?>" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:.5rem;">
        <?= csrf_field() ?>
        <textarea name="training_text" rows="6" placeholder="Q: hi babe&#10;A: hey handsome, welcome back 😘&#10;&#10;or paste JSONL lines..."></textarea>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <input type="file" name="training_file" accept=".jsonl,.csv,.tsv,.txt" style="flex:1;min-width:180px;">
            <button type="submit" class="btn btn-sm">Import pairs</button>
        </div>
    </form>
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
                        <td class="muted"><?= !empty($t['expires_at']) ? e(tzdate('M j, Y', (string) $t['expires_at'])) : 'never' ?></td>
                        <td class="muted"><?= !empty($t['last_used_at']) ? e(tzdate('M j, Y g:i A', (string) $t['last_used_at'])) : 'never' ?></td>
                        <td class="muted"><?= e((string) ($t['created_by_email'] ?? '—')) ?></td>
                        <td><span class="pill <?= $revoked ? 'pill-muted' : '' ?>"><?= $revoked ? 'revoked' : 'active' ?></span></td>
                        <td style="text-align:right;">
                            <?php if (!$revoked): ?>
                                <form class="inline" method="post" action="<?= url('/admin/chat/tokens/' . (int) $t['id'] . '/revoke') ?>" onsubmit="return confirm('Revoke token &quot;<?= e((string) $t['label']) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                </form>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var badge = document.getElementById('waiting-badge');
    if (!badge) return;
    function refresh() {
        fetch('<?= url('/admin/chat/training-count') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                var n = d.waiting;
                badge.innerHTML = 'Waiting to be trained: <strong style="color:' +
                    (n > 0 ? 'var(--warn,#ffb454)' : 'var(--ok,#3ddc84)') + '">' + n + '</strong>';
            })
            .catch(function () {});
    }
    refresh();
    setInterval(refresh, 5000);
})();
</script>
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
    <input type="hidden" name="save_section" value="ai">
    <label class="muted" style="font-size:.85rem;">Default mode for <strong>new</strong> conversations (existing ones keep their own mode):</label>
    <select name="default_ai_mode">
        <option value="retrieval" <?= ($state['default_ai_mode'] ?? 'retrieval') === 'retrieval' ? 'selected' : '' ?>>Retrieval (AI, few-shot over operator replies)</option>
        <option value="finetuned" <?= ($state['default_ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned (AI, LoRA adapter)</option>
        <option value="operator" <?= ($state['default_ai_mode'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator only (no AI)</option>
    </select>
    <label style="font-size:.85rem;display:inline-flex;align-items:center;gap:.3rem;">
        <input type="checkbox" name="ai_content_search" value="1"<?= !empty($state['ai_content_search']) ? ' checked' : '' ?>>
        AI searches site galleries to answer content questions
    </label>
    <label class="muted" style="font-size:.85rem;">max
        <input type="number" name="ai_content_search_max" min="1" max="12" value="<?= (int) ($state['ai_content_search_max'] ?? 6) ?>" style="width:4rem;">
    </label>
    <button type="submit" class="btn btn-sm">Save default</button>
</form>

<?php // Live chat moderation: site-wide word filter + member mutes ?>
<section class="card" style="margin:1rem 0;padding:1rem;">
    <h2 class="section-title">Live chat moderation</h2>
    <p class="muted" style="font-size:.85rem;margin-bottom:.75rem;">Site-wide word filter for the live group chat (members only; the operator is never filtered). One word per line, or comma-separated. Messages containing a filtered word are rejected.</p>
    <form method="post" action="<?= url('/admin/chat/settings') ?>" style="display:flex;flex-direction:column;gap:.5rem;max-width:640px;margin-bottom:1.25rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="save_section" value="wordfilter">
        <textarea name="live_chat_filters" rows="4" placeholder="e.g. scam&#10;onlyfans&#10;spam"><?= e(implode("\n", \App\Core\ChatSettings::liveChatFilters())) ?></textarea>
        <div><button type="submit" class="btn btn-sm">Save word filter</button></div>
    </form>
    <div style="display:flex;flex-wrap:wrap;gap:2rem;">
        <form method="post" action="<?= url('/admin/chat/mute') ?>" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <label class="muted" style="font-size:.85rem;">Mute member from live chat</label>
            <input type="email" name="email" required placeholder="member@example.com" style="max-width:16rem;">
            <input type="number" name="hours" min="1" max="168" value="24" style="width:5rem;" title="Hours">
            <button type="submit" class="btn btn-sm btn-outline">Mute</button>
        </form>
        <form method="post" action="<?= url('/admin/chat/unmute') ?>" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <label class="muted" style="font-size:.85rem;">Lift a mute</label>
            <input type="email" name="email" placeholder="member@example.com" style="max-width:16rem;">
            <button type="submit" class="btn btn-sm btn-outline">Unmute</button>
        </form>
    </div>
    <?php if (!empty($liveChatMutes)): ?>
        <table style="width:100%;max-width:640px;margin-top:.75rem;font-size:.85rem;">
            <thead><tr><th style="text-align:left;">Email</th><th style="text-align:left;">Muted until (site time)</th></tr></thead>
            <tbody>
                <?php foreach ($liveChatMutes as $m): ?>
                    <tr>
                        <td><?= e((string) $m['email']) ?></td>
                        <td><?= e($m['chat_muted_until'] ? tzdate('Y-m-d H:i', $m['chat_muted_until']) : '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php // Daily broadcast to users without the chat feature ?>
<form method="post" action="<?= url('/admin/chat/daily-message') ?>" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;max-width:640px;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Daily message to users without the chat feature (shown on their Chat page; they cannot reply until they add the chat plan):</label>
    <textarea name="daily_message" rows="3" maxlength="5000" placeholder="e.g. Chat is available on Platinum, Yearly, Lifetime, or the Chat add-on plan."><?= e((string) ($state['daily_message'] ?? '')) ?></textarea>
    <div><button type="submit" class="btn btn-sm">Save daily message</button></div>
</form>

<?php // Default/away auto-response when the operator chat is not online ?>
<form method="post" action="<?= url('/admin/chat/away-message') ?>" style="display:flex;flex-direction:column;gap:.35rem;margin-bottom:1rem;max-width:640px;">
    <?= csrf_field() ?>
    <label class="muted" style="font-size:.85rem;">Away auto-response (default message) — sent to members automatically when the operator chat is not online. Leave empty to disable:</label>
    <textarea name="away_message" rows="3" maxlength="5000" placeholder="e.g. Thanks for your message! I'm away right now and will reply as soon as I'm back."><?= e((string) ($awayMessage ?? '')) ?></textarea>
    <div><button type="submit" class="btn btn-sm">Save away message</button></div>
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

<?php // Questionnaire: a structured multi-question form sent to EVERY registered user ?>
<section class="card" style="border-left:4px solid var(--purple-500);padding:1rem;margin-bottom:1.25rem;">
    <h2 class="section-title">Send a questionnaire to all users</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0;">Delivered to every registered user (not just chat-plan members). When replies are ON, users can answer on their Chat page; replies are scoped to this questionnaire only.</p>
    <form method="post" action="<?= url('/admin/chat/questionnaire') ?>" id="questionnaire-form" style="display:flex;flex-direction:column;gap:.5rem;max-width:720px;">
        <?= csrf_field() ?>
        <input type="text" name="title" placeholder="Questionnaire title" maxlength="200" required>
        <textarea name="intro" rows="2" maxlength="5000" placeholder="Intro / context shown above the questions (optional)"></textarea>

        <div id="question-rows" style="display:flex;flex-direction:column;gap:.6rem;"></div>
        <div><button type="button" class="btn btn-sm btn-outline" id="add-question">+ Add question</button></div>

        <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.9rem;">
            <input type="checkbox" name="allow_replies" value="1" checked> Allow replies (users can answer this questionnaire)
        </label>

        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <input type="datetime-local" name="scheduled_at">
            <button type="submit" name="action" value="now" class="btn btn-sm">Send now</button>
            <button type="submit" name="action" value="schedule" class="btn btn-sm btn-outline">Schedule</button>
        </div>
    </form>
</section>

<?php if (!empty($questionnaires)): ?>
    <h3>Questionnaires</h3>
    <table>
        <thead>
            <tr><th>#</th><th>Title</th><th>Status</th><th>Replies</th><th>Notified</th><th>Scheduled</th><th>Sent</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($questionnaires as $q): ?>
                <tr>
                    <td>#<?= (int) $q['id'] ?></td>
                    <td style="max-width:280px;"><?= e(mb_strimwidth((string) $q['title'], 0, 70, '…')) ?></td>
                    <td><span class="pill <?= $q['status'] === 'sent' ? '' : ($q['status'] === 'partial' ? 'pill-warn' : ($q['status'] === 'cancelled' ? 'pill-muted' : 'pill-info')) ?>"><?= e((string) $q['status']) ?></span></td>
                    <td><?= (int) $q['allow_replies'] === 1 ? 'ON' : 'OFF' ?></td>
                    <td><?= (int) $q['notified_count'] ?> / <?= (int) $q['recipients'] ?></td>
                    <td class="muted"><?= !empty($q['scheduled_at']) ? e(tzdate('M j, Y H:i', (string) $q['scheduled_at'])) : '&mdash;' ?></td>
                    <td class="muted"><?= !empty($q['sent_at']) ? e(tzdate('M j, Y H:i', (string) $q['sent_at'])) : '&mdash;' ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat/questionnaire/' . (int) $q['id']) ?>">Results</a>
                        <?php if (in_array($q['status'], ['draft', 'scheduled', 'failed'], true)): ?>
                            <form class="inline" method="post" action="<?= url('/admin/chat/questionnaire/' . (int) $q['id'] . '/send') ?>" onsubmit="return confirm('Send this questionnaire now?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Send now</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($q['status'], ['draft', 'scheduled', 'sending'], true)): ?>
                            <form class="inline" method="post" action="<?= url('/admin/chat/questionnaire/' . (int) $q['id'] . '/cancel') ?>" onsubmit="return confirm('Cancel this questionnaire?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var rows = document.getElementById('question-rows');
    var add = document.getElementById('add-question');
    if (!rows || !add) return;
    var idx = 0;
    function addRow() {
        var n = idx++;
        var wrap = document.createElement('div');
        wrap.style.cssText = 'border:1px solid var(--border,#ddd);border-radius:8px;padding:.5rem;display:flex;flex-direction:column;gap:.35rem;';
        wrap.innerHTML =
            '<div style="display:flex;gap:.4rem;align-items:center;">' +
                '<input type="text" name="q[' + n + '][prompt]" placeholder="Question prompt" required style="flex:1;">' +
                '<select name="q[' + n + '][qtype]">' +
                    '<option value="text">Text</option>' +
                    '<option value="choice">Single choice</option>' +
                    '<option value="multichoice">Multi-select</option>' +
                    '<option value="rating">Rating</option>' +
                    '<option value="number">Number</option>' +
                '</select>' +
                '<label style="font-size:.8rem;display:inline-flex;align-items:center;gap:.2rem;"><input type="checkbox" name="q[' + n + '][required]" value="1" checked> req</label>' +
                '<button type="button" class="btn btn-sm btn-danger" data-remove>×</button>' +
            '</div>' +
            '<input type="text" name="q[' + n + '][options]" placeholder="Options for choice/multi-select, comma-separated (rating defaults 1-5)" style="display:none;">' +
            '<input type="number" name="q[' + n + '][min]" value="1" min="1" style="display:none;width:5rem;" title="Rating min">' +
            '<input type="number" name="q[' + n + '][max]" value="5" min="1" style="display:none;width:5rem;" title="Rating max">';
        var sel = wrap.querySelector('select');
        var opts = wrap.querySelector('input[name$="[options]"]');
        var mn = wrap.querySelector('input[name$="[min]"]');
        var mx = wrap.querySelector('input[name$="[max]"]');
        function sync() {
            var v = sel.value;
            opts.style.display = (v === 'choice' || v === 'multichoice') ? '' : 'none';
            mn.style.display = mx.style.display = (v === 'rating') ? '' : 'none';
        }
        sel.addEventListener('change', sync);
        sync();
        wrap.querySelector('[data-remove]').addEventListener('click', function () { wrap.remove(); });
        rows.appendChild(wrap);
    }
    add.addEventListener('click', addRow);
    addRow();
})();
</script>

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

<script nonce="<?= csp_nonce() ?>">
// Presence heartbeat: while this admin chat page is open, the operator is
// marked online so members don't get the away auto-response.
(function () {
    var url = '<?= e(url('/admin/chat/heartbeat')) ?>';
    var csrf = document.querySelector('input[name="_token"]');
    function beat() {
        var body = new FormData();
        if (csrf) { body.append('_token', csrf.value); }
        fetch(url, { method: 'POST', body: body }).catch(function () {});
    }
    beat();
    setInterval(beat, 60000);
})();
</script>
