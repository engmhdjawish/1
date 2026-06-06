<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\PortalSettingsService;

WebSession::requirePermission('images.upload');
require dirname(__DIR__, 2) . '/views/helpers.php';

MaterialImageStorageService::ensureSettings();

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

}

if (isset($_GET['saved']) && $_GET['saved'] === '1' && $flash === null) {
    $flash = 'تم حفظ مسارات التخزين.';
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
