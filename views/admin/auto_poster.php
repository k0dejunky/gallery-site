<?php
use App\Core\Platforms;
$platform      = (string) ($platform ?? 'x');
$platformName  = (string) ($platformName ?? 'Auto Poster');
$platformMeta  = is_array($platformMeta ?? null) ? $platformMeta : Platforms::get($platform);
$enabledCh    = is_array($enabledChannels ?? null) ? $enabledChannels : [];
$channel       = is_array($channel ?? null) ? $channel : [];
$apMaxLength   = (int) ($apTemplate['max_length'] ?? Platforms::maxLength($platform));
$apMinLength   = (int) ($platformMeta['max_length_min'] ?? 50);
$apLenCeil     = (int) ($platformMeta['max_length_max'] ?? $apMaxLength);
$apMediaMax    = (int) ($platformMeta['media_max'] ?? 4);
$apMedia       = (bool) ($platformMeta['media'] ?? false);
$apSensitive   = (string) ($platformMeta['sensitive'] ?? 'none');
$apInstances   = (bool) ($platformMeta['instances'] ?? false);
$apOAuth       = is_array($platformMeta['oauth'] ?? null) ? $platformMeta['oauth'] : null;
$apFields      = (array) ($platformMeta['fields'] ?? []);
$apTargetNames = Platforms::targetFieldNames($platform);
$apIsTitleBody = Platforms::isTitleBody($platform);
$title         = 'Auto Poster — ' . $platformName;
$platformPath  = $platform === 'x' ? '/admin/auto-poster' : '/admin/auto-poster/' . $platform;
?>

<?php // ----- Platform switch: one tab per enabled posting option ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Auto Poster</h2>
        <div role="tablist" aria-label="Platform" id="ap-platform-switch" style="display:inline-flex;gap:.25rem;border:1px solid #d1d5db;border-radius:8px;padding:.25rem;flex-wrap:wrap;">
            <?php foreach ($enabledCh as $ch): ?>
                <?php $chKey = (string) $ch['key']; $chLabel = (string) $ch['label']; $active = $chKey === $platform; ?>
                <a role="tab" id="ap-tab-<?= e($chKey) ?>" aria-selected="<?= $active ? 'true' : 'false' ?>"
                   href="<?= e($chKey === 'x' ? url('/admin/auto-poster') : url('/admin/auto-poster/' . $chKey)) ?>"
                   style="padding:.35rem .9rem;border-radius:6px;text-decoration:none;font-size:.9rem;<?= $active ? 'background:#4f46e5;color:#fff;font-weight:600;' : 'color:#374151;' ?>"><?= e($chLabel) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="muted" style="font-size:.85rem;margin:0;">
        Everything here belongs to <?= e($platformName) ?>: its post template, credentials, recommended posts, the posting queue and the posting log.
        Each tab formats its posts automatically for that channel.
        Schedule times use the site timezone set on Settings.
    </p>
    <form method="post" action="<?= url('/admin/auto-poster/channels/enable') ?>" style="margin-top:.6rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="platform" value="<?= e($platform) ?>">
        <span class="muted" style="font-size:.8rem;">Channels enabled for the auto-queue:</span>
        <?php foreach (Platforms::enabled() as $ch): ?>
            <?php $chKey = (string) $ch['key']; ?>
            <label class="chip" style="margin-left:.4rem;"><input type="checkbox" name="channels[]" value="<?= e($chKey) ?>" <?= in_array($chKey, (array) ($config['enabled_channels'] ?? Platforms::enabledKeys()), true) ? 'checked' : '' ?>> <?= e((string) $ch['label']) ?></label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-sm" style="margin-left:.5rem;">Save channels</button>
    </form>
</div>

