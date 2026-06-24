<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $orders */
/** @var array<string, int> $statusCounts */
/** @var array<string, int> $syncCounts */
/** @var array<string, mixed> $filters */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canManageOrders */
/** @var bool $itemEditSchemaReady */
/** @var string $staffEditBlockReason */
/** @var string $ordersListUrl */
/** @var string $orderPriceCurrency */
/** @var array<string, mixed>|null $orderDetails */

$statusLabels = [
    'pending' => 'جديد',
    'confirmed' => 'مؤكد',
    'completed' => 'مكتمل',
    'cancelled' => 'ملغي',
];

$syncLabels = [
    'none' => 'بدون مزامنة',
    'pending' => 'بانتظار المزامنة',
    'synced' => 'تمت المزامنة',
    'failed' => 'فشل المزامنة',
];

$statusClass = static function (string $status): string {
    return match ($status) {
        'completed' => 'bg-emerald-100 text-emerald-800',
        'confirmed' => 'bg-blue-100 text-blue-800',
        'cancelled' => 'bg-red-100 text-red-800',
        default => 'bg-amber-100 text-amber-800',
    };
};

$syncClass = static function (string $sync): string {
    return match ($sync) {
        'failed' => 'bg-red-100 text-red-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'synced' => 'bg-emerald-100 text-emerald-800',
        default => 'bg-slate-100 text-slate-700',
    };
};

$buildOrdersUrl = static function (array $params): string {
    return '/dashboard/orders.php?' . http_build_query(array_filter(
        $params,
        static fn ($value) => $value !== null && $value !== ''
    ));
};

$formatUsd = static function (float $amount): string {
    return '$' . number_format($amount, 2, '.', ',');
};

$formatPackages = static function (float $amount): string {
    $formatted = number_format($amount, 2, '.', ',');
    return rtrim(rtrim($formatted, '0'), '.');
};

