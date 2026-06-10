<?php

declare(strict_types=1);

/** @var string $kind */
/** @var array<string, mixed> $document */
/** @var list<array<string, mixed>> $items */
/** @var list<array<string, mixed>> $entryLines */
/** @var array<string, mixed> $meta */

$kind = (string) ($kind ?? 'invoices');
$document = is_array($document ?? null) ? $document : [];
$items = is_array($items ?? null) ? $items : [];
$entryLines = is_array($entryLines ?? null) ? $entryLines : [];
$meta = is_array($meta ?? null) ? $meta : [];
$isInvoice = $kind === 'invoices';
$title = ($isInvoice ? 'فاتورة' : 'سند') . ' رقم ' . (string) ($document['number'] ?? '—');
$symbol = (string) ($document['currencySymbol'] ?? '');
$code = (string) ($document['currencyCode'] ?? '');
?>
<section class="mb-6">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold"><?= h($title) ?></h1>
      <p class="text-sm text-text-muted mt-1">
        <?= h(accounting_format_date($document['date'] ?? null)) ?>
        · <?= h((string) ($document['typeName'] ?? $document['typeCode'] ?? '—')) ?>
      </p>
    </div>
    <a href="<?= h(accounting_url('/dashboard/accounting-documents.php', ['kind' => $kind])) ?>" class="text-sm text-primary font-semibold">← العودة للقائمة</a>
  </div>
</section>

<?php require __DIR__ . '/accounting-flash.php'; ?>

<section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-6">
  <?php
  $cells = [
      'العميل' => (string) ($document['customerName'] ?? '—'),
      'الحساب' => (string) ($document['accountName'] ?? '—'),
      'التسوية' => (string) ($document['settlementTypeName'] ?? '—'),
      'العملة' => trim(implode(' ', array_filter([(string) ($document['currencyName'] ?? ''), $code, $symbol]))),
      'سعر التعادل' => format_decimal($document['currencyRate'] ?? null),
      'الإجمالي' => format_accounting_money($document['totalAmount'] ?? null, $symbol, $code),
      'الحسم' => format_accounting_money($document['totalDiscount'] ?? null, $symbol, $code),
      'الإضافات' => format_accounting_money($document['totalAdditions'] ?? null, $symbol, $code),
      'الصافي' => format_accounting_money($document['netAmount'] ?? null, $symbol, $code),
  ];
  if ($isInvoice) {
      $cells['عدد الأزواج'] = format_decimal($document['pairsCount'] ?? null, 0);
      $cells['عدد الأقلام'] = format_decimal($document['pensCount'] ?? null, 0);
  }
  foreach ($cells as $label => $value): ?>
    <article class="rounded-xl border border-border-subtle bg-white p-4">
      <div class="text-xs text-text-muted"><?= h($label) ?></div>
      <div class="font-bold mt-1"><?= h($value) ?></div>
    </article>
  <?php endforeach; ?>
</section>

<?php if (trim((string) ($document['notes'] ?? '')) !== ''): ?>
  <section class="rounded-xl border border-border-subtle bg-white p-4 mb-6 text-sm">
    <div class="text-xs text-text-muted mb-1">ملاحظات</div>
    <p><?= h((string) $document['notes']) ?></p>
  </section>
<?php endif; ?>

<section class="rounded-xl border border-border-subtle bg-white overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
    <h2 class="font-bold"><?= $isInvoice ? 'بنود الفاتورة' : 'قيود السند' ?></h2>
    <p class="text-xs text-text-muted mt-1">
      <?= $isInvoice
          ? ('عدد البنود: ' . h((string) ($meta['linesCount'] ?? count($items))))
          : ('عدد القيود: ' . h((string) count($entryLines))) ?>
    </p>
  </div>
  <div class="overflow-auto">
    <?php if ($isInvoice): ?>
      <?php
      $unit2Header = accounting_invoice_unit_header($items, 'materialUnit2', 'الوحدة الثانية');
      $unit1Header = accounting_invoice_unit_header($items, 'materialUnity', 'الوحدة الأولى');
      ?>
      <table class="w-full text-sm min-w-[820px] statement-table">
        <thead class="text-text-muted border-b border-border-subtle bg-surface-low/80">
          <tr>
            <th class="text-right p-3 whitespace-nowrap">#</th>
            <th class="text-right p-3 whitespace-nowrap">المادة</th>
            <th class="text-right p-3 whitespace-nowrap"><?= h($unit2Header) ?></th>
            <th class="text-right p-3 whitespace-nowrap"><?= h($unit1Header) ?></th>
            <th class="text-right p-3 whitespace-nowrap">سعر القطعة</th>
            <th class="text-right p-3 whitespace-nowrap">الإجمالي</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($items === []): ?>
            <tr><td colspan="6" class="p-4 text-text-muted">لا توجد بنود.</td></tr>
          <?php else: ?>
            <?php foreach ($items as $index => $item): ?>
              <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-surface-low/40' ?> border-b border-border-subtle last:border-0 hover:bg-primary/5 transition-colors">
                <td class="p-3 whitespace-nowrap text-text-muted tabular-nums"><?= h((string) ($index + 1)) ?></td>
                <td class="p-3 font-semibold"><?= h(accounting_material_label($item)) ?></td>
                <td class="p-3 whitespace-nowrap tabular-nums"><?= h(format_decimal($item['quantityUnit2'] ?? null)) ?></td>
                <td class="p-3 whitespace-nowrap tabular-nums"><?= h(format_decimal($item['quantityUnit1'] ?? $item['quantity'] ?? null)) ?></td>
                <td class="p-3 whitespace-nowrap tabular-nums"><?= h(format_accounting_money($item['unitPriceUnit1'] ?? $item['price'] ?? null, $symbol, $code)) ?></td>
                <td class="p-3 whitespace-nowrap tabular-nums font-bold"><?= h(format_accounting_money($item['lineTotal'] ?? null, $symbol, $code)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php else: ?>
      <table class="w-full text-sm min-w-[980px]">
        <thead class="text-text-muted border-b border-border-subtle">
          <tr>
            <th class="text-right p-3">الحساب</th>
            <th class="text-right p-3">الحساب المقابل</th>
            <th class="text-right p-3">العميل</th>
            <th class="text-right p-3">مدين</th>
            <th class="text-right p-3">دائن</th>
            <th class="text-right p-3">ملاحظات</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($entryLines === []): ?>
            <tr><td colspan="6" class="p-4 text-text-muted">لا توجد قيود.</td></tr>
          <?php else: ?>
            <?php foreach ($entryLines as $line): ?>
              <tr class="border-b border-border-subtle last:border-0">
                <td class="p-3"><?= h((string) ($line['accountName'] ?? $line['accountCode'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($line['contraAccountName'] ?? $line['contraAccountCode'] ?? '—')) ?></td>
                <td class="p-3"><?= h((string) ($line['customerName'] ?? '—')) ?></td>
                <td class="p-3"><?= h(format_decimal($line['debit'] ?? null)) ?></td>
                <td class="p-3"><?= h(format_decimal($line['credit'] ?? null)) ?></td>
                <td class="p-3 text-text-muted"><?= h((string) ($line['notes'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
