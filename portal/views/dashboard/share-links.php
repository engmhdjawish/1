<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $links */
/** @var array<string, mixed> $filters */
/** @var array{total: int, active: int, expired: int, protected: int} $stats */
/** @var list<array{id: string, code: string, name_ar: string}> $policies */
/** @var array<string, mixed>|null $editLink */
/** @var string $editId */
/** @var string|null $flash */
/** @var string $flashType */
/** @var string $publicBaseUrl */
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

$linkOptions = (array) (($editLink['options'] ?? null) ?: []);
$showImages = array_key_exists('show_images', $linkOptions) ? (bool) $linkOptions['show_images'] : true;
$priceMode = (string) ($linkOptions['price_mode'] ?? 'both');
$allowClientFilters = array_key_exists('allow_client_filters', $linkOptions) ? (bool) $linkOptions['allow_client_filters'] : true;
$allowSorting = array_key_exists('allow_sorting', $linkOptions) ? (bool) $linkOptions['allow_sorting'] : true;
$includeResultFilters = array_key_exists('include_result_filters', $linkOptions) ? (bool) $linkOptions['include_result_filters'] : true;
$defaultSort = (string) ($linkOptions['default_sort'] ?? 'number:asc');
$defaultGroupBy = (string) ($linkOptions['default_group_by'] ?? 'none');
$visibleClientFilters = array_map('strval', is_array($linkOptions['visible_client_filters'] ?? null) ? $linkOptions['visible_client_filters'] : []);
if ($visibleClientFilters === []) {
    $visibleClientFilters = ['search', 'materialTypes', 'ageCategories', 'manufacturers', 'sizeRanges', 'countryOfOrigins', 'sort'];
}
$defaultSortClauses = array_values(array_filter(array_map('trim', explode(',', $defaultSort)), static fn ($value) => $value !== ''));
if ($defaultSortClauses === []) {
    $defaultSortClauses = ['number:asc'];
}

$selectedMaterialTypes = array_map('strval', $editLink['forced_material_types'] ?? []);
$selectedAgeCategories = array_map('strval', $editLink['forced_age_categories'] ?? []);
$selectedManufacturers = array_map('strval', $editLink['forced_manufacturers'] ?? []);
$selectedSizeRanges = array_map('strval', $editLink['forced_size_ranges'] ?? []);
$selectedCountryOrigins = array_map('strval', $editLink['forced_country_origins'] ?? []);
$selectedStoreGuids = array_map('strval', $editLink['forced_store_guids'] ?? []);
$selectedGroupGuids = array_map('strval', $editLink['forced_group_guids'] ?? []);
$constraints = is_array($editLink['constraints'] ?? null) ? $editLink['constraints'] : [];
$forcedIsAvailable = array_key_exists('is_available', $constraints) ? $constraints['is_available'] : null;

$materialTypeOptions = array_values(array_unique(array_merge($materialFilterOptions['materialTypes'] ?? [], $selectedMaterialTypes)));
$ageCategoryOptions = array_values(array_unique(array_merge($materialFilterOptions['ageCategories'] ?? [], $selectedAgeCategories)));
$manufacturerOptions = array_values(array_unique(array_merge($materialFilterOptions['manufacturers'] ?? [], $selectedManufacturers)));
$sizeRangeOptions = array_values(array_unique(array_merge($materialFilterOptions['sizeRanges'] ?? [], $selectedSizeRanges)));
$countryOriginOptions = array_values(array_unique(array_merge($materialFilterOptions['countryOfOrigins'] ?? [], $selectedCountryOrigins)));
$storeOptions = array_values(array_filter($materialFilterOptions['stores'] ?? [], static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    return trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));
$groupOptions = array_values(array_filter($materialFilterOptions['groups'] ?? [], static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    return trim((string) ($row['guid'] ?? $row['Guid'] ?? '')) !== '';
}));
$priceRanges = is_array($materialFilterOptions['priceRanges'] ?? null) ? $materialFilterOptions['priceRanges'] : [];

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

