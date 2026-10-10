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
}