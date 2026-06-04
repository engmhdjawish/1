<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ShareLinkService;

WebSession::requirePermission('share_links.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$editLink = null;
$user = WebSession::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save') {
        $result = ShareLinkService::save(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['access_policy_id'] ?? '')),
            isset($_POST['require_password']),
            trim((string) ($_POST['access_username'] ?? '')),
            trim((string) ($_POST['plain_password'] ?? '')),
            trim((string) ($_POST['keyword'] ?? '')),
            (float) ($_POST['min_quantity'] ?? 0),
            trim((string) ($_POST['expires_at'] ?? '')),
            isset($_POST['is_active']),
            isset($user['id']) ? (string) $user['id'] : null
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $editId = (string) ($result['id'] ?? '');
        }
    } elseif ($action === 'toggle') {
        $ok = ShareLinkService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1'
        );
        $flash = $ok ? 'تم تحديث حالة الرابط.' : 'تعذر تحديث حالة الرابط.';
        $flashType = $ok ? 'success' : 'error';
    }
}

$filters = [
    'active' => trim((string) ($_GET['active'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 100),
];

$links = ShareLinkService::list($filters);
if ($editId !== '') {
    $editLink = ShareLinkService::getById($editId);
}
if ($editLink === null) {
    $editId = '';
}
$stats = ShareLinkService::stats();
$policies = ShareLinkService::listAccessPolicies();

$currentRoute = '/dashboard/share-links.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/share-links.php';
$content = ob_get_clean();
$title = 'روابط المشاركة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
