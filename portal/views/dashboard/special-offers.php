<?php

declare(strict_types=1);

/** @var array{total: int, active: int, manual: int, filter: int, on_home: int} $stats */
/** @var list<array<string, mixed>> $offers */
/** @var array<string, mixed> $editOffer */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $isNew */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var string|null $dbError */

require __DIR__ . '/partials/token-picker.php';
require __DIR__ . '/partials/media-picker.php';

$showForm = $showForm ?? false;
$isNew = $isNew ?? false;
$editOffer = is_array($editOffer ?? null) ? $editOffer : [
    'id' => '',
    'filter_rules' => [],
    'display_options' => ['show_images' => true, 'price_mode' => 'both'],
    'selection_mode' => 'filter',
    'discount_type' => 'percent',
    'material_guids' => [],
    'manual_products' => [],
];

$rules = is_array($editOffer['filter_rules'] ?? null) ? $editOffer['filter_rules'] : [];
$displayOptions = is_array($editOffer['display_options'] ?? null)
    ? $editOffer['display_options']
    : ['show_images' => true, 'price_mode' => 'both'];
$selectionMode = (string) ($editOffer['selection_mode'] ?? 'filter');
$discountType = (string) ($editOffer['discount_type'] ?? 'percent');
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$selectedMaterialGuids = array_map('strval', $editOffer['material_guids'] ?? []);

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
    if (!is_array($store)) continue;
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') continue;
    $storeOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid];
}
foreach ($selectedStoreGuids as $guid) {
    if (!in_array($guid, array_column($storeOptionObjects, 'value'), true)) {
        $storeOptionObjects[] = ['value' => $guid, 'label' => $guid];
    }
}

$groupOptionObjects = [];
foreach ($materialFilterOptions['groups'] ?? [] as $group) {
    if (!is_array($group)) continue;
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') continue;
    $groupOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid];
}
foreach ($selectedGroupGuids as $guid) {
    if (!in_array($guid, array_column($groupOptionObjects, 'value'), true)) {
        $groupOptionObjects[] = ['value' => $guid, 'label' => $guid];
    }
}

$manualPickerOptions = [];
foreach ($editOffer['manual_products'] ?? [] as $product) {
    if (!is_array($product)) continue;
    $guid = trim((string) ($product['guid'] ?? ''));
    if ($guid === '') continue;
    $name = trim((string) ($product['name'] ?? ''));
    $code = trim((string) ($product['code'] ?? ''));
    $manualPickerOptions[] = ['value' => $guid, 'label' => $name . ($code !== '' ? " ($code)" : '')];
}

