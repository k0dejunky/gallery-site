<?php

namespace App\Core;

/**
 * Read-only access to the current HTTP request: method, URL, input, files
 * and client IP. Centralising this keeps controllers free of superglobals.
 */
class Request
{
    /**
     * HTTP verb of the request, normalised to uppercase.
     */
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * The request path with the app's base path stripped, so routes are
     * declared without the install directory (e.g. /gallery/galleries -> /galleries).
     */
    public function uri(): string
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = (string) config('app.base_path');

        if ($base !== '' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }

        $uri = rtrim((string) $uri, '/');

        return $uri === '' ? '/' : $uri;
    }

    /**
     * Whether this is a POST request (form submission).
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Access submitted POST body, either the whole array or a single key.
     */
    public function post(?string $key = null, $default = null)
    {
        return $key === null ? $_POST : ($_POST[$key] ?? $default);
    }

    /**
     * Access URL query parameters, either the whole array or a single key.
     */
    public function query(?string $key = null, $default = null)
    {
        return $key === null ? $_GET : ($_GET[$key] ?? $default);
    }

    /**
     * Uploaded file entry for a field, or null when nothing was uploaded.
     */
    public function file(string $key): ?array
    {
        return isset($_FILES[$key]) && is_array($_FILES[$key]) ? $_FILES[$key] : null;
    }

    /**
     * Client IP address, used for login attempt throttling.
     */
    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Trimmed string value of a POST field with a fallback default.
     */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->post($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * Read a request header by name (case-insensitive). Returns the value
     * or null when the header is not present.
     */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        if ($value === null && strtoupper($name) === 'CONTENT-TYPE') {
            $value = $_SERVER['CONTENT_TYPE'] ?? null;
        }

        return is_string($value) ? $value : null;
    }
}
