<?php

declare(strict_types=1);

use Portal\Services\CatalogSectionResolver;

/** @var array<string, mixed> $catalog */
/** @var array<string, mixed> $displayOptions */
/** @var bool $isCustomer */

$catalog = is_array($catalog ?? null) ? $catalog : [];
$displayOptions = is_array($displayOptions ?? null) ? $displayOptions : [];
$filters = is_array($catalog['filters'] ?? null) ? $catalog['filters'] : [];
$sectionContext = is_array($catalog['section_context'] ?? null) ? $catalog['section_context'] : null;
$sectionFilterSummary = is_array($catalog['section_filter_summary'] ?? null) ? $catalog['section_filter_summary'] : [];
$policyFilterSummary = is_array($catalog['policy_filter_summary'] ?? null) ? $catalog['policy_filter_summary'] : [];
$storeOptions = is_array($catalog['store_options'] ?? null) ? $catalog['store_options'] : [];
$filterOptions = is_array($catalog['filterOptions'] ?? null) ? $catalog['filterOptions'] : ['stores' => [], 'groups' => []];
$allowClientFilters = (bool) ($catalog['allow_client_filters'] ?? false);
$isSectionBrowse = $sectionContext !== null;
$products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
$resultFilters = is_array($catalog['resultFilters'] ?? null) ? $catalog['resultFilters'] : [];

$visibleClientFilters = array_map('strval', $storeOptions['visible_client_filters'] ?? []);
$allowSorting = (bool) ($storeOptions['allow_sorting'] ?? true);
$clientSortFields = array_map('strval', $storeOptions['client_sort_fields'] ?? ['number', 'materialType', 'manufacturer']);
$isClientFilterVisible = static fn (string $code): bool => in_array($code, $visibleClientFilters, true);

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

require __DIR__ . '/partials/store-filter-chips.php';
?>
<link href="/css/store-filters.css" rel="stylesheet">

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

<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-3xl font-extrabold text-slate-900">المتجر</h1>
      <p class="text-sm text-gray-600 mt-1">تصفّح المواد، ابحث بالاسم أو الكود، وافتح تفاصيل كل مادة.</p>
    </div>
    <?php if ($isCustomer): ?>
      <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2 text-sm font-bold">
        <span class="material-symbols-outlined text-base" aria-hidden="true">verified_user</span>
        حساب عميل مفعّل
      </span>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($catalog['apiError'])): ?>
  <p class="mb-4 rounded-xl border bg-red-50 border-red-200 text-red-700 px-4 py-3 text-sm"><?= h((string) $catalog['apiError']) ?></p>
<?php endif; ?>

