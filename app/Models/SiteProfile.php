<?php

namespace App\Models;

/**
 * Creator profile shown on the public /creator page and managed from the admin
 * Creator settings. Persisted as a tiny JSON file (storage/site_profile.json),
 * same pattern as SiteConfig, so it needs no DB migration and survives deploys.
 */
class SiteProfile
{
    public const FILE = '/storage/site_profile.json';

    private const DEFAULTS = [
        'display_name' => 'Amethyst',
        'tagline'      => '',
        'bio'          => '',
        'avatar'       => '',
        'links'        => [],
    ];

    public static function filePath(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    public static function all(): array
    {
        $path = self::filePath();
        if (!is_file($path)) {
            return self::DEFAULTS;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data)
            ? array_merge(self::DEFAULTS, $data)
            : self::DEFAULTS;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function save(array $values): void
    {
        $data = array_merge(self::all(), array_intersect_key($values, self::DEFAULTS));

        if (!is_array($data['links'])) {
            $data['links'] = [];
        }

        $data['links'] = array_values(array_filter(
            $data['links'],
            static fn (mixed $link): bool => is_array($link) && !empty($link['label']) && !empty($link['url'])
        ));

        file_put_contents(self::filePath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function update(mixed $displayName, mixed $tagline, mixed $bio, mixed $avatar, mixed $links): void
    {
        self::save([
            'display_name' => trim((string) $displayName),
            'tagline'      => trim((string) $tagline),
            'bio'          => trim((string) $bio),
            'avatar'       => trim((string) $avatar),
            'links'        => $links,
        ]);
    }
}