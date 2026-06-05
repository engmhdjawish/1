<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;
use Portal\Services\ShareLinkService;
use Portal\Services\WebCustomerService;

WebSession::requireLogin();
require dirname(__DIR__, 2) . '/views/helpers.php';

$pendingCustomers = count(WebCustomerService::listPending());
$pendingRegistrations = WebCustomerService::listByStatus('pending', '', '', 3);
$orderCounts = OrderService::statusCounts();
$syncCounts = OrderService::syncCounts();
$recentOrders = OrderService::list(['limit' => 8]);
$reviewOrders = OrderService::list(['status' => 'pending', 'limit' => 3]);
$syncQueue = OrderService::list(['sync' => 'failed', 'limit' => 3]);
$activeLinks = ShareLinkService::countActive();
$user = WebSession::user();
$currentRoute = '/dashboard/index.php';

ob_start();
?>
<section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <article class="bg-red-50 border border-red-100 rounded-2xl p-5 hover:shadow-md transition">
    <div class="flex items-center justify-between">
      <div class="w-11 h-11 rounded-xl bg-red-100 text-red-700 flex items-center justify-center">
        <span class="material-symbols-outlined">person_add</span>
      </div>
      <span class="text-xs text-red-700 font-bold">+8%</span>
    </div>
    <div class="mt-4">
      <p class="text-3xl font-extrabold"><?= (int) $pendingCustomers ?></p>
      <p class="text-sm text-text-muted mt-1">عملاء بانتظار الموافقة</p>
    </div>
  </article>
  <article class="bg-amber-50 border border-amber-100 rounded-2xl p-5 hover:shadow-md transition">
    <div class="flex items-center justify-between">
      <div class="w-11 h-11 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center">
        <span class="material-symbols-outlined">shopping_basket</span>
      </div>
      <span class="text-xs text-emerald-700 font-bold">+12%</span>
    </div>
    <div class="mt-4">
      <p class="text-3xl font-extrabold"><?= (int) (($orderCounts['pending'] ?? 0) + ($orderCounts['confirmed'] ?? 0)) ?></p>
      <p class="text-sm text-text-muted mt-1">طلبات جديدة / قيد التأكيد</p>
    </div>
  </article>
  <article class="bg-white border border-border-subtle rounded-2xl p-5 hover:shadow-md transition">
    <div class="flex items-center justify-between">
      <div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center">
        <span class="material-symbols-outlined">link</span>
      </div>
      <span class="text-xs text-text-muted font-bold">0%</span>
    </div>
    <div class="mt-4">
      <p class="text-3xl font-extrabold"><?= (int) $activeLinks ?></p>
      <p class="text-sm text-text-muted mt-1">روابط مشاركة نشطة</p>
    </div>
  </article>
  <article class="bg-white border border-border-subtle rounded-2xl p-5 hover:shadow-md transition">
    <div class="flex items-center justify-between">
      <div class="w-11 h-11 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center">
        <span class="material-symbols-outlined">sync_problem</span>
      </div>
      <span class="text-xs text-red-700 font-bold"><?= (int) ($syncCounts['failed'] ?? 0) ?></span>
    </div>
    <div class="mt-4">
      <p class="text-3xl font-extrabold"><?= (int) (($syncCounts['pending'] ?? 0) + ($syncCounts['failed'] ?? 0)) ?></p>
      <p class="text-sm text-text-muted mt-1">طابور مزامنة الأمين</p>
    </div>
  </article>
</section>

