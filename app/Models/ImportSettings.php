<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The gallery folder-import app's settings, stored on the site so they can be
 * edited from the gallery management page and pulled by the Windows app
 * (training PC) / Ubuntu importer. Persisted as JSON in the autoposter_settings
 * table (key "import_app") — the same key/value table the auto poster uses.
 */
class ImportSettings
{
    private const KEY   = 'import_app';
    private const TABLE = 'autoposter_settings';

    /**
     * Defaults. import_token defaults to the site's GALLERY_IMPORT_KEY so the
     * importer can authenticate out of the box.
     */
    public static function defaults(): array
    {
        return [
            'enabled'          => false,
            'schedule'         => '',
            'interval_minutes' => 0,
            'host_folder'      => '',
            'posted_folder'    => '',
            'import_token'     => env_value('GALLERY_IMPORT_KEY', ''),
            'spacing_hours'    => 24,
            'min_level'        => 0,
            'description'      => '',
            'is_secret'        => false,
        ];
    }

    /**
     * The effective import settings (defaults merged over whatever was saved).
     */
    public static function all(): array
    {
        $settings = self::defaults();

        $row = Database::run(
            'SELECT setting_value FROM ' . self::TABLE . ' WHERE setting_key = ?',
            [self::KEY]
        )->fetch();

        if ($row !== false) {
            $saved = json_decode((string) ($row['setting_value'] ?? ''), true);
            if (is_array($saved)) {
                $settings = array_merge($settings, $saved);
            }
        }

        return self::normalize($settings);
    }

    /**
     * Persist a set of settings (merged over current). Returns the saved array.
     */
    public static function save(array $values): array
    {
        $settings = self::normalize(array_merge(self::all(), $values));

        Database::run(
            'INSERT INTO ' . self::TABLE . ' (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [self::KEY, json_encode($settings, JSON_UNESCAPED_SLASHES)]
        );

        return $settings;
    }

    /**
     * Coerce the stored values into the right scalar types.
     */
    public static function normalize(array $settings): array
    {
        $settings['enabled']          = (bool) ($settings['enabled'] ?? false);
        $settings['is_secret']        = (bool) ($settings['is_secret'] ?? false);
        $settings['schedule']         = (string) ($settings['schedule'] ?? '');
        $settings['interval_minutes'] = max(0, (int) ($settings['interval_minutes'] ?? 0));
        $settings['host_folder']      = (string) ($settings['host_folder'] ?? '');
        $settings['posted_folder']    = (string) ($settings['posted_folder'] ?? '');
        $settings['import_token']     = (string) ($settings['import_token'] ?? '');
        $settings['spacing_hours']    = max(1, min(168, (int) ($settings['spacing_hours'] ?? 24)));
        $settings['min_level']        = max(0, min(3, (int) ($settings['min_level'] ?? 0)));
        $settings['description']      = (string) ($settings['description'] ?? '');

        return $settings;
    }
}