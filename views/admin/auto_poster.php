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
                <?= number_format((int) $queueCounts['failed']) ?> failed &middot;
                <?= number_format((int) $queueCounts['skipped']) ?> skipped
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
                                <input type="datetime-local" name="scheduled_at" value="<?= e(\App\Models\AutoPostQueue::displaySchedule($item['scheduled_at'] ?? null)) ?>" style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;">
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

<?php // ----- Recent posts: repost or reschedule a past post ----- ?>
<div class="stats-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h2>Recent posts</h2>
        <span class="muted" style="font-size:.85rem;">Re-publish a past post now, or schedule it to go out again later.</span>
    </div>
    <?php if (empty($recentPosts)): ?>
        <p class="muted">No posts recorded yet — posted, failed and skipped items will appear here.</p>
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
                    ?>
                    <?php $rpEditable = $rpStatus === 'failed'; ?>
                    <tr>
                        <td><span class="ap-pill ap-pill-<?= e($rpPill) ?>"><span class="ap-dot"></span><?= e(ucfirst($rpStatus)) ?></span></td>
                        <td><?= e(ucfirst((string) ($rp['platform'] ?? ''))) ?></td>
                        <td class="muted" style="font-size:.8rem;"><?= e((string) $rp['gallery_title']) ?></td>
                        <?php if ($rpEditable): ?>
                            <?php // Failed posts: editable text so the wording can be fixed, then reposted/scheduled. ?>
                            <td style="max-width:340px;font-size:.85rem;" class="rp-edit">
                                <textarea name="text" form="ap-edit-<?= (int) $rp['id'] ?>" maxlength="280" rows="2"
                                          style="width:100%;box-sizing:border-box;font-size:.85rem;font-family:inherit;padding:.3rem .4rem;border:1px solid #d1d5db;border-radius:4px;"
                                          aria-label="Editable text for post #<?= (int) $rp['id'] ?>"><?= e((string) $rp['text']) ?></textarea>
                                <div class="muted" style="font-size:.72rem;margin-top:.15rem;">Edit the wording, then click Repost now or Reschedule.</div>
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
                        <td class="ap-time"><span class="muted"><?= $rpTs ? date('Y-m-d H:i', $rpTs) : '&mdash;' ?></span></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($rpEditable): ?>
                                <?php // One shared form; the textarea/schedule/buttons link to it via the HTML5 "form" attribute. ?>
                                <form id="ap-edit-<?= (int) $rp['id'] ?>" method="post" action="<?= url('/admin/auto-poster/history/edit') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                </form>
                                <input type="datetime-local" name="scheduled_at" form="ap-edit-<?= (int) $rp['id'] ?>"
                                       value="<?= e(\App\Models\AutoPostQueue::displaySchedule($rp['scheduled_at'] ?? null)) ?>"
                                       style="font-size:.8rem;padding:.15rem .3rem;border:1px solid #d1d5db;border-radius:4px;width:9.5rem;"
                                       aria-label="Schedule repost time for post #<?= (int) $rp['id'] ?>">
                                <button type="submit" name="action" value="repost" form="ap-edit-<?= (int) $rp['id'] ?>"
                                        class="btn btn-sm" title="Publish the edited text now">Repost now</button>
                                <button type="submit" name="action" value="reschedule" form="ap-edit-<?= (int) $rp['id'] ?>"
                                        class="btn btn-sm" title="Queue the edited text to publish at the chosen time">Reschedule</button>
                            <?php else: ?>
                                <form class="inline" method="post" action="<?= url('/admin/auto-poster/history/repost') ?>"
                                      onsubmit="return confirm('Repost #<?= (int) $rp['id'] ?> to X now?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                    <button type="submit" class="btn btn-sm" title="Post the same content again right away">Repost</button>
                                </form>
                                <form class="inline" method="post" action="<?= url('/admin/auto-poster/history/reschedule') ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_id" value="<?= (int) $rp['id'] ?>">
                                    <input type="datetime-local" name="scheduled_at"
                                           value="<?= e(\App\Models\AutoPostQueue::displaySchedule($rp['scheduled_at'] ?? null)) ?>"
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
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:.5rem 0;">
            <p style="font-size:0.85rem;margin:.5rem 0;">
                <strong>Media upload (OAuth 1.0a)</strong> — X 403s image/video uploads made with the
                OAuth2 bearer token. Paste the app's API Key/Secret and the account's Access Token/Secret
                (Developer Portal &rarr; Keys and tokens &rarr; <em>Read and write</em> + <em>Media</em>)
                so attached images are uploaded instead of failing.
            </p>
            <p>
                <label for="twitter_consumer_key">API Key (consumer key)</label><br>
                <input type="text" name="twitter_consumer_key" id="twitter_consumer_key" value="<?= e($twitter['consumer_key'] ?? '') ?>" placeholder="(optional) enables media uploads" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="twitter_consumer_secret">API Secret (consumer secret)</label><br>
                <input type="password" name="twitter_consumer_secret" id="twitter_consumer_secret" value="" placeholder="<?= empty($twitter['consumer_secret']) ? '' : 'Leave blank to keep the saved secret' ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="twitter_oauth_token">Access Token</label><br>
                <input type="text" name="twitter_oauth_token" id="twitter_oauth_token" value="<?= e($twitter['oauth_token'] ?? '') ?>" placeholder="555555555-TokenHere" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="twitter_oauth_token_secret">Access Token Secret</label><br>
                <input type="password" name="twitter_oauth_token_secret" id="twitter_oauth_token_secret" value="" placeholder="<?= empty($twitter['oauth_token_secret']) ? '' : 'Leave blank to keep the saved secret' ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="timezone">Schedule timezone</label><br>
                <select name="timezone" id="timezone" style="width:100%;box-sizing:border-box;">
                    <?php $tzs = preg_grep('/^((Africa|America|Antarctica|Arctic|Asia|Atlantic|Australia|Europe|Indian|Pacific)\/)/', DateTimeZone::listIdentifiers()); ?>
                    <?php foreach ($tzs as $tz): ?>
                        <option value="<?= e($tz) ?>"<?= ($config['timezone'] ?? 'UTC') === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="muted" style="font-size:0.8rem;">Times in "Recommended posts" and the queue show in this zone; posting happens at the equivalent UTC moment.</span>
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
                <h2>Posting log</h2>
                <?php $logCount  = count($log); ?>
                <?php $logOk     = count(array_filter($log, fn($l) => ($l['status'] ?? '') === 'success')); ?>
                <span class="ap-log-summary"><?= (int) $logCount ?> entries &middot; <?= (int) $logOk ?> succeeded &middot; <?= (int) ($logCount - $logOk) ?> failed</span>
            </div>
        </summary>
        <div class="ap-log-body">
        <?php if (empty($log)): ?>
            <div class="ap-log-empty">No posts have been made yet.</div>
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
                                    <span class="ap-time-absolute"><?= $apTs ? date('Y-m-d H:i', $apTs) : '&mdash;' ?></span>
                                </td>
                                <td><?= e(ucfirst((string) ($entry['platform'] ?? ''))) ?></td>
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
                <form method="post" action="<?= url('/admin/auto-poster/clear-log') ?>" onsubmit="return confirm('Clear the entire posting log?');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-danger">Clear Log</button>
                </form>
            </div>
        </div>
    </details>
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

    // Live countdown to each queued post's publish time. The server stamps
    // the target (data-until) and its own clock (data-synced) so the client
    // shows a correct countdown regardless of clock skew.
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

    // Relative timestamps for the posting log ("5m ago" / "just now"),
    // refreshing every 30 seconds.
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
})();
</script>
