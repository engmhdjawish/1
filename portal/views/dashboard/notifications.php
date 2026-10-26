<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */
/** @var list<array<string, mixed>> $sentNotifications */
/** @var list<array<string, mixed>> $customers */
/** @var list<array<string, mixed>> $staffUsers */
/** @var array<string, int|array<string, int>> $notificationStats */
/** @var array{customers_active: int, customers_pending: int, staff_active: int, push: array{total: int, guest: int, customer: int, staff: int}} $audienceReach */
/** @var bool $pushConfigured */
/** @var list<array{label: string, icon: string, title: string, body: string, link: string}> $composeTemplates */
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
    'auto_count' => 0,
    'expired_count' => 0,
    'active_count' => 0,
    'read_total' => 0,
    'dismiss_total' => 0,
];
$audienceReach = $audienceReach ?? [
    'customers_active' => 0,
    'customers_pending' => 0,
    'staff_active' => 0,
    'push' => ['total' => 0, 'guest' => 0, 'customer' => 0, 'staff' => 0],
];
$pushConfigured = (bool) ($pushConfigured ?? false);
$composeTemplates = $composeTemplates ?? NotificationService::composeTemplates();
$push = is_array($audienceReach['push'] ?? null) ? $audienceReach['push'] : ['total' => 0, 'guest' => 0, 'customer' => 0, 'staff' => 0];

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
$audienceLabel = static function (string $audience): string {
    return match ($audience) {
        NotificationService::AUDIENCE_GUESTS => 'الزوار فقط',
        NotificationService::AUDIENCE_CUSTOMERS => 'العملاء المسجّلون',
        NotificationService::AUDIENCE_STAFF => 'الموظفون',
        default => 'الجميع',
    };
};
?>
<section class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
  <div>
    <p class="text-xs font-bold text-primary mb-1">مركز الاتصالات</p>
    <h1 class="text-2xl font-extrabold text-slate-900">الإشعارات</h1>
    <p class="text-sm text-text-muted mt-1 max-w-3xl">
      أرسل حملات للجوال والموقع، راقب الوصول عبر اشتراكات الجهاز، وتابع السجل اليدوي والتلقائي من مكان واحد.
    </p>
  </div>
  <div class="flex flex-wrap gap-2 text-xs">
    <span class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full border <?= $pushConfigured ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900' ?>">
      <span class="material-symbols-outlined text-base" aria-hidden="true"><?= $pushConfigured ? 'notifications_active' : 'notifications_off' ?></span>
      <?= $pushConfigured ? 'إشعارات الجهاز مفعّلة' : 'إشعارات الجهاز غير مهيّأة' ?>
    </span>
    <span class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full border border-border-subtle bg-white text-slate-700">
      <span class="material-symbols-outlined text-base" aria-hidden="true">devices</span>
      <?= (int) ($push['total'] ?? 0) ?> جهاز مشترك
    </span>
  </div>
</section>

<section class="grid grid-cols-2 xl:grid-cols-6 gap-3 mb-6">
  <?php foreach ([
      ['label' => 'حملاتي', 'value' => (int) ($notificationStats['manual_count'] ?? 0), 'hint' => 'يدوية'],
      ['label' => 'تلقائية', 'value' => (int) ($notificationStats['auto_count'] ?? max(0, (int) ($notificationStats['total'] ?? 0) - (int) ($notificationStats['manual_count'] ?? 0))), 'hint' => 'النظام'],
      ['label' => 'سارية', 'value' => (int) ($notificationStats['active_count'] ?? 0), 'hint' => 'غير منتهية'],
      ['label' => 'منتهية', 'value' => (int) ($notificationStats['expired_count'] ?? 0), 'hint' => 'انتهى وقتها'],
      ['label' => 'قراءات', 'value' => (int) ($notificationStats['read_total'] ?? 0), 'hint' => 'مرات فتح'],
      ['label' => 'إخفاء', 'value' => (int) ($notificationStats['dismiss_total'] ?? 0), 'hint' => 'من الجرس'],
  ] as $stat): ?>
    <article class="rounded-2xl border border-border-subtle bg-white px-4 py-3 shadow-sm">
      <p class="text-[11px] text-text-muted"><?= h($stat['label']) ?></p>
      <p class="text-2xl font-extrabold text-slate-900 mt-1 tabular-nums"><?= h((string) $stat['value']) ?></p>
      <p class="text-[11px] text-slate-400 mt-0.5"><?= h($stat['hint']) ?></p>
    </article>
  <?php endforeach; ?>
