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
/** @var array<string, mixed>|null $editCustomer */
/** @var array<string, mixed>|null $detailsCustomer */

$statusTabs = [
    'pending' => ['label' => 'بانتظار الموافقة', 'icon' => 'hourglass_top', 'badge' => 'bg-blue-100 text-blue-700', 'ring' => 'ring-blue-200'],
    'active' => ['label' => 'نشطون', 'icon' => 'verified_user', 'badge' => 'bg-green-100 text-green-700', 'ring' => 'ring-green-200'],
    'rejected' => ['label' => 'مرفوضون', 'icon' => 'block', 'badge' => 'bg-red-100 text-red-700', 'ring' => 'ring-red-200'],
    'suspended' => ['label' => 'معلقون', 'icon' => 'pause_circle', 'badge' => 'bg-amber-100 text-amber-700', 'ring' => 'ring-amber-200'],
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
$editStatus = (string) ($editCustomer['status'] ?? 'pending');
$editIsActive = (int) ($editCustomer['is_active'] ?? 0) === 1;
?>
<section class="mb-6">
  <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">إدارة العملاء</h1>
      <p class="text-sm text-text-muted mt-1">متابعة طلبات التسجيل، تفعيل الحسابات، وتعليق الدخول.</p>
    </div>
    <?php if ($canManageCustomers): ?>
      <a href="<?= h($buildCustomerUrl(['status' => $statusFilter, 'q' => $searchFilter, 'source' => $sourceFilter, 'new' => '1'])) ?>" class="h-11 inline-flex items-center gap-2 rounded-xl bg-primary text-white px-5 font-bold hover:brightness-110">
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">person_add</span>
        عميل جديد
      </a>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/partials/flash.php'; ?>

<section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ($statusTabs as $key => $tab): ?>
    <a
      href="<?= h($buildCustomerUrl(['status' => $key, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>"
      class="rounded-2xl border p-4 transition hover:shadow-md <?= $statusFilter === $key ? 'border-primary bg-primary/5 ring-2 ' . $tab['ring'] : 'border-border-subtle bg-white' ?>"
    >
      <div class="flex items-center justify-between gap-2">
        <span class="material-symbols-outlined text-primary" aria-hidden="true"><?= h($tab['icon']) ?></span>
        <span class="text-2xl font-extrabold"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
      </div>
      <p class="text-sm font-bold mt-2"><?= h($tab['label']) ?></p>
    </a>
  <?php endforeach; ?>
</section>

<section class="mb-5 flex flex-col md:flex-row gap-3">
  <form method="get" data-dashboard-filter class="flex-1 grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-2">
    <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
    <input name="q" value="<?= h($searchFilter) ?>" class="h-11 rounded-xl border border-border-subtle px-4 focus:border-primary focus:ring-primary" placeholder="بحث بالاسم، الهاتف، أو البريد...">
    <select name="source" class="h-11 rounded-xl border border-border-subtle px-3 text-sm focus:border-primary focus:ring-primary">
      <option value="">كل المصادر</option>
      <option value="self_register" <?= $sourceFilter === 'self_register' ? 'selected' : '' ?>>تسجيل ذاتي</option>
      <option value="admin_created" <?= $sourceFilter === 'admin_created' ? 'selected' : '' ?>>بواسطة المسؤول</option>
    </select>
    <button class="dashboard-btn h-11 rounded-xl bg-slate-900 text-white px-5 font-bold">بحث</button>
  </form>
</section>

<section class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-5">
  <article class="bg-white rounded-2xl border border-border-subtle shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-border-subtle bg-surface-low/50 flex items-center justify-between">
      <h2 class="font-bold"><?= h($statusTabs[$statusFilter]['label'] ?? 'العملاء') ?></h2>
      <span class="text-xs text-text-muted"><?= count($customers) ?> سجل</span>
    </div>
    <div class="dashboard-table-wrap overflow-x-auto">
      <table class="w-full min-w-[920px] text-sm">
        <thead class="bg-surface-low border-b border-border-subtle text-text-muted text-xs">
          <tr>
            <th class="px-4 py-3 text-right font-bold">العميل</th>
            <th class="px-4 py-3 text-right font-bold">الحالة</th>
            <th class="px-4 py-3 text-right font-bold">الدخول</th>
            <th class="px-4 py-3 text-right font-bold">السياسة</th>
            <th class="px-4 py-3 text-right font-bold">التاريخ</th>
            <th class="px-4 py-3 text-left font-bold">إجراءات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($customers === []): ?>
            <tr><td colspan="6" class="px-5 py-10 text-center text-text-muted">لا توجد سجلات مطابقة.</td></tr>
          <?php endif; ?>
          <?php foreach ($customers as $row): ?>
            <?php
              $rowId = (string) ($row['id'] ?? '');
              $status = (string) ($row['status'] ?? '');
              $isActive = (int) ($row['is_active'] ?? 0) === 1;
              $statusClass = match ($status) {
                  'active' => 'bg-green-100 text-green-700',
                  'rejected' => 'bg-red-100 text-red-700',
                  'suspended' => 'bg-amber-100 text-amber-700',
                  default => 'bg-blue-100 text-blue-700',
              };
              $source = (string) ($row['registration_source'] ?? '');
              $isEditingRow = $editing && $rowId === (string) ($editCustomer['id'] ?? '');
            ?>
            <tr class="<?= $isEditingRow ? 'bg-primary/5' : 'hover:bg-slate-50' ?> transition">
              <td class="px-4 py-3">
                <div class="flex items-center gap-3">
                  <span class="w-10 h-10 rounded-full bg-primary/10 text-primary inline-flex items-center justify-center font-extrabold shrink-0">
                    <?php
                      $initial = trim((string) ($row['name_ar'] ?? '?'));
                      $initial = $initial !== '' ? $initial : '?';
                      echo h(function_exists('mb_substr') ? mb_substr($initial, 0, 1) : substr($initial, 0, 1));
                    ?>
                  </span>
                  <div class="min-w-0">
                    <div class="font-bold truncate"><?= h((string) ($row['name_ar'] ?? '—')) ?></div>
                    <div class="text-xs text-text-muted mt-0.5" dir="ltr"><?= h((string) ($row['phone'] ?? '')) ?></div>
                    <div class="text-[11px] text-text-muted"><?= h($sourceLabels[$source] ?? $source) ?></div>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold <?= $statusClass ?>">
                  <?= h($statusLabels[$status] ?? $status) ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <?php if ($status === 'active'): ?>
                  <span class="inline-flex items-center gap-1 text-xs font-bold <?= $isActive ? 'text-emerald-700' : 'text-slate-500' ?>">
                    <span class="material-symbols-outlined text-base" aria-hidden="true"><?= $isActive ? 'login' : 'logout' ?></span>
                    <?= $isActive ? 'مسموح' : 'موقوف' ?>
                  </span>
                <?php else: ?>
                  <span class="text-xs text-text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-text-muted"><?= h((string) ($row['access_policy_name_ar'] ?? 'غير محددة')) ?></td>
              <td class="px-4 py-3 text-xs text-text-muted whitespace-nowrap"><?= h((string) ($row['created_at'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap items-center justify-end gap-1.5">
                  <button
                    type="button"
                    class="h-8 px-2.5 rounded-lg border border-border-subtle text-xs font-bold hover:bg-surface-low"
                    data-details-btn="<?= h($rowId) ?>"
                  >تفاصيل</button>

                  <?php if ($canManageCustomers): ?>
                    <a
                      href="<?= h($buildCustomerUrl(['status' => $statusFilter, 'q' => $searchFilter, 'source' => $sourceFilter, 'edit' => $rowId])) ?>"
                      class="h-8 px-2.5 inline-flex items-center rounded-lg border border-border-subtle text-xs font-bold hover:bg-surface-low"
                    >تعديل</a>
                  <?php endif; ?>

                  <?php if ($canApproveCustomers && $status === 'pending'): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="inline-flex items-center gap-1">
                      <input type="hidden" name="customer_id" value="<?= h($rowId) ?>">
                      <select name="access_policy_id" class="h-8 rounded-lg border border-border-subtle px-1.5 text-[11px] max-w-[110px]" required>
                        <?php foreach ($policies as $policy): ?>
                          <option value="<?= h((string) $policy['id']) ?>"><?= h((string) $policy['name_ar']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" name="action" value="approve" class="dashboard-btn h-8 px-2 rounded-lg bg-emerald-600 text-white text-[11px] font-bold">موافقة</button>
                      <button type="submit" name="action" value="reject" class="dashboard-btn h-8 px-2 rounded-lg bg-red-600 text-white text-[11px] font-bold">رفض</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($canManageCustomers && in_array($status, ['active', 'pending'], true)): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="inline" onsubmit="return confirm('تعليق هذا الحساب؟ لن يتمكن العميل من الدخول.');">
                      <input type="hidden" name="action" value="suspend">
                      <input type="hidden" name="customer_id" value="<?= h($rowId) ?>">
                      <button type="submit" class="dashboard-btn h-8 px-2.5 rounded-lg bg-amber-500 text-white text-[11px] font-bold hover:bg-amber-600">تعليق</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($canApproveCustomers && in_array($status, ['suspended', 'rejected'], true)): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="inline-flex items-center gap-1">
                      <input type="hidden" name="action" value="reactivate">
                      <input type="hidden" name="customer_id" value="<?= h($rowId) ?>">
                      <select name="access_policy_id" class="h-8 rounded-lg border border-border-subtle px-1.5 text-[11px] max-w-[110px]" required>
                        <?php foreach ($policies as $policy): ?>
                          <option value="<?= h((string) $policy['id']) ?>"><?= h((string) $policy['name_ar']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="dashboard-btn h-8 px-2 rounded-lg bg-primary text-white text-[11px] font-bold">تفعيل</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($canManageCustomers && $status === 'active'): ?>
                    <form method="post" data-dashboard-ajax data-dashboard-reload class="inline">
                      <input type="hidden" name="action" value="toggle_login">
                      <input type="hidden" name="customer_id" value="<?= h($rowId) ?>">
                      <input type="hidden" name="enable_login" value="<?= $isActive ? '0' : '1' ?>">
                      <button type="submit" class="dashboard-btn h-8 px-2.5 rounded-lg border text-[11px] font-bold <?= $isActive ? 'border-slate-300 text-slate-600' : 'border-emerald-300 text-emerald-700' ?>">
                        <?= $isActive ? 'إيقاف الدخول' : 'تفعيل الدخول' ?>
                      </button>
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

  <article class="bg-white border border-border-subtle rounded-2xl shadow-sm overflow-hidden h-fit sticky top-20">
    <div class="px-5 py-4 border-b border-border-subtle bg-surface-low/50">
      <h2 class="font-bold"><?= $editing ? 'تعديل عميل' : ((isset($_GET['new']) ? 'إضافة عميل' : 'نموذج العميل')) ?></h2>
      <?php if ($editing): ?>
        <p class="text-xs text-text-muted mt-1"><?= h((string) ($editCustomer['name_ar'] ?? '')) ?> · <?= h($statusLabels[$editStatus] ?? $editStatus) ?></p>
      <?php endif; ?>
    </div>

    <?php if (!$canManageCustomers): ?>
      <p class="m-5 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 px-4 py-3 text-sm">لا تملك صلاحية إضافة أو تعديل العملاء.</p>
    <?php elseif (!$editing && !isset($_GET['new'])): ?>
      <div class="p-8 text-center text-text-muted text-sm">
        <span class="material-symbols-outlined text-4xl text-slate-300 mb-2" aria-hidden="true">person_search</span>
        <p>اختر عميلاً من الجدول للتعديل، أو أنشئ عميلاً جديداً.</p>
      </div>
    <?php else: ?>
      <form method="post" data-dashboard-ajax data-dashboard-reload class="p-5 space-y-4">
        <input type="hidden" name="action" value="save_customer">
        <input type="hidden" name="customer_id" value="<?= h((string) ($editCustomer['id'] ?? '')) ?>">

        <div class="grid grid-cols-1 gap-3">
          <label class="text-sm">
            <span class="text-text-muted block mb-1 text-xs font-semibold">الاسم *</span>
            <input name="name_ar" required value="<?= h((string) ($editCustomer['name_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1 text-xs font-semibold">الهاتف *</span>
            <input name="phone" required value="<?= h((string) ($editCustomer['phone'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4" dir="ltr">
          </label>
          <label class="text-sm">
            <span class="text-text-muted block mb-1 text-xs font-semibold">البريد</span>
            <input type="email" name="email" value="<?= h((string) ($editCustomer['email'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4" dir="ltr">
          </label>
        </div>

        <div class="rounded-xl border border-border-subtle p-3 space-y-3 bg-surface-low/30">
          <p class="text-xs font-bold text-slate-700">الصلاحيات والحالة</p>
          <label class="text-sm block">
            <span class="text-text-muted block mb-1 text-xs">سياسة الوصول</span>
            <select name="access_policy_id" class="h-11 w-full rounded-xl border border-border-subtle px-3">
              <option value="">غير محددة</option>
              <?php foreach ($policies as $policy): ?>
                <?php $policyId = (string) ($policy['id'] ?? ''); ?>
                <option value="<?= h($policyId) ?>" <?= ((string) ($editCustomer['access_policy_id'] ?? '')) === $policyId ? 'selected' : '' ?>>
                  <?= h((string) ($policy['name_ar'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm block">
            <span class="text-text-muted block mb-1 text-xs">حالة الطلب</span>
            <select name="status" class="h-11 w-full rounded-xl border border-border-subtle px-3">
              <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $editStatus === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input
              type="checkbox"
              name="is_active"
              value="1"
              <?= ($editing ? $editIsActive : true) ? 'checked' : '' ?>
              class="rounded border-border-subtle text-primary"
              <?= $editStatus !== 'active' && $editing ? 'disabled' : '' ?>
            >
            <span>السماح بتسجيل الدخول</span>
          </label>
          <?php if ($editing && $editStatus !== 'active'): ?>
            <p class="text-[11px] text-text-muted">يُفعّل خيار الدخول فقط عندما تكون الحالة «نشط».</p>
          <?php endif; ?>
        </div>

        <label class="text-sm block">
          <span class="text-text-muted block mb-1 text-xs"><?= $editing ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور (اختياري)' ?></span>
          <input type="password" name="plain_password" class="h-11 w-full rounded-xl border border-border-subtle px-4">
        </label>

        <label class="text-sm block">
          <span class="text-text-muted block mb-1 text-xs">سبب الرفض</span>
          <input name="rejection_reason_ar" value="<?= h((string) ($editCustomer['rejection_reason_ar'] ?? '')) ?>" class="h-11 w-full rounded-xl border border-border-subtle px-4">
        </label>

        <label class="text-sm block">
          <span class="text-text-muted block mb-1 text-xs">ملاحظات</span>
          <textarea name="notes_ar" rows="3" class="w-full rounded-xl border border-border-subtle px-4 py-2"><?= h((string) ($editCustomer['notes_ar'] ?? '')) ?></textarea>
        </label>

        <div class="flex flex-wrap gap-2 pt-1">
          <button type="submit" class="dashboard-btn flex-1 min-w-[140px] h-11 rounded-xl bg-primary text-white font-bold hover:brightness-110">
            <?= $editing ? 'حفظ التعديلات' : 'إضافة العميل' ?>
          </button>
          <?php if ($editing): ?>
            <a href="<?= h($buildCustomerUrl(['status' => $statusFilter, 'q' => $searchFilter, 'source' => $sourceFilter])) ?>" class="h-11 px-4 inline-flex items-center rounded-xl border border-border-subtle font-bold text-sm">إلغاء</a>
            <?php if (in_array($editStatus, ['active', 'pending'], true)): ?>
              <button
                type="submit"
                name="action"
                value="suspend"
                class="dashboard-btn h-11 px-4 rounded-xl bg-amber-500 text-white font-bold text-sm"
                onclick="return confirm('تعليق هذا الحساب؟');"
              >تعليق الحساب</button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </article>
</section>

<div id="customerDetailsModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/40 p-4">
  <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-hidden flex flex-col">
    <div class="px-5 py-4 border-b border-border-subtle flex items-center justify-between">
      <h3 class="font-extrabold">تفاصيل العميل</h3>
      <button type="button" id="closeDetailsModal" class="w-9 h-9 rounded-full hover:bg-slate-100 inline-flex items-center justify-center">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>
    <div id="customerDetailsBody" class="p-5 overflow-auto text-sm space-y-3"></div>
  </div>
</div>

<script>
(() => {
  const customers = <?= json_encode($customers, JSON_UNESCAPED_UNICODE) ?>;
  const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
  const sourceLabels = <?= json_encode($sourceLabels, JSON_UNESCAPED_UNICODE) ?>;
  const modal = document.getElementById('customerDetailsModal');
  const body = document.getElementById('customerDetailsBody');
  const closeBtn = document.getElementById('closeDetailsModal');

  function escapeHtml(value) {
    return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
  }

  function openDetails(id) {
    const row = customers.find((item) => String(item.id) === String(id));
    if (!row || !modal || !body) return;
    body.innerHTML = `
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">الاسم</div><div class="font-bold">${escapeHtml(row.name_ar)}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">الهاتف</div><div class="font-bold" dir="ltr">${escapeHtml(row.phone)}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">البريد</div><div class="font-bold">${escapeHtml(row.email || '—')}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">الحالة</div><div class="font-bold">${escapeHtml(statusLabels[row.status] || row.status)}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">الدخول</div><div class="font-bold">${Number(row.is_active) === 1 ? 'مسموح' : 'موقوف'}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">المصدر</div><div class="font-bold">${escapeHtml(sourceLabels[row.registration_source] || row.registration_source)}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">السياسة</div><div class="font-bold">${escapeHtml(row.access_policy_name_ar || 'غير محددة')}</div></div>
      <div class="rounded-xl border p-3"><div class="text-xs text-text-muted">تاريخ الإنشاء</div><div class="font-bold">${escapeHtml(row.created_at)}</div></div>
      ${row.rejection_reason_ar ? `<div class="rounded-xl border border-red-200 bg-red-50 p-3"><div class="text-xs text-red-700">سبب الرفض</div><div class="font-bold text-red-700">${escapeHtml(row.rejection_reason_ar)}</div></div>` : ''}
      ${row.notes_ar ? `<div class="rounded-xl border p-3"><div class="text-xs text-text-muted">ملاحظات</div><div>${escapeHtml(row.notes_ar)}</div></div>` : ''}
    `;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  document.querySelectorAll('[data-details-btn]').forEach((btn) => {
    btn.addEventListener('click', () => openDetails(btn.getAttribute('data-details-btn')));
  });
  closeBtn?.addEventListener('click', () => { modal?.classList.add('hidden'); modal?.classList.remove('flex'); });
  modal?.addEventListener('click', (event) => { if (event.target === modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } });
})();
</script>

<?php if ($detailsCustomer): ?>
<script>document.querySelector('[data-details-btn="<?= h((string) ($detailsCustomer['id'] ?? '')) ?>"]')?.click();</script>
<?php endif; ?>
