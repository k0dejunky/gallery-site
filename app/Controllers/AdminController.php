<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Stats;

class AdminController extends Controller
{
    public function dashboard(): void
    {
        if (!Auth::isAdmin()) {
            $this->viewStandalone('admin/login');
            return;
        }

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

        $diskFreeGb = null;
        $free = @disk_free_space($root);
        if ($free !== false) {
            $diskFreeGb = round($free / 1073741824, 1);
        }

        $storagePeriod = (string) ($_GET['period'] ?? 'week');
        if (!in_array($storagePeriod, ['day', 'week', 'month', 'year', 'all'], true)) {
            $storagePeriod = 'week';
        }

        $this->viewAdmin('dashboard', [
            'summary'   => Stats::summary(),
            'growth'    => Stats::growth(),
            'finance'   => \App\Models\Stats::finance(),
            'feed'      => \App\Models\Stats::feed(),
            'storageTrend' => \App\Models\Stats::storageTrend($storagePeriod),
            'storagePeriod' => $storagePeriod,
            'security'  => $security,
            'backupFailure' => $backupFailure,
            'cronAgeMin' => $cronAge,
            'diskFreeGb' => $diskFreeGb,
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
            'activeEditJob' => \App\Models\PhotoJob::latestForGallery($id),
        ]);
    }

    /**
     * Admin: show staged uploads that were abandoned before their gallery
     * was created. These are files still sitting in session staging dirs.
     */
    public function abandonedUploads(): void
    {
        Auth::requirePermission('dashboard');

        $this->viewAdmin('abandoned', [
            'uploads' => Photo::abandonedPending(),
            'galleries' => Gallery::all(),
        ]);
    }

    /**
     * Admin: serve a staged file (original, web or thumb) from an abandoned
     * session's pending directory so admins can preview before assigning.
     */
    public function abandonedFile(string $session, string $file): void
    {
        Auth::requirePermission('dashboard');

        $session = basename($session);
        $file    = basename($file);
        $size    = (string) $this->request->query('size', '');

        if ($session === '' || !preg_match('/^[A-Za-z0-9_,-]+$/', $session)
            || !preg_match('/^pending_[A-Za-z0-9_.-]+\.[A-Za-z0-9]+$/', $file)) {
            $this->notFound();
            return;
        }

        $name = $file;
        if ($size === 'thumb') {
            $name = 'thumb_' . $file;
        } elseif ($size === 'web') {
            $name = 'web_' . $file;
        }

        $path = config('app.uploads.dir') . '/pending/' . $session . '/' . $name;

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime      = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=3600');
        readfile($path);
        exit;
    }

    /**
     * Admin: resume gallery creation from bulk-selected abandoned uploads.
     * The chosen staged files are moved into the current session's pending
     * staging area with their metadata restored, then the admin is sent to
     * the normal create-gallery page to finish as if nothing was abandoned.
     */
    public function resumeAbandoned(): void
    {
        Auth::requirePermission('galleries');

        $selected = $this->request->post('files', []);
        $selected = is_array($selected) ? $selected : [];

        if ($selected === []) {
            $this->flash('error', 'Select at least one upload to resume.');
            $this->redirect('/admin/abandoned-uploads');
        }

        $config = config('app.uploads');
        $dir    = $config['dir'] . '/pending/' . session_id();

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $list  = $_SESSION['pending_gallery_files'] ?? [];
        $added = 0;

        foreach ($selected as $key) {
            $key = (string) $key;
            [$session, $file] = array_pad(explode('|', $key, 2), 2, '');

            if ($session === '' || !preg_match('/^[A-Za-z0-9_,-]+$/', $session)
                || !preg_match('/^pending_[A-Za-z0-9_.-]+\.[A-Za-z0-9]+$/', $file)) {
                continue;
            }

            $source = $config['dir'] . '/pending/' . $session . '/' . $file;

            if (!is_file($source)) {
                continue;
            }

            $dest = $dir . '/' . $file;

            if (!rename($source, $dest)) {
                continue;
            }

            foreach (['thumb_', 'web_'] as $prefix) {
                $variant = $config['dir'] . '/pending/' . $session . '/' . $prefix . $file;

                if (is_file($variant)) {
                    rename($variant, $dir . '/' . $prefix . $file);
                }
            }

            $isImage = !is_video($file);

            $list[] = [
                'filename' => $file,
                'original' => $file,
                'hash'     => sha1_file($dest),
                'is_image' => $isImage,
            ];
            $added++;

            // Remove the abandoned session dir once it is empty.
            $sessionDir = $config['dir'] . '/pending/' . $session;
            if (is_dir($sessionDir) && count(glob($sessionDir . '/*') ?: []) === 0) {
                @rmdir($sessionDir);
            }
        }

        if ($added === 0) {
            $this->flash('error', 'None of the selected uploads could be resumed.');
            $this->redirect('/admin/abandoned-uploads');
        }

        $_SESSION['pending_gallery_files'] = $list;

        $this->flash('success', $added . ' upload(s) staged. Finish creating the gallery below.');
        $this->redirect('/admin/galleries/create?resume=1');
    }

