<?php

declare(strict_types=1);

/** @var array{general: array<string, mixed>, blocks: list<array<string, mixed>>} $layout */
/** @var array<string, array<string, mixed>> $definitions */
/** @var array{season_active: bool, season_title: string, season_reason: string, ads_count: int, shop_tabs: int, shop_bands: int} $editorStatus */
/** @var bool $tableReady */
/** @var string|null $flash */
/** @var string $flashType */

$inputClass = 'h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary';
$selectClass = 'h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary';
$checkClass = 'rounded border-border-subtle text-primary focus:ring-primary';
$btnLight = 'h-9 px-4 inline-flex items-center gap-1 rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50';
$general = $layout['general'];
$editorStatus = is_array($editorStatus ?? null) ? $editorStatus : \Portal\Services\HomeLayoutService::editorStatus();
$generalLabels = [
    'brand' => 'إظهار السطر التعريفي تحت اسم الشركة في الهيدر',
    'currency' => 'مبدّل العملة في الهيدر',
    'dock' => 'شريط التنقل السفلي في الموبايل',
];
$groupLabels = \Portal\Services\HomeLayoutService::GROUPS;
$statusFor = static function (string $key) use ($editorStatus): string {
    return match ($key) {
        'stories', 'season' => $editorStatus['season_active']
            ? ('الموسم الظاهر للزبائن: ' . ($editorStatus['season_title'] !== '' ? $editorStatus['season_title'] : 'نشط'))
            : $editorStatus['season_reason'],
        'ads' => $editorStatus['ads_count'] > 0
            ? ($editorStatus['ads_count'] . ' إعلان جاهز في مكتبة الوسائط')
            : 'لا صور بتصنيف «إعلان» بعد — ارفعها من مكتبة الوسائط وإلا لن يظهر الشريط.',
        'shop' => $editorStatus['shop_tabs'] > 0
            ? ($editorStatus['shop_tabs'] . ' مجموعة بموضع تبويب')
            : 'لا مجموعات بموضع «تبويب» — أنشئها من تسوّق حسب أو غيّر موضع مجموعة موجودة.',
        'bands' => $editorStatus['shop_bands'] > 0
            ? ($editorStatus['shop_bands'] . ' مجموعة بموضع شريط مستقل')
            : 'لا مجموعات بموضع «شريط مستقل» — من تسوّق حسب اختر الموضع عند الحفظ.',
        default => '',
    };
};
$statusOk = static function (string $key) use ($editorStatus): bool {
    return match ($key) {
        'stories', 'season' => (bool) $editorStatus['season_active'],
        'ads' => $editorStatus['ads_count'] > 0,
        'shop' => $editorStatus['shop_tabs'] > 0,
        'bands' => $editorStatus['shop_bands'] > 0,
        default => true,
    };
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-start gap-4 mb-6">
  <div class="min-w-0">
    <h1 class="text-2xl font-extrabold text-slate-900">تخطيط الصفحة الرئيسية</h1>
    <p class="text-sm text-text-muted mt-1 max-w-2xl">
      هذه الصفحة تضبط <strong>الترتيب والإظهار</strong> فقط.
      المحتوى (صور، أقسام، عروض، مجموعات) يُعدّ من الروابط بجانب كل قسم.
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <a href="/index.php" target="_blank" class="<?= $btnLight ?>">
      <span class="material-symbols-outlined text-base" aria-hidden="true">open_in_new</span>
      معاينة
    </a>
    <a href="/dashboard/home-sections.php" class="<?= $btnLight ?>">أقسام</a>
    <a href="/dashboard/special-offers.php" class="<?= $btnLight ?>">عروض</a>
    <a href="/dashboard/store-seasons.php" class="<?= $btnLight ?>">مواسم</a>
    <a href="/dashboard/home-shop-blocks.php" class="<?= $btnLight ?>">تسوّق حسب</a>
    <a href="/dashboard/site-media.php" class="<?= $btnLight ?>">وسائط</a>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!$tableReady): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
    جدول التخطيط غير موجود بعد. شغّل ترحيل قاعدة البيانات (<code>PORTAL_DB_SETUP=migrate</code>) ثم أعد فتح الصفحة.
  </p>
