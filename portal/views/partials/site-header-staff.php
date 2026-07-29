<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $staffUser */

$staffUser = is_array($staffUser ?? null) ? $staffUser : [];
$staffName = trim((string) ($staffUser['display_name_ar'] ?? $staffUser['user_name'] ?? 'موظف'));
?>
<div class="site-header__staff-session" data-guide="staff-session">
  <a href="/dashboard/index.php" class="site-header__staff-badge" title="لوحة التحكم — <?= h($staffName) ?>">
    <span class="site-header__staff-badge-icon material-symbols-outlined" aria-hidden="true">badge</span>
    <span class="site-header__staff-badge-copy">
      <span class="site-header__staff-badge-role">موظف</span>
      <span class="site-header__staff-badge-name"><?= h($staffName) ?></span>
    </span>
  </a>
  <a href="/logout.php" class="site-header__staff-signout" title="تسجيل خروج" aria-label="تسجيل خروج">
    <span class="material-symbols-outlined" aria-hidden="true">logout</span>
  </a>
</div>
