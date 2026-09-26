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
        // Re-encoding a long recording can take many minutes. When finalize
        // runs from /live/stop the app's HTTP client gives up after 30s and
        // disconnects; without these, PHP would abort and kill ffmpeg mid-way.
        // Keep working regardless of client disconnect / execution time so the
        // recording always becomes a gallery.
        @ignore_user_abort(true);
        @set_time_limit(0);

        if (!preg_match('/^[A-Za-z0-9]+$/', $streamKey)) {
            return null;
        }

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
            . ' -map 0:v:0 -map 0:a:0 -c copy -movflags +faststart ' . escapeshellarg($mp4) . ' 2>&1';
        exec($cmd, $out, $rc);
        $ok = $rc === 0 && is_file($mp4) && (int) filesize($mp4) >= 100 * 1024
            && self::hasVideoStream($mp4);

        if (!$ok) {
            // Re-encode to repair the stream (also keeps only the first
            // video/audio program, avoiding a doubled transcode when the
            // encoder config changed mid-broadcast).
            error_log('[live] copy remux produced no playable video; re-encoding');
            @unlink($mp4);
            $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($file)
                . ' -map 0:v:0 -map 0:a:0'
                . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
                . ' -c:a aac -b:a 128k -movflags +faststart ' . escapeshellarg($mp4) . ' 2>&1';
            exec($cmd, $out, $rc);
            $ok = $rc === 0 && is_file($mp4) && (int) filesize($mp4) >= 100 * 1024
                && self::hasVideoStream($mp4);
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

        $galleryId = Gallery::create($title, 'Recorded live on ' . $when, 'videos', 3, null, false);
        $photoId   = Photo::create($mp4Name, (string) sha1_file($mp4));
        Gallery::attachPhoto($galleryId, $photoId);

        // Auto-post to X + Reddit like any new gallery.
        AutoPostQueue::enqueueGalleryPosts($galleryId, null);

        @unlink($file);
        \App\Core\Cache::bump('gallery');

        return $galleryId;
    }

    /** Whether an mp4 actually contains a video stream (a -c copy remux can come
     *  out audio-only when the recording began mid-GOP). */
    private static function hasVideoStream(string $mp4): bool
    {
        $probe = 'ffprobe -v error -select_streams v:0 -show_entries stream=codec_type -of csv=p=0 '
            . escapeshellarg($mp4) . ' 2>&1';
        exec($probe, $pout, $prc);
        return $prc === 0 && trim(implode("\n", $pout)) === 'video';
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