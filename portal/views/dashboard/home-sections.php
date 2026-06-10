<?php

declare(strict_types=1);

/** @var array{total: int, active: int, manual: int, filter: int} $stats */
/** @var list<array<string, mixed>> $sections */
/** @var array<string, mixed> $editSection */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $isNew */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */

require __DIR__ . '/partials/token-picker.php';
require __DIR__ . '/partials/media-picker.php';

$showForm = $showForm ?? false;
$isNew = $isNew ?? false;

$rules = is_array($editSection['filter_rules'] ?? null) ? $editSection['filter_rules'] : [];
$displayOptions = is_array($editSection['display_options'] ?? null)
    ? $editSection['display_options']
    : ['show_images' => true, 'price_mode' => 'both'];
$displayMode = (string) ($editSection['display_mode'] ?? 'filter');
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');

$selectedMaterialTypes = array_map('strval', $rules['material_types'] ?? []);
$selectedAgeCategories = array_map('strval', $rules['age_categories'] ?? []);
$selectedManufacturers = array_map('strval', $rules['manufacturers'] ?? []);
$selectedSizeRanges = array_map('strval', $rules['size_ranges'] ?? []);
$selectedCountryOrigins = array_map('strval', $rules['country_origins'] ?? []);
$selectedStoreGuids = array_map('strval', $rules['store_guids'] ?? []);
$selectedGroupGuids = array_map('strval', $rules['group_guids'] ?? []);
$filterIsAvailable = array_key_exists('is_available', $rules) ? $rules['is_available'] : null;
$filterHasImage = array_key_exists('has_image', $rules) ? $rules['has_image'] : null;

$toOptionObjects = static function (array $values): array {
    $result = [];
    foreach ($values as $value) {
        $item = trim((string) $value);
        if ($item !== '') {
            $result[] = ['value' => $item, 'label' => $item];
        }
    }
    return array_values(array_unique($result, SORT_REGULAR));
};

$materialTypeOptions = array_values(array_unique(array_merge($materialFilterOptions['materialTypes'] ?? [], $selectedMaterialTypes)));
$ageCategoryOptions = array_values(array_unique(array_merge($materialFilterOptions['ageCategories'] ?? [], $selectedAgeCategories)));
$manufacturerOptions = array_values(array_unique(array_merge($materialFilterOptions['manufacturers'] ?? [], $selectedManufacturers)));
$sizeRangeOptions = array_values(array_unique(array_merge($materialFilterOptions['sizeRanges'] ?? [], $selectedSizeRanges)));
$countryOriginOptions = array_values(array_unique(array_merge($materialFilterOptions['countryOfOrigins'] ?? [], $selectedCountryOrigins)));

$storeOptionObjects = [];
foreach ($materialFilterOptions['stores'] ?? [] as $store) {
    if (!is_array($store)) {
        continue;
    }
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $label = trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid;
    $storeOptionObjects[] = ['value' => $guid, 'label' => $label];
}
foreach ($selectedStoreGuids as $guid) {
    if (!in_array($guid, array_column($storeOptionObjects, 'value'), true)) {
        $storeOptionObjects[] = ['value' => $guid, 'label' => $guid];
    }
}

$groupOptionObjects = [];
foreach ($materialFilterOptions['groups'] ?? [] as $group) {
    if (!is_array($group)) {
        continue;
    }
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $label = trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid;
    $groupOptionObjects[] = ['value' => $guid, 'label' => $label];
}
foreach ($selectedGroupGuids as $guid) {
    if (!in_array($guid, array_column($groupOptionObjects, 'value'), true)) {
        $groupOptionObjects[] = ['value' => $guid, 'label' => $guid];
    }
}

