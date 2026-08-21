<?php $title = 'Auto Poster'; ?>
<?php
$reddit  = $config['reddit'] ?? [];
$twitter = $config['twitter'] ?? [];
$maskSecret = static function (string $v): string {
    return $v === '' ? '' : str_repeat('•', min(12, strlen($v)));
};
?>
<h1>Auto Poster</h1>
<p class="muted">Post content from this site to Reddit (any subreddit) and X (formerly Twitter). Configure your API credentials below, then compose a post.</p>

<div class="stats-grid">
    <?php // ----- Reddit credentials ----- ?>
    <div class="stats-panel">
        <h2>Reddit</h2>
        <form method="post" action="<?= url('/admin/auto-poster/settings') ?>">
            <?= csrf_field() ?>
            <p class="muted" style="font-size:0.85rem;">
                Create a Reddit "script" app at <a href="https://www.reddit.com/prefs/apps" target="_blank" rel="noopener">reddit.com/prefs/apps</a> and enter its credentials.
            </p>
            <p>
                <label for="reddit_client_id">Client ID</label><br>
                <input type="text" name="reddit_client_id" id="reddit_client_id" value="<?= e($reddit['client_id'] ?? '') ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <p>
                <label for="reddit_client_secret">Client Secret</label><br>
                <input type="password" name="reddit_client_secret" id="reddit_client_secret" value="<?= e($maskSecret($reddit['client_secret'] ?? '')) ?>" placeholder="<?= empty($reddit['client_secret']) ? '' : 'Leave blank to keep the saved secret' ?>" style="width:100%;box-sizing:border-box;">
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
                Create an app in the <a href="https://developer.x.com" target="_blank" rel="noopener">X developer portal</a>, grant <code>tweet.read tweet.write users.read</code>, and paste its bearer token.
            </p>
            <p>
                <label for="twitter_bearer_token">Bearer Token</label><br>
                <input type="password" name="twitter_bearer_token" id="twitter_bearer_token" value="<?= e($maskSecret($twitter['bearer_token'] ?? '')) ?>" placeholder="<?= empty($twitter['bearer_token']) ? '' : 'Leave blank to keep the saved token' ?>" style="width:100%;box-sizing:border-box;">
            </p>
            <button type="submit" class="btn">Save X Settings</button>
        </form>
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
