<?php

use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
try {
    $project = dirname(__DIR__, 2);
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $public = realpath($project.'/public').DIRECTORY_SEPARATOR;
    $file = realpath($project.'/public'.$path);
    if ($file && str_starts_with($file, $public) && is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'ttf', 'woff', 'woff2'], true)) {
        return false;
    }
    $app = require __DIR__.'/postmerge-bootstrap.php';
    $app->handleRequest(Request::capture());
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolated QA unavailable.';
}