</section>

<section class="rounded-2xl border border-border-subtle bg-white p-4 mb-6 shadow-sm">
  <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div>
      <h2 class="font-bold text-slate-900">وصول الجهاز والجمهور</h2>
      <p class="text-xs text-text-muted mt-1">تقدير تقريبي قبل الإرسال — الإشعار داخل الموقع يصل دائماً للجلسة المناسبة، والجهاز يعتمد على الاشتراك.</p>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs w-full lg:w-auto">
      <div class="rounded-xl border border-border-subtle px-3 py-2 bg-surface-low/40">
        <p class="text-text-muted">عملاء مفعّلون</p>
        <p class="font-extrabold text-slate-900 text-lg tabular-nums"><?= (int) ($audienceReach['customers_active'] ?? 0) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle px-3 py-2 bg-surface-low/40">
        <p class="text-text-muted">موظفون</p>
        <p class="font-extrabold text-slate-900 text-lg tabular-nums"><?= (int) ($audienceReach['staff_active'] ?? 0) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle px-3 py-2 bg-surface-low/40">
        <p class="text-text-muted">Push عملاء</p>
        <p class="font-extrabold text-slate-900 text-lg tabular-nums"><?= (int) ($push['customer'] ?? 0) ?></p>
      </div>
      <div class="rounded-xl border border-border-subtle px-3 py-2 bg-surface-low/40">
        <p class="text-text-muted">Push زوار</p>
        <p class="font-extrabold text-slate-900 text-lg tabular-nums"><?= (int) ($push['guest'] ?? 0) ?></p>
      </div>
    </div>
  </div>
</section>

