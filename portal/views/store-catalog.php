<?php

declare(strict_types=1);

use Portal\Services\CatalogSectionResolver;
use Portal\Services\AccessPolicyService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StorePolicyService;
use Portal\Support\StorePricePreference;

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */
/** @var bool $isCustomer */

/** @var array{ok?: bool, message?: string}|string|null $cartNotice */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$cartNoticeMessage = '';
$cartNoticeOk = true;
if (is_array($cartNotice ?? null)) {
    $cartNoticeMessage = (string) ($cartNotice['message'] ?? '');
    $cartNoticeOk = (bool) ($cartNotice['ok'] ?? false);
} elseif (isset($cartNotice) && is_string($cartNotice)) {
    $cartNoticeMessage = $cartNotice;
}
$filters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
$sectionFilterSummary = is_array($catalog['section_filter_summary'] ?? null) ? $catalog['section_filter_summary'] : [];
$storeOptions = is_array($catalog['store_options'] ?? null) ? $catalog['store_options'] : [];
$filterOptions = is_array($catalog['filterOptions'] ?? null) ? $catalog['filterOptions'] : ['stores' => [], 'groups' => []];
$lockedClientFilters = array_map('strval', is_array($catalog['locked_client_filters'] ?? null) ? $catalog['locked_client_filters'] : []);
$allowClientFilters = (bool) ($catalog['allow_client_filters'] ?? false);
$isSectionBrowse = $sectionContext !== null;
$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];

$visibleClientFilters = AccessPolicyService::resolvedVisibleClientFilters($storeOptions);
$allowSorting = (bool) ($storeOptions['allow_sorting'] ?? true);
$clientSortFields = array_map('strval', $storeOptions['client_sort_fields'] ?? ['number', 'materialType', 'manufacturer']);
$isClientFilterVisible = static function (string $code) use ($visibleClientFilters, $lockedClientFilters): bool {
    if (in_array($code, $lockedClientFilters, true)) {
        return false;
    }

    return in_array($code, $visibleClientFilters, true);
};

$selectedMaterialTypes = is_array($filters['materialTypes'] ?? null) ? $filters['materialTypes'] : [];
$selectedManufacturers = is_array($filters['manufacturers'] ?? null) ? $filters['manufacturers'] : [];
$selectedAgeCategories = is_array($filters['ageCategories'] ?? null) ? $filters['ageCategories'] : [];
$selectedSizeRanges = is_array($filters['sizeRanges'] ?? null) ? $filters['sizeRanges'] : [];
$selectedCountryOrigins = is_array($filters['countryOfOrigins'] ?? null) ? $filters['countryOfOrigins'] : [];
$selectedStoreGuids = is_array($filters['storeGuids'] ?? null) ? $filters['storeGuids'] : [];
$selectedGroupGuids = is_array($filters['groupGuids'] ?? null) ? $filters['groupGuids'] : [];
$availabilityValue = $filters['isAvailable'] === true ? '1' : ($filters['isAvailable'] === false ? '0' : '');
$selectedGroupBy = (string) ($filters['groupBy'] ?? 'none');

$sortFieldLabels = [
    'number' => 'رقم المادة',
    'materialType' => 'نوع المادة',
    'ageCategory' => 'الفئة العمرية',
    'manufacturer' => 'الشركة',
    'sizeRange' => 'القياس',
    'countryOfOrigin' => 'بلد المنشأ',
    'unitSalePriceSyp' => 'سعر البيع ل.س',
    'unitSalePriceUsd' => 'سعر البيع $',
];
$clientSortFields = array_values(array_filter($clientSortFields, static fn (string $field): bool => isset($sortFieldLabels[$field])));
if ($clientSortFields === []) {
    $clientSortFields = ['number'];
}

$activeSort = (string) ($filters['sort'] ?? 'number:asc');
$parseSortClause = static function (string $clause): array {
    $clause = trim($clause);
    if ($clause === '') {
        return ['field' => 'number', 'dir' => 'asc'];
    }
    if (str_starts_with($clause, '-')) {
        return ['field' => substr($clause, 1), 'dir' => 'desc'];
    }
    $parts = explode(':', $clause, 2);

    return ['field' => $parts[0], 'dir' => strtolower($parts[1] ?? 'asc')];
};
$buildNextSortValue = static function (string $field) use ($activeSort, $parseSortClause): string {
    $parsed = $parseSortClause($activeSort);
    if ($parsed['field'] === $field) {
        return $parsed['dir'] === 'asc' ? $field . ':desc' : $field . ':asc';
    }

    return $field . ':asc';
};
$activeSortParsed = $parseSortClause($activeSort);

