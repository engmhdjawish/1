<?php

declare(strict_types=1);

/** @var array<string, mixed> $editLink */
/** @var string $editId */
/** @var bool $isNew */
/** @var list<array{id: string, code: string, name_ar: string}> $policies */
/** @var array{
 *  materialTypes: list<string>,
 *  ageCategories: list<string>,
 *  manufacturers: list<string>,
 *  sizeRanges: list<string>,
 *  countryOfOrigins: list<string>,
 *  stores: list<array<string, mixed>>,
 *  groups: list<array<string, mixed>>,
 *  priceRanges: array<string, mixed>
 * } $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var callable(string): string $shareUrlFor */

require __DIR__ . '/../../partials/store-filter-group.php';

$linkOptions = (array) (($editLink['options'] ?? null) ?: []);
$showImages = array_key_exists('show_images', $linkOptions) ? (bool) $linkOptions['show_images'] : true;
$priceMode = (string) ($linkOptions['price_mode'] ?? 'both');
$allowSorting = array_key_exists('allow_sorting', $linkOptions) ? (bool) $linkOptions['allow_sorting'] : false;
$defaultGroupBy = (string) ($linkOptions['default_group_by'] ?? 'none');
$visibleClientFilters = array_map('strval', is_array($linkOptions['visible_client_filters'] ?? null) ? $linkOptions['visible_client_filters'] : []);
$clientSortFields = array_values(array_unique(array_map('strval', is_array($linkOptions['client_sort_fields'] ?? null) ? $linkOptions['client_sort_fields'] : [])));

$selectedMaterialTypes = array_map('strval', $editLink['forced_material_types'] ?? []);
$selectedAgeCategories = array_map('strval', $editLink['forced_age_categories'] ?? []);
$selectedManufacturers = array_map('strval', $editLink['forced_manufacturers'] ?? []);
$selectedSizeRanges = array_map('strval', $editLink['forced_size_ranges'] ?? []);
$selectedCountryOrigins = array_map('strval', $editLink['forced_country_origins'] ?? []);
$selectedStoreGuids = array_map('strval', $editLink['forced_store_guids'] ?? []);
$selectedGroupGuids = array_map('strval', $editLink['forced_group_guids'] ?? []);
$constraints = is_array($editLink['constraints'] ?? null) ? $editLink['constraints'] : [];
$forcedIsAvailable = array_key_exists('is_available', $constraints) ? $constraints['is_available'] : null;
$forcedHasImage = array_key_exists('has_image', $constraints) ? $constraints['has_image'] : null;
$constraintsMinWarehouse = (string) ($constraints['min_warehouse_quantity'] ?? '');
if ($constraintsMinWarehouse === '' && isset($editLink['min_quantity']) && (float) $editLink['min_quantity'] > 0) {
    $constraintsMinWarehouse = (string) $editLink['min_quantity'];
}

$materialTypeOptions = array_values(array_unique(array_merge($materialFilterOptions['materialTypes'] ?? [], $selectedMaterialTypes)));
$ageCategoryOptions = array_values(array_unique(array_merge($materialFilterOptions['ageCategories'] ?? [], $selectedAgeCategories)));
$manufacturerOptions = array_values(array_unique(array_merge($materialFilterOptions['manufacturers'] ?? [], $selectedManufacturers)));
$sizeRangeOptions = array_values(array_unique(array_merge($materialFilterOptions['sizeRanges'] ?? [], $selectedSizeRanges)));
$countryOriginOptions = array_values(array_unique(array_merge($materialFilterOptions['countryOfOrigins'] ?? [], $selectedCountryOrigins)));

