<?php

declare(strict_types=1);

use Portal\Services\StoreSeasonService;

/** @var list<array<string, mixed>> $seasons */
/** @var array{mode: string, manual_season_id: string, show_to_customers: bool} $settings */
/** @var array<string, mixed>|null $current */
/** @var array{season: array<string, mixed>, at: int}|null $nextSwitch */
/** @var array<string, mixed>|null $editSeason */
/** @var string $editId */
/** @var bool $showForm */
/** @var bool $tableReady */
/** @var list<array<string, mixed>> $groups */
/** @var array<string, string> $groupNames */
/** @var string|null $groupsError */
/** @var int|null $currentCount */
/** @var array<int, array<string, mixed>|null> $timeline */
/** @var list<array<string, mixed>> $suggested */
/** @var int $now */
/** @var string|null $flash */
/** @var string $flashType */

$inputClass = 'h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary';
$selectClass = 'h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary';
$btnLight = 'h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50';
$btnPrimary = 'h-9 px-4 inline-flex items-center rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110';

$palette = [
    ['cell' => 'bg-amber-100 text-amber-900', 'dot' => 'bg-amber-400'],
    ['cell' => 'bg-sky-100 text-sky-900', 'dot' => 'bg-sky-400'],
    ['cell' => 'bg-emerald-100 text-emerald-900', 'dot' => 'bg-emerald-400'],
    ['cell' => 'bg-rose-100 text-rose-900', 'dot' => 'bg-rose-400'],
    ['cell' => 'bg-violet-100 text-violet-900', 'dot' => 'bg-violet-400'],
];
$seasonColor = [];
foreach ($seasons as $i => $season) {
    $seasonColor[$season['id']] = $palette[$i % count($palette)];
}
$groupLabel = static fn (string $guid): string => $groupNames[strtolower($guid)] ?? 'مجموعة غير معروفة';
$modeHints = [
    'off' => 'ترتيب المتجر المعتاد بدون أولوية.',
    'manual' => 'تختار الموسم بنفسك ويبقى حتى تغيّره.',
    'auto' => 'يتبدّل الموسم في تاريخ بدايته كل سنة.',
];
$daysUntil = $nextSwitch !== null ? (int) ceil(($nextSwitch['at'] - $now) / 86400) : 0;
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">مواسم العرض</h1>
    <p class="text-sm text-text-muted mt-1">
      مجموعات الموسم الحالي تظهر في مقدمة المتجر، ثم باقي المواد. المتوفر يبقى قبل غير المتوفر دائماً.
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <?php if (!$showForm): ?>
      <a href="/store.php" target="_blank" class="<?= $btnLight ?>">معاينة المتجر</a>
      <a href="/index.php" target="_blank" class="<?= $btnLight ?>">الرئيسية</a>
      <?php if ($tableReady): ?>
        <a href="/dashboard/store-seasons.php?new=1" class="<?= $btnPrimary ?>">موسم جديد</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!$tableReady): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-800 px-4 py-3 text-sm">
    جدول المواسم غير موجود بعد. شغّل ترحيل قاعدة البيانات (<code>PORTAL_DB_SETUP=migrate</code>) ثم أعد فتح الصفحة.
  </p>
<?php endif; ?>

<?php if ($groupsError): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-xs"><?= h($groupsError) ?></p>
<?php endif; ?>

