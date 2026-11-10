<?php
/**
 * One chat row.
 *
 * Rendered by the member chat and the admin conversation, so the two agree
 * on what a message looks like. The JavaScript client builds the same shape
 * for anything that arrives after the page has loaded.
 *
 * Expects: $m (a decorated chat_messages row), $me ('user' | 'operator'),
 *          $mode ('member' | 'admin'), $first (bool - starts a group).
 */
$role    = (string) ($m['sender_role'] ?? 'user');
$mine    = $role === $me;
$deleted = !empty($m['deleted']);
$model   = $role === 'model';
$first   = !empty($first);
$pinned  = !empty($m['pinned']);

$roleName = static function (string $role) use ($me): string {
    if ($role === $me) {
        return $me === 'operator' ? 'Operator' : 'You';
    }

    return match ($role) {
        'model'    => 'Amethyst AI',
        'operator' => 'Amethyst',
        default    => 'Member',
    };
};

$quote = is_array($m['reply_to'] ?? null) ? $m['reply_to'] : null;
$refs  = is_array($m['content_refs'] ?? null) ? $m['content_refs'] : [];
$reactions = is_array($m['reactions'] ?? null) ? $m['reactions'] : [];

$classes = ['chat-row', $mine ? 'chat-row--out' : 'chat-row--in'];
if ($first) {
    $classes[] = 'chat-row--start';
} else {
    $classes[] = 'chat-row--cont';
}
?>
<div class="<?= e(implode(' ', $classes)) ?>"
     data-id="<?= (int) ($m['id'] ?? 0) ?>"
     data-role="<?= e($role) ?>"
     data-created="<?= e((string) ($m['created_at'] ?? '')) ?>"
     data-day="<?= e((string) ($m['day'] ?? '')) ?>"
     data-pinned="<?= $pinned ? '1' : '0' ?>"
     <?php if (!empty($m['content_refs'])): ?>data-refs="<?= e(json_encode($refs, JSON_UNESCAPED_SLASHES)) ?>"<?php endif; ?>>
    <span class="chat-avatar chat-avatar--sm" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($roleName($role), 0, 1))) ?></span>
    <div class="chat-stack">
        <?php if (!$mine && $first): ?>
            <p class="chat-author"><?= e($roleName($role)) ?></p>
        <?php endif; ?>

        <div class="chat-bubble<?= $mine ? '' : ' chat-bubble--model' ?><?= $deleted ? ' chat-bubble--deleted' : '' ?>">
            <?php if ($deleted): ?>
                <span class="chat-text">This message was deleted</span>
            <?php else: ?>
                <?php if ($quote !== null): ?>
                    <blockquote class="chat-quote">
                        <b><?= e(!empty($quote['deleted']) ? 'Deleted message' : $roleName((string) ($quote['role'] ?? 'user'))) ?></b>
                        <?= e((string) ($quote['excerpt'] ?? '')) ?>
                    </blockquote>
                <?php endif; ?>

                <?php if ((string) ($m['message'] ?? '') !== ''): ?>
                    <span class="chat-text"><?= e((string) $m['message']) ?></span>
                <?php endif; ?>

                <?php if (!empty($m['attachment_name'])): ?>
                    <?php if (empty($m['attachment_url'])): ?>
                        <span class="chat-file chat-expired">🔒 Media expired</span>
                    <?php elseif (!empty($m['attachment_thumb_url'])): ?>
                        <a class="chat-media" href="<?= e((string) $m['attachment_url']) ?>" target="_blank" rel="noopener">
                            <img loading="lazy" src="<?= e((string) $m['attachment_thumb_url']) ?>"
                                 alt="<?= e((string) $m['attachment_name']) ?>" draggable="false" data-nocontext>
                        </a>
                    <?php else: ?>
                        <a class="chat-file" href="<?= e((string) $m['attachment_url']) ?>" target="_blank" rel="noopener">
                            📎 <?= e((string) $m['attachment_name']) ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($reactions !== []): ?>
            <div class="chat-reactions">
                <?php foreach ($reactions as $reaction): ?>
                    <?php
                    $emoji = (string) ($reaction['emoji'] ?? '');
                    $actors = is_array($reaction['actors'] ?? null) ? $reaction['actors'] : [];
                    $isMine = in_array($me, $actors, true);
                    ?>
                    <button type="button" class="chat-reaction<?= $isMine ? ' is-mine' : '' ?>"
                            data-emoji="<?= e($emoji) ?>" aria-pressed="<?= $isMine ? 'true' : 'false' ?>">
                        <?= e($emoji) ?> <span><?= count($actors) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="chat-meta">
            <span><?= e((string) ($m['time_label'] ?? '')) ?></span>
            <?php if ($mine && !$deleted): ?>
                <?php $seen = !empty($m['id']) && (int) ($m['id'] ?? 0) <= (int) ($readThrough ?? 0); ?>
                <span class="chat-ticks<?= $seen ? ' is-seen' : '' ?>" aria-label="<?= $seen ? 'Seen' : 'Sent' ?>"><?= $seen ? '✓✓' : '✓' ?></span>
            <?php endif; ?>
            <?php if (!empty($m['edited']) && !$deleted): ?><span class="chat-edited">edited</span><?php endif; ?>
            <button type="button" class="chat-btn" data-chat-menu style="width:22px;height:22px;font-size:.85rem"
                    aria-label="Message actions">⋯</button>
        </div>
    </div>
</div>
