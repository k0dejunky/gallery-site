<?php
require __DIR__ . '/../app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\Auth;

Auth::start();
$_SESSION['impersonator_id'] = 999;
$_SESSION['user_id'] = 999;

file_put_contents(__DIR__ . '/../storage/debug_test.txt', date('c') . " session=" . json_encode($_SESSION) . "\n", FILE_APPEND);
session_commit();

header('Content-Type: text/plain');
echo "session=" . json_encode($_SESSION) . "\n";
echo "file written\n";
