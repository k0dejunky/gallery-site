<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Category;

class CategoryController extends Controller
{
    /**
     * All category actions are admin-only, enforced once in the constructor.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('categories');
    }

    /**
     * Admin: category management page. A ?q= query narrows the list to
     * matching categories; an optional editing category (via the Edit link)
     * replaces the "Add" form with a pre-filled "Edit" form.
     */
    public function index(): void
    {
        $q             = trim((string) $this->request->query('q', ''));
        $editCategory  = null;
        $editingId     = (int) $this->request->query('edit', 0);

        if ($editingId > 0) {
            $editCategory = Category::find($editingId);
        }

        $this->viewAdmin('categories', [
            'categories'    => Category::all($q),
            'q'             => $q,
            'editCategory'  => $editCategory,
        ]);
    }

    /**
     * Create a category, rejecting blank or duplicate names.
     */
    public function store(): void
    {
        $name = trim($this->request->input('name'));

        if ($name === '') {
            $this->flash('error', 'Category name is required.');
            $this->redirect('/admin/categories');
        }

        if (Category::findByName($name) !== null) {
            $this->flash('error', 'A category with that name already exists.');
            $this->redirect('/admin/categories');
        }

        try {
            Category::create($name);
        } catch (\PDOException $e) {
            $this->flash('error', 'A category with that name already exists.');
            $this->redirect('/admin/categories');
        }

        $categoryId = (int) Database::connection()->lastInsertId();
        AuditLog::record((int) Auth::user()['id'], 'create', 'category', $categoryId, 'Created category "' . $name . '"', null, ['name' => $name]);

        $this->flash('success', 'Category "' . $name . '" created.');
        $this->redirect('/admin/categories');
    }

    /**
     * Show the edit form for one category (replaces the Add form on the
     * management page). The query string preserves any active search so the
     * list stays filtered after the form posts.
     */
    public function edit(int $id): void
    {
        $category = Category::find($id);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $q = trim((string) $this->request->query('q', ''));

        $this->viewAdmin('categories', [
            'categories'   => Category::all($q),
            'q'            => $q,
            'editCategory' => $category,
        ]);
    }

    /**
     * Rename a category, rejecting blank or duplicate names.
     */
    public function update(int $id): void
    {
        $category = Category::find($id);

        if ($category === null) {
            $this->notFound();
            return;
        }

        $name = trim($this->request->input('name'));

        if ($name === '') {
            $this->flash('error', 'Category name is required.');
            $this->redirect('/admin/categories/' . $id . '/edit');
        }

        $existing = Category::findByName($name);

        if ($existing !== null && (int) $existing['id'] !== $id) {
            $this->flash('error', 'A category with that name already exists.');
            $this->redirect('/admin/categories/' . $id . '/edit');
        }

        try {
            Category::update($id, $name);
        } catch (\PDOException $e) {
            $this->flash('error', 'A category with that name already exists.');
            $this->redirect('/admin/categories/' . $id . '/edit');
        }

        if ($category['name'] !== $name) {
            AuditLog::record((int) Auth::user()['id'], 'update', 'category', $id, 'Renamed category', ['name' => $category['name']], ['name' => $name]);
        }

        $this->flash('success', 'Category renamed to "' . $name . '".');
        $this->redirect('/admin/categories');
    }

    /**
     * Delete a category.
     */
    public function destroy(int $id): void
    {
        $category = Category::find($id);

        if ($category === null) {
            $this->notFound();
            return;
        }

        Category::delete($id);
        AuditLog::record((int) Auth::user()['id'], 'delete', 'category', $id, 'Deleted category "' . $category['name'] . '"', ['name' => $category['name']]);

        $this->flash('success', 'Category "' . $category['name'] . '" deleted.');
        $this->redirect('/admin/categories');
    }
}
