<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ShareCardService;

$sendJpeg = static function (string $path, int $maxAge): void {
    $mtime = (int) filemtime($path);
    $etag = '"' . substr(sha1($path . '|' . $mtime . '|' . filesize($path)), 0, 20) . '"';
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
};

$imageGuid = trim((string) ($_GET['img'] ?? ''));
try {
    if ($imageGuid !== '' && ShareCardService::isImageGuid($imageGuid)) {
        $path = ShareCardService::productCardPath($imageGuid);
        if ($path !== null) {
            $sendJpeg($path, 604800);
        }
    }
    $path = ShareCardService::siteCardPath();
    if ($path !== null) {
        $sendJpeg($path, isset($_GET['v']) ? 604800 : 3600);
    }
} catch (Throwable) {
}

$fallback = __DIR__ . '/icons/icon-512.png';
if (is_file($fallback)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=600');
    readfile($fallback);
    exit;
}
http_response_code(404);
