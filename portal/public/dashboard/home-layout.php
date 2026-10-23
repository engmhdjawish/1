<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\HomeLayoutService;

WebSession::requirePermission('home_sections.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['preview'] ?? '') === '1') {
    HomeLayoutService::storeDraft(HomeLayoutService::fromForm($_POST));
    header('Location: /index.php?layout_preview=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $result = $action === 'reset'
        ? HomeLayoutService::reset($userId)
        : HomeLayoutService::save(HomeLayoutService::fromForm($_POST), $userId);
    if ($result['ok']) {
        header('Location: /dashboard/home-layout.php?saved=' . ($action === 'reset' ? 'reset' : '1'));
        exit;
    }
    $flash = $result['message'];
    $flashType = 'error';
}

if ($flash === null && ($_GET['saved'] ?? '') === '1') {
    $flash = 'تم حفظ التخطيط.';
}
if ($flash === null && ($_GET['saved'] ?? '') === 'reset') {
    $flash = 'تمت إعادة التخطيط الافتراضي.';
}

$tableReady = HomeLayoutService::tableReady();
$layout = $flashType === 'error' && $_SERVER['REQUEST_METHOD'] === 'POST'
    ? HomeLayoutService::fromForm($_POST)
    : HomeLayoutService::normalize(HomeLayoutService::storedConfig());
$definitions = HomeLayoutService::definitions();

$currentRoute = '/dashboard/home-layout.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/home-layout.php';
$content = ob_get_clean();
$title = 'تخطيط الرئيسية الجديدة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
