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

    /**
     * A URL that is safe to put in an href / src.
     *
     * A URL with a scheme must use one of the allowed schemes (https,
     * mailto); a URL without a scheme is a site-relative path and is kept.
     * javascript:, data:, file: and the protocol-relative //host are
     * refused.
     */
    public static function safeUrl(string $url, array $schemes = ['https', 'mailto']): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Protocol-relative: it points at another host.
        if (str_starts_with($url, '//')) {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            return $url;   // a site-relative path
        }
        if (!in_array($scheme, $schemes, true)) {
            return '';
        }
        // https:// needs a host, or it is just a scheme.
        if ($scheme === 'https' && (string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }
        return $url;
    }

    public static function update(mixed $displayName, mixed $tagline, mixed $bio, mixed $avatar, mixed $links): void
    {
        // The avatar and the links are rendered on the public creator page:
        // an arbitrary URL there is a tracking pixel for every visitor, and
        // an arbitrary scheme is a script injection. Both are filtered here,
        // so no caller can bypass it.
        $safeLinks = [];
        foreach ((array) $links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string) ($link['label'] ?? ''));
            $url   = self::safeUrl((string) ($link['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $safeLinks[] = ['label' => $label, 'url' => $url];
            }
        }

        self::save([
            'display_name' => trim((string) $displayName),
            'tagline'      => trim((string) $tagline),
            'bio'          => trim((string) $bio),
            'avatar'       => self::safeUrl((string) $avatar, ['https']),
            'links'        => $safeLinks,
        ]);
    }
}