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
     * A representative image (a recent photo's web variant) for social / OG
     * cards on the static pages. Falls back to the gallery cover or empty.
     */
    private function staticOgImage(): string
    {
        $recent = \App\Models\Photo::recentImages(1);
        if ($recent !== [] && !empty($recent[0]['filename'])) {
            return file_url($recent[0]['filename'], 'web');
        }
        $first = Gallery::firstPhotos([1]);
        $photo = reset($first);
        return is_array($photo) && !empty($photo['filename'])
            ? file_url($photo['filename'], 'web')
            : '';
    }

    /**
     * Render the About page.
     */
    public function about(): void
    {
        $this->view('about', [
            'title'            => 'About Us',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => 'support@' . (string) config('app.site_name') . '.com',
            'metaDescription'  => 'About ' . config('app.site_name') . ' — a personal collection of original photos and videos, updated regularly.',
            'canonicalUrl'     => absolute_url('/about'),
            'ogImage'          => $this->staticOgImage(),
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
            'metaDescription'  => 'The terms of service for ' . config('app.site_name') . ' — the rules for browsing, membership and the content on the site.',
            'canonicalUrl'     => absolute_url('/terms'),
            'ogImage'          => $this->staticOgImage(),
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
            'metaDescription'  => 'How ' . config('app.site_name') . ' collects, uses and protects your personal information.',
            'canonicalUrl'     => absolute_url('/privacy'),
            'ogImage'          => $this->staticOgImage(),
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

        // Static pages render 200 for guests; content pages now serve a
        // public, indexable blurred preview too, so they belong in the map.
        $urls = ['/', '/about', '/terms', '/privacy', '/membership'];

        $rows = \App\Core\Database::run('SELECT slug FROM categories ORDER BY name')->fetchAll();
        foreach ($rows as $row) {
            $urls[] = '/galleries/category/' . rawurlencode((string) $row['slug']);
        }

        $galleries = \App\Core\Database::run(
            'SELECT id FROM galleries WHERE deleted_at IS NULL AND is_secret = 0 AND '
            . Gallery::publishedVisibleSql('galleries')
        )->fetchAll();
        foreach ($galleries as $g) {
            $urls[] = '/galleries/' . (int) $g['id'];
        }

        $media = \App\Core\Database::run(
            'SELECT DISTINCT p.id, p.is_video
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE g.deleted_at IS NULL AND g.is_secret = 0 AND '
            . Gallery::publishedVisibleSql('g')
        )->fetchAll();
        foreach ($media as $m) {
            $urls[] = ((int) ($m['is_video'] ?? 0) === 1 ? '/videos/' : '/images/') . (int) $m['id'];
        }

        // Stay comfortably under Google's 50k URLs / 50MB sitemap limits.
        $urls = array_slice($urls, 0, 45000);

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
