<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$query = [
    'accountGuid' => trim((string) ($_GET['accountGuid'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'pageSize' => 100,
    'page' => 1,
];

$result = null;
$error = null;
if ($query['accountGuid'] !== '' || $query['customerGuid'] !== '') {
    try {
        $result = ApiClient::get('/api/accounts/statement', $query);
        if (!$result['ok']) {
            $error = 'تعذر جلب كشف الحساب من API (رمز ' . ($result['status'] ?? 0) . ')';
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$entries = $result['data']['entries'] ?? [];
$summary = $result['data'] ?? null;
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-statement.php';

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">كشف حساب عميل</h1>
  <p class="text-sm text-gray-600">مرتبط مباشرة بـ <code>/api/accounts/statement</code>.</p>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <form method="get" class="grid md:grid-cols-5 gap-3 items-end">
    <label class="text-sm">
      <span class="text-gray-600">Customer GUID</span>
      <input type="text" name="customerGuid" value="<?= h($query['customerGuid']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">Account GUID</span>
      <input type="text" name="accountGuid" value="<?= h($query['accountGuid']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">من تاريخ</span>
      <input type="date" name="fromDate" value="<?= h($query['fromDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">إلى تاريخ</span>
      <input type="date" name="toDate" value="<?= h($query['toDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <button class="bg-primary text-white rounded px-4 py-2">عرض الكشف</button>
  </form>
</section>

<?php if ($error): ?>
  <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($summary && !$error): ?>
  <section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500">الرصيد الافتتاحي</div>
      <div class="font-bold mt-1"><?= number_format((float) ($summary['openingBalance'] ?? 0), 2, '.', ',') ?></div>
    </article>
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500">إجمالي المدين</div>
      <div class="font-bold mt-1"><?= number_format((float) ($summary['totalDebit'] ?? 0), 2, '.', ',') ?></div>
    </article>
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500">إجمالي الدائن</div>
      <div class="font-bold mt-1"><?= number_format((float) ($summary['totalCredit'] ?? 0), 2, '.', ',') ?></div>
    </article>
    <article class="bg-white border rounded-lg p-3">
      <div class="text-xs text-gray-500">الرصيد الختامي</div>
      <div class="font-bold mt-1"><?= number_format((float) ($summary['closingBalance'] ?? 0), 2, '.', ',') ?></div>
    </article>
  </section>
<?php endif; ?>

<section class="bg-white border rounded-xl overflow-hidden">
  <?php if ($entries === []): ?>
    <p class="p-4 text-sm text-gray-500">أدخل Account GUID أو Customer GUID لعرض كشف الحساب.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[920px]">
        <thead class="bg-gray-50 text-gray-600 border-b">
          <tr>
            <th class="text-right p-3">التاريخ</th>
            <th class="text-right p-3">الرقم</th>
            <th class="text-right p-3">المدين</th>
            <th class="text-right p-3">الدائن</th>
            <th class="text-right p-3">الرصيد الجاري</th>
            <th class="text-right p-3">المرجع</th>
            <th class="text-right p-3">الحساب المقابل</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr class="border-b last:border-0">
              <td class="p-3"><?= h((string) ($entry['entryDate'] ?? $entry['date'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($entry['entryNumber'] ?? $entry['number'] ?? '')) ?></td>
              <td class="p-3"><?= number_format((float) ($entry['debit'] ?? 0), 2, '.', ',') ?></td>
              <td class="p-3"><?= number_format((float) ($entry['credit'] ?? 0), 2, '.', ',') ?></td>
              <td class="p-3"><?= number_format((float) ($entry['runningBalance'] ?? 0), 2, '.', ',') ?></td>
              <td class="p-3"><?= h((string) ($entry['referenceNumber'] ?? '')) ?></td>
              <td class="p-3"><?= h((string) ($entry['contraAccountName'] ?? $entry['contraAccountCode'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'كشف حساب عميل';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
