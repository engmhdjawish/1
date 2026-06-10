<?php

declare(strict_types=1);

/** @var array<string, string|int> $query */
/** @var list<array<string, mixed>> $customerMatches */
/** @var list<array<string, mixed>> $accountMatches */
/** @var array<string, mixed>|null $summary */
/** @var list<array<string, mixed>> $entries */
/** @var string|null $selectedCustomerName */
/** @var string|null $selectedAccountName */
/** @var float $totalDebit */
/** @var float $totalCredit */
/** @var float $closingBalance */
/** @var int $totalCount */
/** @var int $totalPages */
/** @var string|null $error */

$query = is_array($query ?? null) ? $query : [];
$customerMatches = is_array($customerMatches ?? null) ? $customerMatches : [];
$accountMatches = is_array($accountMatches ?? null) ? $accountMatches : [];
$entries = is_array($entries ?? null) ? $entries : [];
$summary = is_array($summary ?? null) ? $summary : null;
$totalCount = (int) ($totalCount ?? 0);
$totalPages = max(1, (int) ($totalPages ?? 1));

$statementParams = [
    'customerSearch' => (string) ($query['customerSearch'] ?? ''),
    'accountSearch' => (string) ($query['accountSearch'] ?? ''),
    'customerGuid' => (string) ($query['customerGuid'] ?? ''),
    'accountGuid' => (string) ($query['accountGuid'] ?? ''),
    'fromDate' => (string) ($query['fromDate'] ?? ''),
    'toDate' => (string) ($query['toDate'] ?? ''),
    'pageSize' => (string) ($query['pageSize'] ?? 50),
];

$hasSelection = ($query['customerGuid'] ?? '') !== '' || ($query['accountGuid'] ?? '') !== '';
$showCustomerResults = !$hasSelection && ($query['customerSearch'] ?? '') !== '';
$showAccountResults = !$hasSelection && ($query['accountSearch'] ?? '') !== '';
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">كشف حساب</h1>
      <p class="text-sm text-text-muted mt-1">ابحث باسم العميل أو اسم الحساب أو كليهما، ثم اعرض الحركات مع روابط الفواتير والسندات.</p>
    </div>
    <div class="flex flex-wrap gap-3 text-sm">
      <?php if (web_can('accounting.customers.view')): ?>
        <a href="/dashboard/accounting-customers.php" class="text-primary font-semibold">عملاء الأمين</a>
      <?php endif; ?>
      <?php if (web_can('accounting.documents.view')): ?>
        <a href="/dashboard/accounting-documents.php" class="text-primary font-semibold">الفواتير والسندات</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="rounded-xl border border-border-subtle bg-white p-4 mb-4 shadow-sm">
  <form method="get" class="grid md:grid-cols-2 xl:grid-cols-6 gap-3 items-end">
    <label class="text-sm xl:col-span-2 relative">
      <span class="text-text-muted">بحث العميل (الاسم أو الهاتف)</span>
      <input
        type="text"
        id="customerSearchInput"
        name="customerSearch"
        value="<?= h((string) ($query['customerSearch'] ?? '')) ?>"
        class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5"
        placeholder="اسم أو هاتف..."
        autocomplete="off"
      >
      <input type="hidden" id="customerGuidHidden" name="customerGuid" value="<?= h((string) ($query['customerGuid'] ?? '')) ?>">
      <div id="customerSuggestPanel" class="hidden absolute z-30 mt-1 w-full bg-white border border-border-subtle rounded-xl shadow-lg max-h-64 overflow-auto"></div>
    </label>
    <label class="text-sm xl:col-span-2 relative">
      <span class="text-text-muted">بحث الحساب (الاسم أو الرمز)</span>
      <input
        type="text"
        id="accountSearchInput"
        name="accountSearch"
        value="<?= h((string) ($query['accountSearch'] ?? '')) ?>"
        class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5"
        placeholder="اسم أو رمز الحساب..."
        autocomplete="off"
      >
      <input type="hidden" id="accountGuidHidden" name="accountGuid" value="<?= h((string) ($query['accountGuid'] ?? '')) ?>">
      <div id="accountSuggestPanel" class="hidden absolute z-30 mt-1 w-full bg-white border border-border-subtle rounded-xl shadow-lg max-h-64 overflow-auto"></div>
    </label>
    <label class="text-sm">
      <span class="text-text-muted">من تاريخ</span>
      <input type="date" name="fromDate" value="<?= h((string) ($query['fromDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">إلى تاريخ</span>
      <input type="date" name="toDate" value="<?= h((string) ($query['toDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <div class="md:col-span-2 xl:col-span-6 flex flex-wrap gap-2 items-center">
      <p class="text-xs text-text-muted">البحث تلقائي — اختر من القائمة لعرض الكشف</p>
      <?php if ($hasSelection): ?>
        <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', [
            'customerSearch' => (string) ($query['customerSearch'] ?? ''),
            'accountSearch' => (string) ($query['accountSearch'] ?? ''),
            'fromDate' => (string) ($query['fromDate'] ?? ''),
            'toDate' => (string) ($query['toDate'] ?? ''),
        ])) ?>" class="rounded-xl border border-border-subtle px-4 py-2.5 text-sm font-semibold text-text-muted hover:bg-surface-low transition">مسح الاختيار</a>
      <?php endif; ?>
    </div>
  </form>
