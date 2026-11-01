<?php

declare(strict_types=1);

use Portal\Services\ProcurementService;

/** @var bool $tableReady */
/** @var string $tab */
/** @var string|null $flash */
/** @var string $flashType */
/** @var array{store_guids: list<string>, limit_to_current_season: bool} $settings */
/** @var array<string, mixed>|null $season */
/** @var array<string, list<array<string, mixed>>> $shortagesByManufacturer */
/** @var list<array<string, mixed>> $materials */
/** @var string $materialFilter */
/** @var string $materialQ */
/** @var string $materialManufacturer */
/** @var int $materialPage */
/** @var int $materialTotal */
/** @var string $materialView */
/** @var list<string> $materialManufacturers */
/** @var string $invQ */
/** @var string $invManufacturer */
/** @var int $invPage */
/** @var bool $invSeasonOnly */
/** @var array{items: list<array<string, mixed>>, total: int, page: int, page_size: int} $invPickerResults */
/** @var list<array<string, mixed>> $invoiceRows */
/** @var int $invoiceCount */
/** @var list<array<string, mixed>> $orders */
/** @var string $viewOrderId */
/** @var array<string, mixed>|null $viewOrder */
/** @var list<array<string, mixed>> $orderLines */
/** @var string $pickQ */
/** @var int $pickPage */
/** @var bool $pickSeasonOnly */
/** @var array{items: list<array<string, mixed>>, total: int, page: int, page_size: int} $pickerResults */
/** @var list<array<string, mixed>> $storeOptions */
/** @var list<string> $manufacturerOptions */
/** @var string|null $filterError */
/** @var list<string> $selectedStoreGuids */

$btnPrimary = 'h-10 px-4 inline-flex items-center justify-center rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110';
$btnLight = 'h-10 px-4 inline-flex items-center justify-center rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50';
$tabClass = static function (string $key) use ($tab): string {
    $base = 'px-3 py-2 rounded-lg text-sm font-bold ';
    if ($tab === $key) {
        return $base . 'bg-primary/10 text-primary';
    }

    return $base . 'text-text-muted hover:bg-surface-low';
};
$orderStatusLabels = [
    'draft' => 'مسودة',
    'sent' => 'مرسل للمصنع',
    'partially_received' => 'استلام جزئي',
    'closed' => 'مغلق',
    'cancelled' => 'ملغى',
];
?>
<section class="bg-white border border-border-subtle rounded-xl p-5 mb-4">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold mb-1">المشتريات — نواقص وطلبات المصانع</h1>
      <p class="text-sm text-text-muted max-w-2xl">
        اكتشاف مواد الأمين، ضبط الحدود بالطرد، وحساب النواقص حسب الشركة المصنّعة.
        المخزون يُحسب من المستودعات المحددة فقط (استبعد التوالف).
      </p>
    </div>
    <?php if ($tableReady): ?>
      <form method="post" class="shrink-0">
        <input type="hidden" name="action" value="sync_materials">
        <button type="submit" class="<?= h($btnPrimary) ?>">
          <span class="material-symbols-outlined text-base ml-1">sync</span>
          مزامنة مواد الأمين
        </button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($flash !== null && $flash !== ''): ?>
    <p class="mt-3 text-sm <?= $flashType === 'error' ? 'text-red-700' : 'text-emerald-700' ?>"><?= h($flash) ?></p>
  <?php endif; ?>
  <?php if (!$tableReady): ?>
    <p class="mt-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
      جداول المشتريات غير موجودة. نفّذ ترحيل <code class="text-xs">030-procurement.sql</code> على PostgreSQL.
    </p>
  <?php endif; ?>
</section>

<nav class="mb-4 flex flex-wrap gap-2" aria-label="أقسام المشتريات">
  <a class="<?= h($tabClass('shortages')) ?>" href="/dashboard/procurement.php?tab=shortages">النواقص</a>
  <a class="<?= h($tabClass('materials')) ?>" href="/dashboard/procurement.php?tab=materials">المواد</a>
  <a class="<?= h($tabClass('orders')) ?>" href="/dashboard/procurement.php?tab=orders">طلبات الشراء</a>
  <a class="<?= h($tabClass('settings')) ?>" href="/dashboard/procurement.php?tab=settings">الإعدادات</a>
