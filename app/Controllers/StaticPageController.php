<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Gallery;

/**
 * Public, static legal pages (Terms of Service, Privacy Policy). These are
 * reached by guests and members alike, so no authentication is required.
 */
class StaticPageController extends Controller
{
    /**
     * Render the About page.
     */
    public function about(): void
    {
        $this->view('about', [
            'title'            => 'About Us',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => 'support@' . (string) config('app.site_name') . '.com',
        ]);
    }

    /**
     * Render the Terms of Service page.
     */
    public function terms(): void
    {
        $this->view('terms', [
            'title'            => 'Terms of Service',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => 'support@' . (string) config('app.site_name') . '.com',
            'lastUpdated'      => 'September 10, 2026',
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

    /**
     * Serve an XML sitemap listing the public, crawlable pages. Private /
     * member-only areas are intentionally excluded, and gallery detail pages
     * require a membership so only the listing pages are published.
     */
    public function sitemap(): void
    {
        $base = rtrim((string) env_value('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base   = $scheme . '://' . $host . rtrim((string) config('app.base_path'), '/');
        }

        $urls = ['/', '/about', '/terms', '/privacy', '/membership', '/galleries'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $path) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . '/' . ltrim($path, '/'), ENT_XML1, 'UTF-8') . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
    }
}
