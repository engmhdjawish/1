<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\PriceCheckerService;

WebSession::requirePermission('price_checker.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$flashOk = '';
$flashError = '';
$viewerIp = PriceCheckerService::clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'save'));

    if ($action === 'add_current_ip') {
        $current = PriceCheckerService::config();
        $ips = PriceCheckerService::allowedIps();
        if ($viewerIp !== '' && filter_var($viewerIp, FILTER_VALIDATE_IP) && !in_array($viewerIp, $ips, true)) {
            $ips[] = $viewerIp;
            $current['allowed_ips'] = implode("\n", $ips);
            try {
                PriceCheckerService::save($current, $userId);
                $flashOk = 'تمت إضافة عنوان IP الحالي إلى القائمة المسموحة.';
            } catch (Throwable $e) {
                $flashError = $e->getMessage();
            }
        } elseif ($viewerIp === '') {
            $flashError = 'تعذّر تحديد عنوان IP الحالي.';
        } else {
            $flashOk = 'عنوان IP الحالي موجود مسبقاً في القائمة.';
        }
    } elseif ($action === 'clear_slideshow_cache') {
        PriceCheckerService::clearSlideshowCache();
        $flashOk = 'تم مسح ذاكرة التخزين المؤقت لإعلانات الشاشة.';
    } else {
        try {
            PriceCheckerService::save([
                'enabled' => isset($_POST['enabled']),
                'allowed_ips' => (string) ($_POST['allowed_ips'] ?? ''),
                'page_title_ar' => (string) ($_POST['page_title_ar'] ?? ''),
                'display_seconds' => (int) ($_POST['display_seconds'] ?? 5),
                'error_display_seconds' => (int) ($_POST['error_display_seconds'] ?? 5),
                'slideshow_enabled' => isset($_POST['slideshow_enabled']),
                'slideshow_count' => (int) ($_POST['slideshow_count'] ?? 5),
                'slideshow_interval_ms' => (int) ($_POST['slideshow_interval_ms'] ?? 20000),
                'slideshow_cache_seconds' => (int) ($_POST['slideshow_cache_seconds'] ?? 300),
                'slideshow_show_price' => isset($_POST['slideshow_show_price']),
                'slideshow_manufacturers' => (string) ($_POST['slideshow_manufacturers'] ?? ''),
            ], $userId);
            $flashOk = 'تم حفظ إعدادات فاحص الأسعار.';
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    }
}

$config = PriceCheckerService::config();
$allowedIps = PriceCheckerService::allowedIps();
$publicUrl = PriceCheckerService::publicUrl();
$legacyUrl = str_replace('price-checker.php', 'pc1.php', $publicUrl);
$ipAllowedForViewer = $viewerIp !== '' && in_array($viewerIp, $allowedIps, true);

$currentRoute = '/dashboard/price-checker.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/price-checker.php';
$content = ob_get_clean();
$title = 'فاحص الأسعار في المحل';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
