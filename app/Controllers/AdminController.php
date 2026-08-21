<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Stats;

class AdminController extends Controller
{
    public function dashboard(): void
    {
        if (!Auth::isAdmin()) {
            $this->viewStandalone('admin/login');
            return;
        }

        $page = (int) $this->request->query('page', 1);

        $this->viewAdmin('dashboard', [
            'paginator' => Gallery::paginate($page, 10),
            'summary'   => Stats::summary(),
        ]);
    }

    public function login(): void
    {
        $email    = $this->request->input('email');
        $password = (string) $this->request->post('password', '');
        $result   = Auth::attempt($email, $password, $this->request->ip());

        if ($result !== true) {
            $this->flash('error', is_string($result) ? $result : 'Invalid email or password.');
            $this->redirect('/admin');
        }

        if (!Auth::isAdmin()) {
            $this->flash('error', 'This account does not have admin access.');
            $this->redirect('/galleries');
        }

        $this->flash('success', 'Welcome back, admin!');
        $this->redirect('/admin');
    }

    public function manageGallery(int $id): void
    {
        Auth::requirePermission('dashboard');
        $gallery = Gallery::find($id);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $this->viewAdmin('manage', [
            'gallery'   => $gallery,
            'photos'    => Gallery::photos($id),
            'categories' => Category::all(),
            'assigned'  => array_map(
                static fn (array $category): int => (int) $category['id'],
                Gallery::categories($id)
            ),
        ]);
    }
}
