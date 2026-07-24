<?php

declare(strict_types=1);

/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var list<array<string, mixed>> $invoiceTypes */
/** @var string|null $invoiceTypesError */

require __DIR__ . '/token-picker.php';

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

$materialTypeOptions = array_values(array_unique(array_map('strval', $materialFilterOptions['materialTypes'] ?? [])));
$ageCategoryOptions = array_values(array_unique(array_map('strval', $materialFilterOptions['ageCategories'] ?? [])));
$manufacturerOptions = array_values(array_unique(array_map('strval', $materialFilterOptions['manufacturers'] ?? [])));
$sizeRangeOptions = array_values(array_unique(array_map('strval', $materialFilterOptions['sizeRanges'] ?? [])));
$countryOriginOptions = array_values(array_unique(array_map('strval', $materialFilterOptions['countryOfOrigins'] ?? [])));

$storeOptionObjects = [];
foreach ($materialFilterOptions['stores'] ?? [] as $store) {
    if (!is_array($store)) {
        continue;
    }
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $storeOptionObjects[] = [
        'value' => $guid,
        'label' => trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid,
    ];
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
    $groupOptionObjects[] = [
        'value' => $guid,
        'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid,
    ];
}
?>
<div data-material-images-download-panel>
  <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    <section class="rounded-xl border border-border-subtle bg-white overflow-hidden xl:col-span-2">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold text-sm">تحميل ZIP حسب فلاتر المواد</h2>
        <p class="text-xs text-text-muted mt-0.5">من ملفات الموقع المحلية فقط — حدّد الفلاتر ثم حمّل</p>
      </div>

      <form class="dash-mi-zip-form" method="get" action="/api/material-images-zip.php" target="_blank" data-material-zip-form>
        <input type="hidden" name="mode" value="materials">
        <input type="hidden" name="isAvailable" value="1" data-zip-availability-input>

        <div class="dash-mi-zip-form__body">
          <?php if (!empty($materialFilterOptionsError)): ?>
            <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $materialFilterOptionsError) ?></p>
          <?php endif; ?>

          <div class="dash-mi-zip-toolbar">
            <label class="dash-mi-zip-toolbar__search">
              <span class="dash-mi-zip-label">بحث</span>
              <input type="search" name="search" class="dash-mi-zip-input" placeholder="رمز أو اسم المادة" autocomplete="off">
            </label>
            <div class="dash-mi-zip-toolbar__availability">
              <span class="dash-mi-zip-label">التوفر</span>
              <div class="dash-mi-filter-tabs" role="group" aria-label="التوفر" data-zip-availability-tabs>
                <button type="button" class="dash-mi-filter-tab" data-availability="">الكل</button>
                <button type="button" class="dash-mi-filter-tab is-active" data-availability="1">متوفر</button>
                <button type="button" class="dash-mi-filter-tab" data-availability="0">غير متوفر</button>
              </div>
            </div>
          </div>

          <div class="dash-mi-zip-filters-grid">
            <div class="dash-mi-zip-filter-card">
              <?php $renderTokenPicker('نوع المادة', 'materialTypes[]', $toOptionObjects($materialTypeOptions), [], 'mid-material-types', true, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card">
              <?php $renderTokenPicker('الفئة العمرية', 'ageCategories[]', $toOptionObjects($ageCategoryOptions), [], 'mid-age-categories', true, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card">
              <?php $renderTokenPicker('الشركة المصنعة', 'manufacturers[]', $toOptionObjects($manufacturerOptions), [], 'mid-manufacturers', true, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card">
              <?php $renderTokenPicker('القياس', 'sizeRanges[]', $toOptionObjects($sizeRangeOptions), [], 'mid-size-ranges', true, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card dash-mi-zip-filter-card--wide">
              <?php $renderTokenPicker('بلد المنشأ', 'countryOfOrigins[]', $toOptionObjects($countryOriginOptions), [], 'mid-country-origins', true, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card dash-mi-zip-filter-card--wide">
              <?php $renderTokenPicker('المخازن', 'storeGuids[]', $storeOptionObjects, [], 'mid-store-guids', false, false, false, 4); ?>
            </div>
            <div class="dash-mi-zip-filter-card dash-mi-zip-filter-card--wide">
              <?php $renderTokenPicker('المجموعات', 'groupGuids[]', $groupOptionObjects, [], 'mid-group-guids', false, false, false, 4); ?>
            </div>
          </div>

          <details class="dash-mi-zip-details">
            <summary class="dash-mi-zip-details__toggle">خيارات متقدمة (مخزون · تقسيم ZIP)</summary>
            <div class="dash-mi-zip-details__body">
              <div class="grid grid-cols-2 gap-3 mb-3">
                <label class="block text-sm">
                  <span class="dash-mi-zip-label">أدنى مخزون</span>
                  <input type="number" step="0.01" min="0" name="minWarehouseQuantity" class="dash-mi-zip-input" placeholder="0">
                </label>
                <label class="block text-sm">
                  <span class="dash-mi-zip-label">أعلى مخزون</span>
                  <input type="number" step="0.01" min="0" name="maxWarehouseQuantity" class="dash-mi-zip-input" placeholder="—">
                </label>
              </div>
              <label class="block text-sm max-w-md">
                <span class="dash-mi-zip-label">تقسيم التحميل</span>
                <select name="splitBy" data-zip-split-by class="dash-mi-zip-input">
                  <option value="">ملف ZIP واحد</option>
                  <option value="materialTypes">حسب نوع المادة</option>
                  <option value="ageCategories">حسب الفئة العمرية</option>
                  <option value="manufacturers">حسب الشركة المصنعة</option>
                  <option value="sizeRanges">حسب القياس</option>
                  <option value="countryOfOrigins">حسب بلد المنشأ</option>
                  <option value="storeGuids">حسب المخزن</option>
                  <option value="groupGuids">حسب المجموعة</option>
                </select>
                <span class="text-[11px] text-text-muted mt-1 block">مع التقسيم: أضف تشيبات في الفلتر المطابق أولاً.</span>
              </label>
            </div>
          </details>
        </div>

        <div class="dash-mi-zip-form__footer">
          <p class="dash-mi-zip-summary" data-zip-filter-summary aria-live="polite"></p>
          <div data-zip-download-status class="hidden text-sm rounded-lg border px-3 py-2"></div>
          <p class="dash-mi-zip-hint text-[11px] text-text-muted">يُفضّل تحديد فلتر واحد على الأقل (بحث، نوع، شركة، …) لتجنّب تحميل آلاف الصور دفعة واحدة.</p>
          <button type="submit" class="dash-mi-zip-download-btn">
            <span class="material-symbols-outlined" aria-hidden="true">download</span>
            تحميل ZIP
          </button>
        </div>
      </form>
    </section>

    <section class="rounded-xl border border-border-subtle bg-white overflow-hidden h-fit">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold text-sm">تحميل صور فاتورة</h2>
        <p class="text-xs text-text-muted mt-0.5">نوع الفاتورة + الرقم</p>
      </div>
      <form class="p-4 space-y-3" method="get" action="/api/material-images-zip.php" target="_blank">
        <input type="hidden" name="mode" value="invoice">

        <label class="block text-sm">
          <span class="dash-mi-zip-label">نوع الفاتورة</span>
          <select name="typeGuid" class="dash-mi-zip-input" required>
            <option value="">اختر النوع</option>
            <?php foreach ($invoiceTypes as $type): ?>
              <?php if (!is_array($type)) continue; ?>
              <option value="<?= h((string) ($type['guid'] ?? $type['typeGuid'] ?? '')) ?>">
                <?= h((string) ($type['name'] ?? $type['typeName'] ?? $type['code'] ?? 'نوع')) ?>
                <?php if (!empty($type['count'])): ?> (<?= (int) $type['count'] ?>)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="dash-mi-zip-label">رقم الفاتورة</span>
          <input type="number" name="number" min="1" required class="dash-mi-zip-input" placeholder="1523">
        </label>

        <?php if (!empty($invoiceTypesError)): ?>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $invoiceTypesError) ?></p>
        <?php endif; ?>

        <button type="submit" class="dash-mi-zip-download-btn dash-mi-zip-download-btn--secondary w-full justify-center">
          <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
          تحميل صور الفاتورة
        </button>
      </form>
    </section>
  </div>
</div>