$customerName = static function (array $row): string {
    $name = trim((string) ($row['customer_name_ar'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $guest = trim((string) ($row['guest_name_ar'] ?? ''));
    return $guest !== '' ? $guest : '—';
};

$customerPhone = static function (array $row): string {
    $phone = trim((string) ($row['customer_phone'] ?? ''));
    if ($phone !== '') {
        return $phone;
    }
    $guest = trim((string) ($row['guest_phone'] ?? ''));
    return $guest !== '' ? $guest : '—';
};

$truncate = static function (string $text, int $max = 48): string {
    if (text_length($text) <= $max) {
        return $text;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max) . '…';
    }

    return substr($text, 0, $max) . '…';
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-3 mb-4">
  <div>
    <h1 class="text-xl font-extrabold text-slate-900">إدارة الطلبات</h1>
    <p class="text-sm text-text-muted mt-1">متابعة طلبات الجملة — الأصناف والطرود والإجمالي بالدولار.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24">
      <p class="text-lg font-extrabold text-primary"><?= (int) ($statusCounts['pending'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">جديدة</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24">
      <p class="text-lg font-extrabold text-amber-600"><?= (int) ($syncCounts['pending'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">بانتظار مزامنة</p>
    </article>
    <article class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24">
      <p class="text-lg font-extrabold text-emerald-700"><?= (int) ($statusCounts['completed'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">مكتملة</p>
    </article>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<section class="bg-white border border-border-subtle rounded-xl p-3 mb-3">
  <form method="get" data-dashboard-filter class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-2 items-end">
    <label class="text-xs lg:col-span-2">
      <span class="text-text-muted block mb-0.5">البحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary" placeholder="رقم الطلب، اسم، هاتف...">
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">حالة الطلب</span>
      <select name="status" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">المزامنة</span>
      <select name="sync" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <?php foreach ($syncLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['sync'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">من</span>
      <input type="date" name="fromDate" value="<?= h((string) ($filters['fromDate'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
    </label>
    <label class="text-xs">
      <span class="text-text-muted block mb-0.5">إلى</span>
      <input type="date" name="toDate" value="<?= h((string) ($filters['toDate'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
    </label>
    <div class="lg:col-span-6 flex justify-end">
      <button class="dashboard-btn h-9 bg-primary text-white rounded-lg px-5 text-xs font-bold hover:brightness-110 transition">تطبيق الفلاتر</button>
    </div>
  </form>
</section>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($orders === []): ?>
    <p class="p-5 text-sm text-text-muted text-center">لا توجد طلبات مطابقة للفلاتر الحالية.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1180px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-4 py-3 font-bold">رقم الطلب</th>
            <th class="text-right px-4 py-3 font-bold">العميل</th>
            <th class="text-right px-4 py-3 font-bold">الهاتف</th>
            <th class="text-right px-4 py-3 font-bold">ملاحظات</th>
            <th class="text-center px-4 py-3 font-bold">أصناف</th>
            <th class="text-center px-4 py-3 font-bold">طرود</th>
            <th class="text-right px-4 py-3 font-bold">الإجمالي $</th>
            <th class="text-right px-4 py-3 font-bold">الحالة</th>
            <th class="text-right px-4 py-3 font-bold">المزامنة</th>
            <th class="text-right px-4 py-3 font-bold">التاريخ</th>
            <th class="text-left px-4 py-3 font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($orders as $row): ?>
            <?php
              $rowStatus = (string) ($row['status'] ?? 'pending');
              $sync = (string) ($row['amine_sync_status'] ?? 'none');
              $notes = trim((string) ($row['notes_ar'] ?? ''));
              $name = $customerName($row);
              $phone = $customerPhone($row);
            ?>
            <tr class="hover:bg-slate-50/80 transition align-top">
              <td class="px-4 py-3">
                <div class="font-extrabold text-primary"><?= h((string) ($row['order_number'] ?? '')) ?></div>
                <div class="text-[11px] text-text-muted mt-0.5"><?= h((string) ($row['share_link_name'] ?? 'طلب مباشر')) ?></div>
              </td>
              <td class="px-4 py-3 font-bold"><?= h($name) ?></td>
              <td class="px-4 py-3 whitespace-nowrap" dir="ltr"><?= h($phone) ?></td>
              <td class="px-4 py-3 text-xs text-text-muted max-w-[180px]">
                <?php if ($notes !== ''): ?>
                  <span title="<?= h($notes) ?>"><?= h($truncate($notes, 42)) ?></span>
                <?php else: ?>
                  <span class="text-slate-400">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-center font-bold"><?= (int) ($row['items_count'] ?? 0) ?></td>
              <td class="px-4 py-3 text-center font-bold"><?= h($formatPackages((float) ($row['packages_count'] ?? 0))) ?></td>
              <td class="px-4 py-3 font-extrabold text-emerald-700 whitespace-nowrap"><?= h($formatUsd((float) ($row['total_usd'] ?? 0))) ?></td>
              <td class="px-4 py-3">
                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $statusClass($rowStatus) ?>">
                  <?= h($statusLabels[$rowStatus] ?? $rowStatus) ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $syncClass($sync) ?>">
                  <?= h($syncLabels[$sync] ?? $sync) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-[11px] text-text-muted whitespace-nowrap"><?= h((string) ($row['created_at'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                  <a
                    href="<?= h($buildOrdersUrl([
                        'q' => $filters['q'] ?? '',
                        'status' => $filters['status'] ?? '',
                        'sync' => $filters['sync'] ?? '',
                        'fromDate' => $filters['fromDate'] ?? '',
                        'toDate' => $filters['toDate'] ?? '',
                        'limit' => $filters['limit'] ?? 50,
                        'details' => (string) ($row['id'] ?? ''),
                    ])) ?>"
                    data-dashboard-no-nav
                    class="h-8 px-3 inline-flex items-center rounded-lg border border-slate-300 bg-white text-xs font-bold text-slate-700 hover:bg-slate-50"
                  >تفاصيل</a>
                  <?php if ($canManageOrders): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="flex items-center gap-1">
                      <input type="hidden" name="order_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                      <select name="next_status" class="h-8 rounded-lg border border-border-subtle px-2 text-[11px]">
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                          <option value="<?= h($statusKey) ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="dashboard-btn h-8 px-2.5 rounded-lg bg-primary text-white text-[11px] font-bold">حفظ</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($orderDetails): ?>
  <?php
    $summary = is_array($orderDetails['summary'] ?? null) ? $orderDetails['summary'] : [];
    $detailItems = is_array($orderDetails['items'] ?? null) ? $orderDetails['items'] : [];
    $detailNotes = trim((string) ($orderDetails['notes_ar'] ?? ''));
    $detailName = (string) ($orderDetails['display_name'] ?? '—');
    $detailPhone = (string) ($orderDetails['display_phone'] ?? '—');
    $detailStatus = (string) ($orderDetails['status'] ?? 'pending');
    $detailSync = (string) ($orderDetails['amine_sync_status'] ?? 'none');
  ?>
  <div class="fixed inset-0 z-50 bg-slate-900/40" aria-hidden="true"></div>
  <aside class="fixed top-0 left-0 z-50 h-screen w-full max-w-2xl bg-white shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="order-details-title">
    <header class="shrink-0 border-b border-border-subtle px-4 py-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-[11px] text-text-muted">تفاصيل الطلب</p>
          <h2 id="order-details-title" class="text-lg font-extrabold text-slate-900 truncate"><?= h((string) ($orderDetails['order_number'] ?? '')) ?></h2>
          <p class="text-xs text-text-muted mt-0.5"><?= h((string) ($orderDetails['share_link_name'] ?? 'طلب مباشر')) ?> · <?= h((string) ($orderDetails['created_at'] ?? '')) ?></p>
        </div>
        <a href="<?= h($ordersListUrl) ?>" data-dashboard-no-nav class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-border-subtle hover:bg-surface-low shrink-0" aria-label="إغلاق">
          <span class="material-symbols-outlined">close</span>
        </a>
      </div>
      <div class="flex flex-wrap gap-1.5 mt-2 items-center">
        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $statusClass($detailStatus) ?>"><?= h($statusLabels[$detailStatus] ?? $detailStatus) ?></span>
        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $syncClass($detailSync) ?>"><?= h($syncLabels[$detailSync] ?? $detailSync) ?></span>
        <a
          href="/api/order-images-zip.php?order_id=<?= h(rawurlencode((string) ($orderDetails['id'] ?? ''))) ?>"
          target="_blank"
          class="inline-flex items-center gap-1 h-8 px-3 rounded-lg border border-border-subtle bg-white text-[11px] font-bold text-slate-700 hover:bg-surface-low"
          download
        >
          <span class="material-symbols-outlined text-sm">folder_zip</span>
          صور ZIP
        </a>
        <div class="store-currency-toggle store-currency-toggle--drawer ms-auto" role="group" aria-label="عملة عرض الطلب">
          <button type="button" class="store-currency-toggle__btn <?= $orderPriceCurrency === 'syp' ? 'is-active' : '' ?>" data-dashboard-order-currency="syp" title="عرض بالليرة">ل.س</button>
          <button type="button" class="store-currency-toggle__btn <?= $orderPriceCurrency === 'usd' ? 'is-active' : '' ?>" data-dashboard-order-currency="usd" title="عرض بالدولار">$</button>
        </div>
      </div>
    </header>

    <div class="flex-1 overflow-y-auto">
      <section class="px-4 py-3 border-b border-border-subtle bg-surface-low/50">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
          <div>
            <p class="text-[11px] text-text-muted mb-0.5">العميل</p>
            <p class="font-bold"><?= h($detailName) ?></p>
          </div>
          <div>
            <p class="text-[11px] text-text-muted mb-0.5">الهاتف</p>
            <p class="font-bold" dir="ltr"><?= h($detailPhone) ?></p>
          </div>
        </div>
        <?php if ($detailNotes !== ''): ?>
          <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
            <p class="text-[11px] font-bold text-amber-800">ملاحظات</p>
            <p class="text-sm text-amber-900 mt-0.5 whitespace-pre-wrap leading-relaxed"><?= h($detailNotes) ?></p>
          </div>
        <?php endif; ?>
      </section>

      <section class="px-4 py-3">
        <?php if ($detailItems === []): ?>
          <p class="text-sm text-text-muted text-center py-8">لا توجد أصناف في هذا الطلب.</p>
        <?php else: ?>
          <?php if ($canManageOrders && $staffEditBlockReason !== ''): ?>
            <p class="text-[11px] text-red-800 bg-red-50 border border-red-200 rounded-lg px-3 py-2 mb-3">
              <?= h($staffEditBlockReason) ?>
            </p>
          <?php elseif ($canManageOrders && !empty($orderDetails['can_staff_edit'])): ?>
            <p class="text-[11px] text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
              يمكنك تعديل الأصناف قبل إتمام الطلب — سيظهر السبب لصاحب الطلب.
            </p>
          <?php endif; ?>
          <div class="store-order-lines">
            <?php foreach ($detailItems as $item): ?>
              <?php
                $showPriceUsd = $orderPriceCurrency === 'usd';
                $showPriceSyp = $orderPriceCurrency === 'syp';
                $orderId = (string) ($orderDetails['id'] ?? '');
                require dirname(__DIR__) . '/partials/dashboard-order-line-edit.php';
              ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ((string) ($orderDetails['amine_sync_error_ar'] ?? '') !== ''): ?>
        <section class="px-4 pb-3">
          <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            <p class="font-bold text-xs mb-0.5">خطأ مزامنة الأمين</p>
            <?= h((string) ($orderDetails['amine_sync_error_ar'] ?? '')) ?>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <footer class="shrink-0 border-t border-border-subtle bg-white px-4 py-3 space-y-2">
      <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-text-muted">
        <span><strong class="text-slate-900"><?= h($formatPackages((float) ($summary['packages_count'] ?? 0))) ?></strong> طرد</span>
        <span class="text-slate-300">|</span>
        <span><strong class="text-slate-900"><?= (int) ($summary['items_count'] ?? 0) ?></strong> صنف</span>
      </div>
      <div class="flex items-center justify-between rounded-xl bg-slate-900 text-white px-4 py-3">
        <span class="text-sm font-bold">إجمالي الحساب</span>
        <span class="text-2xl font-extrabold tracking-tight">
          <?php if ($orderPriceCurrency === 'syp'): ?>
            <?= number_format((float) ($orderDetails['total_sp'] ?? 0), 0, '.', ',') ?> ل.س
          <?php else: ?>
            <?= h($formatUsd((float) ($orderDetails['total_usd'] ?? 0))) ?>
          <?php endif; ?>
        </span>
      </div>
      <?php if ($orderPriceCurrency === 'usd' && (float) ($orderDetails['total_sp'] ?? 0) > 0): ?>
        <p class="text-[11px] text-text-muted text-center">ما يعادل <?= number_format((float) ($orderDetails['total_sp'] ?? 0), 0, '.', ',') ?> ل.س</p>
      <?php elseif ($orderPriceCurrency === 'syp' && (float) ($orderDetails['total_usd'] ?? 0) > 0): ?>
        <p class="text-[11px] text-text-muted text-center">ما يعادل <?= h($formatUsd((float) ($orderDetails['total_usd'] ?? 0))) ?></p>
      <?php endif; ?>
      <p class="text-[10px] text-text-muted text-center">عند مزامنة الأمين يُرسل سعر الدولار للصنف.</p>
    </footer>
  </aside>
<?php endif; ?>
