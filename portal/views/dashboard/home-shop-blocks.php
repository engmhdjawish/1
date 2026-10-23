<?php

declare(strict_types=1);

use Portal\Services\HomeShopBlockService;

/** @var list<array<string, mixed>> $blocks */
/** @var array<string, mixed>|null $editBlock */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $tableReady */
/** @var bool $usingDefaults */
/** @var array<string, list<string>> $facetOptions */
/** @var string|null $facetOptionsError */
/** @var list<string> $hiddenFacets */
/** @var string|null $flash */
/** @var string $flashType */

require __DIR__ . '/partials/token-picker.php';
require __DIR__ . '/partials/media-picker.php';

$facets = HomeShopBlockService::FACETS;
$inputClass = 'h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary';
$selectClass = 'h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary';
$btnLight = 'h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50';

$optionObjects = static function (array $values, array $selected): array {
    $result = [];
    foreach (array_values(array_unique(array_merge($values, $selected))) as $value) {
        $result[] = ['value' => $value, 'label' => $value];
    }

    return $result;
};

$describe = static function (array $block) use ($facets): string {
    $primary = $facets[$block['primary_param']] ?? '';
    $secondary = $facets[$block['secondary_param']] ?? '';

    return match ($block['block_type']) {
        'combo' => $primary . ' + ' . $secondary,
        'custom' => count($block['tiles']) . ' بطاقة',
        default => $primary,
    };
};
$valuesSummary = static function (array $block): string {
    if ($block['block_type'] === 'custom') {
        return implode('، ', array_slice(array_column($block['tiles'], 'label'), 0, 4));
    }
    $count = count($block['primary_values']);

    return $count > 0 ? $count . ' قيمة مختارة' : 'كل القيم';
};

$type = (string) ($editBlock['block_type'] ?? 'facet');
$primaryParam = (string) ($editBlock['primary_param'] ?? '');
$secondaryParam = (string) ($editBlock['secondary_param'] ?? '');
$tiles = is_array($editBlock['tiles'] ?? null) ? $editBlock['tiles'] : [];
$tileRows = min(HomeShopBlockService::MAX_CUSTOM_TILES, count($tiles) + 6);
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">تسوّق حسب</h1>
    <p class="text-sm text-text-muted mt-1">
      مجموعات الوصول السريع في الصفحة الرئيسية: حسب الفئة أو النوع أو الشركة، أو دمج خاصيتين مثل «الشركة + الفئة»، أو بطاقات مخصّصة بفلاتر متعددة.
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <?php if (!$showForm): ?>
      <a href="/index.php" target="_blank" class="<?= $btnLight ?>">معاينة الرئيسية</a>
      <a href="/dashboard/home-sections.php" class="<?= $btnLight ?>">أقسام الرئيسية</a>
      <?php if ($tableReady): ?>
        <a href="/dashboard/home-shop-blocks.php?new=1" class="h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">مجموعة جديدة</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!$tableReady): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
    جدول المجموعات غير موجود بعد. شغّل ترحيل قاعدة البيانات (<code>PORTAL_DB_SETUP=migrate</code>) ثم أعد فتح الصفحة. حتى ذلك الحين تعرض الرئيسية المجموعات التلقائية.
  </p>
<?php endif; ?>

<?php if ($hiddenFacets !== []): ?>
  <p class="mb-4 rounded-xl border border-slate-200 bg-slate-50 text-slate-700 px-4 py-3 text-sm">
    مخفية في سياسة وصول المتجر، فلن تظهر المجموعات التي تعتمد عليها: <strong><?= h(implode('، ', $hiddenFacets)) ?></strong>.
  </p>
<?php endif; ?>

<?php if ($facetOptionsError): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-xs"><?= h($facetOptionsError) ?></p>
<?php endif; ?>

<?php if ($showForm && $editBlock !== null): ?>
<?php if ($editId !== ''): ?>
  <form method="post" id="hsb-delete-form" class="hidden" data-dashboard-confirm="حذف هذه المجموعة؟">
    <input type="hidden" name="action" value="delete_block">
    <input type="hidden" name="id" value="<?= h($editId) ?>">
  </form>
<?php endif; ?>

