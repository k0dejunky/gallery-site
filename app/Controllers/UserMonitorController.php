<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\UserActivity;

/**
 * Admin "User Monitor" tab: a live feed of member logins, logouts and
 * gallery views, filterable by search term, action type or a specific user.
 */
class UserMonitorController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('user_monitor');
    }

    public function index(): void
    {
        $q      = trim((string) $this->request->query('q', ''));
        $action = trim((string) $this->request->query('action', ''));
        $userId = max(0, (int) $this->request->query('user', 0));

        $paginator = UserActivity::search(
            (int) $this->request->query('page', 1),
            50,
            $q,
            $action,
            $userId
        );

        $filterUser = null;
        if ($userId > 0) {
            $filterUser = \App\Models\User::find($userId);
        }

        $this->viewAdmin('user_monitor', [
            'paginator'   => $paginator,
            'facets'      => UserActivity::facets(),
            'lastSeen'    => UserActivity::lastSeenByUser(25),
            'filterQ'     => $q,
            'filterAction'=> $action,
            'filterUserId'=> $userId,
            'filterUser'  => $filterUser,
        ]);
    }
}