<?php if ($showForm && $editSeason !== null): ?>
  <?php
    $picked = array_map('strtolower', $editSeason['group_guids']);
    $sortedGroups = $groups;
    usort($sortedGroups, static function (array $a, array $b) use ($picked): int {
        $aOn = in_array(strtolower((string) $a['guid']), $picked, true);
        $bOn = in_array(strtolower((string) $b['guid']), $picked, true);

        return $aOn === $bOn ? strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')) : ($aOn ? -1 : 1);
    });
  ?>
  <?php if ($editId !== ''): ?>
    <form method="post" id="season-delete-form" class="hidden" data-dashboard-confirm="حذف هذا الموسم؟">
      <input type="hidden" name="action" value="delete_season">
      <input type="hidden" name="id" value="<?= h($editId) ?>">
    </form>
  <?php endif; ?>
  <form method="post" data-dashboard-explicit-save data-season-form class="space-y-3 mb-6">
    <input type="hidden" name="action" value="save_season">
    <input type="hidden" name="id" value="<?= h($editId) ?>">

    <div class="dashboard-sticky-toolbar sticky z-20 -mx-1 px-1 py-2 bg-surface-low/95 backdrop-blur border border-border-subtle rounded-xl flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-bold text-base"><?= $editId !== '' ? 'تعديل الموسم' : 'موسم جديد' ?></h2>
      <div class="flex flex-wrap items-center gap-2">
        <a href="/dashboard/store-seasons.php" class="<?= $btnLight ?>">إلغاء</a>
        <?php if ($editId !== ''): ?>
          <button type="submit" form="season-delete-form" class="h-9 px-4 rounded-lg border border-red-300 bg-white text-xs font-bold text-red-700 hover:bg-red-50">حذف</button>
        <?php endif; ?>
        <button type="submit" data-dashboard-save-btn class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ</button>
      </div>
    </div>

    <article class="bg-white border border-border-subtle rounded-xl p-3">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <label class="text-xs md:col-span-2">
          <span class="text-text-muted block mb-0.5">اسم الموسم *</span>
          <input name="name_ar" required maxlength="120" value="<?= h((string) $editSeason['name_ar']) ?>" class="<?= $inputClass ?>" placeholder="صيفي">
        </label>
        <label class="text-xs md:col-span-2">
          <span class="text-text-muted block mb-0.5">العنوان للزبون</span>
          <input name="customer_title_ar" maxlength="160" value="<?= h((string) $editSeason['customer_title_ar']) ?>" class="<?= $inputClass ?>" placeholder="تشكيلة الصيف">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">يبدأ في شهر</span>
          <select name="starts_month" class="<?= $selectClass ?>">
            <option value="">بدون تاريخ</option>
            <?php foreach (StoreSeasonService::MONTHS as $month => $label): ?>
              <option value="<?= (int) $month ?>" <?= $editSeason['starts_month'] === $month ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">اليوم</span>
          <input type="number" min="1" max="31" name="starts_day" value="<?= (int) ($editSeason['starts_day'] ?? 1) ?>" class="<?= $inputClass ?>">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-0.5">الترتيب</span>
          <input type="number" min="0" name="sort_order" value="<?= (int) $editSeason['sort_order'] ?>" class="<?= $inputClass ?>">
        </label>
        <fieldset class="text-xs md:col-span-4">
          <legend class="text-text-muted mb-1">الأيقونة</legend>
          <div class="flex flex-wrap gap-2">
            <?php foreach (StoreSeasonService::ICONS as $icon => $label): ?>
              <label class="cursor-pointer">
                <input type="radio" name="icon" value="<?= h($icon) ?>" class="peer sr-only" <?= $editSeason['icon'] === $icon ? 'checked' : '' ?>>
                <span class="flex flex-col items-center gap-1 w-20 rounded-xl border-2 border-border-subtle bg-white px-2 py-2 peer-checked:border-primary peer-checked:bg-red-50 peer-focus-visible:ring-2 peer-focus-visible:ring-primary">
                  <span class="material-symbols-outlined text-2xl text-primary" aria-hidden="true"><?= h($icon) ?></span>
                  <span class="text-[11px] font-bold text-slate-700"><?= h($label) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      </div>
    </article>

    <article class="bg-white border border-border-subtle rounded-xl p-3 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-bold text-sm">مجموعات الموسم <span class="text-text-muted font-normal">(<span data-season-count><?= count($editSeason['group_guids']) ?></span> مختارة)</span></h3>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach (['صيف' => 'كل «صيفي»', 'شتو' => 'كل «شتوي»', 'عام' => 'كل «عام»'] as $keyword => $label): ?>
            <button type="button" class="h-8 px-3 rounded-full border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50" data-season-pick="<?= h($keyword) ?>">+ <?= h($label) ?></button>
          <?php endforeach; ?>
          <button type="button" class="h-8 px-3 rounded-full border border-border-subtle bg-white text-xs font-bold text-slate-500 hover:bg-slate-50" data-season-pick="">مسح الاختيار</button>
        </div>
      </div>
      <input type="search" class="<?= $inputClass ?>" placeholder="ابحث في المجموعات" data-season-group-search aria-label="ابحث في المجموعات">
      <?php if ($groups === []): ?>
        <p class="text-xs text-text-muted">لا توجد مجموعات من أمين حالياً.</p>
      <?php endif; ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1.5 max-h-96 overflow-y-auto p-0.5">
        <?php foreach ($sortedGroups as $group): ?>
          <?php $guid = (string) $group['guid']; $name = trim((string) ($group['name'] ?? '')) ?: $guid; ?>
          <label class="flex items-center gap-2 rounded-lg border border-border-subtle px-2.5 py-2 text-sm hover:bg-slate-50 has-[:checked]:border-primary has-[:checked]:bg-red-50" data-season-group data-name="<?= h($name) ?>">
            <input type="checkbox" name="group_guids[]" value="<?= h($guid) ?>" class="rounded border-border-subtle text-primary focus:ring-primary" <?= in_array(strtolower($guid), $picked, true) ? 'checked' : '' ?>>
            <span class="font-bold text-slate-800"><?= h($name) ?></span>
            <?php if (trim((string) ($group['code'] ?? '')) !== ''): ?>
              <span class="ms-auto text-[11px] text-text-muted" dir="ltr"><?= h((string) $group['code']) ?></span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <?php foreach ($editSeason['group_guids'] as $guid): ?>
          <?php if (!isset($groupNames[strtolower($guid)])): ?>
            <label class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-2 text-sm" data-season-group data-name="">
              <input type="checkbox" name="group_guids[]" value="<?= h($guid) ?>" checked class="rounded border-border-subtle text-primary focus:ring-primary">
              <span class="font-bold text-amber-800">مجموعة غير موجودة الآن</span>
            </label>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </article>
  </form>
