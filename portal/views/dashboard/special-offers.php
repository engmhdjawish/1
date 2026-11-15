<?php

declare(strict_types=1);

use Portal\Support\PriceFx;

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
/** @var array<string, array<string, mixed>> $offerAnalyticsById */
/** @var array{summary?: array<string, mixed>, materials?: list<array<string, mixed>>}|null $editOfferAnalytics */

require __DIR__ . '/partials/token-picker.php';
require __DIR__ . '/partials/media-picker.php';

$offerAnalyticsById = is_array($offerAnalyticsById ?? null) ? $offerAnalyticsById : [];
$editOfferAnalytics = is_array($editOfferAnalytics ?? null) ? $editOfferAnalytics : null;
$fmtInt = static fn ($n): string => number_format((float) $n, 0);
$fmtMoneyAr = static fn ($n, int $d = 0): string => number_format((float) $n, $d, '.', ',');


$showForm = $showForm ?? false;
$isNew = $isNew ?? false;
$editOffer = is_array($editOffer ?? null) ? $editOffer : [
    'id' => '',
    'filter_rules' => [],
    'display_options' => ['show_images' => true, 'price_mode' => 'both'],
    'selection_mode' => 'filter',
    'pricing_scope' => 'offer',
    'discount_type' => 'percent',
    'material_guids' => [],
    'manual_products' => [],
    'product_overrides' => [],
];
$rules = is_array($editOffer['filter_rules'] ?? null) ? $editOffer['filter_rules'] : [];
$displayOptions = is_array($editOffer['display_options'] ?? null)
    ? $editOffer['display_options']
    : ['show_images' => true, 'price_mode' => 'both'];
