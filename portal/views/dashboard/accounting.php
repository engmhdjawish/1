<?php

declare(strict_types=1);

/** @var array<string, mixed> $amine */
/** @var array<string, int> $syncCounts */
/** @var array<string, int> $statusCounts */

$amine = is_array($amine ?? null) ? $amine : [];
$syncCounts = is_array($syncCounts ?? null) ? $syncCounts : [];
$statusCounts = is_array($statusCounts ?? null) ? $statusCounts : [];
?>
<section class="mb-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">لوحة المحاسبة</h1>
      <p class="text-sm text-text-muted mt-1">ملخص بيانات الأمين (عملاء، فواتير، سندات) مع متابعة طلبات الموقع.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-xs">
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        <span class="material-symbols-outlined text-base">cloud</span>
        API: <?= !empty($amine['apiHealthy']) ? '<span class="text-status-active font-bold">متصل</span>' : '<span class="text-status-rejected font-bold">غير متصل</span>' ?>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        <span class="material-symbols-outlined text-base">group</span>
        عملاء الأمين: <strong><?= h((string) ($amine['customerCount'] ?? '—')) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        <span class="material-symbols-outlined text-base">receipt_long</span>
        فواتير: <strong><?= h((string) ($amine['invoiceCount'] ?? '—')) ?></strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 border border-border-subtle bg-white">
        <span class="material-symbols-outlined text-base">payments</span>
        سندات: <strong><?= h((string) ($amine['voucherCount'] ?? '—')) ?></strong>
      </span>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="grid gap-3 grid-cols-2 md:grid-cols-4 mb-6">
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div class="text-xs text-text-muted">طلبات مؤكدة</div>
    <div class="text-2xl font-extrabold mt-1"><?= (int) ($statusCounts['confirmed'] ?? 0) ?></div>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div class="text-xs text-text-muted">طلبات مكتملة</div>
    <div class="text-2xl font-extrabold mt-1 text-status-active"><?= (int) ($statusCounts['completed'] ?? 0) ?></div>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div class="text-xs text-text-muted">بانتظار مزامنة</div>
    <div class="text-2xl font-extrabold mt-1 text-status-pending"><?= (int) ($syncCounts['pending'] ?? 0) ?></div>
  </article>
  <article class="rounded-xl border border-border-subtle bg-white p-4">
    <div class="text-xs text-text-muted">فشل مزامنة</div>
    <div class="text-2xl font-extrabold mt-1 text-status-rejected"><?= (int) ($syncCounts['failed'] ?? 0) ?></div>
  </article>
</section>

<section class="grid gap-4 lg:grid-cols-2 mb-6">
  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between">
      <h2 class="font-bold">اختصارات بيانات الأمين</h2>
      <span class="text-xs text-text-muted">من API الأمين</span>
    </div>
    <div class="p-4 grid gap-2 sm:grid-cols-2 text-sm">
      <?php if (web_can('accounting.customers.view')): ?>
        <a href="/dashboard/accounting-customers.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">group</span>
          عملاء الأمين
        </a>
      <?php endif; ?>
      <?php if (web_can('accounting.documents.view')): ?>
        <a href="/dashboard/accounting-documents.php?kind=invoices" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">receipt_long</span>
          الفواتير
        </a>
        <a href="/dashboard/accounting-documents.php?kind=vouchers" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">payments</span>
          السندات
        </a>
      <?php endif; ?>
      <?php if (web_can('accounting.statement.view')): ?>
        <a href="/dashboard/accounting-statement.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">account_balance_wallet</span>
          كشف حساب
        </a>
      <?php endif; ?>
    </div>
  </article>

  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between">
      <h2 class="font-bold">طلبات الموقع</h2>
      <span class="text-xs text-text-muted">من قاعدة البوابة</span>
    </div>
    <div class="p-4 grid gap-2 sm:grid-cols-2 text-sm">
      <?php if (web_can('accounting.sync.view')): ?>
        <a href="/dashboard/accounting-sync.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">sync</span>
          طابور المزامنة
        </a>
      <?php endif; ?>
      <?php if (web_can('accounting.reports.view')): ?>
        <a href="/dashboard/accounting-reports.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">analytics</span>
          التقارير المالية
        </a>
      <?php endif; ?>
      <?php if (web_can('orders.view')): ?>
        <a href="/dashboard/orders.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">shopping_cart</span>
          إدارة الطلبات
        </a>
      <?php endif; ?>
      <?php if (web_can('web_customers.view')): ?>
        <a href="/dashboard/customers.php" class="rounded-xl border border-border-subtle px-3 py-3 hover:bg-surface-low transition flex items-center gap-2">
          <span class="material-symbols-outlined text-primary">person_add</span>
          عملاء الموقع
        </a>
      <?php endif; ?>
    </div>
  </article>
