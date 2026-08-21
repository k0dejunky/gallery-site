<?php

namespace App\Models;

/**
 * Minimal cURL HTTP helper used by the Auto Poster's API clients. Returns
 * [status, headers, body] for a request so callers can inspect the response
 * without a full HTTP client library.
 */
class Http
{
    /**
     * Perform an HTTP request. $options supports:
     *   - method:   GET (default) or POST
     *   - headers:  associative array of header name => value
     *   - form:     associative array sent as application/x-www-form-urlencoded
     *   - json:     array/associative sent as application/json
     *   - body:     raw string body (overrides form/json)
     *   - timeout:  seconds (default 30)
     * Returns [status:int, headers:array, body:string].
     */
    public static function request(string $url, array $options = []): array
    {
        $method  = strtoupper($options['method'] ?? 'GET');
        $headers = $options['headers'] ?? [];
        $timeout = (int) ($options['timeout'] ?? 30);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'gallery-mvc/1.0 (auto poster)',
        ]);

        $headerLines = [];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (isset($options['form'])) {
            $body         = http_build_query($options['form']);
            $headers[]    = 'Content-Type: application/x-www-form-urlencoded';
            $headers[]    = 'Content-Length: ' . strlen($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (isset($options['json'])) {
            $body         = json_encode($options['json']);
            $headers[]    = 'Content-Type: application/json';
            $headers[]    = 'Content-Length: ' . strlen($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (isset($options['body'])) {
            $headers[]    = 'Content-Length: ' . strlen($options['body']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }

        $headerStr = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headerStr, &$headerLines) {
            $headerStr .= $line;
            $trim = trim($line);
            if ($trim !== '' && strpos($trim, ':') !== false) {
                [$name, $value] = explode(':', $trim, 2);
                $headerLines[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        });

        // Add remaining headers as curl header array.
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $body = '';
            $status = 0;
        } else {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);
        }

        curl_close($ch);

        return [$status, $headerLines, $body];
    }
}
