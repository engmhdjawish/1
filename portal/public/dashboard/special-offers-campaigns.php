<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\SpecialOfferAnalyticsService;
use Portal\Services\SpecialOfferService;

WebSession::requirePermission('special_offers.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$allowedOfferTabs = ['active', 'scheduled', 'paused', 'ended', 'all'];
$offerTab = trim((string) ($_GET['tab'] ?? 'active'));
if (!in_array($offerTab, $allowedOfferTabs, true)) {
    $offerTab = 'active';
}

$includeEngagement = (($_GET['engagement'] ?? '') === '1');
$focusOfferId = trim((string) ($_GET['offer'] ?? ''));

$dbError = null;
$offers = [];
try {
    $offers = SpecialOfferService::adminList();
} catch (\Throwable $e) {
    $dbError = 'تعذر تحميل العروض: ' . $e->getMessage();
}

$offerLifecycle = static function (array $row): string {
    $now = time();
    $ends = trim((string) ($row['ends_at'] ?? ''));
    $starts = trim((string) ($row['starts_at'] ?? ''));
    $endsTs = $ends !== '' ? strtotime($ends) : false;
    if ($endsTs !== false && $endsTs <= $now) {
        return 'ended';
    }
    if (empty($row['is_active'])) {
        return 'paused';
    }
    $startsTs = $starts !== '' ? strtotime($starts) : false;
    if ($startsTs !== false && $startsTs > $now) {
        return 'scheduled';
    }

    return 'active';
};

$campaignProgressPct = static function (array $row) use ($offerLifecycle): ?int {
    $lifecycle = $offerLifecycle($row);
    if ($lifecycle === 'ended') {
        return 100;
    }
    $starts = trim((string) ($row['starts_at'] ?? ''));
    $ends = trim((string) ($row['ends_at'] ?? ''));
    $startsTs = $starts !== '' ? strtotime($starts) : false;
    $endsTs = $ends !== '' ? strtotime($ends) : false;
    if ($startsTs === false || $endsTs === false || $endsTs <= $startsTs) {
        return null;
    }
    $now = time();
    if ($now < $startsTs) {
        return 0;
    }
    if ($now > $endsTs) {
        return 100;
    }

    return (int) round((($now - $startsTs) / ($endsTs - $startsTs)) * 100);
};

$visibleOffers = [];
$offerTabCounts = ['active' => 0, 'scheduled' => 0, 'paused' => 0, 'ended' => 0, 'all' => count($offers)];
foreach ($offers as $row) {
    if (!is_array($row)) {
        continue;
    }
    $lifecycle = $offerLifecycle($row);
    $row['_lifecycle'] = $lifecycle;
    $row['_progress_pct'] = $campaignProgressPct($row);
    $offerTabCounts[$lifecycle] = ($offerTabCounts[$lifecycle] ?? 0) + 1;
    if ($offerTab === 'all' || $lifecycle === $offerTab) {
        $visibleOffers[] = $row;
    }
}

$offerIds = array_values(array_filter(array_map(
    static fn (array $row): string => trim((string) ($row['id'] ?? '')),
    $visibleOffers
)));

$salesByOffer = SpecialOfferAnalyticsService::summariesForOffers(
    $offerIds,
    ['include_engagement' => $includeEngagement]
);

$campaignTotals = [
    'orders' => 0,
    'packages' => 0.0,
    'revenue_syp' => 0.0,
    'discount_syp' => 0.0,
    'page_views' => 0,
    'add_to_cart' => 0,
];
foreach ($offerIds as $id) {
    $summary = $salesByOffer[$id] ?? [];
    $campaignTotals['orders'] += (int) ($summary['orders'] ?? 0);
    $campaignTotals['packages'] += (float) ($summary['packages'] ?? 0);
    $campaignTotals['revenue_syp'] += (float) ($summary['revenue_syp'] ?? 0);
    $campaignTotals['discount_syp'] += (float) ($summary['discount_syp'] ?? 0);
    $campaignTotals['page_views'] += (int) ($summary['page_views'] ?? 0);
    $campaignTotals['add_to_cart'] += (int) ($summary['add_to_cart'] ?? 0);
}

$focusAnalytics = null;
$focusOffer = null;
if ($focusOfferId !== '') {
    foreach ($offers as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $focusOfferId) {
            $focusOffer = $row;
            break;
        }
    }
    if ($focusOffer !== null) {
        try {
            $focusAnalytics = SpecialOfferAnalyticsService::detailForOffer($focusOfferId);
        } catch (\Throwable) {
            $focusAnalytics = null;
        }
    }
}

$currentRoute = '/dashboard/special-offers-campaigns.php';
ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/special-offers-campaigns.php';
$content = ob_get_clean();
$title = 'متابعة حملات العروض';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';