</section>

<script>
  (() => {
    const pairs = [
      ['customerSearchInput', 'customerGuidHidden'],
      ['accountSearchInput', 'accountGuidHidden'],
    ];

    pairs.forEach(([searchId, guidId]) => {
      const searchInput = document.getElementById(searchId);
      const guidInput = document.getElementById(guidId);
      if (!searchInput || !guidInput) return;
      const original = searchInput.value.trim();
      searchInput.addEventListener('input', () => {
        if (searchInput.value.trim() !== original) {
          guidInput.value = '';
        }
      });
    });
  })();
</script>

<?php if ($hasSelection): ?>
  <div class="mb-4 flex flex-wrap gap-2">
    <?php if (($query['customerGuid'] ?? '') !== '' && $selectedCustomerName): ?>
      <div class="inline-flex items-center gap-2 rounded-full bg-primary/10 text-primary px-4 py-2 text-sm">
        العميل: <strong><?= h($selectedCustomerName) ?></strong>
      </div>
    <?php endif; ?>
    <?php if (($query['accountGuid'] ?? '') !== '' && $selectedAccountName): ?>
      <div class="inline-flex items-center gap-2 rounded-full bg-slate-100 text-slate-800 px-4 py-2 text-sm">
        الحساب: <strong><?= h($selectedAccountName) ?></strong>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($showCustomerResults): ?>
  <section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-4 shadow-sm">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">نتائج بحث العملاء</h2>
    </div>
    <?php if ($customerMatches === []): ?>
      <p class="p-4 text-sm text-text-muted">لا توجد نتائج للعملاء. جرّب كلمة أخرى.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="text-text-muted border-b border-border-subtle bg-surface-low/40">
            <tr>
              <th class="text-right p-3">الاسم</th>
              <th class="text-right p-3">الهاتف</th>
              <th class="text-right p-3">الحالة</th>
              <th class="text-right p-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customerMatches as $index => $match): ?>
              <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-surface-low/50' ?> border-b border-border-subtle last:border-0">
                <td class="p-3 font-semibold"><?= h((string) ($match['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['mobile'] ?? $match['phone1'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['state'] ?? '—')) ?></td>
                <td class="p-3">
                  <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, [
                      'customerGuid' => (string) ($match['guid'] ?? ''),
                      'page' => 1,
                  ]))) ?>" class="inline-flex rounded-lg px-3 py-1.5 bg-primary text-white text-xs font-bold">عرض الكشف</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($showAccountResults): ?>
  <section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-4 shadow-sm">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">نتائج بحث الحسابات</h2>
    </div>
    <?php if ($accountMatches === []): ?>
      <p class="p-4 text-sm text-text-muted">لا توجد نتائج للحسابات. جرّب كلمة أخرى.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="text-text-muted border-b border-border-subtle bg-surface-low/40">
            <tr>
              <th class="text-right p-3">الحساب</th>
              <th class="text-right p-3">الرمز</th>
              <th class="text-right p-3">الرقم</th>
              <th class="text-right p-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accountMatches as $index => $match): ?>
              <?php
              $accountLabel = trim((string) ($match['name'] ?? ''));
              if ($accountLabel === '') {
                  $accountLabel = (string) ($match['code'] ?? $match['number'] ?? '—');
              }
              ?>
              <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-surface-low/50' ?> border-b border-border-subtle last:border-0">
                <td class="p-3 font-semibold"><?= h($accountLabel) ?></td>
                <td class="p-3"><?= h((string) ($match['code'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['number'] ?? '—')) ?></td>
                <td class="p-3">
                  <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, [
                      'accountGuid' => (string) ($match['guid'] ?? ''),
                      'page' => 1,
                  ]))) ?>" class="inline-flex rounded-lg px-3 py-1.5 bg-primary text-white text-xs font-bold">عرض الكشف</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($summary): ?>
  <?php
  $currencySymbol = (string) ($summary['accountCurrencySymbol'] ?? '');
  $currencyCode = (string) ($summary['accountCurrencyCode'] ?? '');
  ?>
  <section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-4">
    <article class="rounded-xl border border-border-subtle bg-white p-4 shadow-sm">
      <div class="text-xs text-text-muted">الرصيد الافتتاحي</div>
      <div class="font-extrabold mt-1 text-lg"><?= h(format_accounting_money($summary['openingBalance'] ?? null, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4 shadow-sm">
      <div class="text-xs text-text-muted">إجمالي المدين</div>
      <div class="font-extrabold mt-1 text-lg text-emerald-700"><?= h(format_accounting_money($totalDebit, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4 shadow-sm">
      <div class="text-xs text-text-muted">إجمالي الدائن</div>
      <div class="font-extrabold mt-1 text-lg text-rose-700"><?= h(format_accounting_money($totalCredit, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4 shadow-sm">
      <div class="text-xs text-text-muted">الرصيد الختامي</div>
      <div class="font-extrabold mt-1 text-lg"><?= h(format_accounting_money($closingBalance, $currencySymbol, $currencyCode)) ?></div>
    </article>
  </section>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden shadow-sm">
  <?php if (!$hasSelection): ?>
    <p class="p-6 text-sm text-text-muted">ابحث عن عميل أو حساب لعرض كشف الحساب.</p>
  <?php elseif ($entries === []): ?>
    <p class="p-6 text-sm text-text-muted">لا توجد حركات في الفترة المحددة.</p>
  <?php else: ?>
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-bold">حركات الحساب</h2>
      <span class="text-xs text-text-muted"><?= h((string) $totalCount) ?> حركة — صفحة <?= h((string) ($query['page'] ?? 1)) ?> من <?= h((string) $totalPages) ?></span>
    </div>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[980px] statement-table">
        <thead class="text-text-muted border-b border-border-subtle bg-surface-low/80 sticky top-0 z-10">
          <tr>
            <th class="text-right p-3 whitespace-nowrap">التاريخ</th>
            <th class="text-right p-3 whitespace-nowrap">النوع</th>
            <th class="text-right p-3 whitespace-nowrap">المرجع</th>
            <th class="text-right p-3 whitespace-nowrap">الحساب المقابل</th>
            <th class="text-right p-3 whitespace-nowrap">المدين</th>
            <th class="text-right p-3 whitespace-nowrap">الدائن</th>
            <th class="text-right p-3 whitespace-nowrap">الرصيد الجاري</th>
            <th class="text-right p-3 min-w-[180px]">ملاحظات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $index => $entry): ?>
            <?php
            $docKind = accounting_document_kind((string) ($entry['reasonType'] ?? ''));
            $referenceGuid = (string) ($entry['referenceGuid'] ?? '');
            $referenceNumber = (string) ($entry['referenceNumber'] ?? '');
            $debit = (float) ($entry['debit'] ?? 0);
            $credit = (float) ($entry['credit'] ?? 0);
            $balance = (float) ($entry['runningBalance'] ?? 0);
            $notes = trim((string) ($entry['notes'] ?? $entry['referenceNotes'] ?? ''));
            ?>
            <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-surface-low/40' ?> border-b border-border-subtle last:border-0 hover:bg-primary/5 transition-colors">
              <td class="p-3 whitespace-nowrap font-medium"><?= h(accounting_format_date($entry['entryDate'] ?? $entry['date'] ?? null)) ?></td>
              <td class="p-3 whitespace-nowrap"><?= h((string) ($entry['reasonDocumentType'] ?? $entry['reasonType'] ?? '—')) ?></td>
              <td class="p-3 whitespace-nowrap">
                <?php if ($docKind !== null && $referenceGuid !== ''): ?>
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 text-primary font-semibold hover:underline doc-ref-btn"
                    data-ref-guid="<?= h($referenceGuid) ?>"
                    data-ref-kind="<?= h($docKind === 'invoices' ? 'invoice' : 'voucher') ?>"
                    title="عرض المستند"
                  >
                    <span class="material-symbols-outlined text-base">description</span>
                    <?= h($referenceNumber !== '' ? $referenceNumber : 'عرض') ?>
                  </button>
                <?php else: ?>
                  <?= h($referenceNumber !== '' ? $referenceNumber : '—') ?>
                <?php endif; ?>
              </td>
              <td class="p-3"><?= h((string) ($entry['contraAccountName'] ?? $entry['contraAccountCode'] ?? '—')) ?></td>
              <td class="p-3 whitespace-nowrap tabular-nums <?= $debit > 0 ? 'text-emerald-700 font-semibold' : 'text-text-muted' ?>">
                <?= $debit > 0 ? h(format_decimal($debit)) : '—' ?>
              </td>
              <td class="p-3 whitespace-nowrap tabular-nums <?= $credit > 0 ? 'text-rose-700 font-semibold' : 'text-text-muted' ?>">
                <?= $credit > 0 ? h(format_decimal($credit)) : '—' ?>
              </td>
              <td class="p-3 whitespace-nowrap tabular-nums font-bold"><?= h(format_decimal($balance)) ?></td>
              <td class="p-3 text-text-muted text-xs leading-relaxed"><?= h($notes !== '' ? $notes : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <?php
      $currentPage = max(1, (int) ($query['page'] ?? 1));
      $windowStart = max(1, $currentPage - 2);
      $windowEnd = min($totalPages, $currentPage + 2);
      ?>
      <nav class="px-4 py-3 border-t border-border-subtle bg-surface-low/30 flex flex-wrap items-center justify-center gap-1" aria-label="ترقيم الصفحات">
        <?php if ($currentPage > 1): ?>
          <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, ['page' => $currentPage - 1]))) ?>" class="rounded-lg px-3 py-1.5 text-sm border border-border-subtle hover:bg-white transition">السابق</a>
        <?php endif; ?>

        <?php if ($windowStart > 1): ?>
          <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, ['page' => 1]))) ?>" class="rounded-lg px-3 py-1.5 text-sm border border-border-subtle hover:bg-white transition">1</a>
          <?php if ($windowStart > 2): ?>
            <span class="px-2 text-text-muted">…</span>
          <?php endif; ?>
        <?php endif; ?>

        <?php for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++): ?>
          <?php if ($pageNumber === $currentPage): ?>
            <span class="rounded-lg px-3 py-1.5 text-sm bg-primary text-white font-bold"><?= h((string) $pageNumber) ?></span>
          <?php else: ?>
            <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, ['page' => $pageNumber]))) ?>" class="rounded-lg px-3 py-1.5 text-sm border border-border-subtle hover:bg-white transition"><?= h((string) $pageNumber) ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($windowEnd < $totalPages): ?>
          <?php if ($windowEnd < $totalPages - 1): ?>
            <span class="px-2 text-text-muted">…</span>
          <?php endif; ?>
          <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, ['page' => $totalPages]))) ?>" class="rounded-lg px-3 py-1.5 text-sm border border-border-subtle hover:bg-white transition"><?= h((string) $totalPages) ?></a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
          <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', array_merge($statementParams, ['page' => $currentPage + 1]))) ?>" class="rounded-lg px-3 py-1.5 text-sm border border-border-subtle hover:bg-white transition">التالي</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<div id="documentModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/45 p-4" role="dialog" aria-modal="true">
  <div class="bg-white border border-border-subtle rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden flex flex-col">
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-border-subtle bg-surface-low/60">
      <div>
        <h3 id="modalTitle" class="text-lg font-extrabold">تفاصيل المستند</h3>
        <p id="modalSubtitle" class="text-xs text-text-muted mt-0.5"></p>
      </div>
      <button type="button" id="closeDocumentModalBtn" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-red-50 text-gray-600">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div id="modalLoading" class="hidden p-10 text-center text-sm text-text-muted">جاري تحميل التفاصيل...</div>
    <div id="modalContent" class="hidden overflow-auto p-5 space-y-4">
      <div id="modalSummary" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"></div>
      <div class="border border-border-subtle rounded-xl overflow-hidden">
        <div class="px-4 py-2 border-b border-border-subtle bg-surface-low/60 font-bold text-sm" id="modalItemsTitle">البنود</div>
        <div class="overflow-auto">
          <table class="w-full text-sm min-w-[820px] statement-table">
            <thead class="text-text-muted border-b border-border-subtle bg-surface-low/80 sticky top-0 z-10" id="modalItemsHead"></thead>
            <tbody id="modalItemsBody"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const apiBase = '/dashboard/accounting-statement-api.php';
  const form = document.querySelector('form[method="get"]');
  const customerSearchInput = document.getElementById('customerSearchInput');
  const accountSearchInput = document.getElementById('accountSearchInput');
  const customerGuidHidden = document.getElementById('customerGuidHidden');
  const accountGuidHidden = document.getElementById('accountGuidHidden');
  const customerSuggestPanel = document.getElementById('customerSuggestPanel');
  const accountSuggestPanel = document.getElementById('accountSuggestPanel');
  const fromDateInput = form?.querySelector('input[name="fromDate"]');
  const toDateInput = form?.querySelector('input[name="toDate"]');

  let customerTimer = null;
  let accountTimer = null;

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function formatDecimal(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    if (!Number.isFinite(number)) return String(value);
    return number.toLocaleString('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString('ar-SY');
  }

  function formatAccountingMoney(value, symbol, code) {
    const formatted = formatDecimal(value);
    if (formatted === '—') return formatted;
    const suffix = String(symbol ?? '').trim() || String(code ?? '').trim();
    return suffix ? `${formatted} ${suffix}` : formatted;
  }

  function formatEntryAmount(value) {
    const number = Number(value ?? 0);
    if (!Number.isFinite(number) || number <= 0) return '—';
    return formatDecimal(number);
  }

  function formatMaterialLabel(item) {
    const code = String(item.materialCode ?? '').trim();
    const name = String(item.materialName ?? '').trim();
    if (code && name) return `${code} - ${name}`;
    return name || code || '—';
  }

  function resolveUnitHeader(items, field, fallback) {
    for (const item of items) {
      const value = String(item[field] ?? '').trim();
      if (value) return value;
    }
    return fallback;
  }

  function invoiceRowClass(index) {
    return `${index % 2 === 0 ? 'bg-white' : 'bg-surface-low/40'} border-b border-border-subtle last:border-0 hover:bg-primary/5 transition-colors`;
  }

  async function apiCall(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`${apiBase}?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || 'تعذر تنفيذ الطلب');
    }
    return payload.data;
  }

  function buildStatementUrl(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && String(value).trim() !== '') {
        query.set(key, String(value));
      }
    });
    return '/dashboard/accounting-statement.php' + (query.toString() ? '?' + query.toString() : '');
  }

  function navigateToStatement(params) {
    window.location.href = buildStatementUrl(params);
  }

  function renderSuggestPanel(panel, items, onSelect) {
    if (!items.length) {
      panel.classList.add('hidden');
      panel.innerHTML = '';
      return;
    }
    panel.classList.remove('hidden');
    panel.innerHTML = items.map((item) => `
      <button type="button" class="w-full text-right px-3 py-2 text-sm border-b border-border-subtle last:border-0 hover:bg-primary/5 transition">
        <span class="font-semibold block">${escapeHtml(item.label)}</span>
        ${item.hint ? `<span class="text-xs text-text-muted block">${escapeHtml(item.hint)}</span>` : ''}
      </button>
    `).join('');
    panel.querySelectorAll('button').forEach((button, index) => {
      button.addEventListener('click', () => {
        panel.classList.add('hidden');
        onSelect(items[index]);
      });
    });
  }

  async function searchCustomers(term) {
    const search = term.trim();
    if (search.length < 2) {
      customerSuggestPanel?.classList.add('hidden');
      return;
    }
    const data = await apiCall('customers', { search, pageSize: 20 });
    const customers = (data.items || []).map((customer) => ({
      guid: customer.guid,
      label: customer.customerName || '—',
      hint: customer.mobile || customer.phone1 || '',
      search: customer.customerName || search,
    }));
    renderSuggestPanel(customerSuggestPanel, customers, (item) => {
      navigateToStatement({
        customerSearch: item.search,
        customerGuid: item.guid,
        accountSearch: accountSearchInput?.value || '',
        fromDate: fromDateInput?.value || '',
        toDate: toDateInput?.value || '',
        page: 1,
      });
    });
  }

  async function searchAccounts(term) {
    const search = term.trim();
    if (search.length < 2) {
      accountSuggestPanel?.classList.add('hidden');
      return;
    }
    const data = await apiCall('accounts', { search, pageSize: 20 });
    const accounts = (data.items || []).map((account) => {
      const label = account.name || account.code || account.number || '—';
      return {
        guid: account.guid,
        label,
        hint: [account.code, account.number].filter(Boolean).join(' · '),
        search: label,
      };
    });
    renderSuggestPanel(accountSuggestPanel, accounts, (item) => {
      navigateToStatement({
        accountSearch: item.search,
        accountGuid: item.guid,
        customerSearch: customerSearchInput?.value || '',
        fromDate: fromDateInput?.value || '',
        toDate: toDateInput?.value || '',
        page: 1,
      });
    });
  }

  customerSearchInput?.addEventListener('input', () => {
    if (customerGuidHidden) customerGuidHidden.value = '';
    clearTimeout(customerTimer);
    customerTimer = setTimeout(() => searchCustomers(customerSearchInput.value), 300);
  });

  accountSearchInput?.addEventListener('input', () => {
    if (accountGuidHidden) accountGuidHidden.value = '';
    clearTimeout(accountTimer);
    accountTimer = setTimeout(() => searchAccounts(accountSearchInput.value), 300);
  });

  document.addEventListener('click', (event) => {
    if (customerSuggestPanel && !customerSuggestPanel.contains(event.target) && event.target !== customerSearchInput) {
      customerSuggestPanel.classList.add('hidden');
    }
    if (accountSuggestPanel && !accountSuggestPanel.contains(event.target) && event.target !== accountSearchInput) {
      accountSuggestPanel.classList.add('hidden');
    }
  });

  fromDateInput?.addEventListener('change', () => {
    if ((customerGuidHidden?.value || accountGuidHidden?.value) && form) form.submit();
  });
  toDateInput?.addEventListener('change', () => {
    if ((customerGuidHidden?.value || accountGuidHidden?.value) && form) form.submit();
  });

  const modal = document.getElementById('documentModal');
  const modalTitle = document.getElementById('modalTitle');
  const modalSubtitle = document.getElementById('modalSubtitle');
  const modalLoading = document.getElementById('modalLoading');
  const modalContent = document.getElementById('modalContent');
  const modalSummary = document.getElementById('modalSummary');
  const modalItemsTitle = document.getElementById('modalItemsTitle');
  const modalItemsHead = document.getElementById('modalItemsHead');
  const modalItemsBody = document.getElementById('modalItemsBody');

  function summaryCard(label, value) {
    return `
      <article class="rounded-xl border border-border-subtle bg-surface-low/40 p-3">
        <div class="text-xs text-text-muted">${escapeHtml(label)}</div>
        <div class="font-bold mt-1 text-sm">${escapeHtml(value)}</div>
      </article>
    `;
  }

  function closeDocumentModal() {
    modal?.classList.add('hidden');
    modal?.classList.remove('flex');
    document.body.style.overflow = '';
  }

  async function openDocumentModal(guid, kind) {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modalContent?.classList.add('hidden');
    modalLoading?.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    try {
      const data = await apiCall(kind, { guid });
      const document = data.document || {};
      const isInvoice = kind === 'invoice';
      modalTitle.textContent = `${isInvoice ? 'تفاصيل الفاتورة' : 'تفاصيل السند'} رقم ${document.number ?? '—'}`;
      modalSubtitle.textContent = [
        document.typeName || document.typeCode,
        formatDate(document.date),
        document.customerName,
      ].filter(Boolean).join(' • ');

      const summaryItems = [
        summaryCard('النوع', document.typeName || document.typeCode || '—'),
        summaryCard('التسوية', document.settlementTypeName || '—'),
        summaryCard('العملة', [document.currencyName, document.currencyCode, document.currencySymbol].filter(Boolean).join(' - ') || '—'),
        summaryCard('سعر التعادل', formatDecimal(document.currencyRate)),
        summaryCard('العميل', document.customerName || '—'),
        summaryCard('الحساب', [document.accountNumber, document.accountName].filter(Boolean).join(' - ') || '—'),
        summaryCard('الإجمالي', formatAccountingMoney(document.totalAmount, document.currencySymbol, document.currencyCode)),
        summaryCard('الحسم', formatAccountingMoney(document.totalDiscount, document.currencySymbol, document.currencyCode)),
        summaryCard('الإضافات', formatAccountingMoney(document.totalAdditions, document.currencySymbol, document.currencyCode)),
        summaryCard('الصافي', formatAccountingMoney(document.netAmount, document.currencySymbol, document.currencyCode)),
      ];
      modalSummary.innerHTML = summaryItems.join('');

      if (isInvoice) {
        modalItemsTitle.textContent = 'بنود الفاتورة';
        const items = data.items || [];
        const unit2Header = resolveUnitHeader(items, 'materialUnit2', 'الوحدة الثانية');
        const unit1Header = resolveUnitHeader(items, 'materialUnity', 'الوحدة الأولى');
        modalItemsHead.innerHTML = `
          <tr>
            <th class="text-right p-3 whitespace-nowrap">#</th>
            <th class="text-right p-3 whitespace-nowrap">المادة</th>
            <th class="text-right p-3 whitespace-nowrap">${escapeHtml(unit2Header)}</th>
            <th class="text-right p-3 whitespace-nowrap">${escapeHtml(unit1Header)}</th>
            <th class="text-right p-3 whitespace-nowrap">سعر القطعة</th>
            <th class="text-right p-3 whitespace-nowrap">الإجمالي</th>
          </tr>
        `;
        modalItemsBody.innerHTML = items.length
          ? items.map((item, index) => `
            <tr class="${invoiceRowClass(index)}">
              <td class="p-3 whitespace-nowrap text-text-muted tabular-nums">${escapeHtml(String(index + 1))}</td>
              <td class="p-3 font-semibold">${escapeHtml(formatMaterialLabel(item))}</td>
              <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatDecimal(item.quantityUnit2))}</td>
              <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatDecimal(item.quantityUnit1 ?? item.quantity))}</td>
              <td class="p-3 whitespace-nowrap tabular-nums">${escapeHtml(formatAccountingMoney(item.unitPriceUnit1 ?? item.price, document.currencySymbol, document.currencyCode))}</td>
              <td class="p-3 whitespace-nowrap tabular-nums font-bold">${escapeHtml(formatAccountingMoney(item.lineTotal, document.currencySymbol, document.currencyCode))}</td>
            </tr>
          `).join('')
          : '<tr><td colspan="6" class="p-4 text-center text-text-muted">لا توجد بنود.</td></tr>';
      } else {
        modalItemsTitle.textContent = 'قيود السند';
        modalItemsHead.innerHTML = '<tr><th class="text-right p-3">رقم</th><th class="text-right p-3">حساب</th><th class="text-right p-3">مقابل</th><th class="text-right p-3">مدين</th><th class="text-right p-3">دائن</th><th class="text-right p-3">التعادل</th><th class="text-right p-3">عميل</th><th class="text-right p-3">ملاحظات</th></tr>';
        const entryLines = data.entryLines || [];
        modalItemsBody.innerHTML = entryLines.length
          ? entryLines.map((line) => {
            const debit = Number(line.debit ?? 0);
            const credit = Number(line.credit ?? 0);
            const equivalent = line.equivalentValue != null
              ? formatAccountingMoney(line.equivalentValue, line.equivalentCurrencySymbol, line.equivalentCurrencyCode)
              : '—';
            return `
              <tr class="border-b border-border-subtle last:border-0">
                <td class="p-3">${escapeHtml(line.number ?? '—')}</td>
                <td class="p-3">${escapeHtml([line.accountNumber, line.accountName || line.accountCode].filter(Boolean).join(' - ') || '—')}</td>
                <td class="p-3">${escapeHtml([line.contraAccountNumber, line.contraAccountName || line.contraAccountCode].filter(Boolean).join(' - ') || '—')}</td>
                <td class="p-3 whitespace-nowrap tabular-nums ${debit > 0 ? 'text-emerald-700 font-semibold' : 'text-text-muted'}">${escapeHtml(formatEntryAmount(line.debit))}</td>
                <td class="p-3 whitespace-nowrap tabular-nums ${credit > 0 ? 'text-rose-700 font-semibold' : 'text-text-muted'}">${escapeHtml(formatEntryAmount(line.credit))}</td>
                <td class="p-3">${escapeHtml(equivalent)}</td>
                <td class="p-3">${escapeHtml(line.customerName || '—')}</td>
                <td class="p-3">${escapeHtml(line.notes || '—')}</td>
              </tr>
            `;
          }).join('')
          : '<tr><td colspan="8" class="p-4 text-center text-text-muted">لا توجد قيود.</td></tr>';
      }

      modalLoading?.classList.add('hidden');
      modalContent?.classList.remove('hidden');
    } catch (error) {
      modalLoading?.classList.add('hidden');
      modalContent?.classList.remove('hidden');
      modalSummary.innerHTML = `<p class="text-sm text-red-700">${escapeHtml(error.message)}</p>`;
      modalItemsBody.innerHTML = '';
      modalItemsHead.innerHTML = '';
    }
  }

  document.querySelectorAll('.doc-ref-btn').forEach((button) => {
    button.addEventListener('click', () => openDocumentModal(button.dataset.refGuid, button.dataset.refKind));
  });

  document.getElementById('closeDocumentModalBtn')?.addEventListener('click', closeDocumentModal);
  modal?.addEventListener('click', (event) => {
    if (event.target === modal) closeDocumentModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeDocumentModal();
  });
})();
</script>
