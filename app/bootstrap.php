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