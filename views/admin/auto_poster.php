<?php $title = 'Auto Poster'; ?>
<?php
$reddit  = $config['reddit'] ?? [];
$twitter = $config['twitter'] ?? [];
?>
<h1>Auto Poster</h1>
<p class="muted">Post content from this site to Reddit (any subreddit) and X (formerly Twitter). Configure your API credentials below, then compose a post.</p>

<?php // ----- Recommended posts: generated from recent uploads ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Recommended posts</h2>
        <span class="muted" style="font-size:.85rem;">One post per gallery with uploads in the last 14 days, carrying 1&ndash;4 of its newest images (or a single video). Each gallery is offered once.</span>
    </div>
    <?php if (empty($recommended)): ?>
        <p class="muted">No recent uploads to recommend. Upload new media, or every recent gallery has already been queued/posted/dismissed.</p>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;margin-top:.75rem;">
            <?php foreach ($recommended as $rec): ?>
                <div style="border:1px solid var(--border,#e5e7eb);border-radius:var(--border-radius,.5rem);padding:.75rem;display:flex;flex-direction:column;gap:.5rem;">
                    <div style="display:flex;gap:.75rem;align-items:center;">
                        <?php if (!empty($rec['media'])): ?>
                            <img src="<?= e(file_url((string) $rec['media'][0]['filename'], 'thumb')) ?>" alt="" width="64" height="48" style="border-radius:4px;object-fit:cover;flex-shrink:0;background:#000;">
                        <?php else: ?>
                            <div style="width:64px;height:48px;border-radius:4px;background:#f3f4f6;flex-shrink:0;"></div>
                        <?php endif; ?>
                        <div style="min-width:0;">
                            <div style="font-weight:600;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e((string) $rec['gallery_title'] ?: 'Untitled gallery') ?></div>
                            <div class="muted" style="font-size:.8rem;"><?= e(date('M j, Y', strtotime((string) $rec['newest_media_at']))) ?> &middot; <?= (int) $rec['media_count'] ?> file(s)</div>
                        </div>
                    </div>
                    <?php if (count($rec['media']) > 1): ?>
                        <div style="display:flex;gap:.25rem;flex-wrap:wrap;">
                            <?php foreach ($rec['media'] as $mf): ?>
                                <img src="<?= e(file_url((string) $mf['filename'], 'thumb')) ?>" alt="" width="56" height="42" style="border-radius:4px;object-fit:cover;background:#000;">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= url('/admin/auto-poster/queue/recommend') ?>" style="display:flex;flex-direction:column;gap:.5rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="gallery_id" value="<?= (int) $rec['gallery_id'] ?>">
                        <textarea name="text" rows="2" maxlength="280" style="font-size:.85rem;color:#374151;background:#fff;padding:.5rem .6rem;border-radius:4px;border:1px solid #d1d5db;word-wrap:break-word;resize:vertical;box-sizing:border-box;width:100%;"><?= e((string) $rec['suggested_text']) ?></textarea>
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                            <label for="sched_<?= (int) $rec['gallery_id'] ?>" class="muted" style="font-size:.8rem;">Publish</label>
                            <input type="datetime-local" name="scheduled_at" id="sched_<?= (int) $rec['gallery_id'] ?>" value="<?= e((string) $rec['default_scheduled_at']) ?>" style="font-size:.85rem;padding:.2rem .35rem;border:1px solid #d1d5db;border-radius:4px;">
                            <span class="muted" style="font-size:.75rem;">auto-posts when the time passes</span>
                        </div>
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-sm">Add to queue</button>
                            <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;"
                                    formaction="<?= url('/admin/auto-poster/queue/post') ?>"
                                    onclick="return confirm('Post this now to X?');">Post now</button>
                            <button type="submit" class="btn btn-sm btn-danger"
                                    formaction="<?= url('/admin/auto-poster/queue/dismiss') ?>"
                                    onclick="return confirm('Dismiss this recommended post?');">Dismiss</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php // ----- Pending queue ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Posting queue</h2>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <span class="muted" style="font-size:.85rem;">
                <?= number_format((int) $queueCounts['queued']) ?> queued &middot;
                <?= number_format((int) $queueCounts['posted']) ?> posted &middot;
                <?= number_format((int) $queueCounts['failed']) ?> failed
            </span>
            <?php if (!empty($queue) && count($queue) > 0): ?>
                <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/post-all') ?>"
                      onsubmit="return confirm('Post all <?= number_format(count($queue)) ?> queued item(s) to X now?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm">Post all queued</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
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
                                <input type="datetime-local" name="scheduled_at" value="<?= e(trim((string) $item['scheduled_at'])) ?>" style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;">
                                <button type="submit" class="btn btn-sm">Set</button>
                            </form>
                            <div class="muted" style="font-size:.75rem;margin-top:.1rem;">
                                <?php if (!empty($item['scheduled_at']) && $item['scheduled_at'] <= date('Y-m-d H:i:s')): ?>
                                    <span style="color:#d97706;">publishing now on next worker run</span>
                                <?php else: ?>
                                    auto-posts at this time
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <form class="inline" method="post" action="<?= url('/admin/auto-poster/queue/post') ?>">
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
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="stats-grid">
    <?php // ----- Reddit credentials ----- ?>
    <div class="stats-panel">
        <h2>Reddit</h2>
        <form method="post" action="<?= url('/admin/auto-poster/settings') ?>">
            <?= csrf_field() ?>
            <p class="muted" style="font-size:0.85rem;">
                Create a Reddit <strong>"web app"</strong> (not a script app) at
                <a href="https://www.reddit.com/prefs/apps" target="_blank" rel="noopener">reddit.com/prefs/apps</a>,
                set its redirect URI to <code><?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/admin/auto-poster/reddit/callback')) ?></code>,
                then enter its client ID, secret and username below.
            </p>
            <p>
                <label for="reddit_client_id">Client ID</label><br>
                <input type="text" name="reddit_client_id" id="reddit_client_id" value="<?= e($reddit['client_id'] ?? '') ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_client_secret">Client Secret</label><br>
                <input type="password" name="reddit_client_secret" id="reddit_client_secret" value="" placeholder="<?= empty($reddit['client_secret']) ? '' : 'Leave blank to keep the saved secret' ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_username">Reddit username</label><br>
                <input type="text" name="reddit_username" id="reddit_username" value="<?= e($reddit['username'] ?? '') ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_app_name">App name (User-Agent)</label><br>
                <input type="text" name="reddit_app_name" id="reddit_app_name" value="<?= e($reddit['app_name'] ?? 'gallery-auto-poster') ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <button type="submit" class="btn">Save Reddit Settings</button>
        </form>
        <p style="margin-top:0.75rem;">
            <?php if (!empty($reddit['refresh_token'])): ?>
                <span style="color:var(--success,#2e7d32);font-weight:600;">&#10003; Authorized — you can post to subreddits.</span>
            <?php else: ?>
                <span class="muted" style="display:block;margin-bottom:0.5rem;">Not authorized yet. Complete the flow below to enable posting.</span>
                <a class="btn" href="<?= url('/admin/auto-poster/reddit/authorize') ?>">Authorize Reddit</a>
            <?php endif; ?>
        </p>
    </div>

    <?php // ----- X / Twitter credentials ----- ?>
    <div class="stats-panel">
        <h2>X (Twitter)</h2>
        <form method="post" action="<?= url('/admin/auto-poster/settings') ?>">
            <?= csrf_field() ?>
            <p class="muted" style="font-size:0.85rem;">
                Create an app in the <a href="https://developer.x.com" target="_blank" rel="noopener">X developer portal</a>
                and set its <strong>callback URL</strong> to
                <code><?= e((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/admin/auto-poster/twitter/callback')) ?></code>.
                Grant <code>tweet.read tweet.write users.read offline.access</code> and paste the app's Client ID and Client Secret below.
            </p>
            <p>
                <label for="twitter_client_id">Client ID</label><br>
                <input type="text" name="twitter_client_id" id="twitter_client_id" value="<?= e($twitter['client_id'] ?? '') ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="twitter_client_secret">Client Secret</label><br>
                <input type="password" name="twitter_client_secret" id="twitter_client_secret" value="" placeholder="<?= empty($twitter['client_secret']) ? '' : 'Leave blank to keep the saved secret' ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <button type="submit" class="btn">Save X Settings</button>
        </form>
        <p style="margin-top:0.75rem;">
            <?php if (!empty($twitter['refresh_token'])): ?>
                <span style="color:var(--success,#2e7d32);font-weight:600;">&#10003; Authorized — you can post tweets.</span>
            <?php else: ?>
                <span class="muted" style="display:block;margin-bottom:0.5rem;">Not authorized yet. Complete the flow below to enable posting.</span>
                <a class="btn" href="<?= url('/admin/auto-poster/twitter/authorize') ?>">Authorize X</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php // ----- Compose posts ----- ?>
<div class="stats-grid">
    <?php // ----- Reddit post ----- ?>
    <div class="stats-panel">
        <h2>Post to Reddit</h2>
        <form method="post" action="<?= url('/admin/auto-poster/post/reddit') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <p>
                <label for="reddit_subreddit">Subreddit</label><br>
                <input type="text" name="reddit_subreddit" id="reddit_subreddit" placeholder="e.g. pics" required style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_title">Title</label><br>
                <input type="text" name="reddit_title" id="reddit_title" required style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_media">Image (optional — one image per post)</label><br>
                <input type="file" name="reddit_media" id="reddit_media" accept="image/*" style="width:100%;box-sizing:border-box;">
                <span class="muted" style="font-size:0.8rem;">Uploading an image posts it as an image post.</span>
            </p>
            <p>
                <label>Type (when no image)</label><br>
                <label class="chip"><input type="radio" name="reddit_type" value="link" checked> Link</label>
                <label class="chip"><input type="radio" name="reddit_type" value="self"> Text</label>
            </p>
            <p id="reddit-url-row">
                <label for="reddit_url">URL</label><br>
                <input type="url" name="reddit_url" id="reddit_url" placeholder="https://..." style="width:100%;box-sizing:border-box;">
            </p>
            <p id="reddit-text-row" style="display:none;">
                <label for="reddit_text">Text</label><br>
                <textarea name="reddit_text" id="reddit_text" rows="4" style="width:100%;box-sizing:border-box;"></textarea>
            </p>
            <button type="submit" class="btn">Submit to Reddit</button>
        </form>
    </div>

    <?php // ----- X post ----- ?>
    <div class="stats-panel">
        <h2>Post to X (Twitter)</h2>
        <form method="post" action="<?= url('/admin/auto-poster/post/twitter') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <p>
                <label for="twitter_text">Text</label><br>
                <textarea name="twitter_text" id="twitter_text" rows="5" maxlength="280" placeholder="Post content (max 280 characters)..." style="width:100%;box-sizing:border-box;"></textarea>
                <span class="muted" style="font-size:0.8rem;"><span id="twitter-count">0</span>/280</span>
            </p>
            <p>
                <label for="twitter_media">Images / video (optional)</label><br>
                <input type="file" name="twitter_media[]" id="twitter_media" accept="image/*,video/*" multiple style="width:100%;box-sizing:border-box;">
                <span class="muted" style="font-size:0.8rem;">
                    Up to 4 images, or 1 video. Images ≤5 MB; video ≤512 MB.
                </span>
            </p>
            <button type="submit" class="btn">Post to X</button>
        </form>
    </div>
</div>

<?php // ----- Log ----- ?>
<div class="stats-panel" style="margin-top:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;">
        <h2>Posting log</h2>
        <form method="post" action="<?= url('/admin/auto-poster/clear-log') ?>" onsubmit="return confirm('Clear the entire posting log?');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger">Clear Log</button>
        </form>
    </div>
    <?php if (empty($log)): ?>
        <p class="muted">No posts have been made yet.</p>
    <?php else: ?>
        <table>
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
                    <tr>
                        <td><?= e($entry['created_at'] ?? '') ?></td>
                        <td><?= e(ucfirst($entry['platform'] ?? '')) ?></td>
                        <td><?= e($entry['target'] ?? '') ?></td>
                        <td>
                            <span style="color:<?= ($entry['status'] ?? '') === 'success' ? 'var(--success,#2e7d32)' : 'var(--danger,#c62828)' ?>">
                                <?= e(ucfirst($entry['status'] ?? '')) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (strpos((string) $entry['message'], 'http') === 0): ?>
                                <a href="<?= e($entry['message']) ?>" target="_blank" rel="noopener"><?= e($entry['message']) ?></a>
                            <?php else: ?>
                                <?= e($entry['message'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function () {
    var typeRadios = document.querySelectorAll('input[name="reddit_type"]');
    var urlRow = document.getElementById('reddit-url-row');
    var textRow = document.getElementById('reddit-text-row');
    function updateType() {
        var self = document.querySelector('input[name="reddit_type"]:checked');
        var isSelf = self && self.value === 'self';
        urlRow.style.display = isSelf ? 'none' : '';
        textRow.style.display = isSelf ? '' : 'none';
    }
    typeRadios.forEach(function (r) { r.addEventListener('change', updateType); });
    updateType();

    var tInput = document.getElementById('twitter_text');
    var tCount = document.getElementById('twitter-count');
    if (tInput && tCount) {
        tInput.addEventListener('input', function () { tCount.textContent = tInput.value.length; });
    }
})();
</script>
