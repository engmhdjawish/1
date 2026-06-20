<?php

declare(strict_types=1);

/** @var string $kind */
/** @var array<string, string|int> $filters */
/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $types */
/** @var int $totalCount */
/** @var int $page */
/** @var int $pageSize */
/** @var int $totalPages */

$kind = (string) ($kind ?? 'invoices');
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$types = is_array($types ?? null) ? $types : [];
$isInvoice = $kind === 'invoices';
$kindLabel = $isInvoice ? 'الفواتير' : 'السندات';
?>
<section class="mb-6">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold"><?= h($kindLabel) ?></h1>
      <p class="text-sm text-text-muted mt-1">تصفح <?= h($kindLabel) ?> من نظام الأمين مع فلترة بالنوع والتاريخ والبحث.</p>
    </div>
    <div class="flex flex-wrap gap-2 text-sm">
      <a href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => 'invoices'] + $filters)) ?>" class="rounded-full px-4 py-2 border <?= $isInvoice ? 'bg-primary text-white border-primary font-bold' : 'border-border-subtle bg-white' ?>">فواتير</a>
      <a href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => 'vouchers'] + $filters)) ?>" class="rounded-full px-4 py-2 border <?= !$isInvoice ? 'bg-primary text-white border-primary font-bold' : 'border-border-subtle bg-white' ?>">سندات</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/partials/accounting-flash.php'; ?>

<section class="rounded-xl border border-border-subtle bg-white p-4 mb-4">
  <form method="get" class="grid md:grid-cols-6 gap-3 items-end">
    <input type="hidden" name="kind" value="<?= h($kind) ?>">
    <label class="text-sm md:col-span-2">
      <span class="text-text-muted">بحث</span>
      <input type="text" name="keyword" value="<?= h((string) ($filters['keyword'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5" placeholder="رقم أو عميل...">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">النوع</span>
      <select name="typeGuid" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
        <option value="">الكل</option>
        <?php foreach ($types as $type): ?>
          <?php $typeGuid = (string) ($type['typeGuid'] ?? $type['guid'] ?? $type['Guid'] ?? ''); ?>
          <option value="<?= h($typeGuid) ?>" <?= ($filters['typeGuid'] ?? '') === $typeGuid ? 'selected' : '' ?>>
            <?= h((string) ($type['typeName'] ?? $type['name'] ?? $type['Name'] ?? $type['typeCode'] ?? $typeGuid)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">
      <span class="text-text-muted">من</span>
      <input type="date" name="fromDate" value="<?= h((string) ($filters['fromDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <label class="text-sm">
      <span class="text-text-muted">إلى</span>
      <input type="date" name="toDate" value="<?= h((string) ($filters['toDate'] ?? '')) ?>" class="mt-1 w-full border border-border-subtle rounded-xl px-3 py-2.5">
    </label>
    <button class="bg-primary text-white rounded-xl px-4 py-2.5 font-bold">تصفية</button>
  </form>
</section>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between text-sm">
    <span class="text-text-muted"><?= (int) $totalCount ?> سجل</span>
    <span>صفحة <?= (int) $page ?> من <?= (int) $totalPages ?></span>
  </div>
  <div class="overflow-auto">
    <table class="w-full text-sm min-w-[1080px]">
      <thead class="text-text-muted border-b border-border-subtle">
        <tr>
          <th class="text-right p-3">الرقم</th>
          <th class="text-right p-3">التاريخ</th>
          <th class="text-right p-3">النوع</th>
          <th class="text-right p-3">التسوية</th>
          <th class="text-right p-3">العميل</th>
          <th class="text-right p-3">الحساب</th>
          <th class="text-right p-3">الإجمالي</th>
          <th class="text-right p-3">الصافي</th>
          <th class="text-right p-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($rows === []): ?>
          <tr><td colspan="9" class="p-4 text-text-muted">لا توجد نتائج.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr class="border-b border-border-subtle last:border-0 hover:bg-surface-low/40">
              <td class="p-3 font-semibold"><?= h((string) ($row['number'] ?? '—')) ?></td>
              <td class="p-3"><?= h(accounting_format_date($row['date'] ?? null)) ?></td>
              <td class="p-3"><?= h((string) ($row['typeName'] ?? $row['typeCode'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($row['settlementTypeName'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($row['customerName'] ?? '—')) ?></td>
              <td class="p-3"><?= h((string) ($row['accountName'] ?? '—')) ?></td>
              <td class="p-3"><?= h(format_accounting_money($row['totalAmount'] ?? null, $row['currencySymbol'] ?? null, $row['currencyCode'] ?? null)) ?></td>
              <td class="p-3 font-semibold"><?= h(format_accounting_money($row['netAmount'] ?? null, $row['currencySymbol'] ?? null, $row['currencyCode'] ?? null)) ?></td>
              <td class="p-3">
                <a href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => $kind, 'guid' => (string) ($row['guid'] ?? '')])) ?>" class="text-primary font-bold text-xs">تفاصيل</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t border-border-subtle flex items-center justify-between text-sm">
      <?php
      $prev = max(1, $page - 1);
      $next = min($totalPages, $page + 1);
      $baseFilters = $filters;
      ?>
      <a class="<?= $page <= 1 ? 'text-text-muted pointer-events-none' : 'text-primary font-semibold' ?>" href="<?= h(accounting_url('/dashboard/accounting-documents.php', $baseFilters + ['kind' => $kind, 'page' => $prev])) ?>">السابق</a>
      <a class="<?= $page >= $totalPages ? 'text-text-muted pointer-events-none' : 'text-primary font-semibold' ?>" href="<?= h(accounting_url('/dashboard/accounting-documents.php', $baseFilters + ['kind' => $kind, 'page' => $next])) ?>">التالي</a>
    </div>
  <?php endif; ?>
</section>
