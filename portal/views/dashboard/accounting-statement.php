<?php

declare(strict_types=1);

/** @var array<string, string|int> $query */
/** @var list<array<string, mixed>> $customerMatches */
/** @var array<string, mixed>|null $summary */
/** @var list<array<string, mixed>> $entries */
/** @var string|null $selectedCustomerName */
/** @var float $totalDebit */
/** @var float $totalCredit */
/** @var float $closingBalance */

$query = is_array($query ?? null) ? $query : [];
$customerMatches = is_array($customerMatches ?? null) ? $customerMatches : [];
$entries = is_array($entries ?? null) ? $entries : [];
$summary = is_array($summary ?? null) ? $summary : null;
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">كشف حساب</h1>
      <p class="text-sm text-text-muted mt-1">بحث بالاسم أو الهاتف ثم عرض حركات الحساب مع روابط للفواتير والسندات.</p>
    </div>
    <a href="/dashboard/accounting-customers.php" class="text-sm text-primary font-semibold">← عملاء الأمين</a>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="rounded-xl border border-border-subtle bg-white p-4 mb-4">
  <form method="get" class="grid md:grid-cols-5 gap-3 items-end">
    <label class="text-sm md:col-span-2">
      <span class="text-text-muted">بحث العميل</span>
      <input
        type="text"
        id="customerSearchInput"
        name="customerSearch"
        value="<?= h((string) ($query['customerSearch'] ?? '')) ?>"
        class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5"
        placeholder="اسم أو هاتف..."
      >
      <input type="hidden" id="customerGuidHidden" name="customerGuid" value="<?= h((string) ($query['customerGuid'] ?? '')) ?>">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">من تاريخ</span>
      <input type="date" name="fromDate" value="<?= h((string) ($query['fromDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">إلى تاريخ</span>
      <input type="date" name="toDate" value="<?= h((string) ($query['toDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <button class="bg-primary text-white rounded-xl px-4 py-2.5 font-bold hover:brightness-110 transition">عرض الكشف</button>
  </form>
</section>

<script>
  (() => {
    const searchInput = document.getElementById('customerSearchInput');
    const guidInput = document.getElementById('customerGuidHidden');
    if (!searchInput || !guidInput) return;
    const original = searchInput.value.trim();
    searchInput.addEventListener('input', () => {
      if (searchInput.value.trim() !== original) guidInput.value = '';
    });
  })();
</script>

<?php if (($query['customerGuid'] ?? '') !== ''): ?>
  <div class="mb-4 inline-flex items-center gap-2 rounded-full bg-primary/10 text-primary px-4 py-2 text-sm">
    العميل: <strong><?= h($selectedCustomerName ?: 'محدد') ?></strong>
    <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', ['customerSearch' => (string) ($query['customerSearch'] ?? ''), 'fromDate' => (string) ($query['fromDate'] ?? ''), 'toDate' => (string) ($query['toDate'] ?? '')])) ?>" class="underline">تغيير</a>
  </div>
<?php endif; ?>

