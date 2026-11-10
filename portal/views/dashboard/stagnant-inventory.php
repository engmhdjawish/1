<?php

declare(strict_types=1);

/** @var array<string, mixed> $report */
/** @var list<array<string, mixed>> $seasons */
/** @var array<string, mixed>|null $selectedSeason */
/** @var array<string, mixed>|null $currentSeason */
/** @var string $seasonId */
/** @var int $minAgeDays */
/** @var string $sort */
/** @var int $page */
/** @var int $pageSize */
/** @var int $totalPages */
/** @var int $totalCount */
/** @var string $yearStartDate */
/** @var string $untilDate */
/** @var string|null $error */
/** @var string|null $flash */
/** @var string $flashType */

$items = is_array($report['items'] ?? null) ? $report['items'] : [];
$fmtMoney = static function (?float $value, string $suffix = ''): string {
    if ($value === null) {
        return '—';
    }

    return number_format($value, 2) . ($suffix !== '' ? ' ' . $suffix : '');
};
$fmtDate = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);

    return $ts ? date('Y-m-d', $ts) : $value;
};
$queryBase = [
    'minAgeDays' => $minAgeDays,
    'sort' => $sort,
    'pageSize' => $pageSize,
    'seasonId' => $seasonId !== '' ? $seasonId : 'all',
    'yearStartDate' => $yearStartDate,
    'untilDate' => $untilDate,
];
$buildUrl = static function (array $overrides) use ($queryBase): string {
    $q = array_merge($queryBase, $overrides);

    return '/dashboard/stagnant-inventory.php?' . http_build_query($q);
};
?>
<section class="mb-6" data-stagnant-inventory-root>
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">المخزون الراكد</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">
        مواد لها رصيد راكد حسب أعمار FIFO من الأمين (من أول السنة، مدة الركود قابلة للتعديل).
        راجع الصورة والأسعار ثم حدّد الأصناف لتوليد عرض تصريف.
      </p>
    </div>
    <a href="/dashboard/special-offers.php" class="h-10 px-4 inline-flex items-center gap-2 rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50" data-dashboard-nav-link>
      <span class="material-symbols-outlined text-lg">sell</span>
      العروض الخاصة
    </a>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<?php if ($error): ?>
  <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    <?= h($error) ?>
    <span class="block mt-1 text-xs text-amber-800">تأكد أن API الأمين يعمل وأن إجراء dbo.repGetAges_bi متاح. للتشخيص: portal/scripts/run-inventory-ages-report.sql</span>
  </p>
<?php endif; ?>

