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
/** @var array<string, mixed>|null $shortagesDiagnostics */
/** @var list<array<string, mixed>> $materials */
/** @var string $materialFilter */
/** @var string $materialQ */
/** @var string $materialManufacturer */
/** @var int $materialPage */
/** @var int $materialTotal */
/** @var string $materialView */
/** @var list<string> $materialManufacturers */
/** @var string $invManufacturer */
/** @var list<array<string, mixed>> $invoiceRows */
/** @var int $invoiceCount */
/** @var list<string> $invoiceGridGuids */
/** @var bool $loadProcurementInvoiceJs */
/** @var bool $loadProcurementOrdersJs */
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

$btnPrimary = 'proc-btn proc-btn--primary h-10 px-4 inline-flex items-center justify-center rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110 w-full sm:w-auto';
$btnLight = 'proc-btn h-10 px-4 inline-flex items-center justify-center rounded-xl border border-border-subtle bg-white text-sm font-bold text-slate-700 hover:bg-slate-50 w-full sm:w-auto';
$procPanel = 'proc-panel bg-white border border-border-subtle rounded-xl p-4 sm:p-5 mb-4';
$shortageLineCount = 0;
foreach ($shortagesByManufacturer as $manLines) {
    $shortageLineCount += count($manLines);
}
$procMainTabs = [
    'shortages' => ['label' => 'النواقص', 'icon' => 'production_quantity_limits', 'badge' => $shortageLineCount > 0 ? $shortageLineCount : null],
    'materials' => ['label' => 'المواد', 'icon' => 'inventory_2', 'badge' => $invoiceCount > 0 ? $invoiceCount : null],
    'orders' => ['label' => 'الطلبات', 'icon' => 'receipt_long', 'badge' => count($orders) > 0 ? count($orders) : null],
    'settings' => ['label' => 'إعدادات', 'icon' => 'tune', 'badge' => null],
];
$orderStatusLabels = [
    'draft' => 'مسودة',
    'sent' => 'مرسل للمصنع',
    'partially_received' => 'استلام جزئي',
    'closed' => 'مغلق',
    'cancelled' => 'ملغى',
];
?>
<div class="proc-page">
  <header class="proc-hero <?= h($procPanel) ?>">
    <div class="proc-hero__main">
      <div class="proc-hero__title-row">
        <span class="material-symbols-outlined proc-hero__icon" aria-hidden="true">local_shipping</span>
        <h1 class="proc-hero__title">المشتريات</h1>
      </div>
      <p class="proc-hero__desc text-sm text-text-muted">
        نواقص المخزون بالطرد، ضبط المواد، ومسودات طلبات المصانع.
        <span class="hidden sm:inline">المتاح من المستودعات المختارة فقط.</span>
      </p>
    </div>
    <?php if ($tableReady): ?>
      <form method="post" class="proc-hero__action">
        <input type="hidden" name="action" value="sync_materials">
        <button type="submit" class="<?= h($btnPrimary) ?>">
          <span class="material-symbols-outlined text-base ml-1">sync</span>
          مزامنة الأمين
        </button>
      </form>
    <?php endif; ?>
    <?php if ($flash !== null && $flash !== ''): ?>
      <p class="proc-flash <?= $flashType === 'error' ? 'proc-flash--error' : 'proc-flash--ok' ?>" role="status"><?= h($flash) ?></p>
    <?php endif; ?>
    <?php if (!$tableReady): ?>
      <p class="proc-alert">
        جداول المشتريات غير موجودة. نفّذ ترحيل <code class="text-xs">030-procurement.sql</code> على PostgreSQL.
      </p>
    <?php endif; ?>
  </header>

  <nav class="proc-tabs" aria-label="أقسام المشتريات">
    <?php foreach ($procMainTabs as $tabKey => $tabMeta): ?>
      <?php
        $tabHref = '/dashboard/procurement.php?' . http_build_query(['tab' => $tabKey]);
        $isActive = $tab === $tabKey;
      ?>
      <a
        class="proc-tabs__link<?= $isActive ? ' is-active' : '' ?>"
        href="<?= h($tabHref) ?>"
        <?= $isActive ? 'aria-current="page"' : '' ?>
      >
        <span class="material-symbols-outlined proc-tabs__icon" aria-hidden="true"><?= h($tabMeta['icon']) ?></span>
        <span class="proc-tabs__label"><?= h($tabMeta['label']) ?></span>
        <?php if ($tabMeta['badge'] !== null): ?>
          <span class="proc-tabs__badge"><?= h((string) $tabMeta['badge']) ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

