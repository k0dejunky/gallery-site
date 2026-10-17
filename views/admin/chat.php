<?php
/**
 * Admin chat console.
 *
 * This page used to overflow off the bottom of every screen. The cause was
 * structural, not cosmetic: the header opened a `display:flex` wrapper and never
 * closed it, so the ENTIRE rest of the page became one flex row, and three stray
 * `</div>`s closed the layout's own containers early. Everything below the fold
 * then rendered outside `.admin-main`'s content column.
 *
 * It is rebuilt with the admin design system (adm-section / sys-card /
 * adm-cols / adm-field / users-table-wrap) instead of ~40 ad-hoc inline
 * styles, and the two <script> blocks that were sitting INSIDE a table cell are
 * now at the bottom where they belong.
 */
$title = 'Chat Admin';

$aiOn       = !empty($state['ai_enabled']);
$operatorIsOnline = !empty($operatorOnline);
?>

<?php // ---------------------------------------------------------------- Overview ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Overview</h2>
        <p>AI switch, training corpus and the operator app.</p>
    </div>

    <div class="stat-cards">
        <div class="stat-card">
            <b><?= $aiOn ? 'ON' : 'OFF' ?></b>
            <small>AI master switch</small>
        </div>
        <div class="stat-card">
            <b><?= number_format((int) ($trainingCount ?? 0)) ?></b>
            <small>Training pairs (<?= number_format((int) ($cleanedCount ?? 0)) ?> cleaned)</small>
        </div>
        <div class="stat-card">
            <b id="waiting-value">&hellip;</b>
            <small id="waiting-badge">Waiting to be trained</small>
        </div>
        <div class="stat-card">
            <b style="color:<?= $operatorIsOnline ? '#15803d' : '#b45309' ?>;">
                <?= $operatorIsOnline ? 'Online' : 'Away' ?>
            </b>
            <small>Operator presence</small>
        </div>
    </div>

    <div class="sys-card adm-toolbar">
        <form class="inline" method="post" action="<?= url('/admin/chat/ai-toggle') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= $aiOn ? '' : 'btn-outline' ?>">
                <?= $aiOn ? 'Turn AI off' : 'Turn AI on' ?>
            </button>
        </form>
        <form class="inline" method="post" action="<?= url('/admin/chat/export-training') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline" data-confirm="Write the cleaned training export?">Export training</button>
        </form>
        <?php if (!empty($latestApk['file'])): ?>
            <a class="btn btn-outline" href="<?= url('/assets/apk/' . e($latestApk['file'])) ?>" download>
                Download operator app (Android APK v<?= e((string) $latestApk['version']) ?>)
            </a>
        <?php endif; ?>
        <span class="muted" style="font-size:.85rem;">
            <?= $operatorIsOnline ? '&#9679;' : '&#9675;' ?>
            While this page is open you are marked online; members get the away auto-response when nobody is.
        </span>
    </div>

    <p class="muted" style="font-size:.85rem;margin:.75rem 0 0;">
        AI base:
        <?php if (!empty($ai['hasBase'])): ?>
            <strong style="color:#15803d;">online</strong>
        <?php else: ?>
            <strong style="color:var(--danger,#c62828);">offline</strong>
        <?php endif; ?>
        &middot;
        <?php if (!empty($ai['hasFine'])): ?>
            fine-tuned model loaded (<code><?= e((string) $finetunedModel) ?></code>)
        <?php elseif (!empty($adapterInstalled)): ?>
            adapter installed — model <span style="color:var(--danger,#c62828);">not built yet</span>
        <?php else: ?>
            no adapter installed (retrieval mode only)
        <?php endif; ?>
    </p>
</section>

