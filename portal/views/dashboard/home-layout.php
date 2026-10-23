<?php

declare(strict_types=1);

/** @var array{general: array<string, mixed>, blocks: list<array<string, mixed>>} $layout */
/** @var array<string, array<string, mixed>> $definitions */
/** @var bool $tableReady */
/** @var string|null $flash */
/** @var string $flashType */

$inputClass = 'h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary';
$selectClass = 'h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary';
$checkClass = 'rounded border-border-subtle text-primary focus:ring-primary';
$btnLight = 'h-9 px-4 inline-flex items-center gap-1 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50';
$general = $layout['general'];
$generalLabels = [
    'brand' => 'إظهار السطر التعريفي تحت اسم الشركة في الهيدر',
    'currency' => 'مبدّل العملة في الهيدر',
    'dock' => 'شريط التنقل السفلي في الموبايل',
];
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">تخطيط الرئيسية الجديدة</h1>
    <p class="text-sm text-text-muted mt-1">رتّب أقسام الصفحة، أظهرها أو أخفها، وعدّل عناوينها وخياراتها.</p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <a href="/index.php" target="_blank" class="<?= $btnLight ?>">
      <span class="material-symbols-outlined text-base" aria-hidden="true">open_in_new</span>
      معاينة
    </a>
    <a href="/dashboard/home-sections.php" class="<?= $btnLight ?>">أقسام الرئيسية</a>
    <a href="/dashboard/home-shop-blocks.php" class="<?= $btnLight ?>">تسوّق حسب</a>
    <a href="/guide.php" target="_blank" class="<?= $btnLight ?>">دليل الاستخدام</a>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!$tableReady): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
    جدول التخطيط غير موجود بعد. شغّل ترحيل قاعدة البيانات (<code>PORTAL_DB_SETUP=migrate</code>) ثم أعد فتح الصفحة.
  </p>
<?php endif; ?>

<form method="post" id="home-layout-reset" class="hidden" data-dashboard-confirm="إعادة الترتيب والعناوين الافتراضية؟">
  <input type="hidden" name="action" value="reset">
</form>

