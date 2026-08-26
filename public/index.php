<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Request;
use App\Core\Router;

require __DIR__ . '/../app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

$configurationValid = (require __DIR__ . '/../config/validate.php')();
if (!$configurationValid) {
    if (PHP_SAPI === 'cli') exit(1);
    http_response_code(503);
    header('Retry-After: 600');
    require __DIR__ . '/../views/errors/503.php';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (isset($_GET['se']) && in_array($_GET['se'], ['1', 'user'], true)) session_name('GALLERY_USER_PREVIEW');
}

Auth::start();

// Maintenance mode: when storage/maintenance.flag exists, everyone except
// staff gets a downtime page. Admin area, login, cron and file serving stay
// reachable so staff can still work and the login page keeps its art.
$maintenanceFlag = dirname(__DIR__) . '/storage/maintenance.flag';

if (is_file($maintenanceFlag)) {
    $request = new Request();
    $path    = rtrim((string) $request->uri(), '/');

    $allowedPrefixes = ['/admin', '/health', '/login', '/files', '/cron', '/assets', '/webhooks', '/verify-email'];
    $allowed = false;

    foreach ($allowedPrefixes as $prefix) {
        if ($path === $prefix || strpos($path . '/', $prefix . '/') === 0) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed && !Auth::isAdmin()) {
        http_response_code(503);
        header('Retry-After: 600');
        require __DIR__ . '/../views/errors/503.php';
        exit;
    }
}

$routes = require __DIR__ . '/../config/routes.php';
(new Router($routes))->dispatch(new Request());
