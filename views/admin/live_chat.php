<?php $title = 'Live Chat'; ?>

<?php /* The live chat had NO moderation screen at all: a member's message
       stayed in the replay for everyone to see, and there was nowhere to
       look at what was said or to take a message down. The member chat has
       a moderation path; the live chat had none.

       It used to live on the support page, which made that page show two
       unrelated things: the support tickets and the chat of a live stream.
       It has its own page now. */ ?>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Streams with chat</h2>
        <p><?= number_format(count($liveSessions ?? [])) ?> stream(s) with messages.</p>
    </div>

    <?php if (empty($liveSessions)): ?>
        <?= admin_empty('broadcast', 'No live stream has chat', 'A stream shows up here once a member writes in its chat.') ?>
    <?php else: ?>
        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Stream</th>
                        <th>Status</th>
                        <th>Messages</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($liveSessions as $session): ?>
                    <tr>
                        <td>#<?= (int) $session['id'] ?></td>
                        <td><?= e((string) $session['status']) ?></td>
                        <td><?= number_format((int) $session['message_count']) ?></td>
                        <td class="user-date"><?= e(tzdate('M j, Y g:i', (string) $session['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Messages</h2>
        <p>Messages posted in a live stream. <?= number_format((int) ($liveCount ?? 0)) ?> in the last hour.</p>
    </div>

    <?php if (empty($liveMessages)): ?>
        <?= admin_empty('broadcast', 'No live chat messages', 'Messages posted during a live stream land here, so they can be read and removed.') ?>
    <?php else: ?>
        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Member</th>
                        <th>Message</th>
                        <th>Stream</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($liveMessages as $live): ?>
                    <tr>
                        <td class="user-date"><?= e(tzdate('M j, g:ia', (string) $live['created_at'])) ?></td>
                        <td><?= e((string) $live['email']) ?></td>
                        <td>
                            <?php if (($live['sender_role'] ?? 'user') === 'user'): ?>
                                <?= e((string) $live['message']) ?>
                            <?php else: ?>
                                <span class="muted"><?= e((string) $live['message']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="user-date">#<?= (int) $live['session_id'] ?> (<?= e((string) ($live['session_status'] ?? '')) ?>)</td>
                        <td class="act">
                            <form method="post" action="<?= url('/admin/live-chat/' . (int) $live['id'] . '/delete') ?>" class="inline"
                                  data-confirm="Remove this live chat message? It disappears from the chat and the replay.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                            <form method="post" action="<?= url('/admin/live-chat/' . (int) $live['session_id'] . '/user/' . (int) $live['user_id'] . '/delete') ?>" class="inline"
                                  data-confirm="Remove every message from this member in this stream?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline">Remove all from this member</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