$manualPickerOptions = [];
foreach ($editSection['manual_products'] ?? [] as $product) {
    if (!is_array($product)) {
        continue;
    }
    $guid = trim((string) ($product['guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $name = trim((string) ($product['name'] ?? ''));
    $code = trim((string) ($product['code'] ?? ''));
    $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $guid;
    $manualPickerOptions[] = ['value' => $guid, 'label' => $label];
}

$selectedManualGuids = array_map('strval', $editSection['material_guids'] ?? []);
$priceRanges = is_array($materialFilterOptions['priceRanges'] ?? null) ? $materialFilterOptions['priceRanges'] : [];
$previewProducts = is_array($editSection['preview_products'] ?? null) ? $editSection['preview_products'] : [];
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">أقسام الصفحة الرئيسية</h1>
    <p class="text-sm text-text-muted mt-1">
      أنشئ أقساماً مثل «وصلنا حديثاً» أو «رجالي» — اختر مواداً يدوياً أو فلاتر API.
      في وضع الفلترة تتغيّر المواد المعروضة <strong>عشوائياً عند كل تحديث</strong> للصفحة الرئيسية.
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-3">
    <?php if (!$showForm): ?>
      <a href="/dashboard/home-sections.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">قسم جديد</a>
      <a href="/dashboard/site-media.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">مكتبة الصور</a>
    <?php endif; ?>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold"><?= (int) $stats['total'] ?></p>
      <p class="text-xs text-text-muted">إجمالي</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-24 text-center">
      <p class="text-xl font-extrabold text-green-700"><?= (int) $stats['active'] ?></p>
      <p class="text-xs text-text-muted">نشط</p>
    </article>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if ($showForm): ?>
<?php if ($editId !== ''): ?>
  <form method="post" id="hs-delete-form" class="hidden" data-dashboard-confirm="هل أنت متأكد من حذف هذا القسم؟">
    <input type="hidden" name="action" value="delete_section">
    <input type="hidden" name="id" value="<?= h($editId) ?>">
  </form>
<?php endif; ?>

<form method="post" id="home-section-form" data-dashboard-explicit-save class="space-y-3 mb-4">
  <input type="hidden" name="action" value="save_section">
  <input type="hidden" name="id" value="<?= h((string) ($editSection['id'] ?? '')) ?>">

  <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل القسم' : 'قسم جديد' ?></h2>
    <div class="flex flex-wrap items-center gap-2">
      <a href="/dashboard/home-sections.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"><?= $editId !== '' ? 'إلغاء التعديل' : 'إلغاء' ?></a>
      <?php if ($editId !== ''): ?>
        <button type="submit" form="hs-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
      <?php endif; ?>
      <button type="submit" id="home-section-save-btn" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">
        <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء القسم' ?>
      </button>
    </div>
  </div>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">عنوان القسم *</span>
        <input name="title_ar" required value="<?= h((string) ($editSection['title_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary" placeholder="وصلنا حديثاً">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">Slug</span>
        <input name="slug" value="<?= h((string) ($editSection['slug'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">ترتيب العرض</span>
        <input type="number" min="0" name="sort_order" value="<?= h((string) ($editSection['sort_order'] ?? '0')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">عدد المواد</span>
        <input type="number" min="1" max="48" name="max_products" value="<?= h((string) ($editSection['max_products'] ?? '12')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs md:col-span-2">
        <span class="text-text-muted block mb-0.5">وصف مختصر</span>
        <input name="subtitle_ar" value="<?= h((string) ($editSection['subtitle_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <div class="md:col-span-3">
        <?php $renderMediaPickerField('صورة البانر', 'banner_image_url', (string) ($editSection['banner_image_url'] ?? ''), 'hs-banner-image', 'banner'); ?>
      </div>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">طريقة اختيار المواد</span>
        <select name="display_mode" id="display_mode" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
          <option value="filter" <?= $displayMode === 'filter' ? 'selected' : '' ?>>فلترة API</option>
          <option value="manual" <?= $displayMode === 'manual' ? 'selected' : '' ?>>مواد يدوية</option>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">وضع السعر (للعميل)</span>
        <select name="option_price_mode" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
          <option value="both" <?= $priceMode === 'both' ? 'selected' : '' ?>>سوري + دولار</option>
          <option value="syp" <?= $priceMode === 'syp' ? 'selected' : '' ?>>سوري فقط</option>
          <option value="usd" <?= $priceMode === 'usd' ? 'selected' : '' ?>>دولار فقط</option>
          <option value="none" <?= $priceMode === 'none' ? 'selected' : '' ?>>بدون سعر</option>
        </select>
      </label>
      <div class="text-xs flex flex-wrap items-center gap-4 pt-5">
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="is_active" <?= !empty($editSection['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>نشط</span>
        </label>
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>إظهار الصور للعميل</span>
        </label>
      </div>
    </div>
  </article>

  <article id="filter-mode-panel" class="bg-white border border-border-subtle rounded-xl p-3 <?= $displayMode === 'manual' ? 'hidden' : '' ?>">
    <details open class="group">
      <summary class="font-bold text-sm cursor-pointer list-none flex items-center justify-between gap-2">
        <span>فلاتر المواد</span>
        <span class="text-xs text-text-muted font-normal">عشوائي عند كل زيارة</span>
      </summary>
      <div class="mt-2 space-y-2">
        <?php if ($materialFilterOptionsError): ?>
          <p class="rounded-lg border border-amber-200 bg-amber-50 text-amber-700 px-2 py-1.5 text-xs"><?= h($materialFilterOptionsError) ?></p>
        <?php endif; ?>

        <label class="text-xs block">
          <span class="text-text-muted block mb-0.5">كلمة بحث</span>
          <input name="filter_keyword" value="<?= h((string) ($rules['keyword'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: صيف">
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'filter_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'hs-material-types', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'filter_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'hs-age-categories', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('الشركة', 'filter_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'hs-manufacturers', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('القياس', 'filter_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'hs-size-ranges', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'filter_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'hs-country-origins', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'filter_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'hs-store-guids', false, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'filter_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'hs-group-guids', false, false, false, 4); ?></div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 pt-1">
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">التوفر</span>
            <select name="filter_is_available" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
              <option value="" <?= $filterIsAvailable === null ? 'selected' : '' ?>>بدون قيد</option>
              <option value="1" <?= $filterIsAvailable === true ? 'selected' : '' ?>>متوفر</option>
              <option value="0" <?= $filterIsAvailable === false ? 'selected' : '' ?>>غير متوفر</option>
            </select>
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">الصورة</span>
            <select name="filter_has_image" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
              <option value="" <?= $filterHasImage === null ? 'selected' : '' ?>>بدون قيد</option>
              <option value="1" <?= $filterHasImage === true ? 'selected' : '' ?>>مع صورة</option>
              <option value="0" <?= $filterHasImage === false ? 'selected' : '' ?>>بدون صورة</option>
            </select>
          </label>
        </div>

        <details class="rounded-lg border border-border-subtle bg-surface-low">
          <summary class="px-3 py-2 text-xs font-bold cursor-pointer">مخزون وأسعار (متقدم)</summary>
          <div class="px-3 pb-3 grid grid-cols-2 md:grid-cols-4 gap-2">
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى مخزون</span>
              <input type="number" step="0.01" min="0" name="filter_min_warehouse_quantity" value="<?= h((string) ($rules['min_warehouse_quantity'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى مخزون</span>
              <input type="number" step="0.01" min="0" name="filter_max_warehouse_quantity" value="<?= h((string) ($rules['max_warehouse_quantity'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى بيع ل.س</span>
              <input type="number" step="0.01" min="0" name="filter_min_unit_sale_price_syp" value="<?= h((string) ($rules['min_unit_sale_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى بيع ل.س</span>
              <input type="number" step="0.01" min="0" name="filter_max_unit_sale_price_syp" value="<?= h((string) ($rules['max_unit_sale_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى بيع $</span>
              <input type="number" step="0.01" min="0" name="filter_min_unit_sale_price_usd" value="<?= h((string) ($rules['min_unit_sale_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى بيع $</span>
              <input type="number" step="0.01" min="0" name="filter_max_unit_sale_price_usd" value="<?= h((string) ($rules['max_unit_sale_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أدنى شراء $</span>
              <input type="number" step="0.01" min="0" name="filter_min_unit_purchase_price_usd" value="<?= h((string) ($rules['min_unit_purchase_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
            <label class="text-xs">
              <span class="text-text-muted block mb-0.5">أعلى شراء $</span>
              <input type="number" step="0.01" min="0" name="filter_max_unit_purchase_price_usd" value="<?= h((string) ($rules['max_unit_purchase_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
            </label>
          </div>
        </details>
      </div>
    </details>
  </article>

  <article id="manual-mode-panel" class="bg-white border border-border-subtle rounded-xl p-3 <?= $displayMode === 'filter' ? 'hidden' : '' ?>">
    <h3 class="font-bold text-sm mb-2">اختيار المواد يدوياً</h3>
    <p class="text-xs text-text-muted mb-2">ابحث بالاسم أو الكود — 24 نتيجة لكل دفعة.</p>

    <div class="mb-2 flex flex-wrap gap-2 items-start">
      <div class="text-xs flex-1 min-w-[200px] relative" id="hs-material-search-wrap">
        <span class="text-text-muted block mb-0.5">بحث لإضافة مواد</span>
        <input
          type="search"
          id="hs-material-search"
          autocomplete="off"
          enterkeyhint="search"
          role="combobox"
          aria-expanded="false"
          aria-controls="hs-material-search-results"
          class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary"
          placeholder="اسم أو كود المادة"
        >
        <ul
          id="hs-material-search-results"
          class="hidden absolute z-30 mt-1 w-full max-h-60 overflow-y-auto rounded-xl border border-border-subtle bg-white shadow-lg text-sm divide-y divide-border-subtle"
          role="listbox"
        ></ul>
      </div>
      <p id="hs-material-search-status" class="text-xs text-text-muted min-h-[1.25rem] pt-8 flex-1"></p>
    </div>

    <?php $renderTokenPicker('المواد المختارة للقسم', 'manual_material_guids[]', $manualPickerOptions, $selectedManualGuids, 'hs-manual-materials', false, true, true); ?>
  </article>

  <?php if ($editId !== ''): ?>
    <details class="bg-white border border-border-subtle rounded-xl p-3">
      <summary class="font-bold text-sm cursor-pointer">معاينة عشوائية (<?= count($previewProducts) ?>)</summary>
      <div class="mt-2">
        <?php if ($previewProducts === []): ?>
          <p class="text-xs text-text-muted">لا توجد مواد — تحقق من الفلاتر أو اتصال API.</p>
        <?php else: ?>
          <div class="flex gap-2 overflow-x-auto pb-1">
            <?php foreach ($previewProducts as $item): ?>
              <?php if (!is_array($item)) continue; ?>
              <div class="shrink-0 w-36 border rounded-lg p-1.5 bg-surface-low text-xs">
                <div class="font-bold line-clamp-2"><?= h((string) ($item['name'] ?? '-')) ?></div>
                <div class="text-text-muted"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
  <?php endif; ?>
</form>
<?php portal_render_media_picker_modal(); ?>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($sections === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد أقسام بعد.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">القسم</th>
            <th class="px-4 py-3 text-right font-bold">الوضع</th>
            <th class="px-4 py-3 text-right font-bold">فلاتر</th>
            <th class="px-4 py-3 text-right font-bold">يدوي</th>
            <th class="px-4 py-3 text-right font-bold">الترتيب</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($sections as $section): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($section['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted"><?= h((string) ($section['subtitle_ar'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3"><?= ($section['display_mode'] ?? '') === 'manual' ? 'يدوي' : 'فلترة' ?></td>
              <td class="px-4 py-3"><?= (int) ($section['filters_count'] ?? 0) ?></td>
              <td class="px-4 py-3"><?= (int) ($section['products_count'] ?? 0) ?></td>
              <td class="px-4 py-3"><?= (int) ($section['sort_order'] ?? 0) ?></td>
              <td class="px-4 py-3">
                <?= !empty($section['is_active']) ? '<span class="text-emerald-700 font-bold text-xs">نشط</span>' : '<span class="text-xs text-slate-500">متوقف</span>' ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5 flex-wrap">
                  <a href="/dashboard/home-sections.php?edit=<?= urlencode((string) $section['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                  <form method="post" data-dashboard-ajax data-dashboard-reload>
                    <input type="hidden" name="action" value="toggle_section">
                    <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($section['is_active']) ? '0' : '1' ?>">
                    <?php if (!empty($section['is_active'])): ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                    <?php else: ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" data-dashboard-confirm="حذف القسم؟">
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
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
<?php portal_render_media_picker_script(); ?>
<script>
(() => {
  const modeSelect = document.getElementById('display_mode');
  const filterPanel = document.getElementById('filter-mode-panel');
  const manualPanel = document.getElementById('manual-mode-panel');
  const syncPanels = () => {
    const isManual = modeSelect?.value === 'manual';
    filterPanel?.classList.toggle('hidden', isManual);
    manualPanel?.classList.toggle('hidden', !isManual);
  };
  modeSelect?.addEventListener('change', syncPanels);
  syncPanels();

  const searchInput = document.getElementById('hs-material-search');
  const resultsEl = document.getElementById('hs-material-search-results');
  const searchWrap = document.getElementById('hs-material-search-wrap');
  const statusEl = document.getElementById('hs-material-search-status');
  const MANUAL_PICKER_ID = 'hs-manual-materials';
  const PAGE_SIZE = 24;

  let searchItems = [];
  let activeResultIndex = -1;
  let searchQuery = '';
  let searchPage = 1;
  let searchTotal = 0;
  let searchHasMore = false;
  let searchLoading = false;
  let searchTimer = null;
  let searchRequestId = 0;

  const getSelectedMaterialIds = () => {
    if (typeof window.portalTokenPickerGetSelected === 'function') {
      return new Set(window.portalTokenPickerGetSelected(MANUAL_PICKER_ID));
    }
    return new Set();
  };

  const hideResults = () => {
    if (!resultsEl) return;
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
    searchInput?.setAttribute('aria-expanded', 'false');
    activeResultIndex = -1;
    searchItems = [];
    searchQuery = '';
    searchPage = 1;
    searchTotal = 0;
    searchHasMore = false;
    searchLoading = false;
  };

  const updateStatus = () => {
    if (!statusEl) return;
    if (searchItems.length === 0) {
      statusEl.textContent = 'لا توجد نتائج.';
      return;
    }
    const shown = searchItems.length;
    const totalText = searchTotal > 0 ? ' من ' + searchTotal : '';
    statusEl.textContent = shown + totalText + ' — اختر من القائمة (↑↓ Enter) — مرّر للأسفل للمزيد';
  };

  const highlightResult = () => {
    if (!resultsEl) return;
    const activeNode = resultsEl.querySelector('[data-result-index="' + activeResultIndex + '"]');
    Array.from(resultsEl.querySelectorAll('[data-result-index]')).forEach((node) => {
      const index = Number(node.getAttribute('data-result-index'));
      node.classList.toggle('bg-primary/10', index === activeResultIndex);
      node.classList.toggle('font-bold', index === activeResultIndex);
    });
    activeNode?.scrollIntoView({ block: 'nearest' });
  };

  const appendResultRow = (item, index) => {
    if (!resultsEl || !item) return;
    const li = document.createElement('li');
    li.setAttribute('role', 'option');
    li.setAttribute('data-result-index', String(index));
    li.className = 'px-3 py-2.5 cursor-pointer hover:bg-surface-low text-right';
    li.textContent = item.label || item.value || '';
    li.addEventListener('mousedown', (event) => {
      event.preventDefault();
      addMaterialItem(item);
    });
    resultsEl.appendChild(li);
  };

  const addMaterialItem = (item) => {
    if (!item || typeof window.portalTokenPickerAdd !== 'function') return;
    const added = window.portalTokenPickerAdd(MANUAL_PICKER_ID, [item]);
    if (added > 0 && statusEl) {
      statusEl.textContent = 'تمت إضافة: ' + (item.label || item.value);
    }
    if (searchInput) searchInput.value = '';
    hideResults();
  };

  const renderResultList = (items, append) => {
    if (!resultsEl) return;
    if (!append) {
      resultsEl.innerHTML = '';
      searchItems = [];
    }
    const selected = getSelectedMaterialIds();
    items.forEach((item) => {
      if (!item || !item.value || selected.has(item.value)) return;
      if (searchItems.some((row) => row.value === item.value)) return;
      searchItems.push(item);
      appendResultRow(item, searchItems.length - 1);
    });

    if (searchItems.length === 0) {
      hideResults();
      if (statusEl) statusEl.textContent = 'لا توجد نتائج جديدة.';
      return;
    }

    resultsEl.classList.remove('hidden');
    searchInput?.setAttribute('aria-expanded', 'true');
    if (activeResultIndex < 0) activeResultIndex = 0;
    highlightResult();
    updateStatus();

    const sentinel = resultsEl.querySelector('[data-role="load-sentinel"]');
    if (sentinel) sentinel.remove();
    if (searchHasMore) {
      const loadingRow = document.createElement('li');
      loadingRow.setAttribute('data-role', 'load-sentinel');
      loadingRow.className = 'px-3 py-2 text-center text-xs text-text-muted';
      loadingRow.textContent = searchLoading ? 'جاري تحميل المزيد...' : 'مرّر للأسفل للمزيد';
      resultsEl.appendChild(loadingRow);
    }
  };

  const fetchMaterialPage = async (page, append) => {
    const q = (searchInput?.value || '').trim();
    if (q === '') {
      hideResults();
      if (statusEl) statusEl.textContent = '';
      return;
    }
    if (searchLoading) return;

    const requestId = ++searchRequestId;
    searchLoading = true;
    if (!append && statusEl) statusEl.textContent = 'جاري البحث...';

    try {
      const url = '/dashboard/home-sections-api.php?q=' + encodeURIComponent(q)
        + '&page=' + encodeURIComponent(String(page))
        + '&pageSize=' + encodeURIComponent(String(PAGE_SIZE));
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (requestId !== searchRequestId) return;
      const data = await response.json();
      if (!data.ok) {
        if (!append) hideResults();
        if (statusEl) statusEl.textContent = data.message || 'تعذر البحث.';
        return;
      }

      searchQuery = q;
      searchPage = Number(data.page) || page;
      searchTotal = Number(data.total) || 0;
      searchHasMore = !!data.hasMore;
      const items = Array.isArray(data.items) ? data.items : [];
      renderResultList(items, append);
    } catch (_) {
      if (requestId !== searchRequestId) return;
      if (!append) hideResults();
      if (statusEl) statusEl.textContent = 'تعذر الاتصال بالخادم.';
    } finally {
      if (requestId === searchRequestId) {
        searchLoading = false;
      }
    }
  };

  const runMaterialSearch = (append = false) => {
    if (append) {
      if (!searchHasMore || searchLoading) return;
      fetchMaterialPage(searchPage + 1, true);
      return;
    }
    searchPage = 1;
    searchHasMore = false;
    searchTotal = 0;
    fetchMaterialPage(1, false);
  };

  const scheduleMaterialSearch = (delayMs = 280) => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      searchTimer = null;
      runMaterialSearch(false);
    }, delayMs);
  };

  searchInput?.addEventListener('input', () => {
    const q = (searchInput?.value || '').trim();
    if (q === '') {
      if (searchTimer) clearTimeout(searchTimer);
      hideResults();
      if (statusEl) statusEl.textContent = '';
      return;
    }
    scheduleMaterialSearch();
  });

  resultsEl?.addEventListener('scroll', () => {
    if (!searchHasMore || searchLoading) return;
    const nearBottom = resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 48;
    if (nearBottom) {
      runMaterialSearch(true);
    }
  });

  searchInput?.addEventListener('keydown', (event) => {
    const hasList = resultsEl && !resultsEl.classList.contains('hidden') && searchItems.length > 0;
    if (event.key === 'ArrowDown' && hasList) {
      event.preventDefault();
      activeResultIndex = Math.min(activeResultIndex + 1, searchItems.length - 1);
      highlightResult();
      return;
    }
    if (event.key === 'ArrowUp' && hasList) {
      event.preventDefault();
      activeResultIndex = Math.max(activeResultIndex - 1, 0);
      highlightResult();
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      event.stopPropagation();
      if (searchTimer) clearTimeout(searchTimer);
      if (hasList && activeResultIndex >= 0 && searchItems[activeResultIndex]) {
        addMaterialItem(searchItems[activeResultIndex]);
        return;
      }
      runMaterialSearch(false);
      return;
    }
    if (event.key === 'Escape') {
      hideResults();
    }
  });

  document.addEventListener('click', (event) => {
    if (!searchWrap || searchWrap.contains(event.target)) return;
    hideResults();
  });
})();
</script>
<?php endif; ?>
