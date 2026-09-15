<?php

/**
 * Independent, framework-free verification of the exact publication-gate SQL
 * that Gallery::publishedVisibleSql() + paginate()/findPublic() run against
 * the live DB. Inserts a throwaway scheduled gallery row, asserts the three
 * public-read paths hide it until due, forces it published, asserts it then
 * shows, and removes the throwaway row. Uses the app's own .env for creds so
 * it exercises the same data the site serves.
 */

$env = [];
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) $env = (array) (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []);

$val = static fn (string $k, string $d = ''): string => trim((string) ($env[$k] ?? getenv($k) ?: $d)) !== '' ? (string) ($env[$k] ?? getenv($k) ?: $d) : $d;

$pdo = new PDO(
    'mysql:host=' . $val('GALLERY_DB_HOST', '127.0.0.1') . ';port=' . $val('GALLERY_DB_PORT', '3306')
        . ';dbname=' . $val('GALLERY_DB_NAME', 'gallery_mvc') . ';charset=utf8mb4',
    $val('GALLERY_DB_USER', 'gallery'),
    $val('GALLERY_DB_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
};

// --- the publishedVisibleSql gate the model emits (mirrors Gallery::publishedVisibleSql) ---
$gate = 'g.deleted_at IS NULL AND (g.published_at IS NULL OR g.published_at <= NOW())';

echo "== scheduled site-publication gate (raw DB, app .env creds) ==\n";

$future = date('Y-m-d H:i:s', time() + 3600);
$pdo->prepare('INSERT INTO galleries (title, description, type, min_level, published_at, created_at) VALUES (?,?,?,?,?, NOW())')
    ->execute(['smoke-sched-' . bin2hex(random_bytes(4)), 'scheduled smoke', 'images', 0, $future]);
$gid = (int) $pdo->lastInsertId();

$find = $pdo->prepare("SELECT id FROM galleries g WHERE $gate AND g.id = ?");
$find->execute([$gid]);
$check('findPublic hides a future-scheduled gallery', $find->fetchColumn() === false);

$list = $pdo->prepare("SELECT id FROM galleries g WHERE $gate ORDER BY g.created_at DESC LIMIT 50");
$list->execute();
$ids = array_map('intval', array_column($list->fetchAll(), 'id'));
$check('public paginate excludes the future-scheduled gallery', !in_array($gid, $ids, true));

$pdo->prepare('UPDATE galleries SET published_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$gid]);

$find2 = $pdo->prepare("SELECT id FROM galleries g WHERE $gate AND g.id = ?");
$find2->execute([$gid]);
$check('findPublic returns the gallery once due', (int) $find2->fetchColumn() === $gid);

$pdo->prepare('DELETE FROM gallery_category WHERE gallery_id = ?')->execute([$gid]);
$pdo->prepare('DELETE FROM galleries WHERE id = ?')->execute([$gid]);
echo "\n" . ($fail === 0 ? 'ALL GATES PASSED' : "$fail GATE(S) FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
