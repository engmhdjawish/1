<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require __DIR__ . '/../bootstrap.php';

use Portal\Services\PriceCheckerService;

$self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'price-checker.php'));
$action = trim((string) ($_GET['action'] ?? ''));

if ($action !== '') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

if ($action === 'lookup') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!PriceCheckerService::isEnabled() || !PriceCheckerService::isIpAllowed()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $barcode = trim((string) ($_GET['barcode'] ?? ''));
    if ($barcode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'barcode_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $material = PriceCheckerService::lookupBarcode($barcode);
        if ($material === null) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'barcode' => $barcode], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode($material, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode(['error' => 'api_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'warmup') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!PriceCheckerService::isEnabled() || !PriceCheckerService::isIpAllowed()) {
        http_response_code(403);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        PriceCheckerService::warmConnection();
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'slideshow') {
    header('Content-Type: application/json; charset=utf-8');
    if (!PriceCheckerService::isEnabled() || !PriceCheckerService::isIpAllowed()) {
        http_response_code(403);
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $excludeRaw = trim((string) ($_GET['exclude'] ?? ''));
    $excludeGuids = $excludeRaw !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $excludeRaw))))
        : [];

    echo json_encode([
        'items' => PriceCheckerService::slideshowItems($forceRefresh, $excludeGuids),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = PriceCheckerService::config();
$clientIp = PriceCheckerService::clientIp();

if (!PriceCheckerService::isEnabled()) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><body style="font-family:sans-serif;text-align:center;padding:4rem">';
    echo '<h1>غير متاح</h1><p>فاحص الأسعار معطّل حالياً.</p></body></html>';
    exit;
}

if (!PriceCheckerService::isIpAllowed($clientIp)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html dir="rtl" lang="ar"><body style="font-family:sans-serif;text-align:center;padding:4rem">';
    echo '<h1>غير مسموح</h1><p>هذه الصفحة متاحة فقط من عناوين IP المصرّح بها.</p>';
    if ($clientIp !== '') {
        echo '<p style="color:#666;font-size:.9rem;margin-top:1rem">عنوانك الحالي: ' . htmlspecialchars($clientIp, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</body></html>';
    exit;
}

require dirname(__DIR__) . '/views/helpers.php';

$siteName = PriceCheckerService::siteName();
$pageTitle = trim((string) ($config['page_title_ar'] ?? ''));
if ($pageTitle === '') {
    $pageTitle = 'فاحص الأسعار';
}
$logoUrl = PriceCheckerService::logoUrl();
$displaySec = (int) ($config['display_seconds'] ?? 5);
$errorSec = (int) ($config['error_display_seconds'] ?? 5);
$promoInterval = (int) ($config['slideshow_interval_ms'] ?? 20000);
$promoShowPrice = (bool) ($config['slideshow_show_price'] ?? true);
$slideshowEnabled = (bool) ($config['slideshow_enabled'] ?? true);

require dirname(__DIR__) . '/views/price-checker-kiosk.php';