<?php if ($tab === 'settings'): ?>
  <section class="<?= h($procPanel) ?>">
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
  <nav class="proc-subtabs" aria-label="عرض المواد">
    <a href="<?= h($listModeHref) ?>"
       class="proc-subtabs__link<?= $materialView === 'list' ? ' is-active' : '' ?>">
      قائمة وبحث
    </a>
    <a href="<?= h($invoiceModeHref) ?>"
       class="proc-subtabs__link<?= $materialView === 'invoice' ? ' is-active' : '' ?>">
      فاتورة ضبط
      <?php if ($invoiceCount > 0): ?>
        <span class="proc-subtabs__badge"><?= h((string) $invoiceCount) ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <?php if ($materialView === 'invoice'): ?>
  <?php $invoiceFormId = 'procurement-invoice-save-all'; ?>
  <section id="procurement-invoice-root" class="<?= h($procPanel) ?> proc-invoice-shell"
           data-manufacturer="<?= h($invManufacturer) ?>">
    <form id="proc-invoice-batch-add" method="post" class="hidden">
      <input type="hidden" name="action" value="invoice_add_materials">
    </form>
    <form id="<?= h($invoiceFormId) ?>" method="post" class="hidden">
      <input type="hidden" name="action" value="invoice_save_all">
    </form>

    <div class="proc-toolbar flex flex-col sm:flex-row flex-wrap gap-3 mb-4 sm:items-end">
      <label class="text-sm w-full sm:min-w-[10rem] sm:flex-1 sm:max-w-xs">
        <span class="font-bold block mb-1">تقييد الشركة (اختياري)</span>
        <select id="proc-invoice-manufacturer" class="h-10 w-full rounded-lg border border-border-subtle px-2 text-sm">
          <option value="">— كل الشركات —</option>
          <?php foreach ($manufacturerOptions as $mfr): ?>
            <option value="<?= h($mfr) ?>" <?= $invManufacturer === $mfr ? 'selected' : '' ?>><?= h($mfr) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p id="proc-invoice-search-status" class="text-xs text-text-muted flex-1 min-h-[1.25rem] pb-2"></p>
      <button type="button" id="proc-invoice-add-selected" class="<?= h($btnPrimary) ?> h-9 px-4 text-xs hidden" disabled>
        إضافة المحدّد (<span id="proc-invoice-selected-count">0</span>)
      </button>
    </div>

    <div class="so-invoice">
      <div class="so-invoice__head">
        <div>
          <p class="so-invoice__kicker">ضبط سياسة الشراء</p>
          <h2 class="so-invoice__title">فاتورة المواد</h2>
          <p class="so-invoice__hint">اكتب في سطر الشبكة السفلي (مثل Excel) — حدّد عدة مواد ثم «إضافة المحدّد»، أو Enter لإضافة سطر واحد.</p>
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
        <table class="so-invoice__table">
          <thead>
            <tr>
              <th class="so-invoice__num">#</th>
              <th>المادة</th>
              <th>متاح (طرد)</th>
              <th class="so-invoice__type-col">الحالة</th>
              <th>حد طلب</th>
              <th>سقف</th>
              <th>ملاحظة</th>
              <th class="so-invoice__action-col"></th>
            </tr>
          </thead>
          <tbody id="proc-invoice-grid-body">
            <?php foreach ($invoiceRows as $index => $row): ?>
              <?php
                $guid = (string) ($row['material_guid'] ?? '');
                $lc = ProcurementService::normalizeLifecycle((string) ($row['lifecycle'] ?? 'new'));
              ?>
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
                      <option value="<?= h($code) ?>" <?= $lc === $code ? 'selected' : '' ?>><?= h($label) ?></option>
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
            <tr class="so-invoice__row proc-invoice-composer" data-role="composer">
              <td class="so-invoice__num">+</td>
              <td class="so-invoice__material proc-invoice-material-cell relative" colspan="1">
                <input
                  type="search"
                  id="proc-invoice-grid-input"
                  class="proc-invoice-material-input so-invoice__input w-full min-w-[12rem]"
                  autocomplete="off"
                  enterkeyhint="search"
                  role="combobox"
                  aria-expanded="false"
                  aria-controls="proc-invoice-grid-dropdown"
                  placeholder="اكتب كود أو اسم المادة…"
                >
                <ul
                  id="proc-invoice-grid-dropdown"
                  class="proc-invoice-dropdown hidden absolute z-50 mt-1 min-w-[16rem] max-w-[28rem] max-h-72 overflow-y-auto rounded-xl border border-border-subtle bg-white shadow-lg text-sm"
                  role="listbox"
                ></ul>
              </td>
              <td colspan="6" class="text-xs text-text-muted">↑↓ للتنقل · Enter يبدّل التحديد · Enter مرتين أو الزر أعلاه للإضافة</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mt-4 flex flex-wrap gap-2 items-center">
        <?php if ($invoiceRows !== []): ?>
          <button type="submit" form="<?= h($invoiceFormId) ?>" class="<?= h($btnPrimary) ?>">حفظ كل البنود</button>
        <?php else: ?>
          <p class="text-xs text-text-muted">أضف مواداً من السطر السفلي ثم احفظ الضبط.</p>
        <?php endif; ?>
      </div>
    </div>
    <script type="application/json" id="proc-invoice-grid-guids"><?= json_encode($invoiceGridGuids, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?></script>
  </section>

  <?php else: /* list */ ?>
  <section class="<?= h($procPanel) ?>">
    <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4 items-end">
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
    <div class="proc-chip-scroll mb-4">
      <?php
        $filters = [
            'needs_config' => 'تحتاج ضبط',
            'new' => 'جديد',
            'active' => 'فعال',
            'cancelled' => 'ملغي',
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
           class="proc-chip<?= $materialFilter === $key ? ' is-active' : '' ?>">
          <?= h($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="text-sm text-text-muted mb-2">
      <?= h((string) $materialTotal) ?> نتيجة — صفحة <?= h((string) $materialPage) ?>
      <?= (int) ceil(max(1, $materialTotal) / 50) > 1 ? ' من ' . (int) ceil($materialTotal / 50) : '' ?>
    </p>
    <p class="text-sm text-text-muted mb-4">كل مادة جديدة من الأمين تُسجَّل تلقائياً «جديد» حتى تضبط حالتها وحدود الطلب.</p>
    <?php if ($materials === []): ?>
      <p class="text-sm text-text-muted">لا مواد في هذا الفلتر. جرّب «مزامنة مواد الأمين».</p>
    <?php else: ?>
      <p class="proc-scroll-hint sm:hidden text-xs text-text-muted mb-2">مرّر الجدول أفقياً لعرض ضبط المواد</p>
      <div class="proc-table-scroll overflow-x-auto -mx-1 px-1">
        <table class="w-full text-sm min-w-[640px]">
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
                $lifecycle = ProcurementService::normalizeLifecycle((string) ($row['lifecycle'] ?? 'new'));
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
  <section class="<?= h($procPanel) ?>">
    <h2 class="text-sm font-bold mb-2">مسودة طلب جديد</h2>
    <form method="post" class="flex flex-col sm:flex-row flex-wrap gap-2 sm:items-end">
      <input type="hidden" name="action" value="create_empty_order">
      <label class="text-sm flex-1 min-w-0 w-full sm:min-w-[12rem]">
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
  <div class="proc-orders-layout">
    <aside class="proc-orders-list <?= h($procPanel) ?>">
      <h2 class="text-base sm:text-lg font-bold mb-3">طلبات مفتوحة</h2>
      <?php if ($orders === []): ?>
        <p class="text-sm text-text-muted">لا مسودات — أنشئ من النواقص أو «مسودة جديد» أعلاه.</p>
      <?php else: ?>
        <ul class="proc-orders-pills" role="list">
          <?php foreach ($orders as $order): ?>
            <?php
              $oid = (string) ($order['id'] ?? '');
              $active = $viewOrderId === $oid;
              $status = (string) ($order['status'] ?? 'draft');
            ?>
            <li>
              <a href="/dashboard/procurement.php?tab=orders&amp;order=<?= h(rawurlencode($oid)) ?>"
                 class="proc-order-pill<?= $active ? ' is-active' : '' ?>">
                <span class="proc-order-pill__name"><?= h((string) ($order['manufacturer'] ?? '')) ?></span>
                <span class="proc-order-pill__meta">
                  <?= h($orderStatusLabels[$status] ?? $status) ?>
                  · <?= h(substr((string) ($order['created_at'] ?? ''), 0, 10)) ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </aside>
    <div class="proc-orders-detail <?= h($procPanel) ?> space-y-5 min-w-0">
      <?php if ($viewOrderId === '' || $viewOrder === null): ?>
        <p class="text-sm text-text-muted">اختر طلباً من القائمة — ثم أضف الأصناف كفاتورة.</p>
      <?php else: ?>
        <div>
          <h2 class="text-lg font-bold"><?= h((string) ($viewOrder['manufacturer'] ?? '')) ?></h2>
          <p class="text-xs text-text-muted">مسودة طلب شراء — <?= h($orderStatusLabels[(string) ($viewOrder['status'] ?? 'draft')] ?? '') ?></p>
        </div>

        <div id="proc-order-lines-root" data-order-id="<?= h($viewOrderId) ?>">
          <h3 class="text-sm font-bold mb-2">بنود الطلب (<span id="proc-order-lines-visible-count"><?= h((string) count($orderLines)) ?></span> / <?= h((string) count($orderLines)) ?>)</h3>
          <?php if ($orderLines === []): ?>
            <p class="text-sm text-text-muted">لا بنود بعد — استخدم «ملء من المعايير» أو البحث أدناه.</p>
          <?php else: ?>
            <div class="proc-order-filters grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mb-3 p-3 rounded-lg bg-surface-low border border-border-subtle">
              <label class="text-xs lg:col-span-2">
                <span class="font-bold block mb-1">بحث في البنود</span>
                <input type="search" id="proc-order-filter-q" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm" placeholder="كود أو اسم…">
              </label>
              <label class="text-xs">
                <span class="font-bold block mb-1">الحالة</span>
                <select id="proc-order-filter-lifecycle" class="h-9 w-full rounded-lg border border-border-subtle px-2 text-sm">
                  <option value="">— الكل —</option>
                  <?php foreach (ProcurementService::LIFECYCLE_LABELS as $code => $label): ?>
                    <option value="<?= h($code) ?>"><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="text-xs flex items-end gap-2 pb-1 min-h-[2.25rem]">
                <input type="checkbox" id="proc-order-filter-below-min" class="shrink-0">
                <span>تحت حد الطلب فقط</span>
              </label>
              <label class="text-xs flex items-center gap-2 min-h-[2.25rem]">
                <input type="checkbox" id="proc-order-filter-open-only" class="shrink-0">
                <span>بنود مفتوحة فقط</span>
              </label>
              <button type="button" id="proc-order-filter-reset" class="<?= h($btnLight) ?> h-9 text-xs px-3 lg:col-span-2">إعادة ضبط الفلاتر</button>
            </div>
            <p class="proc-scroll-hint lg:hidden text-xs text-text-muted mb-2">بطاقات البنود على الشاشة الصغيرة — أو مرّر الجدول على الأجهزة اللوحية</p>
            <div class="proc-table-scroll overflow-x-auto border border-border-subtle rounded-lg lg:overflow-visible">
              <table class="proc-table proc-table--cards w-full text-sm min-w-[720px] lg:min-w-0" id="proc-order-lines-table">
                <thead>
                  <tr class="text-right text-text-muted bg-surface-low">
                    <th class="p-2">كود</th>
                    <th class="p-2">الصنف</th>
                    <th class="p-2">حالة</th>
                    <th class="p-2">متاح</th>
                    <th class="p-2">حد / سقف</th>
                    <th class="p-2">طرد</th>
                    <th class="p-2"></th>
                  </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderLines as $line): ?>
                    <?php
                      $lc = (string) ($line['lifecycle'] ?? '');
                      $lineId = (string) ($line['id'] ?? '');
                      $guid = (string) ($line['material_guid'] ?? '');
                      $searchRaw = trim((string) ($line['material_code'] ?? '') . ' ' . (string) ($line['material_name_ar'] ?? ''));
                      $searchHay = function_exists('mb_strtolower') ? mb_strtolower($searchRaw, 'UTF-8') : strtolower($searchRaw);
                    ?>
                    <tr class="border-t border-border-subtle/60 proc-order-line"
                        data-line-id="<?= h($lineId) ?>"
                        data-material-guid="<?= h($guid) ?>"
                        data-lifecycle="<?= h($lc) ?>"
                        data-below-min="<?= !empty($line['below_min']) ? '1' : '0' ?>"
                        data-line-open="<?= ($line['line_status'] ?? '') === 'open' ? '1' : '0' ?>"
                        data-search="<?= h($searchHay) ?>">
                      <td class="p-2 font-mono text-xs" data-label="كود"><?= h((string) ($line['material_code'] ?? '')) ?></td>
                      <td class="p-2" data-label="الصنف"><?= h((string) ($line['material_name_ar'] ?? '')) ?></td>
                      <td class="p-2 text-xs" data-label="حالة">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-slate-100"><?= h((string) ($line['lifecycle_label'] ?? '—')) ?></span>
                      </td>
                      <td class="p-2 text-xs" data-label="متاح"><?= h((string) ($line['available_cartons'] ?? '')) ?></td>
                      <td class="p-2 text-xs text-text-muted" data-label="حد / سقف">
                        <?= h((string) ($line['min_cartons'] ?? '—')) ?> / <?= h((string) ($line['max_cartons'] ?? '—')) ?>
                      </td>
                      <td class="p-2" data-label="طرد">
                        <form method="post" class="flex gap-1 items-center">
                          <input type="hidden" name="action" value="update_line_qty">
                          <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
                          <input type="hidden" name="line_id" value="<?= h($lineId) ?>">
                          <input type="number" step="0.001" min="0" name="approved_cartons" value="<?= h((string) ($line['approved_cartons'] ?? '')) ?>"
                                 class="h-8 w-20 rounded border border-border-subtle px-1 text-xs">
                          <button type="submit" class="text-xs font-bold text-primary">✓</button>
                        </form>
                      </td>
                      <td class="p-2 whitespace-nowrap" data-label="إجراء">
                        <button type="button"
                                class="text-xs font-bold text-primary proc-order-edit-material"
                                data-material-guid="<?= h($guid) ?>"
                                data-material-code="<?= h((string) ($line['material_code'] ?? '')) ?>"
                                data-material-name="<?= h((string) ($line['material_name_ar'] ?? '')) ?>"
                                data-lifecycle="<?= h($lc) ?>"
                                data-min="<?= h((string) ($line['min_cartons'] ?? '')) ?>"
                                data-max="<?= h((string) ($line['max_cartons'] ?? '')) ?>"
                                data-notes="<?= h((string) ($line['notes_ar'] ?? '')) ?>">
                          ضبط
                        </button>
                        <?php if (($line['line_status'] ?? '') === 'open'): ?>
                          <form method="post" class="inline">
                            <input type="hidden" name="action" value="close_line">
                            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
                            <input type="hidden" name="line_id" value="<?= h($lineId) ?>">
                            <button type="submit" class="text-xs text-text-muted mr-1">إغلاق</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <dialog id="proc-order-material-dialog" class="proc-order-dialog rounded-xl border border-border-subtle p-0 max-w-md w-[calc(100%-2rem)] shadow-xl backdrop:bg-black/40">
              <form method="post" class="p-5 space-y-3">
                <input type="hidden" name="action" value="save_material">
                <input type="hidden" name="return_order_id" value="<?= h($viewOrderId) ?>">
                <input type="hidden" name="material_guid" id="proc-order-dialog-guid" value="">
                <h3 class="text-lg font-bold" id="proc-order-dialog-title">ضبط المادة</h3>
                <p class="text-xs text-text-muted" id="proc-order-dialog-subtitle"></p>
                <label class="text-sm block">
                  <span class="font-bold">الحالة</span>
                  <select name="lifecycle" id="proc-order-dialog-lifecycle" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-2">
                    <?php foreach (ProcurementService::LIFECYCLE_LABELS as $code => $label): ?>
                      <option value="<?= h($code) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <div class="grid grid-cols-2 gap-2">
                  <label class="text-sm">
                    <span class="font-bold">حد الطلب (طرد)</span>
                    <input type="number" step="0.001" min="0" name="min_cartons" id="proc-order-dialog-min" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-2">
                  </label>
                  <label class="text-sm">
                    <span class="font-bold">سقف (طرد)</span>
                    <input type="number" step="0.001" min="0" name="max_cartons" id="proc-order-dialog-max" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-2">
                  </label>
                </div>
                <label class="text-sm block">
                  <span class="font-bold">ملاحظة</span>
                  <input type="text" name="notes_ar" id="proc-order-dialog-notes" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-2">
                </label>
                <div class="flex flex-wrap gap-2 pt-2">
                  <button type="submit" class="<?= h($btnPrimary) ?>">حفظ</button>
                  <button type="button" class="<?= h($btnLight) ?>" id="proc-order-dialog-cancel">إلغاء</button>
                </div>
              </form>
            </dialog>
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
              <input type="checkbox" name="only_active" checked>
              <span>فقط مواد «فعال»</span>
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

        <div class="proc-action-bar flex flex-col sm:flex-row flex-wrap gap-2 border-t border-border-subtle pt-4">
          <form method="post" class="w-full sm:w-auto">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="sent">
            <button type="submit" class="<?= h($btnPrimary) ?> text-xs w-full sm:w-auto">تعليم «مرسل»</button>
          </form>
          <form method="post" class="w-full sm:w-auto">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="closed">
            <button type="submit" class="<?= h($btnLight) ?> text-xs w-full sm:w-auto">إغلاق الطلب</button>
          </form>
          <form method="post" class="w-full sm:w-auto" onsubmit="return confirm('إلغاء الطلب؟');">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= h($viewOrderId) ?>">
            <input type="hidden" name="status" value="cancelled">
            <button type="submit" class="h-10 px-3 rounded-xl border border-red-200 text-red-700 text-xs font-bold w-full sm:w-auto">إلغاء</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: /* shortages */ ?>
  <section class="<?= h($procPanel) ?>">
    <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center justify-between gap-3 mb-4">
      <div>
        <h2 class="text-lg font-bold">نواقص (بالطرد)</h2>
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
      <div class="text-sm text-text-muted space-y-3 max-w-2xl">
        <p class="font-bold text-slate-800">لا توجد نواقص معروضة الآن</p>
        <p>
          تبويب النواقص لا يعرض كل المواد «الفعّالة»، بل فقط التي
          <strong>المتاح بالطرد ≤ حد الطلب (الأدنى)</strong>.
          إذا المخزون أعلى من 5 طرود (مثلاً 6 أو أكثر) لن تظهر — وهذا سلوك مقصود.
        </p>
        <?php if (is_array($shortagesDiagnostics)): ?>
          <ul class="list-disc mr-5 space-y-1 text-xs">
            <li>مستودعات للحساب: <?= $shortagesDiagnostics['stores_ok'] ? 'مضبوطة ✓' : 'غير محددة — راجع الإعدادات' ?></li>
            <li>مواد «فعال» بحدّ وسقف: <?= h((string) ($shortagesDiagnostics['active_with_limits'] ?? 0)) ?></li>
            <?php if (!empty($shortagesDiagnostics['season_filter_on'])): ?>
              <li>فلتر الموسم: مفعّل<?= ($shortagesDiagnostics['season_name'] ?? '') !== '' ? ' (' . h((string) $shortagesDiagnostics['season_name']) . ')' : '' ?>
                — مستبعدة خارج مجموعات الموسم: <?= h((string) ($shortagesDiagnostics['skipped_season'] ?? 0)) ?></li>
            <?php endif; ?>
            <?php if ((int) ($shortagesDiagnostics['skipped_stock_above_min'] ?? 0) > 0): ?>
              <li>مخزون فوق حد الطلب (لا تُعدّ نقصاً): <?= h((string) $shortagesDiagnostics['skipped_stock_above_min']) ?></li>
            <?php endif; ?>
            <?php if ((int) ($shortagesDiagnostics['skipped_open_line'] ?? 0) > 0): ?>
              <li>لها بند في طلب شراء مفتوح: <?= h((string) $shortagesDiagnostics['skipped_open_line']) ?></li>
            <?php endif; ?>
            <?php if ((int) ($shortagesDiagnostics['skipped_not_in_api'] ?? 0) > 0): ?>
              <li>لم تُجلب من الأمين للمستودعات المختارة: <?= h((string) $shortagesDiagnostics['skipped_not_in_api']) ?>
                <span class="text-text-muted">— غالباً لأن الرصيد 0 فلا تظهر في بحث «المتاح فقط»؛ يُعالَج بعد آخر تحديث للنشر.</span></li>
            <?php endif; ?>
          </ul>
          <?php if (($shortagesDiagnostics['samples_above_min'] ?? []) !== []): ?>
            <p class="text-xs font-bold text-slate-700">أمثلة (متاح &gt; حد الطلب):</p>
            <ul class="text-xs space-y-1">
              <?php foreach ($shortagesDiagnostics['samples_above_min'] as $sample): ?>
                <li>
                  <?= h((string) ($sample['code'] ?? '')) ?>
                  <?= h((string) ($sample['name'] ?? '')) ?>
                  — متاح <?= h((string) ($sample['available_cartons'] ?? '')) ?> طرد،
                  حد الطلب <?= h((string) ($sample['min_cartons'] ?? '')) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
        <p class="text-xs">
          للتجربة: اختر مادة متاحها ≤ 5 طرود، أو ارفع «حد الطلب» فوق المتاح الحالي، ثم حدّث الصفحة.
          تأكد أيضاً من <a class="text-primary font-bold" href="/dashboard/procurement.php?tab=settings">اختيار المستودعات</a>
          وإلغاء فلتر الموسم مؤقتاً إن كانت المادة خارج مجموعة الموسم.
        </p>
      </div>
    <?php else: ?>
      <?php foreach ($shortagesByManufacturer as $manufacturer => $lines): ?>
        <div class="mb-6 last:mb-0">
          <h3 class="font-bold text-primary mb-2 flex items-center gap-2">
            <span class="material-symbols-outlined text-base">factory</span>
            <?= h($manufacturer) ?>
          </h3>
          <div class="proc-table-scroll overflow-x-auto -mx-1 px-1">
            <table class="proc-table proc-table--cards w-full text-sm min-w-[520px] lg:min-w-0">
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
                    <td class="py-2 font-mono text-xs" data-label="كود"><?= h((string) ($line['material_code'] ?? '')) ?></td>
                    <td class="py-2" data-label="الصنف"><?= h((string) ($line['material_name_ar'] ?? '')) ?></td>
                    <td class="py-2" data-label="متاح"><?= h((string) ($line['available_cartons'] ?? '')) ?></td>
                    <td class="py-2" data-label="حد"><?= h((string) ($line['min_cartons'] ?? '')) ?></td>
                    <td class="py-2" data-label="سقف"><?= h((string) ($line['max_cartons'] ?? '')) ?></td>
                    <td class="py-2 font-bold" data-label="مقترح"><?= h((string) ($line['suggested_cartons'] ?? '')) ?></td>
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
</div>
