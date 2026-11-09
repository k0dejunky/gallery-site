<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\PermissionMap;
use App\Models\User;

/**
 * Access assignments: which parts of the admin panel each role may reach, and
 * per-account overrides on top of that.
 *
 * Both surfaces are gated on `manage_roles`, which is one of the permissions
 * that only the super admin can hold - so nobody can hand themselves more
 * access than they already have. Every write is audited, because changing who
 * can reach the money pages or the user list is exactly the kind of change an
 * audit trail exists for.
 */
class PermissionController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('manage_roles');
    }

    /**
     * The role matrix: permissions down the side, roles across the top.
     */
    public function index(): void
    {
        $roles = ['admin', 'editor', 'moderator', 'viewer'];

        $effective = [];
        foreach ($roles as $role) {
            $effective[$role] = PermissionMap::effectiveRole($role);
        }

        $this->viewAdmin('permissions', [
            'title'      => 'Access & roles',
            'roles'      => $roles,
            'catalog'    => PermissionMap::CATALOG,
            'effective'  => $effective,
            'customised' => array_filter(
                $roles,
                static fn (string $role): bool => PermissionMap::isCustomised($role)
            ),
            'adminOnly'  => PermissionMap::ADMIN_ONLY,
        ]);
    }

    /**
     * Save the matrix.
     *
     * Each editable permission posts an explicit 0 or 1 (a hidden 0 followed
     * by the checkbox 1, so PHP keeps the ticked value). That makes a role the
     * form rendered always present in the request, even when every box for it
     * was cleared - whereas a role ABSENT from `state` was never part of this
     * request and is left untouched. Reading checkbox presence instead would
     * make those two cases identical, and a partial request would revoke a
     * role's entire access in one step.
     *
     * The values become a delta against the Auth::PERMISSIONS default inside
     * PermissionMap::saveRole().
     */
    public function save(): void
    {
        $state = $this->request->post('state');
        if (!is_array($state)) {
            $state = [];
        }

        $covered = [];
        foreach ($state as $role => $permissions) {
            if (is_array($permissions)
                && in_array((string) $role, ['admin', 'editor', 'moderator', 'viewer'], true)) {
                $covered[] = (string) $role;
            }
        }

        if ($covered === []) {
            $this->flash('error', 'No roles were submitted, so nothing changed.');
            $this->redirect('/admin/roles');
            return;
        }

        $changed = [];
        foreach ($covered as $role) {
            $desired = [];
            foreach ((array) $state[$role] as $permission => $value) {
                if ((string) $value === '1') {
                    $desired[] = (string) $permission;
                }
            }

            $before = PermissionMap::effectiveRole($role);
            PermissionMap::saveRole($role, $desired);
            $after = PermissionMap::effectiveRole($role);

            if ($before !== $after) {
                $changed[$role] = ['before' => $before, 'after' => $after];
            }
        }

        if ($changed !== []) {
            AuditLog::record(
                (int) (Auth::user()['id'] ?? 0),
                'update',
                'role_permissions',
                null,
                'Updated admin access for: ' . implode(', ', array_keys($changed)),
                null,
                $changed
            );
        }

        $this->flash('success', $changed === []
            ? 'No changes - every role already matched what was posted.'
            : 'Access updated for ' . implode(', ', array_keys($changed)) . '.');
        $this->redirect('/admin/roles');
    }

    /** Put one role back to its built-in default. */
    public function reset(): void
    {
        $role = (string) $this->request->post('role', '');
        if (!in_array($role, ['admin', 'editor', 'moderator', 'viewer'], true)) {
            $this->flash('error', 'Unknown role.');
            $this->redirect('/admin/roles');
            return;
        }

        $before = PermissionMap::effectiveRole($role);
        PermissionMap::resetRole($role);
        $after = PermissionMap::effectiveRole($role);

        AuditLog::record(
            (int) (Auth::user()['id'] ?? 0),
            'update',
            'role_permissions',
            null,
            'Reset the ' . $role . ' role to its default access',
            ['permissions' => $before],
            ['permissions' => $after]
        );

        $this->flash('success', 'The ' . $role . ' role is back to its default access.');
        $this->redirect('/admin/roles');
    }

    /**
     * Per-account overrides for one admin-role account.
     *
     * A plain member has no admin access at all - Auth::can() short-circuits on
     * isAdmin() - so overrides there would be inert and are not offered.
     */
    public function userPermissions(int $id): void
    {
        $user = User::find($id);
        if ($user === null) {
            $this->notFound();
            return;
        }

        if (!Auth::isAdminRole($user)) {
            $this->flash('error', 'Access overrides only apply to accounts that already hold an admin role.');
            $this->redirect('/admin/users/' . $id);
            return;
        }

        $this->viewAdmin('permission_user', [
            'title'      => 'Access overrides',
            'target'     => $user,
            'catalog'    => PermissionMap::CATALOG,
            'roleSet'    => PermissionMap::effectiveRole((string) $user['role']),
            'overrides'  => $this->currentOverrides((int) $user['id']),
            'effective'  => PermissionMap::effectiveUser($user),
            'adminOnly'  => PermissionMap::ADMIN_ONLY,
        ]);
    }

    /**
     * Save one account's overrides. Every state is posted, so an untouched
     * form writes back exactly what it showed.
     */
    public function saveUserPermissions(int $id): void
    {
        $user = User::find($id);
        if ($user === null) {
            $this->notFound();
            return;
        }

        if (!Auth::isAdminRole($user)) {
            $this->flash('error', 'Access overrides only apply to accounts that already hold an admin role.');
            $this->redirect('/admin/users/' . $id);
            return;
        }

        $role   = (string) $user['role'];
        $posted = $this->request->post('state');
        $states = is_array($posted) ? $posted : [];

        $before = PermissionMap::effectiveUser($user);
        PermissionMap::saveUser($id, $role, $states);

        // Re-read: effectiveUser() memoises per user, and saveUser() has
        // already dropped this account's rows from the memo.
        $after = PermissionMap::effectiveUser(User::find($id) ?? $user);

        if ($before !== $after) {
            AuditLog::record(
                (int) (Auth::user()['id'] ?? 0),
                'update',
                'user_permissions',
                $id,
                'Changed access overrides for ' . $user['email'],
                ['permissions' => $before],
                ['permissions' => $after]
            );
        }

        $this->flash('success', 'Access overrides saved for ' . $user['email'] . '.');
        $this->redirect('/admin/users/' . $id . '/permissions');
    }

    /**
     * Clear every override on one account so it follows its role again.
     */
    public function resetUserPermissions(int $id): void
    {
        $user = User::find($id);
        if ($user === null) {
            $this->notFound();
            return;
        }

        if (!Auth::isAdminRole($user)) {
            $this->flash('error', 'Access overrides only apply to accounts that already hold an admin role.');
            $this->redirect('/admin/users/' . $id);
            return;
        }

        $before = PermissionMap::effectiveUser($user);
        PermissionMap::resetUser($id);
        $after = PermissionMap::effectiveUser(User::find($id) ?? $user);

        if ($before !== $after) {
            AuditLog::record(
                (int) (Auth::user()['id'] ?? 0),
                'update',
                'user_permissions',
                $id,
                'Cleared access overrides for ' . $user['email'],
                ['permissions' => $before],
                ['permissions' => $after]
            );
        }

        $this->flash('success', 'Overrides cleared for ' . $user['email'] . '.');
        $this->redirect('/admin/users/' . $id . '/permissions');
    }

    /**
     * The account's stored overrides as permission => '' | '1' | '0'.
     *
     * @return array<string, string>
     */
    private function currentOverrides(int $userId): array
    {
        $states = [];
        try {
            $rows = \App\Core\Database::run(
                'SELECT permission, granted FROM admin_user_permissions WHERE user_id = ?',
                [$userId]
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            return $states;
        }

        foreach ($rows as $row) {
            $states[(string) $row['permission']] = ((int) $row['granted']) === 1 ? '1' : '0';
        }

        return $states;
    }
}
