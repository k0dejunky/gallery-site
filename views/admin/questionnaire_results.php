<?php $title = 'Questionnaire Results'; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
    <h1 style="margin:0;">Questionnaire: <?= e((string) $questionnaire['title']) ?></h1>
    <a class="btn btn-sm btn-outline" href="<?= url('/admin/chat') ?>">&larr; Back to chat</a>
</div>

<p class="muted" style="margin-top:0;">
    Status <strong><?= e((string) $questionnaire['status']) ?></strong> &middot;
    Replies <?= (int) $questionnaire['allow_replies'] === 1 ? 'ON' : 'OFF' ?> &middot;
    Notified <?= (int) $questionnaire['notified_count'] ?> / <?= (int) $questionnaire['recipients'] ?> &middot;
    <?= !empty($questionnaire['sent_at']) ? 'Sent ' . e(tzdate('M j, Y H:i', (string) $questionnaire['sent_at'])) : 'Not sent yet' ?>
</p>

<?php if (!empty($questionnaire['intro'])): ?>
    <div class="card" style="padding:.75rem 1rem;margin-bottom:1rem;white-space:pre-wrap;"><?= e((string) $questionnaire['intro']) ?></div>
<?php endif; ?>

<?php foreach ($results as $r): ?>
    <?php $qq = $r['question']; $rows = $r['rows']; ?>
    <section class="card" style="padding:1rem;margin-bottom:1rem;">
        <h2 class="section-title" style="margin-top:0;">
            <?= (int) $qq['position'] ?>. <?= e((string) $qq['prompt']) ?>
            <span class="pill pill-muted"><?= e((string) $qq['qtype']) ?></span>
            <?php if ((int) $qq['required'] === 1): ?><span class="pill">required</span><?php endif; ?>
        </h2>

        <?php if (!empty($r['tally'])): ?>
            <table style="width:100%;max-width:520px;margin-bottom:.75rem;">
                <thead><tr><th style="text-align:left;">Answer</th><th style="text-align:right;">Count</th></tr></thead>
                <tbody>
                    <?php foreach ($r['tally'] as $answer => $count): ?>
                        <tr>
                            <td><?= e((string) $answer) ?></td>
                            <td style="text-align:right;"><?= (int) $count ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="muted" style="font-size:.85rem;margin:.25rem 0 .5rem;"><?= count($rows) ?> response(s)</p>
        <?php if (empty($rows)): ?>
            <p class="muted">No answers yet.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>User</th><th>Answer</th><th>When</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="muted"><?= e((string) $row['email']) ?></td>
                            <td style="white-space:pre-wrap;"><?= e((string) $row['answer']) ?></td>
                            <td class="muted"><?= e(tzdate('M j, Y H:i', (string) $row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
