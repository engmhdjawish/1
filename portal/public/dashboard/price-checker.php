<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
use Portal\Services\MaterialBatchService;
use Portal\Services\PriceCheckerService;
use Portal\Services\SpecialOfferService;

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
            $mode = trim((string) ($_POST['slideshow_mode'] ?? 'filter'));
            $useOfferPrices = isset($_POST['slideshow_use_offer_prices']);
            $offerPriceSlug = trim((string) ($_POST['slideshow_offer_price_slug'] ?? ''));
            $offerModeSlug = trim((string) ($_POST['slideshow_offer_slug'] ?? ''));

            $slideshowOfferSlug = '';
            if ($mode === 'offer') {
                $slideshowOfferSlug = $offerModeSlug;
                $useOfferPrices = true;
            } elseif ($useOfferPrices) {
                $slideshowOfferSlug = $offerPriceSlug;
            }

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
                'slideshow_mode' => $mode,
                'slideshow_filter_rules' => PriceCheckerService::parseFilterPayloadFromPost($_POST),
                'slideshow_offer_slug' => $slideshowOfferSlug,
                'slideshow_use_offer_prices' => $useOfferPrices,
                'slideshow_material_guids' => array_values(array_filter(array_map(
                    static fn ($guid): string => trim((string) $guid),
                    (array) ($_POST['manual_material_guids'] ?? [])
                ), static fn (string $guid): bool => $guid !== '')),
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

$manualProducts = [];
$manualGuids = is_array($config['slideshow_material_guids'] ?? null) ? $config['slideshow_material_guids'] : [];
if ($manualGuids !== []) {
    try {
        $materialsByGuid = MaterialBatchService::fetchByGuids($manualGuids);
        foreach ($manualGuids as $guid) {
            $item = $materialsByGuid[$guid] ?? null;
            if (is_array($item)) {
                $manualProducts[] = $item;
            }
        }
    } catch (Throwable $e) {
        $flashError = $flashError !== '' ? $flashError : ('تعذر تحميل المواد المختارة: ' . $e->getMessage());
    }
}

$materialFilterOptions = [
    'materialTypes' => [],
    'ageCategories' => [],
    'manufacturers' => [],
    'sizeRanges' => [],
    'countryOfOrigins' => [],
    'stores' => [],
    'groups' => [],
];
$materialFilterOptionsError = null;
try {
    $filtersResponse = ApiClient::get('/api/materials/filter-options');
    if ($filtersResponse['ok']) {
        $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
        $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);
        $materialFilterOptions = [
            'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
            'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
            'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
            'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
            'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
            'stores' => array_values(array_filter($stores, static fn ($row): bool => is_array($row))),
            'groups' => array_values(array_filter($groups, static fn ($row): bool => is_array($row))),
        ];
    } else {
        $materialFilterOptionsError = 'تعذر جلب فلاتر المواد من API (رمز ' . (int) ($filtersResponse['status'] ?? 0) . ').';
    }
} catch (Throwable $exception) {
    $materialFilterOptionsError = $exception->getMessage();
}

$specialOffers = [];
try {
    $specialOffers = SpecialOfferService::adminList();
} catch (Throwable $exception) {
    $flashError = $flashError !== '' ? $flashError : ('تعذر تحميل العروض الخاصة: ' . $exception->getMessage());
}

$currentRoute = '/dashboard/price-checker.php';
$extraScripts = '<script src="/assets/dashboard/price-checker-slideshow.js" defer></script>';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/price-checker.php';
$content = ob_get_clean();
$title = 'فاحص الأسعار في المحل';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
