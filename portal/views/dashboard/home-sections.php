<?php

declare(strict_types=1);

/** @var array{total: int, active: int, manual: int, filter: int} $stats */
/** @var list<array<string, mixed>> $sections */
/** @var array<string, mixed> $editSection */
/** @var string $editId */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */

require __DIR__ . '/partials/token-picker.php';

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
  <div class="flex flex-wrap gap-3">
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

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<?php if ($editId !== ''): ?>
  <form method="post" class="mb-3 flex justify-end" onsubmit="return confirm('هل أنت متأكد من حذف هذا القسم؟')">
    <input type="hidden" name="action" value="delete_section">
    <input type="hidden" name="id" value="<?= h($editId) ?>">
    <button type="submit" class="h-9 px-4 rounded-lg text-xs font-bold bg-red-600 text-white">حذف القسم</button>
  </form>
<?php endif; ?>

<form method="post" id="home-section-form" class="space-y-6 mb-6">
  <input type="hidden" name="action" value="save_section">
  <input type="hidden" name="id" value="<?= h((string) ($editSection['id'] ?? '')) ?>">

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h2 class="font-bold text-lg mb-4"><?= $editId !== '' ? 'تعديل القسم' : 'قسم جديد' ?></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">عنوان القسم *</span>
        <input name="title_ar" required value="<?= h((string) ($editSection['title_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="وصلنا حديثاً">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">Slug</span>
        <input name="slug" value="<?= h((string) ($editSection['slug'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">ترتيب العرض</span>
        <input type="number" min="0" name="sort_order" value="<?= h((string) ($editSection['sort_order'] ?? '0')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
      </label>
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">وصف مختصر</span>
        <input name="subtitle_ar" value="<?= h((string) ($editSection['subtitle_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
      </label>
      <label class="text-sm md:col-span-2">
        <span class="text-text-muted block mb-1">رابط صورة البانر (اختياري)</span>
        <input name="banner_image_url" value="<?= h((string) ($editSection['banner_image_url'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">طريقة اختيار المواد</span>
        <select name="display_mode" id="display_mode" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          <option value="filter" <?= $displayMode === 'filter' ? 'selected' : '' ?>>فلترة API (عشوائي عند كل زيارة)</option>
          <option value="manual" <?= $displayMode === 'manual' ? 'selected' : '' ?>>مواد محددة يدوياً (عشوائي من القائمة)</option>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">عدد المواد في الشريط</span>
        <input type="number" min="1" max="48" name="max_products" value="<?= h((string) ($editSection['max_products'] ?? '12')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
      </label>
      <label class="text-sm md:col-span-2 inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" <?= !empty($editSection['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>نشط على الصفحة الرئيسية</span>
      </label>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <h3 class="font-bold text-lg mb-3">خيارات العرض على الرئيسية</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <label class="text-sm inline-flex items-center gap-2">
        <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>إظهار الصور</span>
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
    </div>
  </article>

  <article id="filter-mode-panel" class="bg-white border border-border-subtle rounded-2xl p-5 <?= $displayMode === 'manual' ? 'hidden' : '' ?>">
    <h3 class="font-bold text-lg mb-2">فلاتر القسم (مثل رابط المشاركة)</h3>
    <p class="text-sm text-text-muted mb-4">ابحث ضمن القوائم ثم اضغط «إضافة» أو انقر مرتين على الخيار. يُجلب مجموعة من المواد ثم يُعرض عدد عشوائي في الشريط.</p>

    <?php if ($materialFilterOptionsError): ?>
      <p class="mb-3 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-3 py-2 text-xs"><?= h($materialFilterOptionsError) ?></p>
    <?php endif; ?>

    <label class="text-sm block mb-4">
      <span class="text-text-muted block mb-1">كلمة بحث في المواد</span>
      <input name="filter_keyword" value="<?= h((string) ($rules['keyword'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4" placeholder="مثال: صيف">
    </label>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'filter_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'hs-material-types'); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'filter_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'hs-age-categories'); ?></div>
      <div><?php $renderTokenPicker('الشركة', 'filter_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'hs-manufacturers'); ?></div>
      <div><?php $renderTokenPicker('القياس', 'filter_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'hs-size-ranges'); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'filter_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'hs-country-origins'); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'filter_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'hs-store-guids', false); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'filter_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'hs-group-guids', false); ?></div>
    </div>

    <div class="mt-4 rounded-xl border border-border-subtle p-4 bg-surface-low grid grid-cols-1 md:grid-cols-3 gap-3">
      <label class="text-sm">
        <span class="text-text-muted block mb-1">التوفر</span>
        <select name="filter_is_available" class="h-11 w-full rounded-xl border border-border-subtle px-3">
          <option value="" <?= $filterIsAvailable === null ? 'selected' : '' ?>>بدون قيد</option>
          <option value="1" <?= $filterIsAvailable === true ? 'selected' : '' ?>>متوفر فقط</option>
          <option value="0" <?= $filterIsAvailable === false ? 'selected' : '' ?>>غير متوفر</option>
        </select>
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أدنى كمية مخزون</span>
        <input type="number" step="0.01" min="0" name="filter_min_warehouse_quantity" value="<?= h((string) ($rules['min_warehouse_quantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أعلى كمية مخزون</span>
        <input type="number" step="0.01" min="0" name="filter_max_warehouse_quantity" value="<?= h((string) ($rules['max_warehouse_quantity'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أدنى سعر بيع ل.س</span>
        <input type="number" step="0.01" min="0" name="filter_min_unit_sale_price_syp" value="<?= h((string) ($rules['min_unit_sale_price_syp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أعلى سعر بيع ل.س</span>
        <input type="number" step="0.01" min="0" name="filter_max_unit_sale_price_syp" value="<?= h((string) ($rules['max_unit_sale_price_syp'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أدنى سعر بيع $</span>
        <input type="number" step="0.01" min="0" name="filter_min_unit_sale_price_usd" value="<?= h((string) ($rules['min_unit_sale_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أعلى سعر بيع $</span>
        <input type="number" step="0.01" min="0" name="filter_max_unit_sale_price_usd" value="<?= h((string) ($rules['max_unit_sale_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أدنى سعر شراء $</span>
        <input type="number" step="0.01" min="0" name="filter_min_unit_purchase_price_usd" value="<?= h((string) ($rules['min_unit_purchase_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">أعلى سعر شراء $</span>
        <input type="number" step="0.01" min="0" name="filter_max_unit_purchase_price_usd" value="<?= h((string) ($rules['max_unit_purchase_price_usd'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3">
      </label>
    </div>
  </article>

  <article id="manual-mode-panel" class="bg-white border border-border-subtle rounded-2xl p-5 <?= $displayMode === 'filter' ? 'hidden' : '' ?>">
    <h3 class="font-bold text-lg mb-2">اختيار المواد يدوياً</h3>
    <p class="text-sm text-text-muted mb-4">اكتب اسم المادة أو رقمها — تظهر قائمة منسدلة فوراً (بدون إعادة تحميل الصفحة)، ثم اختر المادة لإضافتها.</p>

    <div class="mb-4 flex flex-wrap gap-2 items-start">
      <div class="text-sm flex-1 min-w-[240px] relative" id="hs-material-search-wrap">
        <span class="text-text-muted block mb-1">بحث لإضافة مواد</span>
        <input
          type="search"
          id="hs-material-search"
          autocomplete="off"
          enterkeyhint="search"
          role="combobox"
          aria-expanded="false"
          aria-controls="hs-material-search-results"
          class="h-10 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary"
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

    <?php $renderTokenPicker('المواد المختارة للقسم', 'manual_material_guids[]', $manualPickerOptions, $selectedManualGuids, 'hs-manual-materials', false, true); ?>
  </article>

  <?php if ($editId !== ''): ?>
    <article class="bg-white border border-border-subtle rounded-2xl p-5">
      <h3 class="font-bold mb-3">معاينة عشوائية (مثال لزيارة واحدة)</h3>
      <?php if ($previewProducts === []): ?>
        <p class="text-sm text-text-muted">لا توجد مواد في المعاينة (تحقق من الفلاتر أو المواد اليدوية واتصال API).</p>
      <?php else: ?>
        <div class="flex gap-3 overflow-x-auto pb-2">
          <?php foreach ($previewProducts as $item): ?>
            <?php if (!is_array($item)) continue; ?>
            <div class="shrink-0 w-44 border rounded-lg p-2 bg-surface-low text-xs">
              <div class="font-bold line-clamp-2"><?= h((string) ($item['name'] ?? '-')) ?></div>
              <div class="text-text-muted"><?= h((string) ($item['materialCode'] ?? '')) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p class="text-xs text-text-muted mt-2">تحديث الصفحة الرئيسية يعيد ترتيباً عشوائياً جديداً ضمن نفس الإعدادات.</p>
    </article>
  <?php endif; ?>

  <div class="flex justify-end gap-2">
    <?php if ($editId !== ''): ?>
      <a href="/dashboard/home-sections.php" class="h-11 px-5 inline-flex items-center rounded-xl border border-border-subtle text-sm font-bold">قسم جديد</a>
    <?php endif; ?>
    <button type="submit" id="home-section-save-btn" class="h-11 px-8 rounded-xl bg-primary text-white font-extrabold hover:brightness-110">
      <?= $editId !== '' ? 'حفظ القسم' : 'إنشاء القسم' ?>
    </button>
  </div>
</form>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <?php if ($sections === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد أقسام بعد.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-5 py-4 text-right font-bold">القسم</th>
            <th class="px-5 py-4 text-right font-bold">الوضع</th>
            <th class="px-5 py-4 text-right font-bold">فلاتر</th>
            <th class="px-5 py-4 text-right font-bold">يدوي</th>
            <th class="px-5 py-4 text-right font-bold">الترتيب</th>
            <th class="px-5 py-4 text-right font-bold">الحالة</th>
            <th class="px-5 py-4 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($sections as $section): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-5 py-4">
                <div class="font-bold"><?= h((string) ($section['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted"><?= h((string) ($section['subtitle_ar'] ?? '')) ?></div>
              </td>
              <td class="px-5 py-4"><?= ($section['display_mode'] ?? '') === 'manual' ? 'يدوي' : 'فلترة' ?></td>
              <td class="px-5 py-4"><?= (int) ($section['filters_count'] ?? 0) ?></td>
              <td class="px-5 py-4"><?= (int) ($section['products_count'] ?? 0) ?></td>
              <td class="px-5 py-4"><?= (int) ($section['sort_order'] ?? 0) ?></td>
              <td class="px-5 py-4">
                <?= !empty($section['is_active']) ? '<span class="text-green-700 font-bold text-xs">نشط</span>' : '<span class="text-xs">متوقف</span>' ?>
              </td>
              <td class="px-5 py-4">
                <div class="flex justify-end gap-2 flex-wrap">
                  <a href="/dashboard/home-sections.php?edit=<?= urlencode((string) $section['id']) ?>" class="h-9 px-3 rounded-lg border text-xs font-bold">تعديل</a>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle_section">
                    <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($section['is_active']) ? '0' : '1' ?>">
                    <button class="h-9 px-3 rounded-lg text-xs font-bold bg-primary text-white"><?= !empty($section['is_active']) ? 'إيقاف' : 'تفعيل' ?></button>
                  </form>
                  <form method="post" onsubmit="return confirm('حذف القسم؟')">
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="id" value="<?= h((string) $section['id']) ?>">
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

<?php portal_render_token_picker_script(); ?>

<script>
(() => {
  const mainForm = document.getElementById('home-section-form');
  const saveBtn = document.getElementById('home-section-save-btn');
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
    if (target.id === 'home-section-save-btn') return;
    event.preventDefault();
  }, true);

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
  let searchItems = [];
  let activeResultIndex = -1;

  const hideResults = () => {
    if (!resultsEl) return;
    resultsEl.classList.add('hidden');
    resultsEl.innerHTML = '';
    searchInput?.setAttribute('aria-expanded', 'false');
    activeResultIndex = -1;
    searchItems = [];
  };

  const highlightResult = () => {
    if (!resultsEl) return;
    Array.from(resultsEl.querySelectorAll('[data-result-index]')).forEach((node) => {
      const index = Number(node.getAttribute('data-result-index'));
      node.classList.toggle('bg-primary/10', index === activeResultIndex);
      node.classList.toggle('font-bold', index === activeResultIndex);
    });
  };

  const addMaterialItem = (item) => {
    if (!item || typeof window.portalTokenPickerAddOptions !== 'function') return;
    window.portalTokenPickerAddOptions('hs-manual-materials', [item]);
    if (statusEl) statusEl.textContent = 'تمت إضافة: ' + (item.label || item.value);
    if (searchInput) searchInput.value = '';
    hideResults();
  };

  const renderResults = (items) => {
    if (!resultsEl) return;
    searchItems = items;
    resultsEl.innerHTML = '';
    if (items.length === 0) {
      hideResults();
      if (statusEl) statusEl.textContent = 'لا توجد نتائج.';
      return;
    }
    items.forEach((item, index) => {
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
    });
    resultsEl.classList.remove('hidden');
    searchInput?.setAttribute('aria-expanded', 'true');
    activeResultIndex = 0;
    highlightResult();
    if (statusEl) statusEl.textContent = items.length + ' نتيجة — اختر من القائمة (↑↓ ثم Enter)';
  };

  let searchTimer = null;
  let searchRequestId = 0;

  const runMaterialSearch = async () => {
    const q = (searchInput?.value || '').trim();
    if (q === '') {
      hideResults();
      if (statusEl) statusEl.textContent = '';
      return;
    }
    const requestId = ++searchRequestId;
    if (statusEl) statusEl.textContent = 'جاري البحث...';
    try {
      const response = await fetch('/dashboard/home-sections-api.php?q=' + encodeURIComponent(q), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (requestId !== searchRequestId) return;
      const data = await response.json();
      if (!data.ok) {
        hideResults();
        if (statusEl) statusEl.textContent = data.message || 'تعذر البحث.';
        return;
      }
      renderResults(Array.isArray(data.items) ? data.items : []);
    } catch (_) {
      if (requestId !== searchRequestId) return;
      hideResults();
      if (statusEl) statusEl.textContent = 'تعذر الاتصال بالخادم.';
    }
  };

  const scheduleMaterialSearch = (delayMs = 280) => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      searchTimer = null;
      runMaterialSearch();
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
      runMaterialSearch();
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
