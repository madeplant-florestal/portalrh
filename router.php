<?php
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $requestPath);

if ($requestPath !== '/' && is_file($publicFile)) {
    return false;
}

require __DIR__ . '/index.php';
