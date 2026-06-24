<?php

declare(strict_types=1);

use Portal\Auth\WebSession;
use Portal\Services\CatalogSectionResolver;
use Portal\Services\ShareCartService;

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_request_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
}

function portal_asset_url(string $webPath): string
{
    $webPath = '/' . ltrim($webPath, '/');
    $file = dirname(__DIR__) . '/public' . $webPath;
    if (is_file($file)) {
        return $webPath . '?v=' . (string) filemtime($file);
    }

    return $webPath;
}

function portal_is_catalog_page(string $path): bool
{
    return in_array($path, ['/index.php', '/store.php', '/product.php', '/share.php'], true);
}

function web_can(string $permission): bool
{
    return WebSession::hasPermission($permission);
}

/** @param list<string> $permissions */
function web_can_any(array $permissions): bool
{
    return WebSession::hasAnyPermission($permissions);
}

function format_money(?float $amount, bool $show): string
{
    if (!$show || $amount === null) {
        return '—';
    }

    return number_format($amount, 0, '.', ',');
}

/** Character count without requiring ext-mbstring (common on Windows PHP builds). */
function text_length(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    if (preg_match_all('/./us', $value, $matches) === 1) {
        return count($matches[0]);
    }

    return strlen($value);
}

function format_decimal(mixed $amount, int $decimals = 2): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }

    if (!is_numeric($amount)) {
        return (string) $amount;
    }

    return number_format((float) $amount, $decimals, '.', ',');
}

function format_accounting_money(mixed $amount, ?string $symbol = null, ?string $code = null): string
{
    $formatted = format_decimal($amount);
    if ($formatted === '—') {
        return $formatted;
    }

    $symbol = trim((string) $symbol);
    if ($symbol !== '') {
        return $formatted . ' ' . $symbol;
    }

    $code = trim((string) $code);
    if ($code !== '') {
        return $formatted . ' ' . $code;
    }

    return $formatted;
}

/** @param array<string, scalar|null> $params */
function accounting_url(string $path, array $params = []): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $filtered[$key] = $text;
        }
    }

    return $path . ($filtered === [] ? '' : ('?' . http_build_query($filtered)));
}

function accounting_document_kind(?string $reasonType): ?string
{
    $normalized = strtolower(trim((string) $reasonType));
    if ($normalized === 'invoice') {
        return 'invoices';
    }
    if ($normalized === 'payment') {
        return 'vouchers';
    }

    return null;
}

