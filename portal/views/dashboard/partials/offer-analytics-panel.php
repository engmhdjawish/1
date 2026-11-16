<?php

declare(strict_types=1);

/** @var array<string, mixed> $analyticsSummary */
/** @var list<array<string, mixed>> $analyticsMaterials */
/** @var string|null $analyticsPanelTitle */
/** @var string|null $analyticsPanelNote */

$analyticsSummary = is_array($analyticsSummary ?? null) ? $analyticsSummary : [];
$analyticsMaterials = is_array($analyticsMaterials ?? null) ? $analyticsMaterials : [];
$fmtInt = static fn ($n): string => number_format((float) $n, 0);
$fmtMoneyAr = static fn ($n, int $d = 0): string => number_format((float) $n, $d, '.', ',');
$panelTitle = trim((string) ($analyticsPanelTitle ?? 'قياس التفاعل والمبيعات'));
$panelNote = trim((string) ($analyticsPanelNote ?? 'آخر 90 يوماً للمشاهدات · المبيعات من طلبات مرتبطة بهذا العرض'));
?>
<article class="so-analytics bg-white border border-border-subtle rounded-xl p-4">
  <div class="flex flex-wrap items-end justify-between gap-2 mb-3">
    <div>
      <p class="text-[11px] font-bold text-primary tracking-wide">جدوى العرض</p>
      <h3 class="font-extrabold text-slate-900 text-base"><?= h($panelTitle) ?></h3>
      <p class="text-xs text-text-muted mt-0.5"><?= h($panelNote) ?></p>
    </div>
  </div>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">طلبات</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($analyticsSummary['orders'] ?? 0)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">طرود مباعة</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($analyticsSummary['packages'] ?? 0, 1)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">إيراد ل.س</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($analyticsSummary['revenue_syp'] ?? 0, 0)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">خصم ممنوح ل.س</div>
      <div class="text-xl font-extrabold tabular-nums text-amber-800"><?= h($fmtMoneyAr($analyticsSummary['discount_syp'] ?? 0, 0)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">مشاهدات / صفحات</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($analyticsSummary['page_views'] ?? 0)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">إضافة للسلة</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($analyticsSummary['add_to_cart'] ?? 0)) ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">تحويل طلبات</div>
      <div class="text-xl font-extrabold tabular-nums"><?= isset($analyticsSummary['conversion_pct']) && $analyticsSummary['conversion_pct'] !== null ? h((string) $analyticsSummary['conversion_pct']) . '%' : '—' ?></div>
    </div>
    <div class="rounded-lg bg-surface-low border border-border-subtle p-3">
      <div class="text-[11px] text-text-muted">مواد بيعت</div>
      <div class="text-xl font-extrabold tabular-nums"><?= h($fmtInt($analyticsSummary['materials'] ?? 0)) ?></div>
    </div>
  </div>
  <?php if ($analyticsMaterials !== []): ?>
    <div class="overflow-auto border border-border-subtle rounded-lg">
      <table class="w-full text-sm min-w-[640px]">
        <thead class="bg-surface-low text-text-muted">
          <tr>
            <th class="px-3 py-2 text-right font-bold">المادة</th>
            <th class="px-3 py-2 text-right font-bold">طلبات</th>
            <th class="px-3 py-2 text-right font-bold">طرود</th>
            <th class="px-3 py-2 text-right font-bold">إيراد ل.س</th>
            <th class="px-3 py-2 text-right font-bold">خصم ل.س</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($analyticsMaterials as $mat): ?>
            <?php if (!is_array($mat)) continue; ?>
            <tr>
              <td class="px-3 py-2">
                <div class="font-bold"><?= h((string) ($mat['material_name_ar'] ?? $mat['material_guid'] ?? '')) ?></div>
                <?php if (!empty($mat['material_code'])): ?>
                  <div class="text-[11px] text-text-muted"><?= h((string) $mat['material_code']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 tabular-nums"><?= h($fmtInt($mat['orders'] ?? 0)) ?></td>
              <td class="px-3 py-2 tabular-nums"><?= h($fmtMoneyAr($mat['packages'] ?? 0, 1)) ?></td>
              <td class="px-3 py-2 tabular-nums"><?= h($fmtMoneyAr($mat['revenue_syp'] ?? 0, 0)) ?></td>
              <td class="px-3 py-2 tabular-nums text-amber-800"><?= h($fmtMoneyAr($mat['discount_syp'] ?? 0, 0)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-xs text-text-muted">لا مبيعات مرتبطة بهذا العرض بعد.</p>
  <?php endif; ?>
</article>