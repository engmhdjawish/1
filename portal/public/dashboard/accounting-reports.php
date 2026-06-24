<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;

WebSession::requireAnyPermission(['accounting.reports.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$summary = OrderService::financialSummary();
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-reports.php';

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">التقارير المالية</h1>
  <p class="text-sm text-gray-600">تجميع مالي أولي حسب حالة الطلب من portal_db.</p>
</section>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($summary === []): ?>
    <p class="p-4 text-sm text-gray-500">لا توجد بيانات مالية حتى الآن.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[680px]">
        <thead class="bg-gray-50 text-gray-600 border-b">
          <tr>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3">عدد الطلبات</th>
            <th class="text-right p-3">الإجمالي بالليرة</th>
            <th class="text-right p-3">الإجمالي بالدولار</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($summary as $row): ?>
            <tr class="border-b last:border-0">
              <td class="p-3"><?= h($row['status']) ?></td>
              <td class="p-3"><?= (int) $row['orders_count'] ?></td>
              <td class="p-3"><?= number_format((float) $row['total_sp'], 0, '.', ',') ?> ل.س</td>
              <td class="p-3"><?= number_format((float) $row['total_usd'], 2, '.', ',') ?> $</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="mt-4 bg-white border rounded-xl p-4">
  <h2 class="text-sm font-bold mb-2">تفاصيل إضافية</h2>
  <p class="text-sm text-gray-600 mb-3">لمراجعة الطلبات حسب نوع العميل (زائر / مسجّل) أو حسب عميل معيّن، استخدم صفحات الطلبات والعملاء.</p>
  <div class="flex flex-wrap gap-2">
    <a href="/dashboard/orders.php" class="inline-flex h-9 items-center px-4 rounded-lg bg-primary text-white text-xs font-bold">كل الطلبات</a>
    <a href="/dashboard/orders.php?origin=guest" class="inline-flex h-9 items-center px-4 rounded-lg border text-xs font-bold">طلبات الزوار</a>
    <a href="/dashboard/orders.php?origin=registered" class="inline-flex h-9 items-center px-4 rounded-lg border text-xs font-bold">طلبات العملاء المسجّلين</a>
    <a href="/dashboard/customers.php" class="inline-flex h-9 items-center px-4 rounded-lg border text-xs font-bold">عملاء الموقع</a>
  </div>
</section>
<?php
$content = ob_get_clean();
$title = 'التقارير المالية';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
