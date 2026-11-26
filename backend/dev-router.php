<?php
declare(strict_types=1);

/*
 * Local development server: serves the frontend and the API on one port.
 *
 *   php -S localhost:8000 backend/dev-router.php
 *
 * then open http://localhost:8000
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/public/index.php';
    return true;
}

$frontend = realpath(dirname(__DIR__) . '/frontend');
$file = realpath($frontend . $path);
if ($file !== false && is_dir($file)) {
    $file = realpath($file . '/index.html');
}
if ($file === false || !str_starts_with($file, $frontend)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$types = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript',
    'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
    'jpg' => 'image/jpeg', 'webmanifest' => 'application/manifest+json',
];
header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
readfile($file);
return true;
