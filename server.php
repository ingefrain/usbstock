<?php

// Local development router used by Artisan serve. Never print document capabilities.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);

    return;
}

$publicPath = __DIR__.'/public';
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$file = realpath($publicPath.$uri);
if (! str_starts_with($uri, '/api/') && $uri !== '/' && $file !== false && is_file($file)
    && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', realpath($publicPath)).'/')) {
    return false;
}

require $publicPath.'/index.php';
