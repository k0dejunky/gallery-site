<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Request;
use App\Core\Router;
use App\Models\Traffic;

require __DIR__ . '/../app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

// Global error/exception handling: log structured details to the app log and
// render a safe, non-leaking 500 page. Without this, an uncaught Throwable in
// a controller would surface a raw PHP fatal (and potentially stack trace) to
// the visitor. The handlers are registered before any request is dispatched.
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$appLog = $logDir . '/php-error.log';

$renderError = static function (int $status, string $message = 'Something went wrong.'): void {
    if (headers_sent()) {
        return;
    }
    http_response_code($status);
    $view = __DIR__ . '/../views/errors/' . ($status === 404 ? '404' : 'error') . '.php';
    if (is_file($view)) {
        require $view;
    } else {
        echo 'An error occurred.';
    }
};

set_exception_handler(function (Throwable $e) use ($appLog, $renderError): void {
    $line = sprintf(
        '[%s] Uncaught %s: %s in %s:%d%s',
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL . $e->getTraceAsString()
    );
    @file_put_contents($appLog, $line . "\n", FILE_APPEND | LOCK_EX);

    $renderError(500);
});

set_error_handler(function (int $severity, string $message, string $file, int $line) use ($appLog): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    @file_put_contents(
        $appLog,
        sprintf("[%s] PHP error [%d]: %s in %s:%d\n", date('Y-m-d H:i:s'), $severity, $message, $file, $line),
        FILE_APPEND | LOCK_EX
    );
    return false; // keep the normal PHP handling too
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
$request = new Request();
Traffic::capture($request);
(new Router($routes))->dispatch($request);
