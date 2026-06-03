<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\OrderService;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$syncCounts = OrderService::syncCounts();
$statusCounts = OrderService::statusCounts();
$user = WebSession::user();
$currentRoute = '/dashboard/accounting.php';

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">لوحة المحاسب</h1>
  <p class="text-sm text-gray-600">ملخص مالي وتشغيلي سريع بالاعتماد على بيانات الطلبات.</p>
</section>

<section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">طلبات مؤكدة</div>
    <div class="text-xl font-bold mt-1"><?= (int) ($statusCounts['confirmed'] ?? 0) ?></div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">طلبات مكتملة</div>
    <div class="text-xl font-bold mt-1 text-green-700"><?= (int) ($statusCounts['completed'] ?? 0) ?></div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">بانتظار مزامنة</div>
    <div class="text-xl font-bold mt-1 text-yellow-700"><?= (int) ($syncCounts['pending'] ?? 0) ?></div>
  </article>
  <article class="bg-white border rounded-lg p-3">
    <div class="text-xs text-gray-500">فشل مزامنة</div>
    <div class="text-xl font-bold mt-1 text-red-700"><?= (int) ($syncCounts['failed'] ?? 0) ?></div>
  </article>
</section>

<section class="bg-white border rounded-xl p-4">
  <h2 class="font-semibold mb-2">اختصارات المحاسبة</h2>
  <div class="grid gap-2 md:grid-cols-3 text-sm">
    <a href="/dashboard/accounting-sync.php" class="rounded border px-3 py-2 hover:bg-gray-50">طابور المزامنة</a>
    <a href="/dashboard/accounting-reports.php" class="rounded border px-3 py-2 hover:bg-gray-50">التقارير المالية</a>
    <a href="/dashboard/accounting-statement.php" class="rounded border px-3 py-2 hover:bg-gray-50">كشف حساب عميل</a>
  </div>
</section>
<?php
$content = ob_get_clean();
$title = 'لوحة المحاسب';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
