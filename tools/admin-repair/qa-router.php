<?php

use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$app = require __DIR__.'/qa-bootstrap.php';
$app->handleRequest(Request::capture());
