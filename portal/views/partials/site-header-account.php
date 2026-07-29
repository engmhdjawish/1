<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */

$customerName = trim((string) ($customer['name_ar'] ?? ''));
if ($customerName === '') {
    $customerName = 'عميل';
}
$customerInitial = mb_strtoupper(mb_substr($customerName, 0, 1));
$customerPhone = trim((string) ($customer['phone'] ?? ''));
$customerPending = ($customer['status'] ?? '') === 'pending';
?>
<div class="site-header__account" data-site-account-menu>
  <button
    type="button"
    class="site-header__account-trigger"
    aria-haspopup="menu"
    aria-expanded="false"
    aria-controls="siteHeaderAccountMenu"
    title="<?= h($customerName) ?>"
  >
    <span class="site-header__account-avatar" aria-hidden="true"><?= h($customerInitial) ?></span>
    <span class="site-header__account-meta">
      <span class="site-header__account-greeting">مرحباً</span>
      <span class="site-header__account-name"><?= h($customerName) ?></span>
    </span>
    <span class="site-header__account-chevron material-symbols-outlined" aria-hidden="true">expand_more</span>
  </button>
  <div class="site-header__account-menu" id="siteHeaderAccountMenu" role="menu" hidden>
    <div class="site-header__account-menu-head">
      <span class="site-header__account-menu-avatar" aria-hidden="true"><?= h($customerInitial) ?></span>
      <div class="site-header__account-menu-copy">
        <strong><?= h($customerName) ?></strong>
        <?php if ($customerPending): ?>
          <span class="site-header__account-menu-badge">بانتظار التفعيل</span>
        <?php elseif ($customerPhone !== ''): ?>
          <span dir="ltr"><?= h($customerPhone) ?></span>
        <?php else: ?>
          <span>مسجّل الدخول</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$customerPending): ?>
    <a href="/my-profile.php" class="site-header__account-menu-link" role="menuitem" data-guide="my-profile">
      <span class="material-symbols-outlined" aria-hidden="true">person</span>
      الملف الشخصي
    </a>
    <a href="/my-orders.php" class="site-header__account-menu-link" role="menuitem" data-guide="my-orders">
      <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
      طلباتي
    </a>
    <?php else: ?>
    <p class="site-header__account-menu-note">حسابك قيد المراجعة. يمكنك التصفح بصلاحيات الزائر حتى التفعيل.</p>
    <?php endif; ?>
    <a href="/logout.php" class="site-header__account-menu-link site-header__account-menu-link--danger" role="menuitem">
      <span class="material-symbols-outlined" aria-hidden="true">logout</span>
      تسجيل الخروج
    </a>
  </div>
</div>