</section>

<section class="grid gap-4 lg:grid-cols-2">
  <?php if (web_can('accounting.documents.view')): ?>
  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
      <h2 class="font-bold">آخر الفواتير</h2>
      <a href="/dashboard/accounting-documents.php?kind=invoices" class="text-xs text-primary font-semibold">عرض الكل</a>
    </div>
    <?php $recentInvoices = is_array($amine['recentInvoices'] ?? null) ? $amine['recentInvoices'] : []; ?>
    <?php if ($recentInvoices === []): ?>
      <p class="p-4 text-sm text-text-muted">لا توجد فواتير حديثة أو تعذر جلبها.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[640px]">
          <thead class="text-text-muted border-b border-border-subtle">
            <tr>
              <th class="text-right p-3">الرقم</th>
              <th class="text-right p-3">التاريخ</th>
              <th class="text-right p-3">النوع</th>
              <th class="text-right p-3">العميل</th>
              <th class="text-right p-3">الصافي</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentInvoices as $row): ?>
              <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-low/50">
                <td class="p-3 font-semibold">
                  <a class="text-primary hover:underline" href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => 'invoices', 'guid' => (string) ($row['guid'] ?? '')])) ?>">
                    <?= h((string) ($row['number'] ?? '—')) ?>
                  </a>
                </td>
                <td class="p-3"><?= h(accounting_format_date($row['date'] ?? null)) ?></td>
                <td class="p-3"><?= h((string) ($row['typeName'] ?? $row['typeCode'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($row['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h(format_accounting_money($row['netAmount'] ?? null, $row['currencySymbol'] ?? null, $row['currencyCode'] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
  <?php endif; ?>

  <?php if (web_can('accounting.documents.view')): ?>
  <article class="rounded-xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between">
      <h2 class="font-bold">آخر السندات</h2>
      <a href="/dashboard/accounting-documents.php?kind=vouchers" class="text-xs text-primary font-semibold">عرض الكل</a>
    </div>
    <?php $recentVouchers = is_array($amine['recentVouchers'] ?? null) ? $amine['recentVouchers'] : []; ?>
    <?php if ($recentVouchers === []): ?>
      <p class="p-4 text-sm text-text-muted">لا توجد سندات حديثة أو تعذر جلبها.</p>
    <?php else: ?>
      <div class="overflow-auto">
        <table class="w-full text-sm min-w-[640px]">
          <thead class="text-text-muted border-b border-border-subtle">
            <tr>
              <th class="text-right p-3">الرقم</th>
              <th class="text-right p-3">التاريخ</th>
              <th class="text-right p-3">النوع</th>
              <th class="text-right p-3">العميل</th>
              <th class="text-right p-3">الصافي</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentVouchers as $row): ?>
              <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-low/50">
                <td class="p-3 font-semibold">
                  <a class="text-primary hover:underline" href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => 'vouchers', 'guid' => (string) ($row['guid'] ?? '')])) ?>">
                    <?= h((string) ($row['number'] ?? '—')) ?>
                  </a>
                </td>
                <td class="p-3"><?= h(accounting_format_date($row['date'] ?? null)) ?></td>
                <td class="p-3"><?= h((string) ($row['typeName'] ?? $row['typeCode'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($row['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h(format_accounting_money($row['netAmount'] ?? null, $row['currencySymbol'] ?? null, $row['currencyCode'] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
  <?php endif; ?>
</section>
