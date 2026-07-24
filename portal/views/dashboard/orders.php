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
/** @var array<string, mixed>|null $filteredCustomer */

use Portal\Services\OrderService;

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

$originClass = static function (array $row): string {
    return OrderService::isRegisteredCustomerOrder($row)
        ? 'bg-indigo-100 text-indigo-800'
        : 'bg-slate-100 text-slate-700';
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

$filterParams = static function (array $overrides = []) use ($filters, $statusFormValue): array {
    $params = [
        'q' => array_key_exists('q', $overrides) ? $overrides['q'] : ($filters['q'] ?? ''),
        'status' => array_key_exists('status', $overrides) ? $overrides['status'] : $statusFormValue,
        'sync' => array_key_exists('sync', $overrides) ? $overrides['sync'] : ($filters['sync'] ?? ''),
        'origin' => array_key_exists('origin', $overrides) ? $overrides['origin'] : ($filters['origin'] ?? ''),
        'fromDate' => array_key_exists('fromDate', $overrides) ? $overrides['fromDate'] : ($filters['fromDate'] ?? ''),
        'toDate' => array_key_exists('toDate', $overrides) ? $overrides['toDate'] : ($filters['toDate'] ?? ''),
        'web_customer_id' => array_key_exists('web_customer_id', $overrides) ? $overrides['web_customer_id'] : ($filters['web_customer_id'] ?? ''),
        'limit' => array_key_exists('limit', $overrides) ? $overrides['limit'] : ($filters['limit'] ?? 50),
    ];

    return array_filter(
        $params,
        static fn ($value) => $value !== null && $value !== ''
    );
};

$buildOrdersUrl = static function (array $params) use ($filterParams): string {
    $query = $filterParams($params);
    if (!empty($params['details'])) {
        $query['details'] = (string) $params['details'];
    }

    return '/dashboard/orders.php?' . http_build_query($query);
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

$statusTabs = [
    'all' => ['label' => 'الكل', 'count' => array_sum($statusCounts), 'tone' => 'bg-slate-100 text-slate-700'],
    'pending' => ['label' => 'جديد', 'count' => (int) ($statusCounts['pending'] ?? 0), 'tone' => 'bg-amber-100 text-amber-800'],
    'confirmed' => ['label' => 'مؤكد', 'count' => (int) ($statusCounts['confirmed'] ?? 0), 'tone' => 'bg-blue-100 text-blue-800'],
    'completed' => ['label' => 'مكتمل', 'count' => (int) ($statusCounts['completed'] ?? 0), 'tone' => 'bg-emerald-100 text-emerald-800'],
    'cancelled' => ['label' => 'ملغي', 'count' => (int) ($statusCounts['cancelled'] ?? 0), 'tone' => 'bg-red-100 text-red-800'],
];

$syncTabs = [
    '' => ['label' => 'كل المزامنة', 'count' => null],
    'pending' => ['label' => 'بانتظار المزامنة', 'count' => (int) ($syncCounts['pending'] ?? 0)],
    'failed' => ['label' => 'فشل المزامنة', 'count' => (int) ($syncCounts['failed'] ?? 0)],
    'synced' => ['label' => 'تمت المزامنة', 'count' => (int) ($syncCounts['synced'] ?? 0)],
];

$activeSyncTab = (string) ($filters['sync'] ?? '');
$hasExtraFilters = !(
    $activeStatusTab === 'pending'
    && $activeSyncTab === ''
    && ($filters['q'] ?? '') === ''
    && ($filters['origin'] ?? '') === ''
    && ($filters['fromDate'] ?? '') === ''
    && ($filters['toDate'] ?? '') === ''
);
$advancedFiltersOpen = ($filters['origin'] ?? '') !== ''
    || ($filters['fromDate'] ?? '') !== ''
    || ($filters['toDate'] ?? '') !== '';
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-3 mb-4">
  <div>
    <h1 class="text-xl font-extrabold text-slate-900">إدارة الطلبات</h1>
    <p class="text-sm text-text-muted mt-1">متابعة طلبات الجملة — الأصناف والطرود والإجمالي بالدولار.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <a href="<?= h($buildOrdersUrl(['status' => 'pending', 'sync' => ''])) ?>" class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24 hover:border-primary/30 hover:shadow-sm transition no-underline text-inherit">
      <p class="text-lg font-extrabold text-primary"><?= (int) ($statusCounts['pending'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">جديدة</p>
    </a>
    <a href="<?= h($buildOrdersUrl(['status' => $statusFormValue, 'sync' => 'pending'])) ?>" class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24 hover:border-amber-200 hover:shadow-sm transition no-underline text-inherit">
      <p class="text-lg font-extrabold text-amber-600"><?= (int) ($syncCounts['pending'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">بانتظار مزامنة</p>
    </a>
    <a href="<?= h($buildOrdersUrl(['status' => 'completed', 'sync' => ''])) ?>" class="bg-white border border-border-subtle rounded-xl px-3 py-2 text-center min-w-24 hover:border-emerald-200 hover:shadow-sm transition no-underline text-inherit">
      <p class="text-lg font-extrabold text-emerald-700"><?= (int) ($statusCounts['completed'] ?? 0) ?></p>
      <p class="text-[11px] text-text-muted">مكتملة</p>
    </a>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if (!empty($filteredCustomer)): ?>
  <section class="mb-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 flex flex-wrap items-center justify-between gap-2">
    <p>
      عرض طلبات العميل:
      <strong><?= h((string) ($filteredCustomer['name_ar'] ?? '')) ?></strong>
      <span dir="ltr">(<?= h((string) ($filteredCustomer['phone'] ?? '')) ?>)</span>
    </p>
    <div class="flex gap-2">
      <a href="/dashboard/customers.php?details=<?= h((string) ($filteredCustomer['id'] ?? '')) ?>" class="text-xs font-bold text-primary hover:underline">ملف العميل</a>
      <a href="/dashboard/orders.php" class="text-xs font-bold text-slate-600 hover:underline">إلغاء التصفية</a>
    </div>
  </section>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl p-3 mb-3 space-y-3">
  <form method="get" data-dashboard-filter data-dashboard-filter-auto class="space-y-3">
    <?php if (!empty($filters['web_customer_id'])): ?>
      <input type="hidden" name="web_customer_id" value="<?= h((string) $filters['web_customer_id']) ?>">
    <?php endif; ?>
    <input type="hidden" name="status" value="<?= h($statusFormValue) ?>">
    <input type="hidden" name="sync" value="<?= h($activeSyncTab) ?>">

    <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
      <label class="relative flex-1 text-sm">
        <span class="sr-only">بحث</span>
        <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 text-text-muted text-lg pointer-events-none">search</span>
        <input
          type="search"
          name="q"
          value="<?= h((string) ($filters['q'] ?? '')) ?>"
          data-dashboard-filter-search
          data-role="search"
          class="h-10 w-full rounded-xl border border-border-subtle pr-10 pl-10 text-sm focus:border-primary focus:ring-primary"
          placeholder="ابحث برقم الطلب، اسم العميل، أو الهاتف..."
          autocomplete="off"
        >
      </label>
      <?php if (($filters['q'] ?? '') !== ''): ?>
        <a
          href="<?= h($buildOrdersUrl(['q' => ''])) ?>"
          class="h-10 px-3 inline-flex items-center justify-center rounded-xl border border-border-subtle text-xs font-bold text-slate-600 hover:bg-surface-low shrink-0"
        >مسح البحث</a>
      <?php endif; ?>
      <?php if ($hasExtraFilters): ?>
        <a
          href="<?= h(!empty($filters['web_customer_id']) ? $buildOrdersUrl(['q' => '', 'status' => 'all', 'sync' => '', 'origin' => '', 'fromDate' => '', 'toDate' => '']) : '/dashboard/orders.php') ?>"
          class="h-10 px-3 inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 text-xs font-bold text-red-700 hover:bg-red-100 shrink-0"
        >إعادة ضبط</a>
      <?php endif; ?>
    </div>

    <div class="dashboard-orders-filter-tabs flex gap-2 overflow-x-auto pb-0.5" role="tablist" aria-label="حالة الطلب">
      <?php foreach ($statusTabs as $tabKey => $tab): ?>
        <?php $isActiveStatus = $activeStatusTab === $tabKey; ?>
        <a
          href="<?= h($buildOrdersUrl(['status' => $tabKey === 'all' ? 'all' : $tabKey])) ?>"
          class="dashboard-orders-filter-tab whitespace-nowrap inline-flex items-center gap-2 px-4 py-2 rounded-full border text-sm transition <?= $isActiveStatus ? 'is-active' : '' ?>"
          <?= $isActiveStatus ? 'aria-current="page"' : '' ?>
        >
          <span class="font-bold"><?= h($tab['label']) ?></span>
          <span class="dashboard-orders-filter-tab__count text-[11px] px-2 py-0.5 rounded-full <?= h($tab['tone']) ?>"><?= (int) $tab['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="dashboard-orders-filter-tabs dashboard-orders-filter-tabs--sync flex gap-2 overflow-x-auto pb-0.5" role="tablist" aria-label="مزامنة الأمين">
      <?php foreach ($syncTabs as $tabKey => $tab): ?>
        <?php $isActiveSync = $activeSyncTab === $tabKey; ?>
        <a
          href="<?= h($buildOrdersUrl(['sync' => $tabKey])) ?>"
          class="dashboard-orders-filter-tab dashboard-orders-filter-tab--compact whitespace-nowrap inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs transition <?= $isActiveSync ? 'is-active' : '' ?>"
          <?= $isActiveSync ? 'aria-current="page"' : '' ?>
        >
          <span class="font-bold"><?= h($tab['label']) ?></span>
          <?php if ($tab['count'] !== null && (int) $tab['count'] > 0): ?>
            <span class="dashboard-orders-filter-tab__count text-[10px] px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-700"><?= (int) $tab['count'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <details class="dashboard-orders-filter-advanced group" <?= $advancedFiltersOpen ? 'open' : '' ?>>
      <summary class="cursor-pointer select-none text-xs font-bold text-primary inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-base transition group-open:rotate-180">expand_more</span>
        فلاتر إضافية
      </summary>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pt-3">
        <label class="text-xs">
          <span class="text-text-muted block mb-1">مصدر الطلب</span>
          <select name="origin" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
            <option value="">الكل</option>
            <option value="registered" <?= ($filters['origin'] ?? '') === 'registered' ? 'selected' : '' ?>>عميل مسجّل</option>
            <option value="guest" <?= ($filters['origin'] ?? '') === 'guest' ? 'selected' : '' ?>>زائر</option>
          </select>
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-1">من تاريخ</span>
          <input type="date" name="fromDate" value="<?= h((string) ($filters['fromDate'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
        </label>
        <label class="text-xs">
          <span class="text-text-muted block mb-1">إلى تاريخ</span>
          <input type="date" name="toDate" value="<?= h((string) ($filters['toDate'] ?? '')) ?>" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
        </label>
      </div>
    </details>
  </form>
</section>

<section class="bg-white border border-border-subtle rounded-xl overflow-hidden">
  <?php if ($orders === []): ?>
    <p class="p-5 text-sm text-text-muted text-center">لا توجد طلبات مطابقة للفلاتر الحالية.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1240px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-4 py-3 font-bold">رقم الطلب</th>
            <th class="text-right px-4 py-3 font-bold">المصدر</th>
            <th class="text-right px-4 py-3 font-bold">العميل</th>
            <th class="text-right px-4 py-3 font-bold">الهاتف</th>
            <th class="text-right px-4 py-3 font-bold">ملاحظات</th>
            <th class="text-center px-4 py-3 font-bold">أصناف</th>
            <th class="text-center px-4 py-3 font-bold">طرود</th>
            <th class="text-right px-4 py-3 font-bold">الإجمالي $</th>
            <th class="text-right px-4 py-3 font-bold">الحالة</th>
            <th class="text-right px-4 py-3 font-bold">المزامنة</th>
            <th class="text-right px-4 py-3 font-bold">التاريخ</th>
            <?php if ($canManageOrders): ?>
            <th class="text-left px-4 py-3 font-bold">تحديث سريع</th>
            <?php endif; ?>
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
              $detailUrl = $buildOrdersUrl(['details' => (string) ($row['id'] ?? '')]);
            ?>
            <tr class="hover:bg-slate-50/80 transition align-top dashboard-clickable-row" data-dashboard-row-href="<?= h($detailUrl) ?>">
              <td class="px-4 py-3">
                <div class="font-extrabold text-primary"><?= h((string) ($row['order_number'] ?? '')) ?></div>
                <div class="text-[11px] text-text-muted mt-0.5"><?= h((string) ($row['share_link_name'] ?? 'طلب مباشر')) ?></div>
              </td>
              <td class="px-4 py-3">
                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $originClass($row) ?>">
                  <?= h(OrderService::orderOriginLabel($row)) ?>
                </span>
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
              <?php if ($canManageOrders): ?>
              <td class="px-4 py-3" data-dashboard-row-ignore>
                <form method="post" data-dashboard-ajax data-dashboard-reload class="flex items-center justify-end gap-1">
                  <input type="hidden" name="order_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                  <select name="next_status" class="h-8 rounded-lg border border-border-subtle px-2 text-[11px]" aria-label="حالة الطلب">
                    <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                      <option value="<?= h($statusKey) ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="dashboard-btn h-8 px-2.5 rounded-lg bg-primary text-white text-[11px] font-bold">حفظ</button>
                </form>
              </td>
              <?php endif; ?>
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
    $detailOrigin = OrderService::orderOriginLabel($orderDetails);
    $detailIsRegistered = OrderService::isRegisteredCustomerOrder($orderDetails);
  ?>
  <div class="dashboard-slide-panel-backdrop fixed inset-0 bg-slate-900/40" aria-hidden="true"></div>
  <aside class="dashboard-slide-panel fixed top-0 left-0 w-full max-w-2xl bg-white shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="order-details-title">
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
        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $originClass($orderDetails) ?>"><?= h($detailOrigin) ?></span>
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
          <?php if ($detailIsRegistered && trim((string) ($orderDetails['web_customer_id'] ?? '')) !== ''): ?>
            <div class="sm:col-span-2">
              <a href="/dashboard/orders.php?web_customer_id=<?= h((string) $orderDetails['web_customer_id']) ?>" class="text-xs font-bold text-primary hover:underline">
                عرض كل طلبات هذا العميل
              </a>
              ·
              <a href="/dashboard/customers.php?details=<?= h((string) $orderDetails['web_customer_id']) ?>" class="text-xs font-bold text-primary hover:underline">
                ملف العميل
              </a>
            </div>
          <?php endif; ?>
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
      <?php if ($canManageOrders): ?>
        <form method="post" data-dashboard-ajax data-dashboard-reload class="rounded-xl border border-border-subtle bg-surface-low/60 px-3 py-2.5">
          <input type="hidden" name="order_id" value="<?= h((string) ($orderDetails['id'] ?? '')) ?>">
          <div class="flex flex-wrap items-end gap-2">
            <label class="flex-1 min-w-[9rem] text-xs">
              <span class="text-text-muted block mb-1">حالة الطلب</span>
              <select name="next_status" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm focus:border-primary focus:ring-primary">
                <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                  <option value="<?= h($statusKey) ?>" <?= $detailStatus === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="dashboard-btn h-9 px-4 rounded-lg bg-primary text-white text-xs font-bold hover:brightness-110 transition">
              تحديث الحالة
            </button>
          </div>
        </form>
      <?php endif; ?>
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
