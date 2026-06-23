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
  <p class="mb-6 text-sm text-text-muted max-w-3xl leading-relaxed">
    حمّل صور الأصناف كملف ZIP مضغوط — مناسب للمشاركة على واتساب. يُبنى الملف على السيرفر ثم يُرسل للمتصفح مع <strong>شريط تقدم</strong> (بفضل معرفة الحجم مسبقاً).
  </p>

  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden xl:col-span-2">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold">تحميل حسب فلاتر المواد</h2>
        <p class="text-xs text-text-muted mt-1">فلاتر متقدمة مع تشيبس — مثل العروض الخاصة وأقسام الرئيسية</p>
      </div>
      <form class="p-4 space-y-4" method="get" action="/api/material-images-zip.php" target="_blank" data-material-zip-form>
        <input type="hidden" name="mode" value="materials">

        <label class="block text-sm max-w-xl">
          <span class="font-bold text-slate-700">بحث</span>
          <input type="search" name="search" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="رمز أو اسم المادة">
        </label>

        <?php if (!empty($materialFilterOptionsError)): ?>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $materialFilterOptionsError) ?></p>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div class="md:col-span-2"><?php $renderTokenPicker('نوع المادة', 'materialTypes[]', $toOptionObjects($materialTypeOptions), [], 'mid-material-types', true, false, false, 5); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('الفئة العمرية', 'ageCategories[]', $toOptionObjects($ageCategoryOptions), [], 'mid-age-categories', true, false, false, 5); ?></div>
          <div><?php $renderTokenPicker('الشركة المصنعة', 'manufacturers[]', $toOptionObjects($manufacturerOptions), [], 'mid-manufacturers', true, false, false, 5); ?></div>
          <div><?php $renderTokenPicker('القياس', 'sizeRanges[]', $toOptionObjects($sizeRangeOptions), [], 'mid-size-ranges', true, false, false, 5); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('بلد المنشأ', 'countryOfOrigins[]', $toOptionObjects($countryOriginOptions), [], 'mid-country-origins', true, false, false, 5); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المخازن', 'storeGuids[]', $storeOptionObjects, [], 'mid-store-guids', false, false, false, 5); ?></div>
          <div class="md:col-span-2"><?php $renderTokenPicker('المجموعات', 'groupGuids[]', $groupOptionObjects, [], 'mid-group-guids', false, false, false, 5); ?></div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <label class="block text-sm">
            <span class="font-bold text-slate-700">التوفر</span>
            <select name="isAvailable" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">بدون قيد</option>
              <option value="1">متوفر</option>
              <option value="0">غير متوفر</option>
            </select>
          </label>
          <label class="block text-sm">
            <span class="font-bold text-slate-700">أدنى مخزون</span>
            <input type="number" step="0.01" min="0" name="minWarehouseQuantity" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="0">
          </label>
          <label class="block text-sm">
            <span class="font-bold text-slate-700">أعلى مخزون</span>
            <input type="number" step="0.01" min="0" name="maxWarehouseQuantity" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="—">
          </label>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="block text-sm md:col-span-2 max-w-md">
            <span class="font-bold text-slate-700">تقسيم التحميل (اختياري)</span>
            <select name="splitBy" data-zip-split-by class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
              <option value="">ملف ZIP واحد لكل النتائج</option>
              <option value="materialTypes">تقسيم حسب نوع المادة</option>
              <option value="ageCategories">تقسيم حسب الفئة العمرية</option>
              <option value="manufacturers">تقسيم حسب الشركة المصنعة</option>
              <option value="sizeRanges">تقسيم حسب القياس</option>
              <option value="countryOfOrigins">تقسيم حسب بلد المنشأ</option>
              <option value="storeGuids">تقسيم حسب المخزن</option>
              <option value="groupGuids">تقسيم حسب المجموعة</option>
            </select>
            <span class="text-xs text-text-muted mt-1 block">يُحمَّل ملف <strong>split-material-images.zip</strong> يحتوي عدة ملفات ZIP داخلية — ملف لكل تشيب في الفلتر المختار.</span>
          </label>
        </div>

        <div data-zip-download-status class="hidden text-sm rounded-lg border px-3 py-2"></div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
          <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2">
            <span class="material-symbols-outlined text-lg">download</span>
            تحميل ZIP للنتائج
          </button>
          <p class="text-xs text-text-muted">بدون تقسيم: ملف واحد. مع التقسيم: أرشيف رئيسي فيه عدة ZIP — فك الضغط لاستخراجها.</p>
        </div>
      </form>
    </section>

    <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold">تحميل صور فاتورة أمين</h2>
        <p class="text-xs text-text-muted mt-1">حدّد نوع الفاتورة ورقمها لسحب صور أصنافها — مثالي للواتساب</p>
      </div>
      <form class="p-4 space-y-3" method="get" action="/api/material-images-zip.php" target="_blank">
        <input type="hidden" name="mode" value="invoice">

        <label class="block text-sm">
          <span class="font-bold text-slate-700">نوع الفاتورة</span>
          <select name="typeGuid" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" required>
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
          <span class="font-bold text-slate-700">رقم الفاتورة</span>
          <input type="number" name="number" min="1" required class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: 1523">
        </label>

        <?php if (!empty($invoiceTypesError)): ?>
          <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2"><?= h((string) $invoiceTypesError) ?></p>
        <?php endif; ?>

        <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2">
          <span class="material-symbols-outlined text-lg">receipt_long</span>
          تحميل صور الفاتورة
        </button>
      </form>
    </section>

    <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
      <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
        <h2 class="font-bold">تحميل سريع</h2>
      </div>
      <div class="p-4 flex flex-wrap gap-3">
        <a href="/api/material-images-zip.php?mode=linked&amp;linked=true" target="_blank" class="h-10 px-4 rounded-xl border border-border-subtle bg-white text-sm font-bold inline-flex items-center gap-2 hover:bg-surface-low">
          <span class="material-symbols-outlined text-lg">link</span>
          كل الصور المرتبطة
        </a>
        <a href="/api/material-images-zip.php?mode=linked&amp;linked=false" target="_blank" class="h-10 px-4 rounded-xl border border-border-subtle bg-white text-sm font-bold inline-flex items-center gap-2 hover:bg-surface-low">
          <span class="material-symbols-outlined text-lg">link_off</span>
          الصور غير المرتبطة
        </a>
      </div>
    </section>
  </div>
</div>
