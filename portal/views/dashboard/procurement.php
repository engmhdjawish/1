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
/** @var list<array<string, mixed>> $orders */
/** @var string $viewOrderId */
/** @var list<array<string, mixed>> $orderLines */
/** @var list<array<string, mixed>> $storeOptions */
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
  <section class="bg-white border border-border-subtle rounded-xl p-5 mb-4">
    <div class="flex flex-wrap gap-2 mb-4">
      <?php
        $filters = [
            'needs_config' => 'تحتاج ضبط',
            'trial' => 'تجربة',
            'season_active' => 'موسمي مستمر',
            'discontinued' => 'متوقف',
        ];
        foreach ($filters as $key => $label):
      ?>
        <a href="/dashboard/procurement.php?tab=materials&amp;material_filter=<?= h(rawurlencode($key)) ?>"
           class="text-xs font-bold px-3 py-1.5 rounded-full border <?= $materialFilter === $key ? 'bg-primary/10 border-primary text-primary' : 'border-border-subtle text-text-muted' ?>">
          <?= h($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
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
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'orders'): ?>
  <section class="grid lg:grid-cols-2 gap-4">
    <div class="bg-white border border-border-subtle rounded-xl p-5">
      <h2 class="text-lg font-bold mb-3">طلبات مفتوحة</h2>
      <?php if ($orders === []): ?>
        <p class="text-sm text-text-muted">لا مسودات أو طلبات مرسلة. أنشئ من تبويب النواقص.</p>
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
    <div class="bg-white border border-border-subtle rounded-xl p-5">
      <?php if ($viewOrderId === ''): ?>
        <p class="text-sm text-text-muted">اختر طلباً من القائمة لعرض البنود.</p>
      <?php else: ?>
        <h2 class="text-lg font-bold mb-3">بنود الطلب</h2>
        <?php if ($orderLines === []): ?>
          <p class="text-sm text-text-muted">لا بنود.</p>
        <?php else: ?>
          <div class="space-y-3 mb-4">
            <?php foreach ($orderLines as $line): ?>
              <div class="border border-border-subtle rounded-lg p-3 text-sm">
                <div class="font-bold"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
                <div class="text-xs text-text-muted font-mono"><?= h((string) ($line['material_code'] ?? '')) ?></div>
                <div class="mt-2 text-xs">متاح عند الإنشاء: <?= h((string) ($line['available_cartons'] ?? '')) ?> طرد</div>
                <form method="post" class="mt-2 flex flex-wrap items-end gap-2">
                  <input type="hidden" name="action" value="update_line_qty">
                  <input type="hidden" name="line_id" value="<?= h((string) ($line['id'] ?? '')) ?>">
                  <label class="text-xs">طرد معتمد
                    <input type="number" step="0.001" min="0" name="approved_cartons" value="<?= h((string) ($line['approved_cartons'] ?? '')) ?>"
                           class="block h-9 w-24 rounded-lg border border-border-subtle px-2 mt-1">
                  </label>
                  <button type="submit" class="<?= h($btnLight) ?> h-9 text-xs">تحديث</button>
                </form>
                <?php if (($line['line_status'] ?? '') === 'open'): ?>
                  <form method="post" class="mt-2">
                    <input type="hidden" name="action" value="close_line">
                    <input type="hidden" name="line_id" value="<?= h((string) ($line['id'] ?? '')) ?>">
                    <button type="submit" class="text-xs font-bold text-primary">إغلاق السطر (وصل / لا حاجة)</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="flex flex-wrap gap-2">
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
