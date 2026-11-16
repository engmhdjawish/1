<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $offers */
/** @var list<array<string, mixed>> $visibleOffers */
/** @var array<string, int> $offerTabCounts */
/** @var array<string, array<string, mixed>> $salesByOffer */
/** @var array<string, float|int> $campaignTotals */
/** @var array{summary?: array<string, mixed>, materials?: list<array<string, mixed>>}|null $focusAnalytics */
/** @var array<string, mixed>|null $focusOffer */
/** @var string $offerTab */
/** @var bool $includeEngagement */
/** @var string $focusOfferId */
/** @var string|null $dbError */

$fmtInt = static fn ($n): string => number_format((float) $n, 0);
$fmtMoneyAr = static fn ($n, int $d = 0): string => number_format((float) $n, $d, '.', ',');

$offerTabLabels = [
    'active' => 'فعالة',
    'scheduled' => 'قادمة',
    'paused' => 'موقوفة',
    'ended' => 'منتهية',
    'all' => 'الكل',
];
$offerStatusLabels = [
    'active' => 'فعالة',
    'scheduled' => 'قادمة',
    'paused' => 'موقوفة',
    'ended' => 'منتهية',
];

$focusOfferId = $focusOfferId ?? trim((string) ($_GET['offer'] ?? ''));
$buildQuery = static function (array $params = []) use ($offerTab, $includeEngagement, $focusOfferId): string {
    $base = ['tab' => $offerTab];
    if ($includeEngagement) {
        $base['engagement'] = '1';
    }
    if ($focusOfferId !== '') {
        $base['offer'] = $focusOfferId;
    }
    $merged = array_merge($base, $params);
    foreach ($merged as $key => $value) {
        if ($value === null || $value === '') {
            unset($merged[$key]);
        }
    }

    return '/dashboard/special-offers-campaigns.php?' . http_build_query($merged);
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-start gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">متابعة حملات العروض</h1>
    <p class="text-sm text-text-muted mt-1">قياس المبيعات والتفاعل خارج شاشة التعديل — لمتابعة سير كل حملة في مكان واحد.</p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="/dashboard/special-offers.php" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-700 hover:bg-slate-50">إدارة العروض</a>
    <?php if (!$includeEngagement): ?>
      <a href="<?= h($buildQuery(['engagement' => '1'])) ?>" class="h-9 px-4 inline-flex items-center rounded-lg border border-amber-300 bg-amber-50 text-xs font-bold text-amber-900 hover:bg-amber-100">تحميل مشاهدات وسلة (90 يوم)</a>
    <?php else: ?>
      <a href="<?= h($buildQuery(['engagement' => null])) ?>" class="h-9 px-4 inline-flex items-center rounded-lg border border-border-subtle bg-white text-xs font-bold text-slate-600 hover:bg-slate-50">إخفاء التفاعل (أسرع)</a>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($dbError)): ?>
  <div class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm"><?= h($dbError) ?></div>
<?php endif; ?>

<section class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
  <article class="rounded-xl border border-border-subtle bg-white p-3">
    <p class="text-[11px] text-text-muted">طلبات (التبويب الحالي)</p>
    <p class="text-2xl font-extrabold tabular-nums"><?= h($fmtInt($campaignTotals['orders'] ?? 0)) ?></p>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-3">
    <p class="text-[11px] text-text-muted">طرود مباعة</p>
    <p class="text-2xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($campaignTotals['packages'] ?? 0, 1)) ?></p>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-3">
    <p class="text-[11px] text-text-muted">إيراد ل.س</p>
    <p class="text-2xl font-extrabold tabular-nums"><?= h($fmtMoneyAr($campaignTotals['revenue_syp'] ?? 0, 0)) ?></p>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-3">
    <p class="text-[11px] text-text-muted">خصم ممنوح ل.س</p>
    <p class="text-2xl font-extrabold tabular-nums text-amber-800"><?= h($fmtMoneyAr($campaignTotals['discount_syp'] ?? 0, 0)) ?></p>
  </article>
</section>

