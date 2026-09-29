<?php
/**
 * Recommended-posts cards + pagination for the auto-poster page. Rendered
 * server-side for the initial load and re-rendered via AJAX when paging, so
 * only this section reloads. Variables: $recommended, $recTotal, $recPage,
 * $recPages, $platform, $platformName, $apMaxLength.
 */
if (empty($recommended)): ?>
    <p class="muted">No recently-updated galleries to recommend. Upload new media, or every recent gallery already has a pending post (or was dismissed).</p>
<?php else: ?>
    <div class="ap-rec-grid">
        <?php foreach ($recommended as $rec): ?>
            <div class="ap-rec-card">
                <div class="ap-rec-thumb">
                    <?php if (!empty($rec['media'])): ?>
                        <img src="<?= e(file_url((string) $rec['media'][0]['filename'], 'thumb')) ?>" alt="" loading="lazy">
                    <?php endif; ?>
                </div>
                <div class="ap-rec-title" title="<?= e((string) $rec['gallery_title']) ?>"><?= e((string) $rec['gallery_title'] ?: 'Untitled gallery') ?></div>
                <div class="muted ap-rec-sub"><?= e(tzdate('M j, Y', $rec['newest_media_at'])) ?> &middot; <?= (int) $rec['media_count'] ?> file(s)</div>
                <form method="post" action="<?= url('/admin/auto-poster/queue/recommend') ?>" class="ap-rec-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="platform" value="<?= e($platform) ?>">
                    <input type="hidden" name="gallery_id" value="<?= (int) $rec['gallery_id'] ?>">
                    <textarea name="text" rows="2" maxlength="<?= $apMaxLength ?>" data-char-count data-char-count-id="rec-<?= (int) $rec['gallery_id'] ?>"><?= e((string) $rec['suggested_text']) ?></textarea>
                    <div class="muted ap-rec-count"><span data-char-count-out="rec-<?= (int) $rec['gallery_id'] ?>">0</span>/<?= $apMaxLength ?></div>
                    <label for="sched_<?= (int) $rec['gallery_id'] ?>" class="muted">Publish</label>
                    <input type="datetime-local" name="scheduled_at" id="sched_<?= (int) $rec['gallery_id'] ?>" value="<?= e((string) $rec['default_scheduled_at']) ?>">
                    <div class="ap-rec-actions">
                        <button type="submit" class="btn btn-sm">Queue</button>
                        <button type="submit" class="btn btn-sm ap-rec-post"
                                formaction="<?= url('/admin/auto-poster/queue/post') ?>"
                                onclick="return confirm('Post this now to <?= e($platformName) ?>?');">Post now</button>
                        <button type="submit" class="btn btn-sm btn-danger"
                                formaction="<?= url('/admin/auto-poster/queue/dismiss') ?>"
                                onclick="return confirm('Dismiss this recommended post?');">Dismiss</button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (($recPages ?? 1) > 1): ?>
        <div class="ap-rec-pager">
            <span class="muted">Page <?= (int) $recPage ?> of <?= (int) $recPages ?> &middot; <?= number_format((int) $recTotal) ?> recommended &middot; <?= (int) $recPage === 1 ? '' : 'later posts first &middot;' ?> next to process first</span>
            <span>
                <?php if ($recPage > 1): ?>
                    <button type="button" class="btn btn-sm btn-outline" data-rec-page="<?= (int) $recPage - 1 ?>">&laquo; Prev</button>
                <?php endif; ?>
                <?php for ($rp = 1; $rp <= (int) $recPages; $rp++): ?>
                    <button type="button" class="btn btn-sm <?= $rp === (int) $recPage ? 'btn' : 'btn-outline' ?>" data-rec-page="<?= $rp ?>"><?= $rp ?></button>
                <?php endfor; ?>
                <?php if ($recPage < $recPages): ?>
                    <button type="button" class="btn btn-sm btn-outline" data-rec-page="<?= (int) $recPage + 1 ?>">Next &raquo;</button>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>