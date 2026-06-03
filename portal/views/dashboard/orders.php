<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $orders */
/** @var array<string, int> $statusCounts */
/** @var array<string, int> $syncCounts */
/** @var array<string, mixed> $filters */
/** @var string|null $flash */
/** @var string $flashType */

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
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">إدارة الطلبات</h1>
  <p class="text-sm text-gray-600">متابعة الحالات التشغيلية + حالة مزامنة الأمين من قاعدة portal_db.</p>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded border px-3 py-2 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
  <?php foreach ($statusLabels as $key => $label): ?>
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500"><?= h($label) ?></div>
      <div class="text-xl font-bold mt-1"><?= (int) ($statusCounts[$key] ?? 0) ?></div>
    </article>
  <?php endforeach; ?>
</section>

<section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
  <?php foreach ($syncLabels as $key => $label): ?>
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500"><?= h($label) ?></div>
      <div class="text-xl font-bold mt-1"><?= (int) ($syncCounts[$key] ?? 0) ?></div>
    </article>
  <?php endforeach; ?>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <form method="get" class="grid md:grid-cols-4 gap-3 items-end">
    <label class="text-sm">
      <span class="text-gray-600">بحث</span>
      <input type="text" name="q" value="<?= h((string) ($filters['q'] ?? '')) ?>" class="mt-1 w-full border rounded px-3 py-2" placeholder="رقم طلب / اسم عميل / هاتف">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">حالة الطلب</span>
      <select name="status" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">الكل</option>
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-gray-600">حالة المزامنة</span>
      <select name="sync" class="mt-1 w-full border rounded px-3 py-2">
        <option value="">الكل</option>
        <?php foreach ($syncLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['sync'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="bg-primary text-white rounded px-4 py-2">تطبيق الفلاتر</button>
  </form>
</section>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($orders === []): ?>
    <p class="p-4 text-sm text-gray-500">لا توجد طلبات مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[960px]">
        <thead class="bg-gray-50 text-gray-600 border-b">
          <tr>
            <th class="text-right p-3">رقم الطلب</th>
            <th class="text-right p-3">العميل</th>
            <th class="text-right p-3">العناصر</th>
            <th class="text-right p-3">الإجمالي (ل.س)</th>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3">مزامنة الأمين</th>
            <th class="text-right p-3">تاريخ الإنشاء</th>
            <th class="text-right p-3">إجراء سريع</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $row): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-semibold"><?= h((string) ($row['order_number'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: '—')) ?></td>
              <td class="p-3"><?= (int) ($row['items_count'] ?? 0) ?></td>
              <td class="p-3"><?= number_format((float) ($row['total_sp'] ?? 0), 0, '.', ',') ?></td>
              <td class="p-3"><?= h($statusLabels[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? '-')) ?></td>
              <td class="p-3"><?= h($syncLabels[(string) ($row['amine_sync_status'] ?? '')] ?? (string) ($row['amine_sync_status'] ?? '-')) ?></td>
              <td class="p-3 text-xs text-gray-500"><?= h((string) ($row['created_at'] ?? '')) ?></td>
              <td class="p-3">
                <form method="post" class="flex items-center gap-2">
                  <input type="hidden" name="order_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                  <select name="next_status" class="border rounded px-2 py-1 text-xs">
                    <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                      <option value="<?= h($statusKey) ?>" <?= ($row['status'] ?? '') === $statusKey ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="px-2 py-1 text-xs bg-gray-900 text-white rounded">حفظ</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
