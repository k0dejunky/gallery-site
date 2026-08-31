<?php

namespace App\Controllers;

use App\Core\Controller;

/**
 * Public, static legal pages (Terms of Service, Privacy Policy). These are
 * reached by guests and members alike, so no authentication is required.
 */
class StaticPageController extends Controller
{
    /**
     * Render the Terms of Service page.
     */
    public function terms(): void
    {
        $this->view('terms', [
            'title'            => 'Terms of Service',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => 'support@' . (string) config('app.site_name') . '.com',
            'lastUpdated'      => 'August 31, 2026',
        ]);
    }

    /**
     * Render the Privacy Policy page.
     */
    public function privacy(): void
    {
        $this->view('privacy', [
            'title'            => 'Privacy Policy',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => 'support@' . (string) config('app.site_name') . '.com',
            'lastUpdated'      => 'August 31, 2026',
        ]);
    }
}
