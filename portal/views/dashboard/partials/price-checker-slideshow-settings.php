<?php

declare(strict_types=1);

/**
 * Slideshow / promo settings for price checker dashboard.
 *
 * @var array<string, mixed> $config
 * @var array<string, mixed> $materialFilterOptions
 * @var string|null $materialFilterOptionsError
 * @var list<array<string, mixed>> $specialOffers
 */

require __DIR__ . '/token-picker.php';

$slideshowMode = (string) ($config['slideshow_mode'] ?? 'filter');
$rules = is_array($config['slideshow_filter_rules'] ?? null) ? $config['slideshow_filter_rules'] : [];
$selectedMaterialGuids = is_array($config['slideshow_material_guids'] ?? null) ? $config['slideshow_material_guids'] : [];

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
    $storeOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid];
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
    $groupOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid];
}
foreach ($selectedGroupGuids as $guid) {
    if (!in_array($guid, array_column($groupOptionObjects, 'value'), true)) {
        $groupOptionObjects[] = ['value' => $guid, 'label' => $guid];
    }
}

$manualPickerOptions = [];
foreach ($manualProducts ?? [] as $product) {
    if (!is_array($product)) {
        continue;
    }
    $guid = trim((string) ($product['guid'] ?? $product['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $name = trim((string) ($product['name'] ?? $product['Name'] ?? ''));
    $code = trim((string) ($product['materialCode'] ?? $product['MaterialCode'] ?? ''));
    $label = $name !== '' ? $name : $guid;
    if ($code !== '') {
        $label .= ' (' . $code . ')';
    }
    $manualPickerOptions[] = ['value' => $guid, 'label' => $label];
}

$offerPriceSlug = !empty($config['slideshow_use_offer_prices']) ? (string) ($config['slideshow_offer_slug'] ?? '') : '';
?>
<div class="space-y-4" id="price-checker-slideshow-settings">
  <label class="block text-sm font-bold text-slate-800">مصدر إعلانات الشاشة</label>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
    <label class="flex items-start gap-2 rounded-xl border border-border-subtle p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
      <input type="radio" name="slideshow_mode" value="filter" class="mt-1" <?= $slideshowMode === 'filter' ? 'checked' : '' ?>>
      <span>
        <span class="block text-sm font-bold">فلاتر المواد</span>
        <span class="block text-xs text-text-muted mt-0.5">مثل المتجر — عشوائي من نتائج الفلتر</span>
      </span>
    </label>
    <label class="flex items-start gap-2 rounded-xl border border-border-subtle p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
      <input type="radio" name="slideshow_mode" value="manual" class="mt-1" <?= $slideshowMode === 'manual' ? 'checked' : '' ?>>
      <span>
        <span class="block text-sm font-bold">مواد محددة</span>
        <span class="block text-xs text-text-muted mt-0.5">اختر مواداً بالاسم أو الكود</span>
      </span>
    </label>
    <label class="flex items-start gap-2 rounded-xl border border-border-subtle p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
      <input type="radio" name="slideshow_mode" value="offer" class="mt-1" <?= $slideshowMode === 'offer' ? 'checked' : '' ?>>
      <span>
        <span class="block text-sm font-bold">عرض خاص</span>
        <span class="block text-xs text-text-muted mt-0.5">من العروض الخاصة مع أسعار العرض</span>
      </span>
    </label>
  </div>

  <div id="pc-filter-panel" class="<?= $slideshowMode !== 'filter' ? 'hidden' : '' ?> rounded-xl border border-border-subtle bg-surface-low p-4 space-y-3">
    <p class="text-xs text-text-muted">اختر الفلاتر كما في أقسام الرئيسية. تُعرض مواد عشوائية من النتائج في كل دورة.</p>
    <?php if (!empty($materialFilterOptionsError)): ?>
      <p class="rounded-lg border border-amber-200 bg-amber-50 text-amber-700 px-3 py-2 text-xs"><?= h($materialFilterOptionsError) ?></p>
    <?php endif; ?>
    <label class="text-xs block">
      <span class="text-text-muted block mb-1">كلمة بحث</span>
      <input name="filter_keyword" value="<?= h((string) ($rules['keyword'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'filter_material_types[]', $toOptionObjects($materialTypeOptions), $selectedMaterialTypes, 'pc-material-types', true, false, false, 4); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'filter_age_categories[]', $toOptionObjects($ageCategoryOptions), $selectedAgeCategories, 'pc-age-categories', true, false, false, 4); ?></div>
      <div><?php $renderTokenPicker('الشركة', 'filter_manufacturers[]', $toOptionObjects($manufacturerOptions), $selectedManufacturers, 'pc-manufacturers', true, false, false, 4); ?></div>
      <div><?php $renderTokenPicker('القياس', 'filter_size_ranges[]', $toOptionObjects($sizeRangeOptions), $selectedSizeRanges, 'pc-size-ranges', true, false, false, 4); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'filter_country_origins[]', $toOptionObjects($countryOriginOptions), $selectedCountryOrigins, 'pc-country-origins', true, false, false, 4); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'filter_store_guids[]', $storeOptionObjects, $selectedStoreGuids, 'pc-store-guids', false, false, false, 4); ?></div>
      <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'filter_group_guids[]', $groupOptionObjects, $selectedGroupGuids, 'pc-group-guids', false, false, false, 4); ?></div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
      <label class="text-xs">
        <span class="text-text-muted block mb-1">التوفر</span>
        <select name="filter_is_available" class="h-10 w-full rounded-xl border border-border-subtle px-2 text-sm">
          <option value="" <?= $filterIsAvailable === null ? 'selected' : '' ?>>بدون قيد</option>
          <option value="1" <?= $filterIsAvailable === true ? 'selected' : '' ?>>متوفر</option>
          <option value="0" <?= $filterIsAvailable === false ? 'selected' : '' ?>>غير متوفر</option>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-1">الصورة</span>
        <select name="filter_has_image" class="h-10 w-full rounded-xl border border-border-subtle px-2 text-sm">
          <option value="" <?= $filterHasImage === null ? 'selected' : '' ?>>بدون قيد</option>
          <option value="1" <?= $filterHasImage === true ? 'selected' : '' ?>>مع صورة</option>
          <option value="0" <?= $filterHasImage === false ? 'selected' : '' ?>>بدون صورة</option>
        </select>
      </label>
    </div>

    <details class="rounded-lg border border-border-subtle bg-white">
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

  <div id="pc-manual-panel" class="<?= $slideshowMode !== 'manual' ? 'hidden' : '' ?> rounded-xl border border-border-subtle bg-surface-low p-4 space-y-3">
    <p class="text-xs text-text-muted">ابحث بالاسم أو الكود وأضف المواد التي تريد عرضها على الشاشة.</p>
    <div class="relative" id="pc-material-search-wrap">
      <label class="text-xs block">
        <span class="text-text-muted block mb-1">بحث لإضافة مواد</span>
        <input type="search" id="pc-material-search" autocomplete="off" class="h-10 w-full rounded-xl border border-border-subtle px-3 text-sm" placeholder="اسم أو كود المادة">
      </label>
      <div id="pc-material-search-results" class="hidden absolute z-20 mt-1 w-full bg-white border border-border-subtle rounded-xl shadow-lg max-h-64 overflow-auto"></div>
      <p id="pc-material-search-status" class="text-xs text-text-muted mt-1"></p>
    </div>
    <?php $renderTokenPicker('المواد المختارة', 'manual_material_guids[]', $manualPickerOptions, $selectedMaterialGuids, 'pc-manual-materials', false, true, true); ?>
  </div>

  <div id="pc-offer-panel" class="<?= $slideshowMode !== 'offer' ? 'hidden' : '' ?> rounded-xl border border-border-subtle bg-surface-low p-4 space-y-3">
    <label class="text-sm block">
      <span class="font-bold text-slate-800">العرض الخاص</span>
      <select name="slideshow_offer_slug" class="mt-1 h-10 w-full max-w-lg rounded-xl border border-border-subtle px-3 text-sm">
        <option value="">— اختر عرضاً نشطاً —</option>
        <?php foreach ($specialOffers as $offer): ?>
          <?php
            $slug = trim((string) ($offer['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $label = trim((string) ($offer['title_ar'] ?? $slug));
            if (empty($offer['is_active'])) {
                $label .= ' (غير نشط)';
            }
          ?>
          <option value="<?= h($slug) ?>" <?= (string) ($config['slideshow_offer_slug'] ?? '') === $slug ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="text-xs text-text-muted">يُستخدم محتوى العرض (فلاتره أو مواده اليدوية) مع <strong>أسعار العرض</strong> تلقائياً.</p>
  </div>

  <div class="rounded-xl border border-dashed border-border-subtle p-3 space-y-2 <?= $slideshowMode === 'offer' ? 'hidden' : '' ?>" id="pc-offer-prices-panel">
    <label class="flex items-center gap-3 cursor-pointer">
      <input type="checkbox" name="slideshow_use_offer_prices" value="1" class="rounded border-border-subtle" <?= !empty($config['slideshow_use_offer_prices']) ? 'checked' : '' ?>>
      <span class="text-sm font-bold text-slate-800">عرض أسعار عرض خاص على المواد</span>
    </label>
    <label class="text-sm block mr-8">
      <span class="text-text-muted text-xs">العرض الخاص لأسعار الشاشة</span>
      <select name="slideshow_offer_price_slug" class="mt-1 h-10 w-full max-w-lg rounded-xl border border-border-subtle px-3 text-sm">
        <option value="">— اختر عرضاً —</option>
        <?php foreach ($specialOffers as $offer): ?>
          <?php $slug = trim((string) ($offer['slug'] ?? '')); if ($slug === '') continue; ?>
          <option value="<?= h($slug) ?>" <?= $offerPriceSlug === $slug ? 'selected' : '' ?>><?= h((string) ($offer['title_ar'] ?? $slug)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
</div>
