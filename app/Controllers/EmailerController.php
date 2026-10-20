<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Mailer;
use App\Models\EmailerConfig;
use App\Models\EmailQueue;

/**
 * Admin page for the auto-emailer.
 *
 * There is nothing to schedule any more: the periodic newsletter digest was
 * removed. What is left is a set of switches over the four kinds of mail the
 * site actually sends - a chat/support answer, one email for the day a gallery
 * is posted, the live broadcast, the 7-day renewal reminder, a win-back, and
 * billing dunning - plus the queue log, retry, and per-template test sends so
 * each one can be reviewed without touching a real member.
 *
 * Everything here is gated to admins with the membership permission (router
 * 4th element plus an explicit guard in the constructor).
 */
class EmailerController extends MembershipAdminController
{
    /**
     * Show the emailer switches, what each one sends, the queue counts by kind
     * and the recent queue log.
     *
     * The queue list can be filtered by status and by kind, and exported as
     * CSV, so the operator can work through a backlog of failures without
     * scrolling a page of 50 mixed rows.
     */
    public function index(): void
    {
        $status = trim((string) ($this->request->query('status') ?? ''));
        if (!in_array($status, ['queued', 'sent', 'failed'], true)) {
            $status = '';
        }

        $kind = trim((string) ($this->request->query('kind') ?? ''));

        $this->viewAdmin('emailer', [
            'config'         => EmailerConfig::all(),
            'queueCounts'    => EmailQueue::statusCounts(),
            'kindCounts'     => EmailQueue::kindCounts(),
            'recent'         => EmailQueue::recent(50, $status, $kind),
            'lifecycleStats' => EmailQueue::lifecycleStats(),
            'filterStatus'   => $status,
            'filterKind'     => $kind,
        ]);
    }

    /**
     * The queue log as a CSV file, with the same filters the page uses.
     */
    public function export(): void
    {
        $status = trim((string) ($this->request->query('status') ?? ''));
        if (!in_array($status, ['queued', 'sent', 'failed'], true)) {
            $status = '';
        }

        $kind = trim((string) ($this->request->query('kind') ?? ''));

        $rows = EmailQueue::forExport(2000, $status, $kind);

        $out = fopen('php://output', 'w');
        if ($out === false) {
            $this->flash('error', 'Could not build the export.');
            $this->redirect('/admin/emailer');
            return;
        }

        fputcsv($out, ['id', 'kind', 'status', 'email', 'subject', 'attempts', 'created_at', 'sent_at', 'error']);

        foreach ($rows as $row) {
            fputcsv($out, [
                (int) $row['id'],
                (string) $row['kind'],
                (string) $row['status'],
                (string) $row['email'],
                (string) $row['subject'],
                (int) $row['attempts'],
                (string) $row['created_at'],
                (string) ($row['sent_at'] ?? ''),
                (string) ($row['error'] ?? ''),
            ]);
        }

        fclose($out);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="email-queue-' . date('Ymd-His') . '.csv"');
        exit;
    }

    /**
     * Persist the admin's switches. Only the surviving keys are written; the
     * retired digest schedule fields are dropped.
     */
    public function save(): void
    {
        EmailerConfig::save([
            'enabled'                     => $this->request->post('enabled') !== null,
            'lifecycle_renewal_reminders' => $this->request->post('lifecycle_renewal_reminders') !== null,
            'lifecycle_winbacks'          => $this->request->post('lifecycle_winbacks') !== null,
            'lifecycle_trial_endings'     => $this->request->post('lifecycle_trial_endings') !== null,
            'lifecycle_inactive_winbacks' => $this->request->post('lifecycle_inactive_winbacks') !== null,
            'notification_daily_cap'      => (int) $this->request->post('notification_daily_cap', 1),
        ]);

        $this->flash('success', 'Emailer settings saved.');
        $this->redirect('/admin/emailer');
    }

    /**
     * Send a single test of one email template straight to the admin's inbox
     * with sample data, so each template and the SMTP path can be reviewed
     * without touching the queue or any real member.
     */
    public function testLifecycle(string $kind): void
    {
        // kind => [subject, template, data]
        $kinds = [
            'payment_failed'    => ["Your payment didn't go through", 'payment_failed', []],
            'past_due'          => ['Your subscription is past due', 'past_due', []],
            'expired'           => ['Your membership has ended', 'expired', []],
            'renewal_reminder'  => ['Your renewal is coming up', 'renewal_reminder', []],
            'winback'           => ['We miss you', 'winback', []],
            'new_gallery'       => ['New gallery: sample set', 'new_gallery', ['gallery' => ['id' => 0, 'title' => 'Sample set', 'description' => 'A sample gallery for the test send.', 'is_secret' => 0]]],
            'live_now'          => ['She is live now', 'live_now', []],
            'chat_reply'        => ['You have a new reply', 'chat_reply', ['conversation_id' => 0]],
        ];

        if (!isset($kinds[$kind])) {
            $this->notFound();
            return;
        }

        [$subject, $template, $data] = $kinds[$kind];

        $recipient = trim((string) env_value('ADMIN_EMAIL', ''));

        if ($recipient === '') {
            $this->flash('error', 'Set ADMIN_EMAIL in .env, then try again.');
            $this->redirect('/admin/emailer');
            return;
        }

        $data += [
            'planName'      => 'Gold',
            'amount'        => 10.00,
            'dueAmount'     => 10.00,
            'attemptedAt'   => date('F j, Y'),
            'renewsOn'      => date('F j, Y', strtotime('+7 days')),
            'manageUrl'     => absolute_url('/membership/my'),
            'rejoinUrl'     => absolute_url('/membership'),
            'galleryUrl'    => absolute_url('/galleries'),
            'membershipUrl' => absolute_url('/membership'),
        ];

        $sent = Mailer::sendHtml(
            $recipient,
            '[TEST] ' . $subject,
            render_email($template, $data),
            render_email($template . '.text', $data)
        );

        $this->flash($sent ? 'success' : 'error', $sent
            ? 'Test "' . $subject . '" email sent to ' . $recipient . '.'
            : 'Could not send the test — check the SMTP settings and error logs.');
        $this->redirect('/admin/emailer');
    }

    /**
     * Move a given-up (failed) queue row back to the front of the queue.
     */
    public function retry(): void
    {
        $id = (int) $this->request->post('queue_id', 0);

        if ($id <= 0 || !EmailQueue::requeue($id)) {
            $this->flash('error', 'That queued email could not be retried.');
            $this->redirect('/admin/emailer');
            return;
        }

        $this->flash('success', 'Email #' . $id . ' moved back to the sending queue.');
        $this->redirect('/admin/emailer');
    }

    /**
     * Move EVERY failed row back into the queue. One click instead of one
     * click per row when the SMTP server was down for an hour.
     */
    public function retryAll(): void
    {
        $done = EmailQueue::requeueAllFailed();

        $this->flash($done > 0 ? 'success' : 'error', $done > 0
            ? $done . ' failed email(s) moved back to the sending queue.'
            : 'There are no failed emails to retry.');
        $this->redirect('/admin/emailer');
    }
}
