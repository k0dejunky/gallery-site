<?php $title = 'Chat'; ?>
<?php
$me    = 'user';
$mode  = 'member';
$readThrough = (int) ($operatorReadThrough ?? 0);

// Grouping: a new group starts when the sender changes, the day changes, or
// five minutes pass. Same rule as the JavaScript client, so a message that
// streams in looks like the ones around it.
$prev = null;
$firstFlags = [];
foreach ($messages as $i => $m) {
    $first = true;
    if ($prev !== null) {
        $sameRole = (string) $prev['sender_role'] === (string) $m['sender_role'];
        $sameDay  = (string) ($prev['day'] ?? '') === (string) ($m['day'] ?? '');
        $t1 = strtotime(((string) $prev['created_at']) . ' UTC');
        $t2 = strtotime(((string) $m['created_at']) . ' UTC');
        $close    = ($t1 !== false && $t2 !== false) ? abs($t2 - $t1) <= 300 : false;
        $first = !($sameRole && $sameDay && $close);
    }
    $firstFlags[$i] = $first;
    $prev = $m;
}
?>
<div class="chat-page page-shell chat-shell">
    <?php if (!empty($questionnaires)): ?>
        <div class="card u-mb-1">
            <h2 class="section-title" style="margin-bottom:.5rem;">Questionnaires</h2>
            <?php foreach ($questionnaires as $q): ?>
                <?php $qAnswered = !empty($q['answered']); ?>
                <div id="questionnaire-<?= (int)$q['id'] ?>" class="qn-panel<?= $qAnswered ? ' qn-panel-done' : '' ?>">
                    <strong class="qn-title"><?= e((string)$q['title']) ?></strong>
                    <?php if ($qAnswered): ?>
                        <span class="chat-submitted">✓ Submitted</span>
                    <?php endif; ?>
                    <?php if (!empty($q['intro'])): ?><p class="qn-intro"><?= e((string)$q['intro']) ?></p><?php endif; ?>

                    <?php if ($qAnswered): ?>
                        <?php foreach ($q['questions'] as $qq): ?>
                            <?php $qqid=(int)$qq['id']; $my = $q['my_answers'][$qqid] ?? ''; ?>
                            <div class="qn-question">
                                <label class="qn-label"><?= e((string)$qq['prompt']) ?></label>
                                <?php if ($qq['qtype']==='text'): ?>
                                    <textarea rows="2" disabled class="chat-answer"><?= e((string)$my) ?></textarea>
                                <?php elseif ($qq['qtype']==='choice' || $qq['qtype']==='rating'): ?>
                                    <?php if ($qq['qtype']==='choice'): $opts=$qq['options_decoded']??[]; else: $opts=$qq['options_decoded']??['min'=>1,'max'=>5]; $tmp=[]; for($r=(int)$opts['min'];$r<=(int)$opts['max'];$r++){ $tmp[]=$r; } $opts=$tmp; endif; ?>
                                    <?php foreach ($opts as $opt): ?>
                                        <label class="qn-option"><input type="radio" disabled <?= (string)$my === (string)$opt ? 'checked' : '' ?>> <?= e((string)$opt) ?></label>
                                    <?php endforeach; ?>
                                <?php elseif ($qq['qtype']==='multichoice'): ?>
                                    <?php $vals=json_decode((string)$my,true); if(!is_array($vals)){$vals=array_filter([(string)$my]);} ?>
                                    <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                        <label class="qn-option"><input type="checkbox" disabled <?= in_array((string)$opt, array_map('strval',$vals), true) ? 'checked' : '' ?>> <?= e((string)$opt) ?></label>
                                    <?php endforeach; ?>
                                <?php elseif ($qq['qtype']==='number'): ?>
                                    <input type="text" disabled value="<?= e((string)$my) ?>" class="chat-answer">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <p class="muted u-m0"><strong>Submitted.</strong> You can view your answers above; they can't be changed.</p>
                    <?php elseif ((int)$q['allow_replies'] === 1): ?>
                        <form method="post" action="<?= url('/chat/questionnaire/' . (int)$q['id'] . '/answer') ?>" class="qn-form">
                            <?= csrf_field() ?>
                            <?php foreach ($q['questions'] as $qq): ?>
                                <?php $qqid=(int)$qq['id']; $key='q'.$qqid; ?>
                                <div>
                                    <label class="qn-label"><?= e((string)$qq['prompt']) ?><?= (int)$qq['required']===1?'*':'' ?></label>
                                    <?php if ($qq['qtype']==='text'): ?>
                                        <textarea name="answers[<?= $key ?>]" rows="2" aria-label="<?= e((string)$qq['prompt']) ?>" <?= (int)$qq['required']===1?'required':'' ?>></textarea>
                                    <?php elseif ($qq['qtype']==='choice'): ?>
                                        <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                            <label class="qn-option"><input type="radio" name="answers[<?= $key ?>]" value="<?= e((string)$opt) ?>" <?= (int)$qq['required']===1?'required':'' ?>> <?= e((string)$opt) ?></label>
                                        <?php endforeach; ?>
                                    <?php elseif ($qq['qtype']==='multichoice'): ?>
                                        <?php foreach (($qq['options_decoded']??[]) as $opt): ?>
                                            <label class="qn-option"><input type="checkbox" name="answers[<?= $key ?>][]" value="<?= e((string)$opt) ?>"> <?= e((string)$opt) ?></label>
                                        <?php endforeach; ?>
                                    <?php elseif ($qq['qtype']==='rating'): ?>
                                        <?php $opts=$qq['options_decoded']??['min'=>1,'max'=>5]; $min=(int)$opts['min']; $max=(int)$opts['max']; ?>
                                        <?php for ($r=$min;$r<=$max;$r++): ?>
                                            <label class="qn-option"><input type="radio" name="answers[<?= $key ?>]" value="<?= $r ?>" <?= (int)$qq['required']===1?'required':'' ?>> <?= $r ?></label>
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
            <div class="chat-daily">
                <strong>Message from the site</strong>
                <p><?= e((string) $dailyMessage) ?></p>
            </div>
        <?php endif; ?>
        <p class="muted">Chat is available to members on the <strong>Platinum</strong>, <strong>Yearly</strong>, <strong>Lifetime</strong>, or <strong>Chat add-on</strong> plans. Upgrade in <a href="<?= url('/membership') ?>">Membership</a> to start chatting.</p>
    <?php else: ?>
        <?php
        $endpoints = [
            'send'     => url('/chat'),
            'history'  => url('/chat/history'),
            'stream'   => url('/chat/stream'),
            'edit'     => url('/chat/edit'),
            'delete'   => url('/chat/delete'),
            'react'    => url('/chat/react'),
            'search'   => url('/chat/search'),
            'typing'   => url('/chat/typing'),
        ];
        $online = !empty($operatorOnline);
        ?>
        <div class="chat-app" id="chat-app"
             data-mode="member"
             data-me="user"
             data-conversation="<?= (int) ($conversation['id'] ?? 0) ?>"
             data-latest-id="<?= (int) ($latestId ?? 0) ?>"
             data-oldest-id="<?= !empty($messages) ? (int) $messages[0]['id'] : 0 ?>"
             data-has-more="<?= !empty($hasMore) ? '1' : '0' ?>"
             data-read-through="<?= $readThrough ?>"
             data-csrf="<?= e(\App\Core\Csrf::token()) ?>"
             data-upload-limit="<?= 25 * 1024 * 1024 ?>"
             <?php foreach ($endpoints as $key => $value): ?>data-endpoint-<?= e($key) ?>="<?= e($value) ?>"<?php endforeach; ?>>

            <header class="chat-head">
                <span class="chat-avatar" aria-hidden="true">A<span class="chat-presence<?= $online ? ' is-online' : '' ?>"></span></span>
                <div class="chat-who">
                    <h2 class="chat-name">Amethyst</h2>
                    <p class="chat-sub<?= $online ? ' is-online' : '' ?>" id="chat-presence">
                        <?= $online ? 'Online now' : 'Away — replies as soon as possible' ?>
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
                     aria-label="Conversation with Amethyst" tabindex="0">
                    <?php if (empty($messages)): ?>
                        <div class="chat-empty" id="chat-empty">
                            <span class="chat-empty-ico" aria-hidden="true">💬</span>
                            <h3>Say hello</h3>
                            <p>This is a private conversation with the studio. Ask about a set, a custom request, or anything else.</p>
                        </div>
                    <?php else: ?>
                        <?php $lastDay = ''; ?>
                        <?php foreach ($messages as $i => $m): ?>
                            <?php if ((string) ($m['day'] ?? '') !== $lastDay): ?>
                                <?php $lastDay = (string) ($m['day'] ?? ''); ?>
                                <div class="chat-day" data-day="<?= e($lastDay) ?>"><?= e((string) ($m['day_label'] ?? '')) ?></div>
                            <?php endif; ?>
                            <?php
                            $m['content_refs'] = $m['content_refs'] ?? [];
                            $first = $firstFlags[$i] ?? true;
                            require __DIR__ . '/../partials/chat_message.php';
                            ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="chat-typing" role="status" aria-live="polite">
                    <span class="chat-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span>Amethyst is typing…</span>
                </div>

                <button type="button" class="chat-jump" aria-label="Jump to the newest messages">
                    <b>↓</b> <span>New messages</span>
                </button>

                <div class="chat-drop" aria-hidden="true">Drop a file to attach it</div>
            </div>

            <?php if (!empty($replyEnabled)): ?>
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

                    <form class="chat-inputrow" id="chat-form" novalidate>
                        <label class="chat-btn chat-attach-label" for="chat-file"
                               title="Attach a file (images, video, audio, PDF, text; max 25 MB)"
                               aria-label="Attach a file">📎</label>
                        <input type="file" id="chat-file" class="chat-file-input" hidden
                               accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mp3,.ogg,.oga,.wav,.pdf,.txt"
                               aria-label="Attach a file">
                        <button type="button" class="chat-btn" data-chat-emoji-toggle aria-expanded="false"
                                aria-label="Emoji" title="Emoji">😊</button>
                        <textarea class="chat-input" id="chat-input" rows="1" maxlength="2000"
                                  placeholder="Write a message…" aria-label="Message" autocomplete="off"></textarea>
                        <button type="submit" class="chat-send" id="chat-send" aria-label="Send message" title="Send">➤</button>
                    </form>

                    <div class="chat-hint">
                        <span>Enter sends · Shift+Enter adds a line</span>
                        <span class="chat-count" aria-hidden="true">0/2000</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="chat-composer-bar">
                    <p class="muted u-m0" style="font-size:.85rem">
                        Replies are currently disabled for this conversation. Messages from the site will still appear here.
                        If this looks wrong, <a href="<?= e(url('/support')) ?>">contact support</a>.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <div class="chat-lightbox" id="chat-lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
            <button type="button" class="chat-lightbox-close" aria-label="Close">✕</button>
            <img src="" alt="">
        </div>
    <?php endif; ?>
</div>