$storeOptions = array_values(array_filter($materialFilterOptions['stores'] ?? [], static function ($row): bool {
    return is_array($row) && trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));
$groupOptions = array_values(array_filter($materialFilterOptions['groups'] ?? [], static function ($row): bool {
    return is_array($row) && trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));

foreach ($selectedStoreGuids as $guid) {
    $exists = false;
    foreach ($storeOptions as $store) {
        if ((string) ($store['guid'] ?? $store['Guid'] ?? '') === $guid) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $storeOptions[] = ['guid' => $guid, 'name' => $guid];
    }
}

foreach ($selectedGroupGuids as $guid) {
    $exists = false;
    foreach ($groupOptions as $group) {
        if ((string) ($group['guid'] ?? $group['Guid'] ?? '') === $guid) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $groupOptions[] = ['guid' => $guid, 'name' => $guid];
    }
}

$toGroupOptions = static function (array $values): array {
    $result = [];
    foreach ($values as $value) {
        $item = trim((string) $value);
        if ($item !== '') {
            $result[] = ['value' => $item, 'label' => $item];
        }
    }

    return array_values(array_unique($result, SORT_REGULAR));
};

$storeGroupOptions = [];
foreach ($storeOptions as $store) {
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $storeGroupOptions[] = [
        'value' => $guid,
        'label' => trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid,
    ];
}

$groupGroupOptions = [];
foreach ($groupOptions as $group) {
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $groupGroupOptions[] = [
        'value' => $guid,
        'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid,
    ];
}

$sortFieldOptions = [
    ['value' => 'number', 'label' => 'رقم المادة'],
    ['value' => 'materialType', 'label' => 'نوع المادة'],
    ['value' => 'ageCategory', 'label' => 'الفئة العمرية'],
    ['value' => 'manufacturer', 'label' => 'الشركة'],
    ['value' => 'sizeRange', 'label' => 'القياس'],
    ['value' => 'countryOfOrigin', 'label' => 'بلد المنشأ'],
    ['value' => 'unitSalePriceSyp', 'label' => 'سعر البيع ل.س'],
    ['value' => 'unitSalePriceUsd', 'label' => 'سعر البيع $'],
];

$visibleFilterOptions = [
    ['value' => 'search', 'label' => 'بحث نصي'],
    ['value' => 'materialTypes', 'label' => 'نوع المادة'],
    ['value' => 'ageCategories', 'label' => 'الفئة العمرية'],
    ['value' => 'manufacturers', 'label' => 'الشركة'],
    ['value' => 'sizeRanges', 'label' => 'القياس'],
    ['value' => 'countryOfOrigins', 'label' => 'بلد المنشأ'],
    ['value' => 'stores', 'label' => 'المخازن'],
    ['value' => 'groups', 'label' => 'المجموعات'],
    ['value' => 'availability', 'label' => 'التوفر'],
    ['value' => 'warehouseRange', 'label' => 'مدى الكمية'],
    ['value' => 'priceSaleSyp', 'label' => 'مدى سعر البيع ل.س'],
    ['value' => 'priceSaleUsd', 'label' => 'مدى سعر البيع $'],
    ['value' => 'pricePurchaseUsd', 'label' => 'مدى سعر الشراء $'],
    ['value' => 'groupBy', 'label' => 'التجميع'],
];

$chipFiltersExpanded = true;
$availabilityValue = $forcedIsAvailable === null ? '' : ($forcedIsAvailable ? '1' : '0');
$hasImageValue = $forcedHasImage === null ? '' : ($forcedHasImage ? '1' : '0');
?>
<div data-share-link-form-panel>
  <?php if ($editId !== ''): ?>
    <form method="post" id="sl-delete-form" class="hidden" data-dashboard-ajax data-dashboard-reload onsubmit="return confirm('هل أنت متأكد من حذف هذا الرابط؟');">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= h($editId) ?>">
    </form>
  <?php endif; ?>

  <form
    method="post"
    id="share-link-form"
    data-dashboard-explicit-save
    data-dashboard-ajax
    data-dashboard-reload
    data-store-filters-form
    class="dash-sl-form mb-4"
  >
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= h((string) ($editLink['id'] ?? '')) ?>">

    <div class="dashboard-sticky-toolbar sticky z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2 mb-3">
      <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل رابط المشاركة' : 'رابط مشاركة جديد' ?></h2>
      <div class="flex flex-wrap items-center gap-2">
        <a href="/dashboard/share-links.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"><?= $editId !== '' ? 'إلغاء التعديل' : 'إلغاء' ?></a>
        <?php if ($editId !== ''): ?>
          <button type="submit" form="sl-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
        <?php endif; ?>
        <button type="submit" id="share-link-save-btn" data-dashboard-save-btn class="dash-mi-zip-download-btn h-9 px-5 text-xs min-h-0">
          <span class="material-symbols-outlined" aria-hidden="true">save</span>
          <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء الرابط' ?>
        </button>
      </div>
    </div>

    <div class="dash-mi-download-accordions">
      <details class="dash-mi-download-accordion" data-share-link-accordion="basics"<?= $isNew ? ' open' : '' ?>>
        <summary class="dash-mi-download-accordion__summary">
          <span class="dash-mi-download-accordion__heading">
            <span class="material-symbols-outlined dash-mi-download-accordion__icon" aria-hidden="true">link</span>
            <span class="dash-mi-download-accordion__text">
              <span class="dash-mi-download-accordion__title">معلومات الرابط</span>
              <span class="dash-mi-download-accordion__subtitle">الاسم، السياسة، الكلمة المفتاحية، وتاريخ الانتهاء</span>
            </span>
          </span>
        </summary>
        <div class="dash-mi-download-accordion__body dash-mi-zip-form__body">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <label class="store-inline-field md:col-span-3 mb-0">
              <span>اسم الرابط *</span>
              <input name="name_ar" required value="<?= h((string) ($editLink['name_ar'] ?? '')) ?>" placeholder="مثال: عروض الصيف - العملاء المميزون">
            </label>
            <label class="store-inline-field mb-0">
              <span>سياسة الوصول *</span>
              <select name="access_policy_id" required>
                <option value="">اختر السياسة</option>
                <?php foreach ($policies as $policy): ?>
                  <option value="<?= h($policy['id']) ?>" <?= (string) ($editLink['access_policy_id'] ?? '') === (string) $policy['id'] ? 'selected' : '' ?>>
                    <?= h($policy['name_ar']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="store-inline-field mb-0">
              <span>كلمة مفتاحية</span>
              <input name="keyword" value="<?= h((string) ($editLink['keyword'] ?? '')) ?>" placeholder="new-arrivals">
            </label>
            <label class="store-inline-field mb-0">
              <span>ينتهي بتاريخ</span>
              <input name="expires_at" type="datetime-local" value="<?= h(isset($editLink['expires_at']) && $editLink['expires_at'] ? str_replace(' ', 'T', substr((string) $editLink['expires_at'], 0, 16)) : '') ?>">
            </label>
            <div class="flex items-end pb-1">
              <label class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700">
                <input type="checkbox" name="is_active" <?= $isNew || !empty($editLink['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
                <span>نشط</span>
              </label>
            </div>
          </div>
          <?php if ($editId !== '' && !empty($editLink['public_token'])): ?>
            <div class="mt-3 pt-3 border-t border-border-subtle flex flex-wrap items-center gap-2 text-xs">
              <span class="text-text-muted">رابط المشاركة:</span>
              <code class="font-mono text-slate-700 bg-surface-low px-2 py-1 rounded"><?= h($shareUrlFor((string) $editLink['public_token'])) ?></code>
              <button type="button" data-copy-url="<?= h($shareUrlFor((string) $editLink['public_token'])) ?>" class="h-7 px-2 rounded border border-border-subtle bg-white hover:bg-slate-50 font-bold text-slate-700">نسخ</button>
              <a href="/share.php?token=<?= urlencode((string) $editLink['public_token']) ?>" target="_blank" class="text-primary font-bold hover:underline">فتح</a>
            </div>
          <?php endif; ?>
        </div>
      </details>

      <details class="dash-mi-download-accordion" data-share-link-accordion="forced-filters">
        <summary class="dash-mi-download-accordion__summary">
          <span class="dash-mi-download-accordion__heading">
            <span class="material-symbols-outlined dash-mi-download-accordion__icon" aria-hidden="true">tune</span>
            <span class="dash-mi-download-accordion__text">
              <span class="dash-mi-download-accordion__title">فلاتر المواد المفروضة</span>
              <span class="dash-mi-download-accordion__subtitle">OR داخل الحقل — AND بين الحقول — اختياري</span>
            </span>
          </span>
        </summary>
        <div class="dash-mi-download-accordion__body">
          <div
            class="dash-mi-zip-filters-shell"
            data-store-filters-root
            data-store-filters-static="1"
          >
            <div class="dash-mi-zip-form__body">
              <?php if (!empty($materialFilterOptionsError)): ?>
                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $materialFilterOptionsError) ?></p>
              <?php endif; ?>

              <div class="store-filter-pending-panel" id="store-filter-pending-panel" aria-live="polite">
                <div class="store-filter-pending-panel-head">
                  <span class="store-filter-pending-panel-title">اختياراتك</span>
                  <button type="button" class="store-filter-pending-clear-all" id="store-filter-pending-clear-all" hidden>مسح</button>
                </div>
                <div class="store-filter-pending-chips" id="store-filter-pending-chips-global"></div>
              </div>

              <div class="dash-mi-zip-filters-stack store-filter-chip-layout">
                <?php
                  $renderStoreFilterGroup('forced_material_types', 'نوع المادة', $toGroupOptions($materialTypeOptions), $selectedMaterialTypes, 'materialTypes', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_age_categories', 'الفئة العمرية', $toGroupOptions($ageCategoryOptions), $selectedAgeCategories, 'ageCategories', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_manufacturers', 'الشركة المصنعة', $toGroupOptions($manufacturerOptions), $selectedManufacturers, 'manufacturers', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_size_ranges', 'القياس', $toGroupOptions($sizeRangeOptions), $selectedSizeRanges, 'sizeRanges', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_country_origins', 'بلد المنشأ', $toGroupOptions($countryOriginOptions), $selectedCountryOrigins, 'countryOfOrigins', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_store_guids', 'المخازن', $storeGroupOptions, $selectedStoreGuids, 'stores', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('forced_group_guids', 'المجموعات', $groupGroupOptions, $selectedGroupGuids, 'groups', 5, 8, true, $chipFiltersExpanded);
                ?>

                <section class="store-filter-chip-section" data-filter-group="availability">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">inventory_2</span>
                      <span class="store-filter-chip-section-label">التوفر</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                      <?php foreach (['' => 'بدون قيد', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
                        <?php $isActive = $availabilityValue === (string) $value; ?>
                        <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive ? ' is-selected' : '' ?><?= $isActive && $value === '' ? ' is-neutral' : '' ?>">
                          <input type="radio" name="forced_is_available" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                          <span class="store-filter-option-text"><?= h($label) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>

                <section class="store-filter-chip-section" data-filter-group="hasImage">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">image</span>
                      <span class="store-filter-chip-section-label">الصورة</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                      <?php foreach (['' => 'بدون قيد', '1' => 'مع صورة', '0' => 'بدون صورة'] as $value => $label): ?>
                        <?php $isActive = $hasImageValue === (string) $value; ?>
                        <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive ? ' is-selected' : '' ?><?= $isActive && $value === '' ? ' is-neutral' : '' ?>">
                          <input type="radio" name="forced_has_image" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                          <span class="store-filter-option-text"><?= h($label) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>

                <section class="store-filter-chip-section" data-filter-group="warehouse">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">scale</span>
                      <span class="store-filter-chip-section-label">مدى الكمية</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="grid grid-cols-2 gap-2">
                      <div class="store-inline-field mb-0">
                        <label for="sl-min-warehouse">من</label>
                        <input id="sl-min-warehouse" type="number" step="0.01" min="0" name="forced_min_warehouse_quantity" value="<?= h($constraintsMinWarehouse) ?>" placeholder="0">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-max-warehouse">إلى</label>
                        <input id="sl-max-warehouse" type="number" step="0.01" min="0" name="forced_max_warehouse_quantity" value="<?= h((string) ($constraints['max_warehouse_quantity'] ?? '')) ?>" placeholder="—">
                      </div>
                    </div>
                  </div>
                </section>

                <section class="store-filter-chip-section" data-filter-group="prices">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">payments</span>
                      <span class="store-filter-chip-section-label">حدود الأسعار</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                      <div class="store-inline-field mb-0">
                        <label for="sl-min-sale-syp">أدنى بيع ل.س</label>
                        <input id="sl-min-sale-syp" type="number" step="0.01" min="0" name="forced_min_unit_sale_price_syp" value="<?= h((string) ($constraints['min_unit_sale_price_syp'] ?? '')) ?>">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-max-sale-syp">أعلى بيع ل.س</label>
                        <input id="sl-max-sale-syp" type="number" step="0.01" min="0" name="forced_max_unit_sale_price_syp" value="<?= h((string) ($constraints['max_unit_sale_price_syp'] ?? '')) ?>">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-min-sale-usd">أدنى بيع $</label>
                        <input id="sl-min-sale-usd" type="number" step="0.01" min="0" name="forced_min_unit_sale_price_usd" value="<?= h((string) ($constraints['min_unit_sale_price_usd'] ?? '')) ?>">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-max-sale-usd">أعلى بيع $</label>
                        <input id="sl-max-sale-usd" type="number" step="0.01" min="0" name="forced_max_unit_sale_price_usd" value="<?= h((string) ($constraints['max_unit_sale_price_usd'] ?? '')) ?>">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-min-purchase-usd">أدنى شراء $</label>
                        <input id="sl-min-purchase-usd" type="number" step="0.01" min="0" name="forced_min_unit_purchase_price_usd" value="<?= h((string) ($constraints['min_unit_purchase_price_usd'] ?? '')) ?>">
                      </div>
                      <div class="store-inline-field mb-0">
                        <label for="sl-max-purchase-usd">أعلى شراء $</label>
                        <input id="sl-max-purchase-usd" type="number" step="0.01" min="0" name="forced_max_unit_purchase_price_usd" value="<?= h((string) ($constraints['max_unit_purchase_price_usd'] ?? '')) ?>">
                      </div>
                    </div>
                  </div>
                </section>
              </div>

              <?php if ($storeGroupOptions === []): ?>
                <p class="text-xs text-amber-700 mt-2 mb-0">لم تصل قائمة مخازن من API حاليًا.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </details>

      <details class="dash-mi-download-accordion" data-share-link-accordion="display">
        <summary class="dash-mi-download-accordion__summary">
          <span class="dash-mi-download-accordion__heading">
            <span class="material-symbols-outlined dash-mi-download-accordion__icon" aria-hidden="true">storefront</span>
            <span class="dash-mi-download-accordion__text">
              <span class="dash-mi-download-accordion__title">خيارات عرض الرابط للعميل</span>
              <span class="dash-mi-download-accordion__subtitle">الفلاتر، الأسعار، الترتيب، والتجميع</span>
            </span>
          </span>
        </summary>
        <div class="dash-mi-download-accordion__body">
          <div
            class="dash-mi-zip-filters-shell"
            data-store-filters-root
            data-store-filters-static="1"
          >
            <div class="dash-mi-zip-form__body">
              <div class="flex flex-wrap gap-4 mb-1">
                <label class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700">
                  <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
                  <span>إظهار الصور</span>
                </label>
                <label class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700">
                  <input type="checkbox" name="option_allow_sorting" <?= $allowSorting ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
                  <span>السماح بالترتيب</span>
                </label>
              </div>

              <div class="dash-mi-zip-filters-stack store-filter-chip-layout">
                <?php
                  $renderStoreFilterGroup('option_visible_client_filters', 'الفلاتر المعروضة للعميل', $visibleFilterOptions, $visibleClientFilters, 'visibleFilters', 5, 8, true, $chipFiltersExpanded);
                  $renderStoreFilterGroup('option_client_sort_fields', 'خيارات الترتيب المتاحة للعميل', $sortFieldOptions, $clientSortFields, 'sortFields', 5, 8, true, $chipFiltersExpanded);
                ?>

                <section class="store-filter-chip-section" data-filter-group="priceMode">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">sell</span>
                      <span class="store-filter-chip-section-label">وضع السعر</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                      <?php
                        $priceModeOptions = [
                            'both' => 'سوري + دولار',
                            'syp' => 'سوري فقط',
                            'usd' => 'دولار فقط',
                            'none' => 'بدون سعر',
                        ];
                      ?>
                      <?php foreach ($priceModeOptions as $value => $label): ?>
                        <?php $isActive = $priceMode === $value; ?>
                        <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive ? ' is-selected' : '' ?><?= $isActive && $value === 'both' ? ' is-neutral' : '' ?>">
                          <input type="radio" name="option_price_mode" value="<?= h($value) ?>" <?= $isActive ? 'checked' : '' ?>>
                          <span class="store-filter-option-text"><?= h($label) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>

                <section class="store-filter-chip-section" data-filter-group="groupBy">
                  <div class="store-filter-chip-section-head">
                    <span class="store-filter-chip-section-heading">
                      <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">category</span>
                      <span class="store-filter-chip-section-label">التجميع الافتراضي</span>
                    </span>
                  </div>
                  <div class="store-filter-chip-section-body">
                    <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                      <?php
                        $groupByOptions = [
                            'none' => 'بدون تجميع',
                            'ageCategory' => 'حسب الفئة العمرية',
                            'sizeRange' => 'حسب القياس',
                            'materialType' => 'حسب النوع',
                            'manufacturer' => 'حسب الشركة',
                            'countryOfOrigin' => 'حسب بلد المنشأ',
                            'group' => 'حسب المجموعة',
                        ];
                      ?>
                      <?php foreach ($groupByOptions as $value => $label): ?>
                        <?php $isActive = $defaultGroupBy === $value; ?>
                        <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive ? ' is-selected' : '' ?><?= $isActive && $value === 'none' ? ' is-neutral' : '' ?>">
                          <input type="radio" name="option_default_group_by" value="<?= h($value) ?>" <?= $isActive ? 'checked' : '' ?>>
                          <span class="store-filter-option-text"><?= h($label) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                </section>
              </div>

              <p class="dash-mi-zip-hint text-[11px] text-text-muted mt-2 mb-0">اختر أنواع الفلاتر التي يراها العميل فقط. داخل كل فلتر تُعرض القيم الموجودة في نتائج الرابط.</p>
            </div>
          </div>
        </div>
      </details>

      <details class="dash-mi-download-accordion" data-share-link-accordion="access">
        <summary class="dash-mi-download-accordion__summary">
          <span class="dash-mi-download-accordion__heading">
            <span class="material-symbols-outlined dash-mi-download-accordion__icon" aria-hidden="true">lock</span>
            <span class="dash-mi-download-accordion__text">
              <span class="dash-mi-download-accordion__title">الوصول وكلمة المرور</span>
              <span class="dash-mi-download-accordion__subtitle">حماية اختيارية باسم مستخدم وكلمة مرور</span>
            </span>
          </span>
        </summary>
        <div class="dash-mi-download-accordion__body dash-mi-zip-form__body">
          <label class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-700 mb-3">
            <input type="checkbox" name="require_password" <?= !empty($editLink['require_password']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span>تفعيل حماية الرابط بكلمة مرور</span>
          </label>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="store-inline-field mb-0">
              <span>اسم مستخدم الوصول</span>
              <input name="access_username" autocomplete="off" value="<?= h((string) ($editLink['access_username'] ?? '')) ?>" placeholder="guest-username">
            </label>
            <label class="store-inline-field mb-0">
              <span>كلمة مرور الوصول <?= $editId !== '' ? '(اختياري للتغيير)' : '' ?></span>
              <input name="plain_password" type="password" autocomplete="new-password" placeholder="••••••••">
            </label>
          </div>
        </div>
      </details>
    </div>

    <div class="dash-mi-zip-form__footer mt-3 rounded-xl border border-border-subtle">
      <p class="dash-mi-zip-hint text-[11px] text-text-muted">• لكل رابط token مستقل — القيود اختيارية — استخدم الإيقاف المؤقت بدل الحذف للحفاظ على الإحصاءات.</p>
      <button type="submit" class="dash-mi-zip-download-btn">
        <span class="material-symbols-outlined" aria-hidden="true">save</span>
        <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء الرابط' ?>
      </button>
    </div>
  </form>
</div>
