<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

/**
 * Global admin search: one query box that fans out over users, galleries,
 * photos and subscription references, returning the top hits of each kind
 * with deep links into their detail pages.
 */
class SearchController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('dashboard');

        $q     = trim((string) $this->request->query('q', ''));
        $like  = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $out   = [
            'q'       => $q,
            'users'   => [],
            'galleries' => [],
            'photos'  => [],
            'transactions' => [],
        ];

        if (mb_strlen($q) >= 2) {
            $out['users'] = Database::run(
                'SELECT id, email, role, status, flag FROM users
                 WHERE email LIKE ? OR CAST(id AS CHAR) = ?
                 ORDER BY id DESC LIMIT 8',
                [$like, $q]
            )->fetchAll();

            $out['galleries'] = Database::run(
                'SELECT id, title, created_at, deleted_at FROM galleries
                 WHERE title LIKE ? AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT 8',
                [$like]
            )->fetchAll();

            $out['photos'] = Database::run(
                'SELECT p.id, p.filename, g.id AS gallery_id FROM photos p
                 LEFT JOIN gallery_photo gp ON gp.photo_id = p.id
                 LEFT JOIN galleries g ON g.id = gp.gallery_id
                 WHERE p.filename LIKE ?
                 ORDER BY p.id DESC LIMIT 6',
                [$like]
            )->fetchAll();

            $out['transactions'] = Database::run(
                'SELECT s.id, s.transaction_ref, s.status, s.user_id, u.email
                 FROM subscriptions s LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.transaction_ref LIKE ? AND s.transaction_ref <> \'\'
                 ORDER BY s.id DESC LIMIT 8',
                [$like]
            )->fetchAll();
        }

        $this->viewAdmin('search', $out);
    }
}
