<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Gallery;

/**
 * The sitemap.
 *
 * The map is built here and in ONE place, so the file that is written out
 * and the map that is served can never drift apart: Google would otherwise
 * be told about a different site than the one a crawler sees.
 *
 * Two maps are produced:
 *
 *   sitemap.xml       the pages a crawler should read: the static pages,
 *                     the categories that hold a gallery, and the published
 *                     galleries.
 *   videositemap.xml  the video pages, with the Google video namespace.
 *
 * Individual media pages (/images/{id}, /videos/{id}) are deliberately NOT
 * listed. They are member-gated media viewers, not landing pages: for a
 * crawler they render an "unlock with a membership" wall with no media, and
 * most had no caption, so the whole set shared one boilerplate <title>.
 * Submitting thousands of near-identical empty URLs only told Google the
 * site was thin, and the crawler budget was spent on pages that can never
 * rank. They stay crawlable and linked from their galleries.
 */
final class Sitemap
{
    /** The static pages that belong in the map. */
    private const STATIC_PATHS = [
        '/', '/galleries', '/creator', '/about', '/terms', '/privacy',
        '/2257', '/dmca', '/report-abuse', '/membership',
    ];

    /** Comfortably under Google's 50k URLs / 50MB sitemap limits. */
    private const MAX_ENTRIES = 45000;

    private const MAX_VIDEO_ENTRIES = 5000;

    /**
     * The entries of the main map: [path, lastmod] pairs.
     */
    public static function entries(): array
    {
        $entries = [];
        foreach (self::STATIC_PATHS as $staticPath) {
            $entries[] = [$staticPath, null];
        }

        // ONLY the categories that have a gallery in them. 27 of the 111
        // category pages had no published gallery, so they were a 200 page
        // whose whole body was "No image galleries in this category" - a
        // thin page in the map, and a wasted crawl.
        $rows = Database::run(
            "SELECT c.slug, MAX(COALESCE(g.published_at, g.created_at)) AS lastmod
             FROM categories c
             INNER JOIN gallery_category gc ON gc.category_id = c.id
             INNER JOIN galleries g ON g.id = gc.gallery_id
             WHERE g.deleted_at IS NULL AND g.is_secret = 0 AND "
            . Gallery::publishedVisibleSql('g') . '
             GROUP BY c.slug
             ORDER BY c.name'
        )->fetchAll();
        foreach ($rows as $row) {
            $entries[] = ['/galleries/category/' . rawurlencode((string) $row['slug']), (string) ($row['lastmod'] ?? '')];
        }

        $galleries = Database::run(
            'SELECT id, COALESCE(published_at, created_at) AS lastmod FROM galleries
             WHERE deleted_at IS NULL AND is_secret = 0 AND '
            . Gallery::publishedVisibleSql('galleries') . '
             ORDER BY COALESCE(published_at, created_at) DESC'
        )->fetchAll();
        foreach ($galleries as $gallery) {
            $entries[] = ['/galleries/' . (int) $gallery['id'], (string) ($gallery['lastmod'] ?? '')];
        }

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /**
     * The main map as XML.
     */
    public static function xml(): string
    {
        $base = self::baseUrl();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::entries() as [$path, $lastmod]) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . '/' . ltrim($path, '/'), ENT_XML1, 'UTF-8') . '</loc>';
            $timestamp = is_string($lastmod) && $lastmod !== '' ? strtotime($lastmod) : false;
            if ($timestamp !== false) {
                $xml .= '<lastmod>' . date('Y-m-d', $timestamp) . '</lastmod>';
            }
            $xml .= '</url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    /**
     * The video map as XML.
     */
    public static function videoXml(): string
    {
        $base = self::baseUrl();

        $rows = Database::run(
            'SELECT p.id, p.filename, p.caption, p.duration_seconds, p.created_at,
                    g.title AS gallery_title
             FROM photos p
             INNER JOIN gallery_photo gp ON gp.photo_id = p.id
             INNER JOIN galleries g ON g.id = gp.gallery_id
             WHERE p.is_video = 1
               AND g.deleted_at IS NULL AND g.is_secret = 0 AND '
            . Gallery::publishedVisibleSql('g') . '
             GROUP BY p.id, p.filename, p.caption, p.duration_seconds, p.created_at, g.title
             ORDER BY p.created_at DESC
             LIMIT ' . self::MAX_VIDEO_ENTRIES
        )->fetchAll();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

        foreach ($rows as $row) {
            $pageUrl = $base . '/videos/' . (int) $row['id'];
            $clipUrl = $base . '/previews/' . (int) $row['id'];
            $title = trim((string) ($row['caption'] ?? '')) !== ''
                ? (string) $row['caption']
                : (string) ($row['gallery_title'] ?? 'Video');

            $xml .= '  <url>' . "\n"
                . '    <loc>' . htmlspecialchars($pageUrl, ENT_XML1, 'UTF-8') . '</loc>' . "\n"
                . '    <video:video>' . "\n"
                . '      <video:thumbnail_loc>' . htmlspecialchars(absolute_url(url('/files/' . rawurlencode((string) $row['filename']) . '?size=thumb')), ENT_XML1, 'UTF-8') . '</video:thumbnail_loc>' . "\n"
                . '      <video:title>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</video:title>' . "\n"
                . '      <video:content_loc>' . htmlspecialchars($clipUrl, ENT_XML1, 'UTF-8') . '</video:content_loc>' . "\n"
                . '      <video:publication_date>' . date('c', strtotime((string) ($row['created_at'] ?? 'now')) ?: time()) . '</video:publication_date>' . "\n";
            if (!empty($row['duration_seconds'])) {
                $xml .= '      <video:duration>' . (int) $row['duration_seconds'] . '</video:duration>' . "\n";
            }
            $xml .= '    </video:video>' . "\n"
                . '  </url>' . "\n";
        }

        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    /**
     * Write both maps into a folder as files.
     *
     * The file is written to a temporary name and renamed into place, so a
     * crawler never reads a half-written map.
     */
    public static function write(string $dir): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot make the folder ' . $dir);
        }

        $written = [];
        foreach (['sitemap.xml' => self::xml(), 'videositemap.xml' => self::videoXml()] as $name => $contents) {
            $path = rtrim($dir, '/') . '/' . $name;
            $tmp  = $path . '.new';
            if (@file_put_contents($tmp, $contents) === false) {
                throw new \RuntimeException('cannot write ' . $tmp);
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException('cannot move ' . $tmp . ' into place');
            }
            $written[$name] = ['path' => $path, 'bytes' => strlen($contents)];
        }

        return $written;
    }

    /**
     * The site's base address, the same one the links use.
     *
     * APP_URL is the authority. The request host is only a fallback, and it
     * does not exist when the map is written out from the command line, so
     * the address always comes from the configuration there.
     */
    public static function baseUrl(): string
    {
        $base = rtrim((string) env_value('APP_URL', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base   = $scheme . '://' . $host . rtrim((string) config('app.base_path'), '/');
        }

        return $base;
    }
}