<?php // ----- Platform post template ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2><?= e($platformName) ?> post template</h2>
        <span class="muted" style="font-size:.85rem;">The blueprint every <?= e($platformName) ?> post is generated from. Auto-loaded defaults are fine as-is; edit the wording, link and hashtag count &mdash; new posts pick it up immediately.</span>
    </div>
    <form method="post" action="<?= url('/admin/auto-poster/template/save') ?>" data-ap-template>
        <?= csrf_field() ?>
        <input type="hidden" name="platform" value="<?= e($platform) ?>">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;margin-top:.75rem;">
            <div>
                <label for="ap-pattern"><strong>Post pattern</strong> <span class="muted" style="font-weight:400;">(everything outside the tokens is posted verbatim)</span></label>
                <textarea name="pattern" id="ap-pattern" rows="4" maxlength="2048"
                          style="width:100%;box-sizing:border-box;font-size:.9rem;padding:.5rem .6rem;border:1px solid #d1d5db;border-radius:4px;word-wrap:break-word;resize:vertical;"><?= e((string) $apTemplate['pattern']) ?></textarea>
                <p class="muted" style="font-size:.78rem;margin-top:.3rem;">
                    Tokens: <code>{title}</code> &middot; <code>{sep}</code> (a &ldquo;&mdash;&rdquo; only when both title and description exist) &middot; <code>{description}</code> &middot; <code>{hashtags}</code>. Put any link/URL you want in the pattern text itself (e.g. <code>amethyst2213.com</code>).
                </p>
                <div style="display:flex;flex-wrap:wrap;gap:.75rem;margin-top:.75rem;">
                    <label style="font-size:.85rem;"><span class="muted">Hashtags per post:</span><br>
                        <input type="number" name="max_tags" min="0" max="60" value="<?= (int) $apTemplate['max_tags'] ?>" style="width:5rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <label style="font-size:.85rem;"><span class="muted">Max characters:</span><br>
                        <input type="number" name="max_length" min="<?= $apMinLength ?>" max="<?= $apLenCeil ?>" value="<?= (int) $apTemplate['max_length'] ?>" style="width:6rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <label style="font-size:.85rem;"><span class="muted">Default schedule (min):</span><br>
                        <input type="number" name="schedule_minutes" min="1" max="10080" value="<?= (int) $apTemplate['schedule_minutes'] ?>" style="width:7rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <label style="font-size:.85rem;"><span class="muted">Recent window (days):</span><br>
                        <input type="number" name="recent_days" min="1" max="90" value="<?= (int) $apTemplate['recent_days'] ?>" style="width:6rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <?php if ($apMedia): ?>
                    <label style="font-size:.85rem;"><span class="muted">Media per post:</span><br>
                        <input type="number" name="max_media" min="0" max="<?= $apMediaMax ?>" value="<?= (int) $apTemplate['max_media'] ?>" style="width:5rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <?php endif; ?>
                    <?php if (($apTemplate['video'] ?? 'none') === 'screenshots'): ?>
                    <label style="font-size:.85rem;"><span class="muted">Video screenshots:</span><br>
                        <input type="number" name="screenshots" min="1" max="4" value="<?= (int) $apTemplate['screenshots'] ?>" style="width:5rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                    <?php endif; ?>
                    <label style="font-size:.85rem;"><span class="muted">Preview blur %:</span><br>
                        <input type="number" name="blur_percent" min="0" max="100" value="<?= (int) $apTemplate['blur_percent'] ?>" style="width:5rem;font-size:.85rem;padding:.2rem .3rem;border:1px solid #d1d5db;border-radius:4px;"></label>
                </div>
                <p style="margin-top:.75rem;">
                    <label class="muted" style="font-size:.85rem;display:block;">Banned words <span style="font-weight:400;">(never appear in a post; comma-separated, optional)</span><br>
                        <input type="text" name="banned_words" value="<?= e(implode(', ', $apTemplate['banned_words'] ?? [])) ?>" placeholder="nipple, nipples" style="width:100%;box-sizing:border-box;font-size:.85rem;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;">
                    </label>
                </p>
            </div>
            <div>
                <div style="border:1px dashed #d1d5db;border-radius:6px;padding:.6rem .75rem;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <span class="muted" style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Live preview</span>
                        <span id="ap-preview-count" class="muted" style="font-size:.75rem;font-variant-numeric:tabular-nums;">0/<?= $apMaxLength ?></span>
                    </div>
                    <p id="ap-preview" style="font-size:.88rem;color:#374151;margin:.4rem 0 0;word-wrap:break-word;white-space:pre-wrap;">&mdash;</p>
                </div>
                <p class="muted" style="font-size:.75rem;margin-top:.5rem;">The exact text is baked at queue time from each gallery&rsquo;s real title, description and categories.</p>
            </div>
        </div>
        <div style="margin-top:.75rem;">
            <button type="submit" class="btn">Save template</button>
            <?php if (!empty($templatePreview)): ?><span class="muted" style="font-size:.78rem;margin-left:.5rem;">Current saved result: &ldquo;<?= e((string) $templatePreview) ?>&rdquo;</span><?php endif; ?>
        </div>
    </form>
</div>

<?php // ----- Recommended posts: generated from recent uploads (per platform) ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Recommended posts</h2>
        <span class="muted" style="font-size:.85rem;">One post per <?= e($platformName) ?> gallery with uploads in the last <?= (int) ($apTemplate['recent_days'] ?? 14) ?> days, carrying up to <?= (int) ($apTemplate['max_media'] ?? 0) ?> of its newest media. A gallery already handled here&mdash;queued, posted, or dismissed&mdash;isn&rsquo;t offered again. Ordered by post time, next to process first.</span>
    </div>
    <div id="ap-rec-body" style="margin-top:.75rem;">
        <?php require __DIR__ . '/partials/recommendations.php'; ?>
    </div>
</div>

