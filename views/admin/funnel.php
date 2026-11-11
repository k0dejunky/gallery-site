<?php $title = 'Funnel'; ?>

<p class="muted">
    What people do, not how many pages were served. Seven and thirty day windows, counted from the moment
    each event was added (older traffic has no events).
</p>

<p style="display:flex;gap:.5rem;flex-wrap:wrap;margin:.75rem 0 1.25rem;">
    <a class="btn btn-sm btn-outline" href="<?= e(url('/admin')) ?>">&larr; Dashboard</a>
    <a class="btn btn-sm btn-outline" href="<?= e(url('/admin/traffic')) ?>">Traffic analytics</a>
</p>

<?php
$rows = [
    ['search', 'Searches run'],
    ['search_empty', 'of which found nothing'],
    ['paywall_seen', 'Paywall seen'],
    ['upgrade_click', 'of which clicked an upgrade'],
    ['video_start', 'Videos started'],
    ['video_finish', 'Videos finished'],
    ['chat_send', 'Chat messages sent'],
    ['chat_reply', 'Chat replies from the operator'],
];
?>
<div class="sys-card">
    <h2>Events</h2>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr>
                <th style="text-align:left;padding:.45rem .5rem;">What</th>
                <th style="text-align:right;padding:.45rem .5rem;">Last 7 days</th>
                <th style="text-align:right;padding:.45rem .5rem;">Last 30 days</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as [$key, $label]): ?>
                <tr>
                    <td style="padding:.45rem .5rem;"><?= e($label) ?></td>
                    <td style="padding:.45rem .5rem;text-align:right;font-variant-numeric:tabular-nums;">
                        <?= number_format((int) ($week[$key] ?? 0)) ?>
                    </td>
                    <td style="padding:.45rem .5rem;text-align:right;font-variant-numeric:tabular-nums;">
                        <?= number_format((int) ($month[$key] ?? 0)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="sys-card">
    <h2>Rates (last 7 days)</h2>
    <table style="width:100%;border-collapse:collapse;">
        <tbody>
            <tr>
                <th style="text-align:left;padding:.45rem .5rem;">Searches that found nothing</th>
                <td style="padding:.45rem .5rem;text-align:right;"><?= $rates['search_empty'] ?></td>
            </tr>
            <tr>
                <th style="text-align:left;padding:.45rem .5rem;">Paywall &rarr; upgrade click</th>
                <td style="padding:.45rem .5rem;text-align:right;"><?= $rates['paywall_click'] ?></td>
            </tr>
            <tr>
                <th style="text-align:left;padding:.45rem .5rem;">Video start &rarr; finish</th>
                <td style="padding:.45rem .5rem;text-align:right;"><?= $rates['video_finish'] ?></td>
            </tr>
        </tbody>
    </table>
    <p class="muted" style="font-size:.8rem;margin:.6rem 0 0;">
        A dash means the denominator was zero in the window &mdash; not zero per cent.
    </p>
</div>

<div class="sys-card">
    <h2>How to read it</h2>
    <ul class="muted" style="font-size:.86rem;line-height:1.55;margin:0;padding-left:1.1rem;">
        <li><b>Searches that found nothing</b> is the clearest signal that the catalogue is missing a word
            people use, or that the search is too strict. Compare it with the missed searches on
            <a href="<?= e(url('/admin/traffic')) ?>">Traffic</a> before adding a category.</li>
        <li><b>Paywall &rarr; upgrade click</b> is the offer, not the payment. A wall that is seen a lot and
            clicked rarely is describing the wrong thing (a tier, not a set).</li>
        <li><b>Video start &rarr; finish</b> is the player's health: a low rate on a long video is normal,
            a low rate on a short one means the stream is stalling.</li>
        <li><b>Chat messages with no reply</b> is the difference between the last two rows. The operator queue
            is on <a href="<?= e(url('/admin/chat')) ?>?queue=unread">Chat</a>.</li>
    </ul>
</div>
