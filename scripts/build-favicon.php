<?php

// Package the current brand asset as the favicon used by native PDF browser tabs.
$root = dirname(__DIR__);
$source = imagecreatefrompng($root.'/public/brand/sigworks-mark.png');
$sizes = [16, 32, 48, 64];
$directory = pack('vvv', 0, 1, count($sizes));
$images = '';
$offset = 6 + 16 * count($sizes);
foreach ($sizes as $size) {
    $image = imagecreatetruecolor($size, $size);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $directory .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
    $images .= $png;
    $offset += strlen($png);
    imagedestroy($image);
}
file_put_contents($root.'/public/favicon.ico', $directory.$images);
imagedestroy($source);
echo "Updated public/favicon.ico with SigWorks icons (16, 32, 48, 64 px).\n";
