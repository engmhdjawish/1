<?php

declare(strict_types=1);

/** @var array<string, mixed> $materialFilterOptions */
/** @var string|null $materialFilterOptionsError */
/** @var list<array<string, mixed>> $invoiceTypes */
/** @var string|null $invoiceTypesError */

require __DIR__ . '/../../partials/store-filter-group.php';

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

$materialTypeOptions = $toGroupOptions(array_values(array_unique(array_map('strval', $materialFilterOptions['materialTypes'] ?? []))));
$ageCategoryOptions = $toGroupOptions(array_values(array_unique(array_map('strval', $materialFilterOptions['ageCategories'] ?? []))));
$manufacturerOptions = $toGroupOptions(array_values(array_unique(array_map('strval', $materialFilterOptions['manufacturers'] ?? []))));
$sizeRangeOptions = $toGroupOptions(array_values(array_unique(array_map('strval', $materialFilterOptions['sizeRanges'] ?? []))));
$countryOriginOptions = $toGroupOptions(array_values(array_unique(array_map('strval', $materialFilterOptions['countryOfOrigins'] ?? []))));

$storeGroupOptions = [];
foreach ($materialFilterOptions['stores'] ?? [] as $store) {
    if (!is_array($store)) {
        continue;
    }
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
foreach ($materialFilterOptions['groups'] ?? [] as $group) {
    if (!is_array($group)) {
        continue;
    }
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') {
        continue;
    }
    $groupGroupOptions[] = [
        'value' => $guid,
        'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid,
    ];
}

$defaultAvailability = '1';
?>
<div data-material-images-download-panel>
  <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    <section class="rounded-xl border border-border-subtle bg-white overflow-hidden xl:col-span-2">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold text-sm">تحميل ZIP حسب فلاتر المواد</h2>
        <p class="text-xs text-text-muted mt-0.5">من ملفات الموقع المحلية فقط — حدّد الفلاتر ثم حمّل</p>
      </div>

      <div
        class="dash-mi-zip-filters-shell"
        data-store-filters-root
        data-store-filters-static="1"
        data-store-filters-default-availability="<?= h($defaultAvailability) ?>"
      >
        <form
          class="dash-mi-zip-form"
          method="get"
          action="/api/material-images-zip.php"
          target="_blank"
          data-material-zip-form
          data-store-filters-form
        >
          <input type="hidden" name="mode" value="materials">

          <div class="dash-mi-zip-form__body">
            <?php if (!empty($materialFilterOptionsError)): ?>
              <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $materialFilterOptionsError) ?></p>
            <?php endif; ?>

            <div class="store-inline-field dash-mi-zip-search-field">
              <label for="zip-material-search">بحث</label>
              <input
                id="zip-material-search"
                type="search"
                name="search"
                placeholder="رمز أو اسم المادة"
                autocomplete="off"
              >
            </div>

            <div class="store-filter-pending-panel" id="store-filter-pending-panel" aria-live="polite">
              <div class="store-filter-pending-panel-head">
                <span class="store-filter-pending-panel-title">اختياراتك</span>
                <button type="button" class="store-filter-pending-clear-all" id="store-filter-pending-clear-all" hidden>مسح</button>
              </div>
              <div class="store-filter-pending-chips" id="store-filter-pending-chips-global"></div>
            </div>

            <div class="dash-mi-zip-filters-stack store-filter-chip-layout">
              <section class="store-filter-chip-section" data-filter-group="availability">
                <div class="store-filter-chip-section-head">
                  <span class="store-filter-chip-section-heading">
                    <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">inventory_2</span>
                    <span class="store-filter-chip-section-label">التوفر</span>
                  </span>
                </div>
                <div class="store-filter-chip-section-body">
                  <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                    <?php foreach (['' => 'الكل', '1' => 'متوفر', '0' => 'غير متوفر'] as $value => $label): ?>
                      <?php $isActive = $defaultAvailability === (string) $value; ?>
                      <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive && $value !== '' ? ' is-selected' : '' ?><?= $isActive && $value === '' ? ' is-selected is-neutral' : '' ?>">
                        <input type="radio" name="isAvailable" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                        <span class="store-filter-option-text"><?= h($label) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              </section>

              <?php
                $chipFiltersExpanded = true;
                $renderStoreFilterGroup('materialTypes', 'نوع المادة', $materialTypeOptions, [], 'materialTypes', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('ageCategories', 'الفئة العمرية', $ageCategoryOptions, [], 'ageCategories', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('manufacturers', 'الشركة المصنعة', $manufacturerOptions, [], 'manufacturers', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('sizeRanges', 'القياس', $sizeRangeOptions, [], 'sizeRanges', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('countryOfOrigins', 'بلد المنشأ', $countryOriginOptions, [], 'countryOfOrigins', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('storeGuids', 'المخازن', $storeGroupOptions, [], 'stores', 5, 8, true, $chipFiltersExpanded);
                $renderStoreFilterGroup('groupGuids', 'المجموعات', $groupGroupOptions, [], 'groups', 5, 8, true, $chipFiltersExpanded);
              ?>

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
                      <label for="zip-min-warehouse">من</label>
                      <input id="zip-min-warehouse" type="number" step="0.01" min="0" name="minWarehouseQuantity" placeholder="0">
                    </div>
                    <div class="store-inline-field mb-0">
                      <label for="zip-max-warehouse">إلى</label>
                      <input id="zip-max-warehouse" type="number" step="0.01" min="0" name="maxWarehouseQuantity" placeholder="—">
                    </div>
                  </div>
                </div>
              </section>

              <section class="store-filter-chip-section" data-filter-group="splitBy">
                <div class="store-filter-chip-section-head">
                  <span class="store-filter-chip-section-heading">
                    <span class="material-symbols-outlined store-filter-chip-section-icon" aria-hidden="true">folder_zip</span>
                    <span class="store-filter-chip-section-label">تقسيم ZIP</span>
                  </span>
                </div>
                <div class="store-filter-chip-section-body">
                  <div class="store-filter-options store-filter-options--pills store-filter-options--radio">
                    <?php
                      $splitOptions = [
                          '' => 'ملف ZIP واحد',
                          'materialTypes' => 'حسب نوع المادة',
                          'ageCategories' => 'حسب الفئة العمرية',
                          'manufacturers' => 'حسب الشركة المصنعة',
                          'sizeRanges' => 'حسب القياس',
                          'countryOfOrigins' => 'حسب بلد المنشأ',
                          'storeGuids' => 'حسب المخزن',
                          'groupGuids' => 'حسب المجموعة',
                      ];
                    ?>
                    <?php foreach ($splitOptions as $value => $label): ?>
                      <?php $isActive = $value === ''; ?>
                      <label class="store-filter-option store-filter-pill store-filter-pill--radio<?= $isActive ? ' is-selected is-neutral' : '' ?>">
                        <input type="radio" name="splitBy" value="<?= h((string) $value) ?>" <?= $isActive ? 'checked' : '' ?>>
                        <span class="store-filter-option-text"><?= h($label) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <p class="text-[11px] text-text-muted mt-2 mb-0">مع التقسيم: أضف chip واحداً على الأقل في الفلتر المطابق.</p>
                </div>
              </section>
            </div>
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
      </div>
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
