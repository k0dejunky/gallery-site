<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\MediaUploader;
use App\Core\Request;
use App\Models\Gallery;
use App\Models\ImportSettings;
use App\Models\Photo;
use DateTime;
use DateTimeZone;

/**
 * Machine-to-machine import endpoints for the gallery. The folder-import app
 * (Windows 7 box + Ubuntu) posts new galleries here with their media; the
 * server runs the same creation pipeline a web upload uses (Gallery::create
 * + MediaUploader) and returns the created gallery.
 *
 * Authenticated with the shared GALLERY_IMPORT_KEY Bearer token. No session,
 * no CSRF (all /webhooks/* are CSRF-exempt).
 */
class ImportController extends Controller
{
    private const SPACING_HOURS = 24;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        // Intentionally no Auth::requireLogin(): machine-to-machine.
    }

    /**
     * GET /webhooks/import/queue
     * Returns the scheduled gallery queue (future published_at, oldest first)
     * plus the next available publish slot: the last queued published_at plus
     * spacing_hours (default 24), or now + spacing_hours when the queue is
     * empty. The app posts new galleries using next_slot so every gallery is
     * exactly 24 hours after the last gallery-queue post.
     */
    public function queue(): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $spacing = max(1, min(168, (int) $this->request->query('spacing_hours', (string) self::SPACING_HOURS)));
        $rows    = Gallery::queuedForPublishing(true);

        $queue = [];
        $last  = null;
        foreach ($rows as $row) {
            $queue[] = [
                'gallery_id'   => (int) $row['id'],
                'title'        => (string) $row['title'],
                'type'         => (string) $row['type'],
                'min_level'    => (int) $row['min_level'],
                'published_at' => (string) $row['published_at'],
            ];
            if ($last === null || $row['published_at'] > $last) {
                $last = (string) $row['published_at'];
            }
        }

        $this->json([
            'ok'           => true,
            'queue'        => $queue,
            'next_slot'    => $this->slotAfter($last, $spacing),
            'count'        => count($queue),
            'spacing_hours' => $spacing,
        ]);
    }

    /**
     * GET /webhooks/import/settings?machine=<name>
     * Returns the import-app settings stored on the site (editable on the
     * gallery management page), with host/posted folders resolved for the
     * given machine. The Windows app / Ubuntu importer pull these on each run
     * so they can be configured from the web instead of on the box.
     */
    public function settings(): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $machine = trim((string) $this->request->query('machine', ''));
        $this->json(['ok' => true, 'settings' => ImportSettings::allForMachine($machine)]);
    }

    /**
     * POST /webhooks/import/gallery
     * Create a gallery the proper way and upload its media. Multipart fields:
     *   title, type (images|videos), published_at (UTC, optional → auto next
     *   slot), description, min_level, is_secret, files[].
     * Idempotent: a gallery with the same (title, type, published_at) is
     * returned instead of duplicated (safe retries after partial failures).
     */
    public function gallery(): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $title = trim((string) $this->request->post('title', ''));
        if ($title === '') {
            $this->json(['ok' => false, 'error' => 'title is required'], 422);
            return;
        }

        $type        = strtolower(trim((string) $this->request->post('type', 'images'))) === 'videos' ? 'videos' : 'images';
        $description = (string) $this->request->post('description', '');
        $minLevel    = max(0, min(3, (int) $this->request->post('min_level', '0')));
        $isSecret    = !empty($this->request->post('is_secret', '0'));
        $spacing     = max(1, min(168, (int) $this->request->post('spacing_hours', (string) self::SPACING_HOURS)));
        $publishedAt = $this->normalizePublishedAt((string) $this->request->post('published_at', ''));

        $files = $this->request->file('files');
        $hasFiles = $files !== null && is_array($files) && !empty($files['tmp_name'] ?? []);

        // Idempotency guard: a gallery with the same title+type already exists
        // (a re-run after a partial failure resumes it instead of duplicating).
        $existing = Gallery::findByTitleType($title, $type);
        if ($existing !== null) {
            $this->json([
                'ok'         => true,
                'existing'   => true,
                'gallery_id' => (int) $existing['id'],
                'url'        => url('/galleries/' . (int) $existing['id']),
                'uploaded'   => 0,
            ]);
            return;
        }

        // No explicit schedule → auto-fill the next available slot.
        if ($publishedAt === null) {
            $publishedAt = $this->slotAfter(Gallery::lastScheduledAt(), $spacing);
        }

        $galleryId = Gallery::create($title, $description, $type, $minLevel, $publishedAt, $isSecret);

        // Gallery-only create (the app uploads media via /{id}/files per-file).
        if (!$hasFiles) {
            $this->json([
                'ok'         => true,
                'gallery_id' => $galleryId,
                'url'        => url('/galleries/' . $galleryId),
                'uploaded'   => 0,
            ], 201);
            return;
        }

        $this->ingestFiles($galleryId, $type, $files, $title, true);
    }

    /**
     * POST /webhooks/import/gallery/{id}/files
     * Add one or more media files to an existing scheduled gallery. One file
     * (or a small batch) per request keeps each upload small and reliable.
     */
    public function files(int $galleryId): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            $this->json(['ok' => false, 'error' => 'gallery not found'], 404);
            return;
        }

        $type = (string) $gallery['type'];
        $files = $this->request->file('files');
        if ($files === null || !is_array($files) || empty($files['tmp_name'] ?? [])) {
            $this->json(['ok' => false, 'error' => 'no files uploaded (multipart field "files[]")'], 422);
            return;
        }

        $this->ingestFiles((int) $gallery['id'], $type, $files, (string) $gallery['title'], false);
    }

    /**
     * POST /webhooks/import/gallery/{id}/files/chunk
     * Accept one chunk (multipart field "chunk") of a resumable large-file
     * upload for an import gallery. Chunks are written as part-<index> files
     * under storage/uploads/pending/import/{gallery}/{upload_uid}/ so a failed
     * request can be retried (overwriting the same part) — this is what makes
     * multi-GB videos uploadable: many small fast requests instead of one long
     * request that the webserver/proxy timeouts would kill. The full file is
     * validated and attached in chunkComplete().
     */
    public function chunk(int $galleryId): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            $this->json(['ok' => false, 'error' => 'gallery not found'], 404);
            return;
        }

        $uid   = $this->sanitizeUid((string) $this->request->input('upload_uid', ''));
        $index = max(0, (int) $this->request->input('chunk_index', '-1'));
        $total = max(1, (int) $this->request->input('total_chunks', '0'));
        $chunk = $this->request->file('chunk');
        $tmp   = is_array($chunk['tmp_name'] ?? null) ? ($chunk['tmp_name'][0] ?? null) : ($chunk['tmp_name'] ?? null);
        $err   = is_array($chunk['error'] ?? null) ? ($chunk['error'][0] ?? UPLOAD_ERR_NO_FILE) : ($chunk['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uid === '' || $total > 200000 || $index >= $total || $tmp === null || $tmp === '' || $err !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'Invalid chunk parameters.'], 422);
            return;
        }

        $config     = config('app.uploads');
        $chunkSize  = (int) ($config['chunk_size'] ?? 0);
        $chunkBytes = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($chunkSize > 0 && $chunkBytes > $chunkSize) {
            $this->json(['ok' => false, 'error' => 'Chunk exceeds the configured chunk size.'], 422);
            return;
        }

        $parts = $this->chunksDir($galleryId, $uid);
        if (!is_dir($parts) && !@mkdir($parts, 0775, true)) {
            $this->json(['ok' => false, 'error' => 'Could not allocate upload space.']);
            return;
        }

        $partFile = $parts . '/part-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);

        if (!move_uploaded_file($tmp, $partFile)) {
            $this->json(['ok' => false, 'error' => 'Could not save chunk.']);
            return;
        }

        $this->json(['ok' => true, 'index' => $index]);
    }

    /**
     * POST /webhooks/import/gallery/{id}/files/chunk/complete
     * Reassemble all uploaded chunks for an upload identifier, then validate,
     * store and attach the file exactly like a direct upload (MediaUploader
     * pipeline: MIME sniff + ffprobe, variants, duration).
     */
    public function chunkComplete(int $galleryId): void
    {
        if (!$this->authorized()) {
            $this->deny();
            return;
        }

        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            $this->json(['ok' => false, 'error' => 'gallery not found'], 404);
            return;
        }

        $uid          = $this->sanitizeUid((string) $this->request->input('upload_uid', ''));
        $originalName = trim((string) $this->request->input('original_name', 'upload'));
        $totalChunks  = (int) $this->request->input('total_chunks', '1');
        $config       = config('app.uploads');
        $type         = (string) $gallery['type'];

        if ($uid === '' || $totalChunks < 1 || $totalChunks > 200000) {
            $this->json(['ok' => false, 'error' => 'Invalid upload parameters.'], 422);
            return;
        }

        $assembled = $this->reassembleChunks($galleryId, $uid, $totalChunks);
        if ($assembled === null) {
            $this->json(['ok' => false, 'error' => 'Upload is incomplete. Some chunks are missing; please retry.'], 409);
            return;
        }

        $files = [
            'name'     => [$originalName],
            'tmp_name' => [$assembled],
            'size'     => [(int) filesize($assembled)],
            'error'    => [UPLOAD_ERR_OK],
        ];

        $meta = MediaUploader::inspect($files, 0, $config, $type);
        if ($meta === null) {
            @unlink($assembled);
            $this->removeChunks($galleryId, $uid);
            $this->json(['ok' => false, 'error' => $originalName . ': ' . (MediaUploader::error() ?? 'invalid file')], 422);
            return;
        }

        $filename = uniqid('g', true) . '.' . $meta['extension'];
        $dest     = $config['dir'] . '/' . $filename;

        if (!@rename($assembled, $dest)) {
            @unlink($assembled);
            $this->removeChunks($galleryId, $uid);
            $this->json(['ok' => false, 'error' => $originalName . ': could not be saved.']);
            return;
        }

        set_time_limit(0);

        if (!MediaUploader::generateVariants($dest, $meta['is_image'], $config)) {
            foreach ([$dest, $config['dir'] . '/web_' . $filename, $config['dir'] . '/thumb_' . $filename] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            $this->removeChunks($galleryId, $uid);
            $this->json(['ok' => false, 'error' => $originalName . ': could not generate a preview.'], 422);
            return;
        }

        if (!$meta['is_image']) {
            // Commit the video first, then run the expensive faststart remux +
            // web rendition in a detached background process. A multi-GB video
            // must not hold this request open (proxy timeouts would kill it and
            // the import would stall); the gallery streams the original until
            // the web_ rendition is ready.
            $photoId = MediaUploader::commit($galleryId, $filename, $meta['hash']);
            MediaUploader::finalizeVideoAsync($dest);
        } else {
            $photoId = MediaUploader::commit($galleryId, $filename, $meta['hash']);
        }

        $this->removeChunks($galleryId, $uid);

        $this->json([
            'ok'         => true,
            'photo_id'   => $photoId,
            'gallery_id' => $galleryId,
            'url'        => url('/galleries/' . $galleryId),
        ], 201);
    }

    private function sanitizeUid(string $uid): string
    {
        $uid = trim($uid);
        if (strlen($uid) > 128) {
            $uid = substr($uid, 0, 128);
        }

        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $uid) ?: '';
    }

    private function chunksDir(int $galleryId, string $uid): string
    {
        return config('app.uploads.dir') . '/pending/import/' . $galleryId . '/' . $uid;
    }

    private function reassembleChunks(int $galleryId, string $uid, int $totalChunks): ?string
    {
        $parts = $this->chunksDir($galleryId, $uid);
        $out   = $parts . '/.assembled';

        $fh = @fopen($out, 'wb');
        if ($fh === false) {
            return null;
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $part = $parts . '/part-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (!is_file($part)) {
                fclose($fh);
                @unlink($out);
                return null;
            }
            $in = @fopen($part, 'rb');
            if ($in === false) {
                fclose($fh);
                @unlink($out);
                return null;
            }
            stream_copy_to_stream($in, $fh);
            fclose($in);
        }

        fclose($fh);

        return $out;
    }

    private function removeChunks(int $galleryId, string $uid): void
    {
        $parts = $this->chunksDir($galleryId, $uid);

        if (!is_dir($parts)) {
            return;
        }

        foreach (array_merge(glob($parts . '/*') ?: [], glob($parts . '/.*') ?: []) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($parts);
        @rmdir(dirname($parts));
    }

    /**
     * Validate, store and attach a set of uploaded files to a gallery via the
     * standard MediaUploader pipeline. When $rollbackEmpty is true and nothing
     * imports, the gallery is removed so no empty scheduled gallery is left.
     */
    private function ingestFiles(int $galleryId, string $type, array $files, string $title, bool $rollbackEmpty): void
    {
        $config    = config('app.uploads');
        $photoIds  = [];
        $uploaded  = 0;
        $errors    = [];

        try {
            $count = count($files['tmp_name']);
            for ($i = 0; $i < $count; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'file #' . ($i + 1) . ': upload error ' . (string) ($files['error'][$i] ?? 'unknown');
                    continue;
                }

                $meta = MediaUploader::inspect($files, $i, $config, $type);
                if ($meta === null) {
                    $errors[] = 'file #' . ($i + 1) . ': ' . (MediaUploader::error() ?? 'invalid file');
                    continue;
                }

                $filename = uniqid('g', true) . '.' . (string) $meta['extension'];
                $dest     = $config['dir'] . '/' . $filename;

                if (!MediaUploader::saveFinal((string) $files['tmp_name'][$i], $dest, (bool) $meta['is_image'], $config)) {
                    $errors[] = 'file #' . ($i + 1) . ': ' . (MediaUploader::error() ?? 'could not save');
                    continue;
                }

                $photoIds[] = MediaUploader::commit($galleryId, $filename, (string) $meta['hash']);
                $uploaded++;
            }
        } catch (\Throwable $e) {
            $errors[] = 'exception: ' . $e->getMessage();
        }

        // Nothing imported: remove the empty scheduled gallery + orphan photos.
        if ($uploaded === 0 && $rollbackEmpty) {
            foreach ($photoIds as $pid) {
                Photo::deleteIfOrphan((int) $pid);
            }
            Gallery::softDelete($galleryId);
            $this->json([
                'ok'        => false,
                'error'     => implode('; ', $errors) ?: 'no files imported',
                'uploaded'  => 0,
            ], 422);
            return;
        }

        if ($errors !== []) {
            // Partial import: keep the gallery + imported files, surface errors.
            $this->json([
                'ok'         => true,
                'partial'    => true,
                'gallery_id' => $galleryId,
                'url'        => url('/galleries/' . $galleryId),
                'uploaded'   => $uploaded,
                'errors'     => $errors,
            ], 201);
            return;
        }

        $this->json([
            'ok'         => true,
            'gallery_id' => $galleryId,
            'url'        => url('/galleries/' . $galleryId),
            'uploaded'   => $uploaded,
        ], 201);
    }

    private function normalizePublishedAt(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($dt === false) {
            try {
                $dt = new DateTime($value, new DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $dt->format('Y-m-d H:i:s');
    }

    private function slotAfter(?string $lastUtc, int $spacingHours): string
    {
        $base = new DateTime('now', new DateTimeZone('UTC'));
        if ($lastUtc !== null && $lastUtc !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $lastUtc, new DateTimeZone('UTC'));
            if ($parsed !== false) {
                $base = $parsed;
            }
        }

        $base->modify('+' . $spacingHours . ' hours');

        return $base->format('Y-m-d H:i:s');
    }

    private function presentedToken(): string
    {
        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }

        return $given;
    }

    private function authorized(): bool
    {
        $expected = env_value('GALLERY_IMPORT_KEY', '');
        $given    = $this->presentedToken();

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    private function deny(): void
    {
        $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}