<?php // ------------------------------------------------------- AI & moderation ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>AI &amp; moderation</h2>
        <p>Default mode for new conversations, and the live group-chat word filter.</p>
    </div>

    <div class="adm-cols">
        <div class="sys-card">
            <h2 class="section-title">Default AI mode</h2>
            <form method="post" action="<?= url('/admin/chat/settings') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="save_section" value="ai">
                <label class="adm-field">
                    <span>Mode for <strong>new</strong> conversations (existing ones keep their own)</span>
                    <select name="default_ai_mode">
                        <option value="retrieval" <?= ($state['default_ai_mode'] ?? 'retrieval') === 'retrieval' ? 'selected' : '' ?>>Retrieval (AI, few-shot over operator replies)</option>
                        <option value="finetuned" <?= ($state['default_ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned (AI, LoRA adapter)</option>
                        <option value="operator" <?= ($state['default_ai_mode'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator only (no AI)</option>
                    </select>
                </label>
                <p class="check-row">
                    <label>
                        <input type="checkbox" name="ai_content_search" value="1"<?= !empty($state['ai_content_search']) ? ' checked' : '' ?>>
                        AI searches site galleries to answer content questions
                    </label>
                </p>
                <p class="check-row">
                    <label>
                        <input type="checkbox" name="chat_ai_async" value="1"<?= !empty($state['chat_ai_async']) ? ' checked' : '' ?>>
                        Generate AI replies in the background (recommended for slow local models; replies arrive a moment later)
                    </label>
                </p>
                <label class="adm-field">
                    <span>Maximum galleries to search</span>
                    <input type="number" name="ai_content_search_max" min="1" max="12" value="<?= (int) ($state['ai_content_search_max'] ?? 6) ?>">
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-sm">Save default</button>
                </div>
            </form>
        </div>

        <div class="sys-card">
            <h2 class="section-title">Live chat word filter</h2>
            <form method="post" action="<?= url('/admin/chat/settings') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="save_section" value="wordfilter">
                <label class="adm-field">
                    <span>One word per line, or comma-separated. The operator is never filtered.</span>
                    <textarea name="live_chat_filters" rows="8" placeholder="e.g. scam&#10;onlyfans&#10;spam"><?= e(implode("\n", \App\Core\ChatSettings::liveChatFilters())) ?></textarea>
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-sm">Save word filter</button>
                    <span class="muted">Messages containing a filtered word are rejected.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="sys-card" style="margin-top:1rem;">
        <h2 class="section-title">Member mutes</h2>
        <form class="adm-inline-form" method="post" action="<?= url('/admin/chat/mute') ?>">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Mute from live chat</span>
                <input type="email" name="email" required placeholder="member@example.com">
            </label>
            <label class="adm-field" style="flex:0 1 120px;">
                <span>Hours</span>
                <input type="number" name="hours" min="1" max="168" value="24">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm btn-outline">Mute</button>
            </div>
        </form>
        <form class="adm-inline-form" method="post" action="<?= url('/admin/chat/unmute') ?>" style="margin-top:.6rem;">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Lift a mute</span>
                <input type="email" name="email" placeholder="member@example.com">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm btn-outline">Unmute</button>
            </div>
        </form>

        <?php if (!empty($liveChatMutes)): ?>
            <div class="users-table-wrap" style="margin-top:.9rem;">
                <table>
                    <thead>
                        <tr><th style="text-align:left;">Email</th><th style="text-align:left;">Muted until (site time)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liveChatMutes as $m): ?>
                            <tr>
                                <td><?= e((string) $m['email']) ?></td>
                                <td class="muted"><?= e($m['chat_muted_until'] ? tzdate('Y-m-d H:i', (string) $m['chat_muted_until']) : '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php // -------------------------------------------------------- Member messages ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Member messages</h2>
        <p>Standing messages shown on the member Chat page, plus the daily broadcast.</p>
    </div>

    <div class="adm-cols">
        <div class="sys-card">
            <h2 class="section-title">Daily message</h2>
            <form method="post" action="<?= url('/admin/chat/daily-message') ?>">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>Shown to users without the chat feature (they cannot reply until they add a chat plan)</span>
                    <textarea name="daily_message" rows="4" maxlength="5000" placeholder="e.g. Chat is available on Platinum, Yearly, Lifetime, or the Chat add-on plan."><?= e((string) ($state['daily_message'] ?? '')) ?></textarea>
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-sm">Save daily message</button>
                </div>
            </form>
        </div>

        <div class="sys-card">
            <h2 class="section-title">Away auto-response</h2>
            <form method="post" action="<?= url('/admin/chat/away-message') ?>">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>Sent automatically when the operator chat is not online. Leave empty to disable.</span>
                    <textarea name="away_message" rows="4" maxlength="5000" placeholder="e.g. Thanks for your message! I'm away right now and will reply as soon as I'm back."><?= e((string) ($awayMessage ?? '')) ?></textarea>
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-sm">Save away message</button>
                </div>
            </form>
        </div>
    </div>

    <div class="sys-card" style="margin-top:1rem;">
        <h2 class="section-title">Daily chat broadcast</h2>
        <form method="post" action="<?= url('/admin/chat/daily-broadcast') ?>">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Delivered to every chat-eligible member's conversation now, or scheduled for later</span>
                <textarea name="message" rows="3" maxlength="5000" placeholder="e.g. Good morning! New content is live — open today's galleries and tell me what you think."></textarea>
            </label>
            <div class="adm-field-actions">
                <input type="datetime-local" name="scheduled_at" aria-label="Schedule for">
                <button type="submit" name="action" value="now" class="btn btn-sm">Send now</button>
                <button type="submit" name="action" value="schedule" class="btn btn-sm btn-outline">Schedule</button>
            </div>
        </form>
    </div>

    <h3 class="section-title" style="margin-top:1.5rem;">Daily chat send log</h3>
    <?php if (empty($broadcasts)): ?>
        <p class="muted" style="margin-top:0;">No daily chat broadcasts yet.</p>
    <?php else: ?>
        <div class="users-table-wrap">
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
                            <td class="muted"><?= !empty($b['scheduled_at']) ? e(tzdate('M j, Y H:i', (string) $b['scheduled_at'])) : '&mdash;' ?></td>
                            <td class="muted"><?= !empty($b['sent_at']) ? e(tzdate('M j, Y H:i', (string) $b['sent_at'])) : '&mdash;' ?></td>
                            <td><?= (int) $b['sent_count'] ?> / <?= (int) $b['recipients'] ?></td>
                            <td class="muted"><?= e((string) ($b['created_by_email'] ?? '—')) ?></td>
                            <td>
                                <div class="row-actions">
                                    <?php if (in_array($b['status'], ['scheduled', 'failed'], true)): ?>
                                        <form class="inline" method="post" action="<?= url('/admin/chat/daily-broadcast/' . (int) $b['id'] . '/send') ?>" data-confirm="Send this daily chat now?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm">Send now</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($b['status'], ['scheduled', 'sending'], true)): ?>
                                        <form class="inline" method="post" action="<?= url('/admin/chat/daily-broadcast/' . (int) $b['id'] . '/cancel') ?>" data-confirm="Cancel this scheduled daily chat?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!empty($b['error'])): ?>
                                        <span class="muted" title="<?= e((string) $b['error']) ?>">⚠</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php // ---------------------------------------------------------- Questionnaire ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Questionnaires</h2>
        <p>A structured multi-question form sent to every registered user, not just chat-plan members.</p>
    </div>

    <div class="sys-card">
        <form method="post" action="<?= url('/admin/chat/questionnaire') ?>" id="questionnaire-form">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Title</span>
                <input type="text" name="title" maxlength="200" required placeholder="Questionnaire title">
            </label>
            <label class="adm-field">
                <span>Intro / context (optional)</span>
                <textarea name="intro" rows="2" maxlength="5000" placeholder="Shown above the questions."></textarea>
            </label>

            <div id="question-rows" class="question-rows"></div>
            <div class="adm-field-actions" style="margin-top:.6rem;">
                <button type="button" class="btn btn-sm btn-outline" id="add-question">+ Add question</button>
            </div>

            <p class="check-row">
                <label>
                    <input type="checkbox" name="allow_replies" value="1" checked>
                    Allow replies (users can answer this questionnaire)
                </label>
            </p>

            <div class="adm-field-actions">
                <input type="datetime-local" name="scheduled_at" aria-label="Schedule for">
                <button type="submit" name="action" value="now" class="btn btn-sm">Send now</button>
                <button type="submit" name="action" value="schedule" class="btn btn-sm btn-outline">Schedule</button>
            </div>
        </form>
    </div>

    <?php if (!empty($questionnaires)): ?>
        <div class="users-table-wrap" style="margin-top:1rem;">
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
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat/questionnaire/' . (int) $q['id']) ?>">Results</a>
                                    <?php if (in_array($q['status'], ['draft', 'scheduled', 'failed'], true)): ?>
                                        <form class="inline" method="post" action="<?= url('/admin/chat/questionnaire/' . (int) $q['id'] . '/send') ?>" data-confirm="Send this questionnaire now?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm">Send now</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($q['status'], ['draft', 'scheduled', 'sending'], true)): ?>
                                        <form class="inline" method="post" action="<?= url('/admin/chat/questionnaire/' . (int) $q['id'] . '/cancel') ?>" data-confirm="Cancel this questionnaire?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php // ------------------------------------------------------- Training corpus ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Training corpus</h2>
        <p>Operator-style reply pairs for the AI trainer. Data is cleaned on import (emails/phones stripped, whitespace normalized, junk filtered) and marked ready — the training PC picks it up on its next poll (needs &ge;20 new pairs).</p>
    </div>

    <div class="sys-card">
        <form method="post" action="<?= url('/admin/chat/import-training') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Paste pairs — JSONL, CSV/TSV, or Q:/A: lines</span>
                <textarea name="training_text" rows="6" placeholder="Q: hi babe&#10;A: hey handsome, welcome back 😘&#10;&#10;or paste JSONL lines..."></textarea>
            </label>
            <label class="adm-field">
                <span>…or upload a file</span>
                <input type="file" name="training_file" accept=".jsonl,.csv,.tsv,.txt">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm">Import pairs</button>
            </div>
        </form>
    </div>
</section>

<?php // ------------------------------------------------- Operator device tokens ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Operator device tokens</h2>
        <p>Per-device tokens replace the shared bridge key for the Android app. Only the hash is stored; revoking a token immediately blocks that device. The legacy shared key still works during migration.</p>
    </div>

    <div class="sys-card">
        <form class="adm-inline-form" method="post" action="<?= url('/admin/chat/tokens') ?>">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Device label</span>
                <input type="text" name="label" placeholder="e.g. Operator phone" required>
            </label>
            <label class="adm-field" style="flex:0 1 190px;">
                <span>Expires in days (0 = never)</span>
                <input type="number" name="expires_days" min="0" max="3650" value="0">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm">Create token</button>
            </div>
        </form>
    </div>

    <?php if (empty($tokens)): ?>
        <p class="muted" style="margin-top:.75rem;">No device tokens yet.</p>
    <?php else: ?>
        <div class="users-table-wrap" style="margin-top:1rem;">
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
                            <td>
                                <?php if (!$revoked): ?>
                                    <form class="inline" method="post" action="<?= url('/admin/chat/tokens/' . (int) $t['id'] . '/revoke') ?>" data-confirm="Revoke token &quot;<?= e((string) $t['label']) ?>&quot;?">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php // --------------------------------------------------------- Conversations ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Conversations</h2>
        <p>Start a conversation with any account and send an operator message.</p>
    </div>

    <div class="sys-card">
        <form method="post" action="<?= url('/admin/chat/new') ?>">
            <?= csrf_field() ?>
            <label class="adm-field">
                <span>Send to this user</span>
                <input type="email" name="user_email" placeholder="user@example.com" required>
            </label>
            <label class="adm-field">
                <span>Message</span>
                <textarea name="message" rows="2" maxlength="2000" placeholder="Message to send…" required></textarea>
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-sm">Send to this user</button>
                <span class="muted">Starts the conversation if it does not exist yet.</span>
            </div>
        </form>
    </div>

    <form class="adm-inline-form" method="get" action="<?= url('/admin/chat') ?>" style="margin-top:1rem;">
        <label class="adm-field">
            <span>Search</span>
            <input type="text" name="q" value="<?= e($filterQ) ?>" placeholder="email or #id">
        </label>
        <label class="adm-field">
            <span>Mode</span>
            <select name="mode">
                <option value="">— all —</option>
                <option value="retrieval" <?= $filterMode === 'retrieval' ? 'selected' : '' ?>>Retrieval</option>
                <option value="finetuned" <?= $filterMode === 'finetuned' ? 'selected' : '' ?>>Fine-tuned</option>
                <option value="operator" <?= $filterMode === 'operator' ? 'selected' : '' ?>>Operator only</option>
            </select>
        </label>
        <label class="adm-field">
            <span>Queue</span>
            <select name="queue">
                <option value="" <?= $filterQueue === '' ? 'selected' : '' ?>>Everyone</option>
                <option value="unread" <?= $filterQueue === 'unread' ? 'selected' : '' ?>>Waiting for reply</option>
            </select>
        </label>
        <div class="adm-field-actions">
            <button type="submit" class="btn btn-sm">Filter</button>
            <?php if ($filterQ !== '' || $filterMode !== '' || $filterQueue !== ''): ?>
                <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div style="margin-top:1rem;">
        <?php if (empty($conversations)): ?>
            <?= admin_empty('chat', 'No conversations yet', 'A conversation starts the first time a member opens the operator chat app.') ?>
        <?php else: ?>
            <div class="users-table-wrap">
                <table>
                    <thead>
                        <tr><th>#</th><th>User</th><th>Mode</th><th>Status</th><th>Messages</th><th>Updated</th><th style="text-align:center;">Member replies</th><th style="text-align:right;">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversations as $c): ?>
                            <?php
                                $replyEnabled = (int) ($c['member_reply_enabled'] ?? 1) === 1;
                                $unread = (int) ($c['unread_count'] ?? 0);
                                $preview = trim((string) ($c['last_message'] ?? ''));
                                if (mb_strlen($preview) > 60) {
                                    $preview = mb_substr($preview, 0, 60) . '…';
                                }
                                $who = (($c['last_sender'] ?? '') === 'user') ? 'Member' : 'You';
                            ?>
                            <tr>
                                <td>#<?= (int) $c['id'] ?></td>
                                <td>
                                    <div>
                                        <?= e((string) $c['user_email']) ?>
                                        <?php if ($unread > 0): ?>
                                            <span class="pill pill-warn" title="<?= $unread === 1 ? '1 message waiting for a reply' : $unread . ' messages waiting for a reply' ?>"><?= $unread ?> waiting</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($preview !== ''): ?>
                                        <div class="muted" style="font-size:.78rem;margin-top:.15rem;">
                                            <span style="opacity:.7;"><?= $who ?>:</span> <?= e($preview) ?>
                                            <?php if ($unread > 0 && !empty($c['oldest_unread_at'])): ?>
                                                <span style="opacity:.7;">· since <?= e(tzdate('M j, g:i A', (string) $c['oldest_unread_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
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
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-sm" href="<?= url('/admin/chat/' . (int) $c['id']) ?>">Open</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <div class="adm-field-actions" style="margin-top:.75rem;justify-content:flex-start;">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <a class="btn btn-sm <?= $p === $page ? '' : 'btn-outline' ?>" href="<?= e(url('/admin/chat?page=' . $p . ($filterQ !== '' ? '&q=' . rawurlencode($filterQ) : '') . ($filterMode !== '' ? '&mode=' . rawurlencode($filterMode) : '') . ($filterQueue !== '' ? '&queue=' . rawurlencode($filterQueue) : ''))) ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>


<script nonce="<?= csp_nonce() ?>">
<?php // Training-count badge: polls the count and writes it into the overview card. ?>
(function () {
    var value = document.getElementById('waiting-value');
    var badge = document.getElementById('waiting-badge');
    if (!value || !badge) return;
    function refresh() {
        fetch('<?= url('/admin/chat/training-count') ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                var n = Number(d.waiting) || 0;
                value.textContent = n;
                value.style.color = n > 0 ? 'var(--warn,#ffb454)' : 'var(--ok,#3ddc84)';
                badge.textContent = n > 0 ? 'Waiting to be trained' : 'Nothing waiting to train';
            })
            .catch(function () {});
    }
    refresh();
    setInterval(refresh, 5000);
})();

<?php // Questionnaire builder: add/remove question rows, showing the fields per type. ?>
(function () {
    var rows = document.getElementById('question-rows');
    var add = document.getElementById('add-question');
    if (!rows || !add) return;
    var idx = 0;
    function addRow() {
        var n = idx++;
        var wrap = document.createElement('div');
        wrap.className = 'q-row';
        wrap.innerHTML =
            '<div class="q-top">' +
                '<input type="text" name="q[' + n + '][prompt]" placeholder="Question prompt" required>' +
                '<select name="q[' + n + '][qtype]">' +
                    '<option value="text">Text</option>' +
                    '<option value="choice">Single choice</option>' +
                    '<option value="multichoice">Multi-select</option>' +
                    '<option value="rating">Rating</option>' +
                    '<option value="number">Number</option>' +
                '</select>' +
                '<label class="muted" style="font-size:.8rem;display:inline-flex;align-items:center;gap:.25rem;"><input type="checkbox" name="q[' + n + '][required]" value="1" checked> required</label>' +
                '<button type="button" class="btn btn-sm btn-danger" data-remove aria-label="Remove question">&times;</button>' +
            '</div>' +
            '<input type="text" name="q[' + n + '][options]" placeholder="Options for choice / multi-select, comma-separated (rating defaults 1-5)" style="display:none;">' +
            '<div style="display:flex;gap:.4rem;align-items:center;">' +
                '<input type="number" name="q[' + n + '][min]" value="1" min="1" style="display:none;width:5rem;" title="Rating min">' +
                '<input type="number" name="q[' + n + '][max]" value="5" min="1" style="display:none;width:5rem;" title="Rating max">' +
            '</div>';
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

<?php // Presence heartbeat: while this page is open the operator is marked online. ?>
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
