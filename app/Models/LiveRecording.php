<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\AutoPostQueue;
use App\Models\Gallery;
use App\Models\Photo;

/**
 * Import a recorded live stream (a .ts file saved by scripts/live-record.sh
 * into storage/live-recordings/) as a new top-tier video gallery, exactly like
 * a freshly uploaded video, and auto-post it to X/Reddit.
 */
class LiveRecording
{
    private static function recordingsDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/live-recordings';
    }

    /**
     * Finalize the recording for a stream key. Returns the new gallery id, or
     * null when there was nothing to import (or it was too short to keep).
     * The .ts file is deleted once imported.
     */
    public static function finalize(string $streamKey): ?int
    {
        // The key may arrive url-encoded with the publish secret attached
        // (the operator app derives it from the RTMP url it was given), so
        // it is normalised before it is used as a file name.
        $streamKey = LiveSession::rawKeyFromUrl($streamKey);

        if (!preg_match('/^[A-Za-z0-9]+$/', $streamKey)) {
            return null;
        }

        // Single-flight per stream. /live/stop and the orphan importer can
        // both see the same .ts file; two ffmpeg runs then produce two MP4s
        // with the same bytes, and the second Photo::create() used to die on
        // photos.hash and leave an empty gallery. The loser returns null; the
        // winner still imports the recording and the session is marked ended.
        $lockPath = self::recordingsDir() . '/.finalize-' . $streamKey . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return null;
        }

        try {
            return self::finalizeLocked($streamKey);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The actual import; the caller must hold the per-stream finalize lock. */
    private static function finalizeLocked(string $streamKey): ?int
    {
        // Re-encoding a long recording can take many minutes. When finalize
        // runs from /live/stop the app's HTTP client gives up after 30s and
        // disconnects; without these, PHP would abort and kill ffmpeg mid-way.
        // Keep working regardless of client disconnect / execution time so the
        // recording always becomes a gallery.
        @ignore_user_abort(true);
        @set_time_limit(0);

        $file = self::recordingsDir() . '/' . $streamKey . '.ts';
        if (!is_file($file)) {
            return null;
        }

        // Drop tiny blips (test streams / accidental flashes).
        if ((int) filesize($file) < 100 * 1024) {
            @unlink($file);
            return null;
        }

        $config  = config('app.uploads');
        $mp4Name = 'live-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.mp4';
        $mp4     = $config['dir'] . '/' . $mp4Name;

        // Fast path: remux the MPEG-TS with -c copy. MediaMTX's HLS segments
        // start at keyframes, so the .ts carries clean video and the copy
        // produces a playable MP4 almost instantly (a full re-encode of a long
        // broadcast can take many minutes). Only fall back to re-encoding when
        // the copy loses the video stream (a recording that began mid-GOP).
        $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($file)
            . ' -map 0:v:0 -map 0:a:0? -c copy -movflags +faststart ' . escapeshellarg($mp4) . ' 2>&1';
        exec($cmd, $out, $rc);
        $ok = $rc === 0 && is_file($mp4) && (int) filesize($mp4) >= 100 * 1024
            && video_has_stream($mp4);

        if (!$ok) {
            // Re-encode to repair the stream (also keeps only the first
            // video/audio program, avoiding a doubled transcode when the
            // encoder config changed mid-broadcast).
            error_log('[live] copy remux produced no playable video; re-encoding');
            @unlink($mp4);
            $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($file)
                . ' -map 0:v:0 -map 0:a:0?'
                . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
                . ' -c:a aac -b:a 128k -movflags +faststart ' . escapeshellarg($mp4) . ' 2>&1';
            exec($cmd, $out, $rc);
            $ok = $rc === 0 && is_file($mp4) && (int) filesize($mp4) >= 100 * 1024
                && video_has_stream($mp4);
        }

        if (!$ok) {
            error_log('[live] finalize ffmpeg failed: ' . implode("\n", $out));
            @unlink($mp4);
            // Keep the .ts so a later run can retry - deleting the only copy of
            // a recording on a transient ffmpeg failure would lose the stream.
            return null;
        }

        create_video_thumbnail(
            $mp4,
            $config['dir'] . '/thumb_' . $mp4Name,
            $config['thumb_width'],
            $config['thumb_height']
        );

        $when = tzdate('Y-m-d H:i', gmdate('Y-m-d H:i:s'));
        $title = 'Live Stream ' . $when;

        // Create the photo and the gallery in one transaction. The old order
        // created the gallery first; when Photo::create() hit the UNIQUE hash
        // the request died and left an empty gallery behind.
        $db = \App\Core\Database::connection();
        $db->beginTransaction();
        try {
            $hash          = (string) sha1_file($mp4);
            $existingPhoto = Photo::findByHash($hash);
            $photoId       = Photo::create($mp4Name, $hash);
            if ($existingPhoto !== null) {
                // The same bytes were already imported: the gallery will use
                // the existing photo, so this second copy is dead weight.
                @unlink($mp4);
                @unlink($config['dir'] . '/thumb_' . $mp4Name);
            }
            $galleryId = Gallery::create($title, 'Recorded live on ' . $when, 'videos', 3, null, false);
            Gallery::attachPhoto($galleryId, $photoId);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($mp4);
            @unlink($config['dir'] . '/thumb_' . $mp4Name);
            throw $e;
        }

        // The recording has been imported: delete the source BEFORE the
        // auto-post enqueue, so a queue failure cannot leave the .ts behind
        // for the orphan importer to import a second time. A failed unlink is
        // logged (it means the directory is not writable by www-data, which
        // would make the importer retry the same file forever).
        if (!@unlink($file)) {
            error_log('[live] imported gallery ' . $galleryId . ' but could not delete ' . $file
                . ' (check storage/live-recordings ownership)');
        }
        \App\Core\Cache::bump('gallery');

        // Auto-post to X + Reddit like any new gallery. A queue failure must
        // not undo a successful import.
        try {
            AutoPostQueue::enqueueGalleryPosts($galleryId, null);
        } catch (\Throwable $e) {
            error_log('[live] auto-post enqueue failed for gallery ' . $galleryId . ': ' . $e->getMessage());
        }

        return $galleryId;
    }

    /**
     * Import any recordings left in the dir that belong to a live session
     * (used when the operator app crashed / disconnected without /live/stop).
     * Returns the number of galleries imported.
     */
    public static function importOrphans(): int
    {
        // Single-flight: an import can re-encode a long recording for many
        // minutes, and the 15-min cron could overlap with a previous run.
        // Without a lock two runs would both finalize the same .ts and create
        // duplicate galleries.
        $lockPath = self::recordingsDir() . '/.import.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return 0;
        }

        try {
            $imported = 0;

            foreach (glob(self::recordingsDir() . '/*.ts') ?: [] as $file) {
                $streamKey = basename($file, '.ts');
                if (!preg_match('/^[A-Za-z0-9]+$/', $streamKey)) {
                    continue;
                }

                // Only import recordings that belong to a live session (current or
                // just-ended); anything else is treated as abandoned junk.
                $row = Database::run(
                    'SELECT id FROM live_sessions WHERE stream_key = ? ORDER BY id DESC LIMIT 1',
                    [LiveSession::hashOf($streamKey)]
                )->fetch();

                if ($row === false) {
                    // No matching session: abandoned recording, clean it up.
                    @unlink($file);
                    continue;
                }

                // Never finalize a recording whose stream is still publishing in
                // MediaMTX: the .ts is incomplete and still growing, and importing
                // it would both create a gallery from a partial stream and delete
                // the file, losing the rest of the broadcast.
                if (LiveSession::isPublishing($streamKey)) {
                    continue;
                }

                $galleryId = self::finalize($streamKey);
                if ($galleryId !== null) {
                    LiveSession::markEnded((int) $row['id']);
                    $imported++;
                }
            }

            return $imported;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}