<?php

namespace App\Models;

/**
 * Site and admin colour themes. Each palette is exposed as CSS custom
 * properties on a scope selector — :root for the user-facing site, and
 * .admin-theme for the admin panel — so the two areas can be themed
 * independently. Admin overrides are persisted to storage/theme.json (site)
 * and storage/admin-theme.json (admin) on top of the built-in defaults.
 */
class Theme
{
    /** Scope for the user-facing site palette. */
    public const SCOPE_SITE = 'site';

    /** Scope for the admin panel palette. */
    public const SCOPE_ADMIN = 'admin';

    /**
     * Built-in default colour palette used until (and whenever) an admin
     * override is missing.
     */
    private const COLORS = [
        'pink-100'   => '#ffd9e8',
        'pink-200'   => '#f9a8d4',
        'pink-300'   => '#f472b6',
        'pink-400'   => '#f052a0',
        'pink-500'   => '#ec4899',
        'pink-700'   => '#be185d',
        'pink-800'   => '#9d174d',
        'pink-900'   => '#831843',
        'purple-200' => '#d8b4fe',
        'purple-300' => '#c084fc',
        'purple-400' => '#a855f7',
        'purple-500' => '#9333ea',
        'purple-600' => '#7e22ce',
        'purple-700' => '#6b21a8',
        'purple-800' => '#581c87',
        'purple-900' => '#3b0764',
        'btn-bg'        => '#f472b6',
        'btn-color'     => '#3b0764',
        'btn-hover-bg'  => '#f052a0',
        'btn-danger-bg'     => '#ffd9e8',
        'btn-danger-color'  => '#3b0764',
        'btn-danger-hover-bg' => '#f9a8d4',
        'btn-danger-border' => '#db2777',
        'filter-bg'        => '#f472b6',
        'filter-border'    => '#db2777',
        'filter-color'     => '#3b0764',
        'filter-inactive-bg'    => '#e8e8e8',
        'filter-inactive-border'=> '#cccccc',
        'filter-inactive-color' => '#999999',
        'filter-inactive-hover' => '#dddddd',
        'filter-hover-bg'       => '#f052a0',
        'sidebar-bg'           => '#ffd9e8',
        'sidebar-border'       => '#f472b6',
        'sidebar-heading'      => '#581c87',
        'sidebar-link-bg'      => '#f472b6',
        'sidebar-link-color'   => '#6b21a8',
        'sidebar-link-border'  => '#f052a0',
        'sidebar-link-hover'   => '#f052a0',
        'sidebar-active-bg'    => '#a855f7',
        'sidebar-active-color' => '#3b0764',
        'sidebar-active-border'=> '#9333ea',
        'card-bg'              => '#f472b6',
        'card-border'          => '#f052a0',
        'card-placeholder-bg'  => '#ffd9e8',
        'card-thumb-bg'        => '#3b0764',
        'card-thumb-color'     => '#f9a8d4',
        'card-title-color'     => '#581c87',
        'card-text-color'      => '#6b21a8',
        'card-cat-link-color'  => '#7e22ce',
        'stat-bg'              => '#ffd9e8',
        'stat-border'          => '#f472b6',
        'stat-number-color'    => '#3b0764',
        'stat-label-color'     => '#6b21a8',
        'table-bg'             => '#ffd9e8',
        'table-border'         => '#f472b6',
        'table-header-bg'      => '#f472b6',
        'table-header-color'   => '#3b0764',
        'table-text'           => '#6b21a8',
        'promo-card-bg'        => '#f472b6',
        'promo-card-border'    => '#f052a0',
        'promo-card-title'     => '#581c87',
        'promo-card-text'      => '#6b21a8',
        'promo-code-bg'        => '#f9a8d4',
        'promo-code-border'    => '#db2777',
        'promo-code-color'     => '#3b0764',
        'pagination-bg'        => '#f472b6',
        'pagination-color'     => '#3b0764',
        'pagination-hover-bg'  => '#f052a0',
        'pagination-active-bg' => '#f9a8d4',
        'pagination-active-color' => '#3b0764',
        'pagination-border'    => '#db2777',
    ];

