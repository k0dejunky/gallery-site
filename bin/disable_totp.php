<?php

declare(strict_types=1);

/**
 * Emergency recovery: turn two-factor OFF for one account.
 *
 * Two-factor is mandatory for super_admin / admin / moderator, so a lost
 * authenticator app would otherwise lock the owner out of the admin panel
 * permanently. This is the only supported way back in: it runs on the box, as
 * the site user, and records an audit row like every other account change.
 *
 * Usage (on the server):
 *   php /var/www/gallery/bin/disable_totp.php --email=you@example.com
 *   php /var/www/gallery/bin/disable_totp.php --id=2 --yes
 *
 * The next login goes straight in, and the base controller immediately sends
 * the account to the enrolment page so a new device can be set up.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Models\AuditLog;
use App\Models\User;

$opts = getopt('', ['email:', 'id:', 'yes', 'help']);

if (isset($opts['help']) || (!isset($opts['email']) && !isset($opts['id']))) {
    fwrite(STDERR, "Usage: php bin/disable_totp.php --email=<address> | --id=<user id> [--yes]\n");
    exit(1);
}

$user = null;
if (isset($opts['email'])) {
    $user = User::findByEmail(trim((string) $opts['email']));
} else {
    $user = User::find((int) $opts['id']);
}

if ($user === null) {
    fwrite(STDERR, "No such account.\n");
    exit(1);
}

$id = (int) $user['id'];

if (empty($user['totp_enabled']) && empty($user['totp_secret'])) {
    echo "Two-factor is already off for {$user['email']} (#{$id}).\n";
    exit(0);
}

if (!isset($opts['yes'])) {
    fwrite(STDERR, "This turns two-factor OFF for {$user['email']} (#{$id}, role {$user['role']}).\n");
    fwrite(STDERR, "Re-run with --yes to confirm.\n");
    exit(1);
}

User::disableTotp($id);

if (class_exists(AuditLog::class)) {
    AuditLog::record($id, 'update', 'user_two_factor', $id, 'Disabled two-factor from the command line (emergency recovery)');
}

$runner = getenv('USER') ?: 'cli';
echo "Two-factor disabled for {$user['email']} (#{$id}) by {$runner}. The next login must re-enrol.\n";
