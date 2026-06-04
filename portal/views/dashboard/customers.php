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
?>
<section class="mb-6">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">إدارة العملاء</h1>
      <p class="text-sm text-text-muted mt-1">إدارة ومراجعة طلبات الانضمام وحسابات العملاء النشطة.</p>
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
      href="?status=<?= h($key) ?>&q=<?= urlencode($searchFilter) ?>&source=<?= urlencode($sourceFilter) ?>"
      class="whitespace-nowrap inline-flex items-center gap-2 px-5 py-2.5 rounded-full border transition <?= $statusFilter === $key ? 'bg-primary text-white border-primary shadow-sm' : 'bg-white text-text-muted border-border-subtle hover:bg-surface-low' ?>"
    >
      <span class="font-bold text-sm"><?= h($tab['label']) ?></span>
      <span class="text-xs px-2 py-0.5 rounded-full <?= $statusFilter === $key ? 'bg-white/20 text-white' : $tab['badge'] ?>">
        <?= (int) ($statusCounts[$key] ?? 0) ?>
      </span>
    </a>
  <?php endforeach; ?>
</section>

<section class="bg-surface-white rounded-2xl border border-border-subtle shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full min-w-[960px] text-sm">
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
              <div class="text-xs text-text-muted mt-1"><?= h((string) ($row['email'] ?? 'بدون بريد')) ?></div>
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
              <?php if ($status === 'pending'): ?>
                <form method="post" class="flex items-center justify-end gap-2">
                  <input type="hidden" name="customer_id" value="<?= h((string) ($row['id'] ?? '')) ?>">
                  <select name="access_policy_id" class="h-9 rounded-lg border border-border-subtle px-2 text-xs" required>
                    <?php foreach ($policies as $policy): ?>
                      <option value="<?= h((string) $policy['id']) ?>"><?= h((string) $policy['name_ar']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button name="action" value="approve" class="h-9 px-3 rounded-lg bg-green-600 text-white text-xs font-bold">موافقة</button>
                  <button name="action" value="reject" class="h-9 px-3 rounded-lg bg-red-600 text-white text-xs font-bold">رفض</button>
                </form>
              <?php else: ?>
                <div class="text-left">
                  <button class="h-9 px-3 rounded-lg border border-border-subtle text-xs text-text-muted bg-white hover:bg-surface-low">التفاصيل</button>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
