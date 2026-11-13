<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\MediaUploader;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Theme;

class ThemeController extends Controller
{
    /**
     * Theme editing is an ADMIN surface (the router already gates every theme
     * route on the `theme` permission; this constructor repeats the gate so
     * a future route cannot accidentally expose the editor to a member).
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requireLogin();
        Auth::requirePermission('theme');
    }

    /**
     * Admin: theme editor page pre-filled with both palettes so the admin can
     * switch between the site theme and the admin theme.
     */
    public function index(): void
    {
        $scopes = [
            'site' => ['label' => 'User theme', 'mock' => 'site'],
        ];

        if (Auth::isAdmin()) {
            $scopes['admin'] = ['label' => 'Admin theme', 'mock' => 'admin'];
        }

        $topContent = '<div class="theme-tabs" role="tablist" aria-label="Theme scope">';
        foreach ($scopes as $scope => $info) {
            $active = $scope === 'site' ? ' active' : '';
            $selected = $scope === 'site' ? 'true' : 'false';
            $topContent .= '<button type="button" class="theme-tab' . $active . '" data-scope="' . e($info['mock']) . '" onclick="window.switchThemeScope && window.switchThemeScope(\'' . e($info['mock']) . '\'); return false;" role="tab" aria-selected="' . $selected . '">' . e($info['label']) . '</button>';
        }
        $topContent .= '</div>';

        $this->viewAdmin('theme', [
            'siteTheme'       => Theme::all(Theme::SCOPE_SITE),
            'adminTheme'      => Theme::all(Theme::SCOPE_ADMIN),
            'defaults'        => Theme::defaults(Theme::SCOPE_SITE),
            'adminDefaults'   => Theme::defaults(Theme::SCOPE_ADMIN),
            'siteLayout'      => Theme::allLayout(Theme::SCOPE_SITE),
            'adminLayout'     => Theme::allLayout(Theme::SCOPE_ADMIN),
            'layoutDefaults'  => Theme::layoutDefaults(),
            'layoutSections'  => Theme::LAYOUT_SECTIONS,
            'topContent'      => $topContent,
            'presets'         => array_values(array_filter(
                Theme::presets(),
                static fn (array $preset): bool => Auth::isAdmin() || ($preset['scope'] ?? 'site') !== Theme::SCOPE_ADMIN
            )),
            'themeScopes'     => $scopes,
        ]);
    }

    /**
     * Save the palette and layout settings submitted by the theme editor.
     */
    public function update(): void
    {
        $scope = (string) $this->request->post('scope', Theme::SCOPE_SITE);

        if (!in_array($scope, Theme::scopes(), true)) {
            $scope = Theme::SCOPE_SITE;
        }
        if ($scope === Theme::SCOPE_ADMIN && !Auth::isAdmin()) {
            $scope = Theme::SCOPE_SITE;
        }

        $prefix = $scope . '-';

        // Save colours
        $beforeColors = Theme::all($scope);
        $colorValues  = [];

        foreach (Theme::defaults($scope) as $key => $default) {
            $colorValues[$key] = (string) $this->request->post($prefix . $key, '');
        }

        Theme::save($colorValues, $scope);

        // Save layout settings
        $beforeLayout = Theme::allLayout($scope);
        $layoutValues = [];

        foreach (Theme::layoutDefaults() as $key => $def) {
            $layoutValues[$key] = (string) $this->request->post($prefix . $key, $def['value']);
        }

        Theme::saveLayout($layoutValues, $scope);

        // Handle title image upload
        $beforeTitleImage = Theme::titleImage($scope);
        $titleImageError  = null;

        if ($this->request->post('reset_title_image') === '1') {
            Theme::saveTitleImage('/assets/images/AmethystTitleImage.png', $scope);
        } elseif (isset($_FILES['title_image']) && $_FILES['title_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['title_image'];

            // The allow-list is decided by the BYTES, not the uploaded name:
            // an SVG (active content, and served from our own origin) never
            // passes sniff_mime + image_can_decode, and the stored extension
            // comes from the detected type. A 16-byte random name means the
            // endpoint cannot be walked, and the size/dimension caps keep a
            // 100 MP "logo" from being stored.
            $mime = sniff_mime((string) $file['tmp_name']);
            $ext  = $mime !== '' ? MediaUploader::extensionForMime($mime) : '';
            $info = $mime !== '' ? @getimagesize((string) $file['tmp_name']) : false;
            $bytes = (int) ($file['size'] ?? 0);

            if (in_array($ext, ['jpg', 'png', 'gif', 'webp'], true)
                && $info !== false && $info[0] > 0 && $info[1] > 0
                && $info[0] <= 8000 && $info[1] <= 8000
                && $bytes > 0 && $bytes <= 8 * 1024 * 1024
                && image_can_decode((string) $file['tmp_name'])) {
                $dir = dirname(__DIR__, 2) . '/storage/uploads';

                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                $name = 'title-' . bin2hex(random_bytes(8)) . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
                Theme::saveTitleImage('/title-image/' . $name, $scope);
            } else {
                $titleImageError = 'That title image was not saved: upload a PNG, JPEG, GIF or WebP under 8 MB.';
            }
        }

        $newTitleImage = Theme::titleImage($scope);

        // Audit log if anything changed
        if ($beforeColors !== $colorValues || $beforeLayout !== $layoutValues || $beforeTitleImage !== $newTitleImage) {
            $label = $scope === Theme::SCOPE_ADMIN ? 'Updated admin theme' : 'Updated site theme';
            AuditLog::record(
                (int) Auth::user()['id'],
                'update',
                'theme',
                null,
                $label,
                ['scope' => $scope, 'colors' => $beforeColors, 'layout' => $beforeLayout],
                ['scope' => $scope, 'colors' => $colorValues, 'layout' => $layoutValues]
            );
        }

        if ($titleImageError !== null) {
            $this->flash('error', $titleImageError);
        } else {
            $this->flash('success', 'Theme updated.');
        }
        $this->redirect('/admin/theme');
    }

    /**
     * Save the current theme state as a named preset.
     */
    public function savePreset(): void
    {
        $name  = trim((string) $this->request->post('preset_name', ''));
        $scope = (string) $this->request->post('scope', Theme::SCOPE_SITE);

        if ($name === '') {
            $this->flash('error', 'Please enter a name for the theme.');
            $this->redirect('/admin/theme');
            return;
        }

        if (!in_array($scope, Theme::scopes(), true)) {
            $scope = Theme::SCOPE_SITE;
        }
        if ($scope === Theme::SCOPE_ADMIN && !Auth::isAdmin()) {
            $scope = Theme::SCOPE_SITE;
        }

        // Accept live editor values from JS POST, or fall back to saved files
        $colors = $this->request->post('colors');
        $layout = $this->request->post('layout');

        if (is_string($colors)) {
            $colors = json_decode($colors, true);
        }
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }

        if (!is_array($colors)) {
            $colors = Theme::all($scope);
        }
        if (!is_array($layout)) {
            $layout = Theme::allLayout($scope);
        }

        $titleImage = Theme::titleImage($scope);

        $slug = self::savePresetDirect($name, $scope, $colors, $layout, $titleImage);

        AuditLog::record(
            (int) Auth::user()['id'],
            'create',
            'theme',
            null,
            'Saved theme preset: ' . $name,
            null,
            ['slug' => $slug, 'name' => $name, 'scope' => $scope]
        );

        $this->flash('success', 'Theme "' . e($name) . '" saved.');
        $this->redirect('/admin/theme');
    }

