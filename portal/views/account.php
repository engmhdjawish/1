<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */
/** @var array<string, mixed> $profile */
/** @var string $tab */
/** @var list<array<string, mixed>> $orders */
/** @var array<string, mixed>|null $orderDetails */
/** @var string $orderId */
/** @var string $statusFilter */
/** @var string|null $flash */
/** @var string $flashType */

use Portal\Services\OrderService;

$statusOptions = [
    '' => 'كل الحالات',
    'pending' => OrderService::statusLabel('pending'),
    'confirmed' => OrderService::statusLabel('confirmed'),
    'completed' => OrderService::statusLabel('completed'),
    'cancelled' => OrderService::statusLabel('cancelled'),
];
$customerName = trim((string) ($profile['name_ar'] ?? $customer['name_ar'] ?? 'عميل'));
$initial = function_exists('mb_substr') ? mb_substr($customerName, 0, 1) : substr($customerName, 0, 1);
?>
<div class="customer-portal">
  <header class="customer-portal__hero">
    <div class="flex items-center gap-3 min-w-0">
      <div class="customer-portal__avatar" aria-hidden="true"><?= h($initial) ?></div>
      <div class="min-w-0">
        <h1 class="customer-portal__title">مرحباً، <?= h($customerName) ?></h1>
        <p class="customer-portal__subtitle" dir="ltr"><?= h((string) ($customer['phone'] ?? '')) ?></p>
      </div>
    </div>
    <a href="/store.php" class="store-btn store-btn--secondary shrink-0">
      <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
      المتجر
    </a>
  </header>

  <?php if ($flash): ?>
    <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>">
      <?= h($flash) ?>
    </p>
  <?php endif; ?>

  <nav class="customer-portal-tabs" aria-label="أقسام الحساب">
    <a href="/account.php?tab=profile" class="customer-portal-tab <?= $tab === 'profile' ? 'is-active' : '' ?>">
      <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
      الملف الشخصي
    </a>
    <a href="/account.php?tab=orders" class="customer-portal-tab <?= $tab === 'orders' ? 'is-active' : '' ?>">
      <span class="material-symbols-outlined text-base" aria-hidden="true">receipt_long</span>
      طلباتي
    </a>
  </nav>

  <?php if ($tab === 'profile'): ?>
    <div class="customer-profile-grid">
      <form method="post" class="customer-form-card">
        <input type="hidden" name="action" value="update_profile">
        <h2 class="customer-form-card__title">
          <span class="material-symbols-outlined" aria-hidden="true">badge</span>
          بيانات الحساب
        </h2>
        <label>
          الاسم
          <input name="name_ar" value="<?= h((string) ($profile['name_ar'] ?? $customer['name_ar'] ?? '')) ?>" required>
        </label>
        <label>
          رقم الهاتف
          <input value="<?= h((string) ($customer['phone'] ?? '')) ?>" disabled dir="ltr">
        </label>
        <label>
          البريد الإلكتروني
          <input type="email" name="email" value="<?= h((string) ($profile['email'] ?? '')) ?>" dir="ltr">
        </label>
        <button type="submit" class="store-btn store-btn--primary">حفظ التغييرات</button>
      </form>

      <form method="post" class="customer-form-card">
        <input type="hidden" name="action" value="change_password">
        <h2 class="customer-form-card__title">
          <span class="material-symbols-outlined" aria-hidden="true">lock</span>
          كلمة المرور
        </h2>
        <label>
          كلمة المرور الحالية
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>
          كلمة المرور الجديدة
          <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
        </label>
        <button type="submit" class="store-btn store-btn--secondary">تحديث كلمة المرور</button>
      </form>
    </div>
  <?php else: ?>
    <?php if ($orderDetails !== null): ?>
      <a href="/account.php?tab=orders<?= $statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '' ?>" class="customer-back-link">
        <span class="material-symbols-outlined text-base" aria-hidden="true">chevron_right</span>
        العودة إلى الطلبات
      </a>
      <?php
        $order = $orderDetails;
        $showTrackingLink = true;
        $trackingUrl = absolute_order_tracking_url((string) ($orderDetails['quote_access_token'] ?? ''));
        require __DIR__ . '/partials/customer-order-detail.php';
      ?>
      <?php require __DIR__ . '/partials/store-image-lightbox.php'; ?>
    <?php else: ?>
      <form method="get" class="customer-orders-toolbar">
        <input type="hidden" name="tab" value="orders">
        <label class="text-sm">
          <span class="text-gray-500 text-xs block mb-1">تصفية حسب الحالة</span>
          <select name="status" class="h-10 rounded-xl border border-gray-300 px-3 text-sm min-w-40">
            <?php foreach ($statusOptions as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="store-btn store-btn--primary h-10">تصفية</button>
      </form>

      <?php if ($orders === []): ?>
        <div class="customer-form-card text-center py-10 text-gray-500">
          <span class="material-symbols-outlined text-4xl text-gray-300" aria-hidden="true">shopping_bag</span>
          <p class="mt-3">لا توجد طلبات مرتبطة بحسابك حتى الآن.</p>
          <a href="/store.php" class="store-btn store-btn--primary mt-4">تصفح المتجر</a>
        </div>
      <?php else: ?>
        <div class="customer-order-list">
          <?php foreach ($orders as $row): ?>
            <?php
              $rowStatus = (string) ($row['status'] ?? 'pending');
              $rowId = (string) ($row['id'] ?? '');
              $detailUrl = '/account.php?tab=orders&order=' . rawurlencode($rowId)
                  . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '');
            ?>
            <a href="<?= h($detailUrl) ?>" class="customer-order-row">
              <div class="customer-order-row__top">
                <div>
                  <div class="customer-order-row__number" dir="ltr"><?= h((string) ($row['order_number'] ?? '')) ?></div>
                  <div class="customer-order-row__date"><?= h(accounting_format_date($row['created_at'] ?? '')) ?></div>
                </div>
                <?php $status = $rowStatus; $size = 'sm'; require __DIR__ . '/partials/order-status-badge.php'; ?>
              </div>
              <div class="customer-order-row__meta">
                <span><strong><?= (int) ($row['items_count'] ?? 0) ?></strong> صنف</span>
                <span>الإجمالي: <strong class="store-num" dir="ltr"><?= format_money((float) ($row['total_sp'] ?? 0), true) ?> ل.س</strong></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
  (() => {
    document.querySelectorAll('[data-copy-tracking-url]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const url = btn.getAttribute('data-copy-tracking-url') || '';
        if (!url) return;
        try {
          await navigator.clipboard.writeText(url);
          const prev = btn.textContent;
          btn.textContent = 'تم النسخ';
          setTimeout(() => { btn.textContent = prev; }, 2000);
        } catch {
          const field = document.getElementById('orderTrackingUrlField');
          if (field) { field.select(); document.execCommand('copy'); }
        }
      });
    });
  })();
</script>
