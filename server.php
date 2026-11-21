<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server (local development only):
 *
 *   composer serve
 *
 * Real files under public/ (assets) are served directly; every other
 * request goes through the front controller, like Apache/nginx would.
 */

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

// The booking host serves assets under /_/assets so they can never collide
// with a business slug (D-005); production nginx maps it to public/assets.
$relative = str_starts_with($path, '/_/assets/')
    ? '/assets/' . substr($path, strlen('/_/assets/'))
    : $path;

$file = __DIR__ . '/public' . $relative;

if (!is_file($file)) {
    require __DIR__ . '/public/index.php';
    return true;
}

if ($relative === $path) {
    return false; // Let the built-in server stream it.
}

// Remapped /_/assets/* request: return false would serve the original URI,
// which does not exist on disk — emit the file ourselves.
$types = [
    'css' => 'text/css', 'js' => 'text/javascript', 'map' => 'application/json',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
];
$ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
readfile($file);
return true;
