<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\SiteMediaService;

WebSession::requirePermission('site_media.manage');
require_once dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$activeCategory = trim((string) ($_GET['category'] ?? ''));
if (!in_array($activeCategory, SiteMediaService::CATEGORIES, true)) {
    $activeCategory = '';
}

if (isset($_SESSION['site_media_flash']) && is_array($_SESSION['site_media_flash'])) {
    $flash = trim((string) ($_SESSION['site_media_flash']['message'] ?? ''));
    $flashType = (string) ($_SESSION['site_media_flash']['type'] ?? 'success') === 'error' ? 'error' : 'success';
    unset($_SESSION['site_media_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $user = WebSession::user();
    $userId = isset($user['id']) ? (string) $user['id'] : null;

    try {
        if ($action === 'upload') {
            $result = SiteMediaService::upload(
                is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [],
                trim((string) ($_POST['category'] ?? 'banner')),
                trim((string) ($_POST['title_ar'] ?? '')),
                $userId
            );
            $_SESSION['site_media_flash'] = [
                'message' => $result['message'],
                'type' => $result['ok'] ? 'success' : 'error',
            ];
            if ($result['ok']) {
                $uploadCategory = trim((string) ($_POST['category'] ?? 'banner'));
                if (!in_array($uploadCategory, SiteMediaService::CATEGORIES, true)) {
                    $uploadCategory = 'banner';
                }
                $redirect = '/dashboard/site-media.php';
                if ($uploadCategory !== '') {
                    $redirect .= '?category=' . rawurlencode($uploadCategory);
                }
                header('Location: ' . $redirect);
                exit;
            }
            $flash = $result['message'];
            $flashType = 'error';
        } elseif ($action === 'delete') {
            $result = SiteMediaService::delete(trim((string) ($_POST['id'] ?? '')));
            $_SESSION['site_media_flash'] = [
                'message' => $result['message'],
                'type' => $result['ok'] ? 'success' : 'error',
            ];
            if ($result['ok']) {
                $redirectCategory = trim((string) ($_POST['redirect_category'] ?? ''));
                $suffix = in_array($redirectCategory, SiteMediaService::CATEGORIES, true)
                    ? '?category=' . rawurlencode($redirectCategory)
                    : '';
                header('Location: /dashboard/site-media.php' . $suffix);
                exit;
            }
            $flash = $result['message'];
            $flashType = 'error';
        }
    } catch (\Throwable $exception) {
        $flash = 'تعذر معالجة الطلب: ' . $exception->getMessage();
        $flashType = 'error';
    }
}

try {
    $assets = SiteMediaService::listAssets($activeCategory !== '' ? $activeCategory : null);
} catch (\Throwable $exception) {
    $assets = [];
    if ($flash === null) {
        $flash = 'تعذر تحميل مكتبة الصور: ' . $exception->getMessage();
        $flashType = 'error';
    }
}

$currentRoute = '/dashboard/site-media.php' . ($activeCategory !== '' ? '?category=' . rawurlencode($activeCategory) : '');

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/site-media.php';
$content = ob_get_clean();
$title = 'مكتبة صور الموقع';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
