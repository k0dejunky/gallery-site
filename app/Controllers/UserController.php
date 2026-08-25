<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

class UserController extends Controller
{
    private function requestedRole(): string
    {
        $role = (string) $this->request->input('role');
        $allowed = ['user', 'editor', 'moderator', 'viewer'];
        if (Auth::isAdmin()) $allowed[] = 'admin';
        if (Auth::can('manage_roles')) $allowed[] = 'super_admin';
        return in_array($role, $allowed, true) ? $role : 'user';
    }
    /**
     * User management is admin-only.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('users');
    }

    /**
     * Admin: user accounts list with optional search, filtering, sorting, pagination.
     */
    public function index(): void
    {
        $search = trim($this->request->input('q') ?? '');
        $flag   = trim((string) ($this->request->query('flag') ?? ''));
        $status = trim((string) ($this->request->query('status') ?? ''));
        $role   = trim((string) ($this->request->query('role') ?? ''));

        $sortBy = (string) ($this->request->query('sort') ?? 'created_at');
        $sortDir = (string) ($this->request->query('dir') ?? 'ASC');
        $page   = max(1, (int) ($this->request->query('page') ?? 1));
        $perPage = 50;

        $allowedSort = ['email', 'created_at', 'role', 'status'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';

        $offset = ($page - 1) * $perPage;

        $users = User::all($search, $flag, $status, $role, $sortBy, $sortDir, $perPage, $offset);

        // Count total matching users (without LIMIT/OFFSET) for pagination
        $where  = [];
        $params = [];

        if ($search !== '') {
            $where[]  = 'u.email LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if ($flag !== '') {
            $where[]  = 'u.flag = ?';
            $params[] = $flag;
        }
        if ($status !== '') {
            $where[]  = 'u.status = ?';
            $params[] = $status;
        }
        if ($role !== '') {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }

        $countSql = 'SELECT COUNT(*) FROM users u';
        if ($where !== []) {
            $countSql .= ' WHERE ' . implode(' AND ', $where);
        }
        $totalUsers = (int) Database::run($countSql, $params)->fetchColumn();
        $totalPages = max(1, (int) ceil($totalUsers / $perPage));

        $activeCount    = User::countByStatus('active');
        $suspendedCount = User::countByStatus('suspended');

        $this->viewAdmin('users', [
            'users'          => $users,
            'search'         => $search,
            'flag'           => $flag,
            'status'         => $status,
            'role'           => $role,
            'sortBy'         => $sortBy,
            'sortDir'        => $sortDir,
            'page'           => $page,
            'totalPages'     => $totalPages,
            'totalUsers'     => $totalUsers,
            'activeCount'    => $activeCount,
            'suspendedCount' => $suspendedCount,
            'roles'          => Auth::ADMIN_ROLES,
        ]);
    }

    /**
     * Bulk actions over the checked users: assign a role or delete.
     * GET with ?preview=1 shows a confirmation page first.
     */
    public function bulk(): void
    {
        $ids    = array_values(array_filter(array_map('intval', (array) ($this->request->post('ids') ?? []))));
        $action = (string) $this->request->post('action', '');

        // Preview mode: GET request with ?preview=1
        if ($this->request->isGet() && (string) ($this->request->query('preview') ?? '') !== '') {
            $previewIds = array_values(array_filter(array_map('intval', (array) ($this->request->query('ids') ?? []))));
            $previewAction = (string) ($this->request->query('action') ?? '');
            $previewRole   = (string) ($this->request->query('role') ?? '');

            if ($previewIds === []) {
                $this->flash('error', 'No users selected.');
                $this->redirect('/admin/users');
            }

            $previewUsers = [];
            foreach ($previewIds as $pid) {
                $u = User::find($pid);
                if ($u !== null) {
                    $previewUsers[] = $u;
                }
            }

            $this->viewAdmin('user_bulk_preview', [
                'previewUsers' => $previewUsers,
                'previewAction' => $previewAction,
                'previewRole'   => $previewRole,
                'ids'           => $previewIds,
            ]);
            return;
        }

        $me = (int) Auth::user()['id'];

        if ($ids === []) {
            $this->flash('error', 'No users selected.');
            $this->redirect('/admin/users');
        }

        if (!in_array($action, ['role', 'delete', 'suspend', 'activate'], true)) {
            $this->flash('error', 'Unknown bulk action.');
            $this->redirect('/admin/users');
        }

        $role = (string) $this->request->post('role', '');

        if ($action === 'role' && !in_array($role, Auth::ADMIN_ROLES, true) && $role !== 'user') {
            $this->flash('error', 'Unknown role.');
            $this->redirect('/admin/users');
        }

        $done = 0;

        foreach ($ids as $id) {
            if ($id === $me) {
                continue;
            }

            $user = User::find($id);

            if ($user === null) {
                continue;
            }

            if ($action === 'delete') {
                // Never delete the final admin, mirroring single-user delete.
                if (in_array($user['role'], Auth::ADMIN_ROLES, true) && User::countAdmins() <= 1) {
                    continue;
                }
                User::delete($id);
                AuditLog::record($me, 'delete', 'user', $id, 'Bulk-deleted account "' . $user['email'] . '"', ['email' => $user['email'], 'role' => $user['role']]);
                $done++;
            } elseif ($action === 'suspend' || $action === 'activate') {
                if ($id === $me) {
                    continue;
                }
                $status = $action === 'suspend' ? 'suspended' : 'active';
                Database::run('UPDATE users SET status = ?, session_version = session_version + 1 WHERE id = ?', [$status, $id]);
                AuditLog::record($me, 'update', 'user', $id,
                    'Bulk-' . $action . ' account "' . $user['email'] . '"',
                    ['status' => $user['status'] ?? null], ['status' => $status]);
                $done++;
            } else {
                Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
                AuditLog::record($me, 'update', 'user', $id, 'Bulk role change "' . $user['email'] . '" → ' . $role, ['role' => $user['role']], ['role' => $role]);
                $done++;
            }
        }

        $labels = ['role' => 'Role set', 'delete' => 'Deleted', 'suspend' => 'Suspended', 'activate' => 'Reactivated'];
        $this->flash('success', ($labels[$action] ?? 'Done') . " for {$done} user(s).");
        $this->redirect('/admin/users');
    }

    /**
     * Sign in as another account for support debugging. The original admin
     * id is kept in the session so the bar in the layout can switch back;
     * both directions are audit-logged.
     */
    public function impersonate(int $id): void
    {        $target = User::find($id);
        $me = Auth::user();

        if ($target === null || $id === (int) $me['id']) {
            $this->flash('error', 'Cannot impersonate that account.');
            $this->redirect('/admin/users');
        }

        if ($target['role'] === 'super_admin' && $me['role'] !== 'super_admin') {
            $this->flash('error', 'Only a super admin can impersonate a super admin.');
            $this->redirect('/admin/users');
        }

        $_SESSION['impersonator_id'] = (int) $me['id'];
        AuditLog::record((int) $me['id'], 'create', 'impersonation', $id,
            'Started impersonating "' . $target['email'] . '"', null, ['impersonated_user_id' => $id]);

        Auth::loginUser($id);
        $this->flash('success', 'You are now browsing as ' . $target['email'] . '.');
        $this->redirect('/galleries');
    }

    /**
     * Leave impersonation and restore the original admin session.
     */
    public function exitImpersonation(): void
    {
        $adminId = (int) ($_SESSION['impersonator_id'] ?? 0);

        if ($adminId <= 0) {
            $this->redirect('/admin');
        }

        unset($_SESSION['impersonator_id']);
        AuditLog::record($adminId, 'delete', 'impersonation', $adminId,
            'Stopped impersonating and restored own admin session');

        Auth::loginUser($adminId);
        $this->flash('success', 'Welcome back, admin.');
        $this->redirect('/admin');
    }

    /**
     * Shared data for the user detail page: membership history, audit-trail
     * mentions and recent sign-in attempts.
     */
    private function userContext(int $id): array
    {
        $user = User::find($id);

        $subscriptions = Database::run(
            'SELECT s.*, p.name AS plan_name, pp.name AS processor_name
             FROM subscriptions s
             LEFT JOIN plans p ON p.id = s.plan_id
             LEFT JOIN payment_processors pp ON pp.id = s.payment_processor_id
             WHERE s.user_id = ? ORDER BY s.created_at DESC LIMIT 25',
            [$id]
        )->fetchAll();

        // Audit trail mentioning this account (role changes, suspensions,
        // impersonation, password work) plus recent sign-in attempts.
        $activity = Database::run(
            'SELECT created_at, action, entity_type, entity_id, description
             FROM admin_logs
             WHERE entity_type IN (?, ?, ?) OR description LIKE ?
             ORDER BY id DESC LIMIT 30',
            ['user', 'user_password', 'user_sessions', '%"' . str_replace(['%', '_'], ['\%', '\_'], (string) $user['email']) . '"%']
        )->fetchAll();

        $logins = Database::run(
            'SELECT attempted_at AS at, ip FROM login_attempts
             WHERE email = ? ORDER BY id DESC LIMIT 15',
            [$user['email']]
        )->fetchAll();

        $notes = Database::run(
            'SELECT n.id, n.body, n.created_at, a.email AS author
             FROM user_notes n LEFT JOIN users a ON a.id = n.author_id
             WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 50',
            [$id]
        )->fetchAll();

        return [$subscriptions, $activity, $logins, $notes];
    }

    /**
     * Append an internal note to the account. Notes are admin-only context
     * (chargeback history, support threads) and never shown to the member.
     */
    public function addNote(int $id): void
    {
        $body = trim((string) $this->request->post('body', ''));

        if ($body !== '') {
            Database::run(
                'INSERT INTO user_notes (user_id, author_id, body, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
                [$id, Auth::user()['id'] ?? null, mb_substr($body, 0, 5000)]
            );
            AuditLog::record(Auth::user()['id'] ?? null, 'create', 'user_note', $id,
                'Added internal note to user #' . $id);
            $this->flash('success', 'Note added.');
        }

        $this->redirect('/admin/users/' . $id);
    }

    /**
     * Set or clear the account flag (chargeback, vip, watch, ...). Free-form
     * but the UI offers presets; flagged accounts are filterable in the list.
     */
    public function setFlag(int $id): void
    {
        $flag = trim((string) $this->request->post('flag', ''));
        $flag = mb_substr($flag, 0, 32);

        Database::run('UPDATE users SET flag = ? WHERE id = ?', [$flag !== '' ? $flag : null, $id]);
        AuditLog::record(Auth::user()['id'] ?? null, 'update', 'user_flag', $id,
            'Flag for user #' . $id . ' set to: ' . ($flag !== '' ? $flag : '(cleared)'));

        $this->flash('success', $flag !== '' ? 'Flag set.' : 'Flag cleared.');
        $this->redirect('/admin/users/' . $id);
    }

    /**
     * Update a user's role from the inline dropdown on the users list.
     */
    public function updateRole(int $id): void
    {
        $role = (string) $this->request->post('role', '');
        $allowed = ['user', 'editor', 'moderator', 'viewer'];
        if (Auth::isAdmin()) $allowed[] = 'admin';
        if (Auth::can('manage_roles')) $allowed[] = 'super_admin';

        if (!in_array($role, $allowed, true)) {
            $this->flash('error', 'Invalid role.');
            $this->redirect('/admin/users');
        }

        $user = User::find($id);

        if ($user === null) {
            $this->flash('error', 'User not found.');
            $this->redirect('/admin/users');
        }

        Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        AuditLog::record(
            (int) (Auth::user()['id'] ?? 0),
            'update',
            'user',
            $id,
            'Role for "' . $user['email'] . '" changed to ' . $role,
            ['role' => $user['role']],
            ['role' => $role]
        );

        $this->redirect('/admin/users');
    }

    /**
     * Full account profile for admins: identity, membership history,
     * galleries, and every audit-log mention -- plus the account-control
     * quick actions (suspend, password reset, log out everywhere).
     */
    public function show(int $id): void
    {
        Auth::requirePermission('users');

        $user = User::find($id);

        if ($user === null) {
            $this->flash('error', 'User not found.');
            $this->redirect('/admin/users');
        }

        [$subscriptions, $activity, $logins, $notes] = $this->userContext($id);

        $lifetimeRevenue = User::lifetimeRevenue($id);

        $this->viewAdmin('user_show', [
            'user'            => $user,
            'subscriptions'   => $subscriptions,
            'activity'        => $activity,
            'logins'          => $logins,
            'notes'           => $notes,
            'lifetimeRevenue' => $lifetimeRevenue,
        ]);
    }

    /**
     * Suspend or reactivate an account. Suspended users are logged out on
     * their next request and cannot sign in again until reactivated.
     */
    public function setStatus(int $id): void
    {
        Auth::requirePermission('users');

        $status = (string) $this->request->post('status', '');
        $target = User::find($id);

        if (!in_array($status, ['active', 'suspended'], true) || $target === null) {
            $this->flash('error', 'Invalid status change.');
            $this->redirect('/admin/users');
        }

        $me = Auth::user()['id'] ?? 0;

        if ($id === (int) $me) {
            $this->flash('error', 'You cannot suspend your own account.');
            $this->redirect('/admin/users/' . $id);
        }

        Database::run('UPDATE users SET status = ?, session_version = session_version + 1 WHERE id = ?', [$status, $id]);

        AuditLog::record((int) $me, 'update', 'user', $id,
            ($status === 'suspended' ? 'Suspended' : 'Reactivated') . ' account "' . $target['email'] . '"',
            ['status' => $target['status'] ?? null], ['status' => $status]);

        $this->flash('success', 'Account ' . ($status === 'suspended' ? 'suspended.' : 'reactivated.'));
        $this->redirect($this->request->post('return_to') ?: '/admin/users');
    }

    /**
     * Generate a fresh temporary password for an account. The value is shown
     * exactly once here; the user is advised to change it after signing in.
     */
    public function resetPassword(int $id): void
    {
        Auth::requirePermission('users');

        $target = User::find($id);

        if ($target === null) {
            $this->notFound();
            return;
        }

        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $temp = '';
        for ($i = 0; $i < 12; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        User::updatePassword($id, password_hash($temp, PASSWORD_DEFAULT));
        Database::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);

        AuditLog::record((int) (Auth::user()['id'] ?? 0), 'update', 'user_password', $id,
            'Reset password for "' . $target['email'] . '"');

        $lifetimeRevenue = User::lifetimeRevenue($id);

        $this->viewAdmin('user_show', [
            'user'            => $target,
            'subscriptions'   => [],
            'activity'        => [],
            'logins'          => [],
            'notes'           => [],
            'tempPassword'    => $temp,
            'lifetimeRevenue' => $lifetimeRevenue,
        ]);
    }

    /**
     * Bump the account's session version so every signed-in device is
     * logged out on its next request.
     */
    public function logoutEverywhere(int $id): void
    {
        Auth::requirePermission('users');

        $target = User::find($id);

        if ($target === null) {
            $this->notFound();
            return;
        }

        Database::run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
        AuditLog::record((int) (Auth::user()['id'] ?? 0), 'delete', 'user_sessions', $id,
            'Logged out all devices for "' . $target['email'] . '"');

        $this->flash('success', 'All devices for that account will be signed out.');
        $this->redirect('/admin/users/' . $id);
    }

    /**
     * Show the create-user form.
     */
    public function create(): void
    {
        $this->viewAdmin('user_create', [
            'plans' => Plan::active(),
        ]);
    }

    /**
     * Create an admin-created account (used for the first admin and any
     * additional staff accounts), validating email, password and duplicates.
     * Used by both the create form and the legacy inline form.
     */
    public function store(): void
    {
        $email    = trim($this->request->input('email'));
        $password = (string) $this->request->post('password', '');
        $role     = $this->requestedRole();
        $dob      = $this->request->input('date_of_birth') ?: null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/admin/users/create');
        }

        if (strlen($password) < 8) {
            $this->flash('error', 'Password must be at least 8 characters.');
            $this->redirect('/admin/users/create');
        }

        if (User::findByEmail($email) !== null) {
            $this->flash('error', 'A user with that email already exists.');
            $this->redirect('/admin/users/create');
        }

        User::create($email, $password, $role, $dob);
        $userId = (int) Database::connection()->lastInsertId();

        $bFn   = $this->request->input('billing_first_name');
        $bLn   = $this->request->input('billing_last_name');
        $bA1   = $this->request->input('billing_address_line1');
        $bA2   = $this->request->input('billing_address_line2');
        $bCity = $this->request->input('billing_city');
        $bSt   = $this->request->input('billing_state');
        $bZip  = $this->request->input('billing_zip');
        $bCo   = $this->request->input('billing_country');

        if ($bFn || $bLn || $bA1 || $bCity) {
            User::updateProfile($userId, $email, $role, $dob, false, $bFn, $bLn, $bA1, $bA2, $bCity, $bSt, $bZip, $bCo);
        }

        AuditLog::record((int) Auth::user()['id'], 'create', 'user', $userId, 'Created account "' . $email . '"', null, ['email' => $email, 'role' => $role]);

        $planId = (int) $this->request->input('plan_id');
        if ($planId > 0) {
            Subscription::create($userId, $planId);
            $subId = (int) Database::connection()->lastInsertId();
            Subscription::approve($subId);
        }

        $this->flash('success', 'User "' . $email . '" created.');
        $this->redirect('/admin/users');
    }