<section class="mb-6">
  <div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-bold">يتطلب إجراء</h2>
    <a href="/dashboard/orders.php" class="text-sm text-primary font-bold hover:underline">عرض الكل</a>
  </div>
  <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    <article class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
      <div class="px-4 py-3 bg-surface-low border-b border-border-subtle flex items-center justify-between">
        <h3 class="font-bold">تسجيلات جديدة</h3>
        <span class="px-2 py-0.5 text-xs rounded-full bg-primary text-white"><?= (int) $pendingCustomers ?></span>
      </div>
      <div class="divide-y divide-border-subtle">
        <?php if ($pendingRegistrations === []): ?>
          <p class="px-4 py-6 text-sm text-text-muted">لا توجد تسجيلات جديدة.</p>
        <?php endif; ?>
        <?php foreach ($pendingRegistrations as $row): ?>
          <div class="p-4">
            <div class="flex items-center justify-between mb-3">
              <div>
                <p class="font-bold text-sm"><?= h((string) ($row['name_ar'] ?? '')) ?></p>
                <p class="text-xs text-text-muted"><?= h((string) ($row['phone'] ?? '')) ?></p>
              </div>
              <span class="text-xs text-text-muted"><?= h((string) ($row['created_at'] ?? '')) ?></span>
            </div>
            <div class="flex gap-2">
              <a href="/dashboard/customers.php?status=pending" class="flex-1 text-center bg-green-600 text-white rounded-lg py-1.5 text-xs font-bold">موافقة</a>
              <a href="/dashboard/customers.php?status=pending" class="flex-1 text-center bg-red-600 text-white rounded-lg py-1.5 text-xs font-bold">رفض</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
      <div class="px-4 py-3 bg-surface-low border-b border-border-subtle flex items-center justify-between">
        <h3 class="font-bold">طلبات قيد المراجعة</h3>
        <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-700"><?= (int) ($orderCounts['pending'] ?? 0) ?></span>
      </div>
      <div class="divide-y divide-border-subtle">
        <?php if ($reviewOrders === []): ?>
          <p class="px-4 py-6 text-sm text-text-muted">لا توجد طلبات قيد المراجعة.</p>
        <?php endif; ?>
        <?php foreach ($reviewOrders as $row): ?>
          <div class="px-4 py-3 flex items-center justify-between">
            <div>
              <p class="font-bold text-sm"><?= h((string) ($row['order_number'] ?? '')) ?></p>
              <p class="text-xs text-text-muted"><?= h((string) ($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: 'عميل')) ?></p>
            </div>
            <span class="font-bold text-sm"><?= number_format((float) ($row['total_sp'] ?? 0), 0, '.', ',') ?> ل.س</span>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
      <div class="px-4 py-3 bg-surface-low border-b border-border-subtle flex items-center justify-between">
        <h3 class="font-bold">أخطاء المزامنة</h3>
        <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-700"><?= (int) ($syncCounts['failed'] ?? 0) ?></span>
      </div>
      <div class="divide-y divide-border-subtle">
        <?php if ($syncQueue === []): ?>
          <p class="px-4 py-6 text-sm text-text-muted">لا يوجد فشل مزامنة حاليًا.</p>
        <?php endif; ?>
        <?php foreach ($syncQueue as $row): ?>
          <div class="px-4 py-3 flex items-center justify-between">
            <div>
              <p class="font-bold text-sm"><?= h((string) ($row['order_number'] ?? '')) ?></p>
              <p class="text-xs text-text-muted"><?= h((string) ($row['updated_at'] ?? '')) ?></p>
            </div>
            <a href="/dashboard/accounting-sync.php?sync=failed" class="text-primary text-xs font-bold">متابعة</a>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </div>
</section>

<section class="bg-white border border-border-subtle rounded-2xl overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between">
    <h2 class="font-bold">آخر الطلبات</h2>
    <a href="/dashboard/orders.php" class="text-sm text-primary font-bold">فتح إدارة الطلبات</a>
  </div>
  <?php if ($recentOrders === []): ?>
    <p class="p-4 text-sm text-text-muted">لا توجد طلبات بعد.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[760px]">
        <thead class="bg-surface-low text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right px-4 py-3">رقم الطلب</th>
            <th class="text-right px-4 py-3">العميل</th>
            <th class="text-right px-4 py-3">الحالة</th>
            <th class="text-right px-4 py-3">المزامنة</th>
            <th class="text-right px-4 py-3">الإجمالي</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($recentOrders as $row): ?>
            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3 font-bold text-primary"><?= h((string) ($row['order_number'] ?? '')) ?></td>
              <td class="px-4 py-3"><?= h((string) ($row['customer_name_ar'] ?: $row['guest_name_ar'] ?: '—')) ?></td>
              <td class="px-4 py-3"><?= h((string) ($row['status'] ?? '')) ?></td>
              <td class="px-4 py-3"><?= h((string) ($row['amine_sync_status'] ?? '')) ?></td>
              <td class="px-4 py-3 font-bold"><?= number_format((float) ($row['total_sp'] ?? 0), 0, '.', ',') ?> ل.س</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'لوحة التحكم';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
