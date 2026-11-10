<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\StagnantInventoryService;
use Portal\Services\StoreSeasonService;

WebSession::requirePermission('special_offers.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

$flash = null;
$flashType = 'success';

$minAgeDays = max(1, min(3650, (int) ($_REQUEST['minAgeDays'] ?? 50)));
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
$yearStartDate = trim((string) ($_REQUEST['yearStartDate'] ?? ''));
if ($yearStartDate === '') {
    $yearStartDate = date('Y') . '-01-01';
}
$untilDate = trim((string) ($_REQUEST['untilDate'] ?? ''));
if ($untilDate === '') {
    $untilDate = date('Y-m-d');
}

$seasons = StoreSeasonService::seasons();
$currentSeason = StoreSeasonService::current();
$selectedSeason = null;
$groupGuids = [];

if ($seasonId === 'current' && $currentSeason !== null) {
    $selectedSeason = $currentSeason;
    $groupGuids = $currentSeason['group_guids'] ?? [];
    $seasonId = (string) ($currentSeason['id'] ?? 'current');
} elseif ($seasonId !== '' && $seasonId !== 'all') {
    foreach ($seasons as $season) {
        if (($season['id'] ?? '') === $seasonId) {
            $selectedSeason = $season;
            $groupGuids = $season['group_guids'] ?? [];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_offer') {
    $selectedGuids = array_map('strval', (array) ($_POST['material_guids'] ?? []));
    $discountPercent = (float) ($_POST['discount_percent'] ?? 15);
    $titleAr = trim((string) ($_POST['offer_title'] ?? ''));
    $result = StagnantInventoryService::createOfferFromSelection(
        $selectedGuids,
        $discountPercent,
        $titleAr,
        $userId
    );
    if ($result['ok'] && !empty($result['id'])) {
        header('Location: /dashboard/special-offers.php?edit=' . rawurlencode((string) $result['id']) . '&created=1');
        exit;
    }
    $flash = $result['message'] ?? 'تعذر إنشاء العرض.';
    $flashType = 'error';
}

$error = null;
$report = [
    'page' => $page,
    'pageSize' => $pageSize,
    'totalCount' => 0,
    'minAgeDays' => $minAgeDays,
    'untilDate' => $untilDate,
    'yearStartDate' => $yearStartDate,
    'items' => [],
];

$fetch = StagnantInventoryService::fetch([
    'minAgeDays' => $minAgeDays,
    'untilDate' => $untilDate,
    'yearStartDate' => $yearStartDate,
    'groupGuids' => $groupGuids,
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

$currentRoute = '/dashboard/stagnant-inventory.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/stagnant-inventory.php';
$content = ob_get_clean();
$title = 'المخزون الراكد';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
