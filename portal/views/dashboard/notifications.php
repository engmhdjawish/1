<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */
/** @var list<array<string, mixed>> $sentNotifications */
/** @var list<array<string, mixed>> $customers */
/** @var list<array<string, mixed>> $staffUsers */
/** @var array{total: int, public_count: int, private_count: int, manual_count: int, expired_count: int, active_count: int} $notificationStats */

use Portal\Services\NotificationService;

$notificationStats = $notificationStats ?? [
    'total' => 0,
    'public_count' => 0,
    'private_count' => 0,
    'manual_count' => 0,
    'expired_count' => 0,
    'active_count' => 0,
];
$customerStatusLabels = [
    'active' => 'مفعّل',
    'pending' => 'قيد المراجعة',
    'suspended' => 'موقوف',
];
$iconOptions = [
    'campaign' => 'حملة',
    'notifications' => 'تنبيه',
    'local_offer' => 'عرض',
    'shopping_bag' => 'طلب',
    'verified' => 'تأكيد',
    'info' => 'معلومة',
    'warning' => 'تحذير',
    'celebration' => 'مناسبة',
    'storefront' => 'المتجر',
];
?>
<section class="mb-6">
  <h1 class="text-2xl font-extrabold text-slate-900">الإشعارات</h1>
  <p class="text-sm text-text-muted mt-1 max-w-3xl">
    أرسل إشعاراً عاماً أو خاصاً، وتصل التنبيهات التلقائية عند الطلبات وتعديلاتها، وتسجيل العملاء وتفعيلهم أو إيقافهم، وتغيير كلمة المرور، ونشر العروض.
  </p>
</section>

