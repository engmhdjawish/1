<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;
use Portal\Support\DashboardHttp;

header('Content-Type: application/json; charset=utf-8');

WebSession::requireLogin();

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canManageCompany = $isSuper || in_array('company_settings.manage', $permissions, true);

if (!$canManageCompany) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'غير مصرح لك بهذه العملية.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logoUrl = trim((string) ($_POST['company_logo'] ?? ''));
if ($logoUrl === '') {
    $logoUrl = PortalSettingsService::companyLogoUrl() ?? '';
}

try {
    @set_time_limit(60);
    $ok = CompanyBrandIconService::regenerateFromLogoUrlSafe($logoUrl);
    $iconErr = CompanyBrandIconService::lastError();
    if (!$ok && is_string($iconErr) && trim($iconErr) !== '') {
        DashboardHttp::json(false, 'تعذر توليد أيقونات التطبيق: ' . trim($iconErr));
    }

    DashboardHttp::json(true, $logoUrl === '' ? 'تم مسح أيقونات التطبيق.' : 'تم توليد أيقونات التطبيق.');
} catch (\Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'تعذر توليد أيقونات التطبيق: ' . $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
