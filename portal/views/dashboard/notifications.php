<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */
/** @var list<array<string, mixed>> $sentNotifications */
/** @var list<array<string, mixed>> $customers */
/** @var list<array<string, mixed>> $staffUsers */

use Portal\Services\NotificationService;
?>
<section class="mb-6">
  <h1 class="text-2xl font-extrabold text-slate-900">الإشعارات</h1>
  <p class="text-sm text-text-muted mt-1 max-w-3xl">
    أرسل إشعارات <strong>عامة</strong> (للجميع أو للعملاء أو للموظفين) أو <strong>خاصة</strong> لعميل أو موظف محدد.
    تُرسل تلقائياً أيضاً عند موافقة التسجيل وتحديث حالة الطلب.
  </p>
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
              <option value="<?= h((string) ($customer['id'] ?? '')) ?>">
                <?= h((string) ($customer['name_ar'] ?? '')) ?> — <?= h((string) ($customer['phone'] ?? '')) ?>
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
          <input type="text" name="icon" value="campaign" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="campaign">
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
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60">
      <h2 class="font-bold">آخر الإشعارات المرسلة</h2>
    </div>
    <div class="divide-y divide-border-subtle max-h-[36rem] overflow-y-auto">
      <?php if ($sentNotifications === []): ?>
        <p class="p-4 text-sm text-text-muted">لا توجد إشعارات بعد.</p>
      <?php endif; ?>
      <?php foreach ($sentNotifications as $row): ?>
        <article class="p-4">
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
                <?php if (!empty($row['customer_name_ar'])): ?>
                  · عميل: <?= h((string) $row['customer_name_ar']) ?>
                <?php elseif (!empty($row['staff_name_ar'])): ?>
                  · موظف: <?= h((string) $row['staff_name_ar']) ?>
                <?php elseif (($row['scope'] ?? '') === 'public'): ?>
                  · <?= h(match ((string) ($row['audience'] ?? '')) {
                      NotificationService::AUDIENCE_GUESTS => 'للزوار فقط',
                      NotificationService::AUDIENCE_CUSTOMERS => 'للعملاء',
                      NotificationService::AUDIENCE_STAFF => 'للموظفين',
                      default => 'للجميع',
                  }) ?>
                <?php endif; ?>
              </p>
            </div>
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
})();
</script>