</nav>

<?php if ($tab === 'settings'): ?>
  <section class="bg-white border border-border-subtle rounded-xl p-5">
    <h2 class="text-lg font-bold mb-3">إعدادات حساب المخزون</h2>
    <?php if ($filterError !== null): ?>
      <p class="text-sm text-red-700 mb-3"><?= h($filterError) ?></p>
    <?php endif; ?>
    <form method="post" class="space-y-4 max-w-3xl">
      <input type="hidden" name="action" value="save_settings">
      <div>
        <span class="text-sm font-bold block mb-2">مستودعات تدخل في «المتاح» (بدون توالف)</span>
        <div class="grid sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto border border-border-subtle rounded-lg p-3">
          <?php foreach ($storeOptions as $store): ?>
            <?php
              $guid = (string) ($store['guid'] ?? $store['Guid'] ?? '');
              $name = (string) ($store['name'] ?? $store['Name'] ?? $guid);
              if ($guid === '') {
                  continue;
              }
              $checked = in_array($guid, $selectedStoreGuids, true);
            ?>
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" name="store_guids[]" value="<?= h($guid) ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= h($name) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="limit_to_current_season" <?= $settings['limit_to_current_season'] ? 'checked' : '' ?>>
        <span>اقتراح الطلب فقط لأصناف مجموعات <strong>الموسم الحالي</strong>
          <?php if ($season !== null): ?>
            (<?= h((string) ($season['name_ar'] ?? '')) ?>)
          <?php else: ?>
            — لا موسم مفعّل حالياً
          <?php endif; ?>
        </span>
      </label>
      <button type="submit" class="<?= h($btnPrimary) ?>">حفظ</button>
    </form>
  </section>