<?php endif; ?>

<div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
  <div class="rounded-xl border border-border-subtle bg-white px-4 py-3">
    <p class="text-xs font-bold text-slate-500 mb-1">الموسم للزبائن</p>
    <?php if ($editorStatus['season_active']): ?>
      <p class="font-bold text-emerald-800"><?= h($editorStatus['season_title'] !== '' ? $editorStatus['season_title'] : 'نشط') ?></p>
      <p class="text-xs text-text-muted mt-1">يظهر في دائرة الشريط وبطاقة التشكيلة إن فُعّلا أدناه.</p>
    <?php else: ?>
      <p class="font-bold text-amber-800">غير ظاهر</p>
      <p class="text-xs text-text-muted mt-1"><?= h($editorStatus['season_reason']) ?></p>
      <a href="/dashboard/store-seasons.php" class="text-xs font-bold text-primary mt-2 inline-block">ضبط مواسم العرض</a>
    <?php endif; ?>
  </div>
  <div class="rounded-xl border border-border-subtle bg-white px-4 py-3">
    <p class="text-xs font-bold text-slate-500 mb-1">الإعلانات المتحركة</p>
    <?php if ($editorStatus['ads_count'] > 0): ?>
      <p class="font-bold text-emerald-800"><?= (int) $editorStatus['ads_count'] ?> صورة إعلان</p>
      <p class="text-xs text-text-muted mt-1">ارفع المزيد من مكتبة الوسائط بتصنيف «إعلان».</p>
    <?php else: ?>
      <p class="font-bold text-amber-800">لا إعلانات بعد</p>
      <p class="text-xs text-text-muted mt-1">فعّل القسم أدناه وارفع صوراً بتصنيف إعلان — البانر لا يُستخدم هنا.</p>
      <a href="/dashboard/site-media.php" class="text-xs font-bold text-primary mt-2 inline-block">مكتبة الوسائط</a>
    <?php endif; ?>
  </div>
  <div class="rounded-xl border border-border-subtle bg-white px-4 py-3">
    <p class="text-xs font-bold text-slate-500 mb-1">تسوّق حسب</p>
    <p class="text-sm text-slate-800">
      <span class="font-bold"><?= (int) $editorStatus['shop_tabs'] ?></span> تبويب ·
      <span class="font-bold"><?= (int) $editorStatus['shop_bands'] ?></span> شريط مستقل
    </p>
    <p class="text-xs text-text-muted mt-1">التبويبات تجتمع في قسم واحد؛ الشريط المستقل يظهر لوحده بعنوان المجموعة.</p>
    <a href="/dashboard/home-shop-blocks.php" class="text-xs font-bold text-primary mt-2 inline-block">إدارة المجموعات</a>
  </div>
