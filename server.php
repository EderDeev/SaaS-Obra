<?php

$publicPath = realpath(__DIR__.'/public');
$publicStoragePath = realpath(__DIR__.'/storage/app/public');
$privateStoragePath = realpath(rtrim(getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: __DIR__.'/storage/app/public', '/').'/.sigworks-private');

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// The Railway volume contains a private area alongside the existing public files.
// Block direct paths and, below, resolved symlink aliases before serving assets.
if (in_array('.sigworks-private', explode('/', str_replace('\\', '/', $uri)), true)) {
    http_response_code(404);

    return true;
}

function static_file_content_type(string $file): string
{
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    return match ($extension) {
        'css' => 'text/css; charset=UTF-8',
        'js', 'mjs' => 'application/javascript; charset=UTF-8',
        'json', 'map' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'wasm' => 'application/wasm',
        default => mime_content_type($file) ?: 'application/octet-stream',
    };
}

if ($uri !== '/') {
    $file = realpath($publicPath.$uri);

    if ($file && $privateStoragePath && ($file === $privateStoragePath || str_starts_with($file, $privateStoragePath.DIRECTORY_SEPARATOR))) {
        http_response_code(404);

        return true;
    }

    $isPublicFile = $file
        && str_starts_with($file, $publicPath.DIRECTORY_SEPARATOR)
        && is_file($file);
    $isPublicStorageFile = $file
        && $publicStoragePath
        && str_starts_with($file, $publicStoragePath.DIRECTORY_SEPARATOR)
        && is_file($file);

    if ($isPublicFile || $isPublicStorageFile) {
        header('Content-Type: '.static_file_content_type($file));
        header('Content-Length: '.filesize($file));
        readfile($file);

        return true;
    }
}

require_once $publicPath.'/index.php';
