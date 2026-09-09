<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Traffic;

class TrafficController extends Controller
{
    /**
     * Traffic links are admin-only.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('traffic');
    }

    /**
     * Summary table: every link with its visits, unique visitors, signups and
     * signup rate, plus the create form and copy-ready share links.
     */
    public function index(): void
    {
        $this->viewAdmin('traffic', [
            'links' => Traffic::linksWithStats(),
        ]);
    }

    /**
     * Per-link detail: 30-day daily breakdown, attributed signups and the
     * most recent visits.
     */
    public function show(int $id): void
    {
        $stats = Traffic::linkStats($id);

        if ($stats === null) {
            $this->flash('error', 'That traffic link does not exist.');
            $this->redirect('/admin/traffic');
        }

        $this->viewAdmin('traffic_show', $stats);
    }

    /**
     * Create a new traffic link. A blank code is generated from the link name;
     * codes are validated and must be unique.
     */
    public function create(): void
    {
        $name = trim($this->request->input('name'));
        $code = trim($this->request->input('code'));
        $target = self::normalizeTarget((string) $this->request->input('target_path'));

        if ($code === '') {
            $code = Traffic::slugify($name);
        }

        if ($code === '') {
            $this->flash('error', 'A link name or code is required.');
            $this->redirect('/admin/traffic');
        }

        if (!Traffic::validCode($code)) {
            $this->flash('error', 'The code may only use lowercase letters, numbers, underscore and dashes.');
            $this->redirect('/admin/traffic');
        }

        if (Traffic::codeExists($code)) {
            $this->flash('error', 'A link with code "' . $code . '" already exists.');
            $this->redirect('/admin/traffic');
        }

        $id = Traffic::create($code, $name !== '' ? $name : $code, $target);

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'traffic_link',
            $id,
            'Created traffic link "' . $code . '"'
        );

        $this->flash('success', 'Traffic link "' . $code . '" created.');
        $this->redirect('/admin/traffic');
    }

    /**
     * Update a link's code/name/target. Re-checking uniqueness and keeping the
     * active flag untouched. A terminated link that is reactivated works again.
     */
    public function update(int $id): void
    {
        $link = Traffic::find($id);

        if ($link === null) {
            $this->flash('error', 'That traffic link does not exist.');
            $this->redirect('/admin/traffic');
        }

        $name = trim($this->request->input('name'));
        $code = trim($this->request->input('code'));
        $target = self::normalizeTarget((string) $this->request->input('target_path'));

        if ($code === '') {
            $code = Traffic::slugify($name);
        }

        if ($code === '') {
            $this->flash('error', 'A link name or code is required.');
            $this->redirect('/admin/traffic');
        }

        if (!Traffic::validCode($code)) {
            $this->flash('error', 'The code may only use lowercase letters, numbers, underscore and dashes.');
            $this->redirect('/admin/traffic');
        }

        if (Traffic::codeExists($code, $id)) {
            $this->flash('error', 'A link with code "' . $code . '" already exists.');
            $this->redirect('/admin/traffic');
        }

        Traffic::update($id, $code, $name !== '' ? $name : $code, $target);

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'traffic_link',
            $id,
            'Updated traffic link "' . $code . '"'
        );

        $this->flash('success', 'Traffic link "' . $code . '" updated.');
        $this->redirect('/admin/traffic');
    }

    /**
     * Activate/deactivate a link. Deactivating terminates the link: no further
     * visits are recorded and stored cookies stop being credited at signup.
     */
    public function toggle(int $id): void
    {
        $link = Traffic::find($id);

        if ($link === null) {
            $this->flash('error', 'That traffic link does not exist.');
            $this->redirect('/admin/traffic');
        }

        $active = empty($link['active']);
        Traffic::setActive($id, $active);

        AuditLog::record(
            (int) Auth::user()['id'],
            $active ? 'activate' : 'deactivate',
            'traffic_link',
            $id,
            $active
                ? 'Activated traffic link "' . $link['code'] . '"'
                : 'Deactivated (terminated) traffic link "' . $link['code'] . '"'
        );

        $this->flash('success', $active ? 'Link reactivated.' : 'Link terminated. It no longer records or credits traffic.');
        $this->redirect('/admin/traffic');
    }

    /**
     * Delete a link and its visit history (attributed signups keep their
     * source id, set to NULL by the FK).
     */
    public function delete(int $id): void
    {
        $link = Traffic::find($id);

        if ($link === null) {
            $this->flash('error', 'That traffic link does not exist.');
            $this->redirect('/admin/traffic');
        }

        Traffic::delete($id);

        AuditLog::record(
            (int) Auth::user()['id'],
            'delete',
            'traffic_link',
            $id,
            'Deleted traffic link "' . $link['code'] . '"'
        );

        $this->flash('success', 'Traffic link "' . $link['code'] . '" deleted.');
        $this->redirect('/admin/traffic');
    }

    /**
     * Normalize a target path to a leading-slash site path with no query
     * string. Defaults to /signup when blank or invalid.
     */
    private static function normalizeTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '/signup';
        }

        if (!str_starts_with($target, '/')) {
            $target = '/' . ltrim($target, '/');
        }

        $path = (string) preg_replace('/[^a-zA-Z0-9_\-.\/]/', '', (string) parse_url($target, PHP_URL_PATH));

        return $path === '' ? '/signup' : $path;
    }
}