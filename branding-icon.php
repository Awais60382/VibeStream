<?php
require_once __DIR__ . '/site-branding.php';

try {
    $path      = vs_branding_logo_path();
    $size      = (int)($_GET['size'] ?? 0);
    $maskable  = isset($_GET['maskable']);
    $allowed   = [48, 72, 96, 144, 180, 192, 256, 384, 512];
    $resize    = in_array($size, $allowed, true) && function_exists('imagecreatefrompng');

    $etag = '"' . md5($path . '|' . filemtime($path) . '|' . filesize($path) . '|' . ($resize ? $size : 0) . '|' . ($maskable ? 1 : 0)) . '"';

    header('Content-Type: image/png');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    if (!$resize) {
        readfile($path);
        exit;
    }

    $src = imagecreatefrompng($path);
    if ($src === false) {
        readfile($path);
        exit;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);

    $dst = imagecreatetruecolor($size, $size);
    imagesavealpha($dst, true);

    if ($maskable) {
        // Opaque background + logo kept inside the "safe zone" so Android masks never crop it.
        imagealphablending($dst, true);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 15, 15, 15));
        $inner  = (int)round($size * 0.6);
        $offset = (int)round(($size - $inner) / 2);
    } else {
        imagealphablending($dst, false);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        $inner  = $size;
        $offset = 0;
    }

    imagecopyresampled($dst, $src, $offset, $offset, 0, 0, $inner, $inner, $sw, $sh);
    imagepng($dst);
    imagedestroy($src);
    imagedestroy($dst);
} catch (Throwable $error) {
    error_log('VibeStream branding icon error: ' . $error->getMessage());
    http_response_code(500);
}