<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The gallery folder-import app's settings, stored on the site so they can be
 * edited from the gallery management page and pulled by the folder-import
 * workers (Windows box / Ubuntu). Persisted as JSON in the autoposter_settings
 * table (key "import_app").
 *
 * Shared settings (enabled, schedule, spacing, ...) apply to every machine.
 * The host/posted folders are stored PER MACHINE ("machines" map keyed by the
 * worker's machine name), so each box has its own local paths.
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
            'spacing_hours'    => 24,
            'min_level'        => 0,
            'description'      => '',
            'is_secret'        => false,
            'import_token'     => env_value('GALLERY_IMPORT_KEY', ''),
            'host_folder'      => '',
            'posted_folder'    => '',
            'machines'         => [],
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
     * The settings a given machine should use: the shared settings plus the
     * host/posted folders resolved for that machine (falling back to the
     * legacy shared host/posted when the machine has no entry).
     */
    public static function allForMachine(?string $machine): array
    {
        $settings = self::all();
        $machine  = trim((string) $machine);

        $host   = (string) ($settings['host_folder'] ?? '');
        $posted = (string) ($settings['posted_folder'] ?? '');

        if ($machine !== '' && is_array($settings['machines'] ?? null)) {
            $entry = $settings['machines'][$machine] ?? [];
            if (is_array($entry)) {
                if (!empty($entry['host_folder'])) {
                    $host = (string) $entry['host_folder'];
                }
                if (!empty($entry['posted_folder'])) {
                    $posted = (string) $entry['posted_folder'];
                }
            }
        }

        $settings['host_folder']   = $host;
        $settings['posted_folder'] = $posted;

        return $settings;
    }

    /**
     * Persist a set of settings (merged over current). Returns the saved array.
     * Pass $machine to store host/posted folders under that machine's entry.
     */
    public static function save(array $values, ?string $machine = null): array
    {
        $settings = self::all();

        if ($machine !== null && trim($machine) !== '') {
            // Per-machine host/posted: keep the shared settings, only touch the
            // machine's folder entry.
            $machines = is_array($settings['machines'] ?? null) ? $settings['machines'] : [];
            $entry    = is_array($machines[$machine] ?? null) ? $machines[$machine] : [];

            if (array_key_exists('host_folder', $values)) {
                $entry['host_folder'] = (string) ($values['host_folder'] ?? '');
            }
            if (array_key_exists('posted_folder', $values)) {
                $entry['posted_folder'] = (string) ($values['posted_folder'] ?? '');
            }
            $machines[$machine] = $entry;

            // Don't write the shared host/posted for a machine-targeted save.
            unset($values['host_folder'], $values['posted_folder']);
            $values['machines'] = $machines;
        }

        $settings = self::normalize(array_merge($settings, $values));

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

        $machines = [];
        foreach ((array) ($settings['machines'] ?? []) as $name => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $machines[(string) $name] = [
                'host_folder'   => (string) ($entry['host_folder'] ?? ''),
                'posted_folder' => (string) ($entry['posted_folder'] ?? ''),
            ];
        }
        $settings['machines'] = $machines;

        return $settings;
    }
}