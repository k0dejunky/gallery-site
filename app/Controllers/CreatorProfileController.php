<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\SiteProfile;

/**
 * Creator profile: a public /creator page plus the admin editor for it.
 * Values persist to storage/site_profile.json (no DB tables involved).
 */
class CreatorProfileController extends Controller
{
    public function show(): void
    {
        $this->view('creator/profile', [
            'profile' => SiteProfile::all(),
        ]);
    }

    public function edit(): void
    {
        Auth::requirePermission('dashboard');

        $this->viewAdmin('creator_profile', [
            'profile' => SiteProfile::all(),
        ]);
    }

    public function save(): void
    {
        Auth::requirePermission('dashboard');
        $user = Auth::user();

        $labelUrls = $this->request->post('link_label', []);
        $labelUrls = is_array($labelUrls) ? $labelUrls : [];

        $linkUrls = $this->request->post('link_url', []);
        $linkUrls = is_array($linkUrls) ? $linkUrls : [];

        $links = [];
        foreach ($labelUrls as $i => $label) {
            $label = trim((string) $label);
            $url   = trim((string) ($linkUrls[$i] ?? ''));
            if ($label !== '' && $url !== '') {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        SiteProfile::update(
            $this->request->input('display_name', 'Amethyst'),
            $this->request->input('tagline'),
            $this->request->input('bio'),
            $this->request->input('avatar'),
            $links
        );

        AuditLog::record(
            (int) $user['id'],
            'update',
            'site_profile',
            0,
            'Updated public creator profile'
        );

        $this->flash('success', 'Creator profile saved.');
        $this->redirect('/admin/profile');
    }
}