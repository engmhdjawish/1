<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\SiteMediaService;

WebSession::requireLogin();
$userPermissions = WebSession::user()['permissions'] ?? [];
$canManageMedia = in_array('*', $userPermissions, true)
    || in_array('site_media.manage', $userPermissions, true)
    || in_array('home_sections.manage', $userPermissions, true)
    || in_array('special_offers.manage', $userPermissions, true);
if (!$canManageMedia) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'غير مصرح لك بهذه العملية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

if ($method === 'GET') {
    $category = trim((string) ($_GET['category'] ?? ''));
    $items = SiteMediaService::listAssets($category !== '' ? $category : null);
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST') {
    try {
        $action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'upload'));
        if ($action === 'delete') {
            $id = trim((string) ($_POST['id'] ?? ''));
            $result = SiteMediaService::delete($id);
            http_response_code($result['ok'] ? 200 : 400);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $category = trim((string) ($_POST['category'] ?? 'banner'));
        $titleAr = trim((string) ($_POST['title_ar'] ?? ''));
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'لم يتم إرسال ملف.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = SiteMediaService::upload($file, $category, $titleAr, $userId);
        if ($result['ok'] && is_array($result['asset'] ?? null)) {
            $storagePath = SiteMediaService::absolutePathForId((string) ($result['asset']['id'] ?? ''));
            if (is_string($storagePath) && $storagePath !== '') {
                SiteMediaService::rasterizeSvgCompanionSafe($storagePath);
            }
        }
        http_response_code($result['ok'] ? 200 : 400);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (\Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'تعذر معالجة الطلب: ' . $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
