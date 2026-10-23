<?php

declare(strict_types=1);

use Portal\Auth\WebSession;

/** @var array<string, mixed>|null $customer */
/** @var bool $staffSiteMode */
/** @var array<string, mixed>|null $staffUser */
/** @var string $siteTour */

$accountIsStaff = (bool) ($staffSiteMode ?? false);
$accountLinks = [];
$accountNote = '';

if ($accountIsStaff) {
    $staffRow = is_array($staffUser ?? null) ? $staffUser : [];
    $accountName = trim((string) ($staffRow['display_name_ar'] ?? '')) ?: trim((string) ($staffRow['user_name'] ?? '')) ?: 'موظف';
    $accountRole = trim((string) ($staffRow['role_label'] ?? '')) ?: 'موظف';
    $accountSub = $accountRole !== $accountName && $accountRole !== 'موظف' ? 'موظف · ' . $accountRole : 'حساب موظف';
    $accountIcon = 'badge';
    $accountLinks[] = ['href' => '/dashboard/index.php', 'icon' => 'dashboard', 'label' => 'لوحة التحكم'];
    if (WebSession::hasPermission('orders.view')) {
        $accountLinks[] = ['href' => '/dashboard/orders.php', 'icon' => 'receipt_long', 'label' => 'الطلبات'];
    }
    if (WebSession::hasPermission('web_customers.view')) {
        $accountLinks[] = ['href' => '/dashboard/customers.php', 'icon' => 'groups', 'label' => 'العملاء'];
    }
    $accountLinks[] = ['href' => '/dashboard/profile.php', 'icon' => 'manage_accounts', 'label' => 'ملفي الشخصي'];
} else {
    $customerRow = is_array($customer ?? null) ? $customer : [];
    $accountName = trim((string) ($customerRow['name_ar'] ?? '')) ?: 'عميل';
    $accountPending = ($customerRow['status'] ?? '') === 'pending';
    $accountPhone = trim((string) ($customerRow['phone'] ?? ''));
    $accountSub = $accountPending ? 'بانتظار التفعيل' : 'حساب عميل';
    $accountIcon = 'storefront';
    if ($accountPending) {
        $accountNote = 'حسابك قيد المراجعة. يمكنك التصفح بصلاحيات الزائر حتى التفعيل.';
    } else {
        $accountLinks[] = ['href' => '/my-orders.php', 'icon' => 'inventory_2', 'label' => 'طلباتي', 'guide' => 'my-orders'];
        $accountLinks[] = ['href' => '/my-profile.php', 'icon' => 'person', 'label' => 'الملف الشخصي', 'guide' => 'my-profile'];
    }
    $accountLinks[] = ['href' => '/notifications.php', 'icon' => 'notifications', 'label' => 'الإشعارات'];
}
?>
<div class="site-account<?= $accountIsStaff ? ' site-account--staff' : '' ?>" data-site-account-menu>
  <button
    type="button"
    class="site-account__trigger"
    aria-haspopup="menu"
    aria-expanded="false"
    aria-controls="siteAccountMenu"
    data-site-account-toggle
  >
    <span class="site-account__icon material-symbols-outlined" aria-hidden="true"><?= h($accountIcon) ?></span>
    <span class="site-account__copy">
      <span class="site-account__name"><?= h($accountName) ?></span>
      <span class="site-account__sub<?= !$accountIsStaff && $accountPending ? ' is-pending' : '' ?>"><?= h($accountSub) ?></span>
    </span>
    <span class="site-account__chevron material-symbols-outlined" aria-hidden="true">expand_more</span>
  </button>
  <div class="site-account__backdrop" data-site-account-close hidden></div>
  <div class="site-account__menu" id="siteAccountMenu" role="menu" aria-label="حسابي" hidden>
    <div class="site-account__head">
      <span class="site-account__head-icon material-symbols-outlined" aria-hidden="true"><?= h($accountIcon) ?></span>
      <div class="site-account__head-copy">
        <strong><?= h($accountName) ?></strong>
        <?php if (!$accountIsStaff && $accountPhone !== ''): ?>
          <span dir="ltr"><?= h($accountPhone) ?></span>
        <?php else: ?>
          <span><?= h($accountSub) ?></span>
        <?php endif; ?>
      </div>
      <button type="button" class="site-account__close" data-site-account-close aria-label="إغلاق">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </div>
    <?php if ($accountNote !== ''): ?>
      <p class="site-account__note"><?= h($accountNote) ?></p>
    <?php endif; ?>
    <?php foreach ($accountLinks as $accountLink): ?>
      <a href="<?= h($accountLink['href']) ?>" class="site-account__link" role="menuitem"<?= !empty($accountLink['guide']) ? ' data-guide="' . h($accountLink['guide']) . '"' : '' ?>>
        <span class="material-symbols-outlined" aria-hidden="true"><?= h($accountLink['icon']) ?></span>
        <?= h($accountLink['label']) ?>
      </a>
    <?php endforeach; ?>
    <?php if (($siteTour ?? '') !== ''): ?>
      <button type="button" class="site-account__link" role="menuitem" data-site-guide-replay>
        <span class="material-symbols-outlined" aria-hidden="true">tour</span>
        جولة تعريفية بالصفحة
      </button>
    <?php endif; ?>
    <a href="/logout.php" class="site-account__link site-account__link--danger" role="menuitem">
      <span class="material-symbols-outlined" aria-hidden="true">logout</span>
      تسجيل الخروج
    </a>
  </div>
</div>
