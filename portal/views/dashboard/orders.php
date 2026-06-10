<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $orders */
/** @var array<string, int> $statusCounts */
/** @var array<string, int> $syncCounts */
/** @var array<string, mixed> $filters */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canManageOrders */
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
        'completed' => 'bg-green-100 text-green-700',
        'confirmed' => 'bg-blue-100 text-blue-700',
        'cancelled' => 'bg-red-100 text-red-700',
        default => 'bg-amber-100 text-amber-700',
    };
};

$buildOrdersUrl = static function (array $params): string {
    return '/dashboard/orders.php?' . http_build_query(array_filter(
        $params,
        static fn ($value) => $value !== null && $value !== ''
    ));
};
?>
<section class="flex flex-col md:flex-row justify-between md:items-center gap-4 mb-6">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">إدارة الطلبات</h1>
    <p class="text-sm text-text-muted mt-1">متابعة ومعالجة طلبات الجملة مع حالة مزامنة الأمين.</p>
  </div>
  <div class="flex gap-3 flex-wrap">
    <div class="bg-white border border-border-subtle rounded-xl px-4 py-3 text-center min-w-28">
      <p class="text-2xl font-extrabold text-primary"><?= (int) ($statusCounts['pending'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">طلبات جديدة</p>
    </div>
    <div class="bg-white border border-border-subtle rounded-xl px-4 py-3 text-center min-w-28">
      <p class="text-2xl font-extrabold text-amber-600"><?= (int) ($syncCounts['pending'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">بانتظار مزامنة</p>
    </div>
    <div class="bg-white border border-border-subtle rounded-xl px-4 py-3 text-center min-w-28">
      <p class="text-2xl font-extrabold text-green-700"><?= (int) ($statusCounts['completed'] ?? 0) ?></p>
      <p class="text-xs text-text-muted">طلبات مكتملة</p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<section class="bg-white border border-border-subtle rounded-2xl p-5 mb-5">
  <form method="get" data-dashboard-filter class="grid grid-cols-1 lg:grid-cols-5 gap-4 items-end">
    <label class="text-sm">
      <span class="text-text-muted block mb-1">البحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="رقم الطلب، اسم العميل...">
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">حالة الطلب</span>
      <select name="status" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">حالة المزامنة</span>
      <select name="sync" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <?php foreach ($syncLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['sync'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">تاريخ من</span>
      <input type="date" name="fromDate" value="<?= h((string) ($filters['fromDate'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1">تاريخ إلى</span>
      <input type="date" name="toDate" value="<?= h((string) ($filters['toDate'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
    </label>
    <div class="lg:col-span-5 flex justify-end">
      <button class="h-11 bg-primary text-white rounded-xl px-6 font-bold hover:brightness-110 transition">تطبيق الفلاتر</button>
    </div>
  </form>
</section>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <?php if ($orders === []): ?>
    <p class="p-6 text-sm text-text-muted text-center">لا توجد طلبات مطابقة للفلاتر الحالية.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1060px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-5 py-4 font-bold">رقم الطلب</th>
            <th class="text-right px-5 py-4 font-bold">العميل</th>
            <th class="text-right px-5 py-4 font-bold text-center">الأصناف</th>
            <th class="text-right px-5 py-4 font-bold">الإجمالي</th>
            <th class="text-right px-5 py-4 font-bold">الحالة</th>
            <th class="text-right px-5 py-4 font-bold">المزامنة</th>
            <th class="text-right px-5 py-4 font-bold">تاريخ الإنشاء</th>
            <th class="text-right px-5 py-4 font-bold text-left">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($orders as $row): ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4">
                <div class="font-extrabold text-primary"><?= h((string) ($row['order_number'] ?? '')) ?></div>
                <div class="text-xs text-text-muted mt-1"><?= h((string) ($row['share_link_name'] ?? 'طلب مباشر')) ?></div>
              </td>
              <td class="px-5 py-4">
                <div class="font-bold"><?= h((string) ($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: '—')) ?></div>
              </td>
              <td class="px-5 py-4 text-center"><?= (int) ($row['items_count'] ?? 0) ?></td>
              <td class="px-5 py-4 font-bold"><?= number_format((float) ($row['total_sp'] ?? 0), 0, '.', ',') ?> ل.س</td>
              <td class="px-5 py-4">
                <?php $rowStatus = (string) ($row['status'] ?? 'pending'); ?>
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $statusClass($rowStatus) ?>">
                  <?= h($statusLabels[$rowStatus] ?? $rowStatus) ?>
                </span>
              </td>
              <td class="px-5 py-4">
                <?php $sync = (string) ($row['amine_sync_status'] ?? 'none'); ?>
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $sync === 'failed' ? 'bg-red-100 text-red-700' : ($sync === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700') ?>">
                  <?= h($syncLabels[$sync] ?? $sync) ?>
                </span>
              </td>
              <td class="px-5 py-4 text-xs text-text-muted"><?= h((string) ($row['created_at'] ?? '')) ?></td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <?php if ($canManageOrders): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="flex flex-wrap items-center gap-2">
                      <input type="hidden" name="order_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                      <select name="next_status" class="h-9 rounded-lg border border-border-subtle px-2 text-xs">
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                          <option value="<?= h($statusKey) ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="dashboard-btn h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold">حفظ</button>
                    </form>
                  <?php endif; ?>
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
                    class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-border-subtle text-text-muted hover:bg-surface-low"
                    title="تفاصيل الطلب"
                  >
                    <span class="material-symbols-outlined">visibility</span>
                  </a>
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
  <div class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm"></div>
  <aside class="fixed top-0 left-0 z-50 h-screen w-full max-w-2xl bg-white shadow-2xl overflow-y-auto">
    <div class="sticky top-0 bg-white border-b border-border-subtle px-5 py-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-extrabold text-slate-900">تفاصيل الطلب #<?= h((string) ($orderDetails['order_number'] ?? '')) ?></h2>
        <p class="text-xs text-text-muted mt-1">
          <?= h((string) (($orderDetails['customer_name_ar'] ?? '') !== '' ? $orderDetails['customer_name_ar'] : ($orderDetails['guest_name_ar'] ?? 'ضيف'))) ?>
        </p>
      </div>
      <a href="<?= h($buildOrdersUrl($filters)) ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-low">
        <span class="material-symbols-outlined">close</span>
      </a>
    </div>

    <div class="p-5 space-y-5">
      <section class="grid grid-cols-2 gap-3 text-sm">
        <article class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs">الحالة</p>
          <p class="font-bold mt-1"><?= h((string) ($statusLabels[(string) ($orderDetails['status'] ?? 'pending')] ?? ($orderDetails['status'] ?? 'pending'))) ?></p>
        </article>
        <article class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs">حالة المزامنة</p>
          <p class="font-bold mt-1"><?= h((string) ($syncLabels[(string) ($orderDetails['amine_sync_status'] ?? 'none')] ?? ($orderDetails['amine_sync_status'] ?? 'none'))) ?></p>
        </article>
        <article class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs">الإجمالي (ل.س)</p>
          <p class="font-bold mt-1"><?= number_format((float) ($orderDetails['total_sp'] ?? 0), 0, '.', ',') ?></p>
        </article>
        <article class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs">الإجمالي (USD)</p>
          <p class="font-bold mt-1"><?= number_format((float) ($orderDetails['total_usd'] ?? 0), 2, '.', ',') ?></p>
        </article>
      </section>

      <section>
        <h3 class="font-bold text-slate-900 mb-2">العناصر</h3>
        <?php $items = $orderDetails['items'] ?? []; ?>
        <?php if ($items === []): ?>
          <p class="text-sm text-text-muted rounded-xl border border-border-subtle p-3">لا توجد عناصر مرتبطة بهذا الطلب.</p>
        <?php else: ?>
          <div class="rounded-xl border border-border-subtle overflow-hidden">
            <table class="w-full text-sm">
              <thead class="bg-surface-low text-text-muted">
                <tr>
                  <th class="px-3 py-2 text-right font-bold">المادة</th>
                  <th class="px-3 py-2 text-right font-bold">الكمية</th>
                  <th class="px-3 py-2 text-right font-bold">سعر SP</th>
                  <th class="px-3 py-2 text-right font-bold">سعر USD</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border-subtle">
                <?php foreach ($items as $item): ?>
                  <tr>
                    <td class="px-3 py-2">
                      <div class="font-semibold"><?= h((string) ($item['material_name_ar'] ?? '—')) ?></div>
                      <div class="text-xs text-text-muted"><?= h((string) ($item['material_code'] ?? '')) ?></div>
                    </td>
                    <td class="px-3 py-2"><?= number_format((float) ($item['quantity'] ?? 0), 2, '.', ',') ?></td>
                    <td class="px-3 py-2"><?= number_format((float) ($item['sale_price_sp'] ?? 0), 0, '.', ',') ?></td>
                    <td class="px-3 py-2"><?= number_format((float) ($item['sale_price_usd'] ?? 0), 2, '.', ',') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section>
        <h3 class="font-bold text-slate-900 mb-2">الخط الزمني</h3>
        <?php $timeline = $orderDetails['timeline'] ?? []; ?>
        <ol class="space-y-2">
          <?php foreach ($timeline as $entry): ?>
            <li class="rounded-xl border border-border-subtle p-3 text-sm">
              <p class="font-semibold"><?= h((string) ($entry['label'] ?? 'حدث')) ?></p>
              <p class="text-xs text-text-muted mt-1"><?= h((string) ($entry['at'] ?? '')) ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>

      <?php if ((string) ($orderDetails['notes_ar'] ?? '') !== ''): ?>
        <section class="rounded-xl border border-border-subtle p-3">
          <h3 class="font-bold text-slate-900 mb-1">ملاحظات الطلب</h3>
          <p class="text-sm text-text-muted"><?= h((string) ($orderDetails['notes_ar'] ?? '')) ?></p>
        </section>
      <?php endif; ?>

      <?php if ((string) ($orderDetails['amine_sync_error_ar'] ?? '') !== ''): ?>
        <section class="rounded-xl border border-red-200 bg-red-50 p-3">
          <h3 class="font-bold text-red-700 mb-1">خطأ مزامنة الأمين</h3>
          <p class="text-sm text-red-700"><?= h((string) ($orderDetails['amine_sync_error_ar'] ?? '')) ?></p>
        </section>
      <?php endif; ?>
    </div>
  </aside>
<?php endif; ?>
