<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read the PHP fatal/parse errors out of storage/logs/php-error.log.
 *
 * Housekeeping already emails the admin when a new fatal lands, and 76 of
 * those alerts were never acted on. An email is not a surface: this is the
 * reader behind the System page card, so the errors are in front of the
 * operator with a count and a "mark as reviewed" button.
 *
 * The file is only ever READ here. The reviewed pointer is a separate small
 * JSON file, because the alerting offset (.php_fatal_alert_offset) belongs to
 * Housekeeping and consuming it here would silence the email.
 */
final class PhpErrorLog
{
    /** The pattern a fatal or parse error line carries. */
    public const PATTERN = '/PHP (Fatal|Parse) error:|Uncaught [A-Za-z\\\\]+:/';

    /**
     * How many fatal/parse lines are in a chunk of the log.
     */
    public static function count(string $chunk): int
    {
        if ($chunk === '') {
            return 0;
        }

        // Count LINES, not pattern hits: one line can carry both
        // "PHP Fatal error:" and "Uncaught Error:" and is still one error.
        $n = 0;
        foreach (preg_split('/\r?\n/', $chunk) ?: [] as $line) {
            if (preg_match(self::PATTERN, $line)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The fatal/parse lines from a chunk, oldest first, capped.
     *
     * @return array<int, string>
     */
    public static function lines(string $chunk, int $max = 20): array
    {
        if ($chunk === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/\r?\n/', $chunk) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match(self::PATTERN, $line)) {
                $out[] = $line;
            }
        }

        return $max > 0 ? array_slice($out, -$max) : $out;
    }

    /**
     * A bounded window of the log, from $from to the end.
     *
     * A runaway log must never be loaded into a page (or into memory) whole,
     * so the window is capped and the caller is told when it was truncated.
     *
     * @return array{chunk:string, truncated:bool}
     */
    public static function window(string $path, int $from, int $maxBytes = 262144): array
    {
        if (!is_file($path)) {
            return ['chunk' => '', 'truncated' => false];
        }

        clearstatcache(true, $path);
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return ['chunk' => '', 'truncated' => false];
        }

        $from = max(0, min($from, $size));
        $length = $size - $from;
        $truncated = false;

        if ($length > $maxBytes) {
            $from = $size - $maxBytes;
            $length = $maxBytes;
            $truncated = true;
        }

        $chunk = (string) @file_get_contents($path, false, null, $from, $length);

        return ['chunk' => $chunk, 'truncated' => $truncated];
    }

    /**
     * The reviewed pointer, or 0 when the operator has never cleared it.
     */
    public static function reviewedOffset(string $stateFile): int
    {
        if (!is_file($stateFile)) {
            return 0;
        }

        $raw = (string) @file_get_contents($stateFile);
        $data = json_decode($raw, true);

        if (is_array($data)) {
            return max(0, (int) ($data['offset'] ?? 0));
        }

        // A bare integer (an older write) is accepted too.
        return max(0, (int) $raw);
    }

    /**
     * When the operator last cleared the list.
     */
    public static function reviewedAt(string $stateFile): ?string
    {
        if (!is_file($stateFile)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($stateFile), true);
        if (!is_array($data)) {
            return null;
        }

        $at = (string) ($data['at'] ?? '');

        return $at !== '' ? $at : null;
    }

    /**
     * Mark everything up to the current end of the log as reviewed.
     *
     * The offset is the file size at that moment, so an error that lands
     * while the page is open still shows up on the next load.
     */
    public static function acknowledge(string $stateFile, string $path, int $userId): int
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) @filesize($path) : 0;

        @file_put_contents($stateFile, json_encode([
            'offset' => $size,
            'at'     => date('Y-m-d H:i:s'),
            'by'     => $userId > 0 ? $userId : null,
        ]));

        return $size;
    }

    /**
     * Everything the System page card needs.
     *
     * @return array{since:int, lines:array<int,string>, reviewed_at:?string, size:int, truncated:bool}
     */
    public static function summary(string $path, string $stateFile): array
    {
        // stat cache: a long-running worker (and the test suite) rewrites the
        // log in the same process, and a stale size would hide the error.
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) @filesize($path) : 0;
        $reviewed = self::reviewedOffset($stateFile);

        // A pointer past the end means the log was rotated or truncated, so
        // everything in it is new. Without this, a rotation silently hides
        // the errors that follow it.
        if ($reviewed > $size) {
            $reviewed = 0;
        }

        // Errors since the last review.
        $since = 0;
        $sinceTruncated = false;
        if ($size > $reviewed) {
            $sinceWindow = self::window($path, $reviewed);
            $since = self::count($sinceWindow['chunk']);
            $sinceTruncated = $sinceWindow['truncated'];
        }

        // The newest lines, regardless of the reviewed pointer: the operator
        // needs the shape of the error, not only how many there are.
        $tail = self::window($path, max(0, $size - 262144));

        return [
            'since'           => $since,
            'since_truncated' => $sinceTruncated,
            'lines'           => self::lines($tail['chunk'], 20),
            'reviewed_at'     => self::reviewedAt($stateFile),
            'size'            => $size,
            'truncated'       => $tail['truncated'],
        ];
    }
}
