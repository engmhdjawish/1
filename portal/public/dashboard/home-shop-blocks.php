<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\HomeShopBlockService;
use Portal\Services\StoreCatalogService;
use Portal\Support\DashboardHttp;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$isNew = ($_GET['new'] ?? '') === '1';
$showForm = $editId !== '' || $isNew;
$editBlock = null;

$valuesFromPost = static function (string $field, string $param): array {
    $all = $_POST[$field] ?? [];
    $values = is_array($all) && is_array($all[$param] ?? null) ? $all[$param] : [];

    return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_block') {
        $primary = trim((string) ($_POST['primary_param'] ?? ''));
        $secondary = trim((string) ($_POST['secondary_param'] ?? ''));
        $tiles = [];
        foreach ((array) ($_POST['tiles'] ?? []) as $tile) {
            if (is_array($tile)) {
                $tiles[] = [
                    'label' => (string) ($tile['label'] ?? ''),
                    'image' => (string) ($tile['image'] ?? ''),
                    'filters' => is_array($tile['filters'] ?? null) ? $tile['filters'] : [],
                    'available' => !empty($tile['available']),
                ];
            }
        }
        $input = [
            'title_ar' => (string) ($_POST['title_ar'] ?? ''),
            'block_type' => (string) ($_POST['block_type'] ?? 'facet'),
            'primary_param' => $primary,
            'secondary_param' => $secondary,
            'primary_values' => $valuesFromPost('primary_values', $primary),
            'secondary_values' => $valuesFromPost('secondary_values', $secondary),
            'tiles' => $tiles,
            'style' => (string) ($_POST['style'] ?? 'icons'),
            'placement' => (string) ($_POST['placement'] ?? 'tabs'),
            'max_items' => (int) ($_POST['max_items'] ?? 12),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'is_active' => isset($_POST['is_active']),
        ];
        $id = trim((string) ($_POST['id'] ?? ''));
        $result = HomeShopBlockService::save($id !== '' ? $id : null, $input, $userId);
        if ($result['ok']) {
            header('Location: /dashboard/home-shop-blocks.php?saved=1');
            exit;
        }
        $flash = $result['message'];
        $flashType = 'error';
        $showForm = true;
        $editId = $id;
        $isNew = $id === '';
        $editBlock = HomeShopBlockService::normalize($input + ['id' => $id]);
        $editBlock['tiles'] = $tiles;
    } elseif ($action === 'toggle_block') {
        $ok = HomeShopBlockService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1',
            $userId
        );
        $flash = $ok ? 'تم تحديث الحالة.' : 'تعذر تحديث الحالة.';
        $flashType = $ok ? 'success' : 'error';
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($ok, $flash, ['reload' => true]);
        }
    } elseif ($action === 'delete_block') {
        $ok = HomeShopBlockService::delete(trim((string) ($_POST['id'] ?? '')));
        if ($ok) {
            header('Location: /dashboard/home-shop-blocks.php?deleted=1');
            exit;
        }
        $flash = 'تعذر الحذف.';
        $flashType = 'error';
    } elseif ($action === 'seed_defaults') {
        $ok = HomeShopBlockService::seedDefaults($userId);
        if ($ok) {
            header('Location: /dashboard/home-shop-blocks.php?saved=1');
            exit;
        }
        $flash = 'تعذر نسخ المجموعات التلقائية.';
        $flashType = 'error';
    }
}

if ($flash === null && ($_GET['saved'] ?? '') === '1') {
    $flash = 'تم الحفظ.';
}
if ($flash === null && ($_GET['deleted'] ?? '') === '1') {
    $flash = 'تم الحذف.';
}

$tableReady = HomeShopBlockService::tableReady();
$blocks = HomeShopBlockService::adminBlocks();
$usingDefaults = $blocks === [];
if ($editBlock === null && $editId !== '') {
    $editBlock = HomeShopBlockService::getById($editId);
    if ($editBlock === null) {
        $editId = '';
        $showForm = $isNew;
    }
}
if ($showForm && $editBlock === null) {
    $editBlock = HomeShopBlockService::blankBlock();
    $editBlock['sort_order'] = $blocks !== [] ? max(array_column($blocks, 'sort_order')) + 10 : 10;
}

$facetOptions = [];
$facetOptionsError = null;
try {
    $options = StoreCatalogService::getCachedFilterOptions();
} catch (Throwable $exception) {
    $options = [];
    $facetOptionsError = 'تعذر جلب قيم الفلاتر: ' . $exception->getMessage();
}
foreach (HomeShopBlockService::FACETS as $param => $_) {
    $values = [];
    foreach ((array) ($options[$param] ?? []) as $value) {
        $value = trim((string) (is_array($value) ? ($value['value'] ?? '') : $value));
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }
    $facetOptions[$param] = $values;
}

$hiddenFacets = [];
foreach (HomeShopBlockService::FACETS as $param => $label) {
    if (!store_easy_client_filter_visible($param)) {
        $hiddenFacets[] = $label;
    }
}

$currentRoute = '/dashboard/home-shop-blocks.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/home-shop-blocks.php';
$content = ob_get_clean();
$title = 'تسوّق حسب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
