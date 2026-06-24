<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Support\DashboardNavigation;

WebSession::requirePermission('accounting.sync.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$filters = ['sync' => trim((string) ($_GET['sync'] ?? 'pending')), 'limit' => 100];
if ($filters['sync'] === '') {
    $filters['sync'] = 'pending';
}
$orders = OrderService::list($filters);
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-sync.php';
if (!DashboardNavigation::canAccessAccountingArea($user)) {
    $dashboardNavArea = DashboardNavigation::AREA_OPERATIONS;
}

$syncLabels = [
    'pending' => 'بانتظار المزامنة',
    'failed' => 'فشل المزامنة',
    'synced' => 'تمت المزامنة',
    'none' => 'بدون مزامنة',
];

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">طابور مزامنة الأمين</h1>
  <p class="text-sm text-gray-600">قائمة الطلبات غير المرحّلة أو التي فشلت مزامنتها.</p>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <form method="get" class="flex flex-wrap gap-3 items-end">
    <label class="text-sm">
      <span class="text-gray-600">حالة المزامنة</span>
      <select name="sync" class="mt-1 border rounded px-3 py-2">
        <?php foreach ($syncLabels as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= ($filters['sync'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="bg-primary text-white rounded px-4 py-2">تحديث</button>
  </form>
</section>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($orders === []): ?>
    <p class="p-4 text-sm text-gray-500">لا توجد عناصر في طابور المزامنة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[760px]">
        <thead class="bg-gray-50 text-gray-600 border-b">
          <tr>
            <th class="text-right p-3">رقم الطلب</th>
            <th class="text-right p-3">العميل</th>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3">حالة المزامنة</th>
            <th class="text-right p-3">آخر تحديث</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $row): ?>
            <tr class="border-b last:border-0">
              <td class="p-3 font-semibold"><?= h((string) ($row['order_number'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: '—')) ?></td>
              <td class="p-3"><?= h((string) ($row['status'] ?? '')) ?></td>
              <td class="p-3"><?= h($syncLabels[(string) ($row['amine_sync_status'] ?? '')] ?? (string) ($row['amine_sync_status'] ?? '')) ?></td>
              <td class="p-3 text-xs text-gray-500"><?= h((string) ($row['updated_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'طابور المزامنة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
