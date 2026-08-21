<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

class StorageController extends Controller
{
    /**
     * Serve an uploaded file or generated variant. Thumbnails are public for
     * the login page; originals and web-sized variants require authentication.
     * Video playback is supported through HTTP Range requests (206 partial
     * content), which browsers require for seeking in large files.
     */
    public function serve(string $file): void
    {
        $name = basename($file);

        if ($name === '' || $name !== $file) {
            $this->notFound();
            return;
        }

        $size = (string) $this->request->query('size', '');

        // Thumbnails remain available on the guest login page, but originals
        // and web-sized variants require a paid membership.
        if ($size !== 'thumb') {
            Auth::requireSubscription();
        }

        if ($size === 'thumb') {
            $name = 'thumb_' . $name;
        } elseif ($size === 'web') {
            $name = 'web_' . $name;
        }

        $uploadsDir = config('app.uploads.dir');
        $path       = $uploadsDir . '/' . $name;

        // Video editor exports are stored under uploads/exports/ but served
        // through the same /files/ endpoint (e.g. video galleries built from
        // an export). Fall back to that subdirectory when not in the root.
        if (!is_file($path)) {
            $path = $uploadsDir . '/exports/' . $name;
        }

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        $mime = in_array($size, ['thumb', 'web'], true) ? $this->imageMimeOf($path) : $this->mimeFor($name);
        $len  = (int) filesize($path);

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : null;
            $end   = $m[2] !== '' ? (int) $m[2] : null;

            if ($start === null) {
                $start = max(0, $len - $end);
                $end   = $len - 1;
            } elseif ($end === null || $end >= $len) {
                $end = $len - 1;
            }

            if ($start > $end || $start >= $len) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $len);
                return;
            }

            $length = $end - $start + 1;

            header('HTTP/1.1 206 Partial Content');
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
            header('Content-Length: ' . $length);

            $fp = fopen($path, 'rb');

            if ($fp === false) {
                return;
            }

            fseek($fp, $start);
            $remaining = $length;

            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, (int) min(8192, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
            }

            fclose($fp);

            return;
        }

        header('Content-Length: ' . $len);
        readfile($path);
    }

    /**
     * Detect an image's real MIME type (for thumbnails, which are stored as
     * JPEG bytes even when the extension is a video's, e.g. mp4 thumbs).
     */
    private function imageMimeOf(string $path): string
    {
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            if ($mime !== false && strpos($mime, 'image/') === 0) {
                return $mime;
            }
        }

        $info = @getimagesize($path);

        if ($info !== false && isset($info['mime'])) {
            return $info['mime'];
        }

        return 'image/jpeg';
    }

    /**
     * Map a filename extension to its content type so files are streamed with
     * the correct header.
     */
    private function mimeFor(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }
        if ($extension === 'png') {
            return 'image/png';
        }
        if ($extension === 'gif') {
            return 'image/gif';
        }
        if ($extension === 'webp') {
            return 'image/webp';
        }
        if ($extension === 'mp4' || $extension === 'm4v') {
            return 'video/mp4';
        }
        if ($extension === 'webm') {
            return 'video/webm';
        }
        if ($extension === 'ogg') {
            return 'video/ogg';
        }
        if ($extension === 'mov') {
            return 'video/quicktime';
        }
        if ($extension === 'avi') {
            return 'video/x-msvideo';
        }
        if ($extension === 'mkv') {
            return 'video/x-matroska';
        }

        return 'application/octet-stream';
    }
}
