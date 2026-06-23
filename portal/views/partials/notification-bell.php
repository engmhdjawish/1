<?php

declare(strict_types=1);
?>
<div class="notif-bell" data-notif-bell>
  <button type="button" class="notif-bell__btn" data-notif-bell-btn aria-label="الإشعارات" aria-haspopup="true" aria-expanded="false">
    <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
    <span class="notif-bell__badge hidden" data-notif-bell-badge>0</span>
  </button>
  <div class="notif-bell__panel" data-notif-bell-panel role="dialog" aria-label="قائمة الإشعارات">
    <div class="notif-bell__head">
      <h2>الإشعارات</h2>
      <button type="button" class="notif-bell__mark-all" data-notif-bell-mark-all>تعليم الكل كمقروء</button>
    </div>
    <div class="notif-bell__list" data-notif-bell-list>
      <p class="notif-bell__empty">جاري التحميل...</p>
    </div>
  </div>
</div>
