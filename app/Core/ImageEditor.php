<?php

namespace App\Core;

/**
 * GD-based image editing used by the admin photo editor. Every operation
 * loads an image file, transforms it and saves it back in place (the same
 * format is preserved, including transparency for PNG/GIF/WEBP). Callers are
 * responsible for regenerating the web variant and thumbnail afterwards.
 */
class ImageEditor
{
    /**
     * Load a file into a GD resource plus its image type. Returns null when
     * the file is missing, unreadable or not a supported image.
     */
    public static function open(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $type = $info[2];

        $image = false;
        if ($type === IMAGETYPE_JPEG) {
            $image = @imagecreatefromjpeg($path);
        } elseif ($type === IMAGETYPE_PNG) {
            $image = @imagecreatefrompng($path);
        } elseif ($type === IMAGETYPE_GIF) {
            $image = @imagecreatefromgif($path);
        } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($path);
        }

        if ($image === false) {
            // Formats GD cannot open (HEIC/HEIF, TIFF, AVIF) are decoded via
            // ImageMagick when available, then handed to GD as pixels.
            if (class_exists('\\Imagick')) {
                $viaImagick = _load_image_imagick($path);

                if ($viaImagick !== null) {
                    return $viaImagick;
                }
            }

            return null;
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return [$image, $type];
    }

    /**
     * The configured TrueType font, or null when it is not installed.
     */
    private static function font(): ?string
    {
        $path = config('app.uploads.font_path');

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * Apply the EXIF orientation stored in a JPEG so subsequent edits work on
     * the upright pixels, then save in place (dropping the EXIF block, which
     * GD cannot rewrite). Idempotent: images without a rotation tag are left
     * untouched.
     */
    public static function normalize(string $path): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        if ($type !== IMAGETYPE_JPEG) {
            imagedestroy($image);

            return true;
        }

        $orientation = exif_orientation_of($path);

        if ($orientation <= 1) {
            imagedestroy($image);

            return true;
        }

        $image = apply_exif_orientation($image, $orientation);

        if ($image === false) {
            return false;
        }

        $ok = save_image($image, $path, IMAGETYPE_JPEG, 90);

        imagedestroy($image);

        return $ok;
    }

