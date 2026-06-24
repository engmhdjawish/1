<?php

declare(strict_types=1);

/**
 * Rebuild local_file_path in sync queue from current image folder settings.
 * Usage: php scripts/reindex-material-image-paths.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Database;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\MaterialImageSyncService;

MaterialImageSyncService::ensureTable();
$dirs = MaterialImageStorageService::settings();

echo "Images dir:    {$dirs['images_dir']}\n";
echo "Thumbs dir:    {$dirs['thumbnails_dir']}\n\n";

if (!is_dir($dirs['images_dir'])) {
    fwrite(STDERR, "Images directory does not exist.\n");
    exit(1);
}

$pdo = Database::pdo();
$rows = $pdo->query(
    'SELECT id::text, file_name, local_file_path, local_thumb_path, amine_image_guid::text AS amine_image_guid
     FROM material_image_sync_queue'
)->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$missing = 0;
$foundOnDisk = 0;

foreach ($rows as $row) {
    $fileName = trim((string) ($row['file_name'] ?? ''));
    if ($fileName === '') {
        continue;
    }

    $newPath = MaterialImageStorageService::resolveLocalPath($fileName, false);
    $newThumb = MaterialImageStorageService::resolveLocalPath($fileName, true);

    if ($newPath === null) {
        $missing++;
        continue;
    }

    $foundOnDisk++;
    $oldPath = (string) ($row['local_file_path'] ?? '');
    if ($oldPath === $newPath && (!$newThumb || (string) ($row['local_thumb_path'] ?? '') === $newThumb)) {
        continue;
    }

    $stmt = $pdo->prepare(
        'UPDATE material_image_sync_queue
         SET local_file_path = :path,
             local_thumb_path = :thumb,
             updated_at = NOW()
         WHERE id = :id::uuid'
    );
    $stmt->execute([
        'path' => $newPath,
        'thumb' => $newThumb,
        'id' => $row['id'],
    ]);
    $updated++;
}

$listed = count(MaterialImageStorageService::listLocalFiles());

echo "Queue rows:      " . count($rows) . "\n";
echo "Files on disk:   {$listed} (listLocalFiles)\n";
echo "Matched by name: {$foundOnDisk}\n";
echo "Paths updated:   {$updated}\n";
echo "Missing files:   {$missing}\n\n";

if ($listed === 0) {
    echo "No image files found in the configured folder.\n";
    echo "Check company_settings paths and file extensions (jpg, png, webp, gif).\n";
    exit(1);
}

if ($foundOnDisk === 0) {
    echo "Files exist on disk but names do not match the database queue.\n";
    echo "Run «فحص الملفات المحلية» in dashboard (Upload tab) while API is connected.\n";
    exit(1);
}

echo "Done. Refresh material images page.\n";
exit(0);
