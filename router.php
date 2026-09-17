<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/storage/') || str_starts_with($path, '/.')) {
    http_response_code(404);
    exit('Not found');
}
if ($path === '/assets/app.css' || $path === '/assets/app.js') {
    return false;
}
if (preg_match('~^/assets/uploads/[a-f0-9]{32}\.(?:png|jpg|webp|ico)$~', $path)) {
    if (is_file(__DIR__ . $path)) return false;
    http_response_code(404);
    exit('Not found');
}
require __DIR__ . '/index.php';
