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

    /**
     * Lightweight JSON suggestions for the admin command palette (Ctrl+K).
     * Returns named groups of admin pages plus galleries, users and plans
     * whose titles/emails match, each with a deep link.
     */
    public function suggest(): void
    {
        Auth::requirePermission('dashboard');

        header('Content-Type: application/json; charset=utf-8');

        $q = trim((string) $this->request->query('q', ''));

        if ($q === '') {
            echo json_encode(['q' => $q, 'groups' => []]);
            exit;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $groups = [];

        // Admin pages (static list, filtered by the query).
        $pages = [
            ['Dashboard', '/admin'],
            ['Abandoned Uploads', '/admin/abandoned-uploads'],
            ['Search', '/admin/search'],
            ['Trends', '/admin/trends'],
            ['Gallery Management', '/admin/galleries'],
            ['New Gallery', '/admin/galleries/create'],
            ['Video Projects', '/admin/video-projects'],
            ['Auto Poster', '/admin/auto-poster'],
            ['Categories', '/admin/categories'],
            ['Users', '/admin/users'],
            ['Membership (Plans)', '/admin/plans'],
            ['Subscriptions', '/admin/subscriptions'],
            ['Payments', '/admin/payment-processors'],
            ['Theme', '/admin/theme'],
            ['Site Editor', '/admin/site-editor'],
            ['Logs', '/admin/logs'],
            ['Error Logs', '/admin/error-logs'],
            ['System', '/admin/system'],
            ['Test suite', '/admin/test-suite'],
            ['Documentation', '/admin/help'],
            ['Support', '/admin/support'],
        ];
        $matched = [];
        foreach ($pages as [$label, $href]) {
            if (stripos($label, $q) !== false) {
                $matched[] = ['kind' => 'Page', 'title' => $label, 'href' => url($href)];
            }
        }
        if ($matched !== []) {
            $groups[] = ['label' => 'Pages', 'items' => $matched];
        }

        $galleries = Database::run(
            'SELECT id, title FROM galleries
             WHERE title LIKE ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 6',
            [$like]
        )->fetchAll();
        if ($galleries !== []) {
            $groups[] = ['label' => 'Galleries', 'items' => array_map(
                static fn (array $g) => [
                    'kind'  => 'Gallery',
                    'title' => (string) $g['title'],
                    'href'  => url('/admin/galleries/' . (int) $g['id']),
                ],
                $galleries
            )];
        }

        $users = Database::run(
            'SELECT id, email FROM users
             WHERE email LIKE ? OR CAST(id AS CHAR) = ?
             ORDER BY id DESC LIMIT 6',
            [$like, $q]
        )->fetchAll();
        if ($users !== []) {
            $groups[] = ['label' => 'Users', 'items' => array_map(
                static fn (array $u) => [
                    'kind'  => 'User',
                    'title' => (string) $u['email'],
                    'href'  => url('/admin/users/' . (int) $u['id']),
                ],
                $users
            )];
        }

        $plans = Database::run(
            'SELECT id, name, level FROM plans
             WHERE name LIKE ?
             ORDER BY id DESC LIMIT 6',
            [$like]
        )->fetchAll();
        if ($plans !== []) {
            $groups[] = ['label' => 'Plans', 'items' => array_map(
                static fn (array $p) => [
                    'kind'  => 'Plan',
                    'title' => (string) $p['name'] . ' (tier ' . (int) $p['level'] . ')',
                    'href'  => url('/admin/plans/' . (int) $p['id'] . '/edit'),
                ],
                $plans
            )];
        }

        echo json_encode(['q' => $q, 'groups' => $groups], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
