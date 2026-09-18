<?php

$envFile = __DIR__ . '/../.env';
$env     = [];
if (is_readable($envFile)) {
    $contents = file_get_contents($envFile);
    if ($contents !== false) {
        // .env files in this project use shell-style comments. PHP's INI
        // parser only accepts semicolon comments, so strip full-line hashes
        // before parsing instead of losing all settings on a warning.
        $contents = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
        $env = parse_ini_string($contents, false, INI_SCANNER_RAW) ?: [];
    }
}

$value = static function (string $key, string $default = '') use ($env): string {
    $value = $env[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

return [
    // MySQL is used in production.
    'driver'   => 'mysql',
    'host'     => $value('GALLERY_DB_HOST', '127.0.0.1'),
    'port'     => (int) $value('GALLERY_DB_PORT', '3306'),
    'database' => $value('GALLERY_DB_NAME', 'gallery_mvc'),
    'username' => $value('GALLERY_DB_USER', 'gallery'),
    'password' => $value('GALLERY_DB_PASSWORD'),

    // SQLite alternative (set driver to 'sqlite'):
    'path'     => __DIR__ . '/../storage/gallery.sqlite',
];
