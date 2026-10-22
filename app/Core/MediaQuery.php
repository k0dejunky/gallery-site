<?php

namespace App\Core;

/**
 * Reads a value out of MediaMTX's query string.
 *
 * MediaMTX builds the request query from the RTMP CONNECT command and then
 * APPENDS the publish path to it, so a query the client sent as
 * "publish=<secret>" arrives as:
 *
 *     publish=<secret>/<key>?publish=<secret>
 *
 * parse_str() cannot see a value in that (the value runs on into the path),
 * so the parameter is read by hand and cut at the first path separator.
 *
 * This is why every publish was denied: the stream key arrived correctly,
 * but the secret did not, and the auth webhook answered 401 - so the
 * operator's broadcast never started.
 */
class MediaQuery
{
    /**
     * One parameter out of a query string, or an empty string.
     *
     * The value ends at the first '&', '?' or '/', so a value that MediaMTX
     * glued to the publish path is still read correctly.
     */
    public static function value(string $query, string $key): string
    {
        if ($query === '' || $key === '') {
            return '';
        }

        $pattern = '/(?:^|[?&])' . preg_quote($key, '/') . '=([^&?\\/]*)/';

        return preg_match($pattern, $query, $m) === 1 ? $m[1] : '';
    }
}
