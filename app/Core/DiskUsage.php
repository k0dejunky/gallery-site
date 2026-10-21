<?php

declare(strict_types=1);

namespace App\Core;



/**
 * The disk breakdown on the admin dashboard.
 *
 * The dashboard used to walk the whole storage tree on every load: a
 * filesize() for each of the ~6,800 photos, a glob plus a filesize for
 * each of the ~15,600 web_ and ~22,000 thumb_ variants, and FOURTEEN
 * recursive directory walks over 47,000 files. strace counted 280,000
 * stat() calls - 90% of the page's time, 3.4 to 4.0 seconds, of which
 * 3.7 to 4.0 seconds was FPM CPU. In CLI the same code takes 0.36
 * seconds, which is exactly why the cost was invisible locally.
 *
 * The breakdown is a slow-moving figure, so it is COMPUTED IN THE
 * BACKGROUND (the housekeeping does it every 15 minutes) and the
 * dashboard reads the cached copy. The dashboard never walks the disk.
 */
class DiskUsage
{
    /** Where the cached breakdown lives. */
    public static function cacheFile(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/disk-breakdown.json';
    }

    /**
     * The cached breakdown, or null when there is not one yet.
     */
    public static function cached(): ?array
    {
        $file = self::cacheFile();

        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        if (!is_array($decoded) || !isset($decoded['breakdown'])) {
            return null;
        }

        $decoded['age_sec'] = max(0, time() - (int) ($decoded['at'] ?? 0));

        return $decoded;
    }

    /**
     * Compute the breakdown and cache it. This is the expensive one, and
     * it must never run in a request.
     */
    public static function refresh(): ?array
    {
        $breakdown = self::compute();

        @file_put_contents(
            self::cacheFile(),
            json_encode(['at' => time(), 'breakdown' => $breakdown]) . "\n",
            LOCK_EX
        );

        return $breakdown;
    }

    /**
     * The total and free space, which are cheap (df), for a dashboard that
     * has no cached breakdown yet.
     */
    public static function quick(): array
    {
        $root = dirname(__DIR__, 2);
        $total = @disk_total_space($root);
        $free  = @disk_free_space($root);

        return ['total' => $total === false ? 0 : $total, 'free' => $free === false ? 0 : $free];
    }

