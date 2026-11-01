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
$redirectOrderId = null;

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
    } elseif ($action === 'create_empty_order') {
        $result = ProcurementService::createEmptyDraftOrder(
            trim((string) ($_POST['manufacturer'] ?? '')),
            $userId
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        if ($result['ok'] && !empty($result['order_id'])) {
            $redirectOrderId = (string) $result['order_id'];
        }
    } elseif ($action === 'add_order_line') {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = ProcurementService::addLineToOrder(
            $orderId,
            trim((string) ($_POST['material_guid'] ?? '')),
            isset($_POST['approved_cartons']) && $_POST['approved_cartons'] !== ''
                ? (float) $_POST['approved_cartons']
                : null
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        $redirectOrderId = $orderId;
    } elseif ($action === 'fill_order_criteria') {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = ProcurementService::fillOrderFromCriteria($orderId, [
            'only_season_active' => isset($_POST['only_season_active']),
            'only_below_min' => isset($_POST['only_below_min']),
        ], $userId);
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        $redirectOrderId = $orderId;
    } elseif ($action === 'update_order_status') {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = ProcurementService::updateOrderStatus(
            $orderId,
            trim((string) ($_POST['status'] ?? '')),
            $userId
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        $redirectOrderId = $orderId;
    } elseif ($action === 'update_line_qty') {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = ProcurementService::updateLineApprovedCartons(
            trim((string) ($_POST['line_id'] ?? '')),
            (float) ($_POST['approved_cartons'] ?? 0)
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        $redirectOrderId = $orderId;
    } elseif ($action === 'close_line') {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $result = ProcurementService::closeLine(trim((string) ($_POST['line_id'] ?? '')));
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        $tab = 'orders';
        $redirectOrderId = $orderId;
    }
}

if ($redirectOrderId !== null && $redirectOrderId !== '') {
    $pickQ = trim((string) ($_POST['pick_q'] ?? $_GET['pick_q'] ?? ''));
    $pickPage = max(1, (int) ($_POST['pick_page'] ?? $_GET['pick_page'] ?? 1));
    $pickSeason = isset($_POST['pick_season']) || (($_GET['pick_season'] ?? '') === '1');
    $query = [
        'tab' => 'orders',
        'order' => $redirectOrderId,
        'pick_q' => $pickQ,
        'pick_page' => (string) $pickPage,
    ];
    if ($pickSeason) {
        $query['pick_season'] = '1';
    }
    if ($flash !== null && $flash !== '') {
        $query['flash'] = $flash;
        $query['flash_type'] = $flashType;
    }
    header('Location: /dashboard/procurement.php?' . http_build_query($query));
    exit;
}

if ($flash === null && trim((string) ($_GET['flash'] ?? '')) !== '') {
    $flash = trim((string) $_GET['flash']);
    $flashType = ($_GET['flash_type'] ?? '') === 'error' ? 'error' : 'success';
}

$settings = ProcurementService::settings();
$season = StoreSeasonService::current();
$shortagesByManufacturer = $tableReady ? ProcurementService::shortagesByManufacturer() : [];

$materialFilter = trim((string) ($_GET['material_filter'] ?? 'needs_config'));
$materialQ = trim((string) ($_GET['material_q'] ?? ''));
$materialManufacturer = trim((string) ($_GET['material_manufacturer'] ?? ''));
$materialPage = max(1, (int) ($_GET['material_page'] ?? 1));
$materialSearch = $tableReady
    ? ProcurementService::searchMaterialsCatalog([
        'preset' => $materialFilter,
        'q' => $materialQ,
        'manufacturer' => $materialManufacturer,
        'page' => $materialPage,
        'page_size' => 50,
    ])
    : ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 50];
$materials = $materialSearch['items'];
$materialTotal = (int) $materialSearch['total'];
$materialManufacturers = $tableReady ? ProcurementService::distinctManufacturers() : [];

$orders = $tableReady ? ProcurementService::listOrders('open') : [];
$viewOrderId = trim((string) ($_GET['order'] ?? ''));
$viewOrder = ($tableReady && $viewOrderId !== '') ? ProcurementService::getOrder($viewOrderId) : null;
$orderLines = ($tableReady && $viewOrderId !== '') ? ProcurementService::orderLines($viewOrderId) : [];

$pickQ = trim((string) ($_GET['pick_q'] ?? ''));
$pickPage = max(1, (int) ($_GET['pick_page'] ?? 1));
$pickSeasonOnly = isset($_GET['pick_season'])
    ? (string) $_GET['pick_season'] === '1'
    : (bool) $settings['limit_to_current_season'];
$pickerResults = ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 25];
if ($tableReady && $viewOrder !== null && ($pickQ !== '' || isset($_GET['pick_search']))) {
    $pickerResults = ProcurementService::searchMaterialsForPicker(
        (string) ($viewOrder['manufacturer'] ?? ''),
        [
            'q' => $pickQ,
            'page' => $pickPage,
            'page_size' => 25,
            'current_season_only' => $pickSeasonOnly,
        ]
    );
}

$storeOptions = [];
$manufacturerOptions = [];
$filterError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok'] && is_array($filtersResponse['data'] ?? null)) {
        $data = $filtersResponse['data'];
        $storeOptions = array_values(array_filter(
            is_array($data['stores'] ?? null) ? $data['stores'] : ($data['Stores'] ?? []),
            static fn ($row): bool => is_array($row) && trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== ''
        ));
        $manufacturerOptions = array_values(array_map(
            'strval',
            is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? [])
        ));
    } else {
        $filterError = 'تعذر جلب قائمة المخازن.';
    }
} catch (Throwable $exception) {
    $filterError = $exception->getMessage();
}

if ($manufacturerOptions === [] && $materialManufacturers !== []) {
    $manufacturerOptions = $materialManufacturers;
}

$selectedStoreGuids = $settings['store_guids'];
$currentRoute = '/dashboard/procurement.php?tab=' . rawurlencode($tab);

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/procurement.php';
$content = ob_get_clean();
$title = 'المشتريات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
