<?php $title = 'Creator profile'; ?>

<p class="muted">This is the public creator profile shown at <a href="<?= url('/creator') ?>" target="_blank" rel="noopener">/creator</a>.</p>

<form method="post" action="<?= url('/admin/profile') ?>" style="max-width:720px;">
    <?= csrf_field() ?>

    <p>
        <label for="profile-name">Display name</label>
        <input type="text" id="profile-name" name="display_name" value="<?= e((string) $profile['display_name']) ?>" maxlength="120" style="width:100%;box-sizing:border-box;">
    </p>

    <p>
        <label for="profile-tagline">Tagline</label>
        <input type="text" id="profile-tagline" name="tagline" value="<?= e((string) $profile['tagline']) ?>" maxlength="200" style="width:100%;box-sizing:border-box;" placeholder="A short line under your name">
    </p>

    <p>
        <label for="profile-bio">Bio</label>
        <textarea id="profile-bio" name="bio" rows="8" style="width:100%;box-sizing:border-box;" placeholder="Tell members about yourself and your work…"><?= e((string) $profile['bio']) ?></textarea>
    </p>

    <p>
        <label for="profile-avatar">Avatar</label>
        <input type="text" id="profile-avatar" name="avatar" value="<?= e((string) $profile['avatar']) ?>" style="width:100%;box-sizing:border-box;" placeholder="Stored filename (uploads/) or full https:// URL">
        <span class="muted">Paste a filename from your media library, or a full image URL.</span>
    </p>

    <h2>Links</h2>
    <p class="muted">Social/profile links shown as chips on your profile.</p>
    <div id="link-rows">
        <?php $links = !empty($profile['links']) ? $profile['links'] : [['label' => '', 'url' => '']]; ?>
        <?php foreach ($links as $link): ?>
            <div style="display:flex;gap:.5rem;margin-bottom:.4rem;">
                <input type="text" name="link_label[]" value="<?= e((string) ($link['label'] ?? '')) ?>" placeholder="Label (e.g. Twitter)" style="width:180px;">
                <input type="url" name="link_url[]" value="<?= e((string) ($link['url'] ?? '')) ?>" placeholder="https://…" style="flex:1;min-width:180px;">
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-sm btn-outline" onclick="var r=document.getElementById('link-rows');var d=r.children[0].cloneNode(true);d.querySelectorAll('input').forEach(function(i){i.value='';});r.appendChild(d);">Add another link</button>

    <p style="margin-top:1.5rem;">
        <button type="submit" class="btn">Save profile</button>
    </p>
</form>