$toOptionObjects = static function (array $values): array {
    $result = [];
    foreach ($values as $value) {
        $item = trim((string) $value);
        if ($item === '') {
            continue;
        }
        $result[] = ['value' => $item, 'label' => $item];
    }

    return array_values(array_unique($result, SORT_REGULAR));
};

$storeOptionObjects = [];
foreach ($storeOptions as $store) {
    $storeGuid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($storeGuid === '') {
        continue;
    }
    $storeLabel = trim((string) ($store['name'] ?? $store['Name'] ?? '')) !== ''
        ? (string) ($store['name'] ?? $store['Name'])
        : ((string) ($store['code'] ?? $store['Code'] ?? '') !== '' ? (string) ($store['code'] ?? $store['Code']) : $storeGuid);
    $storeOptionObjects[] = ['value' => $storeGuid, 'label' => $storeLabel];
}

$groupOptionObjects = [];
foreach ($groupOptions as $group) {
    $groupGuid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($groupGuid === '') {
        continue;
    }
    $groupLabel = trim((string) ($group['name'] ?? $group['Name'] ?? '')) !== ''
        ? (string) ($group['name'] ?? $group['Name'])
        : ((string) ($group['code'] ?? $group['Code'] ?? '') !== '' ? (string) ($group['code'] ?? $group['Code']) : $groupGuid);
    $groupOptionObjects[] = ['value' => $groupGuid, 'label' => $groupLabel];
}

$sortPresetOptions = [
    ['value' => 'number:asc', 'label' => 'رقم المادة تصاعدي'],
    ['value' => 'number:desc', 'label' => 'رقم المادة تنازلي'],
    ['value' => 'materialType:asc', 'label' => 'النوع تصاعدي'],
    ['value' => 'ageCategory:asc', 'label' => 'العمر تصاعدي'],
    ['value' => 'manufacturer:asc', 'label' => 'الشركة تصاعدي'],
    ['value' => 'sizeRange:asc', 'label' => 'القياس تصاعدي'],
    ['value' => 'countryOfOrigin:asc', 'label' => 'بلد المنشأ تصاعدي'],
    ['value' => '-unitSalePriceSyp', 'label' => 'سعر البيع ل.س تنازلي'],
    ['value' => 'unitSalePriceSyp:asc', 'label' => 'سعر البيع ل.س تصاعدي'],
    ['value' => 'unitSalePriceUsd:asc', 'label' => 'سعر البيع دولار تصاعدي'],
    ['value' => 'unitSalePriceUsd:desc', 'label' => 'سعر البيع دولار تنازلي'],
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
    ['value' => 'sort', 'label' => 'الترتيب'],
    ['value' => 'groupBy', 'label' => 'التجميع'],
];

