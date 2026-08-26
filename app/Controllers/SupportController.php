<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Request;
use App\Models\SupportMessage;
use Throwable;

/**
 * Handles member support tickets and permission-gated admin actions.
 */
class SupportController extends Controller
{
    /**
     * Require authentication only in the actions that need it because this
     * controller serves both member and permission-gated admin routes.
     */
    public function form(): void
    {
        $siteEditorPreview = $this->request->query('se', '') === 'user';
        if (!$siteEditorPreview) {
            Auth::requireLogin();
        }
        $user = $siteEditorPreview ? ['id' => 0] : Auth::user();
        $this->view('support/contact', [
            'title' => 'Support',
            'tickets' => $siteEditorPreview ? [] : SupportMessage::forUser((int) $user['id']),
            'siteEditorPreview' => $siteEditorPreview,
        ]);
    }

    /**
     * Validate and save a member support request, then notify the admin.
     */
    public function submit(): void
    {
        Auth::requireLogin();

        $subject = $this->request->input('subject');
        $message = $this->request->input('message');

        if ($subject === '' || mb_strlen($subject) > 255) {
            $this->flash('error', 'Please enter a subject of 255 characters or fewer.');
            $this->redirect('/support');
        }

        if ($message === '' || mb_strlen($message) > 10000) {
            $this->flash('error', 'Please enter a message of 10,000 characters or fewer.');
            $this->redirect('/support');
        }

        // Prevent user input from becoming additional mail headers.
        if (preg_match('/[\r\n]/', $subject)) {
            $this->flash('error', 'The subject contains invalid characters.');
            $this->redirect('/support');
        }

        $user = Auth::user();
        $email = (string) $user['email'];
        $id = SupportMessage::create((int) $user['id'], $email, $subject, $message);

        try {
            $adminEmail = Mailer::adminEmail();
            if ($adminEmail !== '') {
                Mailer::send(
                    $adminEmail,
                    'New support message: ' . $subject,
                    "Message #{$id} from {$email}\n\nSubject: {$subject}\n\n{$message}"
                );
            }
        } catch (Throwable $exception) {
            error_log('[SUPPORT] Admin notification failed: ' . $exception->getMessage());
        }

        $this->flash('success', 'Your support request was sent. We will get back to you soon.');
        $this->redirect('/support/' . $id);
    }

    public function show(int $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $ticket = SupportMessage::findForUser($id, (int) $user['id']);
        if ($ticket === null) {
            $this->notFound();
            return;
        }
        SupportMessage::markReadForUser($id, (int) $user['id']);
        $this->view('support/show', ['title' => $ticket['subject'], 'ticket' => $ticket, 'replies' => SupportMessage::replies($id)]);
    }

    public function reply(int $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $ticket = SupportMessage::findForUser($id, (int) $user['id']);
        if ($ticket === null) {
            $this->notFound();
            return;
        }
        if (in_array($ticket['status'], ['resolved', 'ignored'], true)) {
            $this->flash('error', 'This ticket is closed to new replies.');
            $this->redirect('/support/' . $id);
        }
        $message = $this->request->input('message');
        if ($message === '' || mb_strlen($message) > 10000) {
            $this->flash('error', 'Please enter a reply of 10,000 characters or fewer.');
            $this->redirect('/support/' . $id);
        }
        SupportMessage::addReply($id, (int) $user['id'], 'user', $message);
        $adminEmail = Mailer::adminEmail();
        if ($adminEmail !== '') {
            Mailer::send($adminEmail, 'New reply on support ticket #' . $id,
                "Ticket #{$id} received a reply from {$user['email']}.\n\n{$message}");
        }
        $this->flash('success', 'Your reply was sent.');
        $this->redirect('/support/' . $id);
    }

    /**
     * List submitted support messages for admins with the support permission.
     */
    public function index(): void
    {
        Auth::requirePermission('support');
        $this->viewAdmin('support', ['title' => 'Support Messages', 'messages' => SupportMessage::all()]);
    }

    public function adminShow(int $id): void
    {
        Auth::requirePermission('support');
        $ticket = SupportMessage::find($id);
        if ($ticket === null) { $this->notFound(); return; }
        $this->viewAdmin('support_show', ['title' => 'Support #' . $id, 'ticket' => $ticket, 'replies' => SupportMessage::replies($id)]);
    }

    public function adminReply(int $id): void
    {
        Auth::requirePermission('support');
        $ticket = SupportMessage::find($id);
        if ($ticket === null) { $this->notFound(); return; }
        if (in_array($ticket['status'], ['resolved', 'ignored'], true)) {
            $this->flash('error', 'This ticket is closed to new replies.');
            $this->redirect('/admin/support/' . $id);
        }
        $message = $this->request->input('message');
        if ($message === '' || mb_strlen($message) > 10000) {
            $this->flash('error', 'Please enter a reply of 10,000 characters or fewer.');
            $this->redirect('/admin/support/' . $id);
        }
        $admin = Auth::user();
        SupportMessage::addReply($id, (int) $admin['id'], 'admin', $message);
        SupportMessage::setStatus($id, 'read');
        if ((string) ($ticket['email'] ?? '') !== '') {
            Mailer::send((string) $ticket['email'], 'Reply to support ticket #' . $id,
                "Your support ticket received a reply.\n\n{$message}");
        }
        $this->flash('success', 'Reply sent.');
        $this->redirect('/admin/support/' . $id);
    }

    public function status(int $id): void
    {
        Auth::requirePermission('support');
        $ticket = SupportMessage::find($id);
        if ($ticket === null) { $this->notFound(); return; }
        $status = $this->request->input('status');
        if (!in_array($status, ['read', 'postponed', 'resolved', 'ignored'], true)) {
            $this->flash('error', 'Invalid support status.');
            $this->redirect('/admin/support/' . $id);
        }
        SupportMessage::setStatus($id, $status);
        if (in_array($status, ['postponed', 'resolved', 'ignored'], true) && (string) ($ticket['email'] ?? '') !== '') {
            Mailer::send((string) $ticket['email'], 'Support ticket #' . $id . ' updated',
                'Your support ticket status is now: ' . $status . '.');
        }
        $this->flash('success', 'Ticket status updated.');
        $this->redirect('/admin/support/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requirePermission('support');
        if (SupportMessage::find($id) === null) { $this->notFound(); return; }
        SupportMessage::delete($id);
        $this->flash('success', 'Support ticket deleted.');
        $this->redirect('/admin/support');
    }
}
