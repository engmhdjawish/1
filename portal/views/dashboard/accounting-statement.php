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
<div data-accounting-statement-page>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">كشف حساب</h1>
      <p class="text-sm text-text-muted mt-1">ابحث باسم العميل أو اسم الحساب أو كليهما، ثم اعرض الحركات مع روابط الفواتير والسندات.</p>
    </div>
    <div class="flex flex-wrap gap-3 text-sm">
      <a href="/dashboard/accounting-customers.php" class="text-primary font-semibold">عملاء الأمين</a>
      <a href="/dashboard/accounting-documents.php" class="text-primary font-semibold">الفواتير والسندات</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="rounded-xl border border-border-subtle bg-white p-4 mb-4 shadow-sm">
  <form
    id="accountingStatementForm"
    method="get"
    action="/dashboard/accounting-statement.php"
    data-dashboard-filter
    class="grid md:grid-cols-2 xl:grid-cols-6 gap-3 items-end"
  >
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
      <button type="submit" class="rounded-xl bg-primary text-white px-4 py-2.5 text-sm font-bold hover:brightness-110 transition">بحث</button>
      <p class="text-xs text-text-muted">اكتب اسم الحساب أو العميل واختر من القائمة، أو اضغط بحث</p>
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
</div>
