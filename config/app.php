<?php

return [
    // URL prefix the app is served under ('' when it's at the domain root).
    'base_path' => '/gallery',

    'site_name' => 'amethyst2213',

    // How many gallery grid items are rendered in the initial page response
    // before the rest is loaded on demand ("Load more"). Keeps the HTML
    // payload of very large galleries small.
    'gallery_page_size' => 48,

    // Guest-facing gallery-listing fragment cache (Redis, TTL 120s). Setting
    // this to false renders every listing live with zero caching.
    'page_cache' => true,

    'uploads' => [
        // Where uploaded originals, web variants and thumbnails are stored.
        'dir'          => __DIR__ . '/../storage/uploads',
        // Per-file upload ceiling: 10 GiB.
        'max_size'     => 10 * 1024 * 1024 * 1024,
        // Resumable chunked upload: any file at/above 'chunk_min' bytes is
        // sliced into 'chunk_size' byte parts and reassembled server-side, so
        // a 4-5 GB video uploads as many small fast requests instead of one
        // long request that the webserver/fastcgi timeouts would kill.
        'chunk_size'   => 16 * 1024 * 1024, // 16 MiB
        'chunk_min'    => 8 * 1024 * 1024,  // 8 MiB threshold to enable chunking
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

    // Bitrate ladder for member video playback. Each rung is only produced when
    // the source is larger than the rung's target box (see video_ladder_plan),
    // and every rung is a self-contained faststart MP4 so the player can offer
    // a plain quality menu without HLS. Rung order is by 'label' (ascending
    // resolution); 'prefix' is the on-disk variant prefix that StorageController
    // resolves for that size key.
    //
    // 'web' (720p) is also what uploads build synchronously - it is the size the
    // player falls back to - while the lo/hi rungs are built in the background
    // by bin/video_rendition_worker.php. 'maxrate'/'bufsize'/'ab' are ffmpeg
    // encoder strings, 'crf' is x264 quality (lower = better).
    'video_ladder' => [
        [
            'key'     => 'lo',
            'label'   => '480p',
            'prefix'  => 'weblo_',
            'max_w'   => 854,
            'max_h'   => 480,
            'crf'     => 30,
            'maxrate' => '1200k',
            'bufsize' => '2400k',
            'ab'      => '64k',
        ],
        [
            'key'     => 'mid',
            'label'   => '720p',
            'prefix'  => 'web_',
            'max_w'   => 1280,
            'max_h'   => 720,
            'crf'     => 28,
            'maxrate' => '4M',
            'bufsize' => '8M',
            'ab'      => '96k',
        ],
        [
            'key'     => 'hi',
            'label'   => '1080p',
            'prefix'  => 'webhi_',
            'max_w'   => 1920,
            'max_h'   => 1080,
            'crf'     => 24,
            'maxrate' => '6M',
            'bufsize' => '12M',
            'ab'      => '128k',
        ],
    ],

    'video_chapters' => [
        // Scene-detection chapter markers (bin/video_chapters_worker.php).
        'enabled'         => true,
        // Ask the configured AI backend for nicer chapter titles instead of
        // the default "Part N" placeholders. Off by default because every
        // title costs a model call.
        'ai_titles'       => false,
        // ffmpeg select='gt(scene,T)' threshold: lower finds more cuts.
        'scene_threshold' => 0.35,
        // Never place two chapters closer than this (seconds).
        'min_gap_seconds' => 10.0,
        // Cap stored chapters per video (VideoChapter::MAX_CHAPTERS).
        'max_chapters'    => 24,
    ],

    // A gallery whose title starts with one of these is treated as a TEST
    // gallery: it is never announced (no member email, no in-app bell, no
    // social auto-post). Working on the site means creating throwaway
    // galleries, and each one that went live mailed every opted-in member the
    // title of a set that was never meant to be seen. The admin form also has
    // an explicit "test gallery" tick, which is the reliable way; this list
    // is the safety net for galleries created by a script or an import.
    //
    // A pattern matches when the title starts with it and the next character
    // is not a letter, so "Test Gallery" matches but "Testament" does not.
    'gallery_test_patterns' => [
        'probe',
        'test',
        'testing',
        'smoketest',
        'tmp',
        'temp',
        'scratch',
        'fixture',
        'debug',
        'demo',
    ],

    'auth' => [
        // Brute-force protection: N failed logins per email/IP are allowed
        // within the window before the user is temporarily locked out.
        'login_max_attempts'   => 5,
        'login_window_seconds' => 900,
        // Idle session timeout. Admins get a shorter default window; checking
        // "remember me" at login extends it to admin_remember_seconds.
        'session_idle_seconds' => 43200,
        'admin_idle_seconds'   => 1800,
        'admin_remember_seconds' => 604800,
        'reset_rate_limit'     => 5,
        'verification_rate_limit' => 5,
        'recovery_rate_window_seconds' => 3600,
    ],
];
