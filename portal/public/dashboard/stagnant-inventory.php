<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
use Portal\Services\StagnantInventoryService;
use Portal\Services\StoreSeasonService;

WebSession::requirePermission('special_offers.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

$flash = null;
$flashType = 'success';

$parseList = static function (mixed $value): array {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[,|\n]+/u', (string) $value) ?: [];
    }
    $result = [];
    foreach ($parts as $part) {
        $item = trim((string) $part);
        if ($item !== '' && !in_array($item, $result, true)) {
            $result[] = $item;
        }
    }

    return $result;
};

$minAgeDays = max(1, min(3650, (int) ($_REQUEST['minAgeDays'] ?? 60)));
$maxAgeRaw = trim((string) ($_REQUEST['maxAgeDays'] ?? ''));
$maxAgeDays = $maxAgeRaw !== '' && is_numeric($maxAgeRaw)
    ? max($minAgeDays, min(3650, (int) $maxAgeRaw))
    : null;
$sort = trim((string) ($_REQUEST['sort'] ?? 'age:desc'));
$allowedSorts = [
    'age:desc', 'age:asc',
    'name:asc', 'name:desc',
    'price:desc', 'price:asc',
    'purchase:desc', 'purchase:asc',
    'qty:desc', 'qty:asc',
];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'age:desc';
}
$page = max(1, (int) ($_REQUEST['page'] ?? 1));
$pageSize = max(10, min(100, (int) ($_REQUEST['pageSize'] ?? 50)));
$seasonId = trim((string) ($_REQUEST['seasonId'] ?? ''));
$keyword = trim((string) ($_REQUEST['keyword'] ?? ''));
$asOf = trim((string) ($_REQUEST['asOf'] ?? ''));
if ($asOf !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = '';
}
$selectedStoreGuids = $parseList($_REQUEST['store_guids'] ?? []);
$selectedCompanies = $parseList($_REQUEST['companies'] ?? []);
$selectedMaterialTypes = $parseList($_REQUEST['material_types'] ?? []);
$selectedGroupGuids = $parseList($_REQUEST['group_guids'] ?? []);

$seasons = StoreSeasonService::seasons();
$currentSeason = StoreSeasonService::current();
$selectedSeason = null;
$groupGuids = $selectedGroupGuids;

if ($seasonId === 'current' && $currentSeason !== null) {
    $selectedSeason = $currentSeason;
    $seasonGroups = array_map('strval', $currentSeason['group_guids'] ?? []);
    $groupGuids = $groupGuids === [] ? $seasonGroups : array_values(array_intersect($groupGuids, $seasonGroups));
    if ($groupGuids === [] && $selectedGroupGuids === []) {
        $groupGuids = $seasonGroups;
    }
    $seasonId = (string) ($currentSeason['id'] ?? 'current');
} elseif ($seasonId !== '' && $seasonId !== 'all') {
    foreach ($seasons as $season) {
        if (($season['id'] ?? '') === $seasonId) {
            $selectedSeason = $season;
            $seasonGroups = array_map('strval', $season['group_guids'] ?? []);
            if ($selectedGroupGuids === []) {
                $groupGuids = $seasonGroups;
            }
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_offer') {
    $selectedGuids = array_map('strval', (array) ($_POST['material_guids'] ?? []));
    $discountPercent = (float) ($_POST['discount_percent'] ?? 15);
    $titleAr = trim((string) ($_POST['offer_title'] ?? ''));
    $saveMode = trim((string) ($_POST['save_mode'] ?? 'draft'));
    $asDraft = $saveMode !== 'activate';
    $rawDiscounts = is_array($_POST['material_discount'] ?? null) ? $_POST['material_discount'] : [];
    $perMaterialPercents = [];
    foreach ($rawDiscounts as $guid => $pct) {
        $guid = strtolower(trim((string) $guid));
        if ($guid === '' || !is_numeric($pct)) {
            continue;
        }
        $perMaterialPercents[$guid] = (float) $pct;
    }
    $result = StagnantInventoryService::createOfferFromSelection(
        $selectedGuids,
        $perMaterialPercents,
        $discountPercent,
        $titleAr,
        $userId,
        $asDraft
    );
    if ($result['ok'] && !empty($result['id'])) {
        $editUrl = '/dashboard/special-offers.php?edit=' . rawurlencode((string) $result['id']) . '&created=1';
        if ($asDraft) {
            $editUrl .= '&draft=1';
        }
        header('Location: ' . $editUrl);
        exit;
    }
    $flash = $result['message'] ?? 'تعذر إنشاء العرض.';
    $flashType = 'error';
}

$materialFilterOptions = [
    'materialTypes' => [],
    'manufacturers' => [],
    'stores' => [],
    'groups' => [],
];
$materialFilterOptionsError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok']) {
        $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
            'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
            'stores' => array_values(array_filter(is_array($data['stores'] ?? null) ? $data['stores'] : ($data['Stores'] ?? []), static fn ($r) => is_array($r))),
            'groups' => array_values(array_filter(is_array($data['groups'] ?? null) ? $data['groups'] : ($data['Groups'] ?? []), static fn ($r) => is_array($r))),
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب قوائم الفلاتر من API.';
    }
} catch (\Throwable $e) {
    $materialFilterOptionsError = $e->getMessage();
}

$error = null;
$report = [
    'page' => $page,
    'pageSize' => $pageSize,
    'totalCount' => 0,
    'minAgeDays' => $minAgeDays,
    'asOf' => $asOf,
    'items' => [],
];

$fetch = StagnantInventoryService::fetch([
    'minAgeDays' => $minAgeDays,
    'maxAgeDays' => $maxAgeDays,
    'asOf' => $asOf,
    'keyword' => $keyword,
    'groupGuids' => $groupGuids,
    'storeGuids' => $selectedStoreGuids,
    'companies' => $selectedCompanies,
    'materialTypes' => $selectedMaterialTypes,
    'sort' => $sort,
    'page' => $page,
    'pageSize' => $pageSize,
]);

if ($fetch['ok']) {
    $report = $fetch['data'] ?? $report;
} else {
    $error = $fetch['error'] ?? 'فشل جلب التقرير.';
}

$totalCount = (int) ($report['totalCount'] ?? 0);
$totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
$page = (int) ($report['page'] ?? $page);
$pageSize = (int) ($report['pageSize'] ?? $pageSize);

$currentRoute = '/dashboard/stagnant-inventory.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/stagnant-inventory.php';
$content = ob_get_clean();
$title = 'المخزون الراكد';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
