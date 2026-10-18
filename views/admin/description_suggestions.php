<?php $title = 'AI gallery descriptions'; ?>

<p class="muted">
    The AI reads a gallery's media and writes a short description for galleries that have none.
    Nothing is applied automatically — edit the text if you like, then Accept to save it onto the
    gallery, or dismiss it with ✕. Driver: <strong><?= e($driver) ?></strong><?= $driver === 'off' ? ' (analysis disabled in .env)' : '' ?>.
</p>

<section style="display:flex;gap:1rem;flex-wrap:wrap;margin:1rem 0;">
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['pending'] ?></strong> pending proposal<?= (int) $stats['pending'] === 1 ? '' : 's' ?>
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['queued'] ?></strong> queued / running
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);<?= $stats['errors'] > 0 ? 'border-color:var(--red-600,#b91c1c);' : '' ?>">
        <strong><?= (int) $stats['errors'] ?></strong> error<?= $stats['errors'] === 1 ? '' : 's' ?>
    </div>
    <div style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:.75rem 1rem;background:var(--card-bg,#fff);">
        <strong><?= (int) $stats['missing'] ?></strong> gallery/ies without a description
    </div>
</section>

<?php if ($stats['missing'] > 0): ?>
<p style="margin:.75rem 0;">
    <form class="inline" method="post" action="<?= url('/admin/description-suggestions/backfill') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm">Describe galleries without a description (<?= (int) $stats['missing'] ?>)</button>
    </form>
    <span class="muted">Queues up to 200 per click; the worker picks them up within a minute.</span>
</p>
<?php endif; ?>

<?php if (empty($listing['rows'])): ?>
    <p class="muted" style="margin-top:1.5rem;">No pending descriptions. Galleries without a description are analyzed automatically after upload (or use the backfill above).</p>
<?php else: ?>
    <?php foreach ($listing['rows'] as $row): $g = $row['gallery']; $s = $row['suggestion']; ?>
        <section style="border:1px solid var(--card-border,#ddd);border-radius:8px;padding:1rem;background:var(--card-bg,#fff);margin-bottom:1rem;display:flex;gap:1rem;align-items:flex-start;">
            <?php if (!empty($row['cover'])): ?>
                <img src="<?= e(file_url($row['cover'], 'thumb')) ?>" alt="" width="120" height="90" style="object-fit:cover;border-radius:6px;flex-shrink:0;" loading="lazy">
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <strong><a href="<?= url('/admin/galleries/' . (int) $g['id']) ?>"><?= e($g['title']) ?></a></strong>
                <div class="muted" style="font-size:.85em;">Gallery #<?= (int) $g['id'] ?><?= !empty($row['analyzed_at']) ? ' · analyzed ' . e((string) $row['analyzed_at']) : '' ?></div>
                <?php if ($s !== null): ?>
                    <form method="post" action="<?= url('/admin/description-suggestions/accept') ?>" style="margin-top:.6rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <textarea name="text" rows="2" maxlength="600" style="width:100%;" aria-label="Proposed description"><?= e((string) $s['description']) ?></textarea>
                        <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                            <button type="submit" class="btn btn-sm">Accept<?= trim((string) ($g['description'] ?? '')) !== '' ? ' (replace current)' : '' ?></button>
                            <span class="muted" style="font-size:.85em;">Edit freely — the textarea is saved as-is.</span>
                        </div>
                    </form>
                    <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                        <form class="inline" method="post" action="<?= url('/admin/description-suggestions/dismiss') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline">Dismiss ✕</button>
                        </form>
                        <form class="inline" method="post" action="<?= url('/admin/description-suggestions/reanalyze') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="gallery_id" value="<?= (int) $g['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline">Regenerate</button>
                        </form>
                        <a class="btn btn-sm btn-outline" href="<?= url('/admin/galleries/' . (int) $g['id']) ?>">Manage</a>
                    </div>
                    <?php if (trim((string) ($g['description'] ?? '')) !== ''): ?>
                        <p class="muted" style="margin:.5rem 0 0;font-size:.85em;">Current: <?= e((string) $g['description']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($listing['pages'] > 1): ?>
        <nav style="display:flex;gap:.6rem;align-items:center;margin-top:1rem;" aria-label="Description pages">
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
