<?php $title = 'Chat #' . (int) $conversation['id']; ?>
<?php
$me   = 'operator';
$mode = 'admin';
$readThrough = (int) ($memberLastRead ?? 0);
$cid  = (int) $conversation['id'];

// Same grouping rule as the member thread and the JS client.
$prev = null;
$endpoints = [
    'reply'   => url('/admin/chat/' . $cid . '/reply'),
    'history' => url('/admin/chat/history'),
    'search'  => url('/admin/chat/search?conversation=' . $cid),
    'react'   => url('/admin/chat/' . $cid . '/message/__MID__/react'),
    'typing'  => url('/admin/chat/' . $cid . '/typing'),
    'poll'    => url('/admin/chat/poll'),
    'pin'     => url('/admin/chat/' . $cid . '/message/__MID__/pin'),
    'remove'  => url('/admin/chat/' . $cid . '/message/__MID__/delete'),
];
?>

<div class="adm-chat-head">
    <div class="adm-chat-title">
        <h2 class="adm-h1">Chat #<?= $cid ?></h2>
        <p class="adm-chat-sub"><?= e((string) $user_email) ?></p>
    </div>
    <div class="adm-chat-actions">
        <form method="post" action="<?= url('/admin/chat/' . $cid . '/reply-toggle') ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm <?= !empty($member_reply_enabled) ? '' : 'btn-outline' ?>">
                Member replies: <?= !empty($member_reply_enabled) ? 'ON' : 'OFF' ?>
            </button>
        </form>
        <form method="post" action="<?= url('/admin/chat/' . $cid . '/mode') ?>" class="adm-chat-mode">
            <?= csrf_field() ?>
            <label class="muted u-fs-085" for="ai-mode">AI mode:</label>
            <select name="ai_mode" id="ai-mode">
                <option value="retrieval" <?= ($conversation['ai_mode'] ?? '') === 'retrieval' ? 'selected' : '' ?>>Retrieval (AI)</option>
                <option value="finetuned" <?= ($conversation['ai_mode'] ?? '') === 'finetuned' ? 'selected' : '' ?>>Fine-tuned (AI)</option>
                <option value="operator" <?= ($conversation['ai_mode'] ?? '') === 'operator' ? 'selected' : '' ?>>Operator only (no AI)</option>
            </select>
            <button type="submit" class="btn btn-sm">Set mode</button>
        </form>
    </div>
</div>

