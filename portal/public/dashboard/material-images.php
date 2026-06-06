<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\PortalSettingsService;

WebSession::requirePermission('images.upload');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_settings') {
        MaterialImageStorageService::saveSettings([
            'material_images_dir' => trim((string) ($_POST['material_images_dir'] ?? '')),
            'material_thumbnails_dir' => trim((string) ($_POST['material_thumbnails_dir'] ?? '')),
        ], $userId);
        header('Location: /dashboard/material-images.php?saved=1');
        exit;
    }

    if ($action === 'upload') {
        $uploads = $_FILES['files'] ?? null;
        $normalized = [];
        if (is_array($uploads) && is_array($uploads['name'] ?? null)) {
            $count = count($uploads['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name' => $uploads['name'][$i] ?? '',
                    'type' => $uploads['type'][$i] ?? '',
                    'tmp_name' => $uploads['tmp_name'][$i] ?? '',
                    'error' => $uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $uploads['size'][$i] ?? 0,
                ];
            }
        }

        $result = MaterialImageStorageService::uploadMany($normalized);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            header('Location: /dashboard/material-images.php?uploaded=1');
            exit;
        }
    }

    if ($action === 'refresh_index') {
        $result = MaterialImageStorageService::refreshIndexFromApi();
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            header('Location: /dashboard/material-images.php?indexed=1');
            exit;
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1' && $flash === null) {
    $flash = 'تم حفظ مسارات التخزين.';
}
if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1' && $flash === null) {
    $flash = 'تم رفع الصور بنجاح.';
}
if (isset($_GET['indexed']) && $_GET['indexed'] === '1' && $flash === null) {
    $flash = 'تم تحديث فهرس الصور من API.';
}

$company = PortalSettingsService::companySettings();
$paths = MaterialImageStorageService::settings();
$stats = MaterialImageStorageService::stats();
$files = MaterialImageStorageService::listLocalFiles();
$settingsForm = [
    'material_images_dir' => (string) ($company['material_images_dir'] ?? ''),
    'material_thumbnails_dir' => (string) ($company['material_thumbnails_dir'] ?? ''),
];
$currentRoute = '/dashboard/material-images.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/material-images.php';
$content = ob_get_clean();
$title = 'صور المواد';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
