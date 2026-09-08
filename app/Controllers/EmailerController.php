<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Request;
use App\Models\EmailerConfig;
use App\Models\EmailQueue;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin page for the auto-emailer: schedule + audience settings, digest
 * preview, manual "send now" / test emails and the queued/sent/failed log.
 * Everything here is gated to admins with the membership permission (router
 * 4th element plus an explicit guard in the constructor).
 */
class EmailerController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('membership');
    }

    /**
     * Show the emailer settings, current schedule/send state, recipient
     * counts, the sample the next digest will use, and the recent queue log.
     */
    public function index(): void
    {
        $config  = EmailerConfig::all();
        $samples = EmailQueue::sample((int) $config['sample_count']);

        try {
            $nextSend = EmailerConfig::nextSendAt($config);
            $nextSend = $nextSend->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            $nextSend = null;
        }

        $this->viewAdmin('emailer', [
            'config'              => $config,
            'nextSend'            => $nextSend,
            'recipientCounts'     => [
                'subscriber'     => count(EmailQueue::subscribers()),
                'non_subscriber' => count(EmailQueue::nonSubscribers()),
            ],
            'samples'             => $samples,
            'queueCounts'         => EmailQueue::statusCounts(),
            'recent'              => EmailQueue::recent(50),
            'timezones'           => self::timezoneList(),
        ]);
    }

    /**
     * Persist the admin's schedule and settings (runtime last-sent state is
     * preserved). The next digest uses these values the moment it is due.
     */
    public function save(): void
    {
        $timezone = self::readTimezone();

        EmailerConfig::save([
            'enabled'                 => $this->request->post('enabled') !== null,
            'mode'                    => (string) $this->request->post('mode', 'daily'),
            'every_hours'             => (int) $this->request->post('every_hours', 6),
            'day_of_week'             => (int) $this->request->post('day_of_week', 1),
            'hour'                    => (int) $this->request->post('hour', 9),
            'minute'                  => (int) $this->request->post('minute', 0),
            'sample_count'            => (int) $this->request->post('sample_count', 6),
            'include_non_subscribers' => $this->request->post('include_non_subscribers') !== null,
            'subject_subscriber'      => (string) $this->request->post('subject_subscriber', ''),
            'subject_non_subscriber'  => (string) $this->request->post('subject_non_subscriber', ''),
        ], $timezone);

        $this->flash('success', 'Emailer settings saved.');
        $this->redirect('/admin/emailer');
    }

    /**
     * Enqueue a digest immediately, bypassing the "no new uploads" watermark
     * (so a first run with existing content still sends). Uses the same
     * enqueue path as the cron worker, so behaviour is identical.
     */
    public function sendNow(): void
    {
        $result = EmailQueue::enqueueDigest(EmailerConfig::all(), true);

        if (empty($result['ok'])) {
            $reason = [
                'no_sample'      => 'There are no image uploads to email yet — upload some photos first.',
                'no_recipients'  => 'No eligible recipients yet — no verified opted-in accounts.',
                'no_new_uploads' => 'No new uploads since the last digest (use a test email instead).',
            ][$result['reason']] ?? 'Could not enqueue the digest.';

            $this->flash('error', $reason);
            $this->redirect('/admin/emailer');
            return;
        }

        $audience = $result['audience'];
        $this->flash('success', sprintf(
            'Queued a digest of %d photo(s): %s subscriber and %s non-subscriber email(s).',
            (int) $result['count'],
            number_format((int) $audience['subscriber']),
            number_format((int) $audience['non_subscriber'])
        ));
        $this->redirect('/admin/emailer');
    }

    /**
     * Send a single test digest to the admin's inbox (never touches the
     * queue), so the look and the SMTP path can be verified before a real
     * send.
     */
    public function test(): void
    {
        $recipient = trim((string) env_value('ADMIN_EMAIL', ''));

        if ($recipient === '') {
            $this->flash('error', 'Set ADMIN_EMAIL in .env, then try again.');
            $this->redirect('/admin/emailer');
            return;
        }

        $config   = EmailerConfig::all();
        $audience = $this->request->post('audience', 'subscriber') === 'non_subscriber'
            ? 'non_subscriber'
            : 'subscriber';

        $samples = EmailQueue::sample((int) $config['sample_count']);

        if ($samples === []) {
            $this->flash('error', 'There are no image uploads to preview yet.');
            $this->redirect('/admin/emailer');
            return;
        }

        $count   = count($samples);
        $isSub   = $audience === 'subscriber';
        $subject = EmailQueue::subjectFor($config, $audience, $count);
        $sent    = Mailer::sendHtml(
            $recipient,
            '[TEST] ' . $subject,
            render_email('newsletter', ['subscriber' => $isSub, 'samples' => $samples, 'count' => $count]),
            render_email('newsletter.text', ['subscriber' => $isSub, 'samples' => $samples, 'count' => $count])
        );

        $this->flash($sent ? 'success' : 'error', $sent
            ? 'Test ' . ($isSub ? 'subscriber' : 'non-subscriber') . ' email sent to ' . $recipient . '.'
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
     * The submit timezone, validated as a real IANA identifier (UTC fallback).
     */
    private static function readTimezone(): string
    {
        try {
            return (new DateTimeZone(trim((string) ($_POST['timezone'] ?? 'UTC'))))->getName();
        } catch (\Throwable $e) {
            return 'UTC';
        }
    }

    /**
     * The timezone dropdown options: the common regions plus UTC.
     */
    private static function timezoneList(): array
    {
        $list = [['UTC', 'UTC (UTC)']];

        foreach (DateTimeZone::listIdentifiers() as $tz) {
            if (preg_match('/^((Africa|America|Antarctica|Arctic|Asia|Atlantic|Australia|Europe|Indian|Pacific)\/)/', $tz) === 1) {
                $list[] = [$tz, $tz];
            }
        }

        return $list;
    }
}