<?php // ----- Pending queue (scoped to this platform) ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <h2>Posting queue</h2>
            <button type="button" class="btn btn-sm ap-queue-toggle" data-target="ap-queue-body" aria-expanded="true">Collapse</button>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <span class="muted" style="font-size:.85rem;">
                <?= number_format((int) $queueCounts['queued']) ?> queued &middot;
                <?= number_format((int) $queueCounts['posted']) ?> posted &middot;
                <?= number_format((int) $queueCounts['failed']) ?> failed &middot;
                <?= number_format((int) $queueCounts['skipped']) ?> skipped
            </span>
            <?php if (!empty($queue) && count($queue) > 0): ?>
                <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/post-all') ?>"
                      onsubmit="return confirm('Post all <?= number_format(count($queue)) ?> queued item(s) to <?= e($platformName) ?> now?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="platform" value="<?= e($platform) ?>">
                    <button type="submit" class="btn btn-sm">Post all queued</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div id="ap-queue-body">
    <?php if (empty($queue)): ?>
        <p class="muted">The queue is empty — add a recommended post above.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Media</th>
                    <th>Text</th>
                    <th>Scheduled</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue as $item): ?>
                    <?php $media = \App\Models\AutoPostQueue::mediaFiles($item); ?>
                    <tr>
                        <td><?= (int) $item['id'] ?></td>
                        <td style="white-space:nowrap;">
                            <?php if (!empty($media)): ?>
                                <?php foreach ($media as $mf): ?>
                                    <img src="<?= e(file_url((string) $mf['filename'], 'thumb')) ?>" alt="" width="40" height="30" style="border-radius:4px;object-fit:cover;background:#000;vertical-align:middle;margin-right:2px;">
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="muted">no media</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:400px;font-size:.85rem;color:#374151;word-wrap:break-word;"><?= e((string) $item['text']) ?></td>
                        <td>
                            <form method="post" action="<?= url('/admin/auto-poster/queue/schedule') ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                <input type="datetime-local" name="scheduled_at" value="<?= e(\App\Models\AutoPostQueue::displaySchedule($item['scheduled_at'] ?? null, $platform)) ?>" style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;">
                                <button type="submit" class="btn btn-sm">Set</button>
                            </form>
                            <div class="muted" style="font-size:.75rem;margin-top:.1rem;">
                                <?php if (!empty($item['scheduled_at'])): ?>
                                    <?php $apUntil = (int) strtotime($item['scheduled_at'] . ' UTC'); ?>
                                    <span class="ap-countdown" data-until="<?= $apUntil ?>" data-synced="<?= time() ?>" data-past-label="publishing now on next worker run" style="font-variant-numeric:tabular-nums;">calculating&hellip;</span>
                                <?php else: ?>
                                    no schedule
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <button type="button" class="btn btn-sm btn-outline" data-edit-queue="<?= (int) $item['id'] ?>" aria-expanded="false">Edit</button>
                            <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/post') ?>" onsubmit="return confirm('Post this now to <?= e($platformName) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn btn-sm">Post now</button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/dismiss') ?>"
                                  onsubmit="return confirm('Dismiss this queued post?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Dismiss</button>
                            </form>
                        </td>
                    </tr>
                    <?php // Inline editor for a queued post: change the text and/or schedule. ?>
                    <tr class="ap-queue-edit-row" id="ap-queue-edit-<?= (int) $item['id'] ?>" style="display:none;">
                        <td colspan="5" style="background:var(--pink-100,#fdf2f8);border:1px solid var(--pink-300,#f9a8d4);border-radius:8px;">
                            <form method="post" action="<?= url('/admin/auto-poster/queue/edit') ?>" style="display:flex;flex-direction:column;gap:.5rem;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="queue_id" value="<?= (int) $item['id'] ?>">
                                <div>
                                    <label class="muted" style="display:block;margin-bottom:.2rem;font-size:.8rem;">Post text</label>
                                    <textarea name="text" rows="3" maxlength="<?= $apMaxLength ?>" data-char-count data-char-count-id="qedit-<?= (int) $item['id'] ?>"
                                              style="width:100%;box-sizing:border-box;font-size:.85rem;font-family:inherit;padding:.4rem .5rem;border:1px solid #d1d5db;border-radius:4px;"
                                              aria-label="Editable text for queued post #<?= (int) $item['id'] ?>"><?= e((string) $item['text']) ?></textarea>
                                <div class="muted" style="font-size:.72rem;text-align:right;"><span data-char-count-out="qedit-<?= (int) $item['id'] ?>">0</span>/<?= $apMaxLength ?></div>
                                </div>
                                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                                    <label class="muted" style="font-size:.8rem;">Schedule:</label>
                                    <input type="datetime-local" name="scheduled_at" value="<?= e(\App\Models\AutoPostQueue::displaySchedule($item['scheduled_at'] ?? null, $platform)) ?>" style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;">
                                    <span class="muted" style="font-size:.72rem;">leave blank to clear the schedule (publish on next worker run)</span>
                                    <span style="flex:1"></span>
                                    <button type="submit" class="btn btn-sm">Save changes</button>
                                    <button type="button" class="btn btn-sm btn-outline" data-cancel-edit="<?= (int) $item['id'] ?>">Cancel</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>