<?php else: ?>

  <article class="mb-4 rounded-2xl border border-border-subtle bg-white p-4 md:p-5 flex flex-col md:flex-row md:items-center gap-4">
    <div class="flex items-center gap-4 flex-1 min-w-0">
      <span class="flex h-16 w-16 flex-none items-center justify-center rounded-2xl <?= $current !== null ? 'bg-red-50 text-primary' : 'bg-slate-100 text-slate-400' ?>">
        <span class="material-symbols-outlined text-4xl" aria-hidden="true"><?= h($current['icon'] ?? 'event_busy') ?></span>
      </span>
      <div class="min-w-0">
        <p class="text-xs font-bold text-text-muted">الموسم الحالي</p>
        <p class="text-2xl font-extrabold text-slate-900"><?= h($current['name_ar'] ?? 'بلا أولوية') ?></p>
        <p class="text-sm text-slate-600 mt-0.5">
          <?= h(StoreSeasonService::MODES[$settings['mode']] ?? '') ?>
          <?php if ($current !== null): ?>
            · <?= count($current['group_guids']) ?> مجموعة
            <?php if ($currentCount !== null): ?>
              · <strong class="text-slate-900"><?= number_format($currentCount) ?></strong> مادة متوفرة في المقدمة
            <?php endif; ?>
            <?php if (!$settings['show_to_customers']): ?>
              · مخفي عن الزبائن
            <?php endif; ?>
          <?php endif; ?>
        </p>
      </div>
    </div>
    <?php if ($nextSwitch !== null): ?>
      <div class="rounded-xl bg-slate-50 border border-border-subtle px-4 py-3 text-sm">
        <p class="text-xs font-bold text-text-muted">التبديل القادم</p>
        <p class="font-extrabold text-slate-900 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-lg text-primary" aria-hidden="true"><?= h($nextSwitch['season']['icon']) ?></span>
          <?= h($nextSwitch['season']['name_ar']) ?> في <?= h(StoreSeasonService::startLabel($nextSwitch['season'])) ?>
        </p>
        <p class="text-xs text-text-muted">بعد <?= h(hn_days_label(max(1, $daysUntil))) ?></p>
      </div>
    <?php endif; ?>
  </article>

  <?php if ($timeline !== [] && array_filter($timeline) !== []): ?>
    <article class="mb-4 rounded-2xl border border-border-subtle bg-white p-4">
      <h2 class="font-bold text-sm mb-3">السنة حسب تواريخ البداية</h2>
      <ol class="grid grid-cols-6 md:grid-cols-12 gap-1">
        <?php foreach ($timeline as $month => $season): ?>
          <?php $color = $season !== null ? ($seasonColor[$season['id']] ?? $palette[0]) : null; ?>
          <li class="rounded-lg px-1 py-2 text-center <?= $color['cell'] ?? 'bg-slate-50 text-slate-400' ?> <?= (int) date('n', $now) === $month ? 'ring-2 ring-slate-900' : '' ?>">
            <span class="block text-[11px] font-bold"><?= h(StoreSeasonService::MONTHS[$month]) ?></span>
            <span class="block text-xs font-extrabold truncate"><?= h($season['name_ar'] ?? '—') ?></span>
          </li>
        <?php endforeach; ?>
      </ol>
    </article>
  <?php endif; ?>

  <?php if ($tableReady && $seasons !== []): ?>
    <form method="post" class="mb-6 rounded-2xl border border-border-subtle bg-white p-4 space-y-4" data-season-settings>
      <input type="hidden" name="action" value="save_settings">
      <fieldset>
        <legend class="font-bold text-sm mb-2">طريقة اختيار الموسم</legend>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
          <?php foreach (StoreSeasonService::MODES as $mode => $label): ?>
            <label class="cursor-pointer">
              <input type="radio" name="mode" value="<?= h($mode) ?>" class="peer sr-only" <?= $settings['mode'] === $mode ? 'checked' : '' ?> data-season-mode>
              <span class="block h-full rounded-xl border-2 border-border-subtle px-3 py-2.5 peer-checked:border-primary peer-checked:bg-red-50 peer-focus-visible:ring-2 peer-focus-visible:ring-primary">
                <span class="block font-extrabold text-sm text-slate-900"><?= h($label) ?></span>
                <span class="block text-xs text-text-muted mt-0.5"><?= h($modeHints[$mode]) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <div data-season-manual <?= $settings['mode'] === 'manual' ? '' : 'hidden' ?>>
      <label class="block text-xs max-w-sm">
        <span class="text-text-muted block mb-0.5">الموسم الحالي</span>
        <select name="manual_season_id" class="<?= $selectClass ?>">
          <option value="">اختر</option>
          <?php foreach ($seasons as $season): ?>
            <option value="<?= h($season['id']) ?>" <?= $settings['manual_season_id'] === $season['id'] ? 'selected' : '' ?>><?= h($season['name_ar']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="show_to_customers" class="rounded border-border-subtle text-primary focus:ring-primary mt-0.5" <?= $settings['show_to_customers'] ? 'checked' : '' ?>>
        <span>
          إظهار تشكيلة الموسم للزبائن في الرئيسية والمتجر
          <span class="block text-xs text-text-muted mt-0.5">يشمل دائرة الموسم في شريط التصفح السريع وبطاقة التشكيلة — بعد تفعيلهما من <a href="/dashboard/home-layout.php" class="font-bold text-primary">تخطيط الصفحة الرئيسية</a>.</span>
        </span>
      </label>
      <button type="submit" class="dashboard-btn h-9 px-5 rounded-lg bg-primary text-white text-xs font-extrabold hover:brightness-110">حفظ</button>
    </form>
  <?php endif; ?>

  <?php if ($seasons !== []): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
      <?php foreach ($seasons as $season): ?>
        <?php $isCurrent = ($current['id'] ?? '') === $season['id']; $color = $seasonColor[$season['id']]; ?>
        <article class="rounded-2xl border <?= $isCurrent ? 'border-primary' : 'border-border-subtle' ?> bg-white p-4 flex flex-col gap-3">
          <div class="flex items-start gap-3">
            <span class="flex h-12 w-12 flex-none items-center justify-center rounded-xl <?= $color['cell'] ?>">
              <span class="material-symbols-outlined text-2xl" aria-hidden="true"><?= h($season['icon']) ?></span>
            </span>
            <div class="min-w-0 flex-1">
              <h3 class="font-extrabold text-slate-900 flex items-center gap-2">
                <?= h($season['name_ar']) ?>
                <?php if ($isCurrent): ?>
                  <span class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-extrabold text-white">الحالي</span>
                <?php endif; ?>
              </h3>
              <p class="text-xs text-text-muted"><?= h($season['customer_title_ar'] !== '' ? $season['customer_title_ar'] : $season['name_ar']) ?></p>
              <p class="text-xs text-slate-600 mt-0.5"><?= $season['starts_month'] !== null ? 'يبدأ ' . h(StoreSeasonService::startLabel($season)) : 'بدون تاريخ' ?></p>
            </div>
            <a href="/dashboard/store-seasons.php?edit=<?= h(rawurlencode($season['id'])) ?>" class="<?= $btnLight ?>">تعديل</a>
          </div>
          <div class="flex flex-wrap gap-1">
            <?php foreach (array_slice($season['group_guids'], 0, 8) as $guid): ?>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700"><?= h($groupLabel($guid)) ?></span>
            <?php endforeach; ?>
            <?php if (count($season['group_guids']) > 8): ?>
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">+<?= count($season['group_guids']) - 8 ?></span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php elseif ($tableReady): ?>
    <article class="rounded-2xl border border-dashed border-border-subtle bg-white p-5 space-y-4">
      <h2 class="font-bold">لا توجد مواسم بعد</h2>
      <?php if (array_filter($suggested, static fn (array $s): bool => $s['group_guids'] !== []) !== []): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <?php foreach ($suggested as $season): ?>
            <div class="rounded-xl border border-border-subtle p-3">
              <p class="font-extrabold flex items-center gap-1.5">
                <span class="material-symbols-outlined text-primary" aria-hidden="true"><?= h($season['icon']) ?></span>
                <?= h($season['name_ar']) ?>
                <span class="text-xs font-normal text-text-muted">يبدأ <?= h(StoreSeasonService::startLabel($season)) ?></span>
              </p>
              <div class="flex flex-wrap gap-1 mt-2">
                <?php foreach ($season['group_guids'] as $guid): ?>
                  <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700"><?= h($groupLabel($guid)) ?></span>
                <?php endforeach; ?>
                <?php if ($season['group_guids'] === []): ?>
                  <span class="text-xs text-text-muted">لا توجد مجموعات مطابقة</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="seed_seasons">
          <button type="submit" class="<?= $btnPrimary ?>">إنشاء الموسمين من أسماء المجموعات</button>
        </form>
      <?php endif; ?>
      <a href="/dashboard/store-seasons.php?new=1" class="<?= $btnLight ?>">موسم جديد يدوياً</a>
    </article>
  <?php endif; ?>
<?php endif; ?>
