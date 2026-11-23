<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$documentRoot = realpath(__DIR__);
$filePath = realpath(__DIR__ . $path);
$documentRootPrefix = $documentRoot . DIRECTORY_SEPARATOR;

if (
    $path !== '/'
    && $filePath !== false
    && strpos($filePath, $documentRootPrefix) === 0
    && is_file($filePath)
) {
    return false;
}

require __DIR__ . '/index.php';
