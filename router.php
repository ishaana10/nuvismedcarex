<?php
use ClinicFlow\Http\SecurityHeadersMiddleware;

require_once __DIR__ . '/includes/autoloader.php';
SecurityHeadersMiddleware::applyHeaders();

$uri = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

if (str_starts_with($uri, '/assets/')) {
    $file = __DIR__ . $uri;
    if (!file_exists($file) || !is_file($file)) {
        $file = __DIR__ . '/dist' . $uri;
    }
    if (file_exists($file) && is_file($file)) {
        $mime = str_ends_with($file, '.css') ? 'text/css' : (str_ends_with($file, '.js') ? 'application/javascript' : (str_ends_with($file, '.png') ? 'image/png' : 'text/plain'));
        header("Content-Type: $mime");
        readfile($file);
        exit;
    }
}

if ($uri === '/api/notifications/stream' || $uri === '/api/notifications/stream.php') {
    require __DIR__ . '/api/notifications/stream.php';
    exit;
}

if (file_exists(__DIR__ . $uri) && is_file(__DIR__ . $uri)) {
    return false;
}

require __DIR__ . '/index.php';