    /**
     * Built-in default layout / typography settings. Each key maps to a CSS
     * custom property that is emitted alongside the colour palette.
     */
    private const LAYOUT_DEFAULTS = [
        'border-radius-sm'   => ['value' => '4px',   'label' => 'Small radius',    'section' => 'border'],
        'border-radius'      => ['value' => '8px',   'label' => 'Default radius',  'section' => 'border'],
        'border-radius-lg'   => ['value' => '12px',  'label' => 'Large radius',    'section' => 'border'],
        'font-size-xs'       => ['value' => '0.7rem',  'label' => 'Extra small text',  'section' => 'typography'],
        'font-size-sm'       => ['value' => '0.85rem', 'label' => 'Small text',        'section' => 'typography'],
        'font-size-base'     => ['value' => '1rem',    'label' => 'Base font size',    'section' => 'typography'],
        'font-size-lg'       => ['value' => '1.15rem', 'label' => 'Large text',        'section' => 'typography'],
        'font-size-xl'       => ['value' => '1.5rem',  'label' => 'Heading size',      'section' => 'typography'],
        'font-size-h1'       => ['value' => '1.75rem', 'label' => 'H1 size',           'section' => 'typography'],
        'line-height'        => ['value' => '1.5',     'label' => 'Line height',       'section' => 'typography'],
        'spacing-xs'         => ['value' => '0.25rem', 'label' => 'Extra small',       'section' => 'spacing'],
        'spacing-sm'         => ['value' => '0.5rem',  'label' => 'Small',             'section' => 'spacing'],
        'spacing-md'         => ['value' => '1rem',    'label' => 'Medium',            'section' => 'spacing'],
        'spacing-lg'         => ['value' => '1.5rem',  'label' => 'Large',             'section' => 'spacing'],
        'spacing-xl'         => ['value' => '2rem',    'label' => 'Extra large',       'section' => 'spacing'],
        'shadow'             => ['value' => '0 1px 4px rgba(88,28,135,0.15)',  'label' => 'Shadow',         'section' => 'effects'],
        'btn-padding'        => ['value' => '0.5rem 1rem',   'label' => 'Button padding',    'section' => 'components'],
        'btn-radius'         => ['value' => '4px',           'label' => 'Button radius',     'section' => 'components'],
        'btn-font-size'      => ['value' => '0.9rem',        'label' => 'Button font size',  'section' => 'components'],
        'input-padding'      => ['value' => '0.4rem 0.6rem', 'label' => 'Input padding',     'section' => 'components'],
        'input-radius'       => ['value' => '4px',           'label' => 'Input radius',      'section' => 'components'],
        'input-border-width' => ['value' => '1px',           'label' => 'Input border width','section' => 'components'],
        'card-radius'        => ['value' => '8px',           'label' => 'Card radius',       'section' => 'components'],
        'card-padding'       => ['value' => '0.75rem',       'label' => 'Card padding',      'section' => 'components'],
        'chip-radius'        => ['value' => '999px',         'label' => 'Chip radius',       'section' => 'components'],
        'chip-padding'       => ['value' => '0.35rem 0.75rem', 'label' => 'Chip padding',   'section' => 'components'],
        'table-radius'       => ['value' => '0',             'label' => 'Table radius',      'section' => 'components'],
    ];

    /**
     * Section labels for the layout settings groups.
     */
    public const LAYOUT_SECTIONS = [
        'border'      => 'Border & Radius',
        'typography'  => 'Typography',
        'spacing'     => 'Spacing',
        'effects'     => 'Effects',
        'components'  => 'Component Styles',
    ];

    /**
     * Valid theme scopes.
     */
    public static function scopes(): array
    {
        return [self::SCOPE_SITE, self::SCOPE_ADMIN];
    }

    /**
     * The default colour palette.
     */
    public static function defaults(): array
    {
        return self::COLORS;
    }

    /**
     * The default layout settings metadata.
     */
    public static function layoutDefaults(): array
    {
        return self::LAYOUT_DEFAULTS;
    }

    /**
     * Path to the colour theme file for a scope.
     */
    private static function file(string $scope): string
    {
        $name = $scope === self::SCOPE_ADMIN ? 'admin-theme.json' : 'theme.json';

        return __DIR__ . '/../../storage/' . $name;
    }

    /**
     * Path to the layout theme file for a scope.
     */
    private static function layoutFile(string $scope): string
    {
        $name = $scope === self::SCOPE_ADMIN ? 'admin-layout.json' : 'site-layout.json';

        return __DIR__ . '/../../storage/' . $name;
    }

    /**
     * Effective palette for a scope: defaults merged with active template from database,
     * or saved overrides from JSON file if no active template exists.
     */
    public static function all(string $scope = self::SCOPE_SITE): array
    {
        // Colors come from the saved preset JSON file — NOT from config_json,
        // which only stores site-editor structural operations (order/move/hide).
        $file  = self::file($scope);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        $merged = array_merge(self::COLORS, is_array($saved) ? $saved : []);
        // Remove non-color keys that live in the same file but aren't CSS vars
        unset($merged['_title_image']);
        // Remove any values that aren't valid CSS color strings
        return array_filter($merged, fn($v) => is_string($v));
    }