<?php // ----- Recent posts (scoped to this platform): repost or reschedule ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Recent <?= e($platformName) ?> posts</h2>
        <span class="muted" style="font-size:.85rem;">Re-publish a past <?= e($platformName) ?> post now, or schedule it to go out again later.</span>
    </div>
    <?php if (empty($recentPosts)): ?>
        <p class="muted">No <?= e($platformName) ?> posts recorded yet — posted, failed and skipped items will appear here.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="ap-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Platform</th>
                        <th>Gallery</th>
                        <th>Text</th>
                        <th>Posted</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentPosts as $rp): ?>
                    <?php
                    $rpStatus = (string) ($rp['status'] ?? 'posted');
                    $rpPill   = $rpStatus === 'posted' ? 'success' : ($rpStatus === 'failed' ? 'failed' : 'pending');
                    $rpTs     = strtotime((string) ($rp['posted_at'] ?? $rp['created_at'] ?? ''));
                    $rpMax    = \App\Core\Platforms::maxLength((string) ($rp['platform'] ?? ''));
                    ?>
                    <?php $rpEditable = $rpStatus === 'failed'; ?>
                    <tr>
                        <td><span class="ap-pill ap-pill-<?= e($rpPill) ?>"><span class="ap-dot"></span><?= e(ucfirst($rpStatus)) ?></span></td>
                        <td><?= e(\App\Core\Platforms::label((string) ($rp['platform'] ?? ''))) ?></td>
                        <td class="muted" style="font-size:.8rem;"><?= e((string) $rp['gallery_title']) ?></td>
                        <?php if ($rpEditable): ?>
                            <?php // Failed posts: editable text so the wording can be fixed, then reposted/scheduled. ?>
                            <td style="max-width:340px;font-size:.85rem;" class="rp-edit">
                                <textarea name="text" form="ap-edit-<?= (int) $rp['id'] ?>" maxlength="<?= $rpMax ?>" rows="2" data-char-count data-char-count-id="rp-<?= (int) $rp['id'] ?>"
                                          style="width:100%;box-sizing:border-box;font-size:.85rem;font-family:inherit;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;"
                                          aria-label="Editable text for post #<?= (int) $rp['id'] ?>"><?= e((string) $rp['text']) ?></textarea>
                                <div class="muted" style="font-size:.72rem;margin-top:.15rem;text-align:right;"><span data-char-count-out="rp-<?= (int) $rp['id'] ?>">0</span>/<?= $rpMax ?> &middot; Edit the wording, then click Repost now or Reschedule.</div>
                            </td>
                        <?php else: ?>
                            <td style="max-width:320px;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e((string) $rp['text']) ?>">
                                <?php if ($rpStatus === 'posted' && !empty($rp['post_url'])): ?>
                                    <a href="<?= e((string) $rp['post_url']) ?>" target="_blank" rel="noopener" class="ap-link"><?= e((string) $rp['text']) ?></a>
                                <?php else: ?>
                                    <?= e((string) $rp['text']) ?>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="ap-time"><span class="muted"><?= $rpTs ? e(tzdate('Y-m-d H:i', $rpTs)) : '&mdash;' ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($rpEditable): ?>
                                <?php // One shared form; the textarea/schedule/buttons link to it via the HTML5 "form" attribute. ?>
                                <form id="ap-edit-<?= (int) $rp['id'] ?>" method="post" action="<?= url('/admin/auto-poster/history/edit') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                </form>
                                 <input type="datetime-local" name="scheduled_at" form="ap-edit-<?= (int) $rp['id'] ?>"
                                        value="<?= e(\App\Models\AutoPostQueue::rescheduleDefault($platform)) ?>"
                                       style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;width:9.5rem;"
                                       aria-label="Schedule repost time for post #<?= (int) $rp['id'] ?>">
                                <button type="submit" name="action" value="repost" form="ap-edit-<?= (int) $rp['id'] ?>"
                                        class="btn btn-sm" title="Publish the edited text now">Repost now</button>
                                <button type="submit" name="action" value="reschedule" form="ap-edit-<?= (int) $rp['id'] ?>"
                                        class="btn btn-sm" title="Queue the edited text to publish at the chosen time">Reschedule</button>
                            <?php else: ?>
                                <form class="inline" method="post" action="<?= url('/admin/auto-poster/history/repost') ?>"
                                      onsubmit="return confirm('Repost #<?= (int) $rp['id'] ?> to <?= e($platformName) ?> now?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                    <button type="submit" class="btn btn-sm" title="Post the same content again right away">Repost</button>
                                </form>
                                <form class="inline" method="post" action="<?= url('/admin/auto-poster/history/reschedule') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                     <input type="datetime-local" name="scheduled_at"
                                            value="<?= e(\App\Models\AutoPostQueue::rescheduleDefault($platform)) ?>"
                                           style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;width:9.5rem;"
                                           aria-label="Schedule repost time for post #<?= (int) $rp['id'] ?>">
                                    <button type="submit" class="btn btn-sm" title="Queue to publish again at the chosen time">Reschedule</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php // Pagination for the recent-posts list (25 per page). ?>
        <?php if (($recentPages ?? 1) > 1): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-top:.75rem;">
                <span class="muted" style="font-size:.8rem;">Page <?= (int) ($recentPage ?? 1) ?> of <?= (int) $recentPages ?> &middot; <?= number_format((int) ($recentTotal ?? 0)) ?> recorded post<?= ((int) ($recentTotal ?? 0)) === 1 ? '' : 's' ?></span>
                <span style="display:flex;gap:.35rem;flex-wrap:wrap;">
                    <?php
                    $recentBase = $platformPath;
                    $recentCur  = (int) ($recentPage ?? 1);
                    $recentMax  = (int) $recentPages;
                    $window     = 5;
                    $recentLo   = max(1, $recentCur - $window);
                    $recentHi   = min($recentMax, $recentCur + $window);
                    ?>
                    <?php if ($recentCur > 1): ?>
                        <a class="btn btn-sm btn-outline" href="<?= e($recentBase . '?page=' . ($recentCur - 1)) ?>">&laquo; Newer</a>
                    <?php endif; ?>
                    <?php for ($rp = $recentLo; $rp <= $recentHi; $rp++): ?>
                        <a class="btn btn-sm <?= $rp === $recentCur ? 'btn' : 'btn-outline' ?>"
                           href="<?= e($recentBase . '?page=' . $rp) ?>"><?= $rp ?></a>
                    <?php endfor; ?>
                    <?php if ($recentCur < $recentMax): ?>
                        <a class="btn btn-sm btn-outline" href="<?= e($recentBase . '?page=' . ($recentCur + 1)) ?>">Older &raquo;</a>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="stats-grid">
    <?php // ----- Platform credentials (registry-driven) ----- ?>
    <div class="stats-panel">
        <h2><?= e($platformName) ?> credentials</h2>
        <form method="post" action="<?= url('/admin/auto-poster/channel/save') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="platform" value="<?= e($platform) ?>">
            <?php foreach ($apFields as $field): ?>
                <?php
                [$fName, $fLabel, $fType, $fPlaceholder] = array_pad($field, 4, '');
                $fSecret = count($field) >= 5 ? (bool) $field[4] : false;
                $fValue  = (string) ($channel[$fName] ?? '');
                ?>
                <p>
                    <label for="apf-<?= e($fName) ?>"><?= e($fLabel) ?></label><br>
                    <?php if ($fType === 'textarea'): ?>
                        <textarea name="<?= e($fName) ?>" id="apf-<?= e($fName) ?>" rows="3" placeholder="<?= e((string) $fPlaceholder) ?>" style="width:100%;box-sizing:border-box;"><?= e($fValue) ?></textarea>
                    <?php else: ?>
                        <input type="<?= e($fType) ?>" name="<?= e($fName) ?>" id="apf-<?= e($fName) ?>" value="<?= $fType === 'password' ? '' : e($fValue) ?>" placeholder="<?= $fSecret && $fValue !== '' ? 'Leave blank to keep the saved value' : e((string) $fPlaceholder) ?>" style="width:100%;box-sizing:border-box;">
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
            <button type="submit" class="btn">Save <?= e($platformName) ?> Settings</button>
        </form>

        <?php if ($apInstances): ?>
            <?php // Multi-instance accounts (Mastodon family): per-instance tokens. ?>
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:.75rem 0;">
            <p style="font-size:.85rem;margin:.4rem 0;"><strong>Instances</strong> — one bot account + token per instance.</p>
            <?php if (!empty($channel['instances']) && is_array($channel['instances'])): ?>
                <ul style="margin:.4rem 0 .75rem;padding-left:1.1rem;font-size:.85rem;">
                    <?php foreach ($channel['instances'] as $instHost => $instToken): ?>
                        <li style="margin-bottom:.3rem;">
                            <?= e((string) $instHost) ?>
                            <form class="inline" method="post" action="<?= url('/admin/auto-poster/channel/instance/remove') ?>" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="platform" value="<?= e($platform) ?>">
                                <input type="hidden" name="instance_host" value="<?= e((string) $instHost) ?>">
                                <button type="submit" class="btn btn-sm btn-outline" style="font-size:.72rem;">Remove</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted" style="font-size:.8rem;">No instances configured yet.</p>
            <?php endif; ?>
            <form method="post" action="<?= url('/admin/auto-poster/channel/instance') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="platform" value="<?= e($platform) ?>">
                <p><label style="font-size:.85rem;">Instance host</label><br><input type="text" name="instance_host" placeholder="mastodon.social" style="width:100%;box-sizing:border-box;"></p>
                <p><label style="font-size:.85rem;">Access token</label><br><input type="password" name="instance_token" style="width:100%;box-sizing:border-box;"></p>
                <button type="submit" class="btn btn-sm">Add instance</button>
            </form>
        <?php endif; ?>

        <?php if ($apOAuth): ?>
            <p style="margin-top:.75rem;">
                <?php if (!empty($authHealth) && $authHealth['ok']): ?>
                    <span style="color:var(--success,#2e7d32);font-weight:600;">&#10003; Authorized — <?= e((string) ($authHealth['note'] ?? 'connected')) ?></span>
                <?php elseif (!empty($authHealth)): ?>
                    <span style="color:var(--danger,#c62828);font-weight:600;">&#9888; Token invalid — <?= e((string) ($authHealth['error'] ?? 'token invalid')) ?></span><br>
                    <a class="btn" style="margin-top:.5rem;" href="<?= url('/admin/auto-poster/' . $platform . '/authorize') ?>">Re-authorize <?= e($platformName) ?></a>
                <?php elseif (!empty($channel['refresh_token'])): ?>
                    <span class="muted" style="display:block;margin-bottom:.5rem;">Authorization state unknown — could not verify the token.</span>
                    <a class="btn" href="<?= url('/admin/auto-poster/' . $platform . '/authorize') ?>">Re-authorize <?= e($platformName) ?></a>
                <?php else: ?>
                    <span class="muted" style="display:block;margin-bottom:.5rem;">Not authorized yet. Complete the flow below to enable posting.</span>
                    <a class="btn" href="<?= url('/admin/auto-poster/' . $platform . '/authorize') ?>">Authorize <?= e($platformName) ?></a>
                <?php endif; ?>
            </p>
        <?php elseif (!empty($authHealth)): ?>
            <p style="margin-top:.75rem;">
                <?php if ($authHealth['ok']): ?>
                    <span style="color:var(--success,#2e7d32);font-weight:600;">&#10003; Connected — <?= e((string) ($authHealth['note'] ?? 'connected')) ?></span>
                <?php else: ?>
                    <span style="color:var(--danger,#c62828);font-weight:600;">&#9888; <?= e((string) ($authHealth['error'] ?? 'token invalid')) ?></span>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="muted" style="margin-top:.75rem;font-size:.82rem;">Not authorized yet. Save the credentials above to connect this channel.</p>
        <?php endif; ?>

        <p class="muted" style="font-size:.8rem;margin-top:.5rem;">
            <strong>Setup:</strong> <?= e((string) ($platformMeta['requires'] ?? '')) ?>
        </p>
    </div>

    <?php // ----- Compose a post on this platform (auto-formatted) ----- ?>
    <div class="stats-panel">
        <h2>Post to <?= e($platformName) ?></h2>
        <form method="post" action="<?= url('/admin/auto-poster/channel/post') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="platform" value="<?= e($platform) ?>">
            <?php if ($apIsTitleBody): ?>
                <p>
                    <label for="ap-post-title">Title</label><br>
                    <input type="text" name="title" id="ap-post-title" maxlength="300" style="width:100%;box-sizing:border-box;">
                </p>
            <?php endif; ?>
            <p>
                <label for="ap-post-text">Text</label><br>
                <textarea name="text" id="ap-post-text" rows="5" maxlength="<?= $apMaxLength ?>" data-char-count data-char-count-id="compose" placeholder="Post content (max <?= $apMaxLength ?> characters)..." style="width:100%;box-sizing:border-box;"></textarea>
                <span class="muted" style="font-size:0.8rem;"><span data-char-count-out="compose">0</span>/<?= $apMaxLength ?></span>
            </p>
            <?php if ($apMedia): ?>
            <p>
                <label for="ap-post-media">Images / video (optional)</label><br>
                <input type="file" name="media[]" id="ap-post-media" accept="image/*,video/*" multiple style="width:100%;box-sizing:border-box;">
                <span class="muted" style="font-size:0.8rem;">Up to <?= $apMediaMax ?> media items.</span>
            </p>
            <?php endif; ?>
            <?php foreach ($apTargetNames as $tField): ?>
                <?php $tLabel = ucwords(str_replace('_', ' ', $tField)); ?>
                <p>
                    <label for="ap-post-<?= e($tField) ?>"><?= e($tLabel) ?> <span class="muted">(optional — defaults to the saved value)</span></label><br>
                    <input type="text" name="<?= e($tField) ?>" id="ap-post-<?= e($tField) ?>" value="<?= e((string) ($channel[$tField] ?? '')) ?>" style="width:100%;box-sizing:border-box;">
                </p>
            <?php endforeach; ?>
            <?php if ($apSensitive === 'boolean'): ?>
                <p>
                    <label class="chip"><input type="checkbox" name="sensitive" value="1" checked> Mark as sensitive / NSFW</label>
                </p>
            <?php endif; ?>
            <button type="submit" class="btn">Post to <?= e($platformName) ?></button>
        </form>
    </div>
