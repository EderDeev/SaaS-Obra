<?php

$publicPath = realpath(__DIR__.'/public');
$publicStoragePath = realpath(__DIR__.'/storage/app/public');

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

if ($uri !== '/') {
    $file = realpath($publicPath.$uri);

    $isPublicFile = $file
        && str_starts_with($file, $publicPath.DIRECTORY_SEPARATOR)
        && is_file($file);
    $isPublicStorageFile = $file
        && $publicStoragePath
        && str_starts_with($file, $publicStoragePath.DIRECTORY_SEPARATOR)
        && is_file($file);

    if ($isPublicFile || $isPublicStorageFile) {
        header('Content-Type: '.(mime_content_type($file) ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($file));
        readfile($file);

        return true;
    }
}

require_once $publicPath.'/index.php';