<?php if (($query['customerGuid'] ?? '') === '' && ($query['customerSearch'] ?? '') !== ''): ?>
  <section class="rounded-xl border border-border-subtle bg-white overflow-hidden mb-4">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">نتائج البحث</h2>
    </div>
    <?php if ($customerMatches === []): ?>
      <p class="p-4 text-sm text-text-muted">لا توجد نتائج. جرّب كلمة أخرى.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="text-text-muted border-b border-border-subtle">
            <tr>
              <th class="text-right p-3">الاسم</th>
              <th class="text-right p-3">الهاتف</th>
              <th class="text-right p-3">الحالة</th>
              <th class="text-right p-3"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($customerMatches as $match): ?>
              <tr class="border-b border-border-subtle last:border-0">
                <td class="p-3 font-semibold"><?= h((string) ($match['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['mobile'] ?? $match['phone1'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($match['state'] ?? '—')) ?></td>
                <td class="p-3">
                  <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', [
                      'customerSearch' => (string) ($query['customerSearch'] ?? ''),
                      'customerGuid' => (string) ($match['guid'] ?? ''),
                      'fromDate' => (string) ($query['fromDate'] ?? ''),
                      'toDate' => (string) ($query['toDate'] ?? ''),
                  ])) ?>" class="inline-flex rounded-lg px-3 py-1.5 bg-primary text-white text-xs font-bold">عرض الكشف</a>
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
    <article class="rounded-xl border border-border-subtle bg-white p-4">
      <div class="text-xs text-text-muted">الرصيد الافتتاحي</div>
      <div class="font-extrabold mt-1"><?= h(format_accounting_money($summary['openingBalance'] ?? null, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4">
      <div class="text-xs text-text-muted">إجمالي المدين</div>
      <div class="font-extrabold mt-1"><?= h(format_accounting_money($totalDebit, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4">
      <div class="text-xs text-text-muted">إجمالي الدائن</div>
      <div class="font-extrabold mt-1"><?= h(format_accounting_money($totalCredit, $currencySymbol, $currencyCode)) ?></div>
    </article>
    <article class="rounded-xl border border-border-subtle bg-white p-4">
      <div class="text-xs text-text-muted">الرصيد الختامي</div>
      <div class="font-extrabold mt-1"><?= h(format_accounting_money($closingBalance, $currencySymbol, $currencyCode)) ?></div>
    </article>
  </section>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <?php if ($entries === []): ?>
    <p class="p-4 text-sm text-text-muted">ابحث عن عميل واختره لعرض كشف الحساب.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[1080px]">
        <thead class="text-text-muted border-b border-border-subtle bg-surface-low/60">
          <tr>
            <th class="text-right p-3">التاريخ</th>
            <th class="text-right p-3">الرقم</th>
            <th class="text-right p-3">المدين</th>
            <th class="text-right p-3">الدائن</th>
            <th class="text-right p-3">الرصيد</th>
            <th class="text-right p-3">النوع</th>
            <th class="text-right p-3">المرجع</th>
            <th class="text-right p-3">الحساب المقابل</th>
            <th class="text-right p-3">ملاحظات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <?php
            $docKind = accounting_document_kind((string) ($entry['reasonType'] ?? ''));
            $referenceGuid = (string) ($entry['referenceGuid'] ?? '');
            $referenceNumber = (string) ($entry['referenceNumber'] ?? '');
            ?>
            <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-low/40">
              <td class="p-3"><?= h(accounting_format_date($entry['entryDate'] ?? $entry['date'] ?? null)) ?></td>
              <td class="p-3"><?= h((string) ($entry['entryNumber'] ?? $entry['number'] ?? '—')) ?></td>
              <td class="p-3"><?= h(format_decimal($entry['debit'] ?? 0)) ?></td>
              <td class="p-3"><?= h(format_decimal($entry['credit'] ?? 0)) ?></td>
              <td class="p-3 font-semibold"><?= h(format_decimal($entry['runningBalance'] ?? 0)) ?></td>
              <td class="p-3"><?= h((string) ($entry['reasonDocumentType'] ?? $entry['reasonType'] ?? '—')) ?></td>
              <td class="p-3">
                <?php if ($docKind !== null && $referenceGuid !== ''): ?>
                  <a class="text-primary font-semibold hover:underline" href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => $docKind, 'guid' => $referenceGuid])) ?>">
                    <?= h($referenceNumber !== '' ? $referenceNumber : 'عرض') ?>
                  </a>
                <?php else: ?>
                  <?= h($referenceNumber !== '' ? $referenceNumber : '—') ?>
                <?php endif; ?>
              </td>
              <td class="p-3"><?= h((string) ($entry['contraAccountName'] ?? $entry['contraAccountCode'] ?? '—')) ?></td>
              <td class="p-3 text-text-muted"><?= h((string) ($entry['notes'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