/** @param array<string, mixed> $item */
function accounting_material_label(array $item): string
{
    $code = trim((string) ($item['materialCode'] ?? ''));
    $name = trim((string) ($item['materialName'] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }

    return $name !== '' ? $name : ($code !== '' ? $code : '—');
}

/** @param list<array<string, mixed>> $items */
function accounting_invoice_unit_header(array $items, string $field, string $fallback): string
{
    foreach ($items as $item) {
        $value = trim((string) ($item[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function accounting_format_date(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('Y-m-d', $timestamp);
}

function material_guid(array $item): string
{
    return trim((string) ($item['materialGuid'] ?? $item['MaterialGuid'] ?? $item['guid'] ?? $item['Guid'] ?? ''));
}

function material_image_guid(array $item): string
{
    foreach (['productImageGuid', 'ProductImageGuid', 'imageGuid', 'ImageGuid', 'pictureGuid', 'PictureGUID'] as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function material_image_api_url(string $imageGuid, bool $thumb = true): string
{
    return \Portal\Services\MaterialImageStorageService::imageGuidUrl($imageGuid, $thumb);
}

/** يحوّل رابط الصورة المصغّرة إلى نسخة كاملة للتكبير. */
function material_image_zoom_url(string $imageUrl): string
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '') {
        return '';
    }
    if (str_contains($imageUrl, 'thumb=1')) {
        return str_replace('thumb=1', 'thumb=0', $imageUrl);
    }
    if (!str_contains($imageUrl, 'thumb=')) {
        return str_contains($imageUrl, '?') ? $imageUrl . '&thumb=0' : $imageUrl . '?thumb=0';
    }

    return $imageUrl;
}

/**
 * بيانات معاينة صورة المادة في المتجر (lightbox مع أسعار وسلة).
 *
 * @param array<string, mixed> $item
 * @param array<string, mixed> $displayOptions
 * @return array<string, mixed>
 */
function product_preview_payload(
    array $item,
    array $displayOptions,
    float $cartQtyForItem = 0.0,
    ?string $returnUrl = null,
    ?string $offerSlug = null
): array {
    $priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
    $showPriceSyp = in_array($priceMode, ['both', 'syp'], true);
    $showPriceUsd = in_array($priceMode, ['both', 'usd'], true);
    $showPrice = (bool) ($displayOptions['show_price'] ?? false);
    $showQuantity = (bool) ($displayOptions['show_quantity'] ?? false);
    $allowCart = (bool) ($displayOptions['allow_cart'] ?? false);
    $hasOffer = !empty($item['has_offer']);

    $packaging = \Portal\Services\ShareCartService::packaging($item);
    $primaryUnit = \Portal\Services\ShareCartService::primaryUnitLabel($item);
    $packageUnit = \Portal\Services\ShareCartService::packageUnitLabel($item);
    $unitSaleSp = \Portal\Services\ShareCartService::unitSalePriceSp($item);
    $unitSaleUsd = \Portal\Services\ShareCartService::unitSalePriceUsd($item);
    $packageSaleSp = \Portal\Services\ShareCartService::packageSalePriceSp($item);
    $packageSaleUsd = \Portal\Services\ShareCartService::packageSalePriceUsd($item);

    $imageGuid = material_image_guid($item);
    $thumbUrl = $imageGuid !== '' ? material_image_api_url($imageGuid, true) : '';
    $zoomUrl = $thumbUrl !== '' ? material_image_zoom_url($thumbUrl) : '';

    $guid = material_guid($item);
    $returnUrl = $returnUrl ?? ($_SERVER['REQUEST_URI'] ?? '/store.php');
    $cartQtyForItem = max(0, (float) $cartQtyForItem);
    $qtyBounds = store_cart_qty_bounds($item, $cartQtyForItem, $showQuantity);
    $maxPackages = \Portal\Services\StorePolicyService::maxPackagesPerMaterial();
    $maxLabel = $maxPackages !== null
        ? \Portal\Services\SpecialOfferService::formatQuantityLabel($maxPackages)
        : null;
    $remaining = $qtyBounds['effectiveMax'];
    $atLimit = $qtyBounds['atLimit'];
    $packagesAvailable = (float) ($qtyBounds['stockAvailable'] ?? 0.0);

    return [
        'guid' => $guid,
        'name' => (string) ($item['name'] ?? ''),
        'code' => (string) ($item['materialCode'] ?? $item['code'] ?? ''),
        'manufacturer' => (string) ($item['manufacturer'] ?? ''),
        'materialType' => (string) ($item['materialType'] ?? ''),
        'thumbUrl' => $thumbUrl,
        'zoomUrl' => $zoomUrl,
        'detailUrl' => $guid !== '' ? product_url($guid, $returnUrl, $offerSlug) : '',
        'showPrice' => $showPrice,
        'showPriceSyp' => $showPriceSyp,
        'showPriceUsd' => $showPriceUsd,
        'showQuantity' => $showQuantity,
        'packagesAvailable' => $packagesAvailable,
        'packagesAvailableLabel' => $showQuantity ? format_packages_display($packagesAvailable) : '',
        'packaging' => $packaging,
        'primaryUnit' => $primaryUnit,
        'packageUnit' => $packageUnit,
        'hasOffer' => $hasOffer,
        'offerBadge' => trim((string) ($item['offer_badge'] ?? '')),
        'unitSaleSp' => $unitSaleSp,
        'unitSaleUsd' => $unitSaleUsd,
        'packageSaleSp' => $packageSaleSp,
        'packageSaleUsd' => $packageSaleUsd,
        'originalUnitSp' => $hasOffer ? (float) ($item['original_unit_sale_price_sp'] ?? 0) : 0.0,
        'originalUnitUsd' => $hasOffer ? (float) ($item['original_unit_sale_price_usd'] ?? 0) : 0.0,
        'originalPackSp' => $hasOffer ? (float) ($item['original_package_sale_price_sp'] ?? 0) : 0.0,
        'originalPackUsd' => $hasOffer ? (float) ($item['original_package_sale_price_usd'] ?? 0) : 0.0,
        'allowCart' => $allowCart,
        'cartQty' => max(0, $cartQtyForItem),
        'maxPackages' => $maxPackages,
        'maxLabel' => $maxLabel,
        'remaining' => $remaining,
        'atLimit' => $atLimit,
        'defaultQty' => $qtyBounds['defaultQty'],
        'qtyStep' => $qtyBounds['qtyStep'],
        'qtyMin' => $qtyBounds['qtyMin'],
        'partialPackage' => $qtyBounds['partialPackage'],
        'effectiveMax' => $qtyBounds['effectiveMax'],
        'returnUrl' => strtok((string) $returnUrl, '#'),
    ];
}

function product_url(string $guid, ?string $return = null, ?string $offer = null): string
{
    $guid = trim($guid);
    if ($guid === '') {
        return '/store.php';
    }

    $params = ['guid' => $guid];
    if ($return !== null && trim($return) !== '') {
        $params['return'] = safe_return_url($return);
    }
    $offer = trim((string) $offer);
    if ($offer !== '') {
        $params['offer'] = $offer;
    }

    return '/product.php?' . http_build_query($params);
}

function safe_return_url(mixed $return): string
{
    $return = trim((string) $return);
    if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
        return '/store.php';
    }

    return $return;
}

function return_link_label(string $returnUrl): string
{
    if ($returnUrl === '/' || str_starts_with($returnUrl, '/#') || str_contains($returnUrl, 'index.php')) {
        return 'العودة للرئيسية';
    }
    if (str_contains($returnUrl, 'store.php')) {
        return 'العودة للمتجر';
    }

    return 'رجوع';
}

/** @param array<string, mixed> $section */
function home_section_store_url(array $section): string
{
    return store_url(CatalogSectionResolver::storeLinkParams($section));
}

/** @param array<string, mixed> $section */
function home_section_return_url(array $section): string
{
    $slug = trim((string) ($section['slug'] ?? ''));

    return $slug !== '' ? '/#' . $slug : '/';
}

/** @param array<string, mixed> $params */
function store_url(array $params = []): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_array($value)) {
            $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
            if ($items === []) {
                continue;
            }
            $filtered[$key] = $items;
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            $filtered[$key] = $text;
        }
    }

    $query = http_build_query($filtered);

    return '/store.php' . ($query !== '' ? '?' . $query : '');
}

function order_tracking_url(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    return '/track-order.php?token=' . rawurlencode($token);
}

function absolute_order_tracking_url(string $token): string
{
    $path = order_tracking_url($token);
    if ($path === '') {
        return '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $path;
    }

    return $scheme . '://' . $host . $path;
}

function format_packaging(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
}

function format_packages_display(float $qty): string
{
    return \Portal\Services\StockReservationService::formatPackages($qty);
}

/** @param array<string, mixed> $line */
function store_line_has_offer(array $line): bool
{
    if (!empty($line['has_offer']) || !empty($line['special_offer_id'])) {
        return true;
    }

    $origSp = (float) ($line['original_sale_price_sp'] ?? $line['original_package_sale_price_sp'] ?? 0);
    $saleSp = (float) ($line['sale_price_sp'] ?? 0);
    if ($origSp > 0 && $saleSp > 0 && $origSp > $saleSp + 0.009) {
        return true;
    }

    $origUsd = (float) ($line['original_sale_price_usd'] ?? $line['original_package_sale_price_usd'] ?? 0);
    $saleUsd = (float) ($line['sale_price_usd'] ?? 0);

    return $origUsd > 0 && $saleUsd > 0 && $origUsd > $saleUsd + 0.009;
}

/** @param array<string, mixed> $line */
function store_line_offer_badge(array $line): string
{
    $badge = trim((string) ($line['offer_badge'] ?? ''));
    if ($badge !== '') {
        return $badge;
    }

    $title = trim((string) ($line['offer_title_ar'] ?? ''));

    return $title !== '' ? $title : 'عرض خاص';
}

/**
 * @param array<string, mixed> $item
 * @return array{
 *   packaging: float,
 *   primary_unit: string,
 *   package_unit: string,
 *   quantity: float,
 *   unit_sp: float,
 *   unit_usd: float,
 *   pack_sp: float,
 *   pack_usd: float,
 *   orig_unit_sp: float,
 *   orig_unit_usd: float,
 *   orig_pack_sp: float,
 *   orig_pack_usd: float,
 *   line_total_sp: float,
 *   line_total_usd: float
 * }
 */
function store_order_line_prices(array $item): array
{
    $packaging = max(1.0, (float) ($item['packaging'] ?? $item['pcs_per_box'] ?? 1));
    $primaryUnit = trim((string) ($item['primary_unit'] ?? '')) ?: 'زوج';
    $packageUnit = trim((string) ($item['package_unit'] ?? '')) ?: 'طرد';
    $quantity = max(0.0, (float) ($item['quantity'] ?? $item['packages_count'] ?? 0));

    $packSp = (float) ($item['sale_price_sp'] ?? 0);
    $packUsd = (float) ($item['sale_price_usd'] ?? 0);
    $unitSp = (float) ($item['unit_sale_price_sp'] ?? 0);
    $unitUsd = (float) ($item['unit_sale_price_usd'] ?? 0);

    if ($unitSp <= 0 && $packSp > 0) {
        $unitSp = $packSp / $packaging;
    }
    if ($unitUsd <= 0 && $packUsd > 0) {
        $unitUsd = $packUsd / $packaging;
    }
    if ($packSp <= 0 && $unitSp > 0) {
        $packSp = $unitSp * $packaging;
    }
    if ($packUsd <= 0 && $unitUsd > 0) {
        $packUsd = $unitUsd * $packaging;
    }

    $origPackSp = (float) ($item['original_sale_price_sp'] ?? $item['original_package_sale_price_sp'] ?? 0);
    $origPackUsd = (float) ($item['original_sale_price_usd'] ?? $item['original_package_sale_price_usd'] ?? 0);
    $origUnitSp = (float) ($item['original_unit_sale_price_sp'] ?? 0);
    $origUnitUsd = (float) ($item['original_unit_sale_price_usd'] ?? 0);
    if ($origUnitSp <= 0 && $origPackSp > 0) {
        $origUnitSp = $origPackSp / $packaging;
    }
    if ($origUnitUsd <= 0 && $origPackUsd > 0) {
        $origUnitUsd = $origPackUsd / $packaging;
    }

    $lineTotalSp = (float) ($item['line_total_sp'] ?? ($quantity * $packSp));
    $lineTotalUsd = (float) ($item['line_total_usd'] ?? ($quantity * $packUsd));

    return [
        'packaging' => $packaging,
        'primary_unit' => $primaryUnit,
        'package_unit' => $packageUnit,
        'quantity' => $quantity,
        'unit_sp' => $unitSp,
        'unit_usd' => $unitUsd,
        'pack_sp' => $packSp,
        'pack_usd' => $packUsd,
        'orig_unit_sp' => $origUnitSp,
        'orig_unit_usd' => $origUnitUsd,
        'orig_pack_sp' => $origPackSp,
        'orig_pack_usd' => $origPackUsd,
        'line_total_sp' => $lineTotalSp,
        'line_total_usd' => $lineTotalUsd,
    ];
}

/**
 * @return array{
 *   policyRemaining: float|null,
 *   stockAvailable: float|null,
 *   effectiveMax: float|null,
 *   defaultQty: float,
 *   qtyStep: float,
 *   qtyMin: float,
 *   partialPackage: bool,
 *   atLimit: bool
 * }
 */
function store_cart_qty_bounds(array $item, float $cartQtyForItem, bool $showQuantity = false): array
{
    $maxPackages = \Portal\Services\StorePolicyService::maxPackagesPerMaterial();
    $policyRemaining = $maxPackages !== null
        ? max(0.0, round($maxPackages - $cartQtyForItem, 4))
        : null;
    $stockAvailable = packages_available_display($item);
    $effectiveMax = $policyRemaining;
    if ($stockAvailable !== null) {
        $stockRemaining = max(0.0, round($stockAvailable, 4));
        $effectiveMax = $effectiveMax !== null
            ? min($effectiveMax, $stockRemaining)
            : $stockRemaining;
    }

    $partialPackage = $stockAvailable !== null && $stockAvailable > 0 && $stockAvailable < 1;
    $defaultQty = 1.0;
    $qtyStep = 1.0;
    $qtyMin = 1.0;
    if ($partialPackage) {
        $defaultQty = $effectiveMax !== null && $effectiveMax > 0
            ? min($stockAvailable, $effectiveMax)
            : $stockAvailable;
        $qtyStep = 0.01;
        $qtyMin = 0.01;
    } elseif ($effectiveMax !== null && $effectiveMax > 0 && $effectiveMax < 1) {
        $defaultQty = $effectiveMax;
        $qtyStep = 0.01;
        $qtyMin = 0.01;
    }

    $atLimit = $effectiveMax !== null && $effectiveMax <= 0;

    return [
        'policyRemaining' => $policyRemaining,
        'stockAvailable' => $stockAvailable,
        'effectiveMax' => $effectiveMax,
        'defaultQty' => max(0.0, round($defaultQty, 4)),
        'qtyStep' => $qtyStep,
        'qtyMin' => $qtyMin,
        'partialPackage' => $partialPackage,
        'atLimit' => $atLimit,
    ];
}

/** @param array<string, mixed> $item */
function packages_available_display(array $item): float
{
    return \Portal\Services\StockReservationService::displayPackagesAvailable($item);
}

function default_about_content(): string
{
    return <<<'TEXT'
جاويش لتجارة الأحذية هي شركة متخصصة في تجارة كافة أنواع الأحذية المحلية والمستوردة. نعمل على تلبية احتياجات السوق من خلال توفير تشكيلة متنوعة تشمل الأحذية الرسمية، الكاجوال، والرياضية، التي تجمع بين التصاميم العملية والجودة المناسبة للاستخدام اليومي.

## أعمالنا وبماذا نلتزم

*تنوع المنتجات*
نوفر خيارات متعددة من الأحذية المصنوعة محلياً لدعم الإنتاج الوطني، بالإضافة إلى خطوط الأحذية المستوردة لتلبية كافة الأذواق والمتطلبات.

*الجودة والمواد*
نحرص على اختيار بضائعنا بعناية، مع التركيز على جودة الخامات المستخدمة مثل **الجلود الطبيعية** و**النوبوك** لضمان راحة العميل ومتانة المنتج.

*الالتزام والموثوقية*
نعتمد على تنظيم داخلي دقيق وأنظمة رقمية لبرمجة الطلبيات وإدارة المستودعات، مما يضمن لعملائنا وتجار الجملة دقة في المواعيد وسلاسة في التعامل والتسليم.

## هدفنا

> أن نكون **المورد الموثوق** والشريك الدائم لعملائنا في قطاع الأحذية، من خلال تقديم منتج جيد بسعر عادل، وتعامل قائم على الشفافية والوضوح.
TEXT;
}

function format_about_inline(string $text, bool $onDark = false): string
{
    $text = h($text);
    if ($onDark) {
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!\*)\*([^*]+?)\*(?!\*)/', '<em>$1</em>', $text);

        return $text;
    }

    $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong class="font-extrabold text-slate-900">$1</strong>', $text);
    $text = (string) preg_replace('/(?<!\*)\*([^*]+?)\*(?!\*)/', '<em class="text-slate-600">$1</em>', $text);

    return $text;
}

/**
 * @return array{
 *   intro_paragraphs: list<string>,
 *   sections: list<array{
 *     title: string,
 *     subtitle: string,
 *     cards: list<array{title: string, body: string}>,
 *     paragraphs: list<string>,
 *     quote: string,
 *     list_items: list<string>
 *   }>
 * }
 */
function parse_about_content(string $text): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return ['intro_paragraphs' => [], 'sections' => []];
    }

    $chunks = preg_split('/\n(?=##\s+)/', $text) ?: [];
    $introChunk = trim((string) array_shift($chunks));
    $introParagraphs = $introChunk === ''
        ? []
        : array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $introChunk) ?: [])));

    $sections = [];
    foreach ($chunks as $chunk) {
        $chunk = preg_replace('/^##\s+/', '', trim($chunk));
        if ($chunk === '') {
            continue;
        }

        $lines = explode("\n", $chunk);
        $title = trim((string) array_shift($lines));
        $section = [
            'title' => $title,
            'subtitle' => '',
            'cards' => [],
            'paragraphs' => [],
            'quote' => '',
            'list_items' => [],
        ];

        $pendingCardTitle = null;
        $paragraphBuffer = [];

        $flushParagraph = static function () use (&$paragraphBuffer, &$section): void {
            if ($paragraphBuffer === []) {
                return;
            }
            $section['paragraphs'][] = trim(implode("\n", $paragraphBuffer));
            $paragraphBuffer = [];
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                if ($pendingCardTitle !== null) {
                    $section['cards'][] = ['title' => $pendingCardTitle, 'body' => ''];
                    $pendingCardTitle = null;
                }
                $flushParagraph();
                continue;
            }

            if ($line === '---') {
                if ($pendingCardTitle !== null) {
                    $section['cards'][] = ['title' => $pendingCardTitle, 'body' => ''];
                    $pendingCardTitle = null;
                }
                $flushParagraph();
                continue;
            }

            if (preg_match('/^###\s+(.+)$/', $line, $matches)) {
                if ($pendingCardTitle !== null) {
                    $section['cards'][] = ['title' => $pendingCardTitle, 'body' => ''];
                    $pendingCardTitle = null;
                }
                $flushParagraph();
                $section['subtitle'] = trim((string) $matches[1]);
                continue;
            }

            if (preg_match('/^>\s+(.+)$/', $line, $matches)) {
                if ($pendingCardTitle !== null) {
                    $section['cards'][] = ['title' => $pendingCardTitle, 'body' => ''];
                    $pendingCardTitle = null;
                }
                $flushParagraph();
                $section['quote'] = trim((string) $matches[1]);
                continue;
            }

            if (preg_match('/^\*\s*(.+?)\s*\*$/', $line, $matches)) {
                $flushParagraph();
                $pendingCardTitle = trim((string) $matches[1]);
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
                $flushParagraph();
                $itemText = trim((string) $matches[1]);
                $colonPos = strpos($itemText, ':');
                if ($colonPos !== false && $colonPos < 80) {
                    $section['cards'][] = [
                        'title' => trim(substr($itemText, 0, $colonPos)),
                        'body' => trim(substr($itemText, $colonPos + 1)),
                    ];
                } else {
                    $section['list_items'][] = $itemText;
                }
                continue;
            }

            if ($pendingCardTitle !== null) {
                $section['cards'][] = [
                    'title' => $pendingCardTitle,
                    'body' => $line,
                ];
                $pendingCardTitle = null;
                continue;
            }

            $paragraphBuffer[] = $line;
        }

        if ($pendingCardTitle !== null) {
            $section['cards'][] = ['title' => $pendingCardTitle, 'body' => ''];
        }
        $flushParagraph();

        if ($section['title'] !== '') {
            $sections[] = $section;
        }
    }

    return [
        'intro_paragraphs' => $introParagraphs,
        'sections' => $sections,
    ];
}

/** @return list<string> */
function about_section_icons(): array
{
    return ['category', 'verified', 'schedule', 'handshake', 'inventory_2', 'support_agent'];
}