$activeFilterCount = 0;
if (trim((string) ($filters['q'] ?? '')) !== '') {
    $activeFilterCount++;
}
foreach ([$selectedMaterialTypes, $selectedManufacturers, $selectedAgeCategories, $selectedSizeRanges, $selectedCountryOrigins, $selectedStoreGuids, $selectedGroupGuids] as $group) {
    $activeFilterCount += count($group);
}
if ($isClientFilterVisible('availability') && $availabilityValue !== '') {
    $activeFilterCount++;
}

$buildFilterRemoveUrl = static function (
    array $removeScalarKeys = [],
    ?string $arrayParam = null,
    ?string $arrayValue = null
): string {
    $params = $_GET;
    foreach ($removeScalarKeys as $key) {
        unset($params[$key]);
    }
    if ($arrayParam !== null && $arrayValue !== null) {
        $raw = $params[$arrayParam] ?? [];
        if (!is_array($raw)) {
            $raw = [(string) $raw];
        }
        $remaining = array_values(array_filter(
            array_map('strval', $raw),
            static fn (string $item): bool => $item !== $arrayValue
        ));
        if ($remaining === []) {
            unset($params[$arrayParam]);
        } else {
            $params[$arrayParam] = $remaining;
        }
    }
    $params['page'] = 1;

    return store_url($params);
};

