<?php
/**
 * Write the sitemap out as files.
 *
 * The map is served by the app as well (GET /sitemap.xml), but a crawler
 * that fetches it has to go through PHP for every request. Writing the file
 * out means the origin root can serve it directly, the way robots.txt is
 * served, and the crawler never waits on the application.
 *
 * The map itself is built in App\Core\Sitemap, so the file and the route
 * always carry the same URLs.
 *
 * Usage: php bin/write_sitemap.php [--dir=PATH]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

use App\Core\Sitemap;

$dir = $root . '/public';
foreach ($argv as $arg) {
    if (strpos($arg, '--dir=') === 0) {
        $dir = substr($arg, 6);
    }
}

try {
    $written = Sitemap::write($dir);
} catch (Throwable $error) {
    fwrite(STDERR, '[sitemap] ' . $error->getMessage() . "\n");
    exit(1);
}

$total = 0;
foreach ($written as $name => $info) {
    $count = substr_count((string) file_get_contents($info['path']), '<loc>');
    $total += $count;
    printf("[sitemap] wrote %s (%d bytes, %d urls)\n", $info['path'], $info['bytes'], $count);
}

printf("[sitemap] %d url(s) in total\n", $total);
exit(0);
