<?php

return [
    // URL prefix the app is served under ('' when it's at the domain root).
    'base_path' => '/gallery',

    'site_name' => 'amethyst2213',

    // How many gallery grid items are rendered in the initial page response
    // before the rest is loaded on demand ("Load more"). Keeps the HTML
    // payload of very large galleries small.
    'gallery_page_size' => 48,

    'uploads' => [
        // Where uploaded originals, web variants and thumbnails are stored.
        'dir'          => __DIR__ . '/../storage/uploads',
        // Per-file upload ceiling: 10 GiB.
        'max_size'     => 10 * 1024 * 1024 * 1024,
        // Accepted extensions, split into images (GD) and videos (ffmpeg).
        // Keep this in sync with the list of formats the server's GD build can
        // actually process (so uploads are never accepted then fail later
        // during thumbnail generation).
        'image_ext'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'avif', 'tiff'],
        'video_ext'    => ['mp4', 'webm', 'mov', 'm4v', 'ogg', 'avi', 'mkv', '3gp', '3g2', 'mpg', 'mpeg', 'wmv', 'flv', 'ts', 'mts', 'm2ts', 'vob', 'asf'],
        // Thumbnail dimensions; images are center-cropped to this ratio.
        'thumb_width'  => 400,
        'thumb_height' => 300,
        // Longest side of the "fast loading" web variant shown in galleries;
        // the original file is always kept at full size.
        'web_max_width' => 1600,
        // TTF font used to stamp text/watermarks onto images. Falls back to
        // GD's built-in fonts when the file is missing.
        'font_path'     => '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    ],

    'auth' => [
        // Brute-force protection: N failed logins per email/IP are allowed
        // within the window before the user is temporarily locked out.
        'login_max_attempts'   => 5,
        'login_window_seconds' => 900,
        'session_idle_seconds' => 43200,
        'reset_rate_limit'     => 5,
        'verification_rate_limit' => 5,
        'recovery_rate_window_seconds' => 3600,
    ],
];
