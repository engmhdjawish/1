<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\SiteMediaService;

WebSession::requirePermission('site_media.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$activeCategory = trim((string) ($_GET['category'] ?? ''));
if (!in_array($activeCategory, SiteMediaService::CATEGORIES, true)) {
    $activeCategory = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $user = WebSession::user();
    $userId = isset($user['id']) ? (string) $user['id'] : null;

    if ($action === 'upload') {
        $result = SiteMediaService::upload(
            is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [],
            trim((string) ($_POST['category'] ?? 'banner')),
            trim((string) ($_POST['title_ar'] ?? '')),
            $userId
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $uploadCategory = trim((string) ($_POST['category'] ?? 'banner'));
            if (!in_array($uploadCategory, SiteMediaService::CATEGORIES, true)) {
                $uploadCategory = 'banner';
            }
            header('Location: /dashboard/site-media.php?category=' . rawurlencode($uploadCategory) . '&uploaded=1');
            exit;
        }
    } elseif ($action === 'delete') {
        $result = SiteMediaService::delete(trim((string) ($_POST['id'] ?? '')));
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $redirectCategory = trim((string) ($_POST['redirect_category'] ?? ''));
            $suffix = in_array($redirectCategory, SiteMediaService::CATEGORIES, true)
                ? '?category=' . rawurlencode($redirectCategory) . '&deleted=1'
                : '?deleted=1';
            header('Location: /dashboard/site-media.php' . $suffix);
            exit;
        }
    }
}

if (isset($_GET['uploaded']) && $_GET['uploaded'] === '1' && $flash === null) {
    $flash = 'تم رفع الصورة.';
}
if (isset($_GET['deleted']) && $_GET['deleted'] === '1' && $flash === null) {
    $flash = 'تم حذف الصورة.';
}

$assets = SiteMediaService::listAssets($activeCategory !== '' ? $activeCategory : null);
$currentRoute = '/dashboard/site-media.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/site-media.php';
$content = ob_get_clean();
$title = 'مكتبة صور الموقع';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
