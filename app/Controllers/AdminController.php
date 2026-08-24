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

        // Operational alerts: failed background backup, silent cron, brute-
        // force spikes. Email versions go out throttled via Mailer.
        $backupFailure = \App\Core\Housekeeping::consumeBackupFailure();
        $security      = \App\Models\Stats::security();

        if ($security['fails_hour'] >= 25) {
            \App\Core\Mailer::adminAlert(
                'login-spike',
                'Login failure spike',
                sprintf("%d failed logins in the last hour. Review /admin/system for offending IPs.", $security['fails_hour']),
                1800
            );
        }

        $root = dirname(__DIR__, 2);
        $cronAge = null;

        if (is_file($root . '/storage/logs/cron.log')) {
            $cronAge = (int) round((time() - filemtime($root . '/storage/logs/cron.log')) / 60);
        }

        if ($cronAge !== null && $cronAge > 45) {
            \App\Core\Mailer::adminAlert(
                'cron-stale',
                'Housekeeping cron may be down',
                "Last housekeeping run was {$cronAge} minutes ago (expected every ~15).\nCheck /etc/cron.d/gallery-housekeeping on the server.",
                14400
            );
        }

        $storagePeriod = (string) ($_GET['period'] ?? 'week');
        if (!in_array($storagePeriod, ['day', 'week', 'month', 'year', 'all'], true)) {
            $storagePeriod = 'week';
        }

        $this->viewAdmin('dashboard', [
            'paginator' => Gallery::paginate($page, 10),
            'summary'   => Stats::summary(),
            'growth'    => Stats::growth(),
            'categories' => Category::all(),
            'finance'   => \App\Models\Stats::finance(),
            'feed'      => \App\Models\Stats::feed(),
            'storageTrend' => \App\Models\Stats::storageTrend($storagePeriod),
            'storagePeriod' => $storagePeriod,
            'security'  => $security,
            'backupFailure' => $backupFailure,
            'cronAgeMin' => $cronAge,
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
