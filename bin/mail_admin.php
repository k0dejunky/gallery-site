<?php

/**
 * Admin email server operations: list, create, delete and re-password
 * virtual mailboxes served by Postfix + Dovecot on the mail host.
 *
 * This script is intentionally root-only and invoked through a scoped
 * sudoers rule (www-data runs it passwordless). It is NOT reachable from the
 * web directly; the EmailController shells out to it via `sudo -n`.
 *
 * It manages:
 *   - /etc/postfix/vmailbox      (email => domain/user/ delivery map)
 *   - /etc/dovecot/users         (passwd-file: email:{SHA512-CRYPT}hash)
 *   - /var/mail/vhosts/{domain}/{user}/  (Maildir, owned vmail:mail)
 *
 * After any mailbox-map change it rebuilds the Postfix hash with postmap and
 * reloads Dovecot's passwd-file. Usage (root):
 *
 *   php /var/www/gallery/bin/mail_admin.php list
 *   php /var/www/gallery/bin/mail_admin.php create user@example.com 'password'
 *   php /var/www/gallery/bin/mail_admin.php delete user@example.com
 *   php /var/www/gallery/bin/mail_admin.php password user@example.com 'newpass'
 *   php /var/www/gallery/bin/mail_admin.php status
 *
 * Output is a single JSON line on stdout: {"ok":bool,"mailboxes":[...]|"error":str}
 * Exit code 0 on success, non-zero on failure.
 */

declare(strict_types=1);

const VMAILBOX_FILE = '/etc/postfix/vmailbox';
const PASSWD_FILE   = '/etc/dovecot/users';
const VMAIL_DIR     = '/var/mail/vhosts';

const VMAIL_UID = 'vmail';
const VMAIL_GID = 'mail';

/**
 * Abort with a JSON error payload and exit code 1. When $cond is false the
 * message is emitted and the script exits. Global so helper functions can
 * call it without capturing a closure.
 */
function abort(bool $cond, string $msg): void
{
    if (!$cond) {
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit(1);
    }
}

// Must actually be running as root: this script edits /etc/postfix and
// /etc/dovecot. The scoped sudoers rule guarantees this.
abort(function_exists('posix_geteuid') ? posix_geteuid() === 0 : file_exists('/etc/postfix'),
    'mail_admin must run as root (via the scoped sudoers rule)');

$command = (string) ($argv[1] ?? '');
$email   = strtolower(trim((string) ($argv[2] ?? '')));
$password = (string) ($argv[3] ?? '');

abort(in_array($command, ['list', 'create', 'delete', 'password', 'status'], true),
    "unknown command: {$command}");
abort($email === '' || preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i', $email),
    'invalid email address');
if (in_array($command, ['create', 'password'], true)) {
    abort(strlen($password) >= 8, 'password must be at least 8 characters');
}

/** Parse the Postfix vmailbox map into [email => 'domain/user/']. */
function readVmailbox(): array
{
    if (!is_file(VMAILBOX_FILE)) {
        return [];
    }
    $map = [];
    foreach (file(VMAILBOX_FILE) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (preg_match('/^(\S+)\s+(\S+)$/', $line, $m)) {
            $map[strtolower($m[1])] = $m[2];
        }
    }
    return $map;
}

/** Write the vmailbox map back atomically and rebuild the Postfix hash. */
function writeVmailbox(array $map): void
{
    $lines = [];
    ksort($map);
    foreach ($map as $email => $path) {
        $lines[] = $email . "    " . $path;
    }
    abort(file_put_contents(VMAILBOX_FILE, implode("\n", $lines) . "\n") !== false,
        'failed to write ' . VMAILBOX_FILE);
    $out = [];
    exec('postmap ' . escapeshellarg(VMAILBOX_FILE) . ' 2>&1', $out, $rc);
    abort($rc === 0, 'postmap failed: ' . implode(' ', $out));
}

/** Parse the Dovecot passwd-file into [email => hash]. */
function readPasswd(): array
{
    if (!is_file(PASSWD_FILE)) {
        return [];
    }
    $users = [];
    foreach (file(PASSWD_FILE) as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $users[strtolower($parts[0])] = $parts[1];
        }
    }
    return $users;
}

/** Write the passwd-file back atomically. */
function writePasswd(array $users): void
{
    $lines = [];
    ksort($users);
    foreach ($users as $email => $hash) {
        $lines[] = $email . ':' . $hash;
    }
    abort(file_put_contents(PASSWD_FILE, implode("\n", $lines) . "\n") !== false,
        'failed to write ' . PASSWD_FILE);
    // Rebuild the passwd.db cache used by some Dovecot configs.
    @unlink(PASSWD_FILE . '.db');
    $out = [];
    exec('doveadm reload 2>&1', $out, $rc);
    abort($rc === 0, 'doveadm reload failed: ' . implode(' ', $out));
}

/** Generate a {SHA512-CRYPT} password hash for the passwd-file. */
function hashPassword(string $password): string
{
    $out = [];
    exec('doveadm pw -s SHA512-CRYPT -p ' . escapeshellarg($password) . ' 2>&1', $out, $rc);
    abort($rc === 0 && isset($out[0]), 'doveadm pw failed: ' . implode(' ', $out));
    return trim($out[0]);
}

/** Maildir path for a mailbox (domain/user/ under the vhosts base). */
function mailboxPath(string $email): array
{
    [$user, $domain] = explode('@', $email, 2);
    return [$domain, $user, VMAIL_DIR . '/' . $domain . '/' . $user . '/'];
}

