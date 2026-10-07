<?php
// Star rating control. Expects: $gallery, $ratingAverage (float), $ratingCount (int),
// $userRating (int|null). Logged-in members get an inline 1-5 submit form.
$starsFull = (int) round((float) ($ratingAverage ?? 0));
$ratingLabel = $ratingCount > 0
    ? number_format((float) $ratingAverage, 1) . ' / 5 from ' . number_format((int) $ratingCount) . ' rating' . ($ratingCount === 1 ? '' : 's')
    : 'No ratings yet';
?>
<div class="star-rating" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:.75rem 0;">
    <span aria-label="<?= e($ratingLabel) ?>" title="<?= e($ratingLabel) ?>" style="letter-spacing:.1em;font-size:1.1rem;color:#f59e0b;">
        <?php for ($i = 1; $i <= 5; $i++): ?><?= $i <= $starsFull ? '&#9733;' : '&#9734;' ?><?php endfor; ?>
    </span>
    <span class="muted" style="font-size:.85rem;"><?= e($ratingLabel) ?></span>

    <?php if (\App\Core\Auth::check()): ?>
        <form method="post" action="<?= url('/galleries/' . (int) $gallery['id'] . '/rate') ?>" style="display:inline-flex;align-items:center;gap:.35rem;">
            <?= csrf_field() ?>
            <label class="muted" for="rate-select" style="font-size:.85rem;">Rate:</label>
            <select name="rating" id="rate-select" style="width:auto;">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>"<?= (int) ($userRating ?? 0) === $i ? ' selected' : '' ?>><?= str_repeat('&#9733;', $i) ?> (<?= $i ?>)</option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm">Save</button>
        </form>
    <?php endif; ?>
</div>