<?php if ($allowClientFilters || $isSectionBrowse): ?>
<form method="get" class="store-filters-panel mb-6 p-4 md:p-5 space-y-4">
  <input type="hidden" name="page" value="1">
  <?php if (!empty($filters['section'])): ?><input type="hidden" name="section" value="<?= h((string) $filters['section']) ?>"><?php endif; ?>
  <?php if (!empty($filters['offer'])): ?><input type="hidden" name="offer" value="<?= h((string) $filters['offer']) ?>"><?php endif; ?>

  <?php if (!$isSectionBrowse && $policyFilterSummary !== []): ?>
    <div class="store-policy-chips p-3">
      <p class="text-sm font-bold text-gray-700 mb-2">قيود سياسة الوصول (ثابتة)</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($policyFilterSummary as $chip): ?>
          <?php if (!is_array($chip)) continue; ?>
          <span class="store-policy-chip">
            <span><?= h((string) ($chip['label'] ?? '')) ?>:</span>
            <?= h((string) ($chip['value'] ?? '')) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($isSectionBrowse && $sectionFilterSummary !== []): ?>
    <div class="rounded-xl border border-primary/20 bg-white p-3">
      <p class="text-sm font-bold text-gray-700 mb-2">فلاتر هذا القسم (ثابتة)</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($sectionFilterSummary as $chip): ?>
          <?php if (!is_array($chip)) continue; ?>
          <span class="store-policy-chip border-red-100">
            <span class="text-primary"><?= h((string) ($chip['label'] ?? '')) ?>:</span>
            <?= h((string) ($chip['value'] ?? '')) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <?php if (!$isSectionBrowse && $isClientFilterVisible('search')): ?>
      <label class="text-sm md:col-span-2">
        <span class="text-gray-600 block mb-1 font-medium">بحث</span>
        <input name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3 focus:border-primary focus:ring-primary" placeholder="اسم المادة أو الكود">
      </label>
    <?php endif; ?>

    <?php if (!$isSectionBrowse && $isClientFilterVisible('warehouseRange')): ?>
      <label class="text-sm">
        <span class="text-gray-600 block mb-1 font-medium">أقل كمية</span>
        <input type="number" step="0.01" min="0" name="minWarehouseQuantity" value="<?= h((string) ($filters['minWarehouseQuantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
      </label>
      <label class="text-sm">
        <span class="text-gray-600 block mb-1 font-medium">أعلى كمية</span>
        <input type="number" step="0.01" min="0" name="maxWarehouseQuantity" value="<?= h((string) ($filters['maxWarehouseQuantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
      </label>
    <?php endif; ?>

    <?php if (!$isSectionBrowse && $isClientFilterVisible('availability')): ?>
      <div class="text-sm md:col-span-2">
        <span class="text-gray-600 block mb-1 font-medium">التوفر</span>
        <div class="flex flex-wrap gap-2">
          <?php foreach (['' => 'الكل', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
            <?php $isActive = $availabilityValue === (string) $value; ?>
            <label class="cursor-pointer">
              <input type="radio" class="peer sr-only" name="isAvailable" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
              <span class="store-filter-chip"><?= h($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$isSectionBrowse && $allowSorting && $isClientFilterVisible('sort') && $clientSortFields !== []): ?>
    <div class="store-filter-section">
      <p class="store-filter-title mb-2">الترتيب</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($clientSortFields as $sortField): ?>
          <?php
            $isActiveSort = $activeSortParsed['field'] === $sortField;
            $sortLabel = $sortFieldLabels[$sortField] ?? $sortField;
            $sortArrow = $isActiveSort ? ($activeSortParsed['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
            $nextSortValue = $buildNextSortValue($sortField);
          ?>
          <button
            type="submit"
            name="sort"
            value="<?= h($nextSortValue) ?>"
            class="store-filter-chip <?= $isActiveSort ? 'is-active' : '' ?>"
          ><?= h($sortLabel . $sortArrow) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-gray-500 mt-2">اضغط على الخيار للتبديل بين تصاعدي وتنازلي.</p>
    </div>
  <?php elseif ($isSectionBrowse): ?>
    <label class="text-sm block max-w-xs">
      <span class="text-gray-600 block mb-1 font-medium">الترتيب</span>
      <select name="sort" class="h-11 w-full rounded-xl border border-gray-300 px-3">
        <?php foreach ([
            'number:asc' => 'الرقم تصاعدي',
            'number:desc' => 'الرقم تنازلي',
            'name:asc' => 'الاسم',
            '-unitSalePriceSyp' => 'السعر',
        ] as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= ((string) ($filters['sort'] ?? '') === $value) ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <?php if (!$isSectionBrowse && $isClientFilterVisible('groupBy')): ?>
    <label class="text-sm block max-w-xs">
      <span class="text-gray-600 block mb-1 font-medium">التجميع</span>
      <select name="groupBy" class="h-11 w-full rounded-xl border border-gray-300 px-3">
        <option value="none" <?= $selectedGroupBy === 'none' ? 'selected' : '' ?>>بدون</option>
        <option value="ageCategory" <?= $selectedGroupBy === 'ageCategory' ? 'selected' : '' ?>>الفئة العمرية</option>
        <option value="sizeRange" <?= $selectedGroupBy === 'sizeRange' ? 'selected' : '' ?>>القياس</option>
        <option value="materialType" <?= $selectedGroupBy === 'materialType' ? 'selected' : '' ?>>النوع</option>
        <option value="manufacturer" <?= $selectedGroupBy === 'manufacturer' ? 'selected' : '' ?>>الشركة</option>
        <option value="countryOfOrigin" <?= $selectedGroupBy === 'countryOfOrigin' ? 'selected' : '' ?>>بلد المنشأ</option>
        <option value="group" <?= $selectedGroupBy === 'group' ? 'selected' : '' ?>>المجموعة</option>
      </select>
    </label>
  <?php endif; ?>

  <?php if (!$isSectionBrowse): ?>
    <?php
      $facetMap = [
          'materialTypes' => ['param' => 'materialTypes', 'label' => 'نوع المادة', 'selected' => $selectedMaterialTypes],
          'ageCategories' => ['param' => 'ageCategories', 'label' => 'الفئة العمرية', 'selected' => $selectedAgeCategories],
          'manufacturers' => ['param' => 'manufacturers', 'label' => 'الشركة', 'selected' => $selectedManufacturers],
          'sizeRanges' => ['param' => 'sizeRanges', 'label' => 'القياس', 'selected' => $selectedSizeRanges],
          'countryOfOrigins' => ['param' => 'countryOfOrigins', 'label' => 'بلد المنشأ', 'selected' => $selectedCountryOrigins],
      ];
    ?>
    <?php foreach ($facetMap as $facetKey => $facetConfig): ?>
      <?php
        $code = match ($facetKey) {
            'materialTypes' => 'materialTypes',
            'ageCategories' => 'ageCategories',
            'manufacturers' => 'manufacturers',
            'sizeRanges' => 'sizeRanges',
            'countryOfOrigins' => 'countryOfOrigins',
            default => '',
        };
        if (!$isClientFilterVisible($code)) {
            continue;
        }
        $values = is_array($resultFilters[$facetKey] ?? null) ? $resultFilters[$facetKey] : [];
        if ($values === []) {
            continue;
        }
        $chipOptions = [];
        foreach ($values as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $value = trim((string) ($facet['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $chipOptions[] = [
                'value' => $value,
                'label' => $value,
                'count' => $facet['count'] ?? null,
            ];
        }
      ?>
      <fieldset class="store-filter-section">
        <legend><?= h((string) $facetConfig['label']) ?></legend>
        <?php $renderStoreFilterChips((string) $facetConfig['param'], $chipOptions, (array) $facetConfig['selected']); ?>
      </fieldset>
    <?php endforeach; ?>

    <?php if ($isClientFilterVisible('stores')): ?>
      <?php
        $storeChipOptions = [];
        foreach ($filterOptions['stores'] ?? [] as $store) {
            if (!is_array($store)) {
                continue;
            }
            $guid = trim((string) ($store['guid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $label = trim((string) ($store['name'] ?? '')) ?: (trim((string) ($store['code'] ?? '')) ?: $guid);
            $storeChipOptions[] = ['value' => $guid, 'label' => $label];
        }
      ?>
      <?php if ($storeChipOptions !== []): ?>
        <fieldset class="store-filter-section">
          <legend>المخازن</legend>
          <?php $renderStoreFilterChips('storeGuids', $storeChipOptions, $selectedStoreGuids); ?>
        </fieldset>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($isClientFilterVisible('groups')): ?>
      <?php
        $groupChipOptions = [];
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
                $groupChipOptions[] = [
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
                $groupChipOptions[] = ['value' => $guid, 'label' => $label];
            }
        }
      ?>
      <?php if ($groupChipOptions !== []): ?>
        <fieldset class="store-filter-section">
          <legend>المجموعات</legend>
          <?php $renderStoreFilterChips('groupGuids', $groupChipOptions, $selectedGroupGuids); ?>
        </fieldset>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($isClientFilterVisible('priceSaleSyp') || $isClientFilterVisible('priceSaleUsd') || $isClientFilterVisible('pricePurchaseUsd')): ?>
      <fieldset class="store-filter-section">
        <legend>المدى السعري (اختياري)</legend>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-2">
          <?php if ($isClientFilterVisible('priceSaleSyp')): ?>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر البيع ل.س (من)</span>
              <input type="number" step="0.01" min="0" name="minUnitSalePriceSyp" value="<?= h((string) ($filters['minUnitSalePriceSyp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر البيع ل.س (إلى)</span>
              <input type="number" step="0.01" min="0" name="maxUnitSalePriceSyp" value="<?= h((string) ($filters['maxUnitSalePriceSyp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
          <?php endif; ?>
          <?php if ($isClientFilterVisible('priceSaleUsd')): ?>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر البيع $ (من)</span>
              <input type="number" step="0.01" min="0" name="minUnitSalePriceUsd" value="<?= h((string) ($filters['minUnitSalePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر البيع $ (إلى)</span>
              <input type="number" step="0.01" min="0" name="maxUnitSalePriceUsd" value="<?= h((string) ($filters['maxUnitSalePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
          <?php endif; ?>
          <?php if ($isClientFilterVisible('pricePurchaseUsd')): ?>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر الشراء $ (من)</span>
              <input type="number" step="0.01" min="0" name="minUnitPurchasePriceUsd" value="<?= h((string) ($filters['minUnitPurchasePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
            <label class="text-sm">
              <span class="text-gray-600 block mb-1">سعر الشراء $ (إلى)</span>
              <input type="number" step="0.01" min="0" name="maxUnitPurchasePriceUsd" value="<?= h((string) ($filters['maxUnitPurchasePriceUsd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-gray-300 px-3">
            </label>
          <?php endif; ?>
        </div>
      </fieldset>
    <?php endif; ?>
  <?php endif; ?>

  <div class="store-filter-actions flex flex-wrap gap-2 justify-end pt-1">
    <button type="submit" class="store-btn-primary h-11 px-6">تطبيق الفلاتر</button>
    <a href="<?= h(store_url(array_filter([
        'section' => (string) ($filters['section'] ?? ''),
        'offer' => (string) ($filters['offer'] ?? ''),
    ], static fn (string $value): bool => trim($value) !== ''))) ?>" class="store-btn-secondary h-11 inline-flex items-center px-6 text-sm">إعادة ضبط</a>
  </div>
</form>
<?php endif; ?>

<?php if ((int) ($catalog['totalCount'] ?? 0) > 0): ?>
  <p class="text-sm text-gray-600 mb-4">
    عرض <?= (int) ($catalog['rangeStart'] ?? 0) ?>–<?= (int) ($catalog['rangeEnd'] ?? 0) ?> من <?= (int) ($catalog['totalCount'] ?? 0) ?> مادة
    <?php if ((int) ($catalog['totalPages'] ?? 1) > 1): ?>
      <span class="text-gray-400">(صفحة <?= (int) ($catalog['page'] ?? 1) ?> من <?= (int) ($catalog['totalPages'] ?? 1) ?>)</span>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($products === [] && empty($catalog['apiError'])): ?>
  <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
    لا توجد نتائج مطابقة لبحثك أو الفلاتر المحددة.
  </div>
<?php else: ?>
  <?php
    $quickViewGuids = array_values(array_filter(array_map(
        static fn ($row): string => is_array($row) ? material_guid($row) : '',
        $products
    ), static fn (string $g): bool => $g !== ''));
  ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($products as $item): ?>
      <?php if (!is_array($item)) continue; ?>
      <?php require __DIR__ . '/partials/product-card.php'; ?>
    <?php endforeach; ?>
  </div>
  <script>
    window.__productQuickView = <?= json_encode([
        'guids' => $quickViewGuids,
        'offer' => (string) ($productOfferSlug ?? ''),
        'return' => (string) ($productReturnUrl ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
<?php endif; ?>

<?php
$page = (int) ($catalog['page'] ?? 1);
$totalPages = (int) ($catalog['totalPages'] ?? 1);
$buildUrl = static fn (int $targetPage): string => $buildStoreUrl($targetPage);
require __DIR__ . '/partials/catalog-pagination.php';
?>

<style>
  .line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
</style>