<div class="chat-app chat-app--admin" id="chat-app"
     data-mode="admin"
     data-me="operator"
     data-conversation="<?= $cid ?>"
     data-latest-id="<?= !empty($messages) ? (int) $messages[count($messages) - 1]['id'] : 0 ?>"
     data-oldest-id="<?= !empty($messages) ? (int) $messages[0]['id'] : 0 ?>"
     data-has-more="<?= !empty($hasMore) ? '1' : '0' ?>"
     data-read-through="<?= $readThrough ?>"
     data-upload-limit="<?= 25 * 1024 * 1024 ?>"
     <?php foreach ($endpoints as $key => $value): ?>data-endpoint-<?= e($key) ?>="<?= e($value) ?>"<?php endforeach; ?>>

    <header class="chat-head">
        <span class="chat-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $user_email, 0, 1))) ?></span>
        <div class="chat-who">
            <h2 class="chat-name"><?= e((string) $user_email) ?></h2>
            <p class="chat-sub">
                <?= !empty($member_reply_enabled) ? 'Member can reply' : 'Member replies are OFF' ?>
                &middot; <?= e(ucfirst((string) ($conversation['ai_mode'] ?? 'retrieval'))) ?> mode
            </p>
        </div>
        <div class="chat-head-actions">
            <button type="button" class="chat-btn" data-chat-search-toggle aria-expanded="false"
                    aria-label="Search this conversation" title="Search">⌕</button>
        </div>
    </header>

    <div class="chat-search" role="search">
        <input type="search" class="chat-search-input" placeholder="Search this conversation…" aria-label="Search this conversation">
        <span class="chat-search-status" role="status" aria-live="polite"></span>
    </div>

    <?php if (!empty($pinnedMessages)): ?>
        <div class="chat-pinned is-visible" role="note" aria-label="Pinned message">
            <span aria-hidden="true">📌</span>
            <span><b>Pinned:</b> <?= e(mb_substr(trim((string) ($pinnedMessages[0]['message'] ?? '')), 0, 180)) ?></span>
        </div>
    <?php endif; ?>

    <div class="chat-body">
        <div class="chat-list" id="chat-thread" role="log" aria-live="polite" aria-relevant="additions"
             aria-label="Conversation with the member" tabindex="0">
            <?php if (empty($messages)): ?>
                <div class="chat-empty" id="chat-empty">
                    <span class="chat-empty-ico" aria-hidden="true">💬</span>
                    <h3>No messages yet</h3>
                    <p>The conversation starts with the member's first message, or with yours.</p>
                </div>
            <?php else: ?>
                <?php $lastDay = ''; ?>
                <?php foreach ($messages as $m): ?>
                    <?php
                    $first = true;
                    if ($prev !== null) {
                        $sameRole = (string) $prev['sender_role'] === (string) $m['sender_role'];
                        $sameDay  = (string) ($prev['day'] ?? '') === (string) ($m['day'] ?? '');
                        $t1 = strtotime(((string) $prev['created_at']) . ' UTC');
                        $t2 = strtotime(((string) $m['created_at']) . ' UTC');
                        $close    = ($t1 !== false && $t2 !== false) ? abs($t2 - $t1) <= 300 : false;
                        $first = !($sameRole && $sameDay && $close);
                    }
                    $prev = $m;
                    ?>
                    <?php if ((string) ($m['day'] ?? '') !== $lastDay): ?>
                        <?php $lastDay = (string) ($m['day'] ?? ''); ?>
                        <div class="chat-day" data-day="<?= e($lastDay) ?>"><?= e((string) ($m['day_label'] ?? '')) ?></div>
                    <?php endif; ?>
                    <?php $m['content_refs'] = $m['content_refs'] ?? []; ?>
                    <?php require __DIR__ . '/../partials/chat_message.php'; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="chat-typing" role="status" aria-live="polite">
            <span class="chat-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span>The member is typing…</span>
        </div>

        <button type="button" class="chat-jump" aria-label="Jump to the newest messages">
            <b>↓</b> <span>New messages</span>
        </button>

        <div class="chat-drop" aria-hidden="true">Drop a file to attach it</div>
    </div>

    <div class="chat-composer-bar">
        <div class="chat-reply-bar">
            <div class="chat-reply-text">
                <span class="chat-reply-label">Replying</span>
                <span class="chat-reply-quote"></span>
            </div>
            <button type="button" class="chat-btn" data-chat-cancel-reply aria-label="Cancel reply">✕</button>
        </div>

        <div class="chat-attach-preview"></div>
        <div class="chat-progress" aria-hidden="true"><i></i></div>
        <div class="chat-emoji" role="group" aria-label="Emoji"></div>

        <?php /* data-no-progress: the client posts this form with XHR and
            shows its own upload bar, so the admin layout must not throw its
            full-page "Saving…" overlay (it would stay up, because the page
            never navigates). */ ?>
        <form class="chat-inputrow" id="reply-form" method="post" enctype="multipart/form-data"
              action="<?= url('/admin/chat/' . $cid . '/reply') ?>" data-no-progress novalidate>
            <?= csrf_field() ?>
            <label class="chat-btn chat-attach-label" for="attachment-input" title="Attach a file" aria-label="Attach a file">📎</label>
            <input type="file" name="attachment" id="attachment-input" class="chat-file-input" hidden
                   accept="image/*,video/*,text/*,.pdf,.txt,.csv,.log" aria-label="Attach a file">
            <button type="button" class="chat-btn" data-chat-emoji-toggle aria-expanded="false"
                    aria-label="Emoji" title="Emoji">😊</button>
            <textarea class="chat-input" name="message" id="reply-text" rows="1" maxlength="2000"
                      placeholder="Write a reply…" aria-label="Reply" autocomplete="off"></textarea>
            <button type="submit" class="chat-send" aria-label="Send reply" title="Send">➤</button>
        </form>

        <div class="chat-hint">
            <span>Enter sends · Shift+Enter adds a line</span>
            <span class="chat-expiry">
                <label class="muted u-fs-08">Expire
                    <select name="expires_in" id="expires-in" class="chat-expiry-select">
                        <option value="0">never</option>
                        <option value="5">5 min</option>
                        <option value="15">15 min</option>
                        <option value="60">1 h</option>
                        <option value="1440">24 h</option>
                    </select>
                </label>
                <label class="muted u-fs-08">Views
                    <select name="max_views" id="max-views" class="chat-expiry-select">
                        <option value="0">∞</option>
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="10">10</option>
                    </select>
                </label>
            </span>
        </div>
        <p class="muted u-fs-08 u-mt-05 u-m0">The expiry applies to the attached picture or video only.</p>
    </div>
</div>

<div class="chat-lightbox" id="chat-lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
    <button type="button" class="chat-lightbox-close" aria-label="Close">✕</button>
    <img src="" alt="">
</div>

<p class="u-mt-075"><a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">Back to conversations</a></p>
