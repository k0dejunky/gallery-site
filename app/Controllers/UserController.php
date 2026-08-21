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
     * Admin: user accounts list with optional email search.
     */
    public function index(): void
    {
        $search = trim($this->request->input('q') ?? '');

        $users = $search !== ''
            ? User::search($search)
            : User::all();

        $this->viewAdmin('users', [
            'users'  => $users,
            'search' => $search,
        ]);
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