<form method="post" id="home-shop-block-form" data-dashboard-explicit-save data-shop-block-form class="space-y-3 mb-6">
  <input type="hidden" name="action" value="save_block">
  <input type="hidden" name="id" value="<?= h($editId) ?>">

  <div class="dashboard-sticky-toolbar sticky z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل المجموعة' : 'مجموعة جديدة' ?></h2>
    <div class="flex flex-wrap items-center gap-2">
      <a href="/dashboard/home-shop-blocks.php" class="<?= $btnLight ?>">إلغاء</a>
      <?php if ($editId !== ''): ?>
        <button type="submit" form="hsb-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
      <?php endif; ?>
      <button type="submit" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ</button>
    </div>
  </div>

  <article class="bg-white border border-border-subtle rounded-xl p-3">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
      <label class="text-xs md:col-span-2">
        <span class="text-text-muted block mb-0.5">العنوان (يظهر على التبويب) *</span>
        <input name="title_ar" required maxlength="200" value="<?= h((string) $editBlock['title_ar']) ?>" class="<?= $inputClass ?>" placeholder="الشركة">
      </label>
      <label class="text-xs md:col-span-2">
        <span class="text-text-muted block mb-0.5">نوع المجموعة</span>
        <select name="block_type" class="<?= $selectClass ?>" data-shop-type>
          <?php foreach (HomeShopBlockService::TYPES as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">الشكل</span>
        <select name="style" class="<?= $selectClass ?>">
          <?php foreach (HomeShopBlockService::STYLES as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $editBlock['style'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">المكان في الرئيسية</span>
        <select name="placement" class="<?= $selectClass ?>">
          <?php foreach (HomeShopBlockService::PLACEMENTS as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $editBlock['placement'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">عدد العناصر قبل «عرض الكل»</span>
        <input type="number" min="2" max="60" name="max_items" value="<?= (int) $editBlock['max_items'] ?>" class="<?= $inputClass ?>">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">الترتيب</span>
        <input type="number" min="0" name="sort_order" value="<?= (int) $editBlock['sort_order'] ?>" class="<?= $inputClass ?>">
      </label>
      <label class="text-xs inline-flex items-center gap-1.5 md:col-span-4">
        <input type="checkbox" name="is_active" <?= $editBlock['is_active'] ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
        <span>نشط</span>
      </label>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3 space-y-3" data-shop-panel="facet combo" <?= $type === 'custom' ? 'hidden' : '' ?>>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div class="space-y-2">
        <label class="text-xs block">
          <span class="text-text-muted block mb-0.5">الخاصية الأولى</span>
          <select name="primary_param" class="<?= $selectClass ?>" data-shop-param="primary">
            <?php foreach ($facets as $param => $label): ?>
              <option value="<?= h($param) ?>" <?= $primaryParam === $param ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="text-[11px] text-text-muted">اترك القيم فارغة لعرض كل القيم تلقائياً، أو اختر قيماً محددة بالترتيب الذي تريده.</p>
        <?php foreach ($facets as $param => $label): ?>
          <?php $selected = $primaryParam === $param ? $editBlock['primary_values'] : []; ?>
          <div data-shop-values="primary" data-param="<?= h($param) ?>" <?= $primaryParam === $param ? '' : 'hidden' ?>>
            <?php $renderTokenPicker('قيم ' . $label, 'primary_values[' . $param . '][]', $optionObjects($facetOptions[$param] ?? [], $selected), $selected, 'hsb-primary-' . $param, true, false, false, 5); ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="space-y-2" data-shop-panel="combo" <?= $type === 'combo' ? '' : 'hidden' ?>>
        <label class="text-xs block">
          <span class="text-text-muted block mb-0.5">الخاصية الثانية</span>
          <select name="secondary_param" class="<?= $selectClass ?>" data-shop-param="secondary">
            <?php foreach ($facets as $param => $label): ?>
              <option value="<?= h($param) ?>" <?= $secondaryParam === $param || ($secondaryParam === '' && $param === 'ageCategories') ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="text-[11px] text-text-muted">يختار الزبون قيمة من الخاصية الأولى ثم تظهر له قيم الثانية، وكل بطاقة تفتح المتجر بالفلترين معاً.</p>
        <?php $activeSecondary = $secondaryParam !== '' ? $secondaryParam : 'ageCategories'; ?>
        <?php foreach ($facets as $param => $label): ?>
          <?php $selected = $secondaryParam === $param ? $editBlock['secondary_values'] : []; ?>
          <div data-shop-values="secondary" data-param="<?= h($param) ?>" <?= $activeSecondary === $param ? '' : 'hidden' ?>>
            <?php $renderTokenPicker('قيم ' . $label, 'secondary_values[' . $param . '][]', $optionObjects($facetOptions[$param] ?? [], $selected), $selected, 'hsb-secondary-' . $param, true, false, false, 5); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-xl p-3 space-y-2" data-shop-panel="custom" <?= $type === 'custom' ? '' : 'hidden' ?>>
    <h3 class="font-bold text-sm">البطاقات</h3>
    <p class="text-[11px] text-text-muted">كل بطاقة تجمع أي عدد من الفلاتر، مثل «بناتي شتوي من شركة كذا». البطاقة بلا اسم أو بلا فلتر لا تُحفظ.</p>
    <div class="space-y-2" data-shop-tiles>
      <?php for ($i = 0; $i < $tileRows; $i++): ?>
        <?php
          $tile = $tiles[$i] ?? ['label' => '', 'image' => '', 'filters' => [], 'available' => false];
          $isBlank = !isset($tiles[$i]);
          $showRow = !$isBlank || $i === count($tiles);
        ?>
        <fieldset class="rounded-xl border border-border-subtle bg-surface-low p-2.5" data-shop-tile <?= $showRow ? '' : 'hidden' ?>>
          <div class="grid grid-cols-2 md:grid-cols-6 gap-2">
            <label class="text-xs col-span-2 md:col-span-2">
              <span class="text-text-muted block mb-0.5">اسم البطاقة</span>
              <input name="tiles[<?= $i ?>][label]" maxlength="80" value="<?= h((string) $tile['label']) ?>" class="<?= $inputClass ?>">
            </label>
            <?php foreach ($facets as $param => $label): ?>
              <?php $current = (string) ($tile['filters'][$param] ?? ''); ?>
              <label class="text-xs">
                <span class="text-text-muted block mb-0.5"><?= h($label) ?></span>
                <select name="tiles[<?= $i ?>][filters][<?= h($param) ?>]" class="<?= $selectClass ?>">
                  <option value="">—</option>
                  <?php foreach (array_values(array_unique(array_merge($facetOptions[$param] ?? [], $current !== '' ? [$current] : []))) as $value): ?>
                    <option value="<?= h($value) ?>" <?= $current === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endforeach; ?>
            <label class="text-xs inline-flex items-center gap-1.5 col-span-2 md:col-span-1 md:pt-5">
              <input type="checkbox" name="tiles[<?= $i ?>][available]" value="1" <?= !empty($tile['available']) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
              <span>المتوفر فقط</span>
            </label>
            <div class="col-span-2 md:col-span-5">
              <?php $renderMediaPickerField('صورة (اختياري)', 'tiles[' . $i . '][image]', (string) $tile['image'], 'hsb-tile-image-' . $i, 'banner'); ?>
            </div>
          </div>
        </fieldset>
      <?php endfor; ?>
    </div>
    <?php if ($tileRows > count($tiles) + 1): ?>
      <button type="button" class="<?= $btnLight ?>" data-shop-tile-add>إضافة بطاقة</button>
    <?php endif; ?>
  </article>
</form>
<?php endif; ?>

<?php if ($usingDefaults): ?>
  <section class="mb-4 bg-white border border-border-subtle rounded-xl p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="font-bold text-sm">تعرض الرئيسية الآن المجموعات التلقائية</h2>
        <p class="text-xs text-text-muted mt-1">
          <?= h(implode('، ', array_column(HomeShopBlockService::defaultBlocks(), 'title_ar'))) ?>
        </p>
      </div>
      <?php if ($tableReady): ?>
        <form method="post">
          <input type="hidden" name="action" value="seed_defaults">
          <button class="h-9 px-4 rounded-lg bg-slate-800 text-white text-xs font-extrabold hover:bg-slate-900">نسخها للتعديل</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($blocks !== []): ?>
<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <div class="overflow-auto">
    <table class="w-full min-w-[760px] text-sm">
      <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
        <tr>
          <th class="px-4 py-3 text-right font-bold">العنوان</th>
          <th class="px-4 py-3 text-right font-bold">المحتوى</th>
          <th class="px-4 py-3 text-right font-bold">الشكل</th>
          <th class="px-4 py-3 text-right font-bold">المكان</th>
          <th class="px-4 py-3 text-right font-bold">الترتيب</th>
          <th class="px-4 py-3 text-right font-bold">الحالة</th>
          <th class="px-4 py-3 text-left font-bold">إجراءات</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php foreach ($blocks as $block): ?>
          <tr class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <div class="font-bold"><?= h($block['title_ar']) ?></div>
              <div class="text-xs text-text-muted"><?= h(HomeShopBlockService::TYPES[$block['block_type']] ?? '') ?></div>
            </td>
            <td class="px-4 py-3">
              <div><?= h($describe($block)) ?></div>
              <div class="text-xs text-text-muted"><?= h($valuesSummary($block)) ?></div>
            </td>
            <td class="px-4 py-3"><?= h(HomeShopBlockService::STYLES[$block['style']] ?? '') ?></td>
            <td class="px-4 py-3"><?= $block['placement'] === 'band' ? 'شريط مستقل' : 'تبويب' ?></td>
            <td class="px-4 py-3"><?= (int) $block['sort_order'] ?></td>
            <td class="px-4 py-3">
              <?= $block['is_active'] ? '<span class="text-emerald-700 font-bold text-xs">نشط</span>' : '<span class="text-xs text-slate-500">متوقف</span>' ?>
            </td>
            <td class="px-4 py-3">
              <div class="flex justify-end gap-1.5 flex-wrap">
                <a href="/dashboard/home-shop-blocks.php?edit=<?= urlencode($block['id']) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">تعديل</a>
                <form method="post" data-dashboard-ajax data-dashboard-reload>
                  <input type="hidden" name="action" value="toggle_block">
                  <input type="hidden" name="id" value="<?= h($block['id']) ?>">
                  <input type="hidden" name="next_active" value="<?= $block['is_active'] ? '0' : '1' ?>">
                  <?php if ($block['is_active']): ?>
                    <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-slate-600 text-white hover:bg-slate-700">إيقاف</button>
                  <?php else: ?>
                    <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700">تفعيل</button>
                  <?php endif; ?>
                </form>
                <form method="post" data-dashboard-confirm="حذف المجموعة؟">
                  <input type="hidden" name="action" value="delete_block">
                  <input type="hidden" name="id" value="<?= h($block['id']) ?>">
                  <button class="h-8 px-3 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