    /**
     * Blur with a Gaussian filter applied the given number of times
     * (iterations 1-3 map to light/medium/heavy).
     */
    public static function blur(string $path, int $iterations): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        for ($i = 0; $i < $iterations; $i++) {
            if (!@imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR)) {
                imagedestroy($image);

                return false;
            }
        }

        $ok = save_image($image, $path, $type, 90);

        imagedestroy($image);

        return $ok;
    }

    /**
     * Sharpen with a 3x3 convolution kernel applied the given number of times
     * (iterations 1-3 map to light/medium/heavy).
     */
    public static function sharpen(string $path, int $iterations): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $kernel = [[0, -1, 0], [-1, 5, -1], [0, -1, 0]];

        for ($i = 0; $i < $iterations; $i++) {
            if (!@imageconvolution($image, $kernel, 1, 0)) {
                imagedestroy($image);

                return false;
            }
        }

        $ok = save_image($image, $path, $type, 90);

        imagedestroy($image);

        return $ok;
    }

    /**
     * Scale the image down so its longest side is at most $maxDim, preserving
     * the aspect ratio. Images already within the limit are left untouched.
     */
    public static function resize(string $path, int $maxDim): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $width  = (int) imagesx($image);
        $height = (int) imagesy($image);

        if ($width <= $maxDim && $height <= $maxDim) {
            imagedestroy($image);

            return true;
        }

        $scale = min(1, $maxDim / max($width, $height));
        $dstW  = max(1, (int) round($width * $scale));
        $dstH  = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($dstW, $dstH);

        if (self::hasAlpha($type)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $dstW, $dstH, $width, $height);

        imagedestroy($image);

        $ok = save_image($resized, $path, $type, 90);

        imagedestroy($resized);

        return $ok;
    }

    /**
     * Rotate in place: 'left' (90 CCW), 'right' (90 CW) or 'flip' (180).
     */
    public static function rotate(string $path, string $direction): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $hasAlpha = self::hasAlpha($type);

        if ($hasAlpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $angle = $direction === 'left' ? 90 : ($direction === 'right' ? -90 : 180);
        $bg    = $hasAlpha ? imagecolorallocatealpha($image, 0, 0, 0, 127) : 0;

        $rotated = @imagerotate($image, $angle, $bg);

        imagedestroy($image);

        if ($rotated === false) {
            return false;
        }

        if ($hasAlpha) {
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
        }

        $ok = save_image($rotated, $path, $type, 90);

        imagedestroy($rotated);

        return $ok;
    }

    /**
     * Center-crop to a target aspect ratio (e.g. 1.5 for 3:2). The largest
     * crop with that ratio that fits inside the image is taken from its
     * center, then written back.
     */
    public static function cropRatio(string $path, float $ratio): bool
    {
        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $width  = (int) imagesx($image);
        $height = (int) imagesy($image);

        if ($width / $height > $ratio) {
            $cropW = max(1, (int) round($height * $ratio));
            $cropH = $height;
        } else {
            $cropW = $width;
            $cropH = max(1, (int) round($width / $ratio));
        }

        $srcX = (int) (($width - $cropW) / 2);
        $srcY = (int) (($height - $cropH) / 2);

        $cropped = imagecreatetruecolor($cropW, $cropH);

        if (self::hasAlpha($type)) {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
        }

        imagecopyresampled($cropped, $image, 0, 0, $srcX, $srcY, $cropW, $cropH, $cropW, $cropH);

        imagedestroy($image);

        $ok = save_image($cropped, $path, $type, 90);

        imagedestroy($cropped);

        return $ok;
    }

    /**
     * Stamp a single block of text onto the image at a corner or centered.
     * Colors are given as '#rrggbb'; position is one of top-left, top-right,
     * bottom-left, bottom-right or center.
     */
    public static function addText(string $path, string $text, int $size, string $position, string $hexColor): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $width  = (int) imagesx($image);
        $height = (int) imagesy($image);
        $pad    = max(10, (int) round($size * 0.5));
        $rgb    = self::hexRgb($hexColor);

        $font = self::font();

        if ($font !== null) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            $tw   = abs($bbox[4] - $bbox[0]);
            $th   = abs($bbox[5] - $bbox[1]);

            [$x, $y] = self::textOrigin($width, $height, $tw, $th, $pad, $position);

            $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);

            $ok = imagettftext($image, $size, 0, $x, $y, $color, $font, $text) !== false;
        } else {
            $tw = strlen($text) * imagefontwidth(5);
            $th = imagefontheight(5);

            [$x, $y] = self::bitmapOrigin($width, $height, $tw, $th, $pad, $position);

            $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);

            $ok = imagestring($image, 5, $x, $y, $text, $color);
        }

        if ($ok) {
            $ok = save_image($image, $path, $type, 90);
        }

        imagedestroy($image);

        return $ok;
    }

    /**
     * Stamp faint repeated text across the whole image (a watermark). The
     * opacity is 1-100 and controls how visible the text is.
     */
    public static function watermark(string $path, string $text, int $size, int $opacity): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        $opacity = max(1, min(100, $opacity));

        $opened = self::open($path);

        if ($opened === null) {
            return false;
        }

        [$image, $type] = $opened;

        $width  = (int) imagesx($image);
        $height = (int) imagesy($image);
        $alpha  = (int) round(127 * (1 - $opacity / 100));

        $font = self::font();

        if ($font !== null) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            $tw   = abs($bbox[4] - $bbox[0]);
            $th   = abs($bbox[5] - $bbox[1]);

            $color = imagecolorallocatealpha($image, 150, 150, 150, $alpha);
            $stepX = max($tw + $size, 120);
            $stepY = max(($th + (int) round($th * 0.7)), 60);

            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    imagettftext($image, $size, 0, $x, $y + (int) round($th * 0.8), $color, $font, $text);
                }
            }
        } else {
            $tw = strlen($text) * imagefontwidth(5);
            $th = imagefontheight(5);

            $color = imagecolorallocatealpha($image, 150, 150, 150, $alpha);
            $stepX = max($tw + 30, 80);
            $stepY = max($th * 3, 40);

            for ($y = 0; $y < $height; $y += $stepY) {
                for ($x = 0; $x < $width; $x += $stepX) {
                    imagestring($image, 5, $x, $y, $text, $color);
                }
            }
        }

        $ok = save_image($image, $path, $type, 90);

        imagedestroy($image);

        return $ok;
    }

    /**
     * Baseline origin (x, y) for a TrueType text block. For the bottom
     * positions the baseline sits on the padding line; top/center positions
     * approximate the ascent so the text does not hug the edge.
     */
    private static function textOrigin(int $width, int $height, int $tw, int $th, int $pad, string $position): array
    {
        $ascent = (int) round($th * 0.8);

        if (strpos($position, 'left') !== false) {
            $x = $pad;
        } elseif (strpos($position, 'right') !== false) {
            $x = $width - $pad - $tw;
        } else {
            $x = (int) (($width - $tw) / 2);
        }

        if (strpos($position, 'top') !== false) {
            $y = $pad + $ascent;
        } elseif (strpos($position, 'bottom') !== false) {
            $y = $height - $pad;
        } else {
            $y = (int) (($height - $th + $ascent) / 2);
        }

        return [$x, $y];
    }

    /**
     * Top-left origin (x, y) for the built-in bitmap font fallback.
     */
    private static function bitmapOrigin(int $width, int $height, int $tw, int $th, int $pad, string $position): array
    {
        if (strpos($position, 'left') !== false) {
            $x = $pad;
        } elseif (strpos($position, 'right') !== false) {
            $x = $width - $pad - $tw;
        } else {
            $x = (int) (($width - $tw) / 2);
        }

        if (strpos($position, 'top') !== false) {
            $y = $pad;
        } elseif (strpos($position, 'bottom') !== false) {
            $y = $height - $pad - $th;
        } else {
            $y = (int) (($height - $th) / 2);
        }

        return [$x, $y];
    }

    /**
     * Parse '#rrggbb' (or 'rrggbb') into [r, g, b], defaulting to white.
     */
    private static function hexRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            return [255, 255, 255];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Whether an image type can carry transparency (PNG, GIF, WEBP).
     */
    private static function hasAlpha(int $type): bool
    {
        return in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    }
}
