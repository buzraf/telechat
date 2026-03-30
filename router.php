<?php
/**
 * TeleChat — PHP built-in server router
 * Usage: php -S 0.0.0.0:$PORT telechat/router.php
 *        OR (if inside telechat/ dir): php -S 0.0.0.0:$PORT router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files if they exist
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    // Set content type for common file types
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        default => 'text/plain',
    };
    header('Content-Type: ' . $mime);
    readfile($file);
    return true;
}

// Route everything to index.php
require __DIR__ . '/index.php';
