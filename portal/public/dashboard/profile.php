<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\WebUserService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = (string) ($user['id'] ?? '');
$profile = WebUserService::getUserById($userId) ?? [];

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $result = WebUserService::changeOwnPassword(
        $userId,
        trim((string) ($_POST['current_password'] ?? '')),
        trim((string) ($_POST['new_password'] ?? ''))
    );
    $flash = $result['message'];
    $flashType = $result['ok'] ? 'success' : 'error';
}

$title = 'حسابي';
$currentRoute = '/dashboard/profile.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/profile.php';
$content = ob_get_clean();
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
