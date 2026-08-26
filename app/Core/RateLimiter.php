<?php

namespace App\Core;

/** Small process-safe limiter whose state contains only key hashes and counts. */
class RateLimiter
{
    public static function allow(array $identifiers, int $limit, int $window): bool
    {
        $path = dirname(__DIR__, 2) . '/storage/security-rate-limit.json';
        $handle = @fopen($path, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return false;
        }

        $contents = stream_get_contents($handle);
        $state = is_string($contents) && $contents !== '' ? json_decode($contents, true) : [];
        $state = is_array($state) ? $state : [];
        $now = time();
        $state = array_filter($state, static fn ($entry): bool => is_array($entry) && (int) ($entry['expires'] ?? 0) > $now);
        $allowed = true;

        foreach ($identifiers as $identifier) {
            $key = hash('sha256', (string) $identifier);
            $entry = $state[$key] ?? ['count' => 0, 'expires' => $now + $window];
            if ((int) $entry['expires'] <= $now) $entry = ['count' => 0, 'expires' => $now + $window];
            if ((int) $entry['count'] >= $limit) $allowed = false;
            $entry['count']++;
            $state[$key] = $entry;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }
}
