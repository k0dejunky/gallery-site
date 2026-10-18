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
     */
    public function index(): void
    {
        $this->viewAdmin('emailer', [
            'config'         => EmailerConfig::all(),
            'queueCounts'    => EmailQueue::statusCounts(),
            'kindCounts'     => EmailQueue::kindCounts(),
            'recent'         => EmailQueue::recent(50),
            'lifecycleStats' => EmailQueue::lifecycleStats(),
        ]);
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
}