<form method="post" class="space-y-4" data-dashboard-explicit-save data-home-layout>
  <input type="hidden" name="action" value="save">

  <div class="dashboard-sticky-toolbar sticky z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
    <h2 class="font-bold text-base">ترتيب الصفحة</h2>
    <div class="flex flex-wrap items-center gap-2">
      <button type="submit" form="home-layout-reset" class="<?= $btnLight ?>" <?= $tableReady ? '' : 'disabled' ?>>الافتراضي</button>
      <button type="submit" name="preview" value="1" formaction="/dashboard/home-layout.php" formtarget="_blank" class="<?= $btnLight ?>">
        <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
        معاينة قبل الحفظ
      </button>
      <button type="submit" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110" <?= $tableReady ? '' : 'disabled' ?>>حفظ</button>
    </div>
  </div>

  <article class="rounded-2xl border border-border-subtle bg-white p-4 space-y-3">
    <h2 class="font-bold text-sm">أعلى الصفحة</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">سطر تحت اسم الشركة</span>
        <input name="general[tagline]" maxlength="120" value="<?= h((string) $general['tagline']) ?>" class="<?= $inputClass ?>" placeholder="أحذية وألبسة بالجملة">
      </label>
      <label class="text-xs">
        <span class="text-text-muted block mb-0.5">نص مربع البحث</span>
        <input name="general[search_placeholder]" maxlength="80" value="<?= h((string) $general['search_placeholder']) ?>" class="<?= $inputClass ?>">
      </label>
    </div>
    <div class="flex flex-wrap gap-x-5 gap-y-2">
      <?php foreach ($generalLabels as $name => $label): ?>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" name="general[<?= h($name) ?>]" class="<?= $checkClass ?>" <?= !empty($general[$name]) ? 'checked' : '' ?>>
          <span><?= h($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <fieldset class="space-y-1">
      <legend class="text-xs text-text-muted mb-1">أزرار الشريط السفلي (حتى <?= (int) \Portal\Services\HomeLayoutService::DOCK_MAX ?>)</legend>
      <div class="flex flex-wrap gap-x-4 gap-y-2" data-home-layout-dock>
        <?php foreach (\Portal\Services\HomeLayoutService::DOCK_ITEMS as $dockKey => $dockMeta): ?>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="general[dock_items][]" value="<?= h($dockKey) ?>" class="<?= $checkClass ?>" <?= in_array($dockKey, (array) $general['dock_items'], true) ? 'checked' : '' ?>>
            <span class="material-symbols-outlined text-base text-slate-500" aria-hidden="true"><?= h($dockMeta['icon']) ?></span>
            <span><?= h($dockMeta['label']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="text-xs text-text-muted">السلة تظهر فقط عند تفعيل السلة، وواتساب عند وجود رقم، والعروض عند وجود عرض فعّال.</p>
    </fieldset>
  </article>

  <ol class="space-y-2" data-home-layout-list>
    <?php foreach ($layout['blocks'] as $block): ?>
      <?php $definition = $definitions[$block['key']]; $key = $block['key']; ?>
      <li class="rounded-2xl border border-border-subtle bg-white" data-home-layout-row>
        <input type="hidden" name="order[]" value="<?= h($key) ?>">
        <div class="flex items-center gap-3 p-3">
          <span class="material-symbols-outlined text-slate-400 cursor-grab" aria-hidden="true" data-home-layout-handle>drag_indicator</span>
          <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-slate-100 text-slate-700">
            <span class="material-symbols-outlined" aria-hidden="true"><?= h($definition['icon']) ?></span>
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-extrabold text-sm text-slate-900"><?= h($definition['label']) ?></p>
            <?php if ($block['title'] !== ''): ?>
              <p class="text-xs text-text-muted truncate" data-home-layout-title-preview><?= h($block['title']) ?></p>
            <?php endif; ?>
          </div>
          <label class="flex items-center gap-2 text-xs font-bold">
            <input type="checkbox" name="on[<?= h($key) ?>]" class="<?= $checkClass ?>" <?= $block['on'] ? 'checked' : '' ?> data-home-layout-on>
            <span>ظاهر</span>
          </label>
          <div class="flex items-center gap-1">
            <button type="button" class="h-8 w-8 inline-flex items-center justify-center rounded-lg border border-border-subtle hover:bg-slate-50" data-home-layout-move="-1" aria-label="أعلى">
              <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_upward</span>
            </button>
            <button type="button" class="h-8 w-8 inline-flex items-center justify-center rounded-lg border border-border-subtle hover:bg-slate-50" data-home-layout-move="1" aria-label="أسفل">
              <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_downward</span>
            </button>
          </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 border-t border-border-subtle px-3 py-3">
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">الجهاز</span>
            <select name="device[<?= h($key) ?>]" class="<?= $selectClass ?>">
              <?php foreach (\Portal\Services\HomeLayoutService::DEVICES as $choice => $choiceLabel): ?>
                <option value="<?= h($choice) ?>" <?= ($block['device'] ?? 'all') === $choice ? 'selected' : '' ?>><?= h($choiceLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">لمن يظهر</span>
            <select name="audience[<?= h($key) ?>]" class="<?= $selectClass ?>">
              <?php foreach (\Portal\Services\HomeLayoutService::AUDIENCES as $choice => $choiceLabel): ?>
                <option value="<?= h($choice) ?>" <?= ($block['audience'] ?? 'all') === $choice ? 'selected' : '' ?>><?= h($choiceLabel) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">يظهر من تاريخ</span>
            <input type="date" name="from[<?= h($key) ?>]" value="<?= h((string) ($block['from'] ?? '')) ?>" class="<?= $inputClass ?>">
          </label>
          <label class="text-xs">
            <span class="text-text-muted block mb-0.5">حتى تاريخ</span>
            <input type="date" name="to[<?= h($key) ?>]" value="<?= h((string) ($block['to'] ?? '')) ?>" class="<?= $inputClass ?>">
          </label>
        </div>
        <?php if ($definition['title'] !== '' || $definition['options'] !== []): ?>
          <div class="grid grid-cols-1 md:grid-cols-4 gap-3 border-t border-border-subtle px-3 py-3 bg-slate-50 rounded-b-2xl">
            <?php if ($definition['title'] !== ''): ?>
              <label class="text-xs md:col-span-2">
                <span class="text-text-muted block mb-0.5">العنوان</span>
                <input name="title[<?= h($key) ?>]" maxlength="80" value="<?= h($block['title']) ?>" class="<?= $inputClass ?> bg-white" placeholder="<?= h($definition['title']) ?>" data-home-layout-title>
              </label>
            <?php endif; ?>
            <?php foreach ($definition['options'] as $name => $spec): ?>
              <?php $value = $block['options'][$name]; $field = 'opt[' . $key . '][' . $name . ']'; ?>
              <?php if ($spec['type'] === 'bool'): ?>
                <label class="flex items-center gap-2 text-sm self-end h-9">
                  <input type="checkbox" name="<?= h($field) ?>" class="<?= $checkClass ?>" <?= $value ? 'checked' : '' ?>>
                  <span><?= h($spec['label']) ?></span>
                </label>
              <?php elseif ($spec['type'] === 'int'): ?>
                <label class="text-xs">
                  <span class="text-text-muted block mb-0.5"><?= h($spec['label']) ?></span>
                  <input type="number" name="<?= h($field) ?>" min="<?= (int) $spec['min'] ?>" max="<?= (int) $spec['max'] ?>" value="<?= (int) $value ?>" class="<?= $inputClass ?> bg-white">
                </label>
              <?php elseif ($spec['type'] === 'choice'): ?>
                <label class="text-xs md:col-span-2">
                  <span class="text-text-muted block mb-0.5"><?= h($spec['label']) ?></span>
                  <select name="<?= h($field) ?>" class="<?= $selectClass ?> bg-white">
                    <?php foreach ($spec['choices'] as $choice => $choiceLabel): ?>
                      <option value="<?= h((string) $choice) ?>" <?= $value === $choice ? 'selected' : '' ?>><?= h($choiceLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</form>
