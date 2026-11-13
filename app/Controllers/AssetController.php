<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Theme;

/**
 * Cacheable generated assets served through the PHP router (everything under
 * /assets is a static Apache alias, so generated CSS lives at /theme.css).
 */
class AssetController extends Controller
{
    /**
     * The site's external theme stylesheet: palette + layout CSS variables for
     * the (default) site preset, followed by the static base rules that were
     * previously inlined into <style> on every page.
     *
     * The URL includes Theme::themeCssVersion() as ?v= so each distinct render
     * is a unique, immutable URL - the browser can cache this for a year and a
     * preset/base change simply mints a new one.
     */
    public function themeCss(): void
    {
        header('Content-Type: text/css; charset=UTF-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Vary: Accept-Encoding');

        echo Theme::cssUser() . "\n" . Theme::cssLayoutUser() . "\n";

        $base = __DIR__ . '/../Core/theme_base.css';
        if (is_file($base)) {
            echo (string) file_get_contents($base);
        }
    }

    /**
     * The title image uploaded through the theme editor.
     *
     * It lives in storage/uploads (the one writable directory), which Apache
     * does not alias, so the upload used to be stored at a URL that nothing
     * served: a 404 logo and a watermark that quietly vanished. The router
     * serves the file here instead.
     *
     * Only the generated name shape is accepted - title- <16 hex> . <raster
     * extension> - so this endpoint can never be turned into a reader for
     * the rest of the uploads directory. SVG is deliberately absent: an SVG
     * served from the site's own origin can carry script.
     */
    public function titleImage(string $file): void
    {
        $name = basename($file);

        if ($name === '' || $name !== $file
            || preg_match('/^title-[a-f0-9]{16}\.(?:png|jpe?g|gif|webp)$/i', $name) !== 1) {
            http_response_code(404);
            return;
        }

        $path = dirname(__DIR__, 2) . '/storage/uploads/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            return;
        }

        // The name is random and the bytes never change under it, so the
        // response is immutable.
        header('Content-Type: ' . mime_for_extension($name));
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
    }
}