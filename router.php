<?php
$base = '/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// Map requests under base to the public directory
if (strncmp($uri, $base, strlen($base)) === 0) {
    $rel = substr($uri, strlen($base));
    if ($rel === '' || $rel === false) { $rel = '/'; }

    $public = __DIR__ . '/public';
    $file = realpath($public . $rel);

    // If the resolved path is a real file inside public, let the server serve it
    if ($file && is_file($file) && strncmp($file, realpath($public), strlen(realpath($public))) === 0) {
        return false; // serve static
    }
    // Otherwise, pass through to front controller
    require $public . '/index.php';
    return;
}

// Fallback: if a static file exists at project root, serve; else route to front controller
$projectFile = realpath(__DIR__ . $uri);
if ($projectFile && is_file($projectFile)) {
    return false;
}
require_once __DIR__ . '/public/index.php';