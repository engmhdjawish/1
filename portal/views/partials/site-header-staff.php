<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $staffUser */

$staffUser = is_array($staffUser ?? null) ? $staffUser : [];
$staffName = trim((string) ($staffUser['display_name_ar'] ?? $staffUser['user_name'] ?? 'موظف'));
?>
<div class="site-header__staff-panel" data-guide="staff-session">
  <a href="/dashboard/index.php" class="site-header__staff-chip" title="لوحة التحكم">
    <span class="site-header__staff-chip-icon material-symbols-outlined" aria-hidden="true">badge</span>
    <span class="site-header__staff-chip-copy">
      <span class="site-header__staff-chip-label">مسجل كموظف</span>
      <strong class="site-header__staff-chip-name"><?= h($staffName) ?></strong>
    </span>
  </a>
  <a href="/dashboard/index.php" class="site-header__icon-btn site-header__icon-btn--staff" title="لوحة التحكم" aria-label="لوحة التحكم">
    <span class="material-symbols-outlined">dashboard</span>
  </a>
  <a href="/logout.php" class="site-header__btn site-header__btn--ghost site-header__btn--compact site-header__staff-logout">خروج</a>
</div>
