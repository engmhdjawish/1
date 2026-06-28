<?php

declare(strict_types=1);

ob_start();

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\CompanyBrandIconService;
use Portal\Services\PortalSettingsService;
use Portal\Support\DashboardHttp;

WebSession::requireLogin();

$user = WebSession::user();
$permissions = array_map('strval', $user['permissions'] ?? []);
$isSuper = in_array('*', $permissions, true);
$canManageCompany = $isSuper || in_array('company_settings.manage', $permissions, true);

if (!$canManageCompany) {
    DashboardHttp::emitJson(['ok' => false, 'message' => 'غير مصرح لك بهذه العملية.'], 403);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    DashboardHttp::emitJson(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$logoUrl = trim((string) ($_POST['company_logo'] ?? ''));
if ($logoUrl === '') {
    $logoUrl = PortalSettingsService::companyLogoUrl() ?? '';
}

try {
    @set_time_limit(60);
    ob_start();
    $ok = CompanyBrandIconService::regenerateFromLogoUrlSafe($logoUrl);
    ob_end_clean();
    $iconErr = CompanyBrandIconService::lastError();
    if (!$ok && is_string($iconErr) && trim($iconErr) !== '') {
        DashboardHttp::json(false, 'تعذر توليد أيقونات التطبيق: ' . trim($iconErr));
    }

    DashboardHttp::json(true, $logoUrl === '' ? 'تم مسح أيقونات التطبيق.' : 'تم توليد أيقونات التطبيق.');
} catch (\Throwable $exception) {
    DashboardHttp::json(false, 'تعذر توليد أيقونات التطبيق: ' . $exception->getMessage());
}