</div>

<?php // ----- Posting log (scoped to this platform) ----- ?>
<style>
    .ap-log { margin-top: 1rem; }
    .ap-log-card { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; overflow: hidden; }
    .ap-log-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; padding: .85rem 1.1rem; border-bottom: 1px solid var(--pink-300); }
    .ap-log-head h2 { margin: 0; font-size: 1.05rem; color: var(--purple-800); border: 0; padding: 0; }
    .ap-log-summary { font-size: .8rem; color: var(--purple-700); }
    .ap-log-empty { padding: 1.25rem 1.1rem; font-size: .9rem; color: var(--purple-800); opacity: .75; }
    .ap-log details.ap-log-card > summary { cursor: pointer; list-style: none; user-select: none; }
    .ap-log details.ap-log-card > summary::-webkit-details-marker { display: none; }
    .ap-log details.ap-log-card > summary::after { content: '▾'; margin-left: auto; font-size: .8rem; color: var(--purple-600); transition: transform .15s ease; }
    .ap-log details.ap-log-card:not([open]) > summary::after { transform: rotate(-90deg); }
    .ap-log-body { border-top: 1px solid var(--pink-300); }
    .ap-log .ap-table { width: 100%; border-collapse: collapse; }
    .ap-log .ap-table th { text-align: left; padding: .5rem .75rem; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--purple-700); border-bottom: 2px solid var(--pink-300); white-space: nowrap; }
    .ap-log .ap-table td { padding: .6rem .75rem; border-bottom: 1px solid rgba(244,114,182,.25); vertical-align: middle; font-size: .88rem; color: var(--purple-800); }
    .ap-log .ap-table tr:last-child td { border-bottom: 0; }
    .ap-log .ap-table tbody tr:hover { background: rgba(244,114,182,.10); }
    .ap-time { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .ap-time-relative { display: block; font-weight: 600; }
    .ap-time-absolute { font-size: .78rem; opacity: .7; }
    .ap-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .18rem .6rem; border-radius: 999px; font-size: .72rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; line-height: 1.3; }
    .ap-pill .ap-dot { width: .5rem; height: .5rem; border-radius: 50%; background: currentColor; opacity: .9; }
    .ap-pill-success, .ap-pill-info { color: #1d7a3a; background: rgba(29,122,58,.12); }
    .ap-pill-failed, .ap-pill-error { color: #b3261e; background: rgba(179,38,30,.12); }
    .ap-pill-pending { color: #92400e; background: rgba(146,64,14,.12); }
    .ap-target { font-weight: 600; }
    .ap-msg { overflow-wrap: anywhere; }
    .ap-msg a.ap-link { color: var(--purple-700); text-decoration: none; font-weight: 600; }
    .ap-msg a.ap-link:hover { text-decoration: underline; }
    .ap-msg .ap-err { color: #b3261e; }
    .ap-rec-grid { display: grid; grid-template-columns: repeat(7, minmax(0,1fr)); gap: .6rem; }
    .ap-rec-card { border: 1px solid var(--border,#e5e7eb); border-radius: .5rem; padding: .5rem; display: flex; flex-direction: column; gap: .4rem; background: #fff; min-width: 0; }
    .ap-rec-thumb { aspect-ratio: 16/9; border-radius: 4px; overflow: hidden; background: #f3f4f6; }
    .ap-rec-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ap-rec-title { font-weight: 600; font-size: .8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ap-rec-sub { font-size: .72rem; }
    .ap-rec-form { display: flex; flex-direction: column; gap: .35rem; font-size: .78rem; }
    .ap-rec-form textarea { width: 100%; box-sizing: border-box; font-size: .75rem; color: #374151; background: #fff; padding: .35rem .4rem; border-radius: 4px; border: 1px solid #d1d5db; resize: vertical; }
    .ap-rec-count { font-size: .68rem; text-align: right; }
    .ap-rec-form input[type="datetime-local"] { width: 100%; box-sizing: border-box; font-size: .75rem; padding: .15rem .25rem; border: 1px solid #d1d5db; border-radius: 4px; }
    .ap-rec-actions { display: flex; gap: .3rem; flex-wrap: wrap; }
    .ap-rec-actions .btn { flex: 1 1 auto; font-size: .72rem; padding: .25rem .4rem; margin: 0; }
    .ap-rec-post { background: #0ea5e9 !important; color: #fff !important; }
    .ap-rec-pager { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    .ap-rec-pager .btn { margin: 0 .2rem .2rem 0; }
    .ap-rec-loading { opacity: .5; pointer-events: none; transition: opacity .15s; }
    @media (max-width: 1500px) { .ap-rec-grid { grid-template-columns: repeat(5, minmax(0,1fr)); } }
    @media (max-width: 1100px) { .ap-rec-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } }
    @media (max-width: 640px)  { .ap-rec-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 600px) {
        .ap-log .ap-table th { display: none; }
        .ap-log .ap-table, .ap-log .ap-table tbody, .ap-log .ap-table tr, .ap-log .ap-table td { display: block; width: 100%; }
        .ap-log .ap-table tr { padding: .5rem .75rem; border-bottom: 1px solid rgba(244,114,182,.25); }
        .ap-log .ap-table td { border: 0; padding: .15rem .75rem; }
    }
</style>
<div class="stats-panel ap-log">
    <details class="ap-log-card" open>
        <summary class="ap-log-head">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                <h2><?= e($platformName) ?> posting log</h2>
                <?php $logCount  = count($log); ?>
                <?php $logOk     = count(array_filter($log, fn($l) => ($l['status'] ?? '') === 'success')); ?>
                <span class="ap-log-summary"><?= (int) $logCount ?> entries &middot; <?= (int) $logOk ?> succeeded &middot; <?= (int) ($logCount - $logOk) ?> failed</span>
            </div>
        </summary>
        <div class="ap-log-body">
        <?php if (empty($log)): ?>
            <div class="ap-log-empty">No <?= e($platformName) ?> posts have been made yet.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="ap-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Platform</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Message / URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $entry): ?>
                            <?php
                            $apStatus  = strtolower((string) ($entry['status'] ?? ''));
                            $apMsg     = (string) ($entry['message'] ?? '');
                            $apIsUrl   = stripos($apMsg, 'http') === 0;
                            $apPillCls = in_array($apStatus, ['success', 'failed', 'error', 'pending'], true) ? $apStatus : 'pending';
                            $apTs      = strtotime((string) ($entry['created_at'] ?? ''));
                            ?>
                            <tr class="ap-row">
                                <td class="ap-time">
                                    <span class="ap-time-relative" data-uts="<?= $apTs ?: 0 ?>">&mdash;</span>
                                    <span class="ap-time-absolute"><?= $apTs ? e(tzdate('Y-m-d H:i', $apTs)) : '&mdash;' ?></span>
                                </td>
                                <td><?= e(\App\Core\Platforms::label((string) ($entry['platform'] ?? ''))) ?></td>
                                <td class="ap-target"><?= e((string) ($entry['target'] ?? '')) ?></td>
                                <td>
                                    <span class="ap-pill ap-pill-<?= e($apPillCls) ?>">
                                        <span class="ap-dot"></span><?= e(ucfirst($apStatus ?: 'Pending')) ?>
                                    </span>
                                </td>
                                <td class="ap-msg">
                                    <?php if ($apIsUrl): ?>
                                        <a class="ap-link" href="<?= e($apMsg) ?>" target="_blank" rel="noopener"><?= e($apMsg) ?></a>
                                    <?php else: ?>
                                        <span<?= ($apStatus === 'failed' || $apStatus === 'error') ? ' class="ap-err"' : '' ?>><?= $apMsg !== '' ? e($apMsg) : '&mdash;' ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
            <div style="padding:.6rem 1.1rem;text-align:right;border-top:1px solid var(--pink-300);">
                <form method="post" action="<?= url('/admin/auto-poster/clear-log') ?>" onsubmit="return confirm('Clear the <?= e($platformName) ?> posting log?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="platform" value="<?= e($platform) ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Clear <?= e($platformName) ?> Log</button>
                </form>
            </div>
        </div>
    </details>
</div>

<script>
(function () {
    // Real-time character counters for every post-text field.
    (function () {
        function update(ta) {
            var id = ta.getAttribute('data-char-count-id');
            var out = document.querySelector('[data-char-count-out="' + id + '"]');
            if (out) { out.textContent = ta.value.length; }
        }
        window.initApCharCounts = function () {
            document.querySelectorAll('textarea[data-char-count]').forEach(function (ta) {
                update(ta);
                ta.removeEventListener('input', update);
                ta.addEventListener('input', function () { update(ta); });
            });
        };
        window.initApCharCounts();
    })();

    // Live countdown to each queued post's publish time.
    (function () {
        var load = Date.now() / 1000;
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };

        function breakdown(remainingSec) {
            var target = new Date(Date.now() + remainingSec * 1000);
            var now = new Date();
            var months = (target.getFullYear() - now.getFullYear()) * 12 + (target.getMonth() - now.getMonth());
            var monthAnchor = new Date(now);
            monthAnchor.setMonth(now.getMonth() + months);
            if (monthAnchor > target) {
                months--;
                monthAnchor = new Date(now);
                monthAnchor.setMonth(now.getMonth() + months);
            }
            var days = Math.floor((target - monthAnchor) / 86400000);
            var hours = Math.floor(((target - monthAnchor) % 86400000) / 3600000);
            var minutes = Math.floor((((target - monthAnchor) % 86400000) % 3600000) / 60000);
            var seconds = Math.round(((((target - monthAnchor) % 86400000) % 3600000) % 60000) / 1000);
            if (seconds === 60) { seconds = 0; minutes++; }
            if (minutes === 60) { minutes = 0; hours++; }
            if (hours === 24) { hours = 0; days++; }
            return { mo: months, d: days, h: hours, m: minutes, s: seconds };
        }

        function tick() {
            document.querySelectorAll('.ap-countdown').forEach(function (span) {
                var until = parseInt(span.getAttribute('data-until'), 10) || 0;
                var synced = parseInt(span.getAttribute('data-synced'), 10) || 0;
                var remaining = (until - synced) - ((Date.now() / 1000) - load);
                if (remaining <= 0) {
                    span.textContent = span.getAttribute('data-past-label') || 'publishing now';
                    return;
                }
                var p = breakdown(remaining);
                span.textContent = 'in ' + p.mo + 'mo ' + p.d + 'd '
                    + pad(p.h) + 'h ' + pad(p.m) + 'm ' + pad(p.s) + 's';
            });
        }

        tick();
        setInterval(tick, 1000);
    })();

    // Relative timestamps for the posting log.
    (function () {
        var UNITS = [
            [31536000, 'y'], [2592000, 'mo'], [86400, 'd'], [3600, 'h'], [60, 'm'], [1, 's']
        ];
        function fmt(sec) {
            if (sec < 45) { return 'just now'; }
            for (var i = 0; i < UNITS.length; i++) {
                if (sec >= UNITS[i][0]) {
                    return Math.round(sec / UNITS[i][0]) + UNITS[i][1] + ' ago';
                }
            }
            return 'just now';
        }
        function render() {
            var now = Date.now() / 1000;
            document.querySelectorAll('.ap-time-relative').forEach(function (el) {
                var uts = parseInt(el.getAttribute('data-uts'), 10) || 0;
                el.textContent = uts ? fmt(now - uts) : '\u2014';
            });
        }
        render();
        setInterval(render, 30000);
    })();

    // Collapse/expand the Posting queue section.
    (function () {
        document.querySelectorAll('.ap-queue-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var body = document.getElementById(btn.getAttribute('data-target'));
                if (!body) { return; }
                var collapsed = body.style.display === 'none';
                body.style.display = collapsed ? '' : 'none';
                btn.textContent = collapsed ? 'Collapse' : 'Show queue';
                btn.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
            });
        });
    })();

    // Show/hide the inline editor for a queued post.
    (function () {
        function rowFor(id) { return document.getElementById('ap-queue-edit-' + id); }
        function btnFor(id) { return document.querySelector('[data-edit-queue="' + id + '"]'); }

        document.querySelectorAll('[data-edit-queue]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-edit-queue');
                var row = rowFor(id);
                if (!row) { return; }
                var hidden = row.style.display === 'none';
                row.style.display = hidden ? '' : 'none';
                btn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
                btn.textContent = hidden ? 'Hide edit' : 'Edit';
                if (hidden) {
                    var ta = row.querySelector('textarea[name="text"]');
                    if (ta) { ta.focus(); ta.scrollIntoView({ block: 'center' }); }
                }
            });
        });

        document.querySelectorAll('[data-cancel-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-cancel-edit');
                var row = rowFor(id);
                if (row) { row.style.display = 'none'; }
                var ebtn = btnFor(id);
                if (ebtn) { ebtn.textContent = 'Edit'; ebtn.setAttribute('aria-expanded', 'false'); }
            });
        });
    })();

    // Live preview of the current platform's post template.
    (function () {
        var form = document.querySelector('form[data-ap-template]');
        if (!form) { return; }
        var patternEl = form.querySelector('#ap-pattern');
        var previewEl = document.getElementById('ap-preview');
        var countEl = document.getElementById('ap-preview-count');
        var tagsEl = form.querySelector('input[name="max_tags"]');
        var lengthEl = form.querySelector('input[name="max_length"]');

        function blockTags() {
            var n = parseInt(tagsEl.value, 10) || 0;
            var out = [];
            for (var i = 1; i <= n; i++) { out.push(' #tag' + i); }
            return out.join('');
        }

        function render() {
            var title = 'Example gallery';
            var desc = 'Fresh uploads';
            var text = patternEl.value
                .replace('{title}', title)
                .replace('{sep}', desc ? ' — ' : '')
                .replace('{description}', desc)
                .replace(/\{hashtags\}/g, blockTags());
            text = text.replace(/\s+/g, ' ').trim();

            var max = parseInt(lengthEl.value, 10) || 280;
            var shown = text.length > max ? text.slice(0, Math.max(1, max - 1)) + '…' : text;
            previewEl.textContent = shown || '—';
            countEl.textContent = shown.length + '/' + max;
        }

        patternEl.addEventListener('input', render);
        tagsEl.addEventListener('input', render);
        lengthEl.addEventListener('input', render);
        render();
    })();
// AJAX pagination for Recommended posts: reloads only this section with the
    // next/previous 14 posts (2 rows of 7) without a full page refresh.
    (function () {
        var body = document.getElementById('ap-rec-body');
        if (!body) { return; }
        var base = '<?= e($platformPath) ?>' + '/recommendations';

        function bind() {
            body.querySelectorAll('[data-rec-page]').forEach(function (btn) {
                if (btn.dataset.recBound) { return; }
                btn.dataset.recBound = '1';
                btn.addEventListener('click', function () {
                    var page = parseInt(btn.getAttribute('data-rec-page'), 10) || 1;
                    body.classList.add('ap-rec-loading');
                    fetch(base + '?page=' + page, { headers: { 'Accept': 'text/html' } })
                        .then(function (r) {
                            if (!r.ok) { throw new Error('HTTP ' + r.status); }
                            return r.text();
                        })
                        .then(function (html) {
                            body.innerHTML = html;
                            bind();
                            if (window.initApCharCounts) { window.initApCharCounts(); }
                            body.classList.remove('ap-rec-loading');
                        })
                        .catch(function () {
                            body.classList.remove('ap-rec-loading');
                            alert('Could not load that page of recommendations.');
                        });
                });
            });
        }
        bind();
    })();
})();
</script>