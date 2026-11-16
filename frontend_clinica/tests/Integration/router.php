<?php

use Illuminate\Http\Request;

// Test-server router only: normal Laravel routes and middleware, isolated runtime storage.
// It must be selected explicitly by php -S; it is never exposed by public/index.php.
define('LARAVEL_START', microtime(true));
$root = dirname(__DIR__, 2);
$storage = getenv('CLINICA_RUNTIME_STORAGE');
if (! is_string($storage) || ! is_dir($storage) || getenv('CLINICA_ISOLATED_HTTP') !== '1') {
    http_response_code(503);
    exit('An isolated test runtime is required.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = realpath($root.'/public'.$path);
if ($asset && is_file($asset) && str_starts_with($asset, realpath($root.'/public').DIRECTORY_SEPARATOR)
    && in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), ['css', 'js', 'woff', 'woff2', 'svg', 'png', 'jpeg', 'jpg', 'webp', 'ico', 'json'], true)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($storage);
$app->handleRequest(Request::capture());