<section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ([
      ['label' => 'الكل', 'value' => (int) $notificationStats['total']],
      ['label' => 'سارية', 'value' => (int) $notificationStats['active_count']],
      ['label' => 'عامة', 'value' => (int) $notificationStats['public_count']],
      ['label' => 'خاصة', 'value' => (int) $notificationStats['private_count']],
  ] as $stat): ?>
    <article class="rounded-2xl border border-border-subtle bg-white px-4 py-3">
      <p class="text-xs text-text-muted"><?= h($stat['label']) ?></p>
      <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= h((string) $stat['value']) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<?php if (!empty($flash)): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700' ?>">
    <?= h((string) $flash) ?>
  </p>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
  <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">إرسال إشعار جديد</h2>
    </div>
    <form method="post" class="p-4 space-y-4">
      <input type="hidden" name="action" value="send">

      <div class="flex flex-wrap gap-2">
        <label class="inline-flex items-center gap-2 text-sm font-bold">
          <input type="radio" name="scope" value="public" checked data-notification-scope>
          عام
        </label>
        <label class="inline-flex items-center gap-2 text-sm font-bold">
          <input type="radio" name="scope" value="private" data-notification-scope>
          خاص
        </label>
      </div>

      <div data-notification-public-fields>
        <label class="block text-sm font-bold mb-1">الجمهور</label>
        <select name="audience" class="h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
          <option value="<?= h(NotificationService::AUDIENCE_ALL) ?>">الجميع (زوار + عملاء + موظفين)</option>
          <option value="<?= h(NotificationService::AUDIENCE_GUESTS) ?>">الزوار فقط (غير المسجّلين)</option>
          <option value="<?= h(NotificationService::AUDIENCE_CUSTOMERS) ?>">العملاء المسجّلون فقط</option>
          <option value="<?= h(NotificationService::AUDIENCE_STAFF) ?>">الموظفون فقط</option>
        </select>
      </div>

      <div data-notification-private-fields class="hidden space-y-3">
        <label class="block text-sm">
          <span class="font-bold">عميل محدد</span>
          <select name="customer_id" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
            <option value="">—</option>
            <?php foreach ($customers as $customer): ?>
              <?php $customerStatus = (string) ($customer['status'] ?? ''); ?>
              <option value="<?= h((string) ($customer['id'] ?? '')) ?>">
                <?= h((string) ($customer['name_ar'] ?? '')) ?> — <?= h((string) ($customer['phone'] ?? '')) ?>
                <?php if ($customerStatus !== ''): ?>
                  (<?= h($customerStatusLabels[$customerStatus] ?? $customerStatus) ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block text-sm">
          <span class="font-bold">أو موظف محدد</span>
          <select name="staff_id" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
            <option value="">—</option>
            <?php foreach ($staffUsers as $staff): ?>
              <option value="<?= h((string) ($staff['id'] ?? '')) ?>">
                <?= h((string) ($staff['display_name_ar'] ?? '')) ?> (<?= h((string) ($staff['user_name'] ?? '')) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <label class="block text-sm">
        <span class="font-bold">العنوان</span>
        <input type="text" name="title_ar" required maxlength="200" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="مثال: عرض خاص هذا الأسبوع">
      </label>

      <label class="block text-sm">
        <span class="font-bold">النص</span>
        <textarea name="body_ar" required rows="4" class="mt-1 w-full rounded-lg border border-border-subtle px-3 py-2 text-sm" placeholder="تفاصيل الإشعار..."></textarea>
      </label>

      <label class="block text-sm">
        <span class="font-bold">رابط (اختياري)</span>
        <input type="text" name="link_url" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" dir="ltr" placeholder="/store.php أو /dashboard/orders.php">
      </label>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <label class="block text-sm">
          <span class="font-bold">أيقونة</span>
          <select name="icon" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
            <?php foreach ($iconOptions as $iconCode => $iconLabel): ?>
              <option value="<?= h($iconCode) ?>" <?= $iconCode === 'campaign' ? 'selected' : '' ?>><?= h($iconLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block text-sm" data-notification-public-fields>
          <span class="font-bold">ينتهي في (اختياري)</span>
          <input type="datetime-local" name="expires_at" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
        </label>
      </div>

      <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2">
        <span class="material-symbols-outlined text-lg">send</span>
        إرسال الإشعار
      </button>
    </form>
  </section>

  <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 space-y-3">
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-bold">آخر الإشعارات المرسلة</h2>
        <span class="text-xs text-text-muted"><?= h((string) count($sentNotifications)) ?> ظاهر</span>
      </div>
      <input type="search" data-notification-search placeholder="بحث في العنوان أو النص" class="h-10 w-full rounded-lg border border-border-subtle px-3 text-sm">
      <div class="flex flex-wrap gap-2" data-notification-filters>
        <button type="button" class="h-8 px-3 rounded-full border border-primary bg-primary/10 text-primary text-xs font-bold" data-notification-filter="all">الكل</button>
        <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold" data-notification-filter="public">عامة</button>
        <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold" data-notification-filter="private">خاصة</button>
        <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold" data-notification-filter="manual">يدوية</button>
        <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold" data-notification-filter="auto">تلقائية</button>
      </div>
    </div>
    <div class="divide-y divide-border-subtle max-h-[36rem] overflow-y-auto" data-notification-sent-list>
      <?php if ($sentNotifications === []): ?>
        <p class="p-4 text-sm text-text-muted">لا توجد إشعارات بعد.</p>
      <?php endif; ?>
      <?php foreach ($sentNotifications as $row): ?>
        <?php
          $rowSource = (string) ($row['source'] ?? 'manual');
          $rowScope = (string) ($row['scope'] ?? 'public');
          $searchRaw = (string) ($row['title_ar'] ?? '') . ' ' . (string) ($row['body_ar'] ?? '') . ' ' . (string) ($row['customer_name_ar'] ?? '') . ' ' . (string) ($row['staff_name_ar'] ?? '');
          $searchText = function_exists('mb_strtolower') ? mb_strtolower($searchRaw, 'UTF-8') : strtolower($searchRaw);
        ?>
        <article
          class="p-4"
          data-notification-row
          data-scope="<?= h($rowScope) ?>"
          data-source="<?= h($rowSource) ?>"
          data-search="<?= h($searchText) ?>"
        >
          <div class="flex items-start gap-3">
            <span class="w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
              <span class="material-symbols-outlined text-lg"><?= h((string) ($row['icon'] ?? 'notifications')) ?></span>
            </span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-bold text-sm"><?= h((string) ($row['title_ar'] ?? '')) ?></h3>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border border-border-subtle">
                  <?= ($row['scope'] ?? '') === 'private' ? 'خاص' : 'عام' ?>
                </span>
              </div>
              <p class="text-sm text-text-muted mt-1"><?= h((string) ($row['body_ar'] ?? '')) ?></p>
              <p class="text-xs text-text-muted mt-2">
                <?= h((string) ($row['created_at'] ?? '')) ?>
                · <?= h(NotificationService::sourceLabel($rowSource)) ?>
                <?php if (!empty($row['customer_name_ar'])): ?>
                  · عميل: <?= h((string) $row['customer_name_ar']) ?>
                <?php elseif (!empty($row['staff_name_ar'])): ?>
                  · موظف: <?= h((string) $row['staff_name_ar']) ?>
                <?php elseif ($rowScope === 'public'): ?>
                  · <?= h(match ((string) ($row['audience'] ?? '')) {
                      NotificationService::AUDIENCE_GUESTS => 'للزوار فقط',
                      NotificationService::AUDIENCE_CUSTOMERS => 'للعملاء',
                      NotificationService::AUDIENCE_STAFF => 'للموظفين',
                      default => 'للجميع',
                  }) ?>
                <?php endif; ?>
                · قُرئ <?= h((string) ((int) ($row['read_count'] ?? 0))) ?>
                <?php if (!empty($row['expires_at'])): ?>
                  · ينتهي <?= h((string) $row['expires_at']) ?>
                <?php endif; ?>
              </p>
            </div>
            <form method="post" class="shrink-0" onsubmit="return confirm('حذف هذا الإشعار نهائياً من الجميع؟');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= h((string) ($row['id'] ?? '')) ?>">
              <button
                type="submit"
                class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                title="حذف الإشعار"
                aria-label="حذف الإشعار"
              >
                <span class="material-symbols-outlined text-lg">delete</span>
              </button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<script>
(() => {
  const scopeInputs = document.querySelectorAll('[data-notification-scope]');
  const publicFields = document.querySelectorAll('[data-notification-public-fields]');
  const privateFields = document.querySelector('[data-notification-private-fields]');
  const sync = () => {
    const scope = document.querySelector('[data-notification-scope]:checked')?.value || 'public';
    const isPrivate = scope === 'private';
    publicFields.forEach((el) => el.classList.toggle('hidden', isPrivate));
    privateFields?.classList.toggle('hidden', !isPrivate);
  };
  scopeInputs.forEach((input) => input.addEventListener('change', sync));
  sync();

  const customerSelect = document.querySelector('[name="customer_id"]');
  const staffSelect = document.querySelector('[name="staff_id"]');
  customerSelect?.addEventListener('change', () => {
    if (customerSelect.value && staffSelect) staffSelect.value = '';
  });
  staffSelect?.addEventListener('change', () => {
    if (staffSelect.value && customerSelect) customerSelect.value = '';
  });

  const search = document.querySelector('[data-notification-search]');
  const rows = Array.from(document.querySelectorAll('[data-notification-row]'));
  let activeFilter = 'all';
  const applyListFilter = () => {
    const query = (search?.value || '').trim().toLowerCase();
    rows.forEach((row) => {
      const scope = row.getAttribute('data-scope') || '';
      const source = row.getAttribute('data-source') || '';
      const haystack = row.getAttribute('data-search') || '';
      const filterOk = activeFilter === 'all'
        || (activeFilter === 'auto' ? source !== 'manual' : (activeFilter === 'manual' ? source === 'manual' : scope === activeFilter));
      const searchOk = query === '' || haystack.includes(query);
      row.classList.toggle('hidden', !(filterOk && searchOk));
    });
  };
  document.querySelectorAll('[data-notification-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      activeFilter = button.getAttribute('data-notification-filter') || 'all';
      document.querySelectorAll('[data-notification-filter]').forEach((other) => {
        const on = other === button;
        other.classList.toggle('border-primary', on);
        other.classList.toggle('bg-primary/10', on);
        other.classList.toggle('text-primary', on);
        other.classList.toggle('border-border-subtle', !on);
      });
      applyListFilter();
    });
  });
  search?.addEventListener('input', applyListFilter);
})();
</script>