    /**
     * Admin: assign one abandoned staged upload to a compatible gallery.
     * The file is moved out of the pending staging dir into the uploads dir,
     * deduplicated by content hash, and attached to the chosen gallery.
     */
    public function assignAbandoned(string $session, string $file): void
    {
        Auth::requirePermission('dashboard');

        $session   = basename($session);
        $file      = basename($file);
        $galleryId = (int) $this->request->post('gallery_id', 0);
        $gallery   = $galleryId > 0 ? Gallery::find($galleryId) : null;

        $source = config('app.uploads.dir') . '/pending/' . $session . '/' . $file;

        if ($gallery === null) {
            $this->flash('error', 'Select a valid gallery.');
            $this->redirect('/admin/abandoned-uploads');
        }

        if ($session === '' || !preg_match('/^[A-Za-z0-9_,-]+$/', $session)
            || !preg_match('/^pending_[A-Za-z0-9_.-]+\.[A-Za-z0-9]+$/', $file)
            || !is_file($source)) {
            $this->flash('error', 'That upload is no longer available.');
            $this->redirect('/admin/abandoned-uploads');
        }

        $galleryIsVideo = ($gallery['type'] ?? 'images') === 'videos';

        if (is_video($file) !== $galleryIsVideo) {
            $this->flash('error', 'The upload type does not match that gallery.');
            $this->redirect('/admin/abandoned-uploads');
        }

        $config  = config('app.uploads');
        $hash    = sha1_file($source);
        $existing = Photo::findByHash($hash);

        if ($existing !== null) {
            Gallery::attachPhoto($galleryId, (int) $existing['id']);
        } else {
            $dest = $config['dir'] . '/' . $file;

            if (!rename($source, $dest)) {
                $this->flash('error', 'Could not move the upload.');
                $this->redirect('/admin/abandoned-uploads');
            }

            foreach (['thumb_', 'web_'] as $prefix) {
                $variant = config('app.uploads.dir') . '/pending/' . $session . '/' . $prefix . $file;

                if (is_file($variant)) {
                    rename($variant, $config['dir'] . '/' . $prefix . $file);
                }
            }

            $photoId = Photo::create($file, $hash);
            Gallery::attachPhoto($galleryId, $photoId);
        }

        $dir = config('app.uploads.dir') . '/pending/' . $session;

        foreach (glob($dir . '/*') ?: [] as $leftover) {
            if (is_file($leftover)) {
                @unlink($leftover);
            }
        }

        @rmdir($dir);

        $this->flash('success', 'Upload assigned to "' . $gallery['title'] . '".');
        $this->redirect('/admin/abandoned-uploads');
    }
}
