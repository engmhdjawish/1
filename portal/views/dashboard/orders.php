<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $orders */
/** @var array<string, int> $statusCounts */
/** @var array<string, int> $syncCounts */
/** @var array<string, mixed> $filters */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canManageOrders */

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

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-2xl p-5 mb-5">
  <form method="get" class="grid grid-cols-1 lg:grid-cols-5 gap-4 items-end">
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
                    <form method="post" class="flex items-center gap-2">
                      <input type="hidden" name="order_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                      <select name="next_status" class="h-9 rounded-lg border border-border-subtle px-2 text-xs">
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                          <option value="<?= h($statusKey) ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="h-9 px-3 rounded-lg bg-primary text-white text-xs font-bold">حفظ</button>
                    </form>
                  <?php else: ?>
                    <button class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-border-subtle text-text-muted">
                      <span class="material-symbols-outlined">visibility</span>
                    </button>
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
