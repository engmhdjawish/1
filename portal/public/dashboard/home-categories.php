<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\HomeCategoryService;
use Portal\Support\DashboardHttp;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$showForm = isset($_GET['new']) || $editId !== '';
$isNew = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_category') {
        $result = HomeCategoryService::saveCategory(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['label_ar'] ?? '')),
            trim((string) ($_POST['icon_key'] ?? '')),
            trim((string) ($_POST['link_url'] ?? '')),
            (int) ($_POST['sort_order'] ?? 0),
            isset($_POST['is_active'])
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            header('Location: /dashboard/home-categories.php?saved=1');
            exit;
        }
        $showForm = true;
        $editId = trim((string) ($_POST['id'] ?? ''));
        $isNew = $editId === '';
    } elseif ($action === 'toggle_category') {
        $ok = HomeCategoryService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1'
        );
        $flash = $ok ? 'تم تحديث حالة الفئة.' : 'تعذر تحديث حالة الفئة.';
        $flashType = $ok ? 'success' : 'error';
        if (DashboardHttp::wantsJson()) {
            DashboardHttp::json($ok, $flash, ['reload' => true]);
        }
    } elseif ($action === 'delete_category') {
        $deleteId = trim((string) ($_POST['id'] ?? ''));
        $deleteResult = HomeCategoryService::deleteCategory($deleteId);
        $flash = $deleteResult['message'];
        $flashType = $deleteResult['ok'] ? 'success' : 'error';
        if ($deleteResult['ok']) {
            header('Location: /dashboard/home-categories.php?deleted=1');
            exit;
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1' && $flash === null) {
    $flash = 'تم حفظ الفئة.';
    $flashType = 'success';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1' && $flash === null) {
    $flash = 'تم حذف الفئة.';
    $flashType = 'success';
}

$categories = HomeCategoryService::adminCategories();
$editCategory = [
    'id' => '',
    'label_ar' => '',
    'icon_key' => 'category',
    'link_url' => '/store.php',
    'sort_order' => 0,
    'is_active' => 1,
];
if ($editId !== '') {
    $loaded = HomeCategoryService::getById($editId);
    if (is_array($loaded)) {
        $editCategory = $loaded;
        $showForm = true;
        $isNew = false;
    }
}

$currentRoute = '/dashboard/home-categories.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/home-categories.php';
$content = ob_get_clean();
$title = 'فئات الرئيسية';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
