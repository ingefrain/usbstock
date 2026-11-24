<?php

use Illuminate\Http\Request;

// Run only with PHP's loopback development server and public/ as document root.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$project = dirname(__DIR__, 2);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($project.'/public'.$path);
$public = realpath($project.'/public').DIRECTORY_SEPARATOR;
if ($file && str_starts_with($file, $public) && is_file($file) && ! str_ends_with(strtolower($file), '.php')) {
    return false;
}
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