<?php if (!empty($flash)): ?>
  <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700' ?>">
    <?= h((string) $flash) ?>
  </p>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
  <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden shadow-sm">
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 flex items-center justify-between gap-3">
      <div>
        <h2 class="font-bold">إرسال إشعار جديد</h2>
        <p class="text-[11px] text-text-muted mt-0.5">معاينة مباشرة + قوالب جاهزة</p>
      </div>
      <span class="material-symbols-outlined text-primary" aria-hidden="true">edit_notifications</span>
    </div>
    <form method="post" class="p-4 space-y-4" data-notification-compose>
      <input type="hidden" name="action" value="send">

      <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-surface-low/70 border border-border-subtle" role="radiogroup" aria-label="نوع الإشعار">
        <label class="relative cursor-pointer">
          <input type="radio" name="scope" value="public" class="peer sr-only" checked data-notification-scope>
          <span class="flex items-center justify-center gap-1.5 h-10 rounded-lg text-sm font-bold text-slate-600 peer-checked:bg-white peer-checked:text-primary peer-checked:shadow-sm">
            <span class="material-symbols-outlined text-base" aria-hidden="true">campaign</span>
            عام
          </span>
        </label>
        <label class="relative cursor-pointer">
          <input type="radio" name="scope" value="private" class="peer sr-only" data-notification-scope>
          <span class="flex items-center justify-center gap-1.5 h-10 rounded-lg text-sm font-bold text-slate-600 peer-checked:bg-white peer-checked:text-primary peer-checked:shadow-sm">
            <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
            خاص
          </span>
        </label>
      </div>

      <div data-notification-public-fields class="space-y-2">
        <label class="block text-sm font-bold mb-1">الجمهور</label>
        <select name="audience" class="h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" data-notification-audience>
          <option value="<?= h(NotificationService::AUDIENCE_ALL) ?>" data-reach-accounts="<?= (int) ($audienceReach['customers_active'] + $audienceReach['staff_active']) ?>" data-reach-push="<?= (int) ($push['total'] ?? 0) ?>">الجميع (زوار + عملاء + موظفين)</option>
          <option value="<?= h(NotificationService::AUDIENCE_GUESTS) ?>" data-reach-accounts="0" data-reach-push="<?= (int) ($push['guest'] ?? 0) ?>">الزوار فقط (غير المسجّلين)</option>
          <option value="<?= h(NotificationService::AUDIENCE_CUSTOMERS) ?>" data-reach-accounts="<?= (int) ($audienceReach['customers_active'] ?? 0) ?>" data-reach-push="<?= (int) ($push['customer'] ?? 0) ?>">العملاء المسجّلون فقط</option>
          <option value="<?= h(NotificationService::AUDIENCE_STAFF) ?>" data-reach-accounts="<?= (int) ($audienceReach['staff_active'] ?? 0) ?>" data-reach-push="<?= (int) ($push['staff'] ?? 0) ?>">الموظفون فقط</option>
        </select>
        <p class="text-xs text-text-muted" data-audience-reach-hint>تقدير الوصول يظهر حسب الجمهور المختار.</p>
      </div>

      <div data-notification-private-fields class="hidden space-y-3">
        <label class="block text-sm">
          <span class="font-bold">عميل محدد</span>
          <input type="search" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="تصفية الاسم أو الهاتف..." data-recipient-filter="customer" autocomplete="off">
          <select name="customer_id" class="mt-2 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" data-recipient-select="customer" size="6">
            <option value="">—</option>
            <?php foreach ($customers as $customer): ?>
              <?php
                $customerStatus = (string) ($customer['status'] ?? '');
                $customerLabel = trim((string) ($customer['name_ar'] ?? '')) . ' — ' . trim((string) ($customer['phone'] ?? ''));
                if ($customerStatus !== '') {
                    $customerLabel .= ' (' . ($customerStatusLabels[$customerStatus] ?? $customerStatus) . ')';
                }
              ?>
              <option value="<?= h((string) ($customer['id'] ?? '')) ?>" data-filter-text="<?= h(mb_strtolower($customerLabel, 'UTF-8')) ?>">
                <?= h($customerLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block text-sm">
          <span class="font-bold">أو موظف محدد</span>
          <input type="search" class="mt-1 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" placeholder="تصفية اسم الموظف..." data-recipient-filter="staff" autocomplete="off">
          <select name="staff_id" class="mt-2 h-10 w-full rounded-lg border border-border-subtle px-3 text-sm" data-recipient-select="staff" size="5">
            <option value="">—</option>
            <?php foreach ($staffUsers as $staff): ?>
              <?php
                $staffLabel = trim((string) ($staff['display_name_ar'] ?? '')) . ' (' . trim((string) ($staff['user_name'] ?? '')) . ')';
              ?>
              <option value="<?= h((string) ($staff['id'] ?? '')) ?>" data-filter-text="<?= h(mb_strtolower($staffLabel, 'UTF-8')) ?>">
                <?= h($staffLabel) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div>
        <p class="text-xs text-text-muted mb-2">قوالب سريعة</p>
        <div class="flex flex-wrap gap-2" data-notification-templates>
          <?php foreach ($composeTemplates as $template): ?>
            <button type="button" class="h-8 px-3 rounded-full border border-border-subtle text-xs font-bold hover:bg-slate-50 hover:border-primary/40"
              data-template-title="<?= h($template['title']) ?>" data-template-body="<?= h($template['body']) ?>"
              data-template-icon="<?= h($template['icon']) ?>" data-template-link="<?= h($template['link']) ?>"><?= h($template['label']) ?></button>
          <?php endforeach; ?>
        </div>
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

      <div class="rounded-xl border border-dashed border-border-subtle bg-surface-low/30 p-3">
        <p class="text-xs text-text-muted mb-2">معاينة الجرس</p>
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

      <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
        <p class="text-xs text-text-muted" data-send-summary>راجع الجمهور ثم أكّد الإرسال.</p>
        <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white text-sm font-bold inline-flex items-center justify-center gap-2" data-notification-send>
          <span class="material-symbols-outlined text-lg">send</span>
          إرسال الإشعار
        </button>
      </div>
    </form>
  </section>

  <section class="rounded-2xl border border-border-subtle bg-white overflow-hidden shadow-sm">
    <?php
      $historyUrl = static function (array $override) use ($historyKind, $historyScope, $historyQuery): string {
          $query = array_merge(['kind' => $historyKind, 'scope' => $historyScope, 'q' => $historyQuery, 'page' => ''], $override);
          $query = array_filter($query, static fn ($v): bool => (string) $v !== '' && !($v === 'manual'));

          return '/dashboard/notifications.php' . ($query !== [] ? '?' . http_build_query($query) : '');
      };
      $backQuery = (string) (parse_url($historyUrl(['page' => $historyPaging['page'] > 1 ? (string) $historyPaging['page'] : '']), PHP_URL_QUERY) ?? '');
      $tabClass = static fn (bool $on): string => 'h-8 px-3 inline-flex items-center rounded-full border text-xs font-bold ' . ($on ? 'border-primary bg-primary/10 text-primary' : 'border-border-subtle hover:bg-slate-50');
    ?>
    <div class="px-4 py-3 border-b border-border-subtle bg-surface-low/60 space-y-3">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h2 class="font-bold">السجل</h2>
          <p class="text-[11px] text-text-muted mt-0.5">حملات يدوية وتنبيهات النظام</p>
        </div>
        <span class="text-xs text-text-muted tabular-nums"><?= (int) $historyPaging['total'] ?> إشعار</span>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="<?= h($historyUrl(['kind' => 'manual', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'manual') ?>">حملاتي</a>
        <a href="<?= h($historyUrl(['kind' => 'auto', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'auto') ?>">تلقائية</a>
        <a href="<?= h($historyUrl(['kind' => 'all', 'page' => ''])) ?>" class="<?= $tabClass($historyKind === 'all') ?>">الكل</a>
        <span class="w-px bg-border-subtle mx-1 self-stretch"></span>
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
    <div class="divide-y divide-border-subtle max-h-[42rem] overflow-y-auto" data-notification-sent-list>
      <?php if ($sentNotifications === []): ?>
        <div class="p-8 text-center">
          <span class="material-symbols-outlined text-3xl text-slate-300" aria-hidden="true">inbox</span>
          <p class="mt-2 text-sm text-text-muted">لا توجد إشعارات في هذا التبويب.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($sentNotifications as $row): ?>
        <?php
          $rowSource = (string) ($row['source'] ?? 'manual');
          $rowScope = (string) ($row['scope'] ?? 'public');
          $rowAudience = (string) ($row['audience'] ?? '');
          $rowCategory = NotificationService::category($rowSource);
          $searchRaw = (string) ($row['title_ar'] ?? '') . ' ' . (string) ($row['body_ar'] ?? '') . ' ' . (string) ($row['customer_name_ar'] ?? '') . ' ' . (string) ($row['staff_name_ar'] ?? '');
          $searchText = function_exists('mb_strtolower') ? mb_strtolower($searchRaw, 'UTF-8') : strtolower($searchRaw);
          $isExpired = !empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= time();
        ?>
        <article
          class="p-4 hover:bg-slate-50/70 transition-colors"
          data-notification-row
          data-notification-id="<?= h((string) ($row['id'] ?? '')) ?>"
          data-scope="<?= h($rowScope) ?>"
          data-source="<?= h($rowSource) ?>"
          data-search="<?= h($searchText) ?>"
          data-refill-title="<?= h((string) ($row['title_ar'] ?? '')) ?>"
          data-refill-body="<?= h((string) ($row['body_ar'] ?? '')) ?>"
          data-refill-icon="<?= h((string) ($row['icon'] ?? 'campaign')) ?>"
          data-refill-link="<?= h((string) ($row['link_url'] ?? '')) ?>"
          data-refill-scope="<?= h($rowScope) ?>"
          data-refill-audience="<?= h($rowAudience !== '' ? $rowAudience : NotificationService::AUDIENCE_ALL) ?>"
        >
          <div class="flex items-start gap-3">
            <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
              <span class="material-symbols-outlined text-lg"><?= h((string) ($row['icon'] ?? 'notifications')) ?></span>
            </span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-bold text-sm text-slate-900"><?= h((string) ($row['title_ar'] ?? '')) ?></h3>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border border-border-subtle"><?= $rowScope === 'private' ? 'خاص' : 'عام' ?></span>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border border-border-subtle text-slate-600"><?= h(NotificationService::CATEGORY_LABELS[$rowCategory] ?? 'إعلانات') ?></span>
                <?php if ($isExpired): ?>
                  <span class="text-[10px] font-bold px-2 py-0.5 rounded-full border border-amber-200 bg-amber-50 text-amber-800">منتهٍ</span>
                <?php endif; ?>
              </div>
              <p class="text-sm text-text-muted mt-1 leading-6"><?= h((string) ($row['body_ar'] ?? '')) ?></p>
              <p class="text-xs text-text-muted mt-2 flex flex-wrap gap-x-2 gap-y-1">
                <span><?= h($formatWhen((string) ($row['created_at'] ?? ''))) ?></span>
                <span>· <?= h(NotificationService::sourceLabel($rowSource)) ?></span>
                <?php if (!empty($row['creator_name_ar'])): ?>
                  <span>· بواسطة <?= h((string) $row['creator_name_ar']) ?></span>
                <?php endif; ?>
                <?php if (!empty($row['customer_name_ar'])): ?>
                  <span>· عميل: <?= h((string) $row['customer_name_ar']) ?></span>
                <?php elseif (!empty($row['staff_name_ar'])): ?>
                  <span>· موظف: <?= h((string) $row['staff_name_ar']) ?></span>
                <?php elseif ($rowScope === 'public'): ?>
                  <span>· <?= h($audienceLabel($rowAudience)) ?></span>
                <?php endif; ?>
                <span>· قُرئ <?= (int) ($row['read_count'] ?? 0) ?></span>
                <?php if ((int) ($row['dismiss_count'] ?? 0) > 0): ?>
                  <span>· أُخفي <?= (int) $row['dismiss_count'] ?></span>
                <?php endif; ?>
                <?php if (!empty($row['expires_at'])): ?>
                  <span>· ينتهي <?= h($formatWhen((string) $row['expires_at'])) ?></span>
                <?php endif; ?>
              </p>
              <?php if (!empty($row['link_url'])): ?>
                <p class="text-[11px] mt-1" dir="ltr"><a class="text-primary font-bold hover:underline" href="<?= h((string) $row['link_url']) ?>"><?= h((string) $row['link_url']) ?></a></p>
              <?php endif; ?>
              <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle text-xs font-bold hover:bg-white inline-flex items-center gap-1" data-view-readers>
                  <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                  من شاهد (<?= (int) ($row['read_count'] ?? 0) ?>)
                </button>
                <button type="button" class="h-8 px-3 rounded-lg border border-border-subtle text-xs font-bold hover:bg-white" data-refill-notification>
                  إعادة تعبئة النموذج
                </button>
              </div>
              <div class="mt-3 hidden rounded-xl border border-border-subtle bg-white" data-readers-panel>
                <div class="px-3 py-2 border-b border-border-subtle text-xs font-bold text-slate-700">المشاهدون — الضغط من الموقع أو إشعار الجهاز</div>
                <div class="max-h-48 overflow-y-auto divide-y divide-border-subtle text-sm" data-readers-list>
                  <p class="p-3 text-xs text-text-muted">جاري التحميل...</p>
                </div>
              </div>
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
  const audienceSelect = document.querySelector('[data-notification-audience]');
  const reachHint = document.querySelector('[data-audience-reach-hint]');
  const sendSummary = document.querySelector('[data-send-summary]');
  const pushConfigured = <?= $pushConfigured ? 'true' : 'false' ?>;

  const syncScope = () => {
    const scope = document.querySelector('[data-notification-scope]:checked')?.value || 'public';
    const isPrivate = scope === 'private';
    publicFields.forEach((el) => el.classList.toggle('hidden', isPrivate));
    privateFields?.classList.toggle('hidden', !isPrivate);
    syncReach();
  };

  const syncReach = () => {
    const scope = document.querySelector('[data-notification-scope]:checked')?.value || 'public';
    if (scope === 'private') {
      if (reachHint) reachHint.textContent = 'الإشعار الخاص يصل للمستلم المحدد داخل الموقع، وللجهاز إن كان مشتركاً.';
      if (sendSummary) sendSummary.textContent = 'سيتم الإرسال لمستلم واحد فقط.';
      return;
    }
    const option = audienceSelect?.selectedOptions?.[0];
    const accounts = option?.dataset.reachAccounts || '0';
    const push = option?.dataset.reachPush || '0';
    if (reachHint) {
      reachHint.textContent = pushConfigured
        ? `تقدير: حسابات ≈ ${accounts} · أجهزة Push ≈ ${push}`
        : `تقدير الحسابات ≈ ${accounts} · إشعارات الجهاز غير مهيّأة على الخادم.`;
    }
    if (sendSummary) {
      sendSummary.textContent = pushConfigured
        ? `سيصل داخل الموقع للجمهور المختار، وعلى الأجهزة المشتركة (${push}).`
        : 'سيصل داخل الموقع للجمهور المختار.';
    }
  };

  scopeInputs.forEach((input) => input.addEventListener('change', syncScope));
  audienceSelect?.addEventListener('change', syncReach);
  syncScope();

  const customerSelect = document.querySelector('[name="customer_id"]');
  const staffSelect = document.querySelector('[name="staff_id"]');
  customerSelect?.addEventListener('change', () => {
    if (customerSelect.value && staffSelect) staffSelect.value = '';
  });
  staffSelect?.addEventListener('change', () => {
    if (staffSelect.value && customerSelect) customerSelect.value = '';
  });

  document.querySelectorAll('[data-recipient-filter]').forEach((input) => {
    const kind = input.getAttribute('data-recipient-filter');
    const select = document.querySelector(`[data-recipient-select="${kind}"]`);
    if (!select) return;
    input.addEventListener('input', () => {
      const q = (input.value || '').trim().toLocaleLowerCase('ar');
      Array.from(select.options).forEach((option, index) => {
        if (index === 0) {
          option.hidden = false;
          return;
        }
        const hay = option.getAttribute('data-filter-text') || option.textContent.toLocaleLowerCase('ar');
        option.hidden = q !== '' && !hay.includes(q);
      });
    });
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

  document.querySelectorAll('[data-refill-notification]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-notification-row]');
      if (!row || !form) return;
      const scope = row.dataset.refillScope || 'public';
      const scopeInput = form.querySelector(`[data-notification-scope][value="${scope}"]`);
      if (scopeInput) {
        scopeInput.checked = true;
        syncScope();
      }
      if (audienceSelect && row.dataset.refillAudience) {
        audienceSelect.value = row.dataset.refillAudience;
        syncReach();
      }
      if (titleInput) titleInput.value = row.dataset.refillTitle || '';
      if (bodyInput) bodyInput.value = row.dataset.refillBody || '';
      if (iconSelect) iconSelect.value = row.dataset.refillIcon || 'campaign';
      if (linkInput) linkInput.value = row.dataset.refillLink || '';
      syncPreview();
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      titleInput?.focus();
    });
  });

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const typeLabel = (type) => ({
    customer: 'عميل',
    staff: 'موظف',
    guest: 'زائر',
  }[type] || 'مستخدم');

  document.querySelectorAll('[data-view-readers]').forEach((button) => {
    button.addEventListener('click', async () => {
      const row = button.closest('[data-notification-row]');
      const panel = row?.querySelector('[data-readers-panel]');
      const list = row?.querySelector('[data-readers-list]');
      const id = row?.dataset.notificationId || '';
      if (!panel || !list || !id) return;

      const willOpen = panel.classList.contains('hidden');
      document.querySelectorAll('[data-readers-panel]').forEach((other) => {
        if (other !== panel) other.classList.add('hidden');
      });
      if (!willOpen) {
        panel.classList.add('hidden');
        return;
      }

      panel.classList.remove('hidden');
      list.innerHTML = '<p class="p-3 text-xs text-text-muted">جاري التحميل...</p>';
      try {
        const res = await fetch('/api/notifications.php?action=readers&id=' + encodeURIComponent(id), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || data.ok === false) {
          throw new Error(data.message || 'تعذر تحميل المشاهدين.');
        }
        const readers = Array.isArray(data.readers) ? data.readers : [];
        if (readers.length === 0) {
          list.innerHTML = '<p class="p-3 text-xs text-text-muted">لم يشاهده أحد بعد. تُحسب المشاهدة عند الضغط على الإشعار من الموقع أو من إشعار الجهاز.</p>';
          return;
        }
        list.innerHTML = readers.map((reader) => {
          const detail = String(reader.detail_ar || '').trim();
          return '<div class="px-3 py-2 flex items-start justify-between gap-3">'
            + '<div class="min-w-0">'
            + '<p class="font-bold text-slate-900 text-sm">' + escapeHtml(reader.name_ar || 'مستخدم') + '</p>'
            + '<p class="text-[11px] text-text-muted mt-0.5">'
            + escapeHtml(typeLabel(reader.reader_type))
            + (detail ? ' · ' + escapeHtml(detail) : '')
            + ' · ' + escapeHtml(reader.view_via_label || '')
            + '</p>'
            + '</div>'
            + '<time class="text-[11px] text-slate-500 shrink-0 tabular-nums">' + escapeHtml(reader.read_at_fmt || '') + '</time>'
            + '</div>';
        }).join('');
        button.innerHTML = '<span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span> من شاهد (' + readers.length + ')';
      } catch (error) {
        list.innerHTML = '<p class="p-3 text-xs text-red-600">' + escapeHtml(error instanceof Error ? error.message : 'تعذر التحميل') + '</p>';
      }
    });
  });

  form?.addEventListener('submit', (event) => {
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    const scope = document.querySelector('[data-notification-scope]:checked')?.value || 'public';
    const title = titleInput?.value.trim() || 'هذا الإشعار';
    let who = 'الجمهور المختار';
    if (scope === 'private') {
      who = customerSelect?.selectedOptions?.[0]?.textContent?.trim()
        || staffSelect?.selectedOptions?.[0]?.textContent?.trim()
        || 'المستلم المحدد';
    } else {
      who = audienceSelect?.selectedOptions?.[0]?.textContent?.trim() || who;
    }
    if (!window.confirm(`تأكيد إرسال «${title}» إلى: ${who}؟`)) {
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