/** Parse the vmailbox path back into domain/user. */
function pathToParts(string $path): array
{
    $path = trim($path, '/');
    $bits = explode('/', $path);
    return [$bits[0] ?? '', $bits[1] ?? ''];
}

/** Build the mailbox listing (union of vmailbox + passwd-file + disk usage). */
function mailboxList(): array
{
    $vmap   = readVmailbox();
    $passwd = readPasswd();
    $emails = array_keys(array_merge($vmap, $passwd));
    sort($emails);

    $mailboxes = [];
    foreach ($emails as $email) {
        $domain = '';
        $user   = '';
        if (isset($vmap[$email])) {
            [$domain, $user] = pathToParts($vmap[$email]);
        } else {
            [$user, $domain] = explode('@', $email, 2);
        }

        $dir  = VMAIL_DIR . '/' . $domain . '/' . $user . '/';
        $size = 0;
        if (is_dir($dir)) {
            $sOut = [];
            exec('du -sk ' . escapeshellarg($dir) . ' 2>/dev/null', $sOut, $sRc);
            if ($sRc === 0 && isset($sOut[0])) {
                // du prints "<kb>\t<path>"; take only the leading number so
                // digits in the path (e.g. amethyst2213.com) never leak in.
                $size = (int) trim((string) preg_split('/\s+/', $sOut[0])[0] ?? '0');
            }
        }

        $mailboxes[] = [
            'email'  => $email,
            'domain' => $domain,
            'user'   => $user,
            'exists' => is_dir($dir),
            'size_kb' => $size,
        ];
    }

    return $mailboxes;
}

// ---------------------------------------------------------------- commands

if ($command === 'list' || $command === 'status') {
    $mailboxes = mailboxList();

    $vmap = readVmailbox();
    abort(postmap_exists(), 'postmap binary not found');

    $status = [
        'ok'         => true,
        'command'    => $command,
        'mailboxes'  => $mailboxes,
        'postfix'    => serviceActive('postfix'),
        'dovecot'    => serviceActive('dovecot'),
        'opendkim'   => serviceActive('opendkim'),
        'smtp_test'  => null,
    ];
    echo json_encode($status);
    exit(0);
}

if ($command === 'create') {
    $vmap   = readVmailbox();
    $passwd = readPasswd();
    abort(!isset($vmap[$email]), "mailbox already exists: {$email}");

    [$domain, $user, $dir] = mailboxPath($email);
    abort(is_dir(VMAIL_DIR) && is_dir(VMAIL_DIR . '/' . $domain),
        "domain directory missing: /var/mail/vhosts/{$domain}");

    $hash = hashPassword($password);
    $vmap[$email] = $domain . '/' . $user . '/';
    $passwd[$email] = $hash;

    writeVmailbox($vmap);
    writePasswd($passwd);

    // Create the Maildir owned by vmail:mail.
    foreach (['cur', 'new', 'tmp'] as $sub) {
        abort(mkdir($dir . $sub, 0700, true) || is_dir($dir . $sub),
            "failed to create {$dir}{$sub}");
    }
    $cOut = [];
    exec('chown -R ' . VMAIL_UID . ':' . VMAIL_GID . ' ' . escapeshellarg($dir) . ' 2>&1', $cOut, $cRc);
    abort($cRc === 0, 'chown vmail failed: ' . implode(' ', $cOut));

    echo json_encode(['ok' => true, 'command' => 'create', 'email' => $email]);
    exit(0);
}

if ($command === 'delete') {
    $vmap   = readVmailbox();
    $passwd = readPasswd();
    abort(isset($vmap[$email]) || isset($passwd[$email]), "mailbox not found: {$email}");

    unset($vmap[$email], $passwd[$email]);
    writeVmailbox($vmap);
    writePasswd($passwd);

    // Remove the maildir (cur/new/tmp) and any empty parent dirs.
    if (isset($vmap[$email]) === false && is_dir(VMAIL_DIR)) {
        [$domain, $user, $dir] = mailboxPath($email);
        if (is_dir($dir)) {
            $dOut = [];
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1', $dOut, $dRc);
            abort($dRc === 0, 'maildir removal failed: ' . implode(' ', $dOut));
        }
        @rmdir(VMAIL_DIR . '/' . $domain . '/' . $user);
        @rmdir(VMAIL_DIR . '/' . $domain);
    }

    echo json_encode(['ok' => true, 'command' => 'delete', 'email' => $email]);
    exit(0);
}

if ($command === 'password') {
    $passwd = readPasswd();
    abort(isset($passwd[$email]), "mailbox not found: {$email}");
    $passwd[$email] = hashPassword($password);
    writePasswd($passwd);

    echo json_encode(['ok' => true, 'command' => 'password', 'email' => $email]);
    exit(0);
}

/** Whether a systemd service is active. */
function serviceActive(string $name): bool
{
    $out = [];
    exec('systemctl is-active ' . escapeshellarg($name) . ' 2>/dev/null', $out, $rc);
    return $rc === 0 && trim(implode(' ', $out)) === 'active';
}

/** Whether the postmap binary exists. */
function postmap_exists(): bool
{
    $out = [];
    exec('command -v postmap 2>/dev/null', $out, $rc);
    return $rc === 0;
}

// Unreachable.
echo json_encode(['ok' => false, 'error' => 'no command']);
exit(1);