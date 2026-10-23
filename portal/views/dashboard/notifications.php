<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */
/** @var list<array<string, mixed>> $sentNotifications */
/** @var list<array<string, mixed>> $customers */
/** @var list<array<string, mixed>> $staffUsers */
/** @var array{total: int, public_count: int, private_count: int, manual_count: int, expired_count: int, active_count: int} $notificationStats */
/** @var string $historyKind */
/** @var string $historyScope */
/** @var string $historyQuery */
/** @var array{total: int, page: int, pages: int, per_page: int} $historyPaging */

use Portal\Services\NotificationService;

$notificationStats = $notificationStats ?? [
    'total' => 0,
    'public_count' => 0,
    'private_count' => 0,
    'manual_count' => 0,
    'expired_count' => 0,
    'active_count' => 0,
];
$formatWhen = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y/m/d H:i');
    } catch (Throwable) {
        return (string) $value;
    }
};
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
    الحملات هي ما ترسله أنت. التنبيهات التلقائية (الطلبات، الحسابات، العروض) تصل وحدها وتجدها في تبويب «تلقائية».
  </p>
</section>

<section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php foreach ([
      ['label' => 'حملات أرسلتها', 'value' => (int) $notificationStats['manual_count']],
      ['label' => 'تنبيهات تلقائية', 'value' => max(0, (int) $notificationStats['total'] - (int) $notificationStats['manual_count'])],
      ['label' => 'سارية الآن', 'value' => (int) $notificationStats['active_count']],
      ['label' => 'منتهية', 'value' => (int) $notificationStats['expired_count']],
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
    <form method="post" class="p-4 space-y-4" data-notification-compose>
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

      <div class="flex flex-wrap items-center gap-2" data-notification-templates>
        <span class="text-xs text-text-muted">قوالب:</span>
        <?php foreach ([
            ['label' => 'بضاعة جديدة', 'icon' => 'storefront', 'title' => 'وصلت بضاعة جديدة', 'body' => 'تشكيلة جديدة متوفرة الآن بأسعار الجملة. تصفّح واطلب قبل نفاد الكمية.', 'link' => '/store.php'],
            ['label' => 'عرض', 'icon' => 'local_offer', 'title' => 'عرض خاص لفترة محدودة', 'body' => 'خصم على تشكيلة مختارة عند الطلب بالطرد. العرض ساري حتى نفاد الكمية.', 'link' => '/store.php'],
            ['label' => 'تحديث أسعار', 'icon' => 'info', 'title' => 'تحديث الأسعار', 'body' => 'تم تحديث أسعار بعض الأصناف. راجع الأسعار الحالية قبل إرسال طلبك.', 'link' => '/store.php'],
            ['label' => 'عطلة', 'icon' => 'celebration', 'title' => 'تنويه بموعد العطلة', 'body' => 'نستقبل الطلبات عبر الموقع، ويبدأ التجهيز بعد انتهاء العطلة.', 'link' => ''],
        ] as $template): ?>
          <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold hover:bg-slate-50"
            data-template-title="<?= h($template['title']) ?>" data-template-body="<?= h($template['body']) ?>"
            data-template-icon="<?= h($template['icon']) ?>" data-template-link="<?= h($template['link']) ?>"><?= h($template['label']) ?></button>
        <?php endforeach; ?>
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

      <div>
        <p class="text-xs text-text-muted mb-1">هكذا يظهر عند العميل:</p>
        <div class="flex items-start gap-3 rounded-xl border border-border-subtle bg-white p-3 max-w-md" data-notification-preview>
          <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined" data-preview-icon>campaign</span>
          </span>
          <span class="min-w-0 grid gap-0.5">
            <strong class="text-sm text-slate-900" data-preview-title>عنوان الإشعار</strong>
            <span class="text-sm text-text-muted" data-preview-body>نص الإشعار</span>
            <span class="text-xs text-slate-400">الآن</span>
          </span>
        </div>
      </div>

      <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center gap-2" data-notification-send>
        <span class="material-symbols-outlined text-lg">send</span>
        إرسال الإشعار
      </button>
    </form>
  </section>

  <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden">
    <?php
      $historyUrl = static function (array $override) use ($historyKind, $historyScope, $historyQuery): string {
          $query = array_merge(['kind' => $historyKind, 'scope' => $historyScope, 'q' => $historyQuery, 'page' => ''], $override);
          $query = array_filter($query, static fn ($v): bool => (string) $v !== '' && !($v === 'manual'));

          return '/dashboard/notifications.php' . ($query !== [] ? '?' . http_build_query($query) : '');
      };
      $backQuery = (string) (parse_url($historyUrl(['page' => $historyPaging['page'] > 1 ? (string) $historyPaging['page'] : '']), PHP_URL_QUERY) ?? '');
      $tabClass = static fn (bool $on): string => 'h-8 px-3 inline-flex items-center rounded-full border text-xs font-bold ' . ($on ? 'border-primary bg-primary/10 text-primary' : 'border-border-subtle');
    ?>
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 space-y-3">
      <div class="flex items-center justify-between gap-3">
        <h2 class="font-bold">السجل</h2>
        <span class="text-xs text-text-muted"><?= (int) $historyPaging['total'] ?> إشعار</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= h($historyUrl(['kind' => 'manual', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'manual') ?>">حملاتي</a>
        <a href="<?= h($historyUrl(['kind' => 'auto', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'auto') ?>">تلقائية</a>
        <a href="<?= h($historyUrl(['kind' => 'all', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'all') ?>">الكل</a>
        <span class="w-px bg-border-subtle mx-1"></span>
        <a href="<?= h($historyUrl(['scope' => '', 'page' => ''])) ?>" class="<?= $tabClass($historyScope === '') ?>">عامة وخاصة</a>
        <a href="<?= h($historyUrl(['scope' => 'public', 'page' => ''])) ?>" class="<?= $tabClass($historyScope === 'public') ?>">عامة</a>
        <a href="<?= h($historyUrl(['scope' => 'private', 'page' => ''])) ?>" class="<?= $tabClass($historyScope === 'private') ?>">خاصة</a>
      </div>
      <form method="get" class="flex gap-2">
        <input type="hidden" name="kind" value="<?= h($historyKind) ?>">
        <?php if ($historyScope !== ''): ?><input type="hidden" name="scope" value="<?= h($historyScope) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($historyQuery) ?>" placeholder="بحث في العنوان أو النص أو اسم العميل" class="h-10 flex-1 rounded-lg border border-border-subtle px-3 text-sm">
        <button type="submit" class="h-10 px-4 rounded-lg border border-border-subtle bg-white text-sm font-bold">بحث</button>
      </form>
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
                <?= h($formatWhen((string) ($row['created_at'] ?? ''))) ?>
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
                  · ينتهي <?= h($formatWhen((string) $row['expires_at'])) ?>
                <?php endif; ?>
              </p>
            </div>
            <form method="post" class="shrink-0" onsubmit="return confirm('حذف هذا الإشعار نهائياً من الجميع؟');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="back" value="<?= h($backQuery !== '' ? '?' . $backQuery : '') ?>">
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
    <?php if ($historyPaging['pages'] > 1): ?>
      <nav class="flex items-center justify-between gap-2 px-4 py-3 border-t border-border-subtle text-sm" aria-label="صفحات السجل">
        <?php if ($historyPaging['page'] > 1): ?>
          <a class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle font-bold" href="<?= h($historyUrl(['page' => (string) ($historyPaging['page'] - 1)])) ?>">الأحدث</a>
        <?php else: ?><span></span><?php endif; ?>
        <span class="text-xs text-text-muted">صفحة <?= (int) $historyPaging['page'] ?> من <?= (int) $historyPaging['pages'] ?></span>
        <?php if ($historyPaging['page'] < $historyPaging['pages']): ?>
          <a class="h-9 px-3 inline-flex items-center rounded-lg border border-border-subtle font-bold" href="<?= h($historyUrl(['page' => (string) ($historyPaging['page'] + 1)])) ?>">الأقدم</a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>
    <?php endif; ?>
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

  const form = document.querySelector('[data-notification-compose]');
  const titleInput = form?.querySelector('[name="title_ar"]');
  const bodyInput = form?.querySelector('[name="body_ar"]');
  const iconSelect = form?.querySelector('[name="icon"]');
  const linkInput = form?.querySelector('[name="link_url"]');
  const preview = document.querySelector('[data-notification-preview]');
  const syncPreview = () => {
    if (!preview) return;
    preview.querySelector('[data-preview-title]').textContent = titleInput?.value.trim() || 'عنوان الإشعار';
    preview.querySelector('[data-preview-body]').textContent = bodyInput?.value.trim() || 'نص الإشعار';
    preview.querySelector('[data-preview-icon]').textContent = iconSelect?.value || 'campaign';
  };
  [titleInput, bodyInput, iconSelect].forEach((el) => el?.addEventListener('input', syncPreview));
  iconSelect?.addEventListener('change', syncPreview);
  document.querySelectorAll('[data-template-title]').forEach((button) => {
    button.addEventListener('click', () => {
      if (titleInput) titleInput.value = button.dataset.templateTitle || '';
      if (bodyInput) bodyInput.value = button.dataset.templateBody || '';
      if (iconSelect && button.dataset.templateIcon) iconSelect.value = button.dataset.templateIcon;
      if (linkInput) linkInput.value = button.dataset.templateLink || '';
      syncPreview();
      titleInput?.focus();
    });
  });
  syncPreview();
  form?.addEventListener('submit', (event) => {
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const send = form.querySelector('[data-notification-send]');
    if (send) {
      send.setAttribute('aria-busy', 'true');
      send.classList.add('opacity-60', 'pointer-events-none');
    }
  });
})();
</script>