</div>

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
    <h2 class="font-bold text-sm">أعلى الصفحة (الهيدر والشريط السفلي)</h2>
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
    <?php
      $lastGroup = '';
      foreach ($layout['blocks'] as $block):
        $definition = $definitions[$block['key']];
        $key = $block['key'];
        $group = (string) ($definition['group'] ?? '');
        if ($group !== '' && $group !== $lastGroup && isset($groupLabels[$group])):
          $lastGroup = $group;
    ?>
      <li class="list-none pt-3 first:pt-0" data-home-layout-group aria-hidden="true">
        <p class="text-xs font-extrabold text-slate-500 tracking-wide px-1"><?= h($groupLabels[$group]) ?></p>
      </li>
    <?php endif; ?>
      <li class="rounded-2xl border border-border-subtle bg-white" data-home-layout-row>
        <input type="hidden" name="order[]" value="<?= h($key) ?>">
        <div class="flex items-center gap-3 p-3">
          <span class="material-symbols-outlined text-slate-400 cursor-grab" aria-hidden="true" data-home-layout-handle>drag_indicator</span>
          <span class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-slate-100 text-slate-700">
            <span class="material-symbols-outlined" aria-hidden="true"><?= h((string) $definition['icon']) ?></span>
          </span>
          <div class="min-w-0 flex-1">
            <p class="font-extrabold text-sm text-slate-900"><?= h((string) $definition['label']) ?></p>
            <?php if ((string) ($definition['help'] ?? '') !== ''): ?>
              <p class="text-xs text-text-muted mt-0.5"><?= h((string) $definition['help']) ?></p>
            <?php endif; ?>
            <?php if ((string) ($definition['source_href'] ?? '') !== ''): ?>
              <a href="<?= h((string) $definition['source_href']) ?>" class="text-xs font-bold text-primary mt-1 inline-flex items-center gap-0.5">
                المحتوى من: <?= h((string) ($definition['source_label'] !== '' ? $definition['source_label'] : $definition['source_href'])) ?>
                <span class="material-symbols-outlined text-sm" aria-hidden="true">chevron_left</span>
              </a>
            <?php endif; ?>
            <?php
              $note = $statusFor($key);
              if ($note !== ''):
                $ok = $statusOk($key);
            ?>
              <p class="text-xs mt-1 <?= $ok ? 'text-emerald-700' : 'text-amber-800' ?>"><?= h($note) ?></p>
            <?php endif; ?>
            <?php if ($block['title'] !== ''): ?>
              <p class="text-xs text-text-muted truncate mt-0.5" data-home-layout-title-preview>عنوان الزبون: <?= h($block['title']) ?></p>
            <?php endif; ?>
          </div>
          <label class="flex items-center gap-2 text-xs font-bold flex-none">
            <input type="checkbox" name="on[<?= h($key) ?>]" class="<?= $checkClass ?>" <?= $block['on'] ? 'checked' : '' ?> data-home-layout-on>
            <span>ظاهر</span>
          </label>
          <div class="flex items-center gap-1 flex-none">
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
                <span class="text-text-muted block mb-0.5">العنوان على الصفحة</span>
                <input name="title[<?= h($key) ?>]" maxlength="80" value="<?= h($block['title']) ?>" class="<?= $inputClass ?> bg-white" placeholder="<?= h((string) $definition['title']) ?>" data-home-layout-title>
              </label>
            <?php endif; ?>
            <?php foreach ($definition['options'] as $name => $spec): ?>
              <?php $value = $block['options'][$name]; $field = 'opt[' . $key . '][' . $name . ']'; ?>
              <?php if ($spec['type'] === 'bool'): ?>
                <label class="flex flex-col gap-0.5 text-sm self-end min-h-9 justify-center md:col-span-2">
                  <span class="inline-flex items-center gap-2">
                    <input type="checkbox" name="<?= h($field) ?>" class="<?= $checkClass ?>" <?= $value ? 'checked' : '' ?>>
                    <span><?= h((string) $spec['label']) ?></span>
                  </span>
                  <?php if (!empty($spec['hint'])): ?>
                    <span class="text-xs text-text-muted ps-6"><?= h((string) $spec['hint']) ?></span>
                  <?php endif; ?>
                </label>
              <?php elseif ($spec['type'] === 'int'): ?>
                <label class="text-xs">
                  <span class="text-text-muted block mb-0.5"><?= h((string) $spec['label']) ?></span>
                  <input type="number" name="<?= h($field) ?>" min="<?= (int) $spec['min'] ?>" max="<?= (int) $spec['max'] ?>" value="<?= (int) $value ?>" class="<?= $inputClass ?> bg-white">
                </label>
              <?php elseif ($spec['type'] === 'choice'): ?>
                <label class="text-xs md:col-span-2">
                  <span class="text-text-muted block mb-0.5"><?= h((string) $spec['label']) ?></span>
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
