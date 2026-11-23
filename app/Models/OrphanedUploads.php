<?php

namespace App\Models;

use App\Core\Cache;
use App\Core\Database;

/**
 * Files under storage/uploads that nothing refers to.
 *
 * There are three kinds, and they used to live in three different states of
 * visibility:
 *
 *   root       the uploads folder itself: an original (or a derivative whose
 *              source is gone) with no photos row. The System page reported
 *              these.
 *   previews   the public SEO sample clips (previews/preview_<stem>.mp4).
 *              Photo::deleteIfOrphan() never looked in that subdirectory and
 *              the System scan skipped directories, so they were invisible.
 *   chat       chat attachments whose message row is gone. Also invisible.
 *
 * ONE scan for the admin page and bin/cleanup_orphan_uploads.php, so the two
 * can never disagree about what is deletable.
 */
class OrphanedUploads
{
    /** Cached scan (the root alone is ~47k stat() calls). */
    public const CACHE_KEY = 'uploads.orphans.v1';
    public const CACHE_TTL = 120;

    /**
     * The current orphan list, newest cache first.
     *
     * @return array<int, array{name:string,source:string,size:int,type:string,mtime:int}>
     */
    public static function scan(): array
    {
        $json = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static function (): string {
            return json_encode(self::scanUncached(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        });

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Drop the cached scan (after a delete). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The uncached scan.
     *
     * @return array<int, array{name:string,source:string,size:int,type:string,mtime:int}>
     */
    public static function scanUncached(): array
    {
        $uploads = self::uploadsDir();

        if ($uploads === '' || !is_dir($uploads)) {
            return [];
        }

        $known = array_flip((array) Database::run('SELECT filename FROM photos')->fetchAll(\PDO::FETCH_COLUMN));
        $stems = [];

        foreach (array_keys($known) as $name) {
            $stems[pathinfo((string) $name, PATHINFO_FILENAME)] = true;
        }

        // Uploaded title images (the site logo) are live files with no photo
        // row. Keep the ones a theme or preset points at; an old, unreferenced
        // one is still a genuine orphan.
        $themeFiles = Theme::referencedTitleImages();

        $out = [];

        // 1. The uploads root.
        foreach (scandir($uploads) ?: [] as $file) {
            if ($file === '.' || $file === '..' || $file === 'pending' || $file === 'exports') {
                continue;
            }

            $path = $uploads . '/' . $file;
            if (!is_file($path) || isset($themeFiles[$file])) {
                continue;
            }

            // `web_abc.mp4` -> `abc.mp4`; `blur_thumb_abc.webp` -> `abc.webp`.
            // A derivative is live when its base - or the stem of its base -
            // is a photo, whatever prefix or extension it carries.
            $base = media_derivative_base($file);
            if (isset($known[$base]) || isset($stems[pathinfo($base, PATHINFO_FILENAME)])) {
                continue;
            }

            $out[] = self::row($file, 'root', $path);
        }

        // 2. The SEO sample clips: previews/preview_<stem>.mp4.
        foreach (glob($uploads . '/previews/*.mp4') ?: [] as $path) {
            $file = basename($path);
            if (!str_starts_with($file, 'preview_')) {
                continue;
            }

            $stem = pathinfo(substr($file, strlen('preview_')), PATHINFO_FILENAME);
            if ($stem === '' || isset($stems[$stem])) {
                continue;
            }

            $out[] = self::row('previews/' . $file, 'previews', $path);
        }

        // 3. Chat attachments whose message row is gone.
        $knownChat = [];
        foreach (Database::run(
            "SELECT attachment_path FROM chat_messages WHERE attachment_path IS NOT NULL AND attachment_path <> ''"
        )->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            $knownChat[basename((string) $path)] = true;
        }

        foreach (glob($uploads . '/chat/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue; // skips chat/thumbs/ and any other subdirectory
            }

            $file = basename($path);
            if (isset($knownChat[$file])) {
                continue;
            }

            $out[] = self::row('chat/' . $file, 'chat', $path);
        }

        usort($out, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $out;
    }

    /**
     * Delete the named orphans. Returns how many files were removed.
     *
     * @param array<int, string> $names
     */
    public static function delete(array $names): int
    {
        $removed = 0;

        foreach ($names as $name) {
            $path = self::resolve((string) $name);
            if ($path !== null && is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        self::forget();

        return $removed;
    }

    /**
     * The on-disk path for a listed orphan, or null when the name is not one
     * (or tries to escape the uploads folder). Used by the preview endpoint so
     * it can only ever serve files the page is showing.
     */
    public static function viewPath(string $name): ?string
    {
        $path = self::resolve($name);

        if ($path === null || !is_file($path)) {
            return null;
        }

        foreach (self::scan() as $row) {
            if ($row['name'] === $name) {
                return $path;
            }
        }

        return null;
    }

    /** image / video / other, for the grid. */
    public static function classify(string $path): string
    {
        $mime = \sniff_mime($path);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'other';
    }

    /** Resolve a relative name under uploads, refusing traversal. */
    private static function resolve(string $name): ?string
    {
        $name = str_replace('\\', '/', trim($name));

        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
            return null;
        }

        $uploads = self::uploadsDir();
        if ($uploads === '') {
            return null;
        }

        $base = realpath($uploads);
        $path = realpath($uploads . '/' . $name);

        if ($base === false || $path === false) {
            return null;
        }

        // Must be strictly inside uploads (never uploads itself).
        if (strncmp($path, $base . '/', strlen($base) + 1) !== 0) {
            return null;
        }

        return $path;
    }

    /**
     * @return array{name:string,source:string,size:int,type:string,mtime:int}
     */
    private static function row(string $name, string $source, string $path): array
    {
        return [
            'name'   => $name,
            'source' => $source,
            'size'   => (int) @filesize($path),
            'type'   => self::classify($path),
            'mtime'  => (int) @filemtime($path),
        ];
    }

    private static function uploadsDir(): string
    {
        $config = \config('app.uploads');

        return rtrim((string) ($config['dir'] ?? ''), '/');
    }
}