<form method="get" action="/dashboard/stagnant-inventory.php" class="mb-5 bg-white border border-border-subtle rounded-2xl p-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3 items-end" data-dashboard-filter>
  <label class="block text-sm">
    <span class="font-bold text-slate-700">مدة الركود (يوم)</span>
    <input type="number" name="minAgeDays" min="1" max="3650" value="<?= (int) $minAgeDays ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
  </label>
  <label class="block text-sm">
    <span class="font-bold text-slate-700">من تاريخ (أول السنة)</span>
    <input type="date" name="yearStartDate" value="<?= h($yearStartDate) ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
  </label>
  <label class="block text-sm">
    <span class="font-bold text-slate-700">إلى تاريخ</span>
    <input type="date" name="untilDate" value="<?= h($untilDate) ?>" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
  </label>
  <label class="block text-sm">
    <span class="font-bold text-slate-700">الموسم</span>
    <select name="seasonId" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
      <option value="all" <?= $seasonId === '' || $seasonId === 'all' ? 'selected' : '' ?>>كل المواسم / كل المجموعات</option>
      <?php if ($currentSeason): ?>
        <option value="current" <?= $seasonId === 'current' || ($selectedSeason['id'] ?? '') === ($currentSeason['id'] ?? '') ? 'selected' : '' ?>>
          الموسم الحالي — <?= h((string) ($currentSeason['title_ar'] ?? $currentSeason['name_ar'] ?? '')) ?>
        </option>
      <?php endif; ?>
      <?php foreach ($seasons as $season): ?>
        <?php
          $sid = (string) ($season['id'] ?? '');
          if ($sid === '') {
              continue;
          }
        ?>
        <option value="<?= h($sid) ?>" <?= $seasonId === $sid ? 'selected' : '' ?>>
          <?= h((string) ($season['name_ar'] ?? $sid)) ?>
          (<?= count($season['group_guids'] ?? []) ?> مجموعة)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="block text-sm">
    <span class="font-bold text-slate-700">الترتيب</span>
    <select name="sort" class="mt-1 w-full h-10 rounded-xl border border-border-subtle px-3 text-sm">
      <option value="age:desc" <?= $sort === 'age:desc' ? 'selected' : '' ?>>العمر ↓</option>
      <option value="age:asc" <?= $sort === 'age:asc' ? 'selected' : '' ?>>العمر ↑</option>
      <option value="name:asc" <?= $sort === 'name:asc' ? 'selected' : '' ?>>الاسم ↑</option>
      <option value="name:desc" <?= $sort === 'name:desc' ? 'selected' : '' ?>>الاسم ↓</option>
      <option value="price:desc" <?= $sort === 'price:desc' ? 'selected' : '' ?>>سعر البيع $ ↓</option>
      <option value="price:asc" <?= $sort === 'price:asc' ? 'selected' : '' ?>>سعر البيع $ ↑</option>
      <option value="purchase:desc" <?= $sort === 'purchase:desc' ? 'selected' : '' ?>>سعر الشراء $ ↓</option>
      <option value="purchase:asc" <?= $sort === 'purchase:asc' ? 'selected' : '' ?>>سعر الشراء $ ↑</option>
      <option value="qty:desc" <?= $sort === 'qty:desc' ? 'selected' : '' ?>>الكمية الراكدة ↓</option>
      <option value="qty:asc" <?= $sort === 'qty:asc' ? 'selected' : '' ?>>الكمية الراكدة ↑</option>
    </select>
  </label>
  <div class="flex gap-2">
    <input type="hidden" name="page" value="1">
    <input type="hidden" name="pageSize" value="<?= (int) $pageSize ?>">
    <button type="submit" class="h-10 flex-1 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110">تطبيق</button>
  </div>
</form>

