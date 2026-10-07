<?php $title = 'AI category suggestions'; ?>

<p class="muted">
    The AI reviews a gallery's media and proposes categories here. Nothing is applied automatically —
    accept a proposal to merge it into the gallery's categories, or dismiss it. Driver:
    <strong><?= e($driver) ?></strong><?= $driver === 'off' ? ' (analysis disabled in .env)' : '' ?>.
</p>

<section style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0;">
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['galleries'] ?></strong> galler<?= (int) $stats['galleries'] === 1 ? 'y' : 'ies' ?> with suggestions
        <span class="muted">(<?= (int) $stats['pending'] ?> proposals)</span>
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['queued'] ?></strong> queued / running
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);<?= $stats['errors'] > 0 ? 'border-color:var(--red-600,#b91c1c);' : '' ?>">
        <strong><?= (int) $stats['errors'] ?></strong> error<?= $stats['errors'] === 1 ? '' : 's' ?>
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['uncategorized'] ?></strong> uncategorized galler<?= (int) $stats['uncategorized'] === 1 ? 'y' : 'ies' ?>
    </div>
</section>

<?php if ($stats['uncategorized'] > 0): ?>
<p style="margin:.75rem 0;">
    <form class="inline" method="post" action="<?= url('/admin/category-suggestions/backfill') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm">Analyze uncategorized galleries (<?= (int) $stats['uncategorized'] ?>)</button>
    </form>
    <span class="muted">Queues up to 200 per click; the worker picks them up within a minute.</span>
</p>
<?php endif; ?>

<?php if (empty($listing['rows'])): ?>
    <p class="muted" style="margin-top:1.5rem;">No pending suggestions. Galleries are analyzed automatically after upload (or use the backfill above).</p>
<?php else: ?>
    <?php foreach ($listing['rows'] as $row): $g = $row['gallery']; ?>
        <section style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:1rem;background:var(--card-bg,#fff);margin-bottom:1rem;display:flex;gap:1rem;align-items:flex-start;">
            <?php if (!empty($row['cover'])): ?>
                <img src="<?= e(file_url($row['cover'], 'thumb')) ?>" alt="" width="120" height="90" style="object-fit:cover;border-radius:6px;flex-shrink:0;" loading="lazy">
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <strong><a href="<?= url('/admin/galleries/' . (int) $g['id']) ?>"><?= e($g['title']) ?></a></strong>
                <div class="muted" style="font-size:.85em;">Gallery #<?= (int) $g['id'] ?><?= !empty($row['analyzed_at']) ? ' · analyzed ' . e((string) $row['analyzed_at']) : '' ?></div>
                <div class="chips" style="margin-top:.6rem;">
                    <?php foreach ($row['suggestions'] as $s): ?>
                        <span class="chip" style="display:inline-flex;align-items:center;gap:.4rem;">
                            <?= e($s['category_name']) ?>
                            <?php if ($s['confidence'] !== null): ?>
                                <span class="muted" style="font-size:.8em;"><?= (int) round((float) $s['confidence'] * 100) ?>%</span>
                            <?php endif; ?>
                            <form class="inline" method="post" action="<?= url('/admin/category-suggestions/accept') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-sm" title="Accept">✓</button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/category-suggestions/dismiss') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="btn btn-sm" title="Dismiss">✕</button>
                            </form>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:.6rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                    <form class="inline" method="post" action="<?= url('/admin/category-suggestions/accept-all') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                        <button type="submit" class="btn btn-sm">Accept all</button>
                    </form>
                    <form class="inline" method="post" action="<?= url('/admin/category-suggestions/reanalyze') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline">Re-analyze</button>
                    </form>
                    <a class="btn btn-sm btn-outline" href="<?= url('/admin/galleries/' . (int) $g['id']) ?>">Manage</a>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($listing['pages'] > 1): ?>
        <nav style="display:flex;gap:.6rem;align-items:center;margin-top:1rem;" aria-label="Suggestions pages">
            <?php if ($listing['page'] > 1): ?>
                <a class="btn btn-sm btn-outline" href="?page=<?= $listing['page'] - 1 ?>">&larr; Prev</a>
            <?php endif; ?>
            <span class="muted">Page <?= $listing['page'] ?> of <?= $listing['pages'] ?> (<?= (int) $listing['total'] ?> galleries)</span>
            <?php if ($listing['page'] < $listing['pages']): ?>
                <a class="btn btn-sm btn-outline" href="?page=<?= $listing['page'] + 1 ?>">Next &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
