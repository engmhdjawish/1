<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $offers */
/** @var array<string, mixed> $editOffer */
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
$rules = is_array($editOffer['filter_rules'] ?? null) ? $editOffer['filter_rules'] : [];
$selectionMode = (string) ($editOffer['selection_mode'] ?? 'filter');
$discountType = (string) ($editOffer['discount_type'] ?? 'percent');
$selectedMaterialGuids = array_map('strval', $editOffer['material_guids'] ?? []);

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

$materialTypeOptions = array_values(array_unique(array_merge($materialFilterOptions['materialTypes'] ?? [], $rules['material_types'] ?? [])));
$ageCategoryOptions = array_values(array_unique(array_merge($materialFilterOptions['ageCategories'] ?? [], $rules['age_categories'] ?? [])));
$manufacturerOptions = array_values(array_unique(array_merge($materialFilterOptions['manufacturers'] ?? [], $rules['manufacturers'] ?? [])));
$sizeRangeOptions = array_values(array_unique(array_merge($materialFilterOptions['sizeRanges'] ?? [], $rules['size_ranges'] ?? [])));
$countryOriginOptions = array_values(array_unique(array_merge($materialFilterOptions['countryOfOrigins'] ?? [], $rules['country_origins'] ?? [])));

$storeOptionObjects = [];
foreach ($materialFilterOptions['stores'] ?? [] as $store) {
    if (!is_array($store)) continue;
    $guid = trim((string) ($store['guid'] ?? $store['Guid'] ?? ''));
    if ($guid === '') continue;
    $storeOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($store['name'] ?? $store['Name'] ?? '')) ?: $guid];
}
$groupOptionObjects = [];
foreach ($materialFilterOptions['groups'] ?? [] as $group) {
    if (!is_array($group)) continue;
    $guid = trim((string) ($group['guid'] ?? $group['Guid'] ?? ''));
    if ($guid === '') continue;
    $groupOptionObjects[] = ['value' => $guid, 'label' => trim((string) ($group['name'] ?? $group['Name'] ?? '')) ?: $guid];
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
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">العروض الخاصة</h1>
    <p class="text-sm text-text-muted mt-1">حسومات على مستوى الموقع فقط — نسبة أو سعر جديد — مع عرض قبل/بعد على الرئيسية والمتجر.</p>
    <p class="text-xs text-amber-700 mt-1">عند تضارب عروض على نفس المادة: يُطبَّق الأفضل للعميل (أقل سعر)، ثم الأعلى أولوية.</p>
  </div>
  <?php if (!$showForm): ?>
    <a href="/dashboard/special-offers.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold">عرض جديد</a>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if ($showForm): ?>
<form method="post" id="special-offer-form" data-dashboard-explicit-save class="space-y-3 mb-4">
  <input type="hidden" name="action" value="save_offer">
  <input type="hidden" name="id" value="<?= h((string) ($editOffer['id'] ?? '')) ?>">

  <div class="sticky top-16 z-20 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl px-3 py-2 flex flex-wrap justify-between gap-2">
    <h2 class="font-bold"><?= $editId !== '' ? 'تعديل العرض' : 'عرض جديد' ?></h2>
    <div class="flex gap-2">
      <a href="/dashboard/special-offers.php" class="h-9 px-4 inline-flex items-center rounded-lg border text-xs font-bold">إلغاء</a>
      <button type="submit" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold">حفظ العرض</button>
    </div>
  </div>

  <article class="bg-white border rounded-xl p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
    <label class="text-sm md:col-span-2">عنوان العرض *<input name="title_ar" required value="<?= h((string) ($editOffer['title_ar'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">Slug<input name="slug" value="<?= h((string) ($editOffer['slug'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">شارة (اختياري)<input name="badge_text_ar" value="<?= h((string) ($editOffer['badge_text_ar'] ?? '')) ?>" placeholder="-15%" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm md:col-span-2">وصف<input name="subtitle_ar" value="<?= h((string) ($editOffer['subtitle_ar'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">يبدأ<input type="datetime-local" name="starts_at" value="<?= h(substr((string) ($editOffer['starts_at'] ?? ''), 0, 16)) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">ينتهي (فارغ = بلا نهاية)<input type="datetime-local" name="ends_at" value="<?= h(substr((string) ($editOffer['ends_at'] ?? ''), 0, 16)) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">نوع الحسم
      <select name="discount_type" id="discount_type" class="h-10 w-full rounded-lg border px-2 mt-1">
        <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>نسبة مئوية</option>
        <option value="fixed_price" <?= $discountType === 'fixed_price' ? 'selected' : '' ?>>سعر طرد جديد</option>
      </select>
    </label>
    <label class="text-sm" id="field-percent">النسبة %<input type="number" step="0.01" min="0" max="100" name="discount_percent" value="<?= h((string) ($editOffer['discount_percent'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm hidden" id="field-syp">سعر الطرد ل.س<input type="number" step="0.01" min="0" name="fixed_price_syp" value="<?= h((string) ($editOffer['fixed_price_syp'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm hidden" id="field-usd">سعر الطرد $<input type="number" step="0.01" min="0" name="fixed_price_usd" value="<?= h((string) ($editOffer['fixed_price_usd'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">أولوية (أعلى = يفوز عند التعادل)<input type="number" name="priority" value="<?= h((string) ($editOffer['priority'] ?? '0')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">حد أدنى طرود/طلب<input type="number" step="0.01" min="0" name="min_packages" value="<?= h((string) ($editOffer['min_packages'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">حد أقصى طرود/طلب<input type="number" step="0.01" min="0" name="max_packages" value="<?= h((string) ($editOffer['max_packages'] ?? '')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">عدد المواد في الرئيسية<input type="number" min="1" max="48" name="max_products" value="<?= h((string) ($editOffer['max_products'] ?? '12')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <label class="text-sm">ترتيب الرئيسية<input type="number" name="home_sort_order" value="<?= h((string) ($editOffer['home_sort_order'] ?? '0')) ?>" class="h-10 w-full rounded-lg border px-3 mt-1"></label>
    <div class="md:col-span-2 flex flex-wrap gap-4 text-sm pt-1">
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" <?= !empty($editOffer['is_active']) ? 'checked' : '' ?>> نشط</label>
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="show_on_home" <?= !empty($editOffer['show_on_home']) ? 'checked' : '' ?>> عرض كقسم في الرئيسية</label>
    </div>
    <div class="md:col-span-2"><?php $renderMediaPickerField('بانر (اختياري)', 'banner_image_url', (string) ($editOffer['banner_image_url'] ?? ''), 'so-banner', 'banner'); ?></div>
  </article>

  <article class="bg-white border rounded-xl p-4">
    <label class="text-sm block mb-3">اختيار المواد
      <select name="selection_mode" id="selection_mode" class="h-10 w-full max-w-xs rounded-lg border px-2 mt-1">
        <option value="filter" <?= $selectionMode === 'filter' ? 'selected' : '' ?>>فلاتر API</option>
        <option value="manual" <?= $selectionMode === 'manual' ? 'selected' : '' ?>>مواد محددة</option>
      </select>
    </label>
    <div id="filter-panel" class="<?= $selectionMode === 'manual' ? 'hidden' : '' ?> space-y-2">
      <?php if ($materialFilterOptionsError): ?><p class="text-xs text-amber-700"><?= h($materialFilterOptionsError) ?></p><?php endif; ?>
      <label class="text-xs block">كلمة بحث<input name="filter_keyword" value="<?= h((string) ($rules['keyword'] ?? '')) ?>" class="h-9 w-full rounded-lg border px-3 mt-1"></label>
      <div class="grid md:grid-cols-2 gap-2">
        <div><?php $renderTokenPicker('نوع المادة', 'filter_material_types[]', $toOptionObjects($materialTypeOptions), $rules['material_types'] ?? [], 'so-mt', true, false, false, 4); ?></div>
        <div><?php $renderTokenPicker('الفئة العمرية', 'filter_age_categories[]', $toOptionObjects($ageCategoryOptions), $rules['age_categories'] ?? [], 'so-ac', true, false, false, 4); ?></div>
        <div><?php $renderTokenPicker('الشركة', 'filter_manufacturers[]', $toOptionObjects($manufacturerOptions), $rules['manufacturers'] ?? [], 'so-mf', true, false, false, 4); ?></div>
        <div><?php $renderTokenPicker('المجموعات', 'filter_group_guids[]', $groupOptionObjects, $rules['group_guids'] ?? [], 'so-gr', false, false, false, 4); ?></div>
      </div>
    </div>
    <div id="manual-panel" class="<?= $selectionMode === 'filter' ? 'hidden' : '' ?> mt-2">
      <?php $renderTokenPicker('المواد المشمولة', 'manual_material_guids[]', $manualPickerOptions, $selectedMaterialGuids, 'so-manual', false, true, true); ?>
    </div>
  </article>

  <?php if (!empty($editOffer['preview_products'])): ?>
    <details class="bg-white border rounded-xl p-4">
      <summary class="font-bold cursor-pointer">معاينة (<?= count($editOffer['preview_products']) ?>)</summary>
      <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
        <?php foreach ($editOffer['preview_products'] as $item): ?>
          <?php if (!is_array($item)) continue; ?>
          <div class="border rounded-lg p-2">
            <div class="font-bold line-clamp-2"><?= h((string) ($item['name'] ?? '')) ?></div>
            <?php if (!empty($item['has_offer'])): ?>
              <div class="text-gray-400 line-through"><?= format_money((float) ($item['original_package_sale_price_sp'] ?? 0), true) ?> ل.س</div>
              <div class="text-primary font-bold"><?= format_money((float) ($item['effective_package_sale_price_sp'] ?? 0), true) ?> ل.س</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
</form>
<?php portal_render_media_picker_modal(); ?>
<script>
(() => {
  const mode = document.getElementById('selection_mode');
  const fp = document.getElementById('filter-panel');
  const mp = document.getElementById('manual-panel');
  mode?.addEventListener('change', () => {
    const manual = mode.value === 'manual';
    fp?.classList.toggle('hidden', manual);
    mp?.classList.toggle('hidden', !manual);
  });
  const dt = document.getElementById('discount_type');
  const syncDiscount = () => {
    const fixed = dt?.value === 'fixed_price';
    document.getElementById('field-percent')?.classList.toggle('hidden', fixed);
    document.getElementById('field-syp')?.classList.toggle('hidden', !fixed);
    document.getElementById('field-usd')?.classList.toggle('hidden', !fixed);
  };
  dt?.addEventListener('change', syncDiscount);
  syncDiscount();
})();
</script>
<?php endif; ?>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($offers === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد عروض بعد.</p>
  <?php else: ?>
    <table class="w-full text-sm min-w-[800px]">
      <thead class="bg-surface-low border-b text-text-muted"><tr>
        <th class="px-4 py-3 text-right">العرض</th><th class="px-4 py-3 text-right">الحسم</th><th class="px-4 py-3 text-right">الفترة</th><th class="px-4 py-3 text-right">الحالة</th><th class="px-4 py-3 text-left">إجراءات</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($offers as $row): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3"><div class="font-bold"><?= h((string) ($row['title_ar'] ?? '')) ?></div><div class="text-xs text-text-muted"><?= h((string) ($row['slug'] ?? '')) ?></div></td>
            <td class="px-4 py-3 text-xs"><?= ($row['discount_type'] ?? '') === 'fixed_price' ? 'سعر جديد' : h((string) ($row['discount_percent'] ?? '')) . '%' ?></td>
            <td class="px-4 py-3 text-xs whitespace-nowrap"><?= h(substr((string) ($row['starts_at'] ?? ''), 0, 10)) ?> — <?= !empty($row['ends_at']) ? h(substr((string) $row['ends_at'], 0, 10)) : '∞' ?></td>
            <td class="px-4 py-3"><?= !empty($row['is_active']) ? '<span class="text-emerald-700 text-xs font-bold">نشط</span>' : '<span class="text-slate-500 text-xs">متوقف</span>' ?></td>
            <td class="px-4 py-3"><div class="flex justify-end gap-1">
              <a href="/dashboard/special-offers.php?edit=<?= urlencode((string) $row['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border text-xs font-bold">تعديل</a>
              <form method="post" data-dashboard-ajax data-dashboard-reload class="inline">
                <input type="hidden" name="action" value="toggle_offer"><input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                <input type="hidden" name="next_active" value="<?= !empty($row['is_active']) ? '0' : '1' ?>">
                <button class="dashboard-btn h-8 px-2 rounded-lg border text-xs"><?= !empty($row['is_active']) ? 'إيقاف' : 'تفعيل' ?></button>
              </form>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
