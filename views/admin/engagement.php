<?php $title = 'Engagement'; ?>
<?php
// The engagement page mixes three levels of power: moderating the wall and
// the comments, and the money (unlock codes, gift subscriptions, the purchase
// ledger). The money needs the 'monetisation' permission, so a moderator
// without it is not shown the controls they may not use.
$canModerate = \App\Core\Auth::can('membership');
$canMonetise = \App\Core\Auth::can('monetisation');
?>

<?php // The three authoring forms are separate cards (the gift form used to
      // share a card with the unlock form under a cramped <h3>), and every
      // list is a card table with a single-line action group. ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Community</h2>
        <p>Wall posts, comment moderation, unlock codes, gift subscriptions and one-off purchases.</p>
    </div>
    <div class="sys-card adm-toolbar">
        <a class="btn btn-sm" href="<?= url('/admin/engagement/purchases') ?>">Purchases &amp; tips ledger</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/admin/engagement/tags') ?>">Manage tags</a>
        <a class="btn btn-sm btn-outline" href="<?= url('/wall') ?>" target="_blank" rel="noopener">View public wall</a>
    </div>
</section>

<section class="adm-section">
    <div class="adm-cols">
        <?php if ($canModerate): ?>
        <div class="sys-card">
            <h2>New wall post</h2>
            <form method="post" action="<?= url('/admin/engagement/wall') ?>">
                <?= csrf_field() ?>
                <textarea name="body" rows="4" placeholder="Share an update with your members&hellip;" maxlength="4000" required></textarea>
                <p class="check-row"><label><input type="checkbox" name="pinned" value="1"> Pin to the top of the wall</label></p>
                <div class="adm-field-actions">
                    <button type="submit" class="btn">Publish to wall</button>
                    <span class="muted">All active members get a notification.</span>
                </div>
            </form>
        </div>

        <?php endif; ?>
        <?php if ($canMonetise): ?>
        <div class="sys-card">
            <h2>Unlock code</h2>
            <form method="post" action="<?= url('/admin/engagement/codes') ?>">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>Gallery</span>
                    <select name="gallery_id">
                        <option value="">Any PPV gallery</option>
                        <?php foreach ($galleries as $g): ?>
                            <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['title']) ?> (#<?= (int) $g['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="adm-field-grid">
                    <label>
                        <span>Amount</span>
                        <input type="number" step="0.01" min="0" name="amount" placeholder="e.g. 9.99">
                    </label>
                    <label>
                        <span>Max uses</span>
                        <input type="number" min="1" name="max_uses" value="1">
                    </label>
                </div>
                <label class="adm-field">
                    <span>Note</span>
                    <input type="text" name="note" placeholder="Who it was sold to, order #">
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn">Generate unlock code</button>
                </div>
            </form>
        </div>

        <?php endif; ?>
        <?php if ($canMonetise): ?>
        <div class="sys-card">
            <h2>Gift subscription</h2>
            <p class="muted adm-card-note">A 100%-off code redeemable at signup; grants the chosen tier for free.</p>
            <form method="post" action="<?= url('/admin/engagement/gift') ?>">
                <?= csrf_field() ?>
                <div class="adm-field-grid">
                    <label>
                        <span>Tier</span>
                        <select name="target_level">
                            <option value="1">Silver (1)</option>
                            <option value="2">Gold (2)</option>
                            <option value="3">Platinum (3)</option>
                        </select>
                    </label>
                    <label>
                        <span>Uses</span>
                        <input type="number" min="1" name="max_uses" value="1">
                    </label>
                </div>
                <div class="adm-field-actions">
                    <button type="submit" class="btn btn-outline">Create gift code</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Wall posts</h2>
        <p><?= count($posts) ? number_format(count($posts)) . ' shown' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($posts)): ?>
        <?= admin_empty('broadcast', 'No wall posts yet', 'Publish one above and every active member is notified.') ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Posted</th>
                    <th>Post</th>
                    <th class="num">Comments</th>
                    <th class="act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $post): ?>
                    <tr>
                        <td class="muted mg-date"><?= e(tzdate('Y-m-d H:i', (string) $post['created_at'])) ?><?= !empty($post['pinned']) ? ' <span class="chip">Pinned</span>' : '' ?></td>
                        <td><?= e(mb_substr((string) $post['body'], 0, 160)) ?><?= mb_strlen((string) $post['body']) > 160 ? '…' : '' ?><?= (int) ($post['gallery_id'] ?? 0) > 0 ? ' <span class="chip">Gallery #' . (int) $post['gallery_id'] . '</span>' : '' ?></td>
                        <td class="num"><?= (int) (($postComments ?? [])[(int) $post['id']] ?? 0) ?></td>
                        <td class="act">
                            <?php if ($canModerate): ?>
                            <form class="inline" method="post" action="<?= url('/admin/engagement/wall/' . (int) $post['id'] . '/delete') ?>" data-confirm="Remove this wall post?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Unlock codes</h2>
        <p><?= count($codes) ? number_format(count($codes)) . ' shown' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($codes)): ?>
        <?= admin_empty('card', 'No unlock codes yet', 'Generate one above, or a 100%-off gift subscription.') ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Gallery</th>
                    <th class="num">Amount</th>
                    <th class="num">Uses</th>
                    <th>Status</th>
                    <th class="act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($codes as $code): ?>
                    <tr>
                        <td><code><?= e((string) $code['code']) ?></code></td>
                        <td><?= $code['gallery_id'] === null ? '<span class="muted">Any</span>' : e((string) ($code['gallery_title'] ?? ('#' . $code['gallery_id']))) ?></td>
                        <td class="num"><?= $code['amount'] === null ? '<span class="muted">&mdash;</span>' : e(number_format((float) $code['amount'], 2)) ?></td>
                        <td class="num"><?= (int) $code['used_count'] ?><?= $code['max_uses'] === null ? '' : ' / ' . (int) $code['max_uses'] ?></td>
                        <td><?= !empty($code['active']) ? 'Active' : '<span class="muted">Inactive</span>' ?></td>
                        <td class="act">
                            <?php if ($canMonetise): ?>
                            <form class="inline" method="post" action="<?= url('/admin/engagement/codes/' . (int) $code['id'] . '/toggle') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm"><?= !empty($code['active']) ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/engagement/codes/' . (int) $code['id'] . '/delete') ?>" data-confirm="Delete this code?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Recent comments</h2>
        <p><?= count($comments) ? number_format(count($comments)) . ' shown' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($comments)): ?>
        <?= admin_empty('chat', 'No comments yet', 'Member comments on galleries, media and wall posts land here.') ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>On</th>
                    <th>Comment</th>
                    <th class="act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comments as $comment): ?>
                    <tr>
                        <td class="muted mg-date"><?= e(tzdate('Y-m-d H:i', (string) $comment['created_at'])) ?></td>
                        <td>
                            <?php if ($comment['commentable_type'] === \App\Models\Comment::TYPE_GALLERY): ?>
                                <a href="<?= url('/galleries/' . (int) $comment['commentable_id']) ?>" target="_blank" rel="noopener"><?= e(mb_substr((string) ($comment['entity_label'] ?? 'Gallery'), 0, 60)) ?></a>
                            <?php elseif ($comment['commentable_type'] === \App\Models\Comment::TYPE_PHOTO): ?>
                                <a href="<?= url((is_video((string) ($comment['photo_filename'] ?? '')) ? '/videos/' : '/images/') . (int) $comment['commentable_id']) ?>" target="_blank" rel="noopener"><?= e(mb_substr((string) ($comment['entity_label'] ?? '') !== '' ? (string) $comment['entity_label'] : 'Media #' . (int) $comment['commentable_id'], 0, 60)) ?></a>
                            <?php else: ?>
                                <span class="muted">Wall post · <?= e(mb_substr((string) ($comment['entity_label'] ?? ''), 0, 40)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(mb_substr((string) $comment['body'], 0, 140)) ?><?= mb_strlen((string) $comment['body']) > 140 ? '…' : '' ?></td>
                        <td class="act">
                            <form class="inline" method="post" action="<?= url('/admin/engagement/comments/' . (int) $comment['id'] . '/delete') ?>" data-confirm="Remove this comment?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Recent purchases</h2>
        <p><?= count($recentPurchases) ? number_format(count($recentPurchases)) . ' shown' : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($recentPurchases)): ?>
        <?= admin_empty('dollar', 'No purchases yet', 'Unlock purchases and tips are recorded in the full ledger.', ['href' => url('/admin/engagement/purchases'), 'label' => 'Open the ledger']) ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>User</th>
                    <th>Item</th>
                    <th class="num">Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPurchases as $purchase): ?>
                    <tr>
                        <td class="muted mg-date"><?= e(tzdate('Y-m-d H:i', (string) $purchase['created_at'])) ?></td>
                        <td><?= e((string) $purchase['user_email']) ?></td>
                        <td><?= e(ucfirst((string) $purchase['item_type'])) ?> &middot; <?= e(mb_substr((string) ($purchase['gallery_title'] ?? ($purchase['item_type'] === 'tip' ? 'Tip' : '—')), 0, 50)) ?></td>
                        <td class="num">$<?= e(number_format((float) $purchase['amount'], 2)) ?> <span class="muted">(<?= e((string) $purchase['gateway']) ?>)</span></td>
                        <td><?= e((string) $purchase['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>
