<?php

declare(strict_types=1);

/** @var array<string, string> $query */
/** @var list<array<string, mixed>> $customers */
/** @var array<string, mixed>|null $selectedCustomer */
/** @var array<string, mixed>|null $accountSummary */

$query = is_array($query ?? null) ? $query : [];
$customers = is_array($customers ?? null) ? $customers : [];
$selectedCustomer = is_array($selectedCustomer ?? null) ? $selectedCustomer : null;
$accountSummary = is_array($accountSummary ?? null) ? $accountSummary : null;
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold">عملاء الأمين</h1>
      <p class="text-sm text-text-muted mt-1">دليل عملاء المحاسبة مع ملخص الحساب — منفصل عن عملاء تسجيل الموقع.</p>
    </div>
    <a href="/dashboard/customers.php" class="text-xs text-text-muted">عملاء الموقع ←</a>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="rounded-xl border border-border-subtle bg-white p-4 mb-4">
  <form method="get" class="flex flex-col sm:flex-row gap-3 items-end">
    <label class="text-sm flex-1 w-full">
      <span class="text-text-muted">بحث بالاسم أو الهاتف</span>
      <input type="text" name="keyword" value="<?= h((string) ($query['keyword'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5" placeholder="مثال: أحمد / 0932...">
    </label>
    <button class="bg-primary text-white rounded-xl px-5 py-2.5 font-bold whitespace-nowrap">بحث</button>
  </form>
</section>

<?php if ($selectedCustomer && $accountSummary): ?>
  <?php
  $symbol = (string) ($accountSummary['accountCurrencySymbol'] ?? '');
  $code = (string) ($accountSummary['accountCurrencyCode'] ?? '');
  $customerGuid = (string) ($selectedCustomer['guid'] ?? '');
  ?>
  <section class="rounded-xl border border-primary/20 bg-primary/5 p-4 mb-4">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
      <div>
        <h2 class="font-extrabold text-lg"><?= h((string) ($selectedCustomer['customerName'] ?? '—')) ?></h2>
        <p class="text-sm text-text-muted mt-1">
          <?= h((string) ($selectedCustomer['mobile'] ?? $selectedCustomer['phone1'] ?? '—')) ?>
          · حساب: <?= h((string) ($accountSummary['accountName'] ?? $accountSummary['accountCode'] ?? '—')) ?>
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= h(accounting_url('/dashboard/accounting-statement.php', ['customerGuid' => $customerGuid, 'customerSearch' => (string) ($query['keyword'] ?? '')])) ?>" class="rounded-xl bg-primary text-white px-4 py-2 text-sm font-bold">كشف الحساب</a>
        <a href="<?= h(accounting_url('/dashboard/accounting-customers.php', ['keyword' => (string) ($query['keyword'] ?? '')])) ?>" class="rounded-xl border border-border-subtle bg-white px-4 py-2 text-sm font-semibold">إغلاق التفاصيل</a>
      </div>
    </div>
    <div class="grid gap-3 sm:grid-cols-3 mt-4">
      <article class="rounded-xl border border-border-subtle bg-white p-3">
        <div class="text-xs text-text-muted">مدين حالي</div>
        <div class="font-extrabold"><?= h(format_accounting_money($accountSummary['currentDebit'] ?? null, $symbol, $code)) ?></div>
      </article>
      <article class="rounded-xl border border-border-subtle bg-white p-3">
        <div class="text-xs text-text-muted">دائن حالي</div>
        <div class="font-extrabold"><?= h(format_accounting_money($accountSummary['currentCredit'] ?? null, $symbol, $code)) ?></div>
      </article>
      <article class="rounded-xl border border-border-subtle bg-white p-3">
        <div class="text-xs text-text-muted">الرصيد</div>
        <div class="font-extrabold text-primary"><?= h(format_accounting_money($accountSummary['currentBalance'] ?? null, $symbol, $code)) ?></div>
      </article>
    </div>
  </section>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
    <h2 class="font-bold">نتائج البحث</h2>
  </div>
  <?php if (($query['keyword'] ?? '') === ''): ?>
    <p class="p-4 text-sm text-text-muted">أدخل كلمة بحث لعرض عملاء الأمين.</p>
  <?php elseif ($customers === []): ?>
    <p class="p-4 text-sm text-text-muted">لا توجد نتائج مطابقة.</p>
  <?php else: ?>
    <div class="overflow-auto">
      <table class="w-full text-sm min-w-[860px]">
        <thead class="text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right p-3">الاسم</th>
            <th class="text-right p-3">الهاتف</th>
            <th class="text-right p-3">البريد</th>
            <th class="text-right p-3">الحالة</th>
            <th class="text-right p-3"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $customer): ?>
            <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-low/40">
              <td class="p-3 font-semibold"><?= h((string) ($customer['customerName'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($customer['mobile'] ?? $customer['phone1'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($customer['email'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($customer['state'] ?? '—')) ?></td>
              <td class="p-3">
                <a href="<?= h(accounting_url('/dashboard/accounting-customers.php', ['keyword' => (string) ($query['keyword'] ?? ''), 'customerGuid' => (string) ($customer['guid'] ?? '')])) ?>" class="text-primary font-bold text-xs">ملخص الحساب</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