$previewProducts = is_array($editOffer['preview_products'] ?? null) ? $editOffer['preview_products'] : [];
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">العروض الخاصة</h1>
    <p class="text-sm text-text-muted mt-1">حسومات على مستوى الموقع — نسبة أو سعر جديد — مع فلاتر مواد مثل أقسام الرئيسية.</p>
    <p class="text-xs text-amber-700 mt-1">عند التضارب: يُطبَّق الأفضل للعميل (أقل سعر)، ثم الأعلى أولوية.</p>
  </div>
  <div class="flex flex-wrap items-center gap-3">
    <?php if (!$showForm): ?>
      <a href="/dashboard/special-offers.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">عرض جديد</a>
      <a href="/dashboard/site-media.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">مكتبة الصور</a>
    <?php endif; ?>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-20 text-center">
      <p class="text-xl font-extrabold"><?= (int) ($stats['total'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">إجمالي</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-20 text-center">
      <p class="text-xl font-extrabold text-green-700"><?= (int) ($stats['active'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">نشط</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-4 py-3 min-w-20 text-center">
      <p class="text-xl font-extrabold text-primary"><?= (int) ($stats['on_home'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">في الرئيسية</p>
    </article>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!empty($dbError)): ?>
  <div class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm"><?= h($dbError) ?></div>
<?php endif; ?>

<?php if ($showForm): ?>
<?php if ($editId !== ''): ?>
  <form method="post" id="so-delete-form" class="hidden" data-dashboard-ajax data-dashboard-reload onsubmit="return confirm('هل أنت متأكد من حذف هذا العرض؟');">
    <input type="hidden" name="action" value="delete_offer">
    <input type="hidden" name="id" value="<?= h($editId) ?>">
  </form>
<?php endif; ?>

<form method="post" id="special-offer-form" data-dashboard-explicit-save data-dashboard-ajax class="space-y-3 mb-4">
  <input type="hidden" name="action" value="save_offer">
  <input type="hidden" name="id" value="<?= h((string) ($editOffer['id'] ?? '')) ?>">

  <div class="sticky top-16 z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل العرض' : 'عرض جديد' ?></h2>
    <div class="flex flex-wrap items-center gap-2">
      <a href="/dashboard/special-offers.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"><?= $editId !== '' ? 'إلغاء التعديل' : 'إلغاء' ?></a>
      <?php if ($editId !== ''): ?>
        <button type="submit" form="so-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
      <?php endif; ?>
      <button type="submit" id="special-offer-save-btn" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">
        <?= $editId !== '' ? 'حفظ التعديلات' : 'إنشاء العرض' ?>
      </button>
    </div>
  </div>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <h3 class="font-bold text-sm mb-2">بيانات العرض والحسم</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">عنوان العرض *</span>
        <input name="title_ar" required value="<?= h((string) ($editOffer['title_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="تخفيضات الصيف">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">Slug</span>
        <input name="slug" value="<?= h((string) ($editOffer['slug'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">شارة (اختياري)</span>
        <input name="badge_text_ar" value="<?= h((string) ($editOffer['badge_text_ar'] ?? '')) ?>" placeholder="-15%" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">ترتيب الرئيسية</span>
        <input type="number" name="home_sort_order" value="<?= h((string) ($editOffer['home_sort_order'] ?? '0')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs md:col-span-3">
        <span class="text-text-muted block mb-0.5">وصف مختصر</span>
        <input name="subtitle_ar" value="<?= h((string) ($editOffer['subtitle_ar'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">يبدأ</span>
        <input type="datetime-local" name="starts_at" value="<?= h(substr((string) ($editOffer['starts_at'] ?? ''), 0, 16)) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">ينتهي (فارغ = بلا نهاية)</span>
        <input type="datetime-local" name="ends_at" value="<?= h(substr((string) ($editOffer['ends_at'] ?? ''), 0, 16)) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">عدد المواد في الرئيسية</span>
        <input type="number" min="1" max="48" name="max_products" value="<?= h((string) ($editOffer['max_products'] ?? '12')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">نوع الحسم</span>
        <select name="discount_type" id="discount_type" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>نسبة مئوية</option>
          <option value="fixed_price" <?= $discountType === 'fixed_price' ? 'selected' : '' ?>>سعر طرد جديد</option>
        </select>
      </label>
      <label class="text-xs" id="field-percent">
        <span class="text-text-muted block mb-0.5">النسبة %</span>
        <input type="number" step="0.01" min="0" max="100" name="discount_percent" value="<?= h((string) ($editOffer['discount_percent'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden" id="field-syp">
        <span class="text-text-muted block mb-0.5">سعر الطرد ل.س</span>
        <input type="number" step="0.01" min="0" name="fixed_price_syp" value="<?= h((string) ($editOffer['fixed_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden" id="field-usd">
        <span class="text-text-muted block mb-0.5">سعر الطرد $</span>
        <input type="number" step="0.01" min="0" name="fixed_price_usd" value="<?= h((string) ($editOffer['fixed_price_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">أولوية (أعلى = يفوز عند التعادل)</span>
        <input type="number" name="priority" value="<?= h((string) ($editOffer['priority'] ?? '0')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">حد أدنى طرود/طلب</span>
        <input type="number" step="0.01" min="0" name="min_packages" value="<?= h((string) ($editOffer['min_packages'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">حد أقصى طرود/طلب</span>
        <input type="number" step="0.01" min="0" name="max_packages" value="<?= h((string) ($editOffer['max_packages'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">وضع السعر (للعميل)</span>
        <select name="option_price_mode" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="both" <?= $priceMode === 'both' ? 'selected' : '' ?>>سوري + دولار</option>
          <option value="syp" <?= $priceMode === 'syp' ? 'selected' : '' ?>>سوري فقط</option>
          <option value="usd" <?= $priceMode === 'usd' ? 'selected' : '' ?>>دولار فقط</option>
          <option value="none" <?= $priceMode === 'none' ? 'selected' : '' ?>>بدون سعر</option>
        </select>
      </label>
      <div class="text-xs flex flex-wrap items-center gap-4 pt-5 md:col-span-2">
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="is_active" <?= !empty($editOffer['is_active']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary">
          <span>نشط</span>
        </label>
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="show_on_home" <?= !empty($editOffer['show_on_home']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary">
          <span>عرض كقسم في الرئيسية</span>
        </label>
        <label class="inline-flex items-center gap-1.5">
          <input type="checkbox" name="option_show_images" <?= $showImages ? 'checked' : '' ?> class="rounded border-border-subtle text-primary">
          <span>إظهار الصور للعميل</span>
        </label>
      </div>
      <div class="md:col-span-3">
        <?php $renderMediaPickerField('بانر (اختياري)', 'banner_image_url', (string) ($editOffer['banner_image_url'] ?? ''), 'so-banner', 'banner'); ?>
      </div>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <label class="text-xs block mb-2">
      <span class="text-text-muted block mb-0.5">طريقة اختيار المواد</span>
      <select name="selection_mode" id="selection_mode" class="h-9 w-full max-w-xs rounded-lg border border-border-subtle px-2 text-sm">
        <option value="filter" <?= $selectionMode === 'filter' ? 'selected' : '' ?>>فلترة API</option>
        <option value="manual" <?= $selectionMode === 'manual' ? 'selected' : '' ?>>مواد يدوية</option>
      </select>
    </label>
  </article>

  <article id="filter-mode-panel" class="bg-white border border-border-subtle rounded-xl p-3 <?= $selectionMode === 'manual' ? 'hidden' : '' ?>">
    <details open class="group">
      <summary class="font-bold text-sm cursor-pointer list-none flex items-center justify-between gap-2">
        <span>فلاتر المواد</span>
        <span class="text-xs text-text-muted font-normal">عشوائي عند كل زيارة للرئيسية</span>
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
          <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'filter_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'so-material-types', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'filter_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'so-age-categories', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('الشركة', 'filter_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'so-manufacturers', true, false, false, 4); ?></div>
          <div><?php $renderTokenPicker('القياس', 'filter_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'so-size-ranges', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'filter_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'so-country-origins', true, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'filter_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'so-store-guids', false, false, false, 4); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'filter_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'so-group-guids', false, false, false, 4); ?></div>
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

  <article id="manual-mode-panel" class="bg-white border border-border-subtle rounded-xl p-3 <?= $selectionMode === 'filter' ? 'hidden' : '' ?>">
    <h3 class="font-bold text-sm mb-2">اختيار المواد يدوياً</h3>
    <p class="text-xs text-text-muted mb-2">ابحث بالاسم أو الكود — 24 نتيجة لكل دفعة.</p>

    <div class="mb-2 flex flex-wrap gap-2 items-start">
      <div class="text-xs flex-1 min-w-[200px] relative" id="so-material-search-wrap">
        <span class="text-text-muted block mb-0.5">بحث لإضافة مواد</span>
        <input
          type="search"
          id="so-material-search"
          autocomplete="off"
          enterkeyhint="search"
          role="combobox"
          aria-expanded="false"
          aria-controls="so-material-search-results"
          class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary"
          placeholder="اسم أو كود المادة"
        >
        <ul
          id="so-material-search-results"
          class="hidden absolute z-30 mt-1 w-full max-h-60 overflow-y-auto rounded-xl border border-border-subtle bg-white shadow-lg text-sm divide-y divide-border-subtle"
          role="listbox"
        ></ul>
      </div>
      <p id="so-material-search-status" class="text-xs text-text-muted min-h-[1.25rem] pt-8 flex-1"></p>
    </div>

    <?php $renderTokenPicker('المواد المشمولة بالعرض', 'manual_material_guids[]', $manualPickerOptions, $selectedMaterialGuids, 'so-manual-materials', false, true, true); ?>
  </article>

  <?php if ($editId !== ''): ?>
    <details class="bg-white border border-border-subtle rounded-xl p-3">
      <summary class="font-bold text-sm cursor-pointer">معاينة (<?= count($previewProducts) ?>)</summary>
      <div class="mt-2">
        <?php if ($previewProducts === []): ?>
          <p class="text-xs text-text-muted">لا توجد مواد — تحقق من الفلاتر أو اتصال API.</p>
        <?php else: ?>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
            <?php foreach ($previewProducts as $item): ?>
              <?php if (!is_array($item)) continue; ?>
              <div class="border rounded-lg p-2 bg-surface-low">
                <div class="font-bold line-clamp-2"><?= h((string) ($item['name'] ?? '')) ?></div>
                <?php if (!empty($item['has_offer'])): ?>
                  <div class="text-gray-400 line-through"><?= format_money((float) ($item['original_package_sale_price_sp'] ?? 0), true) ?> ل.س</div>
                  <div class="text-primary font-bold"><?= format_money((float) ($item['effective_package_sale_price_sp'] ?? 0), true) ?> ل.س</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>
  <?php endif; ?>
</form>
<?php portal_render_media_picker_modal(); ?>
<?php portal_render_token_picker_script(); ?>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($offers === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد عروض بعد.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[960px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">العرض</th>
            <th class="px-4 py-3 text-right font-bold">الحسم</th>
            <th class="px-4 py-3 text-right font-bold">الوضع</th>
            <th class="px-4 py-3 text-right font-bold">فلاتر</th>
            <th class="px-4 py-3 text-right font-bold">يدوي</th>
            <th class="px-4 py-3 text-right font-bold">الفترة</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($offers as $row): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($row['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted"><?= h((string) ($row['slug'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3 text-xs">
                <?= ($row['discount_type'] ?? '') === 'fixed_price' ? 'سعر جديد' : h((string) ($row['discount_percent'] ?? '')) . '%' ?>
              </td>
              <td class="px-4 py-3 text-xs"><?= ($row['selection_mode'] ?? '') === 'manual' ? 'يدوي' : 'فلترة' ?></td>
              <td class="px-4 py-3"><?= (int) ($row['filters_count'] ?? 0) ?></td>
              <td class="px-4 py-3"><?= (int) ($row['products_count'] ?? 0) ?></td>
              <td class="px-4 py-3 text-xs whitespace-nowrap">
                <?= h(substr((string) ($row['starts_at'] ?? ''), 0, 10)) ?> — <?= !empty($row['ends_at']) ? h(substr((string) $row['ends_at'], 0, 10)) : '∞' ?>
              </td>
              <td class="px-4 py-3">
                <?= !empty($row['is_active']) ? '<span class="text-emerald-700 font-bold text-xs">نشط</span>' : '<span class="text-xs text-slate-500">متوقف</span>' ?>
                <?php if (!empty($row['show_on_home'])): ?>
                  <span class="block text-[10px] text-primary font-bold mt-0.5">رئيسية</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5 flex-wrap">
                  <a href="/dashboard/special-offers.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                  <form method="post" data-dashboard-ajax data-dashboard-reload>
                    <input type="hidden" name="action" value="toggle_offer">
                    <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                    <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                    <?php if (!empty($row['is_active'])): ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                    <?php else: ?>
                      <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" data-dashboard-ajax data-dashboard-reload onsubmit="return confirm('حذف العرض؟');">
                    <input type="hidden" name="action" value="delete_offer">
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