    /**
     * Get the title image path for a scope (relative to /gallery/).
     */
    public static function titleImage(string $scope = self::SCOPE_SITE): string
    {
        $file  = self::file($scope);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return $saved['_title_image'] ?? '/assets/images/AmethystTitleImage.png';
    }

    /**
     * Get the title image URL for a scope.
     */
    public static function titleImageUrl(string $scope = self::SCOPE_SITE): string
    {
        return url(self::titleImage($scope));
    }

    /**
     * Save the title image path for a scope.
     */
    public static function saveTitleImage(string $path, string $scope = self::SCOPE_SITE): void
    {
        $file  = self::file($scope);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $saved['_title_image'] = $path;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($saved, JSON_PRETTY_PRINT));
    }

    /**
     * Effective layout settings for a scope: defaults merged with saved overrides.
     */
    /**
     * Effective layout settings for a scope: defaults merged with active template from database,
     * or saved overrides from JSON file if no active template exists.
     */
    public static function allLayout(string $scope = self::SCOPE_SITE): array
    {
        // Layout settings come from the saved preset JSON file — NOT from config_json.
        $file  = self::layoutFile($scope);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        $result = [];
        foreach (self::LAYOUT_DEFAULTS as $key => $def) {
            $result[$key] = $saved[$key] ?? $def['value'];
        }

        return $result;
    }

    /**
     * Persist the palette for a scope from the theme editor.
     */
    public static function save(array $values, string $scope = self::SCOPE_SITE): void
    {
        $file  = self::file($scope);
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $theme = [];

        // Preserve title image path
        if (isset($saved['_title_image'])) {
            $theme['_title_image'] = $saved['_title_image'];
        }

        foreach (self::COLORS as $key => $default) {
            $value = trim((string) ($values[$key] ?? ''));

            $theme[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', $value)
                ? strtolower($value)
                : $default;
        }

        $file = self::file($scope);
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($theme, JSON_PRETTY_PRINT));

        self::clearCssCache($scope);
    }

    /**
     * Persist layout settings for a scope from the theme editor.
     */
    public static function saveLayout(array $values, string $scope = self::SCOPE_SITE): void
    {
        $theme = [];

        foreach (self::LAYOUT_DEFAULTS as $key => $def) {
            $theme[$key] = trim((string) ($values[$key] ?? $def['value']));
        }

        $file = self::layoutFile($scope);
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($theme, JSON_PRETTY_PRINT));

        self::clearCssCache($scope);
    }

    /**
     * Drop the cached rendered CSS for a scope after its source changes.
     */
    private static function clearCssCache(string $scope = self::SCOPE_SITE): void
    {
        \App\Core\Cache::forget('theme.css.' . $scope . '.' . md5($scope === self::SCOPE_ADMIN ? '.admin-theme' : ':root'));
        \App\Core\Cache::forget('theme.cssLayout.' . $scope . '.' . md5($scope === self::SCOPE_ADMIN ? '.admin-theme' : ':root'));
    }

    /**
     * Render the palette for a scope as CSS custom properties.
     */
    public static function css(string $scope = self::SCOPE_SITE, ?string $selector = null): string
    {
        $selector = $selector ?? ($scope === self::SCOPE_ADMIN ? '.admin-theme' : ':root');

        return \App\Core\Cache::remember('theme.css.' . $scope . '.' . md5($selector), 300, function () use ($scope, $selector) {
            $lines = [];

            foreach (self::all($scope) as $key => $value) {
                if ($key[0] === '_' || !is_string($value)) continue;
                $lines[] = '    --' . $key . ': ' . $value . ';';
            }

            return $selector . ' {' . "\n" . implode("\n", $lines) . "\n}";
        });
    }

    /**
     * Render layout settings for a scope as CSS custom properties.
     */
    public static function cssLayout(string $scope = self::SCOPE_SITE, ?string $selector = null): string
    {
        $selector = $selector ?? ($scope === self::SCOPE_ADMIN ? '.admin-theme' : ':root');

        return \App\Core\Cache::remember('theme.cssLayout.' . $scope . '.' . md5($selector), 300, function () use ($scope, $selector) {
            $lines = [];

            foreach (self::allLayout($scope) as $key => $value) {
                $lines[] = '    --' . $key . ': ' . $value . ';';
            }

            return $selector . ' {' . "\n" . implode("\n", $lines) . "\n}";
        });
    }

    /**
     * Resolve a user's selected site preset, falling back to the global site theme.
     */
    public static function userTheme(?string $slug = null): array
    {
        $theme = [
            'colors'      => self::all(self::SCOPE_SITE),
            'layout'      => self::allLayout(self::SCOPE_SITE),
            'title_image' => self::titleImage(self::SCOPE_SITE),
        ];

        if ($slug === null || $slug === '') {
            return $theme;
        }

        $preset = self::loadPreset($slug);
        if (!is_array($preset) || ($preset['scope'] ?? self::SCOPE_SITE) !== self::SCOPE_SITE) {
            return $theme;
        }

        $colors = is_array($preset['colors'] ?? null) ? $preset['colors'] : [];
        $layout = is_array($preset['layout'] ?? null) ? $preset['layout'] : [];
        $theme['colors'] = array_merge(self::all(self::SCOPE_SITE), array_intersect_key($colors, self::COLORS));
        $theme['layout'] = array_merge(self::allLayout(self::SCOPE_SITE), array_intersect_key($layout, self::LAYOUT_DEFAULTS));
        $theme['title_image'] = (string) ($preset['title_image'] ?? $theme['title_image']);

        return $theme;
    }

    /**
     * Render a user's selected site palette as CSS variables.
     */
    public static function cssUser(?string $slug = null, string $selector = ':root'): string
    {
        $lines = [];
        foreach (self::userTheme($slug)['colors'] as $key => $value) {
            if ($key[0] === '_' || !is_string($value)) continue;
            $lines[] = '    --' . $key . ': ' . $value . ';';
        }

        return $selector . " {\n" . implode("\n", $lines) . "\n}";
    }

    /**
     * Render a user's selected site layout as CSS variables.
     */
    public static function cssLayoutUser(?string $slug = null, string $selector = ':root'): string
    {
        $lines = [];
        foreach (self::userTheme($slug)['layout'] as $key => $value) {
            $lines[] = '    --' . $key . ': ' . $value . ';';
        }

        return $selector . " {\n" . implode("\n", $lines) . "\n}";
    }

    // ── Theme presets ──────────────────────────────────────────────────

    /**
     * Directory that stores saved theme presets.
     */
    public static function presetsDir(): string
    {
        return __DIR__ . '/../../storage/themes';
    }

    /**
     * Sanitise a theme name into a safe filename slug.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'theme-' . bin2hex(random_bytes(4));
    }

    /**
     * List all saved presets. Returns an array of associative arrays,
     * each with: slug, name, scope, created_at.
     */
    public static function presets(): array
    {
        $dir = self::presetsDir();

        if (!is_dir($dir)) {
            return [];
        }

        $presets = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (!is_array($data) || empty($data['name'])) {
                continue;
            }

            $presets[] = [
                'slug'       => basename($file, '.json'),
                'name'       => $data['name'],
                'scope'      => $data['scope'] ?? self::SCOPE_SITE,
                'created_at' => $data['created_at'] ?? '',
            ];
        }

        usort($presets, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $presets;
    }

    /**
     * Load a saved preset by slug. Returns the full data array or null.
     */
    public static function loadPreset(string $slug): ?array
    {
        $file = self::presetsDir() . '/' . preg_replace('/[^a-z0-9\-]/', '', $slug) . '.json';

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Delete a saved preset by slug.
     */
    public static function deletePreset(string $slug): bool
    {
        $file = self::presetsDir() . '/' . preg_replace('/[^a-z0-9\-]/', '', $slug) . '.json';

        if (!is_file($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Apply a saved preset to a scope (overwrite scope's active theme).
     */
    public static function applyPreset(string $slug, string $scope): bool
    {
        $preset = self::loadPreset($slug);

        if (!$preset) {
            return false;
        }

        if (!empty($preset['colors'])) {
            self::save(array_merge(self::COLORS, array_intersect_key($preset['colors'], self::COLORS)), $scope);
        }

        if (!empty($preset['layout'])) {
            $layout = [];
            foreach (self::LAYOUT_DEFAULTS as $key => $definition) {
                $layout[$key] = $preset['layout'][$key] ?? $definition['value'];
            }
            self::saveLayout($layout, $scope);
        }

        if (isset($preset['title_image'])) {
            self::saveTitleImage($preset['title_image'], $scope);
        }

        return true;
    }
}
