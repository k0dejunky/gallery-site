<?php $title = 'Tag manager'; ?>

<p class="muted">Tags group galleries across categories (e.g. themes, locations, series). Assign them per gallery on its manage page.</p>

<p style="margin:1rem 0;">
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/engagement') ?>">&larr; Back to Engagement</a>
</p>

<section style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:1.25rem;background:var(--card-bg,#fff);max-width:640px;">
    <h2 style="margin-top:0;">Add a tag</h2>
    <form method="post" action="<?= url('/admin/engagement/tags') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="text" name="name" placeholder="Tag name (e.g. outdoor, cosplay)" maxlength="80" style="flex:1;min-width:180px;" required>
        <button type="submit" class="btn">Add tag</button>
    </form>
    <p class="muted" style="margin-bottom:0;">Tags are created on the fly when you type them on a gallery, too.</p>
</section>

<section style="margin-top:1.5rem;">
    <h2>Tags (<?= count($tags) ?>)</h2>
    <?php if (empty($tags)): ?>
        <p class="muted">No tags yet.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">Tag</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Slug</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Galleries</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tag): ?>
                    <tr>
                        <td style="padding:.4rem .5rem;"><?= e((string) $tag['name']) ?></td>
                        <td class="muted" style="padding:.4rem .5rem;"><code><?= e((string) $tag['slug']) ?></code></td>
                        <td style="padding:.4rem .5rem;"><?= (int) $tag['gallery_count'] ?></td>
                        <td style="padding:.4rem .5rem;text-align:right;white-space:nowrap;">
                            <a class="btn btn-sm btn-outline" href="<?= url('/galleries/tag/' . e((string) $tag['slug'])) ?>" target="_blank" rel="noopener">View</a>
                            <form class="inline" method="post" action="<?= url('/admin/engagement/tags/' . (int) $tag['id'] . '/delete') ?>" onsubmit="return confirm('Delete this tag and unlink it from every gallery?');">
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