<div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm text-text-muted">
  <p>
    <?= (int) $totalCount ?> مادة راكدة
    <?php if ($selectedSeason): ?>
      · موسم: <span class="font-bold text-slate-700"><?= h((string) ($selectedSeason['name_ar'] ?? $selectedSeason['title_ar'] ?? '')) ?></span>
    <?php endif; ?>
    · ركود ≥ <?= (int) $minAgeDays ?> يوم
  </p>
  <?php if ($totalPages > 1): ?>
    <div class="flex items-center gap-2">
      <?php if ($page > 1): ?>
        <a class="px-3 py-1.5 rounded-lg border border-border-subtle hover:bg-slate-50 font-bold" data-dashboard-nav-link href="<?= h($buildUrl(['page' => $page - 1])) ?>">السابق</a>
      <?php endif; ?>
      <span>صفحة <?= (int) $page ?> / <?= (int) $totalPages ?></span>
      <?php if ($page < $totalPages): ?>
        <a class="px-3 py-1.5 rounded-lg border border-border-subtle hover:bg-slate-50 font-bold" data-dashboard-nav-link href="<?= h($buildUrl(['page' => $page + 1])) ?>">التالي</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<form method="post" action="/dashboard/stagnant-inventory.php" id="stagnant-offer-form">
  <input type="hidden" name="action" value="create_offer">
  <input type="hidden" name="minAgeDays" value="<?= (int) $minAgeDays ?>">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <input type="hidden" name="page" value="<?= (int) $page ?>">
  <input type="hidden" name="pageSize" value="<?= (int) $pageSize ?>">
  <input type="hidden" name="seasonId" value="<?= h($seasonId !== '' ? $seasonId : 'all') ?>">
  <input type="hidden" name="yearStartDate" value="<?= h($yearStartDate) ?>">
  <input type="hidden" name="untilDate" value="<?= h($untilDate) ?>">

  <div class="sticky top-0 z-10 mb-3 bg-white/95 backdrop-blur border border-border-subtle rounded-2xl p-3 flex flex-wrap items-end gap-3 shadow-sm">
    <label class="text-sm">
      <span class="font-bold text-slate-700">عنوان العرض</span>
      <input type="text" name="offer_title" value="عرض تصريف راكد <?= h(date('Y-m-d')) ?>" class="mt-1 block w-56 h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <label class="text-sm">
      <span class="font-bold text-slate-700">نسبة الخصم %</span>
      <input type="number" name="discount_percent" min="1" max="95" step="0.5" value="15" class="mt-1 block w-28 h-10 rounded-xl border border-border-subtle px-3 text-sm">
    </label>
    <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-sm font-bold hover:brightness-110 disabled:opacity-50" data-stagnant-submit disabled>
      توليد عرض للمواد المحددة
      (<span data-stagnant-selected-count>0</span>)
    </button>
    <button type="button" class="h-10 px-3 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-slate-50" data-stagnant-select-all>
      تحديد الظاهر
    </button>
    <button type="button" class="h-10 px-3 rounded-xl border border-border-subtle text-sm font-bold text-slate-700 hover:bg-slate-50" data-stagnant-clear>
      إلغاء التحديد
    </button>
  </div>

  <div class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="px-3 py-3 text-right font-bold w-10"></th>
            <th class="px-3 py-3 text-right font-bold">الصورة</th>
            <th class="px-3 py-3 text-right font-bold">المادة</th>
            <th class="px-3 py-3 text-right font-bold">الكمية الراكدة</th>
            <th class="px-3 py-3 text-right font-bold">العمر (يوم)</th>
            <th class="px-3 py-3 text-right font-bold">أقدم شراء</th>
            <th class="px-3 py-3 text-right font-bold">بيع $</th>
            <th class="px-3 py-3 text-right font-bold">شراء $</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($items === []): ?>
            <tr>
              <td colspan="8" class="px-4 py-10 text-center text-text-muted">لا توجد مواد راكدة وفق المعايير الحالية.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($items as $item): ?>
            <?php
              $guid = (string) ($item['materialGuid'] ?? '');
              $imageGuid = (string) ($item['productImageGuid'] ?? '');
              $name = (string) ($item['name'] ?? '');
              $code = (string) ($item['materialCode'] ?? '');
            ?>
            <tr class="border-t border-border-subtle hover:bg-slate-50/80 align-middle">
              <td class="px-3 py-3">
                <input type="checkbox" name="material_guids[]" value="<?= h($guid) ?>" class="w-4 h-4 rounded border-slate-300" data-stagnant-check>
              </td>
              <td class="px-3 py-3">
                <?php if ($imageGuid !== ''): ?>
                  <div class="material-image-frame w-14 h-14 rounded-lg overflow-hidden bg-slate-100 border border-border-subtle">
                    <div class="material-image-frame__photo w-full h-full">
                      <img
                        src="/api/image.php?id=<?= h($imageGuid) ?>&thumb=1"
                        alt="<?= h($name) ?>"
                        loading="lazy"
                        decoding="async"
                        class="w-full h-full object-cover"
                        onerror="this.closest('.material-image-frame')?.classList.add('opacity-40'); this.replaceWith(Object.assign(document.createElement('div'),{className:'w-full h-full flex items-center justify-center text-[10px] text-slate-400',textContent:'بلا صورة'}))"
                      >
                    </div>
                  </div>
                <?php else: ?>
                  <div class="w-14 h-14 rounded-lg bg-slate-100 border border-dashed border-slate-300 flex items-center justify-center text-[10px] text-slate-400">بلا صورة</div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-3">
                <div class="font-bold text-slate-900"><?= h($name !== '' ? $name : $guid) ?></div>
                <?php if ($code !== ''): ?>
                  <div class="text-xs text-text-muted font-mono mt-0.5" dir="ltr"><?= h($code) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-3 font-bold tabular-nums"><?= h(number_format((float) ($item['stagnantRemainingQty'] ?? 0), 2)) ?></td>
              <td class="px-3 py-3 tabular-nums"><?= h(number_format((float) ($item['maxAgeDays'] ?? 0), 0)) ?></td>
              <td class="px-3 py-3 tabular-nums" dir="ltr"><?= h($fmtDate((string) ($item['oldestPurchaseDate'] ?? ''))) ?></td>
              <td class="px-3 py-3 tabular-nums" dir="ltr"><?= h($fmtMoney(isset($item['unitSalePriceUsd']) ? (float) $item['unitSalePriceUsd'] : null, '$')) ?></td>
              <td class="px-3 py-3 tabular-nums" dir="ltr"><?= h($fmtMoney(isset($item['unitPurchasePriceUsd']) ? (float) $item['unitPurchasePriceUsd'] : null, '$')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
