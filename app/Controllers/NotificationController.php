<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;

/**
 * Member notification hub: unread badge in the nav, an inbox page, per-item
 * "read and go" and a mark-all-read action.
 */
class NotificationController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $notifications = Notification::forUser((int) $user['id'], 50);
        $unreadIds = [];
        foreach ($notifications as $n) {
            if ($n['read_at'] === null) {
                $unreadIds[] = (int) $n['id'];
            }
        }

        $this->view('notifications', [
            'notifications' => $notifications,
            'unreadIds'     => $unreadIds,
            'sidebarNav'    => true,
        ]);
    }

    /** Mark one notification read and jump to its target. */
    public function show(int $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $item = Notification::forUserOne((int) $user['id'], $id);
        if ($item === null) {
            $this->redirect('/notifications');
            return;
        }

        Notification::markRead($id, (int) $user['id']);
        $this->redirect($item['url'] !== '' ? $item['url'] : '/notifications');
    }

    public function readAll(): void
    {
        Auth::requireLogin();
        Notification::markAllRead((int) Auth::user()['id']);

        $this->flash('success', 'All notifications marked as read.');
        $this->redirect('/notifications');
    }
}