<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $links */
/** @var array<string, mixed> $filters */
/** @var array{total: int, active: int, expired: int, protected: int} $stats */
/** @var list<array{id: string, code: string, name_ar: string}> $policies */
/** @var array<string, mixed>|null $editLink */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $isNew */
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

$editLink = is_array($editLink ?? null) ? $editLink : [];
$linkOptions = (array) (($editLink['options'] ?? null) ?: []);
$showImages = array_key_exists('show_images', $linkOptions) ? (bool) $linkOptions['show_images'] : true;
$priceMode = (string) ($linkOptions['price_mode'] ?? 'both');
$allowClientFilters = array_key_exists('allow_client_filters', $linkOptions) ? (bool) $linkOptions['allow_client_filters'] : true;
$allowSorting = array_key_exists('allow_sorting', $linkOptions) ? (bool) $linkOptions['allow_sorting'] : true;
$defaultSort = (string) ($linkOptions['default_sort'] ?? 'number:asc');
$defaultGroupBy = (string) ($linkOptions['default_group_by'] ?? 'none');
$visibleClientFilters = array_map('strval', is_array($linkOptions['visible_client_filters'] ?? null) ? $linkOptions['visible_client_filters'] : []);
if ($visibleClientFilters === []) {
    $visibleClientFilters = ['search'];
}
$clientSortFields = array_map('strval', is_array($linkOptions['client_sort_fields'] ?? null) ? $linkOptions['client_sort_fields'] : []);
if ($clientSortFields === []) {
    foreach (array_filter(array_map('trim', explode(',', $defaultSort))) as $clause) {
        if ($clause === '') {
            continue;
        }
        $field = str_starts_with($clause, '-') ? substr($clause, 1) : explode(':', $clause, 2)[0];
        $field = trim((string) $field);
        if ($field !== '') {
            $clientSortFields[] = $field;
        }
    }
}
if ($clientSortFields === []) {
    $clientSortFields = ['number', 'materialType', 'manufacturer'];
}
$clientSortFields = array_values(array_unique($clientSortFields));

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

$showForm = $showForm ?? false;
$isNew = $isNew ?? false;

require __DIR__ . '/partials/token-picker.php';

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