<?php if ($focusOffer !== null && is_array($focusAnalytics)): ?>
  <?php
    $analyticsSummary = is_array($focusAnalytics['summary'] ?? null) ? $focusAnalytics['summary'] : [];
    $analyticsMaterials = is_array($focusAnalytics['materials'] ?? null) ? $focusAnalytics['materials'] : [];
    $analyticsPanelTitle = 'تفاصيل: ' . (string) ($focusOffer['title_ar'] ?? '');
    $analyticsPanelNote = 'شريحة ' . ($offerStatusLabels[(string) ($focusOffer['_lifecycle'] ?? '')] ?? '') . ' · يمكنك العودة للجدول لمقارنة بقية الحملات';
  ?>
  <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <a href="<?= h($buildQuery(['offer' => null])) ?>" class="text-xs font-bold text-primary hover:underline">← كل الحملات</a>
    <a href="/dashboard/special-offers.php?edit=<?= urlencode((string) ($focusOffer['id'] ?? '')) ?>" class="text-xs font-bold text-slate-600 hover:underline">تعديل إعدادات العرض</a>
  </div>
  <?php require __DIR__ . '/partials/offer-analytics-panel.php'; ?>
  <hr class="my-6 border-border-subtle">
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <nav class="so-offer-tabs" aria-label="تصفية الحملات">
    <?php foreach ($offerTabLabels as $tabKey => $tabLabel): ?>
      <a
        href="<?= h($buildQuery(['tab' => $tabKey, 'offer' => null])) ?>"
        class="so-offer-tabs__link<?= $offerTab === $tabKey ? ' is-active' : '' ?>"
        <?= $offerTab === $tabKey ? 'aria-current="page"' : '' ?>
      >
        <span><?= h($tabLabel) ?></span>
        <span class="so-offer-tabs__count"><?= (int) ($offerTabCounts[$tabKey] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($visibleOffers === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد حملات في هذا التبويب.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full min-w-[1080px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">العرض</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-right font-bold">سير الحملة</th>
            <th class="px-4 py-3 text-right font-bold">طلبات</th>
            <th class="px-4 py-3 text-right font-bold">طرود</th>
            <th class="px-4 py-3 text-right font-bold">إيراد ل.س</th>
            <th class="px-4 py-3 text-right font-bold">خصم ل.س</th>
            <?php if ($includeEngagement): ?>
              <th class="px-4 py-3 text-right font-bold">مشاهدات</th>
              <th class="px-4 py-3 text-right font-bold">سلة</th>
            <?php endif; ?>
            <th class="px-4 py-3 text-left font-bold">قياس</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($visibleOffers as $row): ?>
            <?php
              $id = trim((string) ($row['id'] ?? ''));
              $lifecycle = (string) ($row['_lifecycle'] ?? 'paused');
              $metrics = is_array($salesByOffer[$id] ?? null) ? $salesByOffer[$id] : [];
              $progress = $row['_progress_pct'] ?? null;
            ?>
            <tr class="hover:bg-slate-50<?= $id !== '' && $id === trim((string) ($_GET['offer'] ?? '')) ? ' bg-primary/5' : '' ?>">
              <td class="px-4 py-3">
                <div class="font-bold"><?= h((string) ($row['title_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted"><?= h((string) ($row['slug'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3">
                <span class="so-offer-status so-offer-status--<?= h($lifecycle) ?>"><?= h($offerStatusLabels[$lifecycle] ?? '') ?></span>
              </td>
              <td class="px-4 py-3 min-w-[11rem]">
                <?php if ($progress !== null): ?>
                  <div class="so-campaign-progress" role="presentation">
                    <div class="so-campaign-progress__bar" style="width: <?= (int) $progress ?>%"></div>
                  </div>
                  <p class="text-[10px] text-text-muted mt-1 tabular-nums"><?= (int) $progress ?>% · <?= h(substr((string) ($row['starts_at'] ?? ''), 0, 10)) ?> — <?= !empty($row['ends_at']) ? h(substr((string) $row['ends_at'], 0, 10)) : '∞' ?></p>
                <?php else: ?>
                  <p class="text-xs text-text-muted whitespace-nowrap"><?= h(substr((string) ($row['starts_at'] ?? ''), 0, 10)) ?> — <?= !empty($row['ends_at']) ? h(substr((string) $row['ends_at'], 0, 10)) : '∞' ?></p>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 tabular-nums font-bold"><?= h($fmtInt($metrics['orders'] ?? 0)) ?></td>
              <td class="px-4 py-3 tabular-nums"><?= h($fmtMoneyAr($metrics['packages'] ?? 0, 1)) ?></td>
              <td class="px-4 py-3 tabular-nums"><?= h($fmtMoneyAr($metrics['revenue_syp'] ?? 0, 0)) ?></td>
              <td class="px-4 py-3 tabular-nums text-amber-800"><?= h($fmtMoneyAr($metrics['discount_syp'] ?? 0, 0)) ?></td>
              <?php if ($includeEngagement): ?>
                <td class="px-4 py-3 tabular-nums"><?= h($fmtInt($metrics['page_views'] ?? 0)) ?></td>
                <td class="px-4 py-3 tabular-nums"><?= h($fmtInt($metrics['add_to_cart'] ?? 0)) ?></td>
              <?php endif; ?>
              <td class="px-4 py-3">
                <a href="<?= h($buildQuery(['offer' => $id])) ?>" class="h-8 px-3 inline-flex items-center rounded-lg border border-primary/40 bg-primary/5 text-xs font-bold text-primary hover:bg-primary/10">تفاصيل</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="px-4 py-3 text-[11px] text-text-muted border-t border-border-subtle">
      المبيعات مجمّعة من الطلبات غير الملغاة. التفاعل اختياري وبطيء على قواعد كبيرة — حمّله عند الحاجة فقط.
    </p>
  <?php endif; ?>
</section>