<?php $title = 'Import galleries'; ?>
<h1>Bulk import galleries</h1>
<p class="muted">Create galleries from a CSV. Photos are added afterward through the normal upload UI.</p>

<section class="card" style="padding:1rem;margin-bottom:1rem;">
    <h2 class="section-title">Upload a CSV</h2>
    <p class="muted" style="font-size:.85rem;">Columns (optional header): <code>title, description, type, min_level, is_secret, published_at, categories</code>. Type is <code>images</code> or <code>videos</code>; min_level 0–3; is_secret 0/1; published_at is a site-timezone datetime (blank = publish now); categories are pipe-separated names (existing categories only).</p>
    <form method="post" action="<?= url('/admin/galleries/import') ?>" enctype="multipart/form-data" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="file" name="csv" accept=".csv,text/csv" required>
        <label style="display:inline-flex;align-items:center;gap:.3rem;">
            <input type="checkbox" name="dry_run" value="1" checked> Dry run (preview only)
        </label>
        <button type="submit" class="btn">Upload CSV</button>
    </form>
</section>

<?php if (!empty($summary)): ?>
    <section class="card" style="padding:1rem;margin-bottom:1rem;">
        <h2 class="section-title">Dry-run result</h2>
        <p>
            <?php if (!empty($summary['dry_run'])): ?>
                Would create <strong><?= (int) $summary['would_create'] ?></strong> gallery(ies), <strong><?= (int) $summary['errors'] ?></strong> row error(s).
            <?php endif; ?>
        </p>
        <?php if (!empty($preview)): ?>
            <table style="width:100%;font-size:.85rem;">
                <thead><tr><th>Row</th><th>Title</th><th>Type</th><th>Level</th><th>Secret</th><th>Published</th><th>Categories</th></tr></thead>
                <tbody>
                    <?php foreach ($preview as $p): ?>
                        <tr>
                            <td><?= (int) $p['row'] ?></td>
                            <td><?= e($p['title']) ?></td>
                            <td><?= e($p['type']) ?></td>
                            <td><?= (int) $p['min_level'] ?></td>
                            <td><?= e($p['secret']) ?></td>
                            <td><?= e($p['published']) ?></td>
                            <td><?= (int) $p['categories'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted" style="font-size:.85rem;margin-top:.5rem;">Uncheck "Dry run" and upload again to import for real.</p>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <p class="sys-bad" style="margin-top:.5rem;"><?= e(implode(' ', $errors)) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<p class="muted" style="font-size:.9rem;"><a href="<?= url('/admin/galleries') ?>">&larr; Back to galleries</a></p>