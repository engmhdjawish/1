<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;

WebSession::requirePermission('orders.view');
require dirname(__DIR__, 2) . '/views/helpers.php';

$query = [
    'customerSearch' => trim((string) ($_GET['customerSearch'] ?? '')),
    'customerGuid' => trim((string) ($_GET['customerGuid'] ?? '')),
    'fromDate' => trim((string) ($_GET['fromDate'] ?? '')),
    'toDate' => trim((string) ($_GET['toDate'] ?? '')),
    'pageSize' => 100,
    'page' => 1,
];

$result = null;
$error = null;
$customerMatches = [];
$selectedCustomerName = null;

if ($query['customerGuid'] === '' && $query['customerSearch'] !== '') {
    try {
        $lookup = ApiClient::get('/api/customers', [
            'search' => $query['customerSearch'],
            'page' => 1,
            'pageSize' => 20,
        ]);
        if ($lookup['ok']) {
            $customerMatches = $lookup['data']['items'] ?? [];
            if (count($customerMatches) === 1 && !empty($customerMatches[0]['guid'])) {
                $query['customerGuid'] = (string) $customerMatches[0]['guid'];
                $selectedCustomerName = (string) ($customerMatches[0]['customerName'] ?? '');
            }
        } else {
            $error = 'تعذر البحث عن العملاء (رمز ' . ($lookup['status'] ?? 0) . ')';
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$statementQuery = [
    'customerGuid' => $query['customerGuid'],
    'fromDate' => $query['fromDate'],
    'toDate' => $query['toDate'],
    'pageSize' => 100,
    'page' => 1,
];
if ($query['customerGuid'] !== '') {
    try {
        if ($selectedCustomerName === null) {
            $customerDetails = ApiClient::get('/api/customers/' . $query['customerGuid']);
            if ($customerDetails['ok']) {
                $selectedCustomerName = (string) ($customerDetails['data']['customerName'] ?? '');
            }
        }

        $result = ApiClient::get('/api/accounts/statement', $statementQuery);
        if (!$result['ok']) {
            $error = 'تعذر جلب كشف الحساب من API (رمز ' . ($result['status'] ?? 0) . ')';
        }
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$buildUrl = static function (array $params): string {
    return '/dashboard/accounting-statement.php?' . http_build_query(array_filter(
        $params,
        static fn ($value) => $value !== null && $value !== ''
    ));
};

$entries = $result['data']['entries'] ?? [];
$summary = $result['data'] ?? null;
$user = WebSession::user();
$currentRoute = '/dashboard/accounting-statement.php';

ob_start();
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2">كشف حساب عميل</h1>
  <p class="text-sm text-gray-600">بحث بالكلمات (اسم/هاتف) ثم عرض كشف الحساب مباشرة بدون إدخال معرفات تقنية يدويًا.</p>
</section>

<section class="bg-white border rounded-xl p-4 mb-4">
  <form method="get" class="grid md:grid-cols-4 gap-3 items-end">
    <label class="text-sm md:col-span-2">
      <span class="text-gray-600">بحث العميل (الاسم أو الهاتف)</span>
      <input
        type="text"
        id="customerSearchInput"
        name="customerSearch"
        value="<?= h($query['customerSearch']) ?>"
        class="mt-1 w-full border rounded px-3 py-2"
        placeholder="مثال: أحمد / 0932..."
      >
      <input type="hidden" id="customerGuidHidden" name="customerGuid" value="<?= h($query['customerGuid']) ?>">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">من تاريخ</span>
      <input type="date" name="fromDate" value="<?= h($query['fromDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <label class="text-sm">
      <span class="text-gray-600">إلى تاريخ</span>
      <input type="date" name="toDate" value="<?= h($query['toDate']) ?>" class="mt-1 w-full border rounded px-3 py-2">
    </label>
    <button class="bg-primary text-white rounded px-4 py-2">بحث / عرض</button>
  </form>
</section>

<script>
  (() => {
    const searchInput = document.getElementById('customerSearchInput');
    const guidInput = document.getElementById('customerGuidHidden');
    if (!searchInput || !guidInput) {
      return;
    }
    const original = searchInput.value.trim();
    searchInput.addEventListener('input', () => {
      if (searchInput.value.trim() !== original) {
        guidInput.value = '';
      }
    });
  })();
</script>

<?php if ($error): ?>
  <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
<?php endif; ?>

<?php if ($query['customerGuid'] !== ''): ?>
  <section class="mb-4">
    <p class="inline-flex items-center gap-2 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-sm">
      العميل المحدد: <strong><?= h($selectedCustomerName ?: 'تم تحديد عميل') ?></strong>
      <a href="<?= h($buildUrl(['customerSearch' => $query['customerSearch'], 'fromDate' => $query['fromDate'], 'toDate' => $query['toDate']])) ?>" class="text-blue-800 underline">تغيير</a>
    </p>
  </section>
<?php endif; ?>

<?php if ($query['customerGuid'] === '' && $query['customerSearch'] !== ''): ?>
  <section class="bg-white border rounded-xl overflow-hidden mb-4">
    <div class="px-4 py-3 border-b bg-gray-50">
      <h2 class="font-semibold">نتائج البحث عن العملاء</h2>
    </div>
    <?php if ($customerMatches === []): ?>
      <p class="p-4 text-sm text-gray-500">لا توجد نتائج مطابقة. جرّب كلمة أخرى.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-gray-50 text-gray-600 border-b">
            <tr>
              <th class="text-right p-3">الاسم</th>
              <th class="text-right p-3">الهاتف</th>
              <th class="text-right p-3">الحالة</th>
              <th class="text-right p-3">إجراء</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customerMatches as $match): ?>
              <tr class="border-b last:border-0">
                <td class="p-3 font-semibold"><?= h((string) ($match['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['mobile'] ?? $match['phone1'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['state'] ?? '—')) ?></td>
                <td class="p-3">
                  <a
                    href="<?= h($buildUrl([
                        'customerSearch' => $query['customerSearch'],
                        'customerGuid' => (string) ($match['guid'] ?? ''),
                        'fromDate' => $query['fromDate'],
                        'toDate' => $query['toDate'],
                    ])) ?>"
                    class="inline-flex rounded px-3 py-1.5 bg-primary text-white text-xs font-semibold"
                  >
                    عرض الكشف
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
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
    <p class="p-4 text-sm text-gray-500">ابحث عن العميل بالاسم/الهاتف ثم اختره لعرض كشف الحساب.</p>
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
