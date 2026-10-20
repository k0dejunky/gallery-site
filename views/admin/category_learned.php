<?php $title = 'What the AI has learned'; ?>

<p class="muted">
    The categorizer learns from the editor: a category that is accepted, one that is dismissed, one that
    is taken off a gallery and one that is added by hand are all lessons. The score is
    <code>(kept + 1) / (kept + removed + 2)</code> &mdash; an unseen category sits at 0.5 and only moves as
    the evidence comes in. Learning is <?= $learn ? '<strong>on</strong>' : '<strong>off</strong>' ?>
    (driver: <strong><?= e($driver) ?></strong>).
</p>

<p class="u-mt-15">
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/category-suggestions') ?>">Back to the suggestions</a>
    <form class="inline" method="post" action="<?= url('/admin/category-suggestions/forget') ?>"
          data-confirm="Forget every category decision the AI has learned? The editor's own tags stay.">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-danger">Forget everything</button>
    </form>
</p>

<?php if (empty($rows)): ?>
    <p class="muted">Nothing learned yet. Accept, dismiss, add or remove a category on a gallery and it shows up here.</p>
<?php else: ?>
<table class="users-table-wrap">
    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Kept</th>
                <th>Removed</th>
                <th>Added by hand</th>
                <th>Score</th>
                <th>Last</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= e($row['name']) ?></td>
                <td><?= (int) $row['kept'] ?></td>
                <td><?= (int) $row['removed'] ?></td>
                <td><?= (int) $row['added'] ?></td>
                <td>
                    <?= number_format((float) $row['score'], 2) ?>
                    <?php if ((int) $row['samples'] >= 3): ?>
                        <span class="muted u-fs-085">
                            <?= (float) $row['score'] >= 0.75 ? 'the editor keeps this' : ((float) $row['score'] <= 0.25 ? 'the editor removes this' : '') ?>
                        </span>
                    <?php else: ?>
                        <span class="muted u-fs-085">(<?= (int) $row['samples'] ?> decision<?= (int) $row['samples'] === 1 ? '' : 's' ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="muted u-fs-085"><?= e($row['last']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</table>
<?php endif; ?>
