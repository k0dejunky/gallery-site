<?php

/**
 * Shared bootstrap for every entry point (web front controller, CLI workers,
 * cron scripts, test runners). Consolidates the helpers require + PSR-4-style
 * autoloader that used to be copy-pasted across ~12 files, so a change to
 * autoloading or env handling is made in exactly one place.
 *
 * Usage: require __DIR__ . '/bootstrap.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/Core/helpers.php';

// Per-request CSP nonce (base64, 16 random bytes). Emitted by the layouts on
// script tags so the site can eventually move away from 'unsafe-inline'; the
// front controller uses it for the report-only CSP. CLI runs never need it.
if (!defined('CSP_NONCE')) {
    define('CSP_NONCE', base64_encode(random_bytes(16)));
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});