$shareUrlFor = static function (string $token) use ($publicBaseUrl): string {
    return $publicBaseUrl . '/share.php?token=' . rawurlencode($token);
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-3 mb-4">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">إدارة روابط المشاركة</h1>
    <p class="text-sm text-text-muted mt-1">إنشاء الروابط التسويقية وضبط صلاحيات الوصول والفلاتر المرتبطة بها.</p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <?php if (!$showForm): ?>
      <a href="/dashboard/share-links.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">رابط جديد</a>
    <?php endif; ?>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 min-w-20 text-center">
      <p class="text-lg font-extrabold"><?= (int) $stats['total'] ?></p>
      <p class="text-[11px] text-text-muted">إجمالي</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 min-w-20 text-center">
      <p class="text-lg font-extrabold text-emerald-700"><?= (int) $stats['active'] ?></p>
      <p class="text-[11px] text-text-muted">نشط</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 min-w-20 text-center">
      <p class="text-lg font-extrabold text-amber-700"><?= (int) $stats['expired'] ?></p>
      <p class="text-[11px] text-text-muted">منتهي</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 min-w-20 text-center">
      <p class="text-lg font-extrabold text-blue-700"><?= (int) $stats['protected'] ?></p>
      <p class="text-[11px] text-text-muted">محمي</p>
    </article>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<?php if ($showForm): ?>
<?php if ($editId !== ''): ?>
  <form method="post" id="sl-delete-form" class="hidden" onsubmit="return confirm('هل أنت متأكد من حذف هذا الرابط؟')">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= h($editId) ?>">
  </form>
<?php endif; ?>

<form method="post" id="share-link-form" class="space-y-3 mb-4">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= h((string) ($editLink['id'] ?? '')) ?>">

  <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل رابط المشاركة' : 'رابط مشاركة جديد' ?></h2>
    <div class="flex flex-wrap items-center gap-2">
      <a href="/dashboard/share-links.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"><?= $editId !== '' ? 'إلغاء التعديل' : 'إلغاء' ?></a>
      <?php if ($editId !== ''): ?>
        <button type="submit" form="sl-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
      <?php endif; ?>
      <button type="submit" id="share-link-save-btn" class="h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">
        <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء الرابط' ?>
      </button>
    </div>
  </div>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">اسم الرابط *</span>
        <input name="name_ar" required value="<?= h((string) ($editLink['name_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary" placeholder="مثال: عروض الصيف - العملاء المميزون">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">سياسة الوصول *</span>
        <select name="access_policy_id" required class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
          <option value="">اختر السياسة</option>
          <?php foreach ($policies as $policy): ?>
            <option value="<?= h($policy['id']) ?>" <?= (string) ($editLink['access_policy_id'] ?? '') === (string) $policy['id'] ? 'selected' : '' ?>>
              <?= h($policy['name_ar']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">كلمة مفتاحية</span>
        <input name="keyword" value="<?= h((string) ($editLink['keyword'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary" placeholder="new-arrivals">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">ينتهي بتاريخ</span>
        <input name="expires_at" type="datetime-local" value="<?= h(isset($editLink['expires_at']) && $editLink['expires_at'] ? str_replace(' ', 'T', substr((string) $editLink['expires_at'], 0, 16)) : '') ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
      </label>
      <div class="text-xs flex flex-wrap items-center gap-4 pt-5">
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="is_active" <?= $isNew || !empty($editLink['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>نشط</span>
        </label>
      </div>
    </div>
    <?php if ($editId !== '' && !empty($editLink['public_token'])): ?>
      <div class="mt-2 pt-2 border-t border-border-subtle flex flex-wrap items-center gap-2 text-xs">
        <span class="text-text-muted">رابط المشاركة:</span>
        <code class="font-mono text-slate-700 bg-surface-low px-2 py-1 rounded"><?= h($shareUrlFor((string) $editLink['public_token'])) ?></code>
        <button type="button" data-copy-url="<?= h($shareUrlFor((string) $editLink['public_token'])) ?>" class="h-7 px-2 rounded border border-border-subtle bg-white hover:bg-slate-50 font-bold text-slate-700">نسخ</button>
        <a href="/share.php?token=<?= urlencode((string) $editLink['public_token']) ?>" target="_blank" class="text-primary font-bold hover:underline">فتح</a>
      </div>
    <?php endif; ?>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <details open class="group">
      <summary class="font-bold text-sm cursor-pointer list-none flex items-center justify-between gap-2">
        <span>فلاتر المواد المفروضة</span>
        <span class="text-xs text-text-muted font-normal">OR داخل الحقل — AND بين الحقول</span>
      </summary>
      <div class="mt-2 space-y-2">
        <?php if ($materialFilterOptionsError): ?>
          <p class="rounded-lg border border-amber-200 bg-amber-50 text-amber-700 px-2 py-1.5 text-xs"><?= h($materialFilterOptionsError) ?></p>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'forced_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'sl-material-types', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'forced_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'sl-age-categories', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('الشركة', 'forced_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'sl-manufacturers', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('القياس', 'forced_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'sl-size-ranges', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'forced_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'sl-country-origins', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'forced_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'sl-store-guids', false, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'forced_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'sl-group-guids', false, false, false, 4); ?></div>
        </div>
        <?php if ($storeOptionObjects === []): ?>
          <p class="text-xs text-amber-700">لم تصل قائمة مخازن من API حاليًا.</p>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 pt-1">
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">التوفر</span>
            <select name="forced_is_available" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
              <option value="" <?= $forcedIsAvailable === null ? 'selected' : '' ?>>بدون قيد</option>
              <option value="1" <?= $forcedIsAvailable === true ? 'selected' : '' ?>>متوفر</option>
              <option value="0" <?= $forcedIsAvailable === false ? 'selected' : '' ?>>غير متوفر</option>
            </select>
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">الصورة</span>
            <select name="forced_has_image" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
              <option value="" <?= $forcedHasImage === null ? 'selected' : '' ?>>بدون قيد</option>
              <option value="1" <?= $forcedHasImage === true ? 'selected' : '' ?>>مع صورة</option>
              <option value="0" <?= $forcedHasImage === false ? 'selected' : '' ?>>بدون صورة</option>
            </select>
          </label>
        </div>

        <details open class="rounded-lg border border-border-subtle bg-surface-low">
          <summary class="px-3 py-2 text-xs font-bold cursor-pointer">مخزون وأسعار (متقدم)</summary>
          <p class="px-3 text-[11px] text-text-muted">حدود المخزون والأسعار تُطبَّق على نتائج الرابط. استخدم «أدنى مخزون» لإخفاء المواد ذات الكمية المنخفضة.</p>
          <div class="px-3 pb-3 grid grid-cols-2 md:grid-cols-4 gap-2">
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى مخزون</span>
              <input type="number" step="0.01" min="0" name="forced_min_warehouse_quantity" value="<?= h($constraintsMinWarehouse) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى مخزون</span>
              <input type="number" step="0.01" min="0" name="forced_max_warehouse_quantity" value="<?= h((string) ($constraints['max_warehouse_quantity'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى بيع ل.س</span>
              <input type="number" step="0.01" min="0" name="forced_min_unit_sale_price_syp" value="<?= h((string) ($constraints['min_unit_sale_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى بيع ل.س</span>
              <input type="number" step="0.01" min="0" name="forced_max_unit_sale_price_syp" value="<?= h((string) ($constraints['max_unit_sale_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى بيع $</span>
              <input type="number" step="0.01" min="0" name="forced_min_unit_sale_price_usd" value="<?= h((string) ($constraints['min_unit_sale_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى بيع $</span>
              <input type="number" step="0.01" min="0" name="forced_max_unit_sale_price_usd" value="<?= h((string) ($constraints['max_unit_sale_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى شراء $</span>
              <input type="number" step="0.01" min="0" name="forced_min_unit_purchase_price_usd" value="<?= h((string) ($constraints['min_unit_purchase_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى شراء $</span>
              <input type="number" step="0.01" min="0" name="forced_max_unit_purchase_price_usd" value="<?= h((string) ($constraints['max_unit_purchase_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
          </div>
        </details>
      </div>
    </details>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <details class="group">
      <summary class="font-bold text-sm cursor-pointer list-none">خيارات عرض الرابط للعميل</summary>
      <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
        <label class="text-xs inline-flex items-center gap-1.5">
          <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>إظهار الصور</span>
        </label>
        <label class="text-xs inline-flex items-center gap-1.5 md:col-span-2">
          <input type="checkbox" name="option_allow_client_filters" <?= $allowClientFilters ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>السماح بفلاتر للعميل</span>
        </label>
        <label class="text-xs inline-flex items-center gap-1.5">
          <input type="checkbox" name="option_allow_sorting" <?= $allowSorting ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>السماح بالترتيب</span>
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">وضع السعر</span>
          <select name="option_price_mode" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            <option value="both" <?= $priceMode === 'both' ? 'selected' : '' ?>>سوري + دولار</option>
            <option value="syp" <?= $priceMode === 'syp' ? 'selected' : '' ?>>سوري فقط</option>
            <option value="usd" <?= $priceMode === 'usd' ? 'selected' : '' ?>>دولار فقط</option>
            <option value="none" <?= $priceMode === 'none' ? 'selected' : '' ?>>بدون سعر</option>
          </select>
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">التجميع الافتراضي</span>
          <select name="option_default_group_by" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            <option value="none" <?= $defaultGroupBy === 'none' ? 'selected' : '' ?>>بدون تجميع</option>
            <option value="ageCategory" <?= $defaultGroupBy === 'ageCategory' ? 'selected' : '' ?>>حسب الفئة العمرية</option>
            <option value="sizeRange" <?= $defaultGroupBy === 'sizeRange' ? 'selected' : '' ?>>حسب القياس</option>
            <option value="materialType" <?= $defaultGroupBy === 'materialType' ? 'selected' : '' ?>>حسب النوع</option>
            <option value="manufacturer" <?= $defaultGroupBy === 'manufacturer' ? 'selected' : '' ?>>حسب الشركة</option>
            <option value="countryOfOrigin" <?= $defaultGroupBy === 'countryOfOrigin' ? 'selected' : '' ?>>حسب بلد المنشأ</option>
            <option value="group" <?= $defaultGroupBy === 'group' ? 'selected' : '' ?>>حسب المجموعة</option>
          </select>
        </label>
        <div class="text-xs md:col-span-2">
          <?php $renderTokenPicker('خيارات الترتيب المتاحة للعميل', 'option_client_sort_fields[]', $sortFieldOptions, $clientSortFields, 'sl-client-sort-fields', false, false, false, 4); ?>
          <p class="mt-1 text-[11px] text-text-muted">اختر حقول الترتيب فقط — العميل يحدد تصاعدي أو تنازلي بالضغط على الخيار.</p>
        </div>
        <div class="text-xs md:col-span-2">
          <?php $renderTokenPicker('الفلاتر المعروضة للعميل', 'option_visible_client_filters[]', $visibleFilterOptions, $visibleClientFilters, 'sl-visible-client-filters', true, false, false, 4); ?>
          <p class="mt-1 text-[11px] text-text-muted">اختر أنواع الفلاتر التي يراها العميل. داخل كل فلتر تظهر فقط القيم الموجودة في نتائج الرابط — مثلاً «نسواني» دون «رجالي» إذا لم تكن رجالي ضمن النتائج رغم فرضها في قيود الرابط.</p>
        </div>
      </div>
    </details>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <details class="group">
      <summary class="font-bold text-sm cursor-pointer list-none">الوصول وكلمة المرور</summary>
      <div class="mt-2 space-y-2">
        <label class="text-xs inline-flex items-center gap-1.5">
          <input type="checkbox" name="require_password" <?= !empty($editLink['require_password']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>تفعيل حماية الرابط بكلمة مرور</span>
        </label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">اسم مستخدم الوصول</span>
            <input name="access_username" value="<?= h((string) ($editLink['access_username'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="guest-username">
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">كلمة مرور الوصول <?= $editId !== '' ? '(اختياري للتغيير)' : '' ?></span>
            <input name="plain_password" type="password" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="••••••••">
          </label>
        </div>
      </div>
    </details>
  </article>

  <details class="bg-white border border-border-subtle rounded-xl p-3">
    <summary class="font-bold text-sm cursor-pointer list-none text-text-muted">ملاحظات الاستخدام</summary>
    <ul class="mt-2 space-y-1 text-xs text-text-muted">
      <li>• لكل رابط token مستقل يمكن مشاركته على المسوّقين أو العملاء.</li>
      <li>• القيود اختيارية — OR داخل الحقل، AND بين الحقول.</li>
      <li>• استخدم الإيقاف المؤقت بدل الحذف للحفاظ على الإحصاءات.</li>
    </ul>
  </details>
</form>
<?php endif; ?>

<?php if (!$showForm): ?>
<section class="bg-white border border-border-subtle rounded-xl p-3 mb-4">
  <form method="get" class="grid grid-cols-2 md:grid-cols-5 gap-2 items-end">
    <label class="text-xs md:col-span-2">
      <span class="text-text-muted block mb-0.5">بحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="اسم الرابط أو التوكن">
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">الحالة</span>
      <select name="active" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
        <option value="">الكل</option>
        <option value="1" <?= ($filters['active'] ?? '') === '1' ? 'selected' : '' ?>>نشط</option>
        <option value="0" <?= ($filters['active'] ?? '') === '0' ? 'selected' : '' ?>>متوقف</option>
      </select>
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">عدد النتائج</span>
      <select name="limit" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
        <option value="50" <?= ((int) ($filters['limit'] ?? 100)) === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= ((int) ($filters['limit'] ?? 100)) === 100 ? 'selected' : '' ?>>100</option>
        <option value="200" <?= ((int) ($filters['limit'] ?? 100)) === 200 ? 'selected' : '' ?>>200</option>
      </select>
    </label>
    <button class="h-9 rounded-lg bg-primary text-white text-xs font-extrabold px-4 hover:brightness-110">تطبيق</button>
  </form>
</section>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($links === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد روابط مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[960px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">الرابط</th>
            <th class="px-4 py-3 text-right font-bold">السياسة</th>
            <th class="px-4 py-3 text-right font-bold">فلاتر</th>
            <th class="px-4 py-3 text-right font-bold">انتهاء</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-right font-bold">المشاركة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($links as $row): ?>
            <?php $rowShareUrl = $shareUrlFor((string) ($row['public_token'] ?? '')); ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($row['name_ar'] ?? '')) ?></div>
                <div class="text-xs font-mono text-text-muted mt-0.5"><?= h((string) ($row['public_token'] ?? '')) ?></div>
                <?php if (!empty($row['require_password'])): ?>
                  <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold">محمي</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs"><?= h((string) ($row['access_policy_name_ar'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <div class="font-bold text-slate-800"><?= (int) ($row['filters_count'] ?? 0) ?></div>
                <?php if (trim((string) ($row['keyword'] ?? '')) !== ''): ?>
                  <div class="text-[11px] text-text-muted"><?= h((string) $row['keyword']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-text-muted"><?= h((string) ($row['expires_at'] ?? '—')) ?></td>
              <td class="px-4 py-3">
                <?= !empty($row['is_active']) ? '<span class="text-emerald-700 font-bold text-xs">نشط</span>' : '<span class="text-xs text-slate-500">متوقف</span>' ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-1">
                  <a href="/share.php?token=<?= urlencode((string) ($row['public_token'] ?? '')) ?>" target="_blank" class="h-7 px-2 inline-flex items-center rounded border border-border-subtle text-[11px] font-bold text-primary hover:bg-slate-50">فتح</a>
                  <button type="button" data-copy-url="<?= h($rowShareUrl) ?>" class="h-7 px-2 rounded border border-border-subtle bg-white text-[11px] font-bold text-slate-700 hover:bg-slate-50">نسخ</button>
                </div>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5 flex-wrap">
                  <a href="/dashboard/share-links.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                    <?php if (!empty($row['is_active'])): ?>
                      <button class="h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                    <?php else: ?>
                      <button class="h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" onsubmit="return confirm('حذف الرابط؟')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <button class="h-8 px-3 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
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

<?php if ($showForm): ?>
<?php portal_render_token_picker_script(); ?>
<script>
(() => {
  const mainForm = document.getElementById('share-link-form');
  const saveBtn = document.getElementById('share-link-save-btn');
  let explicitSave = false;

  saveBtn?.addEventListener('click', () => {
    explicitSave = true;
  });

  mainForm?.addEventListener('submit', (event) => {
    if (!explicitSave) {
      event.preventDefault();
      return false;
    }
    explicitSave = false;
  });

  mainForm?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    const target = event.target;
    if (!target || target.tagName === 'TEXTAREA') return;
    if (target.id === 'share-link-save-btn') return;
    event.preventDefault();
  }, true);
})();
</script>
<?php endif; ?>

<script>
(() => {
  const copyText = async (text) => {
    if (!text) return false;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) {}
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (_) {}
    textarea.remove();
    return ok;
  };

  document.querySelectorAll('[data-copy-url]').forEach((button) => {
    button.addEventListener('click', async () => {
      const url = button.getAttribute('data-copy-url') || '';
      const ok = await copyText(url);
      const original = button.textContent;
      button.textContent = ok ? 'تم النسخ' : 'فشل';
      setTimeout(() => {
        button.textContent = original;
      }, 1400);
    });
  });
})();
</script>
