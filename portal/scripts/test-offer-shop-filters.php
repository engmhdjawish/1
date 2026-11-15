<?php

declare(strict_types=1);

/**
 * Offer shop: facet filters must apply inside a manual offer and keep ?offer= in links.
 * Usage: php scripts/test-offer-shop-filters.php
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/store.php?offer=test-offer&ageCategories[]=%D8%A8%D9%86%D8%A7%D8%AA%D9%8A';
$_GET = ['offer' => 'test-offer', 'ageCategories' => ['بناتي']];

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

use Portal\Services\SpecialOfferService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";

        return;
    }
    echo "OK: {$label}\n";
};

$boy = [
    'materialGuid' => 'g-boy',
    'name' => 'قميص ولادي',
    'materialCode' => 'B1',
    'materialType' => 'صيفي',
    'ageCategory' => 'ولادي',
    'manufacturer' => 'جاويش',
    'sizeRange' => '8-10',
    'warehouseQuantity' => 3,
];
$girl = [
    'materialGuid' => 'g-girl',
    'name' => 'قميص بناتي',
    'materialCode' => 'G1',
    'materialType' => 'صيفي',
    'ageCategory' => 'بناتي',
    'manufacturer' => 'جاويش',
    'sizeRange' => '8-10',
    'warehouseQuantity' => 2,
    'has_offer' => true,
    'offer_slug' => 'test-offer',
    'offer_badge' => '-10%',
    'effective_unit_sale_price_sp' => 900,
    'original_unit_sale_price_sp' => 1000,
    'unitSalePriceSyp' => 900,
];

$assert(
    'age filter matches بناتي only',
    SpecialOfferService::materialMatchesRules($girl, ['age_categories' => ['بناتي']])
    && !SpecialOfferService::materialMatchesRules($boy, ['age_categories' => ['بناتي']])
);
$assert(
    'material type filter still works',
    SpecialOfferService::materialMatchesRules($girl, ['material_types' => ['صيفي']])
    && !SpecialOfferService::materialMatchesRules($girl, ['material_types' => ['شتوي']])
);

$sectionContext = [
    'id' => 'offer-1',
    'slug' => 'test-offer',
    'title_ar' => 'عرض تجريبي',
    'selection_mode' => 'manual',
    'is_offer_section' => true,
    'filter_rules' => [],
];

$catalog = [
    'products' => [$girl],
    'totalCount' => 1,
    'page' => 1,
    'pageSize' => 24,
    'totalPages' => 1,
    'rangeStart' => 1,
    'rangeEnd' => 1,
    'resultFilters' => [
        'ageCategories' => [
            ['value' => 'بناتي', 'count' => 1],
            ['value' => 'ولادي', 'count' => 1],
        ],
        'materialTypes' => [['value' => 'صيفي', 'count' => 2]],
    ],
    'filterOptions' => [
        'stores' => [],
        'groups' => [],
        'ageCategories' => ['بناتي', 'ولادي'],
        'materialTypes' => ['صيفي'],
    ],
    'apiError' => null,
    'allow_client_filters' => true,
    'filters' => [
        'q' => '',
        'sort' => 'number:asc',
        'offer' => 'test-offer',
        'materialTypes' => [],
        'manufacturers' => [],
        'ageCategories' => ['بناتي'],
        'sizeRanges' => [],
        'countryOfOrigins' => [],
        'storeGuids' => [],
        'groupGuids' => [],
        'isAvailable' => null,
        'hasImage' => null,
    ],
    'store_options' => [
        'allow_sorting' => true,
        'client_sort_fields' => ['number'],
        'visible_client_filters' => [
            'search', 'materialTypes', 'manufacturers', 'ageCategories', 'sizeRanges', 'availability', 'sort',
        ],
    ],
    'section_context' => $sectionContext,
    'locked_client_filters' => [],
    'filters_deferred' => false,
    'client_filters_payload' => null,
];

$displayOptions = [
    'show_price' => false,
    'show_quantity' => false,
    'allow_cart' => false,
    'allow_order' => false,
    'show_images' => true,
    'price_mode' => 'none',
];
$isCustomer = false;
$storeCatalogImagePreview = false;
unset($GLOBALS['storeCatalogPreviewRendered']);

ob_start();
try {
    require dirname(__DIR__) . '/views/store-easy.php';
} catch (Throwable) {
    // App dock / settings need Postgres; assertions use the HTML rendered above it.
}
$html = (string) ob_get_clean();

$assert('offer banner remains while filtered', str_contains($html, 'عرض خاص') && str_contains($html, 'عرض تجريبي'));
$assert('بناتي tile is active', (bool) preg_match('/class="se-tile is-active"[^>]*>[\s\S]*?بناتي/u', $html));
$assert('tile links keep offer slug', (bool) preg_match('/href="\/store\.php\?[^"]*offer=test-offer[^"]*ageCategories/u', $html)
    || (bool) preg_match('/href="\/store\.php\?[^"]*ageCategories[^"]*offer=test-offer/u', $html));
$assert('all tile keeps offer without age filter', (bool) preg_match('/href="\/store\.php\?offer=test-offer"/u', $html));
$assert('hidden form keeps offer', str_contains($html, 'name="offer" value="test-offer"'));
$assert('hidden form keeps selected age', str_contains($html, 'name="ageCategories[]" value="بناتي"'));
$assert('clear-all keeps offer scope', (bool) preg_match('/href="\/store\.php\?offer=test-offer"/u', $html));

$linkParams = \Portal\Services\CatalogSectionResolver::storeLinkParams($sectionContext);
$assert('store link params are offer-only', $linkParams === ['offer' => 'test-offer']);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}
echo "All assertions passed.\n";