<?php elseif ($tab === 'materials'): ?>
  <nav class="mb-4 flex flex-wrap gap-2" aria-label="عرض المواد">
    <?php
      $listModeHref = '/dashboard/procurement.php?' . http_build_query([
          'tab' => 'materials',
          'material_view' => 'list',
          'material_filter' => $materialFilter,
          'material_q' => $materialQ,
          'material_manufacturer' => $materialManufacturer,
          'material_page' => $materialPage > 1 ? $materialPage : null,
      ]);
      $invoiceModeHref = '/dashboard/procurement.php?' . http_build_query([
          'tab' => 'materials',
          'material_view' => 'invoice',
      ]);
    ?>
    <a href="<?= h($listModeHref) ?>"
       class="px-4 py-2 rounded-lg text-sm font-bold <?= $materialView === 'list' ? 'bg-primary text-white' : 'border border-border-subtle text-text-muted' ?>">
      قائمة وبحث
    </a>
    <a href="<?= h($invoiceModeHref) ?>"
       class="px-4 py-2 rounded-lg text-sm font-bold inline-flex items-center gap-1 <?= $materialView === 'invoice' ? 'bg-primary text-white' : 'border border-border-subtle text-text-muted' ?>">
      فاتورة ضبط
      <?php if ($invoiceCount > 0): ?>
        <span class="text-xs px-1.5 py-0.5 rounded-full bg-white/20"><?= h((string) $invoiceCount) ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <?php if ($materialView === 'invoice'): ?>
  <?php $invoiceFormId = 'procurement-invoice-save-all'; ?>
  <section id="procurement-invoice-root" class="bg-white border border-border-subtle rounded-xl p-5 mb-4"
           data-manufacturer="<?= h($invManufacturer) ?>">
    <form id="proc-invoice-batch-add" method="post" class="hidden">
      <input type="hidden" name="action" value="invoice_add_materials">
    </form>
    <form id="<?= h($invoiceFormId) ?>" method="post" class="hidden">
      <input type="hidden" name="action" value="invoice_save_all">
    </form>

    <div class="flex flex-wrap gap-3 mb-4 items-end">
      <label class="text-sm min-w-[10rem]">
        <span class="font-bold block mb-1">تقييد الشركة (اختياري)</span>
        <select id="proc-invoice-manufacturer" class="h-10 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="">— كل الشركات —</option>
          <?php foreach ($manufacturerOptions as $mfr): ?>
            <option value="<?= h($mfr) ?>" <?= $invManufacturer === $mfr ? 'selected' : '' ?>><?= h($mfr) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="text-sm flex-1 min-w-[220px] relative" id="proc-invoice-search-wrap">
        <span class="font-bold block mb-1">بحث وإضافة للشبكة</span>
        <input
          type="search"
          id="proc-invoice-search"
          autocomplete="off"
          enterkeyhint="search"
          role="combobox"
          aria-expanded="false"
          aria-controls="proc-invoice-search-results"
          class="h-10 w-full rounded-xl border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary"
          placeholder="اكتب كود أو اسم المادة…"
        >
        <ul
          id="proc-invoice-search-results"
          class="hidden absolute z-40 mt-1 w-full max-h-72 overflow-y-auto rounded-xl border border-border-subtle bg-white shadow-lg text-sm"
          role="listbox"
        ></ul>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <p id="proc-invoice-search-status" class="text-xs text-text-muted flex-1 min-h-[1.25rem]"></p>
      <button type="button" id="proc-invoice-add-selected" class="<?= h($btnPrimary) ?> h-9 px-4 text-xs hidden" disabled>
        إضافة المحدّد (<span id="proc-invoice-selected-count">0</span>)
      </button>
    </div>

    <div class="so-invoice">
      <div class="so-invoice__head">
        <div>
          <p class="so-invoice__kicker">ضبط سياسة الشراء</p>
          <h2 class="so-invoice__title">فاتورة المواد</h2>
          <p class="so-invoice__hint">اختر مادة أو أكثر من قائمة البحث — تُضاف دفعة واحدة للشبكة. ↑↓ Enter للاختيار السريع.</p>
        </div>
        <div class="flex flex-col items-end gap-2">
          <p class="so-invoice__count" id="proc-invoice-grid-count"><?= count($invoiceRows) ?> <?= count($invoiceRows) === 1 ? 'مادة' : 'مواد' ?></p>
          <?php if ($invoiceRows !== []): ?>
            <form method="post" onsubmit="return confirm('تفريغ كل البنود؟');">
              <input type="hidden" name="action" value="invoice_clear">
              <button type="submit" class="text-xs font-bold text-red-700">تفريغ الفاتورة</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="so-invoice__sheet">
        <?php if ($invoiceRows === []): ?>
          <p class="so-invoice__empty text-sm text-text-muted p-4">الشبكة فارغة — ابحث أعلاه وأضف مواداً.</p>
        <?php else: ?>
          <table class="so-invoice__table">
            <thead>
              <tr>
                <th class="so-invoice__num">#</th>
                <th>المادة</th>
                <th>متاح (طرد)</th>
                <th class="so-invoice__type-col">النوع</th>
                <th>حد طلب</th>
                <th>سقف</th>
                <th>ملاحظة</th>
                <th class="so-invoice__action-col"></th>
              </tr>
            </thead>
            <tbody id="proc-invoice-grid-body">
              <?php foreach ($invoiceRows as $index => $row): ?>
                <?php $guid = (string) ($row['material_guid'] ?? ''); ?>
                <tr class="so-invoice__row" data-material-guid="<?= h($guid) ?>">
                  <td class="so-invoice__num"><?= h((string) ($index + 1)) ?></td>
                  <td class="so-invoice__material">
                    <div class="so-invoice__material-inner">
                      <div class="so-invoice__material-text">
                        <span class="so-invoice__name"><?= h((string) ($row['material_name_ar'] ?? '')) ?></span>
                        <span class="so-invoice__code"><?= h((string) ($row['material_code'] ?? '')) ?></span>
                      </div>
                    </div>
                  </td>
                  <td class="text-xs"><?= h($row['available_cartons'] !== null ? (string) $row['available_cartons'] : '—') ?></td>
                  <td class="so-invoice__type">
                    <select form="<?= h($invoiceFormId) ?>" name="lifecycle[<?= h($guid) ?>]" class="so-invoice__select">
                      <?php foreach (ProcurementService::LIFECYCLE_LABELS as $code => $label): ?>
                        <option value="<?= h($code) ?>" <?= ($row['lifecycle'] ?? '') === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <input form="<?= h($invoiceFormId) ?>" type="number" step="0.001" min="0" name="min_cartons[<?= h($guid) ?>]" value="<?= h((string) ($row['min_cartons'] ?? '')) ?>"
                           class="so-invoice__input w-20">
                  </td>
                  <td>
                    <input form="<?= h($invoiceFormId) ?>" type="number" step="0.001" min="0" name="max_cartons[<?= h($guid) ?>]" value="<?= h((string) ($row['max_cartons'] ?? '')) ?>"
                           class="so-invoice__input w-20">
                  </td>
                  <td>
                    <input form="<?= h($invoiceFormId) ?>" type="text" name="notes_ar[<?= h($guid) ?>]" value="<?= h((string) ($row['notes_ar'] ?? '')) ?>"
                           class="so-invoice__input min-w-[6rem]">
                  </td>
                  <td class="so-invoice__action">
                    <form method="post">
                      <input type="hidden" name="action" value="invoice_remove_material">
                      <input type="hidden" name="material_guid" value="<?= h($guid) ?>">
                      <button type="submit" class="so-invoice__remove" title="حذف من الفاتورة">×</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php if ($invoiceRows !== []): ?>
        <div class="mt-4">
          <button type="submit" form="<?= h($invoiceFormId) ?>" class="<?= h($btnPrimary) ?>">حفظ كل البنود</button>
        </div>
      <?php endif; ?>
    </div>
    <script type="application/json" id="proc-invoice-grid-guids"><?= json_encode($invoiceGridGuids, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
  </section>

  <?php else: /* list */ ?>
  <section class="bg-white border border-border-subtle rounded-xl p-5 mb-4">
    <form method="get" class="grid md:grid-cols-4 gap-3 mb-4 items-end">
      <input type="hidden" name="tab" value="materials">
      <input type="hidden" name="material_view" value="list">
      <input type="hidden" name="material_filter" value="<?= h($materialFilter) ?>">
      <label class="md:col-span-2 text-sm">
        <span class="font-bold block mb-1">بحث (كود / اسم / شركة)</span>
        <input type="search" name="material_q" value="<?= h($materialQ) ?>" class="h-10 w-full rounded-lg border border-border-subtle px-3">
      </label>
      <label class="text-sm">
        <span class="font-bold block mb-1">الشركة</span>
        <select name="material_manufacturer" class="h-10 w-full rounded-lg border border-border-subtle px-2">
          <option value="">— الكل —</option>
          <?php foreach ($materialManufacturers as $mfr): ?>
            <option value="<?= h($mfr) ?>" <?= $materialManufacturer === $mfr ? 'selected' : '' ?>><?= h($mfr) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="<?= h($btnPrimary) ?> w-full">بحث</button>
    </form>
    <div class="flex flex-wrap gap-2 mb-4">
      <?php
        $filters = [
            'needs_config' => 'تحتاج ضبط',
            'trial' => 'تجربة',
            'season_active' => 'موسمي مستمر',
            'discontinued' => 'متوقف',
            'all' => 'الكل',
        ];
        foreach ($filters as $key => $label):
          $href = '/dashboard/procurement.php?' . http_build_query([
              'tab' => 'materials',
              'material_view' => 'list',
              'material_filter' => $key,
              'material_q' => $materialQ,
              'material_manufacturer' => $materialManufacturer,
          ]);
      ?>
        <a href="<?= h($href) ?>"
           class="text-xs font-bold px-3 py-1.5 rounded-full border <?= $materialFilter === $key ? 'bg-primary/10 border-primary text-primary' : 'border-border-subtle text-text-muted' ?>">
          <?= h($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="text-sm text-text-muted mb-2">
      <?= h((string) $materialTotal) ?> نتيجة — صفحة <?= h((string) $materialPage) ?>
      <?= (int) ceil(max(1, $materialTotal) / 50) > 1 ? ' من ' . (int) ceil($materialTotal / 50) : '' ?>
    </p>
    <p class="text-sm text-text-muted mb-4">كل مادة جديدة من الأمين تُسجَّل تلقائياً كـ «تجربة» حتى تضبطها هنا.</p>
    <?php if ($materials === []): ?>
      <p class="text-sm text-text-muted">لا مواد في هذا الفلتر. جرّب «مزامنة مواد الأمين».</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-right text-text-muted border-b">
              <th class="py-2 pl-2">الكود</th>
              <th class="py-2 pl-2">الاسم</th>
              <th class="py-2 pl-2">الشركة</th>
              <th class="py-2 pl-2">أول ظهور</th>
              <th class="py-2">ضبط</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($materials as $row): ?>
              <?php
                $guid = (string) ($row['material_guid'] ?? '');
                $lifecycle = (string) ($row['lifecycle'] ?? 'trial');
              ?>
              <tr class="border-b border-border-subtle/60 align-top">
                <td class="py-3 pl-2 font-mono text-xs"><?= h((string) ($row['material_code'] ?? '')) ?></td>
                <td class="py-3 pl-2"><?= h((string) ($row['material_name_ar'] ?? '')) ?></td>
                <td class="py-3 pl-2"><?= h((string) ($row['manufacturer'] ?? '')) ?></td>
                <td class="py-3 pl-2 text-xs text-text-muted"><?= h(substr((string) ($row['first_seen_at'] ?? ''), 0, 16)) ?></td>
                <td class="py-3">
                  <form method="post" class="space-y-2 min-w-[14rem]">
                    <input type="hidden" name="action" value="save_material">
                    <input type="hidden" name="material_view" value="list">
                    <input type="hidden" name="material_filter" value="<?= h($materialFilter) ?>">
                    <input type="hidden" name="material_q" value="<?= h($materialQ) ?>">
                    <input type="hidden" name="material_manufacturer" value="<?= h($materialManufacturer) ?>">
                    <input type="hidden" name="material_page" value="<?= h((string) $materialPage) ?>">
                    <input type="hidden" name="material_guid" value="<?= h($guid) ?>">
                    <select name="lifecycle" class="w-full h-9 rounded-lg border border-border-subtle px-2 text-sm">
                      <?php foreach (ProcurementService::LIFECYCLE_LABELS as $code => $label): ?>
                        <option value="<?= h($code) ?>" <?= $lifecycle === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="grid grid-cols-2 gap-1">
                      <input type="number" step="0.001" min="0" name="min_cartons" placeholder="حد الطلب (طرد)"
                             value="<?= h((string) ($row['min_cartons'] ?? '')) ?>" class="h-9 rounded-lg border border-border-subtle px-2 text-xs">
                      <input type="number" step="0.001" min="0" name="max_cartons" placeholder="سقف (طرد)"
                             value="<?= h((string) ($row['max_cartons'] ?? '')) ?>" class="h-9 rounded-lg border border-border-subtle px-2 text-xs">
                    </div>
                    <input type="text" name="notes_ar" placeholder="ملاحظة" value="<?= h((string) ($row['notes_ar'] ?? '')) ?>"
                           class="w-full h-9 rounded-lg border border-border-subtle px-2 text-xs">
                    <button type="submit" class="<?= h($btnLight) ?> w-full h-9 text-xs">حفظ</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($materialTotal > 50): ?>
        <nav class="mt-4 flex flex-wrap gap-2">
          <?php
            $totalPages = (int) ceil($materialTotal / 50);
            for ($p = max(1, $materialPage - 2); $p <= min($totalPages, $materialPage + 2); $p++):
              $pageHref = '/dashboard/procurement.php?' . http_build_query([
                  'tab' => 'materials',
                  'material_view' => 'list',
                  'material_filter' => $materialFilter,
                  'material_q' => $materialQ,
                  'material_manufacturer' => $materialManufacturer,
                  'material_page' => $p,
              ]);
          ?>
            <a href="<?= h($pageHref) ?>" class="px-3 py-1 rounded-lg text-sm font-bold <?= $p === $materialPage ? 'bg-primary text-white' : 'border border-border-subtle' ?>"><?= h((string) $p) ?></a>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>

<?php elseif ($tab === 'orders'): ?>
  <section class="bg-white border border-border-subtle rounded-xl p-4 mb-4">
    <h2 class="text-sm font-bold mb-2">مسودة جديدة (فاتورة شراء)</h2>
    <form method="post" class="flex flex-wrap gap-2 items-end">
      <input type="hidden" name="action" value="create_empty_order">
      <label class="text-sm flex-1 min-w-[12rem]">
        <span class="text-text-muted block mb-1">الشركة المصنّعة</span>
        <select name="manufacturer" required class="h-10 w-full rounded-lg border border-border-subtle px-2">
          <option value="">— اختر —</option>
          <?php foreach ($manufacturerOptions as $mfr): ?>
            <option value="<?= h($mfr) ?>"><?= h($mfr) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="<?= h($btnPrimary) ?>">إنشاء مسودة</button>
    </form>
  </section>
  <section class="grid lg:grid-cols-2 gap-4">
    <div class="bg-white border border-border-subtle rounded-xl p-5">
      <h2 class="text-lg font-bold mb-3">طلبات مفتوحة</h2>
      <?php if ($orders === []): ?>
        <p class="text-sm text-text-muted">لا مسودات — أنشئ من النواقص أو «مسودة جديدة» أعلاه.</p>
      <?php else: ?>
        <ul class="space-y-2">
          <?php foreach ($orders as $order): ?>
            <?php
              $oid = (string) ($order['id'] ?? '');
              $active = $viewOrderId === $oid;
              $status = (string) ($order['status'] ?? 'draft');
            ?>
            <li>
              <a href="/dashboard/procurement.php?tab=orders&amp;order=<?= h(rawurlencode($oid)) ?>"
                 class="block rounded-lg border px-3 py-2 <?= $active ? 'border-primary bg-primary/5' : 'border-border-subtle hover:bg-surface-low' ?>">
                <span class="font-bold"><?= h((string) ($order['manufacturer'] ?? '')) ?></span>
                <span class="text-xs text-text-muted mr-2"><?= h($orderStatusLabels[$status] ?? $status) ?></span>
                <span class="text-xs text-text-muted"> — <?= h(substr((string) ($order['created_at'] ?? ''), 0, 10)) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="bg-white border border-border-subtle rounded-xl p-5 space-y-5">
      <?php if ($viewOrderId === '' || $viewOrder === null): ?>
        <p class="text-sm text-text-muted">اختر طلباً من القائمة — ثم أضف الأصناف كفاتورة.</p>
      <?php else: ?>
        <div>
          <h2 class="text-lg font-bold"><?= h((string) ($viewOrder['manufacturer'] ?? '')) ?></h2>
          <p class="text-xs text-text-muted">مسودة طلب شراء — <?= h($orderStatusLabels[(string) ($viewOrder['status'] ?? 'draft')] ?? '') ?></p>
        </div>

        <div>
          <h3 class="text-sm font-bold mb-2">بنود الفاتورة (<?= h((string) count($orderLines)) ?>)</h3>
          <?php if ($orderLines === []): ?>
            <p class="text-sm text-text-muted">لا بنود بعد — ابحث أدناه أو «ملء من المعايير».</p>
          <?php else: ?>
            <div class="overflow-x-auto border border-border-subtle rounded-lg">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-right text-text-muted bg-surface-low">
                    <th class="p-2">كود</th>
                    <th class="p-2">الصنف</th>
                    <th class="p-2">متاح</th>
                    <th class="p-2">طرد</th>
                    <th class="p-2"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orderLines as $line): ?>
                    <tr class="border-t border-border-subtle/60">
                      <td class="p-2 font-mono text-xs"><?= h((string) ($line['material_code'] ?? '')) ?></td>
                      <td class="p-2"><?= h((string) ($line['material_name_ar'] ?? '')) ?></td>
                      <td class="p-2 text-xs"><?= h((string) ($line['available_cartons'] ?? '')) ?></td>
                      <td class="p-2">
                        <form method="post" class="flex gap-1 items-center">
                          <input type="hidden" name="action" value="update_line_qty">
                          <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
                          <input type="hidden" name="line_id" value="<?= h((string) ($line['id'] ?? '')) ?>">
                          <input type="number" step="0.001" min="0" name="approved_cartons" value="<?= h((string) ($line['approved_cartons'] ?? '')) ?>"
                                 class="h-8 w-20 rounded border border-border-subtle px-1 text-xs">
                          <button type="submit" class="text-xs font-bold text-primary">✓</button>
                        </form>
                      </td>
                      <td class="p-2">
                        <?php if (($line['line_status'] ?? '') === 'open'): ?>
                          <form method="post">
                            <input type="hidden" name="action" value="close_line">
                            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
                            <input type="hidden" name="line_id" value="<?= h((string) ($line['id'] ?? '')) ?>">
                            <button type="submit" class="text-xs text-text-muted">إغلاق</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="border-t border-border-subtle pt-4">
          <h3 class="text-sm font-bold mb-2">ملء تلقائي (معايير الطلب)</h3>
          <form method="post" class="space-y-2">
            <input type="hidden" name="action" value="fill_order_criteria">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" name="only_below_min" checked>
              <span>فقط تحت حد تفعيل الطلب (نواقص)</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input type="checkbox" name="only_season_active" checked>
              <span>فقط «موسمي مستمر»</span>
            </label>
            <button type="submit" class="<?= h($btnLight) ?> text-xs">إضافة الأصناف المطابقة</button>
          </form>
        </div>

        <div class="border-t border-border-subtle pt-4">
          <h3 class="text-sm font-bold mb-2">بحث وإضافة أصناف</h3>
          <form method="get" class="flex flex-wrap gap-2 items-end mb-3">
            <input type="hidden" name="tab" value="orders">
            <input type="hidden" name="order" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="pick_search" value="1">
            <label class="flex-1 min-w-[10rem] text-sm">
              <input type="search" name="pick_q" value="<?= h($pickQ) ?>" placeholder="كود أو اسم…" class="h-10 w-full rounded-lg border border-border-subtle px-3">
            </label>
            <label class="flex items-center gap-2 text-xs whitespace-nowrap">
              <input type="checkbox" name="pick_season" value="1" <?= $pickSeasonOnly ? 'checked' : '' ?>>
              موسم حالي
            </label>
            <button type="submit" class="<?= h($btnPrimary) ?> h-10">بحث</button>
          </form>
          <?php if ($pickQ !== '' || isset($_GET['pick_search'])): ?>
            <p class="text-xs text-text-muted mb-2"><?= h((string) ($pickerResults['total'] ?? 0)) ?> في الأمين — شركة: <?= h((string) ($viewOrder['manufacturer'] ?? '')) ?></p>
            <?php if (($pickerResults['items'] ?? []) === []): ?>
              <p class="text-sm text-text-muted">لا نتائج.</p>
            <?php else: ?>
              <div class="space-y-2 max-h-80 overflow-y-auto">
                <?php foreach ($pickerResults['items'] as $pick): ?>
                  <form method="post" class="flex flex-wrap gap-2 items-center border border-border-subtle rounded-lg p-2 text-sm">
                    <input type="hidden" name="action" value="add_order_line">
                    <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
                    <input type="hidden" name="material_guid" value="<?= h((string) ($pick['material_guid'] ?? '')) ?>">
                    <input type="hidden" name="pick_q" value="<?= h($pickQ) ?>">
                    <input type="hidden" name="pick_page" value="<?= h((string) $pickPage) ?>">
                    <?php if ($pickSeasonOnly): ?><input type="hidden" name="pick_season" value="1"><?php endif; ?>
                    <div class="flex-1 min-w-[8rem]">
                      <div class="font-bold"><?= h((string) ($pick['material_name_ar'] ?? '')) ?></div>
                      <div class="text-xs font-mono text-text-muted"><?= h((string) ($pick['material_code'] ?? '')) ?>
                        — متاح <?= h((string) ($pick['available_cartons'] ?? '')) ?> طرد
                        <?php if (($pick['lifecycle'] ?? '') !== ''): ?>
                          · <?= h(ProcurementService::LIFECYCLE_LABELS[$pick['lifecycle']] ?? (string) $pick['lifecycle']) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <input type="number" step="0.001" min="0.001" name="approved_cartons"
                           value="<?= h((string) (($pick['suggested_cartons'] ?? 0) > 0 ? $pick['suggested_cartons'] : 1)) ?>"
                           class="h-9 w-20 rounded-lg border border-border-subtle px-2 text-xs" title="طرد">
                    <button type="submit" class="<?= h($btnPrimary) ?> h-9 px-3 text-xs">+ إضافة</button>
                  </form>
                <?php endforeach; ?>
              </div>
              <?php
                $pickTotalPages = (int) ceil(max(1, (int) ($pickerResults['total'] ?? 0)) / max(1, (int) ($pickerResults['page_size'] ?? 25)));
                if ($pickTotalPages > 1):
              ?>
                <nav class="mt-2 flex gap-1">
                  <?php for ($pp = max(1, $pickPage - 2); $pp <= min($pickTotalPages, $pickPage + 2); $pp++): ?>
                    <?php
                      $pickHref = '/dashboard/procurement.php?' . http_build_query([
                          'tab' => 'orders',
                          'order' => $viewOrderId,
                          'pick_q' => $pickQ,
                          'pick_page' => $pp,
                          'pick_season' => $pickSeasonOnly ? '1' : '0',
                          'pick_search' => '1',
                      ]);
                    ?>
                    <a href="<?= h($pickHref) ?>" class="px-2 py-1 text-xs rounded border <?= $pp === $pickPage ? 'bg-primary text-white' : '' ?>"><?= h((string) $pp) ?></a>
                  <?php endfor; ?>
                </nav>
              <?php endif; ?>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-xs text-text-muted">اكتب كلمة بحث ثم «بحث» لعرض أصناف الشركة من الأمين.</p>
          <?php endif; ?>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-border-subtle pt-4">
          <form method="post">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="sent">
            <button type="submit" class="<?= h($btnPrimary) ?> text-xs">تعليم «مرسل»</button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="closed">
            <button type="submit" class="<?= h($btnLight) ?> text-xs">إغلاق الطلب</button>
          </form>
          <form method="post" onsubmit="return confirm('إلغاء الطلب؟');">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="cancelled">
            <button type="submit" class="h-10 px-3 rounded-xl border border-red-200 text-red-700 text-xs font-bold">إلغاء</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php else: /* shortages */ ?>
  <section class="bg-white border border-border-subtle rounded-xl p-5 mb-4">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h2 class="text-lg font-bold">نواقص الموسم (بالطرد)</h2>
        <?php if ($settings['store_guids'] === []): ?>
          <p class="text-sm text-amber-800">اختر مستودعات في تبويب الإعدادات أولاً.</p>
        <?php endif; ?>
      </div>
      <?php if ($shortagesByManufacturer !== []): ?>
        <form method="post">
          <input type="hidden" name="action" value="create_drafts">
          <button type="submit" class="<?= h($btnPrimary) ?>">إنشاء مسودة لكل شركة</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($shortagesByManufacturer === []): ?>
      <p class="text-sm text-text-muted">لا نواقص حالياً — أو لا مواد «موسمي مستمر» مضبوطة ضمن الموسم.</p>
    <?php else: ?>
      <?php foreach ($shortagesByManufacturer as $manufacturer => $lines): ?>
        <div class="mb-6 last:mb-0">
          <h3 class="font-bold text-primary mb-2 flex items-center gap-2">
            <span class="material-symbols-outlined text-base">factory</span>
            <?= h($manufacturer) ?>
          </h3>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-right text-text-muted border-b">
                  <th class="py-2">كود</th>
                  <th class="py-2">الصنف</th>
                  <th class="py-2">متاح (طرد)</th>
                  <th class="py-2">حد الطلب</th>
                  <th class="py-2">سقف</th>
                  <th class="py-2">مقترح</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($lines as $line): ?>
                  <tr class="border-b border-border-subtle/60">
                    <td class="py-2 font-mono text-xs"><?= h((string) ($line['material_code'] ?? '')) ?></td>
                    <td class="py-2"><?= h((string) ($line['material_name_ar'] ?? '')) ?></td>
                    <td class="py-2"><?= h((string) ($line['available_cartons'] ?? '')) ?></td>
                    <td class="py-2"><?= h((string) ($line['min_cartons'] ?? '')) ?></td>
                    <td class="py-2"><?= h((string) ($line['max_cartons'] ?? '')) ?></td>
                    <td class="py-2 font-bold"><?= h((string) ($line['suggested_cartons'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
