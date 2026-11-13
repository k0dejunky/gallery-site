<?php
/**
 * Remove scan/probe accounts and everything attached to them.
 *
 *   php bin/purge_test_users.php            # dry run
 *   php bin/purge_test_users.php --yes      # delete
 *
 * Targets are selected by email pattern, so it can be re-run safely: a box
 * with no leftovers does nothing.
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$apply = in_array('--yes', $argv, true);

$pattern = "email LIKE '%@example.test' OR email LIKE 'probe.%' OR email LIKE 'scan.%' OR email LIKE 'deep.%'";
$users = Database::run("SELECT id, email, created_at FROM users WHERE {$pattern} ORDER BY id")->fetchAll() ?: [];

if ($users === []) {
    echo "no test accounts found\n";
    exit(0);
}

$ids = array_map(static fn (array $u): int => (int) $u['id'], $users);
$in  = implode(',', $ids);

echo ($apply ? 'DELETING' : 'DRY RUN') . ' for ' . count($users) . " account(s):\n";
foreach ($users as $u) {
    echo "  #{$u['id']} {$u['email']} ({$u['created_at']})\n";
}

// Every table that carries a user_id (or an owner_id) gets its rows removed
// first, so a foreign key can never block the delete and nothing is orphaned.
$tables = Database::run(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND COLUMN_NAME IN ('user_id','owner_id','sender_id','actor_id','decided_by','created_by')
        AND TABLE_NAME <> 'users'"
)->fetchAll() ?: [];

$report = [];

foreach ($tables as $t) {
    $table = (string) $t['TABLE_NAME'];
    $col   = (string) $t['COLUMN_NAME'];
    $count = (int) Database::run("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IN ({$in})")->fetchColumn();
    if ($count === 0) {
        continue;
    }
    if ($apply) {
        Database::run("DELETE FROM `{$table}` WHERE `{$col}` IN ({$in})");
    }
    $report[] = "{$table}.{$col}: {$count}";
}

// Chat: conversations belong to a user; messages belong to a conversation.
$convIds = Database::run(
    "SELECT id FROM chat_conversations WHERE user_id IN ({$in})"
)->fetchAll(\PDO::FETCH_COLUMN) ?: [];

if ($convIds !== []) {
    $cin = implode(',', array_map('intval', $convIds));
    $msgs = (int) Database::run("SELECT COUNT(*) FROM chat_messages WHERE conversation_id IN ({$cin})")->fetchColumn();
    if ($apply) {
        Database::run("DELETE FROM chat_messages WHERE conversation_id IN ({$cin})");
        Database::run("DELETE FROM chat_conversations WHERE id IN ({$cin})");
    }
    $report[] = 'chat_conversations: ' . count($convIds);
    $report[] = 'chat_messages: ' . $msgs;
}

// Credentials / logs keyed by the address rather than the id.
foreach ($users as $u) {
    $email = (string) $u['email'];
    foreach (['login_attempts' => 'email', 'email_queue' => 'email'] as $table => $col) {
        $count = (int) Database::run("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?", [$email])->fetchColumn();
        if ($count > 0) {
            if ($apply) {
                Database::run("DELETE FROM `{$table}` WHERE `{$col}` = ?", [$email]);
            }
            $report[] = "{$table}: {$count}";
        }
    }
}

if ($apply) {
    Database::run("DELETE FROM users WHERE id IN ({$in})");
}

echo "\nremoved (or would remove):\n";
foreach ($report as $line) {
    echo '  ' . $line . "\n";
}

$left = (int) Database::run("SELECT COUNT(*) FROM users WHERE {$pattern}")->fetchColumn();
echo "\ntest accounts remaining: {$left}\n";
