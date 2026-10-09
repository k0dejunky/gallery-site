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
     * A representative image (a recent photo's public thumb variant) for
     * social / OG cards on the static pages. Absolute and token-free so
     * link scrapers can fetch it. Falls back to the gallery cover or empty.
     */
    private function staticOgImage(): string
    {
        $recent = \App\Models\Photo::recentImages(1);
        if ($recent !== [] && !empty($recent[0]['filename'])) {
            return absolute_url(file_url($recent[0]['filename'], 'thumb'));
        }
        $first = Gallery::firstPhotos([1]);
        $photo = reset($first);
        return is_array($photo) && !empty($photo['filename'])
            ? absolute_url(file_url($photo['filename'], 'thumb'))
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
     * Render the 18 U.S.C. § 2257 record-keeping statement.
     */
    public function notice2257(): void
    {
        $this->view('2257', [
            'title'            => '18 U.S.C. § 2257 Record-Keeping Statement',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => $this->supportContact(),
            'lastUpdated'      => 'October 8, 2026',
            'metaDescription'  => 'The 18 U.S.C. § 2257 record-keeping statement for ' . config('app.site_name') . '.',
            'canonicalUrl'     => absolute_url('/2257'),
            'ogImage'          => $this->staticOgImage(),
        ]);
    }

    /**
     * Render the DMCA / copyright takedown policy.
     */
    public function dmca(): void
    {
        $this->view('dmca', [
            'title'            => 'DMCA Takedown Policy',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => $this->supportContact(),
            'lastUpdated'      => 'October 8, 2026',
            'metaDescription'  => 'How to request removal of infringing content from ' . config('app.site_name') . ' under the DMCA.',
            'canonicalUrl'     => absolute_url('/dmca'),
            'ogImage'          => $this->staticOgImage(),
        ]);
    }

    /**
     * Render the report-abuse / safety page.
     */
    public function reportAbuse(): void
    {
        $this->view('report-abuse', [
            'title'            => 'Report Abuse',
            'siteName'         => (string) config('app.site_name'),
            'supportEmail'     => $this->supportContact(),
            'lastUpdated'      => 'October 8, 2026',
            'metaDescription'  => 'How to report content on ' . config('app.site_name') . ' that you believe is abusive or violates our policies.',
            'canonicalUrl'     => absolute_url('/report-abuse'),
            'ogImage'          => $this->staticOgImage(),
        ]);
    }

    /**
     * The compliance contact used as the interim 2257 records custodian and
     * DMCA designated agent. Overridable per box via env.
     */
    private function supportContact(): string
    {
        $fromEnv = trim((string) env_value('COMPLIANCE_EMAIL', ''));
        return $fromEnv !== '' ? $fromEnv : ('support@' . (string) config('app.site_name') . '.com');
    }

    /**
     * The absolute site base (APP_URL anchored, request fallback) used by the
     * sitemap and feed builders.
     */
    private function baseUrl(): string
    {
        $base = rtrim((string) env_value('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base   = $scheme . '://' . $host . rtrim((string) config('app.base_path'), '/');
        }

        return $base;
    }

    /**
     * Serve an XML sitemap listing the public, crawlable pages. Private /
     * member-only areas are intentionally excluded, and gallery detail pages
     * require a membership so only the listing pages are published. Entries
     * carry a <lastmod> when the content has a known publish/create date.
     */
    public function sitemap(): void
    {
        $base = $this->baseUrl();

        // Static pages render 200 for guests; content pages now serve a
        // public, indexable blurred preview too, so they belong in the map.
        $entries = [];
        foreach (['/', '/about', '/terms', '/privacy', '/2257', '/dmca', '/report-abuse', '/membership'] as $staticPath) {
            $entries[] = [$staticPath, null];
        }

        $rows = \App\Core\Database::run('SELECT slug FROM categories ORDER BY name')->fetchAll();
        foreach ($rows as $row) {
            $entries[] = ['/galleries/category/' . rawurlencode((string) $row['slug']), null];
        }

        $galleries = \App\Core\Database::run(
            'SELECT id, COALESCE(published_at, created_at) AS lastmod FROM galleries
             WHERE deleted_at IS NULL AND is_secret = 0 AND '
            . Gallery::publishedVisibleSql('galleries') . '
             ORDER BY COALESCE(published_at, created_at) DESC'
        )->fetchAll();
        foreach ($galleries as $g) {
            $entries[] = ['/galleries/' . (int) $g['id'], (string) ($g['lastmod'] ?? '')];
        }

        $media = \App\Core\Database::run(
            'SELECT DISTINCT p.id, p.is_video, p.created_at
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE g.deleted_at IS NULL AND g.is_secret = 0 AND '
            . Gallery::publishedVisibleSql('g')
        )->fetchAll();
        foreach ($media as $m) {
            $entries[] = [
                ((int) ($m['is_video'] ?? 0) === 1 ? '/videos/' : '/images/') . (int) $m['id'],
                (string) ($m['created_at'] ?? ''),
            ];
        }

        // Stay comfortably under Google's 50k URLs / 50MB sitemap limits.
        $entries = array_slice($entries, 0, 45000);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as [$path, $lastmod]) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . '/' . ltrim($path, '/'), ENT_XML1, 'UTF-8') . '</loc>';
            $ts = is_string($lastmod) && $lastmod !== '' ? strtotime($lastmod) : false;
            if ($ts !== false) {
                $xml .= '<lastmod>' . date('Y-m-d', $ts) . '</lastmod>';
            }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        header('Content-Type: application/xml; charset=utf-8');
        echo $xml;
    }

    /**
     * RSS 2.0 feed of the newest published galleries. Item links go through
     * Traffic::buildUrl() so subscribers attributed to the `rss` code; the
     * guid stays the canonical (untracked) URL so it never churns.
     */
    public function feed(): void
    {
        $base = $this->baseUrl();
        $siteName = (string) config('app.site_name');

        $rows = \App\Core\Database::run(
            'SELECT g.id, g.title, g.description, COALESCE(g.published_at, g.created_at) AS pub_date
             FROM galleries g
             WHERE g.deleted_at IS NULL AND g.is_secret = 0 AND '
            . Gallery::publishedVisibleSql('g') . '
             ORDER BY COALESCE(g.published_at, g.created_at) DESC
             LIMIT 30'
        )->fetchAll();

        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_XML1, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . '<channel>' . "\n"
            . '  <title>' . $esc($siteName . ' — latest galleries') . '</title>' . "\n"
            . '  <link>' . $esc(absolute_url('/galleries')) . '</link>' . "\n"
            . '  <description>' . $esc('Newest published galleries from ' . $siteName . '.') . '</description>' . "\n"
            . '  <language>en</language>' . "\n"
            . '  <atom:link href="' . $esc($base . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $title = trim((string) $row['title']);
            $desc  = trim((string) ($row['description'] ?? ''));
            $pub   = strtotime((string) ($row['pub_date'] ?? ''));

            $xml .= '  <item>' . "\n";
            $xml .= '    <title>' . $esc($title !== '' ? $title : 'New gallery') . '</title>' . "\n";
            $xml .= '    <link>' . $esc(\App\Models\Traffic::buildUrl('/galleries/' . $id, 'rss')) . '</link>' . "\n";
            $xml .= '    <guid isPermaLink="true">' . $esc(absolute_url('/galleries/' . $id)) . '</guid>' . "\n";
            if ($pub !== false) {
                $xml .= '    <pubDate>' . gmdate('D, d M Y H:i:s', $pub) . ' GMT</pubDate>' . "\n";
            }
            if ($desc !== '') {
                $xml .= '    <description>' . $esc(mb_substr($desc, 0, 500)) . '</description>' . "\n";
            }
            $xml .= '  </item>' . "\n";
        }

        $xml .= '</channel>' . "\n"
            . '</rss>' . "\n";

        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $xml;
    }
}