$renderTokenPicker = static function (
    string $title,
    string $inputName,
    array $optionItems,
    array $selectedItems,
    string $pickerId,
    bool $showAllButton = true
): void {
    $selectedNormalized = array_values(array_unique(array_filter(array_map('strval', $selectedItems), static fn ($value) => trim($value) !== '')));
    ?>
    <div class="token-picker space-y-2" data-picker-id="<?= h($pickerId) ?>" data-input-name="<?= h($inputName) ?>">
      <span class="text-text-muted block mb-1 text-sm"><?= h($title) ?></span>
      <div class="flex flex-wrap gap-2">
        <input type="text" data-role="search" class="h-10 min-w-[180px] flex-1 rounded-lg border border-border-subtle px-3 focus:border-primary focus:ring-primary" placeholder="ابحث ضمن الخيارات...">
        <button type="button" data-action="add" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">إضافة</button>
        <?php if ($showAllButton): ?>
          <button type="button" data-action="add-all" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">الكل</button>
        <?php endif; ?>
        <button type="button" data-action="clear" class="h-10 px-3 rounded-lg border border-border-subtle text-sm">تفريغ</button>
      </div>
      <select data-role="options" multiple size="6" class="w-full rounded-lg border border-border-subtle px-3 py-2 text-sm focus:border-primary focus:ring-primary">
        <?php foreach ($optionItems as $option): ?>
          <?php $value = trim((string) ($option['value'] ?? '')); ?>
          <?php if ($value === '') {
              continue;
          } ?>
          <?php $label = trim((string) ($option['label'] ?? $value)); ?>
          <option value="<?= h($value) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <div data-role="chips" class="flex flex-wrap gap-2"></div>
      <div data-role="hidden-inputs"></div>
      <script type="application/json" data-role="selected-values"><?= h(json_encode($selectedNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?></script>
    </div>
    <?php
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">إدارة روابط المشاركة</h1>
    <p class="text-sm text-text-muted mt-1">إنشاء الروابط التسويقية وضبط صلاحيات الوصول والفلاتر المرتبطة بها.</p>
  </div>
  <div class="flex flex-wrap gap-3">
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold"><?= (int) $stats['total'] ?></p>
      <p class="text-xs text-text-muted">إجمالي الروابط</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-green-700"><?= (int) $stats['active'] ?></p>
      <p class="text-xs text-text-muted">روابط نشطة</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-amber-700"><?= (int) $stats['expired'] ?></p>
      <p class="text-xs text-text-muted">منتهية</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-blue-700"><?= (int) $stats['protected'] ?></p>
      <p class="text-xs text-text-muted">محمي بكلمة مرور</p>
    </article>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid grid-cols-1 xl:grid-cols-[1fr_420px] gap-5 mb-6">
  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-bold text-lg"><?= $editId !== '' ? 'تعديل رابط المشاركة' : 'إضافة رابط مشاركة جديد' ?></h2>
      <?php if ($editId !== ''): ?>
        <a href="/dashboard/share-links.php" class="text-sm text-text-muted hover:text-primary">إلغاء التعديل</a>
      <?php endif; ?>
    </div>
    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= h((string) ($editLink['id'] ?? '')) ?>">
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">اسم الرابط</span>
        <input name="name_ar" required value="<?= h((string) ($editLink['name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="مثال: عروض الصيف - العملاء المميزون">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">سياسة الوصول</span>
        <select name="access_policy_id" required class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          <option value="">اختر السياسة</option>
          <?php foreach ($policies as $policy): ?>
            <option value="<?= h($policy['id']) ?>" <?= (string) ($editLink['access_policy_id'] ?? '') === (string) $policy['id'] ? 'selected' : '' ?>>
              <?= h($policy['name_ar']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">كلمة مفتاحية</span>
        <input name="keyword" value="<?= h((string) ($editLink['keyword'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="new-arrivals">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أقل كمية مطلوبة</span>
        <input name="min_quantity" type="number" min="0" step="0.01" value="<?= h((string) ($editLink['min_quantity'] ?? '0')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">ينتهي بتاريخ</span>
        <input name="expires_at" type="datetime-local" value="<?= h(isset($editLink['expires_at']) && $editLink['expires_at'] ? str_replace(' ', 'T', substr((string) $editLink['expires_at'], 0, 16)) : '') ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="require_password" <?= !empty($editLink['require_password']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>حماية الرابط بكلمة مرور</span>
      </label>
      <?php if ($materialFilterOptionsError): ?>
        <p class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-3 py-2 text-xs">
          <?= h($materialFilterOptionsError) ?>
        </p>
      <?php endif; ?>
      <div class="md:col-span-2">
        <?php $renderTokenPicker('تقييد اختياري: نوع المادة', 'forced_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'forced-material-types'); ?>
      </div>
      <div class="md:col-span-2">
        <?php $renderTokenPicker('تقييد اختياري: الفئة العمرية', 'forced_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'forced-age-categories'); ?>
      </div>
      <div class="md:col-span-1">
        <?php $renderTokenPicker('تقييد اختياري: الشركة', 'forced_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'forced-manufacturers'); ?>
      </div>
      <div class="md:col-span-1">
        <?php $renderTokenPicker('تقييد اختياري: القياس', 'forced_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'forced-size-ranges'); ?>
      </div>
      <div class="md:col-span-2">
        <?php $renderTokenPicker('تقييد اختياري: بلد المنشأ', 'forced_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'forced-country-origins'); ?>
      </div>
      <div class="md:col-span-2">
        <?php $renderTokenPicker('تقييد اختياري: المخازن', 'forced_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'forced-store-guids'); ?>
        <?php if ($storeOptionObjects === []): ?>
          <p class="mt-1 text-xs text-amber-700">لم تصل قائمة مخازن من API حاليًا. تأكد من البيانات والصلاحيات، أو حدّث الصفحة بعد توفرها.</p>
        <?php endif; ?>
      </div>
      <div class="md:col-span-2">
        <?php $renderTokenPicker('تقييد اختياري: المجموعات', 'forced_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'forced-group-guids'); ?>
      </div>
      <div class="md:col-span-2 rounded-xl border border-border-subtle p-4 bg-surface-low">
        <h3 class="text-sm font-bold mb-3">قيود متقدمة إضافية</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <label class="text-sm">
            <span class="text-text-muted block mb-1">توفر المادة</span>
            <select name="forced_is_available" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
              <option value="" <?= $forcedIsAvailable === null ? 'selected' : '' ?>>بدون قيد</option>
              <option value="1" <?= $forcedIsAvailable === true ? 'selected' : '' ?>>متوفر فقط</option>
              <option value="0" <?= $forcedIsAvailable === false ? 'selected' : '' ?>>غير متوفر فقط</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">أدنى كمية بالمخزون</span>
            <input type="number" step="0.01" min="0" name="forced_min_warehouse_quantity" value="<?= h((string) ($constraints['min_warehouse_quantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">أعلى كمية بالمخزون</span>
            <input type="number" step="0.01" min="0" name="forced_max_warehouse_quantity" value="<?= h((string) ($constraints['max_warehouse_quantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">
              أدنى سعر بيع ل.س
              <?php if (is_array($priceRanges['unitSalePriceSyp'] ?? null)): ?>
                <small class="text-text-muted">(API: <?= h((string) ($priceRanges['unitSalePriceSyp']['min'] ?? '0')) ?> - <?= h((string) ($priceRanges['unitSalePriceSyp']['max'] ?? '0')) ?>)</small>
              <?php endif; ?>
            </span>
            <input type="number" step="0.01" min="0" name="forced_min_unit_sale_price_syp" value="<?= h((string) ($constraints['min_unit_sale_price_syp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">أعلى سعر بيع ل.س</span>
            <input type="number" step="0.01" min="0" name="forced_max_unit_sale_price_syp" value="<?= h((string) ($constraints['max_unit_sale_price_syp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">
              أدنى سعر بيع $
              <?php if (is_array($priceRanges['unitSalePriceUsd'] ?? null)): ?>
                <small class="text-text-muted">(API: <?= h((string) ($priceRanges['unitSalePriceUsd']['min'] ?? '0')) ?> - <?= h((string) ($priceRanges['unitSalePriceUsd']['max'] ?? '0')) ?>)</small>
              <?php endif; ?>
            </span>
            <input type="number" step="0.01" min="0" name="forced_min_unit_sale_price_usd" value="<?= h((string) ($constraints['min_unit_sale_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">أعلى سعر بيع $</span>
            <input type="number" step="0.01" min="0" name="forced_max_unit_sale_price_usd" value="<?= h((string) ($constraints['max_unit_sale_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">
              أدنى سعر شراء $
              <?php if (is_array($priceRanges['unitPurchasePriceUsd'] ?? null)): ?>
                <small class="text-text-muted">(API: <?= h((string) ($priceRanges['unitPurchasePriceUsd']['min'] ?? '0')) ?> - <?= h((string) ($priceRanges['unitPurchasePriceUsd']['max'] ?? '0')) ?>)</small>
              <?php endif; ?>
            </span>
            <input type="number" step="0.01" min="0" name="forced_min_unit_purchase_price_usd" value="<?= h((string) ($constraints['min_unit_purchase_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">أعلى سعر شراء $</span>
            <input type="number" step="0.01" min="0" name="forced_max_unit_purchase_price_usd" value="<?= h((string) ($constraints['max_unit_purchase_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          </label>
        </div>
      </div>

      <div class="md:col-span-2 rounded-xl border border-border-subtle p-4 bg-surface-low">
        <h3 class="text-sm font-bold mb-3">خيارات عرض الرابط للعميل</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="text-sm inline-flex items-center gap-2">
            <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span>إظهار الصور</span>
          </label>
          <label class="text-sm inline-flex items-center gap-2">
            <input type="checkbox" name="option_allow_client_filters" <?= $allowClientFilters ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span>السماح للعميل بالفلاتر</span>
          </label>
          <label class="text-sm inline-flex items-center gap-2">
            <input type="checkbox" name="option_allow_sorting" <?= $allowSorting ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span>السماح للعميل بالترتيب</span>
          </label>
          <label class="text-sm inline-flex items-center gap-2">
            <input type="checkbox" name="option_include_result_filters" <?= $includeResultFilters ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
            <span>إظهار فلاتر النتائج الديناميكية</span>
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">وضع السعر</span>
            <select name="option_price_mode" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
              <option value="both" <?= $priceMode === 'both' ? 'selected' : '' ?>>سوري + دولار</option>
              <option value="syp" <?= $priceMode === 'syp' ? 'selected' : '' ?>>سوري فقط</option>
              <option value="usd" <?= $priceMode === 'usd' ? 'selected' : '' ?>>دولار فقط</option>
              <option value="none" <?= $priceMode === 'none' ? 'selected' : '' ?>>بدون سعر</option>
            </select>
          </label>
          <div class="text-sm md:col-span-2">
            <?php $renderTokenPicker('الترتيب الافتراضي (قائمة جاهزة)', 'option_default_sort_clauses[]', $sortPresetOptions, $defaultSortClauses, 'option-default-sort', false); ?>
            <p class="text-xs text-text-muted mt-1">يمكن إضافة أكثر من ترتيب، وسيتم تطبيقها بنفس ترتيب العناصر المختارة.</p>
          </div>
          <label class="text-sm">
            <span class="text-text-muted block mb-1">التجميع الافتراضي</span>
            <select name="option_default_group_by" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
              <option value="none" <?= $defaultGroupBy === 'none' ? 'selected' : '' ?>>بدون تجميع</option>
              <option value="ageCategory" <?= $defaultGroupBy === 'ageCategory' ? 'selected' : '' ?>>حسب الفئة العمرية</option>
              <option value="sizeRange" <?= $defaultGroupBy === 'sizeRange' ? 'selected' : '' ?>>حسب القياس</option>
              <option value="materialType" <?= $defaultGroupBy === 'materialType' ? 'selected' : '' ?>>حسب النوع</option>
              <option value="manufacturer" <?= $defaultGroupBy === 'manufacturer' ? 'selected' : '' ?>>حسب الشركة</option>
              <option value="countryOfOrigin" <?= $defaultGroupBy === 'countryOfOrigin' ? 'selected' : '' ?>>حسب بلد المنشأ</option>
              <option value="group" <?= $defaultGroupBy === 'group' ? 'selected' : '' ?>>حسب المجموعة</option>
            </select>
          </label>
          <div class="text-sm md:col-span-2">
            <?php $renderTokenPicker('فلاتر واجهة العميل المرئية فقط', 'option_visible_client_filters[]', $visibleFilterOptions, $visibleClientFilters, 'option-visible-client-filters'); ?>
            <p class="text-xs text-text-muted mt-1">الواجهة النهائية ستعرض فقط الفلاتر المحددة هنا لتحسين تجربة المستخدم.</p>
          </div>
        </div>
      </div>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">اسم مستخدم الوصول</span>
        <input name="access_username" value="<?= h((string) ($editLink['access_username'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="guest-username">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">كلمة مرور الوصول <?= $editId !== '' ? '(اختياري للتغيير)' : '' ?></span>
        <input name="plain_password" type="password" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="••••••••">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" <?= $editLink === null || !empty($editLink['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>الرابط نشط</span>
      </label>
      <div class="md:col-span-2 flex justify-end">
        <button class="h-11 px-6 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء رابط' ?>
        </button>
      </div>
    </form>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h3 class="font-bold mb-3">ملاحظات الاستخدام</h3>
    <ul class="space-y-2 text-sm text-text-muted">
      <li>• لكل رابط token مستقل يمكن مشاركته على المسوّقين أو العملاء.</li>
      <li>• عند تفعيل كلمة المرور، يصبح الدخول عبر user/pass للرابط.</li>
      <li>• القيود اختيارية وتُختار من فلاتر API المتاحة (بدون إدخال يدوي).</li>
      <li>• عند اختيار أكثر من قيمة داخل نفس الحقل يكون الشرط مركبًا (OR)، وبين الحقول المختلفة يكون (AND).</li>
      <li>• يمكنك فرض قيود متقدمة مثل التوفر، المدى السعري، الكمية، المخازن، والمجموعات.</li>
      <li>• يمكنك التحكم بإظهار الصور ووضع عرض السعر (سوري/دولار/كلاهما/بدون).</li>
      <li>• يدعم الترتيب المركب من API مثل: <code>ageCategory:asc,materialType:asc</code>.</li>
      <li>• استخدم الإيقاف المؤقت للرابط بدل الحذف للحفاظ على الإحصاءات.</li>
    </ul>
  </article>
</section>

<section class="bg-white border border-border-subtle rounded-2xl p-5 mb-5">
  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">بحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="اسم الرابط أو التوكن">
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">الحالة</span>
      <select name="active" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <option value="1" <?= ($filters['active'] ?? '') === '1' ? 'selected' : '' ?>>نشط</option>
        <option value="0" <?= ($filters['active'] ?? '') === '0' ? 'selected' : '' ?>>متوقف</option>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">عدد النتائج</span>
      <select name="limit" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="50" <?= ((int) ($filters['limit'] ?? 100)) === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= ((int) ($filters['limit'] ?? 100)) === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= ((int) ($filters['limit'] ?? 100)) === 200 ? 'selected' : '' ?>>200</option>
      </select>
    </label>
    <button class="h-11 rounded-xl bg-primary text-white font-bold px-5 hover:brightness-110 transition">تطبيق</button>
  </form>
</section>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <?php if ($links === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد روابط مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1050px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-5 py-4 font-bold">الاسم</th>
            <th class="text-right px-5 py-4 font-bold">التوكن</th>
            <th class="text-right px-5 py-4 font-bold">السياسة</th>
            <th class="text-right px-5 py-4 font-bold">الفلاتر الأولية</th>
            <th class="text-right px-5 py-4 font-bold">انتهاء الصلاحية</th>
            <th class="text-right px-5 py-4 font-bold">الحالة</th>
            <th class="text-right px-5 py-4 font-bold">رابط المشاركة</th>
            <th class="text-left px-5 py-4 font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($links as $row): ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4 font-bold"><?= h((string) ($row['name_ar'] ?? '')) ?></td>
              <td class="px-5 py-4 text-xs">
                <div class="font-mono text-slate-700"><?= h((string) ($row['public_token'] ?? '')) ?></div>
                <?php if (!empty($row['require_password'])): ?>
                  <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[11px]">محمي</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4"><?= h((string) ($row['access_policy_name_ar'] ?? '')) ?></td>
              <td class="px-5 py-4 text-sm text-text-muted">
                <div>keyword: <?= h((string) ($row['keyword'] ?? '—')) ?></div>
                <div>minQty: <?= number_format((float) ($row['min_quantity'] ?? 0), 0, '.', ',') ?></div>
              </td>
              <td class="px-5 py-4 text-xs text-text-muted"><?= h((string) ($row['expires_at'] ?? 'غير محدد')) ?></td>
              <td class="px-5 py-4">
                <?php if (!empty($row['is_active'])): ?>
                  <span class="inline-flex rounded-full bg-green-100 text-green-700 px-3 py-1 text-xs font-bold">نشط</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-slate-100 text-slate-700 px-3 py-1 text-xs font-bold">متوقف</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-4 text-xs">
                <a
                  href="/share.php?token=<?= urlencode((string) ($row['public_token'] ?? '')) ?>"
                  target="_blank"
                  class="font-mono text-primary underline"
                >
                  <?= h($publicBaseUrl . '/share.php?token=' . (string) ($row['public_token'] ?? '')) ?>
                </a>
              </td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a href="/dashboard/share-links.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-9 px-3 rounded-lg border border-border-subtle text-xs text-text-muted bg-white hover:bg-surface-low">تعديل</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold <?= !empty($row['is_active']) ? 'bg-slate-800 text-white' : 'bg-primary text-white' ?>">
                      <?= !empty($row['is_active']) ? 'إيقاف' : 'تفعيل' ?>
                    </button>
                  </form>
                  <form method="post" onsubmit="return confirm('هل أنت متأكد من حذف الرابط؟')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold bg-red-600 text-white">حذف</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
(() => {
  const normalize = (value) => (value || '').toString().trim();

  const initPicker = (picker) => {
    const inputName = picker.dataset.inputName;
    const searchInput = picker.querySelector('[data-role="search"]');
    const optionsSelect = picker.querySelector('[data-role="options"]');
    const chipsHost = picker.querySelector('[data-role="chips"]');
    const hiddenHost = picker.querySelector('[data-role="hidden-inputs"]');
    const selectedScript = picker.querySelector('script[data-role="selected-values"]');
    const addButton = picker.querySelector('[data-action="add"]');
    const addAllButton = picker.querySelector('[data-action="add-all"]');
    const clearButton = picker.querySelector('[data-action="clear"]');

    const allOptions = Array.from(optionsSelect.options).map((option) => ({
      value: normalize(option.value),
      label: normalize(option.textContent),
    })).filter((option) => option.value !== '');

    let selectedValues = [];
    try {
      const parsed = JSON.parse(selectedScript?.textContent || '[]');
      if (Array.isArray(parsed)) {
        selectedValues = parsed.map((value) => normalize(value)).filter((value) => value !== '');
      }
    } catch (_) {
      selectedValues = [];
    }
    selectedValues = Array.from(new Set(selectedValues));

    const renderOptions = () => {
      const search = normalize(searchInput?.value || '').toLowerCase();
      for (const option of Array.from(optionsSelect.options)) {
        const text = normalize(option.textContent).toLowerCase();
        const value = normalize(option.value).toLowerCase();
        option.hidden = search !== '' && !text.includes(search) && !value.includes(search);
      }
    };

    const renderSelected = () => {
      chipsHost.innerHTML = '';
      hiddenHost.innerHTML = '';
      for (const value of selectedValues) {
        const option = allOptions.find((item) => item.value === value);
        const label = option ? option.label : value;

        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs';
        chip.textContent = label;
        chip.title = 'حذف';

        const remove = document.createElement('span');
        remove.className = 'text-slate-500';
        remove.textContent = '×';
        chip.appendChild(remove);
        chip.addEventListener('click', () => {
          selectedValues = selectedValues.filter((item) => item !== value);
          renderSelected();
        });
        chipsHost.appendChild(chip);

        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = inputName;
        hiddenInput.value = value;
        hiddenHost.appendChild(hiddenInput);
      }
    };

    const addValues = (values) => {
      for (const value of values) {
        const normalized = normalize(value);
        if (normalized === '') continue;
        if (!allOptions.some((option) => option.value === normalized)) continue;
        if (!selectedValues.includes(normalized)) {
          selectedValues.push(normalized);
        }
      }
      renderSelected();
    };

    addButton?.addEventListener('click', () => {
      const picked = Array.from(optionsSelect.selectedOptions).map((option) => option.value);
      addValues(picked);
    });
    addAllButton?.addEventListener('click', () => {
      addValues(allOptions.map((option) => option.value));
    });
    clearButton?.addEventListener('click', () => {
      selectedValues = [];
      renderSelected();
    });
    searchInput?.addEventListener('input', renderOptions);
    optionsSelect?.addEventListener('dblclick', () => {
      const picked = Array.from(optionsSelect.selectedOptions).map((option) => option.value);
      addValues(picked);
    });

    renderOptions();
    renderSelected();
  };

  document.querySelectorAll('.token-picker').forEach((picker) => initPicker(picker));
})();
</script>
