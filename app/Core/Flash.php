<?php

namespace App\Core;

/**
 * One-time session messages shown once after a redirect (e.g. "Saved"). They
 * are stored in the session and cleared on the next page render.
 */
class Flash
{
    /**
     * Queue a message for the next page load.
     */
    public static function set(string $type, string $message): void
    {
        Auth::start();
        $_SESSION['flash'][$type][] = $message;
    }

    /**
     * All queued messages grouped by type (error/success/...).
     */
    public static function all(): array
    {
        Auth::start();

        return $_SESSION['flash'] ?? [];
    }

    /**
     * Discard all queued messages. Called by the layout after rendering them
     * so the same message is not shown twice.
     */
    public static function clear(): void
    {
        Auth::start();
        $_SESSION['flash'] = [];
    }
}