    public static function compute(): array
    {
        $root  = dirname(__DIR__, 2);
        $total = @disk_total_space($root);
        $free  = @disk_free_space($root);

        if ($total === false || $free === false) {
            return ['total' => 0, 'free' => 0, 'images' => 0, 'videos' => 0, 'web' => 0, 'thumb' => 0,
                    'exports' => 0, 'pending' => 0, 'chat' => 0, 'previews' => 0, 'orphanThumbs' => 0,
                    'logs' => 0, 'testruns' => 0, 'config' => 0, 'misc' => 0,
                    'backups' => 0, 'db' => 0, 'ai' => 0, 'os' => 0];
        }

        $uploads = (string) config('app.uploads.dir');
        $images  = 0;
        $videos  = 0;

        foreach (Database::run('SELECT filename, is_video FROM photos')->fetchAll() as $photo) {
            $size = @filesize($uploads . '/' . $photo['filename']);
            if ($size === false) {
                continue;
            }
            if ((int) $photo['is_video'] === 1) {
                $videos += $size;
            } else {
                $images += $size;
            }
        }

        // Generated photo variants live in the uploads root as thumb_/web_
        // prefixed files (plus their .webp copies) — a separate slice each.
        $prefixBytes = static function (string $dir, string $prefix): float {
            $total = 0.0;
            foreach (glob($dir . '/' . $prefix . '*') ?: [] as $file) {
                if (is_file($file)) {
                    $total += (float) filesize($file);
                }
            }
            return $total;
        };
        $web   = $prefixBytes($uploads, 'web_');
        $thumb = $prefixBytes($uploads, 'thumb_');

        // The backup archive directory (.tar.gz chunks and .sql.gz dumps).
        $backupDir = $root . '/storage/backups';
        $backups   = is_dir($backupDir)
            ? (float) array_sum(array_map(
                static fn (string $file): int => (int) @filesize($file),
                glob($backupDir . '/*') ?: []
            ))
            : 0.0;

        $dbName = (string) (config('database.database') ?: '');

        $db = 0;
        if ($dbName !== '') {
            // Same cached reader as the System page (see Database::tableSizes).
            $db = (float) Database::tableSizeBytes($dbName);
        }

        // AI storage: the self-hosted Ollama model library (base + fine-tuned
        // GGUF models) plus the site's uploaded LoRA adapters. Falls back to
        // the Ollama /api/tags sizes when the model dir is not readable by the
        // web process, so the pie never silently shows 0.
        $trainingBytes = self::dirSize($root . '/storage/training');
        $ai = self::aiStorageBytes() + $trainingBytes;

        // Each distinct storage area gets its own pie slice.
        $storage = $root . '/storage';
        $exports      = self::dirSize($uploads . '/exports');      // video project exports
        $pending      = self::dirSize($uploads . '/pending');      // staged uploads
        $chat         = self::dirSize($uploads . '/chat');         // chat attachment media
        $previews     = self::dirSize($uploads . '/previews');     // public video sample clips
        $orphanThumbs = self::dirSize($uploads . '/orphan-thumbs'); // orphan viewer cache
        $logs         = self::dirSize($storage . '/logs');
        $testruns     = self::dirSize($storage . '/testruns');
        $config       = self::dirSize($storage . '/cron')
                      + self::dirSize($storage . '/scripts')
                      + self::dirSize($storage . '/cache')
                      + self::dirSize($storage . '/mail-outbox')
                      + self::dirSize($storage . '/themes')
                      + self::dirSize($storage . '/live-recordings')
                      + self::dirSize($storage . '/tmp');

        // Root-level config/state JSON files in storage (site_config.json,
        // autoposter.json, emailer.json, chat.json, theme files, ...).
        foreach (glob($storage . '/*.json') ?: [] as $file) {
            if (is_file($file)) {
                $config += (float) filesize($file);
            }
        }

        // Safety net: any storage bytes not attributed above (unknown dirs or
        // stray files) so the pie always reconciles back to the real totals.
        $named = $images + $videos + $web + $thumb + $exports + $pending + $chat
               + $previews + $orphanThumbs + $backups + $logs + $testruns
               + $config + $trainingBytes;
        $misc  = max(0, self::dirSize($storage) - $named);

        $used = $total - $free;
        $os   = max(0, $used - $images - $videos - $web - $thumb - $exports - $pending
                      - $chat - $previews - $orphanThumbs - $backups - $logs - $testruns
                      - $config - $misc - $db - $ai);

        return [
            'total'        => (float) $total,
            'free'         => (float) $free,
            'images'       => (float) $images,
            'videos'       => (float) $videos,
            'web'          => $web,
            'thumb'        => $thumb,
            'exports'      => $exports,
            'pending'      => $pending,
            'chat'         => $chat,
            'previews'     => $previews,
            'orphanThumbs' => $orphanThumbs,
            'logs'         => $logs,
            'testruns'     => $testruns,
            'config'       => $config,
            'misc'         => $misc,
            'backups'      => $backups,
            'db'           => (float) $db,
            'ai'           => (float) $ai,
            'os'           => (float) $os,
        ];
    }

    /**
     * The size of a directory tree, in bytes.
     */
    public static function dirSize(string $dir): float
    {
        if (!@is_dir($dir)) {
            return 0.0;
        }

        $size = 0.0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += (float) $file->getSize();
                }
            }
        } catch (\Throwable $error) {
            return 0.0;
        }

        return $size;
    }

    /**
     * The Ollama model library directory.
     */
    public static function ollamaModelsDir(): string
    {
        $home = (string) env_value('OLLAMA_HOME', '');
        if ($home === '') {
            $home = @is_dir('/usr/share/ollama/.ollama') ? '/usr/share/ollama/.ollama' : '/root/.ollama';
        }

        return $home . '/models';
    }

    /**
     * Ollama model library size in bytes. Prefers summing the model dir;
     * when that is not readable (permissions), falls back to the sum of
     * model sizes reported by the Ollama /api/tags endpoint.
     */
    public static function aiStorageBytes(): float
    {
        $size = self::dirSize(self::ollamaModelsDir());
        if ($size > 0) {
            return $size;
        }

        $url = rtrim((string) env_value('OLLAMA_URL', 'http://127.0.0.1:11434'), '/') . '/api/tags';
        [$status, , $body] = \App\Models\Http::request($url, ['method' => 'GET', 'timeout' => 5]);
        if ($status < 200 || $status >= 300) {
            return 0.0;
        }

        $data = json_decode((string) $body, true);
        $total = 0.0;
        foreach (($data['models'] ?? []) as $model) {
            $total += (float) ($model['size'] ?? 0);
        }

        return $total;
    }
}
