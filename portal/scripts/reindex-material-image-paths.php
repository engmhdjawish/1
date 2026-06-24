<?php

declare(strict_types=1);

/**
 * Rebuild local_file_path / local_thumb_path from the configured site images folder.
 * Usage: php scripts/reindex-material-image-paths.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Services\MaterialImageStorageService;
use Portal\Services\MaterialImageSyncService;

$dirs = MaterialImageStorageService::settings();
echo "Site images: {$dirs['images_dir']}\n";
echo "Site thumbs: {$dirs['thumbnails_dir']}\n\n";

$result = MaterialImageSyncService::reindexLocalPaths();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(($result['missing'] ?? 0) > 0 && ($result['updated'] ?? 0) === 0 ? 1 : 0);
