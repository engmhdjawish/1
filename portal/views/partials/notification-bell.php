<?php

declare(strict_types=1);
?>
<div class="notif-bell" data-notif-bell>
  <button type="button" class="notif-bell__btn" data-notif-bell-btn aria-label="الإشعارات" aria-haspopup="true" aria-expanded="false">
    <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
    <span class="notif-bell__badge hidden" data-notif-bell-badge>0</span>
  </button>
  <div class="notif-bell__panel" data-notif-bell-panel role="dialog" aria-label="قائمة الإشعارات" hidden>
    <div class="notif-bell__head">
      <h2>الإشعارات</h2>
      <button type="button" class="notif-bell__mark-all" data-notif-bell-mark-all>تعليم الكل كمقروء</button>
    </div>
    <div class="notif-bell__filters" role="tablist" aria-label="تصفية الإشعارات">
      <button type="button" class="is-active" data-notif-filter="all">الكل</button>
      <button type="button" data-notif-filter="unread">غير المقروء</button>
      <?php foreach (\Portal\Services\NotificationService::CATEGORY_LABELS as $notifCategory => $notifCategoryLabel): ?>
        <button type="button" data-notif-filter="cat:<?= h($notifCategory) ?>" hidden><?= h($notifCategoryLabel) ?></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="notif-bell__enable-push" data-notif-enable-push hidden>تفعيل إشعارات الجهاز</button>
    <div class="notif-bell__list" data-notif-bell-list></div>
    <?php if (!str_starts_with(portal_request_path(), '/dashboard')): ?>
      <a class="notif-bell__all" href="/notifications.php">كل الإشعارات</a>
    <?php endif; ?>
  </div>
</div>
