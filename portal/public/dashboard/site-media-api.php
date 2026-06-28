<?php

declare(strict_types=1);

ob_start();

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\SiteMediaService;
use Portal\Support\DashboardHttp;

WebSession::requireLogin();
$userPermissions = WebSession::user()['permissions'] ?? [];
$canManageMedia = in_array('*', $userPermissions, true)
    || in_array('site_media.manage', $userPermissions, true)
    || in_array('home_sections.manage', $userPermissions, true)
    || in_array('special_offers.manage', $userPermissions, true);
if (!$canManageMedia) {
    DashboardHttp::emitJson(['ok' => false, 'message' => 'غير مصرح لك بهذه العملية.'], 403);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

if ($method === 'GET') {
    $category = trim((string) ($_GET['category'] ?? ''));
    $items = SiteMediaService::listAssets($category !== '' ? $category : null);
    DashboardHttp::emitJson(['ok' => true, 'items' => $items]);
}

if ($method === 'POST') {
    try {
        $action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'upload'));
        if ($action === 'delete') {
            $id = trim((string) ($_POST['id'] ?? ''));
            $result = SiteMediaService::delete($id);
            DashboardHttp::emitJson($result, $result['ok'] ? 200 : 400);
        }

        $category = trim((string) ($_POST['category'] ?? 'banner'));
        $titleAr = trim((string) ($_POST['title_ar'] ?? ''));
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            DashboardHttp::emitJson(['ok' => false, 'message' => 'لم يتم إرسال ملف.'], 400);
        }

        $result = SiteMediaService::upload($file, $category, $titleAr, $userId);
        if ($result['ok'] && is_array($result['asset'] ?? null)) {
            $storagePath = SiteMediaService::absolutePathForId((string) ($result['asset']['id'] ?? ''));
            if (is_string($storagePath) && $storagePath !== '') {
                ob_start();
                try {
                    SiteMediaService::rasterizeSvgCompanionSafe($storagePath);
                } catch (\Throwable) {
                    // Optional companion file — upload must still succeed.
                }
                ob_end_clean();
            }
        }
        DashboardHttp::emitJson($result, $result['ok'] ? 200 : 400);
    } catch (\Throwable $exception) {
        DashboardHttp::emitJson([
            'ok' => false,
            'message' => 'تعذر معالجة الطلب: ' . $exception->getMessage(),
        ], 500);
    }
}

DashboardHttp::emitJson(['ok' => false, 'message' => 'Method not allowed.'], 405);