$selectionMode = (string) ($editOffer['selection_mode'] ?? 'filter');
$discountType = (string) ($editOffer['discount_type'] ?? 'percent');
$pricingScope = (string) ($editOffer['pricing_scope'] ?? 'offer');
$showImages = array_key_exists('show_images', $displayOptions) ? (bool) $displayOptions['show_images'] : true;
$priceMode = (string) ($displayOptions['price_mode'] ?? 'both');
$selectedMaterialGuids = array_values(array_unique(array_filter(array_map(
    static fn ($g) => strtolower(trim((string) $g)),
    $editOffer['material_guids'] ?? []
), static fn ($g) => $g !== '')));
$manualProductsByGuid = [];
foreach (($editOffer['manual_products'] ?? []) as $mp) {
    if (!is_array($mp)) {
        continue;
    }
    $g = strtolower(trim((string) ($mp['guid'] ?? '')));
    if ($g !== '') {
        $mp['guid'] = $g;
        $manualProductsByGuid[$g] = $mp;
    }
}
// Ensure every selected GUID has at least a stub label for the invoice table.
foreach ($selectedMaterialGuids as $guid) {
    if (!isset($manualProductsByGuid[$guid])) {
        $manualProductsByGuid[$guid] = [
            'guid' => $guid,
            'name' => $guid,
            'code' => '',
            'image' => '',
        ];
    }
}
$productOverrides = [];
foreach ((is_array($editOffer['product_overrides'] ?? null) ? $editOffer['product_overrides'] : []) as $overrideGuid => $override) {
    $overrideGuid = strtolower(trim((string) $overrideGuid));
    if ($overrideGuid === '' || !is_array($override)) {
        continue;
    }
    $productOverrides[$overrideGuid] = $override;
}

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
      <a href="/dashboard/special-offers.php?new=1&amp;tab=<?= urlencode((string) ($offerTab ?? 'active')) ?>" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">عرض جديد</a>
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

  <div class="dashboard-sticky-toolbar sticky z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل العرض' : 'عرض جديد' ?></h2>
    <div class="flex flex-wrap items-center gap-2">
      <a href="<?= h($offerTabQuery($offerTab, false)) ?>" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"><?= $editId !== '' ? 'إلغاء التعديل' : 'إلغاء' ?></a>
      <?php if ($editId !== ''): ?>
        <button type="submit" form="so-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
      <?php endif; ?>
      <button type="submit" id="special-offer-save-btn" data-dashboard-save-btn data-loading-label="جاري الحفظ…" class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">
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
        <span class="text-text-muted block mb-0.5">نطاق الحسم</span>
        <select name="pricing_scope" id="pricing_scope" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="offer" <?= $pricingScope === 'offer' ? 'selected' : '' ?>>موحّد لكل المواد</option>
          <option value="per_material" <?= $pricingScope === 'per_material' ? 'selected' : '' ?>>سعر لكل مادة</option>
        </select>
      </label>
      <label class="text-xs so-unified-discount" id="offer-discount-type-wrap">
        <span class="text-text-muted block mb-0.5">نوع الحسم</span>
        <select name="discount_type" id="discount_type" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>نسبة مئوية</option>
          <option value="fixed_amount" <?= $discountType === 'fixed_amount' ? 'selected' : '' ?>>مبلغ مقطوع من سعر الطرد</option>
          <option value="fixed_price" <?= $discountType === 'fixed_price' ? 'selected' : '' ?>>سعر طرد جديد</option>
        </select>
      </label>
      <label class="text-xs so-unified-discount" id="field-percent">
        <span class="text-text-muted block mb-0.5">النسبة %</span>
        <input type="number" step="0.01" min="0" max="100" name="discount_percent" value="<?= h((string) ($editOffer['discount_percent'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden so-unified-discount" id="field-amount-syp">
        <span class="text-text-muted block mb-0.5">خصم مبلغ ل.س من الطرد</span>
        <input type="number" step="0.01" min="0" name="fixed_amount_syp" value="<?= h((string) ($editOffer['fixed_amount_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden so-unified-discount" id="field-amount-usd">
        <span class="text-text-muted block mb-0.5">خصم مبلغ $ من الطرد</span>
        <input type="number" step="0.01" min="0" name="fixed_amount_usd" value="<?= h((string) ($editOffer['fixed_amount_usd'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden so-unified-discount" id="field-syp">
        <span class="text-text-muted block mb-0.5">سعر الطرد ل.س</span>
        <input type="number" step="0.01" min="0" name="fixed_price_syp" value="<?= h((string) ($editOffer['fixed_price_syp'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm">
      </label>
      <label class="text-xs hidden so-unified-discount" id="field-usd">
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

  <article id="selection-mode-article" class="bg-white border border-border-subtle rounded-xl p-3">
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
    <h3 id="manual-mode-heading" class="font-bold text-sm mb-2">اختيار المواد يدوياً</h3>
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

    <?php
      // Manual mode always uses the invoice/materials table — never visible chips.
      // Token picker stays mounted (hidden) so GUID hidden inputs stay in sync.
      $showManualInvoice = $selectionMode === 'manual';
      $invoiceIsPriced = $pricingScope === 'per_material';
    ?>
    <div class="hidden so-manual-picker--invoice" data-so-manual-picker-wrap data-suppress-chips="1">
      <?php $renderTokenPicker('المواد المشمولة بالعرض', 'manual_material_guids[]', $manualPickerOptions, $selectedMaterialGuids, 'so-manual-materials', false, true, true); ?>
    </div>

    <div id="per-material-pricing-panel" class="so-invoice mt-3<?= $showManualInvoice ? '' : ' hidden' ?><?= $invoiceIsPriced ? '' : ' so-invoice--unified' ?>">
      <div class="so-invoice__head">
        <div>
          <p class="so-invoice__kicker" data-so-invoice-kicker><?= $invoiceIsPriced ? 'فاتورة العرض' : 'مواد العرض' ?></p>
          <h4 class="so-invoice__title" data-so-invoice-title><?= $invoiceIsPriced ? 'مواد العرض وأسعارها' : 'المواد المشمولة بالعرض' ?></h4>
          <p class="so-invoice__hint" data-so-invoice-hint>
            <?php if ($invoiceIsPriced): ?>
              الدولار ثابت · الليرة بعد الخصم = دولار × سعر الصرف مع التقريب لأعلى 5. عدّل النسبة أو السعر.
            <?php else: ?>
              أضف مواداً من البحث أعلاه. الحسم الموحّد من أعلى النموذج يُطبَّق على كل المواد — يمكن ضبط كمية العرض لكل مادة.
            <?php endif; ?>
          </p>
        </div>
        <p class="so-invoice__count" id="so-invoice-count">
          <?= $selectedMaterialGuids === [] ? 'لا مواد' : (count($selectedMaterialGuids) === 1 ? 'مادة واحدة' : count($selectedMaterialGuids) . ' مواد') ?>
        </p>
      </div>
      <div class="so-invoice__sheet">
        <table class="so-invoice__table so-invoice__table--priced">
          <thead>
            <tr>
              <th class="so-invoice__num">#</th>
              <th>المادة</th>
              <th>مبيع $</th>
              <th>شراء $</th>
              <th class="so-invoice__type-col">نوع الحسم</th>
              <th class="so-invoice__price-col">خصم % / سعر $</th>
              <th>كمية العرض</th>
              <th class="so-invoice__after-col">بعد الخصم ل.س</th>
              <th class="so-invoice__action-col"></th>
            </tr>
          </thead>
          <tbody id="per-material-pricing-rows">
            <?php if ($showManualInvoice && $selectedMaterialGuids !== []): ?>
              <?php foreach ($selectedMaterialGuids as $idx => $guid): ?>
                <?php
                  $meta = $manualProductsByGuid[$guid] ?? ['guid' => $guid, 'name' => $guid];
                  $override = $productOverrides[$guid] ?? [];
                  $dtype = (string) ($override['discount_type'] ?? 'percent');
                  if (!in_array($dtype, ['percent', 'fixed_amount', 'fixed_price'], true)) {
                      $dtype = 'percent';
                  }
                  $pct = isset($override['discount_percent']) && is_numeric((string) $override['discount_percent'])
                      ? (float) $override['discount_percent']
                      : (($editOffer['discount_percent'] ?? null) !== null && is_numeric((string) $editOffer['discount_percent'])
                          ? (float) $editOffer['discount_percent']
                          : 15.0);
                  $saleUsd = isset($meta['unitSalePriceUsd']) && is_numeric((string) $meta['unitSalePriceUsd'])
                      ? (float) $meta['unitSalePriceUsd'] : null;
                  $saleSyp = isset($meta['unitSalePriceSyp']) && is_numeric((string) $meta['unitSalePriceSyp'])
                      ? (float) $meta['unitSalePriceSyp'] : null;
                  $purchaseUsd = isset($meta['unitPurchasePriceUsd']) && is_numeric((string) $meta['unitPurchasePriceUsd'])
                      ? (float) $meta['unitPurchasePriceUsd'] : null;
                  $currencyVal = isset($meta['currencyVal']) && is_numeric((string) $meta['currencyVal'])
                      ? (float) $meta['currencyVal'] : null;
                  $rate = PriceFx::inferRate($saleSyp, $saleUsd, $currencyVal);
                  $afterUsd = $saleUsd !== null ? PriceFx::applyPercentToUsd($saleUsd, $pct) : null;
                  if ($dtype === 'fixed_price' && isset($override['fixed_price_usd']) && is_numeric((string) $override['fixed_price_usd'])) {
                      $afterUsd = (float) $override['fixed_price_usd'];
                  }
                  $afterSyp = ($afterUsd !== null && $rate > 0.0) ? PriceFx::sypFromUsd($afterUsd, $rate) : null;
                  $belowCost = $afterUsd !== null && $purchaseUsd !== null && $purchaseUsd > 0 && $afterUsd < $purchaseUsd;
                  $name = (string) ($meta['name'] ?? $guid);
                  $code = (string) ($meta['code'] ?? '');
                  $image = (string) ($meta['image'] ?? '');
                ?>
                <tr
                  class="so-invoice__row"
                  data-override-guid="<?= h($guid) ?>"
                  data-sale-usd="<?= $saleUsd !== null ? h((string) $saleUsd) : '' ?>"
                  data-purchase-usd="<?= $purchaseUsd !== null ? h((string) $purchaseUsd) : '' ?>"
                  data-rate="<?= $rate > 0 ? h((string) $rate) : '' ?>"
                >
                  <td class="so-invoice__num"><?= (int) $idx + 1 ?></td>
                  <td class="so-invoice__material">
                    <div class="so-invoice__material-inner">
                      <span class="so-invoice__thumb<?= $image === '' ? ' is-empty' : '' ?>">
                        <?php if ($image !== ''): ?>
                          <img src="<?= h($image) ?>" alt="" loading="lazy">
                        <?php else: ?>
                          صورة
                        <?php endif; ?>
                      </span>
                      <span class="so-invoice__material-text">
                        <span class="so-invoice__name"><?= h($name) ?></span>
                        <?php if ($code !== ''): ?>
                          <span class="so-invoice__code"><?= h($code) ?></span>
                        <?php endif; ?>
                      </span>
                    </div>
                  </td>
                  <td class="tabular-nums font-bold" dir="ltr"><?= $saleUsd !== null ? h(number_format($saleUsd, 2, '.', '')) : '—' ?></td>
                  <td class="tabular-nums text-slate-600" dir="ltr"><?= $purchaseUsd !== null ? h(number_format($purchaseUsd, 2, '.', '')) : '—' ?></td>
                  <td class="so-invoice__type">
                    <select name="material_override[<?= h($guid) ?>][discount_type]" data-field="discount_type" class="so-invoice__select"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      <option value="percent" <?= $dtype === 'percent' ? 'selected' : '' ?>>نسبة %</option>
                      <option value="fixed_amount" <?= $dtype === 'fixed_amount' ? 'selected' : '' ?>>مبلغ مقطوع</option>
                      <option value="fixed_price" <?= $dtype === 'fixed_price' ? 'selected' : '' ?>>سعر طرد جديد</option>
                    </select>
                  </td>
                  <td class="so-invoice__value">
                    <div class="so-invoice__values">
                      <label class="so-invoice__value-field<?= $dtype === 'percent' ? '' : ' hidden' ?>" data-show-for="percent">
                        <span class="text-text-muted whitespace-nowrap">%</span>
                        <input type="text" inputmode="decimal" autocomplete="off"<?= $invoiceIsPriced ? ' required' : '' ?> class="so-invoice__input" name="material_override[<?= h($guid) ?>][discount_percent]" data-field="discount_percent" value="<?= h(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') ?: '0') ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                      <label class="so-invoice__value-field<?= $dtype === 'percent' ? '' : ' hidden' ?>" data-show-for="percent">
                        <span class="text-text-muted whitespace-nowrap">بعد $</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input<?= $belowCost ? ' is-below-cost' : '' ?>" data-field="sale_after_usd" value="<?= $afterUsd !== null ? h(number_format($afterUsd, 2, '.', '')) : '' ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                      <label class="so-invoice__value-field<?= $dtype === 'fixed_amount' ? '' : ' hidden' ?>" data-show-for="fixed_amount">
                        <span class="text-text-muted whitespace-nowrap">ل.س</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input" name="material_override[<?= h($guid) ?>][fixed_amount_syp]" data-field="fixed_amount_syp" value="<?= h((string) ($override['fixed_amount_syp'] ?? '')) ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                      <label class="so-invoice__value-field<?= $dtype === 'fixed_amount' ? '' : ' hidden' ?>" data-show-for="fixed_amount">
                        <span class="text-text-muted whitespace-nowrap">$</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input" name="material_override[<?= h($guid) ?>][fixed_amount_usd]" data-field="fixed_amount_usd" value="<?= h((string) ($override['fixed_amount_usd'] ?? '')) ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                      <label class="so-invoice__value-field<?= $dtype === 'fixed_price' ? '' : ' hidden' ?>" data-show-for="fixed_price">
                        <span class="text-text-muted whitespace-nowrap">سعر ل.س</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input" name="material_override[<?= h($guid) ?>][fixed_price_syp]" data-field="fixed_price_syp" value="<?= h((string) ($override['fixed_price_syp'] ?? ($afterSyp !== null ? (string) $afterSyp : ''))) ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                      <label class="so-invoice__value-field<?= $dtype === 'fixed_price' ? '' : ' hidden' ?>" data-show-for="fixed_price">
                        <span class="text-text-muted whitespace-nowrap">سعر $</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input" name="material_override[<?= h($guid) ?>][fixed_price_usd]" data-field="fixed_price_usd" value="<?= h((string) ($override['fixed_price_usd'] ?? ($afterUsd !== null ? number_format($afterUsd, 2, '.', '') : ''))) ?>"<?= $invoiceIsPriced ? '' : ' disabled' ?>>
                      </label>
                    </div>
                  </td>
                  <?php
                    $qtyMode = trim((string) ($override['qty_mode'] ?? ''));
                    if (!in_array($qtyMode, ['unlimited', 'stock', 'fixed'], true)) {
                        // Prefer fixed when a package cap was stored (stagnant creates).
                        $qtyMode = isset($override['qty_cap_packages']) && is_numeric((string) $override['qty_cap_packages'])
                            ? 'fixed'
                            : 'unlimited';
                    }
                    $qtyCap = $override['qty_cap_packages'] ?? '';
                    $isExhausted = !empty($override['is_exhausted']);
                    $mpMeta = $manualProductsByGuid[$guid] ?? [];
                    if ($qtyMode === 'fixed' && ($qtyCap === '' || $qtyCap === null)
                        && isset($mpMeta['availablePackages']) && is_numeric((string) $mpMeta['availablePackages'])) {
                        $qtyCap = $mpMeta['availablePackages'];
                    }
                  ?>
                  <td class="so-invoice__qty">
                    <div class="so-invoice__qty-fields">
                      <select name="material_override[<?= h($guid) ?>][qty_mode]" data-field="qty_mode" class="so-invoice__select so-invoice__select--qty">
                        <option value="unlimited" <?= $qtyMode === 'unlimited' ? 'selected' : '' ?>>بلا حد</option>
                        <option value="stock" <?= $qtyMode === 'stock' ? 'selected' : '' ?>>المخزون المتاح</option>
                        <option value="fixed" <?= $qtyMode === 'fixed' ? 'selected' : '' ?>>كمية محددة</option>
                      </select>
                      <label class="so-invoice__value-field<?= $qtyMode === 'fixed' ? '' : ' hidden' ?>" data-show-for-qty="fixed">
                        <span class="text-text-muted whitespace-nowrap">طرد</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="so-invoice__input" name="material_override[<?= h($guid) ?>][qty_cap_packages]" data-field="qty_cap_packages" value="<?= h((string) $qtyCap) ?>" placeholder="مثلاً 20">
                      </label>
                      <?php if ($isExhausted): ?>
                        <span class="so-invoice__exhausted">نفدت — خرجت من العرض</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="tabular-nums font-extrabold so-invoice__after<?= $belowCost ? ' is-below-cost' : ' text-emerald-800' ?>" dir="ltr" data-after-syp>
                    <?= $afterSyp !== null ? h(number_format($afterSyp, 0)) : '—' ?>
                  </td>
                  <td class="so-invoice__action">
                    <button type="button" class="so-invoice__remove" data-so-remove-row>حذف</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <script type="application/json" id="so-product-overrides-json"><?= json_encode($productOverrides, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
      <script type="application/json" id="so-manual-product-labels-json"><?= json_encode($manualProductsByGuid, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
      <script type="application/json" id="so-manual-material-guids-json"><?= json_encode(array_values(array_map('strval', $selectedMaterialGuids)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
    </div>
  </article>

  <?php if ($editId !== ''): ?>
    <?php
      $previewLoaded = $includeOfferPreview ?? (($_GET['preview'] ?? '') === '1');
      $previewQuery = $offerTabQuery($offerTab ?? 'active');
      $previewQuery .= (str_contains($previewQuery, '?') ? '&' : '?') . 'preview=1';
      // When editing, offerTabQuery already keeps edit=; ensure preview flag is present.
      if (!str_contains($previewQuery, 'edit=')) {
          $previewQuery = '/dashboard/special-offers.php?edit=' . rawurlencode($editId)
              . '&tab=' . rawurlencode((string) ($offerTab ?? 'active'))
              . '&preview=1';
      }
    ?>
    <details class="bg-white border border-border-subtle rounded-xl p-3"<?= $previewLoaded ? ' open' : '' ?>>
      <summary class="font-bold text-sm cursor-pointer">معاينة المتجر<?= $previewLoaded ? ' (' . count($previewProducts) . ')' : '' ?></summary>
      <div class="mt-2">
        <?php if (!$previewLoaded): ?>
          <p class="text-xs text-text-muted mb-2">المعاينة الثقيلة تُحمَّل عند الطلب لتسريع فتح العروض ذات القوائم الكبيرة.</p>
          <a href="<?= h($previewQuery) ?>" class="inline-flex h-8 px-3 items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تحميل المعاينة</a>
        <?php elseif ($previewProducts === []): ?>
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

  <?php if ($editOfferAnalytics !== null && trim((string) ($editOffer['id'] ?? '')) !== ''): ?>
    <?php
      $an = is_array($editOfferAnalytics['summary'] ?? null) ? $editOfferAnalytics['summary'] : [];
      $anMats = is_array($editOfferAnalytics['materials'] ?? null) ? $editOfferAnalytics['materials'] : [];
    ?>
    <article class="so-analytics bg-white border border-border-subtle rounded-xl p-4 mt-4">
      <div class="flex flex-wrap items-end justify-between gap-2 mb-3">
        <div>
          <p class="text-[11px] font-bold text-primary tracking-wide">جدوى العرض</p>
          <h3 class="font-extrabold text-slate-900 text-base">قياس التفاعل والمبيعات</h3>
          <p class="text-xs text-text-muted mt-0.5">آخر 90 يوماً للمشاهدات · المبيعات من طلبات مرتبطة بهذا العرض</p>
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">طلبات</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($an['orders'] ?? 0)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">طرود مباعة</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($an['packages'] ?? 0, 1)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">إيراد ل.س</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($an['revenue_syp'] ?? 0, 0)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">خصم ممنوح ل.س</div>
          <div class="text-xl font-extrabold tabular-nums text-amber-800"><?= h($fmtMoneyAr($an['discount_syp'] ?? 0, 0)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">مشاهدات / صفحات</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($an['page_views'] ?? 0)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">إضافة للسلة</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($an['add_to_cart'] ?? 0)) ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">تحويل طلبات</div>
          <div class="text-xl font-extrabold tabular-nums"><?= isset($an['conversion_pct']) && $an['conversion_pct'] !== null ? h((string) $an['conversion_pct']) . '%' : '—' ?></div>
        </div>
        <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
          <div class="text-[11px] text-text-muted">مواد بيعت</div>
          <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($an['materials'] ?? 0)) ?></div>
        </div>
      </div>
      <?php if ($anMats !== []): ?>
        <div class="overflow-auto border border-border-subtle rounded-lg">
          <table class="w-full text-sm min-w-[640px]">
            <thead class="bg-surface-low text-text-muted">
              <tr>
                <th class="px-3 py-2 text-right font-bold">المادة</th>
                <th class="px-3 py-2 text-right font-bold">طلبات</th>
                <th class="px-3 py-2 text-right font-bold">طرود</th>
                <th class="px-3 py-2 text-right font-bold">إيراد ل.س</th>
                <th class="px-3 py-2 text-right font-bold">خصم ل.س</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <?php foreach ($anMats as $mat): ?>
                <?php if (!is_array($mat)) continue; ?>
                <tr>
                  <td class="px-3 py-2">
                    <div class="font-bold"><?= h((string) ($mat['material_name_ar'] ?? $mat['material_guid'] ?? '')) ?></div>
                    <?php if (!empty($mat['material_code'])): ?>
                      <div class="text-[11px] text-text-muted"><?= h((string) $mat['material_code']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="px-3 py-2 tabular-nums"><?= h($fmtInt($mat['orders'] ?? 0)) ?></td>
                  <td class="px-3 py-2 tabular-nums"><?= h($fmtMoneyAr($mat['packages'] ?? 0, 1)) ?></td>
                  <td class="px-3 py-2 tabular-nums"><?= h($fmtMoneyAr($mat['revenue_syp'] ?? 0, 0)) ?></td>
                  <td class="px-3 py-2 tabular-nums text-amber-800"><?= h($fmtMoneyAr($mat['discount_syp'] ?? 0, 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-xs text-text-muted">لا مبيعات مرتبطة بهذا العرض بعد.</p>
      <?php endif; ?>
    </article>
  <?php endif; ?>
</form>
<?php portal_render_token_picker_script(); ?>
<?php endif; ?>

<?php
$offerTab = $offerTab ?? 'active';
$offerTabQuery = $offerTabQuery ?? static function (string $tab, bool $keepForm = true): string {
    return '/dashboard/special-offers.php?tab=' . rawurlencode($tab);
};
$offerLifecycle = static function (array $row): string {
    $now = time();
    $ends = trim((string) ($row['ends_at'] ?? ''));
    $starts = trim((string) ($row['starts_at'] ?? ''));
    $endsTs = $ends !== '' ? strtotime($ends) : false;
    if ($endsTs !== false && $endsTs <= $now) {
        return 'ended';
    }
    if (empty($row['is_active'])) {
        return 'paused';
    }
    $startsTs = $starts !== '' ? strtotime($starts) : false;
    if ($startsTs !== false && $startsTs > $now) {
        return 'scheduled';
    }

    return 'active';
};
$offerTabLabels = [
    'active' => 'فعالة',
    'scheduled' => 'قادمة',
    'paused' => 'موقوفة',
    'ended' => 'منتهية',
    'all' => 'الكل',
];
$offerStatusLabels = [
    'active' => 'فعالة',
    'scheduled' => 'قادمة',
    'paused' => 'موقوفة',
    'ended' => 'منتهية',
];
$offerTabCounts = ['active' => 0, 'scheduled' => 0, 'paused' => 0, 'ended' => 0, 'all' => count($offers)];
$visibleOffers = [];
foreach ($offers as $row) {
    if (!is_array($row)) {
        continue;
    }
    $lifecycle = $offerLifecycle($row);
    $row['_lifecycle'] = $lifecycle;
    $offerTabCounts[$lifecycle] = ($offerTabCounts[$lifecycle] ?? 0) + 1;
    if ($offerTab === 'all' || $lifecycle === $offerTab) {
        $visibleOffers[] = $row;
    }
}
?>
<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <nav class="so-offer-tabs" aria-label="تصفية العروض">
    <?php foreach ($offerTabLabels as $tabKey => $tabLabel): ?>
      <a
        href="<?= h($offerTabQuery($tabKey)) ?>"
        class="so-offer-tabs__link<?= $offerTab === $tabKey ? ' is-active' : '' ?>"
        <?= $offerTab === $tabKey ? 'aria-current="page"' : '' ?>
      >
        <span><?= h($tabLabel) ?></span>
        <span class="so-offer-tabs__count"><?= (int) ($offerTabCounts[$tabKey] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php if ($offers === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد عروض بعد.</p>
  <?php elseif ($visibleOffers === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد عروض في تبويب «<?= h($offerTabLabels[$offerTab] ?? '') ?>».</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[960px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">العرض</th>
            <th class="px-4 py-3 text-right font-bold">الحسم</th>
            <th class="px-4 py-3 text-right font-bold">الوضع</th>
            <th class="px-4 py-3 text-right font-bold">يدوي</th>
            <th class="px-4 py-3 text-right font-bold">جدوى</th>
            <th class="px-4 py-3 text-right font-bold">الفترة</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($visibleOffers as $row): ?>
            <?php
              $lifecycle = (string) ($row['_lifecycle'] ?? 'paused');
              $rowAn = $offerAnalyticsById[(string) ($row['id'] ?? '')] ?? null;
            ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($row['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted"><?= h((string) ($row['slug'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3 text-xs">
                <?php
                  $rowType = (string) ($row['discount_type'] ?? '');
                  $rowDiscountLabel = match ($rowType) {
                      'fixed_price' => 'سعر جديد',
                      'fixed_amount' => 'مبلغ مقطوع',
                      default => h((string) ($row['discount_percent'] ?? '')) . '%',
                  };
                ?>
                <?= $rowDiscountLabel ?>
              </td>
              <td class="px-4 py-3 text-xs"><?= ($row['selection_mode'] ?? '') === 'manual' ? 'يدوي' : 'فلترة' ?></td>
              <td class="px-4 py-3"><?= (int) ($row['products_count'] ?? 0) ?></td>
              <td class="px-4 py-3 text-xs">
                <?php if (is_array($rowAn)): ?>
                  <div class="font-bold tabular-nums"><?= h($fmtInt($rowAn['orders'] ?? 0)) ?> طلب</div>
                  <div class="text-text-muted tabular-nums"><?= h($fmtMoneyAr($rowAn['revenue_syp'] ?? 0, 0)) ?> ل.س</div>
                  <div class="text-[10px] text-text-muted tabular-nums"><?= h($fmtInt($rowAn['page_views'] ?? 0)) ?> مشاهدة · <?= h($fmtInt($rowAn['add_to_cart'] ?? 0)) ?> سلة</div>
                <?php else: ?>
                  <span class="text-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs whitespace-nowrap">
                <?= h(substr((string) ($row['starts_at'] ?? ''), 0, 10)) ?> — <?= !empty($row['ends_at']) ? h(substr((string) $row['ends_at'], 0, 10)) : '∞' ?>
              </td>
              <td class="px-4 py-3">
                <span class="so-offer-status so-offer-status--<?= h($lifecycle) ?>"><?= h($offerStatusLabels[$lifecycle] ?? '') ?></span>
                <?php if (!empty($row['show_on_home'])): ?>
                  <span class="block text-[10px] text-primary font-bold mt-0.5">رئيسية</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex justify-end gap-1.5 flex-wrap">
                  <a href="/dashboard/special-offers.php?edit=<?= urlencode((string) $row['id']) ?>&amp;tab=<?= urlencode($offerTab) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
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
