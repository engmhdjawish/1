<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $customers */
/** @var array{pending: int, active: int, rejected: int, suspended: int} $statusCounts */
/** @var list<array<string, mixed>> $policies */
/** @var string $statusFilter */
/** @var string $searchFilter */
/** @var string $sourceFilter */
/** @var string|null $flash */
/** @var string $flashType */
/** @var bool $canApproveCustomers */
/** @var bool $canManageCustomers */
/** @var bool $canViewAmineCustomers */
/** @var array<string, mixed>|null $editCustomer */
/** @var array<string, mixed>|null $detailsCustomer */
/** @var list<array<string, mixed>> $customerOrders */
/** @var int $customerOrderCount */

use Portal\Services\OrderService;

$statusTabs = [
    'pending' => ['label' => 'بانتظار الموافقة', 'badge' => 'bg-blue-100 text-blue-700'],
    'active' => ['label' => 'العملاء النشطون', 'badge' => 'bg-green-100 text-green-700'],
    'rejected' => ['label' => 'الطلبات المرفوضة', 'badge' => 'bg-red-100 text-red-700'],
    'suspended' => ['label' => 'الحسابات المعلقة', 'badge' => 'bg-amber-100 text-amber-700'],
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

$editing = $editCustomer !== null;
?>
<section class="mb-6">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">عملاء الموقع</h1>
      <p class="text-sm text-text-muted mt-1">
        تسجيلات وعملاء البوابة الإلكترونية
        <?php if (!empty($canViewAmineCustomers)): ?>
          — مختلف عن <a href="/dashboard/accounting-customers.php" class="text-primary font-bold hover:underline">عملاء الأمين</a>.
        <?php else: ?>
          .
        <?php endif; ?>
      </p>
    </div>
    <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-3 w-full md:w-auto">
      <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
      <label class="text-sm">
        <span class="text-text-muted block mb-1">بحث</span>
        <input name="q" value="<?= h($searchFilter) ?>" class="h-11 w-full md:w-72 rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="الاسم، الهاتف، البريد...">
      </label>
      <label class="text-sm">
        <span class="text-text-muted block mb-1">مصدر التسجيل</span>
        <select name="source" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
          <option value="">الكل</option>
          <option value="self_register" <?= $sourceFilter === 'self_register' ? 'selected' : '' ?>>تسجيل ذاتي</option>
          <option value="admin_created" <?= $sourceFilter === 'admin_created' ? 'selected' : '' ?>>بواسطة المسؤول</option>
        </select>
      </label>
      <button class="h-11 mt-0 md:mt-[22px] rounded-xl bg-primary text-white font-bold px-5 hover:brightness-110 transition">تطبيق</button>
    </form>
  </div>
</section>

<?php if ($flash): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700' ?>">
    <?= h($flash) ?>
  </p>
<?php endif; ?>

<section class="mb-6 flex gap-2 overflow-x-auto pb-2">
  <?php foreach ($statusTabs as $key => $tab): ?>
    <a
      href="<?= h($buildCustomerUrl(['status' => $key, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>"
      class="whitespace-nowrap inline-flex items-center gap-2 px-5 py-2.5 rounded-full border transition <?= $statusFilter === $key ? 'bg-primary text-white border-primary shadow-sm' : 'bg-white text-text-muted border-border-subtle hover:bg-surface-low' ?>"
    >
      <span class="font-bold text-sm"><?= h($tab['label']) ?></span>
      <span class="text-xs px-2 py-0.5 rounded-full <?= $statusFilter === $key ? 'bg-white/20 text-white' : $tab['badge'] ?>">
        <?= (int) ($statusCounts[$key] ?? 0) ?>
      </span>
    </a>
  <?php endforeach; ?>
</section>

<section class="grid grid-cols-1 xl:grid-cols-3 gap-5 mb-5">
  <article class="xl:col-span-2 bg-surface-white rounded-2xl border border-border-subtle shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full min-w-[980px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted">
          <tr>
            <th class="px-5 py-4 text-right font-bold">الاسم</th>
            <th class="px-5 py-4 text-right font-bold">الهاتف</th>
            <th class="px-5 py-4 text-right font-bold">الحالة</th>
            <th class="px-5 py-4 text-right font-bold">المصدر</th>
            <th class="px-5 py-4 text-right font-bold">التاريخ</th>
            <th class="px-5 py-4 text-right font-bold">سياسة الوصول</th>
            <th class="px-5 py-4 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($customers === []): ?>
            <tr>
              <td colspan="7" class="px-5 py-8 text-center text-text-muted">لا توجد بيانات مطابقة للحالة المختارة.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($customers as $row): ?>
            <?php
              $status = (string) ($row['status'] ?? '');
              $statusClass = match ($status) {
                  'active' => 'bg-green-100 text-green-700',
                  'rejected' => 'bg-red-100 text-red-700',
                  'suspended' => 'bg-amber-100 text-amber-700',
                  default => 'bg-blue-100 text-blue-700',
              };
              $source = (string) ($row['registration_source'] ?? '');
            ?>
            <tr class="hover:bg-slate-50 transition">
              <td class="px-5 py-4">
                <div class="font-bold text-slate-900"><?= h((string) ($row['name_ar'] ?? '—')) ?></div>
                <div class="text-xs text-text-muted mt-1"><?= h((string) (($row['email'] ?? '') !== '' ? $row['email'] : 'بدون بريد')) ?></div>
              </td>
              <td class="px-5 py-4 text-text-muted"><?= h((string) ($row['phone'] ?? '—')) ?></td>
              <td class="px-5 py-4">
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $statusClass ?>">
                  <?= h($statusLabels[$status] ?? $status) ?>
                </span>
              </td>
              <td class="px-5 py-4 text-text-muted"><?= h($sourceLabels[$source] ?? $source) ?></td>
              <td class="px-5 py-4 text-xs text-text-muted"><?= h((string) ($row['created_at'] ?? '')) ?></td>
              <td class="px-5 py-4 text-text-muted">
                <?= h((string) ($row['access_policy_name_ar'] ?? 'غير محددة')) ?>
              </td>
              <td class="px-5 py-4">
                <div class="flex items-center justify-end gap-2">
                  <a
                    href="<?= h($buildCustomerUrl([
                        'status' => $statusFilter,
                        'q' => $searchFilter,
                        'source' => $sourceFilter,
                        'details' => (string) ($row['id'] ?? ''),
                    ])) ?>"
                    class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle text-xs font-bold text-text-muted hover:bg-surface-low"
                  >
                    التفاصيل
                  </a>
                  <a
                    href="/dashboard/orders.php?web_customer_id=<?= h((string) ($row['id'] ?? '')) ?>"
                    class="h-9 px-3 inline-flex items-center rounded-lg border border-indigo-200 bg-indigo-50 text-xs font-bold text-indigo-800 hover:bg-indigo-100"
                  >
                    الطلبات
                  </a>

                  <?php if ($canManageCustomers): ?>
                    <a
                      href="<?= h($buildCustomerUrl([
                          'status' => $statusFilter,
                          'q' => $searchFilter,
                          'source' => $sourceFilter,
                          'edit' => (string) ($row['id'] ?? ''),
                      ])) ?>"
                      class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle text-xs font-bold text-text-muted hover:bg-surface-low"
                    >
                      تعديل
                    </a>
                  <?php endif; ?>

                  <?php if ($canApproveCustomers && $status === 'pending'): ?>
                    <form method="post" class="flex items-center gap-2">
                      <input type="hidden" name="customer_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                      <select name="access_policy_id" class="h-9 rounded-lg border border-border-subtle px-2 text-xs" required>
                        <?php foreach ($policies as $policy): ?>
                          <option value="<?= h((string) $policy['id']) ?>"><?= h((string) $policy['name_ar']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button name="action" value="approve" class="h-9 px-3 rounded-lg bg-green-600 text-white text-xs font-bold">موافقة</button>
                      <button name="action" value="reject" class="h-9 px-3 rounded-lg bg-red-600 text-white text-xs font-bold">رفض</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <article class="bg-white border border-border-subtle rounded-2xl p-5">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-slate-900"><?= $editing ? 'تعديل عميل' : 'إضافة عميل جديد' ?></h2>
      <?php if ($editing): ?>
        <a href="<?= h($buildCustomerUrl(['status' => $statusFilter, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>" class="text-xs text-primary hover:underline">إلغاء</a>
      <?php endif; ?>
    </div>

    <?php if (!$canManageCustomers): ?>
      <p class="rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-sm">
        لا تملك صلاحية إضافة أو تعديل العملاء.
      </p>
    <?php else: ?>
      <form method="post" class="space-y-3">
        <input type="hidden" name="action" value="save_customer">
        <input type="hidden" name="customer_id" value="<?= h((string) ($editCustomer['id'] ?? '')) ?>">

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">الاسم</span>
          <input name="name_ar" required value="<?= h((string) ($editCustomer['name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">الهاتف</span>
          <input name="phone" required value="<?= h((string) ($editCustomer['phone'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">البريد الإلكتروني</span>
          <input type="email" name="email" value="<?= h((string) ($editCustomer['email'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">سياسة الوصول</span>
          <select name="access_policy_id" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
            <option value="">غير محددة</option>
            <?php foreach ($policies as $policy): ?>
              <?php $policyId = (string) ($policy['id'] ?? ''); ?>
              <option value="<?= h($policyId) ?>" <?= ((string) ($editCustomer['access_policy_id'] ?? '')) === $policyId ? 'selected' : '' ?>>
                <?= h((string) ($policy['name_ar'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">الحالة</span>
          <select name="status" class="h-11 w-full rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary">
            <?php foreach ($statusLabels as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= ((string) ($editCustomer['status'] ?? 'pending')) === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1"><?= $editing ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور (اختياري)' ?></span>
          <input type="password" name="plain_password" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">سبب الرفض (عند الرفض)</span>
          <input name="rejection_reason_ar" value="<?= h((string) ($editCustomer['rejection_reason_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary">
        </label>

        <label class="block text-sm">
          <span class="text-text-muted block mb-1">ملاحظات</span>
          <textarea name="notes_ar" rows="3" class="w-full rounded-xl border border-border-subtle px-4 py-2 focus:border-primary focus:ring-primary"><?= h((string) ($editCustomer['notes_ar'] ?? '')) ?></textarea>
        </label>

        <label class="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" name="is_active" value="1" <?= ((int) ($editCustomer['is_active'] ?? 0) === 1 || !$editing) ? 'checked' : '' ?> class="rounded border-border-subtle text-primary focus:ring-primary">
          <span>الحساب نشط</span>
        </label>

        <button class="w-full h-11 rounded-xl bg-primary text-white font-bold hover:brightness-110 transition">
          <?= $editing ? 'حفظ التعديلات' : 'إضافة العميل' ?>
        </button>
      </form>
    <?php endif; ?>
  </article>
</section>

<?php if ($detailsCustomer): ?>
  <div class="dashboard-slide-panel-backdrop fixed inset-0 bg-slate-900/40 backdrop-blur-sm"></div>
  <aside class="dashboard-slide-panel fixed top-0 left-0 w-full max-w-xl bg-white shadow-2xl overflow-y-auto">
    <div class="sticky top-0 bg-white border-b border-border-subtle px-5 py-4 flex items-center justify-between">
      <div>
        <h2 class="text-lg font-extrabold text-slate-900">تفاصيل العميل</h2>
        <p class="text-xs text-text-muted mt-1"><?= h((string) ($detailsCustomer['name_ar'] ?? '')) ?></p>
      </div>
      <a href="<?= h($buildCustomerUrl(['status' => $statusFilter, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-full hover:bg-surface-low">
        <span class="material-symbols-outlined">close</span>
      </a>
    </div>
    <div class="p-5 space-y-3 text-sm">
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">الهاتف</p>
        <p class="font-bold"><?= h((string) ($detailsCustomer['phone'] ?? '')) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">البريد</p>
        <p class="font-bold"><?= h((string) (($detailsCustomer['email'] ?? '') !== '' ? $detailsCustomer['email'] : 'غير متوفر')) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">الحالة</p>
        <p class="font-bold"><?= h($statusLabels[(string) ($detailsCustomer['status'] ?? 'pending')] ?? (string) ($detailsCustomer['status'] ?? '')) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">سياسة الوصول</p>
        <p class="font-bold"><?= h((string) (($detailsCustomer['access_policy_name_ar'] ?? '') !== '' ? $detailsCustomer['access_policy_name_ar'] : 'غير محددة')) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">مصدر التسجيل</p>
        <p class="font-bold"><?= h($sourceLabels[(string) ($detailsCustomer['registration_source'] ?? '')] ?? (string) ($detailsCustomer['registration_source'] ?? '')) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle p-3">
        <p class="text-text-muted text-xs mb-1">تاريخ الإنشاء</p>
        <p class="font-bold"><?= h((string) ($detailsCustomer['created_at'] ?? '')) ?></p>
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
          <p class="font-bold"><?= h((string) ($detailsCustomer['notes_ar'] ?? '')) ?></p>
        </div>
      <?php endif; ?>

      <div class="rounded-xl border border-border-subtle p-3">
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
                class="block rounded-lg border border-border-subtle px-3 py-2 hover:bg-surface-low"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="font-bold text-primary" dir="ltr"><?= h((string) ($orderRow['order_number'] ?? '')) ?></span>
                  <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-bold">
                    <?= h(OrderService::statusLabel((string) ($orderRow['status'] ?? 'pending'))) ?>
                  </span>
                </div>
                <p class="text-[11px] text-text-muted mt-1"><?= h((string) ($orderRow['created_at'] ?? '')) ?></p>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </aside>
<?php endif; ?>
