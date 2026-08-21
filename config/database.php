<?php

$envFile = __DIR__ . '/../.env';
$env     = is_readable($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];

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
