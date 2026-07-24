<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $customers */
/** @var array{pending: int, active: int, rejected: int, suspended: int} $statusCounts */
/** @var list<array<string, mixed>> $policies */
/** @var bool $hasPolicies */
/** @var int $listLimit */
/** @var string $statusFilter */
/** @var string $searchFilter */
/** @var string $sourceFilter */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canApproveCustomers */
/** @var bool $canManageCustomers */
/** @var bool $canViewAmineCustomers */
/** @var bool $showEditPanel */
/** @var array<string, mixed>|null $editCustomer */
/** @var array<string, mixed>|null $detailsCustomer */
/** @var list<array<string, mixed>> $customerOrders */
/** @var int $customerOrderCount */
/** @var string $orderPriceCurrency */

use Portal\Services\OrderService;

$statusTabs = [
    'pending' => ['label' => 'بانتظار الموافقة', 'badge' => 'bg-blue-100 text-blue-700'],
    'active' => ['label' => 'نشطون', 'badge' => 'bg-green-100 text-green-700'],
    'rejected' => ['label' => 'مرفوضون', 'badge' => 'bg-red-100 text-red-700'],
    'suspended' => ['label' => 'معلقون', 'badge' => 'bg-amber-100 text-amber-700'],
];

$sourceLabels = [
    'self_register' => 'تسجيل ذاتي',
    'admin_created' => 'بواسطة المسؤول',
];

$statusLabels = [
    'pending' => 'بانتظار الموافقة',
    'active' => 'نشط',
    'rejected' => 'مرفوض',
    'suspended' => 'معلق',
];

$buildCustomerUrl = static function (array $params): string {
    return '/dashboard/customers.php?' . http_build_query(array_filter(
        $params,
        static fn ($value) => $value !== null && $value !== ''
    ));
};

$listBaseParams = [
    'status' => $statusFilter,
    'q' => $searchFilter,
    'source' => $sourceFilter,
];

$formatTimestamp = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (\Throwable) {
        return $value;
    }
};

$formatUsd = static function (float $amount): string {
    return '$' . number_format($amount, 2, '.', ',');
};

$formatOrderTotal = static function (array $order) use ($orderPriceCurrency, $formatUsd): string {
    if ($orderPriceCurrency === 'syp') {
        return number_format((float) ($order['total_sp'] ?? 0), 0, '.', ',') . ' ل.س';
    }

    return $formatUsd((float) ($order['total_usd'] ?? 0));
};

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'active' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
        'suspended' => 'bg-amber-100 text-amber-700',
        default => 'bg-blue-100 text-blue-700',
    };
};

$editingExisting = $showEditPanel && trim((string) ($editCustomer['id'] ?? '')) !== '';
$creatingNew = $showEditPanel && !$editingExisting;
$shownCount = count($customers);
$activeTabCount = (int) ($statusCounts[$statusFilter] ?? 0);
$hitListLimit = $shownCount >= $listLimit && $activeTabCount > $shownCount;
?>
<section class="mb-4">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">عملاء الموقع</h1>
      <p class="text-sm text-text-muted mt-1 max-w-3xl">
        تسجيلات وعملاء البوابة الإلكترونية
        <?php if (!empty($canViewAmineCustomers)): ?>
          — مختلف عن <a href="/dashboard/accounting-customers.php" class="text-primary font-bold hover:underline">عملاء الأمين</a>.
        <?php else: ?>
          .
        <?php endif; ?>
      </p>
    </div>
    <?php if ($canManageCustomers): ?>
      <a
        href="<?= h($buildCustomerUrl(array_merge($listBaseParams, ['edit' => 'new']))) ?>"
        class="inline-flex items-center justify-center gap-1.5 h-10 px-4 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110 transition shrink-0"
      >
        <span class="material-symbols-outlined text-lg">person_add</span>
        إضافة عميل
      </a>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<?php if ($canApproveCustomers && !$hasPolicies): ?>
  <section class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    لا توجد سياسات وصول نشطة. أنشئ سياسة من
    <a href="/dashboard/settings.php" class="font-bold text-primary hover:underline">الإعدادات</a>
    قبل تفعيل العملاء.
  </section>
