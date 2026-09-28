A new gallery just went live on <?= e((string) config('app.site_name')) ?>:

<?= e((string) ($gallery['title'] ?? 'a new gallery')) ?>

Open it here: <?= e(absolute_url('/galleries/' . (int) ($gallery['id'] ?? 0))) ?>

You are receiving this because you are a member. If you no longer want these updates, you can unsubscribe here: {{unsubscribe-url}}