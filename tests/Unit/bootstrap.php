<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the gallery unit tests. Loads the app's global
 * helpers + autoloader (no DB connection is opened unless a helper actually
 * queries, which the pure unit tests under tests/Unit deliberately avoid).
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';