<?php endif; ?>

<section class="bg-white border border-border-subtle rounded-xl p-3 mb-4 space-y-3">
  <form method="get" data-dashboard-filter class="grid grid-cols-1 md:grid-cols-[1fr_auto_auto] gap-2 md:items-end">
    <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
    <label class="text-sm">
      <span class="text-text-muted block mb-1 text-xs">بحث</span>
      <input
        name="q"
        value="<?= h($searchFilter) ?>"
        class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary"
        placeholder="الاسم، الهاتف، البريد..."
      >
    </label>
    <label class="text-sm">
      <span class="text-text-muted block mb-1 text-xs">مصدر التسجيل</span>
      <select name="source" class="h-10 w-full md:w-44 rounded-xl border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary">
        <option value="">الكل</option>
        <option value="self_register" <?= $sourceFilter === 'self_register' ? 'selected' : '' ?>>تسجيل ذاتي</option>
        <option value="admin_created" <?= $sourceFilter === 'admin_created' ? 'selected' : '' ?>>بواسطة المسؤول</option>
      </select>
    </label>
    <button class="h-10 px-5 rounded-xl bg-primary text-white text-sm font-bold hover:brightness-110 transition">تطبيق</button>
  </form>

  <nav class="dash-cust-tabs" aria-label="حالة العملاء">
    <?php foreach ($statusTabs as $key => $tab): ?>
      <a
        href="<?= h($buildCustomerUrl(['status' => $key, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>"
        class="dash-cust-tab<?= $statusFilter === $key ? ' is-active' : '' ?>"
      >
        <span><?= h($tab['label']) ?></span>
        <span class="dash-cust-tab__count"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="bg-white border border-border-subtle rounded-2xl shadow-sm overflow-hidden mb-5">
  <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex flex-wrap items-center justify-between gap-2">
    <h2 class="text-sm font-extrabold text-slate-900"><?= h($statusTabs[$statusFilter]['label'] ?? 'العملاء') ?></h2>
    <p class="text-xs text-text-muted">
      <?= $shownCount ?> <?= $hitListLimit ? 'من ' . $activeTabCount . ' (حد العرض ' . $listLimit . ')' : 'عميل' ?>
    </p>
  </div>

  <?php if ($customers === []): ?>
    <p class="px-4 py-10 text-center text-sm text-text-muted">لا توجد بيانات مطابقة للفلاتر الحالية.</p>
  <?php else: ?>
    <div class="dash-cust-cards divide-y divide-border-subtle">
      <?php foreach ($customers as $row): ?>
        <?php
          $rowId = (string) ($row['id'] ?? '');
          $status = (string) ($row['status'] ?? '');
          $source = (string) ($row['registration_source'] ?? '');
          $detailsUrl = $buildCustomerUrl(array_merge($listBaseParams, ['details' => $rowId]));
        ?>
        <article class="dash-cust-card p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="font-bold text-slate-900 truncate"><?= h((string) ($row['name_ar'] ?? '—')) ?></p>
              <p class="text-xs text-text-muted mt-0.5" dir="ltr"><?= h((string) ($row['phone'] ?? '—')) ?></p>
              <p class="text-xs text-text-muted truncate"><?= h((string) (($row['email'] ?? '') !== '' ? $row['email'] : 'بدون بريد')) ?></p>
            </div>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold shrink-0 <?= $statusBadgeClass($status) ?>">
              <?= h($statusLabels[$status] ?? $status) ?>
            </span>
          </div>
          <dl class="dash-cust-card__meta">
            <div><dt>المصدر</dt><dd><?= h($sourceLabels[$source] ?? $source) ?></dd></div>
            <div><dt>التاريخ</dt><dd><?= h($formatTimestamp((string) ($row['created_at'] ?? ''))) ?></dd></div>
            <div><dt>السياسة</dt><dd><?= h((string) ($row['access_policy_name_ar'] ?? 'غير محددة')) ?></dd></div>
          </dl>
          <div class="dash-cust-actions">
            <a href="<?= h($detailsUrl) ?>" class="dash-cust-action">التفاصيل</a>
            <a href="/dashboard/orders.php?web_customer_id=<?= h($rowId) ?>" class="dash-cust-action">الطلبات</a>
            <?php if ($canManageCustomers): ?>
              <a href="<?= h($buildCustomerUrl(array_merge($listBaseParams, ['edit' => $rowId]))) ?>" class="dash-cust-action">تعديل</a>
            <?php endif; ?>
            <?php if ($canApproveCustomers && $status === 'pending'): ?>
              <a href="<?= h($detailsUrl) ?>" class="dash-cust-action dash-cust-action--primary">مراجعة</a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="dash-cust-table-wrap overflow-x-auto">
      <table class="dash-cust-table w-full text-sm min-w-[760px]">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">العميل</th>
            <th class="px-4 py-3 text-right font-bold">الهاتف</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-right font-bold">المصدر</th>
            <th class="px-4 py-3 text-right font-bold">التاريخ</th>
            <th class="px-4 py-3 text-right font-bold">السياسة</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php foreach ($customers as $row): ?>
            <?php
              $rowId = (string) ($row['id'] ?? '');
              $status = (string) ($row['status'] ?? '');
              $source = (string) ($row['registration_source'] ?? '');
              $detailsUrl = $buildCustomerUrl(array_merge($listBaseParams, ['details' => $rowId]));
            ?>
            <tr class="hover:bg-slate-50/80 transition">
              <td class="px-4 py-3">
                <div class="font-bold text-slate-900"><?= h((string) ($row['name_ar'] ?? '—')) ?></div>
                <div class="text-xs text-text-muted mt-0.5 truncate max-w-[14rem]"><?= h((string) (($row['email'] ?? '') !== '' ? $row['email'] : 'بدون بريد')) ?></div>
              </td>
              <td class="px-4 py-3 text-text-muted" dir="ltr"><?= h((string) ($row['phone'] ?? '—')) ?></td>
              <td class="px-4 py-3">
                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $statusBadgeClass($status) ?>">
                  <?= h($statusLabels[$status] ?? $status) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-text-muted text-xs"><?= h($sourceLabels[$source] ?? $source) ?></td>
              <td class="px-4 py-3 text-xs text-text-muted whitespace-nowrap"><?= h($formatTimestamp((string) ($row['created_at'] ?? ''))) ?></td>
              <td class="px-4 py-3 text-xs text-text-muted max-w-[10rem] truncate"><?= h((string) ($row['access_policy_name_ar'] ?? 'غير محددة')) ?></td>
              <td class="px-4 py-3">
                <div class="dash-cust-actions dash-cust-actions--table">
                  <?php if ($canApproveCustomers && $status === 'pending'): ?>
                    <a href="<?= h($detailsUrl) ?>" class="dash-cust-action dash-cust-action--primary">مراجعة</a>
                  <?php else: ?>
                    <a href="<?= h($detailsUrl) ?>" class="dash-cust-action">التفاصيل</a>
                  <?php endif; ?>
                  <a href="/dashboard/orders.php?web_customer_id=<?= h($rowId) ?>" class="dash-cust-action">الطلبات</a>
                  <?php if ($canManageCustomers): ?>
                    <a href="<?= h($buildCustomerUrl(array_merge($listBaseParams, ['edit' => $rowId]))) ?>" class="dash-cust-action">تعديل</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($detailsCustomer): ?>
  <?php
    $detailStatus = (string) ($detailsCustomer['status'] ?? 'pending');
    $detailSource = (string) ($detailsCustomer['registration_source'] ?? '');
    $closeUrl = $buildCustomerUrl($listBaseParams);
  ?>
  <a href="<?= h($closeUrl) ?>" class="dashboard-slide-panel-backdrop fixed inset-0 bg-slate-900/40" aria-label="إغلاق"></a>
  <aside class="dashboard-slide-panel fixed top-0 left-0 w-full max-w-2xl bg-white shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="customer-details-title">
    <header class="shrink-0 border-b border-border-subtle px-4 py-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-[11px] text-text-muted">تفاصيل العميل</p>
          <h2 id="customer-details-title" class="text-lg font-extrabold text-slate-900 truncate"><?= h((string) ($detailsCustomer['name_ar'] ?? '')) ?></h2>
          <p class="text-xs text-text-muted mt-0.5" dir="ltr"><?= h((string) ($detailsCustomer['phone'] ?? '')) ?></p>
        </div>
        <a href="<?= h($closeUrl) ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-border-subtle hover:bg-surface-low shrink-0" aria-label="إغلاق">
          <span class="material-symbols-outlined">close</span>
        </a>
      </div>
      <div class="flex flex-wrap gap-1.5 mt-2 items-center">
        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold <?= $statusBadgeClass($detailStatus) ?>"><?= h($statusLabels[$detailStatus] ?? $detailStatus) ?></span>
        <span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700"><?= h($sourceLabels[$detailSource] ?? $detailSource) ?></span>
        <?php if ($canManageCustomers): ?>
          <a
            href="<?= h($buildCustomerUrl(array_merge($listBaseParams, ['edit' => (string) ($detailsCustomer['id'] ?? '')]))) ?>"
            class="inline-flex items-center gap-1 h-8 px-3 rounded-lg border border-border-subtle bg-white text-[11px] font-bold text-slate-700 hover:bg-surface-low"
          >
            <span class="material-symbols-outlined text-sm">edit</span>
            تعديل
          </a>
        <?php endif; ?>
      </div>
    </header>

    <div class="flex-1 overflow-y-auto p-4 space-y-3 text-sm">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs mb-1">البريد</p>
          <p class="font-bold break-all"><?= h((string) (($detailsCustomer['email'] ?? '') !== '' ? $detailsCustomer['email'] : 'غير متوفر')) ?></p>
        </div>
        <div class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs mb-1">سياسة الوصول</p>
          <p class="font-bold"><?= h((string) (($detailsCustomer['access_policy_name_ar'] ?? '') !== '' ? $detailsCustomer['access_policy_name_ar'] : 'غير محددة')) ?></p>
        </div>
        <div class="rounded-xl border border-border-subtle p-3 sm:col-span-2">
          <p class="text-text-muted text-xs mb-1">تاريخ التسجيل</p>
          <p class="font-bold"><?= h($formatTimestamp((string) ($detailsCustomer['created_at'] ?? ''))) ?></p>
        </div>
      </div>

      <?php if ((string) ($detailsCustomer['rejection_reason_ar'] ?? '') !== ''): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3">
          <p class="text-red-700 text-xs mb-1">سبب الرفض</p>
          <p class="font-bold text-red-700"><?= h((string) ($detailsCustomer['rejection_reason_ar'] ?? '')) ?></p>
        </div>
      <?php endif; ?>
      <?php if ((string) ($detailsCustomer['notes_ar'] ?? '') !== ''): ?>
        <div class="rounded-xl border border-border-subtle p-3">
          <p class="text-text-muted text-xs mb-1">ملاحظات</p>
          <p class="font-bold whitespace-pre-wrap"><?= h((string) ($detailsCustomer['notes_ar'] ?? '')) ?></p>
        </div>
      <?php endif; ?>

      <section class="rounded-xl border border-border-subtle p-3">
        <div class="flex items-center justify-between gap-2 mb-3">
          <div>
            <p class="text-text-muted text-xs mb-1">طلبات الموقع</p>
            <p class="font-bold"><?= (int) $customerOrderCount ?> طلب</p>
          </div>
          <?php if ($customerOrderCount > 0): ?>
            <a
              href="/dashboard/orders.php?web_customer_id=<?= h((string) ($detailsCustomer['id'] ?? '')) ?>"
              class="h-9 px-3 inline-flex items-center rounded-lg bg-primary text-white text-xs font-bold hover:brightness-110"
            >
              فتح كل الطلبات
            </a>
          <?php endif; ?>
        </div>
        <?php if ($customerOrders === []): ?>
          <p class="text-xs text-text-muted">لا توجد طلبات مرتبطة بهذا الحساب بعد.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($customerOrders as $orderRow): ?>
              <a
                href="/dashboard/orders.php?web_customer_id=<?= h((string) ($detailsCustomer['id'] ?? '')) ?>&details=<?= h((string) ($orderRow['id'] ?? '')) ?>"
                class="block rounded-lg border border-border-subtle px-3 py-2.5 hover:bg-surface-low"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="font-bold text-primary" dir="ltr"><?= h((string) ($orderRow['order_number'] ?? '')) ?></span>
                  <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-bold">
                    <?= h(OrderService::statusLabel((string) ($orderRow['status'] ?? 'pending'))) ?>
                  </span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-1.5">
                  <p class="text-[11px] text-text-muted"><?= h($formatTimestamp((string) ($orderRow['created_at'] ?? ''))) ?></p>
                  <p class="text-sm font-extrabold text-emerald-700 tabular-nums" dir="ltr"><?= h($formatOrderTotal($orderRow)) ?></p>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($canApproveCustomers && $detailStatus === 'pending'): ?>
        <section class="rounded-xl border border-border-subtle p-3 space-y-3" data-customers-review-panel>
          <p class="text-xs font-bold text-slate-700">موافقة أو رفض التسجيل</p>
          <?php if (!$hasPolicies): ?>
            <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">لا يمكن الموافقة قبل إنشاء سياسة وصول نشطة.</p>
          <?php else: ?>
            <form method="post" class="space-y-2">
              <input type="hidden" name="customer_id" value="<?= h((string) ($detailsCustomer['id'] ?? '')) ?>">
              <label class="block text-xs">
                <span class="text-text-muted block mb-1">سياسة الوصول</span>
                <select name="access_policy_id" class="h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" required>
                  <?php foreach ($policies as $policy): ?>
                    <option value="<?= h((string) $policy['id']) ?>"><?= h((string) $policy['name_ar']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button name="action" value="approve" class="h-10 w-full rounded-lg bg-green-600 text-white text-sm font-bold">موافقة وتفعيل</button>
            </form>
          <?php endif; ?>
          <form method="post" class="space-y-2 border-t border-border-subtle pt-3" onsubmit="return confirm('رفض هذا التسجيل؟');">
            <input type="hidden" name="customer_id" value="<?= h((string) ($detailsCustomer['id'] ?? '')) ?>">
            <label class="block text-xs">
              <span class="text-text-muted block mb-1">سبب الرفض (اختياري)</span>
              <textarea name="reject_reason" rows="2" class="w-full rounded-lg border border-border-subtle px-3 py-2 text-sm" placeholder="مثال: بيانات غير مكتملة"></textarea>
            </label>
            <button name="action" value="reject" class="h-10 w-full rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm font-bold">رفض التسجيل</button>
          </form>
        </section>
      <?php elseif ($canApproveCustomers && in_array($detailStatus, ['suspended', 'rejected'], true)): ?>
        <section class="rounded-xl border border-border-subtle p-3">
          <p class="text-xs font-bold text-slate-700 mb-2">إعادة تفعيل الحساب</p>
          <?php if (!$hasPolicies): ?>
            <p class="text-xs text-amber-800">أنشئ سياسة وصول من الإعدادات أولاً.</p>
          <?php else: ?>
            <form method="post" class="space-y-2">
              <input type="hidden" name="customer_id" value="<?= h((string) ($detailsCustomer['id'] ?? '')) ?>">
              <label class="block text-xs">
                <span class="text-text-muted block mb-1">سياسة الوصول</span>
                <select name="access_policy_id" class="h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" required>
                  <?php foreach ($policies as $policy): ?>
                    <option value="<?= h((string) $policy['id']) ?>" <?= ((string) ($detailsCustomer['access_policy_id'] ?? '')) === (string) ($policy['id'] ?? '') ? 'selected' : '' ?>>
                      <?= h((string) $policy['name_ar']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button name="action" value="reactivate" class="h-10 w-full rounded-lg bg-green-600 text-white text-sm font-bold">تفعيل</button>
            </form>
          <?php endif; ?>
        </section>
      <?php elseif ($canManageCustomers && $detailStatus === 'active'): ?>
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-3">
          <form method="post" onsubmit="return confirm('تعليق هذا الحساب وإنهاء جلساته؟');">
            <input type="hidden" name="customer_id" value="<?= h((string) ($detailsCustomer['id'] ?? '')) ?>">
            <button name="action" value="suspend" class="h-10 w-full rounded-lg bg-amber-600 text-white text-sm font-bold">تعليق الحساب</button>
          </form>
        </section>
      <?php endif; ?>
    </div>
  </aside>
<?php endif; ?>

<?php if ($showEditPanel && $editCustomer !== null): ?>
  <?php
    $closeUrl = $buildCustomerUrl($listBaseParams);
    $editTitle = $creatingNew ? 'إضافة عميل جديد' : 'تعديل عميل';
  ?>
  <a href="<?= h($closeUrl) ?>" class="dashboard-slide-panel-backdrop fixed inset-0 bg-slate-900/40" aria-label="إغلاق"></a>
  <aside class="dashboard-slide-panel fixed top-0 left-0 w-full max-w-xl bg-white shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="customer-edit-title">
    <header class="shrink-0 border-b border-border-subtle px-4 py-3 flex items-center justify-between gap-3">
      <div>
        <p class="text-[11px] text-text-muted">إدارة العميل</p>
        <h2 id="customer-edit-title" class="text-lg font-extrabold text-slate-900"><?= h($editTitle) ?></h2>
      </div>
      <a href="<?= h($closeUrl) ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-border-subtle hover:bg-surface-low shrink-0" aria-label="إغلاق">
        <span class="material-symbols-outlined">close</span>
      </a>
    </header>

    <form method="post" class="flex flex-col flex-1 min-h-0">
      <input type="hidden" name="action" value="save_customer">
      <input type="hidden" name="customer_id" value="<?= h((string) ($editCustomer['id'] ?? '')) ?>">

      <div class="flex-1 overflow-y-auto p-4 space-y-3">
        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">الاسم</span>
          <input name="name_ar" required value="<?= h((string) ($editCustomer['name_ar'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">الهاتف</span>
          <input name="phone" required <?= portal_phone_input_attributes() ?> value="<?= h((string) ($editCustomer['phone'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary text-left" placeholder="09xxxxxxxx">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">البريد الإلكتروني</span>
          <input type="email" name="email" value="<?= h((string) ($editCustomer['email'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">سياسة الوصول</span>
          <select name="access_policy_id" class="h-10 w-full rounded-xl border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary">
            <option value="">غير محددة</option>
            <?php foreach ($policies as $policy): ?>
              <?php $policyId = (string) ($policy['id'] ?? ''); ?>
              <option value="<?= h($policyId) ?>" <?= ((string) ($editCustomer['access_policy_id'] ?? '')) === $policyId ? 'selected' : '' ?>>
                <?= h((string) ($policy['name_ar'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="text-[11px] text-text-muted mt-1">مطلوبة عند اختيار حالة «نشط».</p>
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">الحالة</span>
          <select name="status" class="h-10 w-full rounded-xl border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary">
            <?php foreach ($statusLabels as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= ((string) ($editCustomer['status'] ?? 'pending')) === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs"><?= $editingExisting ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور (اختياري)' ?></span>
          <input type="password" name="plain_password" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">سبب الرفض (عند الرفض)</span>
          <input name="rejection_reason_ar" value="<?= h((string) ($editCustomer['rejection_reason_ar'] ?? '')) ?>" class="h-10 w-full rounded-xl border border-border-subtle px-4 text-sm focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1 text-xs">ملاحظات</span>
          <textarea name="notes_ar" rows="3" class="w-full rounded-xl border border-border-subtle px-4 py-2 text-sm focus:border-primary focus:ring-primary"><?= h((string) ($editCustomer['notes_ar'] ?? '')) ?></textarea>
        </label>
      </div>

      <footer class="shrink-0 border-t border-border-subtle p-4">
        <button type="submit" class="w-full h-11 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editingExisting ? 'حفظ التعديلات' : 'إضافة العميل' ?>
        </button>
      </footer>
    </form>
  </aside>
<?php endif; ?>
