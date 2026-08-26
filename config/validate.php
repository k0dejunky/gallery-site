<?php

/**
 * Validate startup configuration without ever including credential values in
 * the response or logs. Returns false instead of throwing so the front
 * controller can give web requests a generic maintenance response.
 */
return static function (): bool {
    $errors = [];
    $database = require __DIR__ . '/database.php';
    $required = ['driver'];

    if (($database['driver'] ?? '') === 'sqlite') {
        $required[] = 'path';
    } else {
        $required = array_merge($required, ['host', 'database', 'username', 'password']);
    }

    foreach ($required as $key) {
        if (!array_key_exists($key, $database) || trim((string) $database[$key]) === '') {
            $errors[] = 'database.' . $key . ' is missing';
        }
    }

    if (!in_array($database['driver'] ?? '', ['mysql', 'sqlite'], true)) {
        $errors[] = 'database.driver is invalid';
    }

    if (($database['driver'] ?? '') === 'mysql' && ((int) ($database['port'] ?? 0) < 1 || (int) ($database['port'] ?? 0) > 65535)) {
        $errors[] = 'database.port is invalid';
    }

    $environment = strtolower(env_value('APP_ENV', ''));
    if (in_array($environment, ['production', 'prod'], true)) {
        $debug = strtolower(env_value('APP_DEBUG', 'false'));
        if (in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
            $errors[] = 'APP_DEBUG must be disabled in production';
        }
        if (ini_get('display_errors')) {
            $errors[] = 'display_errors must be disabled in production';
        }
    }

    // These integrations are optional, but their absence is useful in logs.
    foreach (['APP_URL', 'PAYPAL_CLIENT_SECRET'] as $optional) {
        if (env_value($optional) === '') {
            error_log('[config] optional setting is not configured: ' . $optional);
        }
    }

    if ($errors !== []) {
        error_log('[config] invalid application configuration: ' . implode('; ', $errors));
        return false;
    }

    return true;
};
