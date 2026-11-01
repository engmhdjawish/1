<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
use Portal\Services\ProcurementService;
use Portal\Services\StoreSeasonService;

WebSession::requirePermission('procurement.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$tab = trim((string) ($_GET['tab'] ?? 'shortages'));
$allowedTabs = ['shortages', 'materials', 'orders', 'settings'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'shortages';
}

$flash = null;
$flashType = 'success';
$tableReady = ProcurementService::tableReady();

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'sync_materials') {
        $result = ProcurementService::syncMaterialsFromAmine($userId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $tab = 'materials';
        }
    } elseif ($action === 'save_settings') {
        $result = ProcurementService::saveSettings([
            'store_guids' => array_map('strval', (array) ($_POST['store_guids'] ?? [])),
            'limit_to_current_season' => isset($_POST['limit_to_current_season']),
        ], $userId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'settings';
    } elseif ($action === 'save_material') {
        $guid = trim((string) ($_POST['material_guid'] ?? ''));
        $result = ProcurementService::saveMaterial($guid, [
            'lifecycle' => (string) ($_POST['lifecycle'] ?? ''),
            'min_cartons' => $_POST['min_cartons'] ?? null,
            'max_cartons' => $_POST['max_cartons'] ?? null,
            'notes_ar' => (string) ($_POST['notes_ar'] ?? ''),
        ], $userId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'materials';
    } elseif ($action === 'create_drafts') {
        $result = ProcurementService::createDraftOrdersFromShortages($userId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $tab = 'orders';
        }
    } elseif ($action === 'update_order_status') {
        $result = ProcurementService::updateOrderStatus(
            trim((string) ($_POST['order_id'] ?? '')),
            trim((string) ($_POST['status'] ?? '')),
            $userId
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
    } elseif ($action === 'update_line_qty') {
        $result = ProcurementService::updateLineApprovedCartons(
            trim((string) ($_POST['line_id'] ?? '')),
            (float) ($_POST['approved_cartons'] ?? 0)
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
    } elseif ($action === 'close_line') {
        $result = ProcurementService::closeLine(trim((string) ($_POST['line_id'] ?? '')));
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
    }
}

$settings = ProcurementService::settings();
$season = StoreSeasonService::current();
$shortagesByManufacturer = $tableReady ? ProcurementService::shortagesByManufacturer() : [];
$materialFilter = trim((string) ($_GET['material_filter'] ?? 'needs_config'));
$materials = $tableReady ? ProcurementService::listMaterials($materialFilter) : [];
$orders = $tableReady ? ProcurementService::listOrders('open') : [];
$viewOrderId = trim((string) ($_GET['order'] ?? ''));
$orderLines = ($tableReady && $viewOrderId !== '') ? ProcurementService::orderLines($viewOrderId) : [];

$storeOptions = [];
$filterError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok'] && is_array($filtersResponse['data'] ?? null)) {
        $data = $filtersResponse['data'];
        $storeOptions = array_values(array_filter(
            is_array($data['stores'] ?? null) ? $data['stores'] : ($data['Stores'] ?? []),
            static fn ($row): bool => is_array($row) && trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== ''
        ));
    } else {
        $filterError = 'تعذر جلب قائمة المخازن.';
    }
} catch (Throwable $exception) {
    $filterError = $exception->getMessage();
}

$selectedStoreGuids = $settings['store_guids'];
$currentRoute = '/dashboard/procurement.php?tab=' . rawurlencode($tab);

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/procurement.php';
$content = ob_get_clean();
$title = 'المشتريات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
