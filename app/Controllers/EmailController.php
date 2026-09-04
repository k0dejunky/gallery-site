<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Models\AuditLog;

/**
 * Admin "Email" page: manage the virtual mailboxes served by Postfix + Dovecot
 * on the mail host. All privileged operations are delegated to the root-only
 * bin/mail_admin.php via a scoped sudoers rule, so the web process never
 * touches /etc/postfix or /etc/dovecot directly.
 */
class EmailController extends Controller
{
    private string $root;

    public function __construct($request)
    {
        parent::__construct($request);
        Auth::requirePermission('dashboard');

        $this->root = dirname(__DIR__, 2);
    }

    /** The admin page: mailbox list + server status + a send-test form. */
    public function index(): void
    {
        $state = $this->runScript(['list']);

        $this->viewAdmin('mail', [
            'mailboxes'  => $state['mailboxes'] ?? [],
            'server'     => [
                'ok'       => (bool) ($state['ok'] ?? false),
                'error'    => (string) ($state['error'] ?? ''),
                'postfix'  => $state['postfix'] ?? null,
                'dovecot'  => $state['dovecot'] ?? null,
                'opendkim' => $state['opendkim'] ?? null,
            ],
            'adminEmail' => Mailer::adminEmail(),
        ]);
    }

    /** Create a new mailbox. */
    public function create(): void
    {
        $email    = strtolower(trim($this->request->input('email')));
        $password = (string) $this->request->post('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/admin/mail');
        }
        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            $this->redirect('/admin/mail');
        }

        $state = $this->runScript(['create', $email, $password]);

        if (!empty($state['error'])) {
            $this->flash('error', 'Could not create mailbox: ' . $state['error']);
        } else {
            AuditLog::record((int) (Auth::user()['id'] ?? 0), 'create', 'mailbox', null, 'Created mailbox ' . $email);
            $this->flash('success', 'Mailbox ' . $email . ' created.');
        }

        $this->redirect('/admin/mail');
    }

    /** Delete a mailbox (with confirmation in the view). */
    public function delete(): void
    {
        $email = strtolower(trim($this->request->input('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/admin/mail');
        }

        $state = $this->runScript(['delete', $email]);

        if (!empty($state['error'])) {
            $this->flash('error', 'Could not delete mailbox: ' . $state['error']);
        } else {
            AuditLog::record((int) (Auth::user()['id'] ?? 0), 'delete', 'mailbox', null, 'Deleted mailbox ' . $email);
            $this->flash('success', 'Mailbox ' . $email . ' deleted.');
        }

        $this->redirect('/admin/mail');
    }

    /** Change a mailbox password. */
    public function password(): void
    {
        $email    = strtolower(trim($this->request->input('email')));
        $password = (string) $this->request->post('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/admin/mail');
        }
        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            $this->redirect('/admin/mail');
        }

        $state = $this->runScript(['password', $email, $password]);

        if (!empty($state['error'])) {
            $this->flash('error', 'Could not change password: ' . $state['error']);
        } else {
            AuditLog::record((int) (Auth::user()['id'] ?? 0), 'update', 'mailbox', null, 'Changed password for ' . $email);
            $this->flash('success', 'Password updated for ' . $email . '.');
        }

        $this->redirect('/admin/mail');
    }

    /** Send a test email through the configured mail transport. */
    public function smtpTest(): void
    {
        $to = trim($this->request->input('to', Mailer::adminEmail()));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid recipient email is required.');
            $this->redirect('/admin/mail');
        }

        $ok = Mailer::send($to, 'Gallery admin test email', "This is a test email sent from the gallery admin panel.\n\nIf you are reading this, email delivery is working.\n");

        AuditLog::record((int) (Auth::user()['id'] ?? 0), 'create', 'mail_test', null, 'Sent test email to ' . $to);

        $this->flash($ok ? 'success' : 'error', $ok
            ? 'Test email sent to ' . $to . ' — check the inbox (and spam folder).'
            : 'Test email could not be sent — check the mail configuration.');

        $this->redirect('/admin/mail');
    }

    /**
     * Run bin/mail_admin.php with the given arguments via sudo -n and decode
     * its single JSON line. Returns ['ok'=>bool, ...] merged with the script
     * output, defaulting to an error payload when the script is unreachable.
     */
    private function runScript(array $args): array
    {
        // PHP_BINARY inside FPM is the php-fpm master, which cannot run a
        // script; use the fixed CLI binary like bin/apply_cron.php does.
        $phpBin = '/usr/bin/php';
        $cmd    = 'sudo -n ' . escapeshellarg($phpBin) . ' '
            . escapeshellarg($this->root . '/bin/mail_admin.php');

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';

        $outLines = [];
        exec($cmd, $outLines, $rc);

        $output = implode("\n", $outLines);

        if ($rc !== 0 || trim($output) === '') {
            return [
                'ok'    => false,
                'error' => $rc === 1 && $output !== ''
                    ? trim($output)
                    : 'Mail admin script unreachable (check the www-data sudoers rule for bin/mail_admin.php).',
            ];
        }

        $decoded = json_decode($output, true);

        return is_array($decoded)
            ? $decoded
            : ['ok' => false, 'error' => 'Unexpected mail admin output: ' . trim($output)];
    }
}