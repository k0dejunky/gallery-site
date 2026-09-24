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
    $isProd      = in_array($environment, ['production', 'prod'], true);

    if ($isProd) {
        $debug = strtolower(env_value('APP_DEBUG', 'false'));
        if (in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
            $errors[] = 'APP_DEBUG must be disabled in production';
        }

        // Originals/web variants are signed with this HMAC key; without it the
        // media gate fails closed (helpers.media_token_valid) and no protected
        // file can be served, so a production host must configure it.
        if (env_value('GALLERY_MEDIA_KEY') === '') {
            $errors[] = 'GALLERY_MEDIA_KEY must be set in production';
        }
    }

    // PHP warnings/notices must never be printed inline, regardless of
    // environment — they leak internals and corrupt HTML/JSON/SSE output.
    // index.php also forces display_errors off, but this guard catches a
    // php.ini override and any environment that skips index.php.
    if (PHP_SAPI !== 'cli' && ini_get('display_errors')) {
        $errors[] = 'display_errors must be disabled for web requests';
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
