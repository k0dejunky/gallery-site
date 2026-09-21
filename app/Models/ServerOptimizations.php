<?php

namespace App\Models;

/**
 * Server optimization settings, adjustable by a super admin on the settings
 * page (/settings). Persisted as a JSON file under storage/ (gitignored),
 * mirroring SiteConfig/ChatSettings. The values are applied to the live
 * server by bin/apply_server_optimizations.php via a scoped-root helper.
 */
class ServerOptimizations
{
    private const FILE = '/storage/server_optimizations.json';

    private static function file(): string
    {
        return dirname(__DIR__, 2) . self::FILE;
    }

    /**
     * Default values (also the bootstrap values the apply script uses when
     * the settings file is missing).
     */
    public static function defaults(): array
    {
        return [
            'brotli_enabled'          => true,
            'compress_json'           => true,
            'opcache_revalidate_freq' => 60,
            'mysql_buffer_pool_gb'    => 2.0,
            'mysql_flush_log_trx'     => 1,
            'mysql_slow_query_log'    => true,
            'mysql_long_query_time'   => 1.0,
            'cache_category_ttl'      => 3600,
            'cache_listing_ttl'       => 60,
            'cache_recent_ttl'        => 60,
        ];
    }

    /**
     * Load settings, merging stored values over defaults and clamping each
     * value to its safe range so a bad file can never break the server.
     */
    public static function all(): array
    {
        $defaults = self::defaults();
        $data = [];

        $path = self::file();
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return self::clamp(array_merge($defaults, $data));
    }

    /** A single validated setting value. */
    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    /** Persist settings (values are clamped before storage). */
    public static function save(array $values): void
    {
        $path = self::file();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode(
            self::clamp(array_merge(self::all(), $values)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
    }

    /** Cache TTLs used by the application cache. */
    public static function cacheTtl(string $which): int
    {
        return (int) self::all()['cache_' . $which . '_ttl'] ?? 60;
    }

    /**
     * Clamp every value to its safe range. Unknown keys are dropped so a
     * hand-edited file cannot inject arbitrary server settings.
     */
    private static function clamp(array $values): array
    {
        $allowed = self::defaults();
        $out = [];

        foreach ($allowed as $key => $default) {
            if (!array_key_exists($key, $values)) {
                $out[$key] = $default;
                continue;
            }
            $v = $values[$key];
            switch ($key) {
                case 'brotli_enabled':
                case 'compress_json':
                case 'mysql_slow_query_log':
                    $out[$key] = (bool) $v;
                    break;
                case 'opcache_revalidate_freq':
                    $out[$key] = max(2, min(3600, (int) $v));
                    break;
                case 'mysql_buffer_pool_gb':
                    $out[$key] = max(0.5, min(8.0, (float) $v));
                    break;
                case 'mysql_flush_log_trx':
                    $out[$key] = ((int) $v === 2) ? 2 : 1;
                    break;
                case 'mysql_long_query_time':
                    $out[$key] = max(0.1, min(30.0, (float) $v));
                    break;
                case 'cache_category_ttl':
                    $out[$key] = max(60, min(86400, (int) $v));
                    break;
                case 'cache_listing_ttl':
                case 'cache_recent_ttl':
                    $out[$key] = max(15, min(3600, (int) $v));
                    break;
                default:
                    $out[$key] = $default;
            }
        }

        return $out;
    }
}