$storeLabelByGuid = [];
foreach ($filterOptions['stores'] ?? [] as $storeRow) {
    if (!is_array($storeRow)) {
        continue;
    }
    $guid = trim((string) ($storeRow['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $storeLabelByGuid[$guid] = trim((string) ($storeRow['name'] ?? ''))
        ?: (trim((string) ($storeRow['code'] ?? '')) ?: $guid);
}

$groupLabelByGuid = [];
foreach ($filterOptions['groups'] ?? [] as $groupRow) {
    if (!is_array($groupRow)) {
        continue;
    }
    $guid = trim((string) ($groupRow['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $groupLabelByGuid[$guid] = trim((string) ($groupRow['name'] ?? ''))
        ?: (trim((string) ($groupRow['code'] ?? '')) ?: $guid);
}
foreach (is_array($resultFilters['groups'] ?? null) ? $resultFilters['groups'] : [] as $groupFacet) {
    if (!is_array($groupFacet)) {
        continue;
    }
    $guid = trim((string) ($groupFacet['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $groupLabelByGuid[$guid] = trim((string) ($groupFacet['name'] ?? ''))
        ?: (trim((string) ($groupFacet['code'] ?? '')) ?: $guid);
}

$activeFilterChipGroups = [];
$pushChipGroup = static function (
    string $code,
    string $label,
    string $tone,
    array $chips
) use (&$activeFilterChipGroups): void {
    $normalizedChips = [];
    foreach ($chips as $chip) {
        if (!is_array($chip)) {
            continue;
        }
        $text = trim((string) ($chip['text'] ?? ''));
        $url = trim((string) ($chip['url'] ?? ''));
        if ($text === '' || $url === '') {
            continue;
        }
        $normalizedChips[] = ['text' => $text, 'url' => $url];
    }
    if ($normalizedChips === []) {
        return;
    }
    $activeFilterChipGroups[] = [
        'code' => $code,
        'label' => $label,
        'tone' => $tone,
        'chips' => $normalizedChips,
    ];
};

if (!$isSectionBrowse && $isClientFilterVisible('search') && trim((string) ($filters['q'] ?? '')) !== '') {
    $pushChipGroup('search', 'بحث', 'search', [[
        'text' => (string) $filters['q'],
        'url' => $buildFilterRemoveUrl(['q']),
    ]]);
} elseif ($isSectionBrowse && trim((string) ($filters['q'] ?? '')) !== '') {
    $pushChipGroup('search', 'بحث', 'search', [[
        'text' => (string) $filters['q'],
        'url' => $buildFilterRemoveUrl(['q']),
    ]]);
}

if (!$isSectionBrowse) {
    $facetChipMap = [
        'materialTypes' => ['param' => 'materialTypes', 'label' => 'نوع المادة', 'tone' => 'material', 'selected' => $selectedMaterialTypes],
        'ageCategories' => ['param' => 'ageCategories', 'label' => 'الفئة العمرية', 'tone' => 'age', 'selected' => $selectedAgeCategories],
        'manufacturers' => ['param' => 'manufacturers', 'label' => 'الشركة', 'tone' => 'manufacturer', 'selected' => $selectedManufacturers],
        'sizeRanges' => ['param' => 'sizeRanges', 'label' => 'القياس', 'tone' => 'size', 'selected' => $selectedSizeRanges],
        'countryOfOrigins' => ['param' => 'countryOfOrigins', 'label' => 'بلد المنشأ', 'tone' => 'country', 'selected' => $selectedCountryOrigins],
    ];
    foreach ($facetChipMap as $code => $config) {
        if (!$isClientFilterVisible($code)) {
            continue;
        }
        $chips = [];
        foreach ((array) $config['selected'] as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $chips[] = [
                'text' => $value,
                'url' => $buildFilterRemoveUrl([], (string) $config['param'], $value),
            ];
        }
        $pushChipGroup($code, (string) $config['label'], (string) $config['tone'], $chips);
    }

    if ($isClientFilterVisible('stores') && $selectedStoreGuids !== []) {
        $chips = [];
        foreach ($selectedStoreGuids as $guid) {
            $guid = trim((string) $guid);
            if ($guid === '') {
                continue;
            }
            $chips[] = [
                'text' => $storeLabelByGuid[$guid] ?? $guid,
                'url' => $buildFilterRemoveUrl([], 'storeGuids', $guid),
            ];
        }
        $pushChipGroup('stores', 'المخازن', 'stores', $chips);
    }

    if ($isClientFilterVisible('groups') && $selectedGroupGuids !== []) {
        $chips = [];
        foreach ($selectedGroupGuids as $guid) {
            $guid = trim((string) $guid);
            if ($guid === '') {
                continue;
            }
            $chips[] = [
                'text' => $groupLabelByGuid[$guid] ?? $guid,
                'url' => $buildFilterRemoveUrl([], 'groupGuids', $guid),
            ];
        }
        $pushChipGroup('groups', 'المجموعات', 'groups', $chips);
    }

    if ($isClientFilterVisible('availability') && $availabilityValue !== '') {
        $pushChipGroup('availability', 'التوفر', 'availability', [[
            'text' => $availabilityValue === '1' ? 'متوفر' : 'غير متوفر',
            'url' => $buildFilterRemoveUrl(['isAvailable']),
        ]]);
    }

    $minWarehouseQuantity = trim((string) ($filters['minWarehouseQuantity'] ?? ''));
    $maxWarehouseQuantity = trim((string) ($filters['maxWarehouseQuantity'] ?? ''));
    if ($isClientFilterVisible('warehouseRange') && ($minWarehouseQuantity !== '' || $maxWarehouseQuantity !== '')) {
        $rangeText = 'من ' . ($minWarehouseQuantity !== '' ? $minWarehouseQuantity : '…')
            . ' إلى ' . ($maxWarehouseQuantity !== '' ? $maxWarehouseQuantity : '…');
        $pushChipGroup('warehouseRange', 'مدى الكمية', 'warehouse', [[
            'text' => $rangeText,
            'url' => $buildFilterRemoveUrl(['minWarehouseQuantity', 'maxWarehouseQuantity']),
        ]]);
    }

    if ($isClientFilterVisible('priceSaleSyp')) {
        $min = trim((string) ($filters['minUnitSalePriceSyp'] ?? ''));
        $max = trim((string) ($filters['maxUnitSalePriceSyp'] ?? ''));
        if ($min !== '' || $max !== '') {
            $pushChipGroup('priceSaleSyp', 'سعر البيع ل.س', 'price-syp', [[
                'text' => 'من ' . ($min !== '' ? $min : '…') . ' إلى ' . ($max !== '' ? $max : '…'),
                'url' => $buildFilterRemoveUrl(['minUnitSalePriceSyp', 'maxUnitSalePriceSyp']),
            ]]);
        }
    }

    if ($isClientFilterVisible('priceSaleUsd')) {
        $min = trim((string) ($filters['minUnitSalePriceUsd'] ?? ''));
        $max = trim((string) ($filters['maxUnitSalePriceUsd'] ?? ''));
        if ($min !== '' || $max !== '') {
            $pushChipGroup('priceSaleUsd', 'سعر البيع $', 'price-usd', [[
                'text' => 'من ' . ($min !== '' ? $min : '…') . ' إلى ' . ($max !== '' ? $max : '…'),
                'url' => $buildFilterRemoveUrl(['minUnitSalePriceUsd', 'maxUnitSalePriceUsd']),
            ]]);
        }
    }

    if ($isClientFilterVisible('pricePurchaseUsd')) {
        $min = trim((string) ($filters['minUnitPurchasePriceUsd'] ?? ''));
        $max = trim((string) ($filters['maxUnitPurchasePriceUsd'] ?? ''));
        if ($min !== '' || $max !== '') {
            $pushChipGroup('pricePurchaseUsd', 'سعر الشراء $', 'price-purchase', [[
                'text' => 'من ' . ($min !== '' ? $min : '…') . ' إلى ' . ($max !== '' ? $max : '…'),
                'url' => $buildFilterRemoveUrl(['minUnitPurchasePriceUsd', 'maxUnitPurchasePriceUsd']),
            ]]);
        }
    }

    if ($isClientFilterVisible('groupBy') && $selectedGroupBy !== 'none') {
        $groupByLabels = [
            'ageCategory' => 'الفئة العمرية',
            'sizeRange' => 'القياس',
            'materialType' => 'النوع',
            'manufacturer' => 'الشركة',
            'countryOfOrigin' => 'بلد المنشأ',
            'group' => 'المجموعة',
        ];
        $pushChipGroup('groupBy', 'التجميع', 'group-by', [[
            'text' => $groupByLabels[$selectedGroupBy] ?? $selectedGroupBy,
            'url' => $buildFilterRemoveUrl(['groupBy']),
        ]]);
    }
}

$clearAllFiltersUrl = store_url(array_filter([
    'section' => (string) ($filters['section'] ?? ''),
    'offer' => (string) ($filters['offer'] ?? ''),
], static fn (string $value): bool => trim($value) !== ''));

$buildStoreUrl = static function (int $targetPage) use ($filters, $isSectionBrowse): string {
    $params = $_GET;
    $params['page'] = max(1, $targetPage);
    unset($params['section'], $params['offer']);
    if ($isSectionBrowse) {
        $params = array_merge($params, array_filter([
            'section' => (string) ($filters['section'] ?? ''),
            'offer' => (string) ($filters['offer'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== ''));
    }

    return store_url($params);
};

$productReturnUrl = null;
$productOfferSlug = null;
if ($sectionContext !== null) {
    $productReturnUrl = store_url(CatalogSectionResolver::storeLinkParams($sectionContext));
    if (!empty($sectionContext['is_offer_section'])) {
        $productOfferSlug = trim((string) ($sectionContext['slug'] ?? ''));
        if ($productOfferSlug === '') {
            $productOfferSlug = null;
        }
    }
}

require __DIR__ . '/partials/store-filter-group.php';
?>

<?php if ($sectionContext !== null): ?>
  <section class="mb-4 rounded-2xl border border-primary/20 bg-red-50 px-4 py-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <p class="text-xs text-primary font-bold"><?= !empty($sectionContext['is_offer_section']) ? 'قسم العروض' : 'قسم من الرئيسية' ?></p>
        <h2 class="text-lg font-extrabold text-slate-900"><?= h((string) ($sectionContext['title_ar'] ?? '')) ?></h2>
        <?php if (!empty($sectionContext['subtitle_ar'])): ?>
          <p class="text-sm text-gray-600 mt-0.5"><?= h((string) $sectionContext['subtitle_ar']) ?></p>
        <?php endif; ?>
      </div>
      <a href="/" class="text-sm font-bold text-primary">العودة للرئيسية</a>
    </div>
  </section>
<?php endif; ?>

<section class="store-hero">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <h1 class="store-hero__title">المتجر</h1>
      <p class="store-hero__subtitle">اكتشف منتجاتنا واطلب بسهولة — تجربة تسوق سريعة وآمنة.</p>
      <?php
        $storeMaxPackages = StorePolicyService::maxPackagesPerMaterial();
        $storeAllowCart = (bool) ($displayOptions['allow_cart'] ?? false);
        $storeShowPrice = (bool) ($displayOptions['show_price'] ?? false);
      ?>
      <?php if ($storeAllowCart && $storeMaxPackages !== null): ?>
        <p class="store-hero__meta">الحد الأقصى للطلب: <strong><?= h(SpecialOfferService::formatQuantityLabel($storeMaxPackages)) ?></strong> طرد لكل مادة.</p>
      <?php endif; ?>
    </div>
    <?php if ($isCustomer): ?>
      <span class="store-hero__badge">
        <span class="material-symbols-outlined text-base" aria-hidden="true">verified_user</span>
        حساب عميل مفعّل
      </span>
    <?php endif; ?>
  </div>
</section>

<?php if ($storeShowPrice && StorePricePreference::current() === StorePricePreference::SYP): ?>
  <p class="store-syp-disclaimer" role="note">
    <span class="material-symbols-outlined" aria-hidden="true">info</span>
    الأسعار بالليرة السورية تقريبية وقد تتغيّر حسب سعر الصرف وقت إتمام الطلب.
  </p>
<?php endif; ?>

<?php if (!empty($catalog['apiError'])): ?>
  <p class="mb-4 rounded-xl border bg-red-50 border-red-200 text-red-700 px-4 py-3 text-sm"><?= h((string) $catalog['apiError']) ?></p>
<?php endif; ?>

<?php if ($cartNoticeMessage !== ''): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $cartNoticeOk ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>"><?= h($cartNoticeMessage) ?></p>
<?php endif; ?>

<!-- store-catalog-fragment:start -->
<div class="store-layout <?= ($allowClientFilters || $isSectionBrowse) ? 'has-sidebar' : '' ?>" id="store-filters-root" data-store-catalog-root<?= !empty($filterOptions['deferred']) ? ' data-store-filter-options-deferred="1"' : '' ?>>
  <?php if ($allowClientFilters || $isSectionBrowse): ?>
    <div id="store-filters-backdrop" class="store-filters-backdrop" aria-hidden="true">
      <aside class="store-filters-sidebar">
        <form method="get" class="store-filters-sidebar-inner">
          <input type="hidden" name="page" value="1">
          <?php if (!empty($filters['section'])): ?><input type="hidden" name="section" value="<?= h((string) $filters['section']) ?>"><?php endif; ?>
          <?php if (!empty($filters['offer'])): ?><input type="hidden" name="offer" value="<?= h((string) $filters['offer']) ?>"><?php endif; ?>

          <div class="store-filters-sidebar-header">
            <h2 class="store-filters-sidebar-title">تصفية النتائج</h2>
            <button type="button" id="store-filters-close" class="store-filters-close-btn" aria-label="إغلاق الفلاتر">
              <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
            </button>
          </div>

          <?php if ($isSectionBrowse && $sectionFilterSummary !== []): ?>
            <p class="text-xs text-text-muted mb-3">تصفح ضمن قسم محدد — بعض الفلاتر مقيّدة بهذا القسم.</p>
          <?php endif; ?>

          <?php if (!$isSectionBrowse && $isClientFilterVisible('search')): ?>
            <div class="store-inline-field">
              <label for="store-search-q">بحث</label>
              <input id="store-search-q" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" placeholder="اسم المادة أو الكود">
            </div>
          <?php endif; ?>

          <?php if ($isSectionBrowse): ?>
            <div class="store-inline-field">
              <label for="store-section-sort">الترتيب</label>
              <select id="store-section-sort" name="sort">
                <?php foreach ([
                    'number:asc' => 'الرقم تصاعدي',
                    'number:desc' => 'الرقم تنازلي',
                    'name:asc' => 'الاسم',
                    '-unitSalePriceSyp' => 'السعر',
                ] as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= ((string) ($filters['sort'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <?php if (!$isSectionBrowse): ?>
            <?php
              $facetMap = [
                  'materialTypes' => ['param' => 'materialTypes', 'code' => 'materialTypes', 'label' => 'نوع المادة', 'selected' => $selectedMaterialTypes],
                  'ageCategories' => ['param' => 'ageCategories', 'code' => 'ageCategories', 'label' => 'الفئة العمرية', 'selected' => $selectedAgeCategories],
                  'manufacturers' => ['param' => 'manufacturers', 'code' => 'manufacturers', 'label' => 'الشركة', 'selected' => $selectedManufacturers],
                  'sizeRanges' => ['param' => 'sizeRanges', 'code' => 'sizeRanges', 'label' => 'القياس', 'selected' => $selectedSizeRanges],
                  'countryOfOrigins' => ['param' => 'countryOfOrigins', 'code' => 'countryOfOrigins', 'label' => 'بلد المنشأ', 'selected' => $selectedCountryOrigins],
              ];
            ?>
            <?php foreach ($facetMap as $facetKey => $facetConfig): ?>
              <?php
                if (!$isClientFilterVisible((string) $facetConfig['code'])) {
                    continue;
                }
                $values = is_array($resultFilters[$facetKey] ?? null) ? $resultFilters[$facetKey] : [];
                $groupOptions = [];
                foreach ($values as $facet) {
                    if (!is_array($facet)) {
                        continue;
                    }
                    $value = trim((string) ($facet['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $groupOptions[] = [
                        'value' => $value,
                        'label' => $value,
                        'count' => $facet['count'] ?? null,
                    ];
                }
                $renderStoreFilterGroup(
                    (string) $facetConfig['param'],
                    (string) $facetConfig['label'],
                    $groupOptions,
                    (array) $facetConfig['selected'],
                    (string) $facetConfig['code'],
                    5,
                    6
                );
              ?>
            <?php endforeach; ?>

            <?php if ($isClientFilterVisible('stores')): ?>
              <?php
                $storeGroupOptions = [];
                foreach ($filterOptions['stores'] ?? [] as $store) {
                    if (!is_array($store)) {
                        continue;
                    }
                    $guid = trim((string) ($store['guid'] ?? ''));
                    if ($guid === '') {
                        continue;
                    }
                    $label = trim((string) ($store['name'] ?? '')) ?: (trim((string) ($store['code'] ?? '')) ?: $guid);
                    $storeGroupOptions[] = ['value' => $guid, 'label' => $label];
                }
                if (!empty($filterOptions['deferred']) && $storeGroupOptions === []) {
                    ?>
                    <details class="store-filter-accordion" data-filter-group="stores">
                      <summary class="store-filter-accordion-summary"><span>المخازن</span></summary>
                      <div class="store-filter-accordion-body">
                        <div class="store-filter-options" data-filter-list="stores" data-initial-visible="6"></div>
                      </div>
                    </details>
                    <?php
                } else {
                    $renderStoreFilterGroup('storeGuids', 'المخازن', $storeGroupOptions, $selectedStoreGuids, 'stores');
                }
              ?>
            <?php endif; ?>

            <?php if ($isClientFilterVisible('groups')): ?>
              <?php
                $groupGroupOptions = [];
                $groupFacets = is_array($resultFilters['groups'] ?? null) ? $resultFilters['groups'] : [];
                if ($groupFacets !== []) {
                    foreach ($groupFacets as $groupFacet) {
                        if (!is_array($groupFacet)) {
                            continue;
                        }
                        $guid = trim((string) ($groupFacet['guid'] ?? ''));
                        if ($guid === '') {
                            continue;
                        }
                        $label = trim((string) ($groupFacet['name'] ?? '')) ?: (trim((string) ($groupFacet['code'] ?? '')) ?: $guid);
                        $groupGroupOptions[] = [
                            'value' => $guid,
                            'label' => $label,
                            'count' => $groupFacet['count'] ?? null,
                        ];
                    }
                } else {
                    foreach ($filterOptions['groups'] ?? [] as $group) {
                        if (!is_array($group)) {
                            continue;
                        }
                        $guid = trim((string) ($group['guid'] ?? ''));
                        if ($guid === '') {
                            continue;
                        }
                        $label = trim((string) ($group['name'] ?? '')) ?: (trim((string) ($group['code'] ?? '')) ?: $guid);
                        $groupGroupOptions[] = ['value' => $guid, 'label' => $label];
                    }
                }
                if (!empty($filterOptions['deferred']) && $groupGroupOptions === []) {
                    ?>
                    <details class="store-filter-accordion" data-filter-group="groups">
                      <summary class="store-filter-accordion-summary"><span>المجموعات</span></summary>
                      <div class="store-filter-accordion-body">
                        <div class="store-filter-options" data-filter-list="groups" data-initial-visible="6"></div>
                      </div>
                    </details>
                    <?php
                } else {
                    $renderStoreFilterGroup('groupGuids', 'المجموعات', $groupGroupOptions, $selectedGroupGuids, 'groups');
                }
              ?>
            <?php endif; ?>

            <?php if ($isClientFilterVisible('availability')): ?>
              <details class="store-filter-accordion" <?= $availabilityValue !== '' ? 'open' : '' ?>>
                <summary class="store-filter-accordion-summary"><span>التوفر</span></summary>
                <div class="store-filter-accordion-body store-filter-options">
                  <?php foreach (['' => 'الكل', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
                    <?php $isActive = $availabilityValue === (string) $value; ?>
                    <label class="store-filter-option">
                      <input type="radio" name="isAvailable" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                      <span class="store-filter-option-text"><?= h($label) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>

            <?php if ($isClientFilterVisible('warehouseRange')): ?>
              <details class="store-filter-accordion">
                <summary class="store-filter-accordion-summary"><span>مدى الكمية</span></summary>
                <div class="store-filter-accordion-body grid grid-cols-2 gap-2">
                  <div class="store-inline-field mb-0">
                    <label>من</label>
                    <input type="number" step="0.01" min="0" name="minWarehouseQuantity" value="<?= h((string) ($filters['minWarehouseQuantity'] ?? '')) ?>">
                  </div>
                  <div class="store-inline-field mb-0">
                    <label>إلى</label>
                    <input type="number" step="0.01" min="0" name="maxWarehouseQuantity" value="<?= h((string) ($filters['maxWarehouseQuantity'] ?? '')) ?>">
                  </div>
                </div>
              </details>
            <?php endif; ?>

            <?php if ($isClientFilterVisible('priceSaleSyp') || $isClientFilterVisible('priceSaleUsd') || $isClientFilterVisible('pricePurchaseUsd')): ?>
              <details class="store-filter-accordion">
                <summary class="store-filter-accordion-summary"><span>المدى السعري</span></summary>
                <div class="store-filter-accordion-body space-y-2">
                  <?php if ($isClientFilterVisible('priceSaleSyp')): ?>
                    <div class="grid grid-cols-2 gap-2">
                      <div class="store-inline-field mb-0"><label>بيع ل.س من</label><input type="number" step="0.01" min="0" name="minUnitSalePriceSyp" value="<?= h((string) ($filters['minUnitSalePriceSyp'] ?? '')) ?>"></div>
                      <div class="store-inline-field mb-0"><label>إلى</label><input type="number" step="0.01" min="0" name="maxUnitSalePriceSyp" value="<?= h((string) ($filters['maxUnitSalePriceSyp'] ?? '')) ?>"></div>
                    </div>
                  <?php endif; ?>
                  <?php if ($isClientFilterVisible('priceSaleUsd')): ?>
                    <div class="grid grid-cols-2 gap-2">
                      <div class="store-inline-field mb-0"><label>بيع $ من</label><input type="number" step="0.01" min="0" name="minUnitSalePriceUsd" value="<?= h((string) ($filters['minUnitSalePriceUsd'] ?? '')) ?>"></div>
                      <div class="store-inline-field mb-0"><label>إلى</label><input type="number" step="0.01" min="0" name="maxUnitSalePriceUsd" value="<?= h((string) ($filters['maxUnitSalePriceUsd'] ?? '')) ?>"></div>
                    </div>
                  <?php endif; ?>
                  <?php if ($isClientFilterVisible('pricePurchaseUsd')): ?>
                    <div class="grid grid-cols-2 gap-2">
                      <div class="store-inline-field mb-0"><label>شراء $ من</label><input type="number" step="0.01" min="0" name="minUnitPurchasePriceUsd" value="<?= h((string) ($filters['minUnitPurchasePriceUsd'] ?? '')) ?>"></div>
                      <div class="store-inline-field mb-0"><label>إلى</label><input type="number" step="0.01" min="0" name="maxUnitPurchasePriceUsd" value="<?= h((string) ($filters['maxUnitPurchasePriceUsd'] ?? '')) ?>"></div>
                    </div>
                  <?php endif; ?>
                </div>
              </details>
            <?php endif; ?>

            <?php if ($isClientFilterVisible('groupBy')): ?>
              <div class="store-inline-field">
                <label for="store-group-by">التجميع</label>
                <select id="store-group-by" name="groupBy">
                  <option value="none" <?= $selectedGroupBy === 'none' ? 'selected' : '' ?>>بدون</option>
                  <option value="ageCategory" <?= $selectedGroupBy === 'ageCategory' ? 'selected' : '' ?>>الفئة العمرية</option>
                  <option value="sizeRange" <?= $selectedGroupBy === 'sizeRange' ? 'selected' : '' ?>>القياس</option>
                  <option value="materialType" <?= $selectedGroupBy === 'materialType' ? 'selected' : '' ?>>النوع</option>
                  <option value="manufacturer" <?= $selectedGroupBy === 'manufacturer' ? 'selected' : '' ?>>الشركة</option>
                  <option value="countryOfOrigin" <?= $selectedGroupBy === 'countryOfOrigin' ? 'selected' : '' ?>>بلد المنشأ</option>
                  <option value="group" <?= $selectedGroupBy === 'group' ? 'selected' : '' ?>>المجموعة</option>
                </select>
              </div>
            <?php endif; ?>
          <?php endif; ?>

          <div class="store-filter-actions">
            <button type="submit" class="store-btn-primary">تطبيق</button>
            <a href="<?= h(store_url(array_filter([
                'section' => (string) ($filters['section'] ?? ''),
                'offer' => (string) ($filters['offer'] ?? ''),
            ], static fn (string $value): bool => trim($value) !== ''))) ?>" class="store-btn-secondary inline-flex items-center">مسح</a>
          </div>
        </form>
      </aside>
    </div>
  <?php endif; ?>

  <div class="store-results">
    <?php require __DIR__ . '/partials/store-active-filter-chips.php'; ?>

    <div class="store-results-toolbar">
      <?php if ($allowClientFilters || $isSectionBrowse): ?>
        <button type="button" id="store-filters-open" class="store-filters-open-btn lg:hidden">
          <span class="material-symbols-outlined text-base" aria-hidden="true">tune</span>
          فلاتر
          <?php if ($activeFilterCount > 0): ?>
            <span class="badge"><?= (int) $activeFilterCount ?></span>
          <?php endif; ?>
        </button>
      <?php endif; ?>

      <?php if ((int) ($catalog['totalCount'] ?? 0) > 0 && $products !== []): ?>
        <p class="store-results-meta">
          عرض <?= (int) ($catalog['rangeStart'] ?? 0) ?>–<?= (int) ($catalog['rangeEnd'] ?? 0) ?> من <?= (int) ($catalog['totalCount'] ?? 0) ?> مادة
          <?php if ((int) ($catalog['totalPages'] ?? 1) > 1): ?>
            <span class="text-gray-400">(صفحة <?= (int) ($catalog['page'] ?? 1) ?> من <?= (int) ($catalog['totalPages'] ?? 1) ?>)</span>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <p class="store-results-meta">لا توجد نتائج مطابقة</p>
      <?php endif; ?>

      <?php if (!$isSectionBrowse && $allowSorting && $isClientFilterVisible('sort') && $clientSortFields !== []): ?>
        <form method="get" class="store-sort-bar">
          <?php foreach ($_GET as $key => $value): ?>
            <?php if ($key === 'sort' || $key === 'page') continue; ?>
            <?php if (is_array($value)): ?>
              <?php foreach ($value as $item): ?>
                <input type="hidden" name="<?= h((string) $key) ?>[]" value="<?= h((string) $item) ?>">
              <?php endforeach; ?>
            <?php else: ?>
              <input type="hidden" name="<?= h((string) $key) ?>" value="<?= h((string) $value) ?>">
            <?php endif; ?>
          <?php endforeach; ?>
          <input type="hidden" name="page" value="1">
          <?php foreach ($clientSortFields as $sortField): ?>
            <?php
              $isActiveSort = $activeSortParsed['field'] === $sortField;
              $sortLabel = $sortFieldLabels[$sortField] ?? $sortField;
              $sortArrow = $isActiveSort ? ($activeSortParsed['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
            ?>
            <button
              type="submit"
              name="sort"
              value="<?= h($buildNextSortValue($sortField)) ?>"
              class="store-sort-chip <?= $isActiveSort ? 'is-active' : '' ?>"
            ><?= h($sortLabel . $sortArrow) ?></button>
          <?php endforeach; ?>
        </form>
      <?php endif; ?>
    </div>

    <?php if ($products === [] && empty($catalog['apiError'])): ?>
      <div class="store-empty-state">
        لا توجد نتائج مطابقة لبحثك أو الفلاتر المحددة.
      </div>
    <?php else: ?>
      <div class="store-product-grid">
        <?php foreach ($products as $item): ?>
          <?php if (!is_array($item)) continue; ?>
          <?php
            $useImagePreview = true;
            $useQuickView = false;
            require __DIR__ . '/partials/product-card.php';
          ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
    $page = (int) ($catalog['page'] ?? 1);
    $totalPages = (int) ($catalog['totalPages'] ?? 1);
    $buildUrl = static fn (int $targetPage): string => $buildStoreUrl($targetPage);
    require __DIR__ . '/partials/catalog-pagination.php';

    $buildPreviewPageUrl = static function (int $targetPage, string $previewEdge) use ($buildStoreUrl): string {
        $url = $buildStoreUrl($targetPage);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'preview=' . rawurlencode($previewEdge);
    };
    ?>
    <script type="application/json" data-store-preview-paging><?= json_encode([
          'page' => $page,
          'totalPages' => $totalPages,
          'prevPageUrl' => $page > 1 ? $buildPreviewPageUrl($page - 1, 'last') : null,
          'nextPageUrl' => $page < $totalPages ? $buildPreviewPageUrl($page + 1, 'first') : null,
      ], JSON_UNESCAPED_UNICODE) ?></script>
  </div>
</div>
<!-- store-catalog-fragment:end -->

<?php if (empty($GLOBALS['storeCatalogPreviewRendered'])): ?>
  <?php $GLOBALS['storeCatalogPreviewRendered'] = true; ?>
  <?php require __DIR__ . '/partials/store-product-preview.php'; ?>
<?php endif; ?>

<script src="<?= h(portal_asset_url('/assets/store-filters.js')) ?>" defer></script>
<script src="<?= h(portal_asset_url('/assets/store-catalog-nav.js')) ?>" defer></script>
<script src="<?= h(portal_asset_url('/assets/store-product-preview.js')) ?>" defer></script>

<style>
  .line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