    /**
     * Delete a user, protecting against removing your own account or the
     * last remaining admin.
     */
    public function destroy(int $id): void
    {
        $user = User::find($id);

        if ($user === null) {
            $this->notFound();
            return;
        }

        if ($id === (int) $_SESSION['user_id']) {
            $this->flash('error', 'You cannot delete your own account.');
            $this->redirect('/admin/users');
        }

        if (in_array($user['role'], Auth::ADMIN_ROLES, true) && User::countAdmins() <= 1) {
            $this->flash('error', 'You cannot delete the last admin account.');
            $this->redirect('/admin/users');
        }

        User::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'user', $id, 'Deleted account "' . $user['email'] . '"', ['email' => $user['email'], 'role' => $user['role']]);

        $this->flash('success', 'User "' . $user['email'] . '" deleted.');
        $this->redirect('/admin/users');
    }

    /**
     * Show the edit form for a user.
     */
    public function edit(int $id): void
    {
        $user = User::find($id);

        if ($user === null) {
            $this->notFound();
            return;
        }

        $currentSub = Subscription::activeFor($id);

        $this->viewAdmin('user_edit', [
            'user'       => $user,
            'plans'      => Plan::active(),
            'currentSub' => $currentSub,
        ]);
    }

    /**
     * Update a user's email, role, billing and age-verification data.
     * Optionally reset the password.
     */
    public function update(int $id): void
    {
        $user = User::find($id);

        if ($user === null) {
            $this->notFound();
            return;
        }

        $email    = trim($this->request->input('email'));
        $role     = $this->requestedRole();
        $password = (string) $this->request->post('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'A valid email address is required.');
            $this->redirect('/admin/users/' . $id . '/edit');
        }

        $existing = User::findByEmail($email);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            $this->flash('error', 'A user with that email already exists.');
            $this->redirect('/admin/users/' . $id . '/edit');
        }

        if ($password !== '') {
            if (strlen($password) < 8) {
                $this->flash('error', 'Password must be at least 8 characters.');
                $this->redirect('/admin/users/' . $id . '/edit');
            }
            User::updatePassword($id, password_hash($password, PASSWORD_DEFAULT));
        }

        $dob   = $this->request->input('date_of_birth') ?: null;
        $av    = (bool) $this->request->input('age_verified');
        $bFn   = $this->request->input('billing_first_name');
        $bLn   = $this->request->input('billing_last_name');
        $bA1   = $this->request->input('billing_address_line1');
        $bA2   = $this->request->input('billing_address_line2');
        $bCity = $this->request->input('billing_city');
        $bSt   = $this->request->input('billing_state');
        $bZip  = $this->request->input('billing_zip');
        $bCo   = $this->request->input('billing_country');

        User::updateProfile($id, $email, $role, $dob, $av, $bFn, $bLn, $bA1, $bA2, $bCity, $bSt, $bZip, $bCo);

        $planId    = (int) $this->request->input('plan_id');
        $currentSub = Subscription::activeFor($id);
        $currentPlanId = $currentSub ? (int) $currentSub['plan_id'] : 0;

        if ($planId !== $currentPlanId) {
            if ($currentSub) {
                Subscription::cancel((int) $currentSub['id']);
            }
            if ($planId > 0) {
                Subscription::create($id, $planId);
                $newSubId = (int) Database::connection()->lastInsertId();
                Subscription::approve($newSubId);
            }
        }

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'user',
            $id,
            'Updated account "' . $email . '"',
            ['email' => $user['email'], 'role' => $user['role']],
            ['email' => $email, 'role' => $role, 'age_verified' => $av]
        );

        $this->flash('success', 'User "' . $email . '" updated.');
        $this->redirect('/admin/users');
    }
}
