<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\StoreCatalogService;
use Portal\Services\StoreSeasonService;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$isNew = ($_GET['new'] ?? '') === '1';
$editSeason = null;

$groups = [];
$groupsError = null;
try {
    $groups = array_values(array_filter(
        (array) (StoreCatalogService::getCachedFilterOptions()['groups'] ?? []),
        static fn ($group): bool => is_array($group) && trim((string) ($group['guid'] ?? '')) !== ''
    ));
} catch (Throwable $exception) {
    $groupsError = 'تعذر جلب المجموعات: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_settings') {
        $result = StoreSeasonService::saveSettings([
            'mode' => (string) ($_POST['mode'] ?? 'off'),
            'manual_season_id' => (string) ($_POST['manual_season_id'] ?? ''),
            'show_to_customers' => isset($_POST['show_to_customers']),
        ], $userId);
        if ($result['ok']) {
            header('Location: /dashboard/store-seasons.php?saved=1');
            exit;
        }
        $flash = $result['message'];
        $flashType = 'error';
    } elseif ($action === 'save_season') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $input = [
            'name_ar' => (string) ($_POST['name_ar'] ?? ''),
            'customer_title_ar' => (string) ($_POST['customer_title_ar'] ?? ''),
            'icon' => (string) ($_POST['icon'] ?? ''),
            'group_guids' => array_map('strval', (array) ($_POST['group_guids'] ?? [])),
            'starts_month' => ($_POST['starts_month'] ?? '') !== '' ? (int) $_POST['starts_month'] : null,
            'starts_day' => (int) ($_POST['starts_day'] ?? 1),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        $result = StoreSeasonService::save($id !== '' ? $id : null, $input, $userId);
        if ($result['ok']) {
            header('Location: /dashboard/store-seasons.php?saved=1');
            exit;
        }
        $flash = $result['message'];
        $flashType = 'error';
        $editId = $id;
        $isNew = $id === '';
        $editSeason = StoreSeasonService::normalize($input + ['id' => $id]);
    } elseif ($action === 'delete_season') {
        if (StoreSeasonService::delete(trim((string) ($_POST['id'] ?? '')))) {
            header('Location: /dashboard/store-seasons.php?deleted=1');
            exit;
        }
        $flash = 'تعذر الحذف.';
        $flashType = 'error';
    } elseif ($action === 'seed_seasons') {
        $result = StoreSeasonService::seedSuggested($groups, $userId);
        if ($result['ok']) {
            header('Location: /dashboard/store-seasons.php?seeded=1');
            exit;
        }
        $flash = $result['message'];
        $flashType = 'error';
    }
}

if ($flash === null && ($_GET['saved'] ?? '') === '1') {
    $flash = 'تم الحفظ.';
}
if ($flash === null && ($_GET['deleted'] ?? '') === '1') {
    $flash = 'تم الحذف.';
}
if ($flash === null && ($_GET['seeded'] ?? '') === '1') {
    $flash = 'تم إنشاء المواسم. راجع المجموعات ثم اختر طريقة التبديل.';
}

$tableReady = StoreSeasonService::tableReady();
$seasons = StoreSeasonService::seasons();
$settings = StoreSeasonService::settings();
$now = time();
$current = StoreSeasonService::resolve($seasons, $settings, $now);
$nextSwitch = $settings['mode'] === 'auto' ? StoreSeasonService::nextSwitch($seasons, $current['id'] ?? null, $now) : null;

if ($editSeason === null && $editId !== '') {
    $editSeason = StoreSeasonService::getById($editId);
    if ($editSeason === null) {
        $editId = '';
    }
}
if ($editSeason === null && $isNew) {
    $editSeason = StoreSeasonService::normalize([
        'name_ar' => '',
        'icon' => 'wb_sunny',
        'sort_order' => $seasons !== [] ? max(array_column($seasons, 'sort_order')) + 10 : 10,
    ]);
}
$showForm = $editSeason !== null;

$groupNames = [];
foreach ($groups as $group) {
    $groupNames[strtolower((string) $group['guid'])] = trim((string) ($group['name'] ?? '')) ?: (string) $group['guid'];
}

$currentCount = null;
if ($current !== null && $current['group_guids'] !== [] && !$showForm) {
    $currentCount = StoreCatalogService::countMaterialsInGroups($current['group_guids']);
}

$timeline = [];
if ($seasons !== []) {
    $year = (int) date('Y', $now);
    for ($month = 1; $month <= 12; $month++) {
        $at = (int) mktime(12, 0, 0, $month, 15, $year);
        $timeline[$month] = StoreSeasonService::resolve($seasons, ['mode' => 'auto', 'manual_season_id' => '', 'show_to_customers' => true], $at);
    }
}

$suggested = $seasons === [] ? StoreSeasonService::suggestedSeasons($groups) : [];

$currentRoute = '/dashboard/store-seasons.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/store-seasons.php';
$content = ob_get_clean();
$title = 'مواسم العرض';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
