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

        // MPEG-TS -> MP4. Re-encode (not -c copy): the recorder starts mid-stream, so
        // the TS can carry packets that a copy remux drops (the MP4 ends up
        // audio-only). Re-encoding repairs it and guarantees a playable video.
        $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($file)
            . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p'
            . ' -c:a aac -b:a 128k -movflags +faststart ' . escapeshellarg($mp4) . ' 2>&1';
        exec($cmd, $out, $rc);

        if ($rc !== 0 || !is_file($mp4) || (int) filesize($mp4) < 100 * 1024) {
            error_log('[live] finalize ffmpeg failed: ' . implode("\n", $out));
            @unlink($file);
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

    /**
     * Import any recordings left in the dir that belong to a live session
     * (used when the operator app crashed / disconnected without /live/stop).
     * Returns the number of galleries imported.
     */
    public static function importOrphans(): int
    {
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

            $galleryId = self::finalize($streamKey);
            if ($galleryId !== null) {
                LiveSession::markEnded((int) $row['id']);
                $imported++;
            }
        }

        return $imported;
    }
}