<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Services\WebCustomerService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$pendingCustomers = count(WebCustomerService::listPending());
$orderCounts = OrderService::statusCounts();
$syncCounts = OrderService::syncCounts();
$recentOrders = OrderService::list(['limit' => 8]);
$user = WebSession::user();
$currentRoute = '/dashboard/index.php';

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">مرحباً <?= h($user['display_name_ar'] ?? '') ?></h1>
  <p class="text-sm text-gray-600">ملخص سريع لحالة العملاء، الطلبات، وطابور مزامنة الأمين.</p>
</section>

<section class="grid gap-3 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 mb-4">
  <article class="bg-white border rounded-xl p-4">
    <div class="text-xs text-gray-500">عملاء بانتظار الموافقة</div>
    <div class="text-2xl font-bold text-primary mt-1"><?= (int) $pendingCustomers ?></div>
  </article>
  <article class="bg-white border rounded-xl p-4">
    <div class="text-xs text-gray-500">طلبات جديدة / قيد التأكيد</div>
    <div class="text-2xl font-bold mt-1"><?= (int) ($orderCounts['pending'] + $orderCounts['confirmed']) ?></div>
  </article>
  <article class="bg-white border rounded-xl p-4">
    <div class="text-xs text-gray-500">طلبات مكتملة</div>
    <div class="text-2xl font-bold mt-1 text-green-700"><?= (int) ($orderCounts['completed'] ?? 0) ?></div>
  </article>
  <article class="bg-white border rounded-xl p-4">
    <div class="text-xs text-gray-500">مزامنة أمين فاشلة</div>
    <div class="text-2xl font-bold mt-1 text-red-700"><?= (int) ($syncCounts['failed'] ?? 0) ?></div>
  </article>
</section>

<section class="grid gap-4 grid-cols-1 xl:grid-cols-[1fr_340px]">
  <article class="bg-white border rounded-xl p-4">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold">آخر الطلبات</h2>
      <a href="/dashboard/orders.php" class="text-sm text-primary">فتح إدارة الطلبات</a>
    </div>
    <?php if ($recentOrders === []): ?>
      <p class="text-sm text-gray-500">لا توجد طلبات بعد.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-gray-500 border-b">
              <th class="text-right py-2">رقم الطلب</th>
              <th class="text-right py-2">العميل</th>
              <th class="text-right py-2">الحالة</th>
              <th class="text-right py-2">المزامنة</th>
              <th class="text-right py-2">الإجمالي (ل.س)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $row): ?>
              <tr class="border-b last:border-0">
                <td class="py-2 font-medium"><?= h($row['order_number']) ?></td>
                <td class="py-2"><?= h($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: '—') ?></td>
                <td class="py-2"><?= h($row['status']) ?></td>
                <td class="py-2"><?= h($row['amine_sync_status']) ?></td>
                <td class="py-2"><?= number_format((float) ($row['total_sp'] ?? 0), 0, '.', ',') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
  <article class="bg-white border rounded-xl p-4">
    <h2 class="font-semibold mb-2">روابط سريعة</h2>
    <div class="space-y-2 text-sm">
      <a href="/dashboard/customers.php" class="block rounded border px-3 py-2 hover:bg-gray-50">إدارة العملاء</a>
      <a href="/dashboard/orders.php" class="block rounded border px-3 py-2 hover:bg-gray-50">إدارة الطلبات</a>
      <a href="/dashboard/share-links.php" class="block rounded border px-3 py-2 hover:bg-gray-50">روابط المشاركة</a>
      <a href="/dashboard/accounting-sync.php" class="block rounded border px-3 py-2 hover:bg-gray-50">طابور المزامنة</a>
    </div>
  </article>
</section>
<?php
$content = ob_get_clean();
$title = 'لوحة التحكم';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
