<?php $title = 'Engagement'; ?>

<p class="muted">Wall posts, comment moderation, unlock codes, gift subscriptions and one-off purchases.</p>

<p style="display:flex;gap:.75rem;flex-wrap:wrap;margin:1rem 0;">
    <a class="btn btn-sm" href="<?= url('/admin/engagement/purchases') ?>">Purchases &amp; tips ledger</a>
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/engagement/tags') ?>">Manage tags</a>
    <a class="btn btn-sm btn-outline" href="<?= url('/wall') ?>" target="_blank" rel="noopener">View public wall</a>
</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.25rem;">

    <section style="border:1px solid var(--card-border,#ddd);border-radius:var(--card-radius,8px);padding:1.25rem;background:var(--card-bg,#fff);">
        <h2 style="margin-top:0;">New wall post</h2>
        <form method="post" action="<?= url('/admin/engagement/wall') ?>">
            <?= csrf_field() ?>
            <textarea name="body" rows="4" style="width:100%;box-sizing:border-box;" placeholder="Share an update with your members…" maxlength="4000" required></textarea>
            <p><label><input type="checkbox" name="pinned" value="1"> Pin to the top of the wall</label></p>
            <button type="submit" class="btn">Publish to wall</button>
            <span class="muted">All active members get an in-app notification.</span>
        </form>
    </section>

    <section style="border:1px solid var(--card-border,#ddd);border-radius:var(--card-radius,8px);padding:1.25rem;background:var(--card-bg,#fff);">
        <h2 style="margin-top:0;">Unlock codes</h2>
        <form method="post" action="<?= url('/admin/engagement/codes') ?>">
            <?= csrf_field() ?>
            <p>
                <label for="code-gallery">Gallery (optional)</label>
                <select name="gallery_id" id="code-gallery">
                    <option value="">Any PPV gallery</option>
                    <?php foreach ($galleries as $g): ?>
                        <option value="<?= (int) $g['id'] ?>"><?= e((string) $g['title']) ?> (#<?= (int) $g['id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="code-amount">Amount</label>
                <input type="number" step="0.01" min="0" name="amount" id="code-amount" placeholder="e.g. 9.99" style="width:120px;">
                <label for="code-max">Max uses</label>
                <input type="number" min="1" name="max_uses" id="code-max" value="1" style="width:80px;">
            </p>
            <p><input type="text" name="note" placeholder="Note (who it was sold to, order #)" style="width:100%;box-sizing:border-box;"></p>
            <button type="submit" class="btn">Generate unlock code</button>
        </form>

        <h3>Gift subscription</h3>
        <form method="post" action="<?= url('/admin/engagement/gift') ?>">
            <?= csrf_field() ?>
            <p class="muted" style="margin-top:0;">A 100%-off code redeemable at signup; grants the chosen tier for free.</p>
            <p>
                <label for="gift-level">Tier</label>
                <select name="target_level" id="gift-level">
                    <option value="1">Silver (1)</option>
                    <option value="2">Gold (2)</option>
                    <option value="3">Platinum (3)</option>
                </select>
                <label for="gift-max">Uses</label>
                <input type="number" min="1" name="max_uses" id="gift-max" value="1" style="width:80px;">
            </p>
            <button type="submit" class="btn btn-outline">Create gift code</button>
        </form>
    </section>
</div>

<section style="margin-top:1.5rem;">
    <h2>Wall posts (<?= count($posts) ?>)</h2>
    <?php if (empty($posts)): ?>
        <p class="muted">No wall posts yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">Posted</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Post</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Comments</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $post): ?>
                    <tr>
                        <td class="muted" style="padding:.4rem .5rem;white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', (string) $post['created_at'])) ?><?= !empty($post['pinned']) ? ' <span class="chip">Pinned</span>' : '' ?></td>
                        <td style="padding:.4rem .5rem;"><?= e(mb_substr((string) $post['body'], 0, 160)) ?><?= mb_strlen((string) $post['body']) > 160 ? '…' : '' ?><?= (int) ($post['gallery_id'] ?? 0) > 0 ? ' <span class="chip">Gallery #' . (int) $post['gallery_id'] . '</span>' : '' ?></td>
                        <td style="padding:.4rem .5rem;"><?= (int) \App\Models\Comment::countFor(\App\Models\Comment::TYPE_WALL, (int) $post['id']) ?></td>
                        <td style="padding:.4rem .5rem;text-align:right;">
                            <form class="inline" method="post" action="<?= url('/admin/engagement/wall/' . (int) $post['id'] . '/delete') ?>" onsubmit="return confirm('Remove this wall post?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section style="margin-top:1.5rem;">
    <h2>Unlock codes (<?= count($codes) ?>)</h2>
    <?php if (empty($codes)): ?>
        <p class="muted">No unlock codes yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">Code</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Gallery</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Amount</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Uses</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Status</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($codes as $code): ?>
                    <tr>
                        <td style="padding:.4rem .5rem;"><code><?= e((string) $code['code']) ?></code></td>
                        <td style="padding:.4rem .5rem;"><?= $code['gallery_id'] === null ? '<span class="muted">Any</span>' : e((string) ($code['gallery_title'] ?? ('#' . $code['gallery_id']))) ?></td>
                        <td style="padding:.4rem .5rem;"><?= $code['amount'] === null ? '<span class="muted">—</span>' : e(number_format((float) $code['amount'], 2)) ?></td>
                        <td style="padding:.4rem .5rem;"><?= (int) $code['used_count'] ?><?= $code['max_uses'] === null ? '' : ' / ' . (int) $code['max_uses'] ?></td>
                        <td style="padding:.4rem .5rem;"><?= !empty($code['active']) ? 'Active' : '<span class="muted">Inactive</span>' ?></td>
                        <td style="padding:.4rem .5rem;text-align:right;white-space:nowrap;">
                            <form class="inline" method="post" action="<?= url('/admin/engagement/codes/' . (int) $code['id'] . '/toggle') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm"><?= !empty($code['active']) ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/engagement/codes/' . (int) $code['id'] . '/delete') ?>" onsubmit="return confirm('Delete this code?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section style="margin-top:1.5rem;">
    <h2>Recent comments (<?= count($comments) ?>)</h2>
    <?php if (empty($comments)): ?>
        <p class="muted">No comments yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">When</th>
                    <th style="text-align:left;padding:.4rem .5rem;">On</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Comment</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($comments as $comment): ?>
                    <tr>
                        <td class="muted" style="padding:.4rem .5rem;white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', (string) $comment['created_at'])) ?></td>
                        <td style="padding:.4rem .5rem;">
                            <?php if ($comment['commentable_type'] === \App\Models\Comment::TYPE_GALLERY): ?>
                                <a href="<?= url('/galleries/' . (int) $comment['commentable_id']) ?>" target="_blank" rel="noopener"><?= e(mb_substr((string) ($comment['entity_label'] ?? 'Gallery'), 0, 60)) ?></a>
                            <?php else: ?>
                                <span class="muted">Wall post · <?= e(mb_substr((string) ($comment['entity_label'] ?? ''), 0, 40)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.4rem .5rem;"><?= e(mb_substr((string) $comment['body'], 0, 140)) ?><?= mb_strlen((string) $comment['body']) > 140 ? '…' : '' ?></td>
                        <td style="padding:.4rem .5rem;text-align:right;">
                            <form class="inline" method="post" action="<?= url('/admin/engagement/comments/' . (int) $comment['id'] . '/delete') ?>" onsubmit="return confirm('Remove this comment?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section style="margin-top:1.5rem;">
    <h2>Recent purchases</h2>
    <?php if (empty($recentPurchases)): ?>
        <p class="muted">No purchases yet. <a href="<?= url('/admin/engagement/purchases') ?>">Open the full ledger</a>.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">When</th>
                    <th style="text-align:left;padding:.4rem .5rem;">User</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Item</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Amount</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPurchases as $purchase): ?>
                    <tr>
                        <td class="muted" style="padding:.4rem .5rem;white-space:nowrap;"><?= e(tzdate('Y-m-d H:i', (string) $purchase['created_at'])) ?></td>
                        <td style="padding:.4rem .5rem;"><?= e((string) $purchase['user_email']) ?></td>
                        <td style="padding:.4rem .5rem;"><?= e(ucfirst((string) $purchase['item_type'])) ?> &middot; <?= e(mb_substr((string) ($purchase['gallery_title'] ?? ($purchase['item_type'] === 'tip' ? 'Tip' : '—')), 0, 50)) ?></td>
                        <td style="padding:.4rem .5rem;">$<?= e(number_format((float) $purchase['amount'], 2)) ?> <span class="muted">(<?= e((string) $purchase['gateway']) ?>)</span></td>
                        <td style="padding:.4rem .5rem;"><?= e((string) $purchase['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>