    /**
     * Save a preset directly with provided data (used by savePreset action).
     */
    private static function savePresetDirect(string $name, string $scope, array $colors, array $layout, string $titleImage): string
    {
        $colors = array_merge(Theme::defaults($scope), array_intersect_key($colors, Theme::defaults($scope)));
        $layoutDefaults = Theme::layoutDefaults();
        $layoutValues = [];
        foreach ($layoutDefaults as $key => $definition) {
            $layoutValues[$key] = $layout[$key] ?? $definition['value'];
        }

        $slug = Theme::slugify($name);
        $dir  = Theme::presetsDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = [
            'name'        => $name,
            'scope'       => $scope,
            'colors'      => $colors,
            'layout'      => $layoutValues,
            'title_image' => $titleImage,
            'created_at'  => date('Y-m-d H:i:s'),
        ];

        file_put_contents($dir . '/' . $slug . '.json', json_encode($data, JSON_PRETTY_PRINT));

        // The stylesheet is versioned by URL; a new/updated preset must mint
        // a fresh version or a browser keeps the old immutable CSS.
        \App\Core\Cache::bump('theme');

        return $slug;
    }

    /**
     * Apply a saved preset to the active scope.
     */
    public function applyPreset(): void
    {
        $slug  = (string) $this->request->post('preset_slug', '');
        $scope = (string) $this->request->post('scope', Theme::SCOPE_SITE);

        if (!in_array($scope, Theme::scopes(), true)) {
            $scope = Theme::SCOPE_SITE;
        }
        if ($scope === Theme::SCOPE_ADMIN && !Auth::isAdmin()) {
            $scope = Theme::SCOPE_SITE;
        }

        $beforeColors = Theme::all($scope);
        $beforeLayout = Theme::allLayout($scope);
        $beforeImage  = Theme::titleImage($scope);

        if (!Theme::applyPreset($slug, $scope)) {
            $this->flash('error', 'Theme preset not found.');
            $this->redirect('/admin/theme');
            return;
        }

        $preset = Theme::loadPreset($slug);
        $label  = 'Applied theme preset: ' . ($preset['name'] ?? $slug);

        AuditLog::record(
            (int) Auth::user()['id'],
            'update',
            'theme',
            null,
            $label,
            ['scope' => $scope, 'colors' => $beforeColors, 'layout' => $beforeLayout],
            ['scope' => $scope, 'colors' => Theme::all($scope), 'layout' => Theme::allLayout($scope)]
        );

        $this->flash('success', 'Theme "' . e($slug) . '" applied.');
        $this->redirect('/admin/theme');
    }

    /**
     * Delete a saved preset.
     */
    public function deletePreset(): void
    {
        $slug = (string) $this->request->post('preset_slug', '');

        if (Theme::deletePreset($slug)) {
            AuditLog::record(
                (int) Auth::user()['id'],
                'delete',
                'theme',
                null,
                'Deleted theme preset: ' . $slug,
                null,
                ['slug' => $slug]
            );

            $this->flash('success', 'Theme preset deleted.');
        } else {
            $this->flash('error', 'Theme preset not found.');
        }

        $this->redirect('/admin/theme');
    }
}
