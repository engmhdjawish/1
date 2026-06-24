<?php

declare(strict_types=1);

/**
 * Run local image scan from CLI (same logic as dashboard «فحص الملفات المحلية»).
 * Usage: php scripts/scan-local-files-cli.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\MaterialImageStorageService;
use Portal\Services\MaterialImageSyncService;
use Portal\Services\PortalSettingsService;

$dirs = MaterialImageStorageService::settings();
echo "Images: {$dirs['images_dir']}\n";
echo "Thumbs: {$dirs['thumbnails_dir']}\n";

$health = PortalSettingsService::apiHealth();
if (!($health['ok'] ?? false)) {
    fwrite(STDERR, 'API offline: ' . ($health['message'] ?? '') . "\n");
    exit(1);
}

$fileCount = count(MaterialImageStorageService::listLocalFiles());
echo "Files found on disk: {$fileCount}\n\n";

if ($fileCount === 0) {
    fwrite(STDERR, "No listable images in the configured folder.\n");
    exit(1);
}

echo "Scanning (may take several minutes)...\n";
$result = MaterialImageSyncService::scanLocalFiles(null);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(($result['offline'] ?? false) ? 1 : 0);
