<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;
use Portal\Services\ApiClient;
use Portal\Services\MaterialImageStorageService;
use Portal\Services\MaterialImageSyncService;
use Portal\Services\PortalSettingsService;

WebSession::requirePermission('images.upload');
require dirname(__DIR__, 2) . '/views/helpers.php';

MaterialImageStorageService::ensureSettings();
MaterialImageSyncService::ensureTable();
MaterialImageSyncService::recoverStaleSyncing();

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
        header('Location: /dashboard/material-images.php?tab=upload&saved=1');
        exit;
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1' && $flash === null) {
    $flash = 'تم حفظ مسارات التخزين.';
}

$workspaceTab = trim((string) ($_GET['tab'] ?? 'link'));
if (!in_array($workspaceTab, ['link', 'upload', 'download'], true)) {
    $workspaceTab = 'link';
}

$company = PortalSettingsService::companySettings();
$paths = MaterialImageStorageService::settings();
$stats = MaterialImageStorageService::stats();
$syncStats = MaterialImageSyncService::stats();
$apiHealth = PortalSettingsService::apiHealth();
$queuePage = MaterialImageSyncService::listQueuePage(1, 20);
$queue = $queuePage['items'];
$settingsForm = [
    'material_images_dir' => (string) ($company['material_images_dir'] ?? ''),
    'material_thumbnails_dir' => (string) ($company['material_thumbnails_dir'] ?? ''),
];
$detailsBanner = MaterialImageStorageService::detailsBannerRequirements();

$materialFilterOptions = [
    'materialTypes' => [],
    'ageCategories' => [],
    'manufacturers' => [],
    'sizeRanges' => [],
    'countryOfOrigins' => [],
    'stores' => [],
    'groups' => [],
];
$materialFilterOptionsError = null;

try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok']) {
        $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
        $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
            'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
            'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
            'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
            'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
            'stores' => array_values(array_filter($stores, static fn ($row) => is_array($row))),
            'groups' => array_values(array_filter($groups, static fn ($row) => is_array($row))),
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب فلاتر المواد من API (رمز ' . (int) ($filtersResponse['status'] ?? 0) . ').';
    }
} catch (\Throwable $exception) {
    $materialFilterOptionsError = $exception->getMessage();
}

$invoiceTypes = [];
$invoiceTypesError = null;
if ($workspaceTab === 'download') {
    try {
        $invoiceTypes = AccountingApiService::invoiceTypes();
    } catch (\Throwable $exception) {
        $invoiceTypesError = $exception->getMessage();
    }
}

$currentRoute = '/dashboard/material-images.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/material-images-workspace.php';
$content = ob_get_clean();
$title = 'صور المواد';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
