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
$routes = require __DIR__ . '/../config/routes.php';
(new Router($routes))->dispatch(new Request());
