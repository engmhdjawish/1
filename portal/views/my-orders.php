<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */
/** @var array<string, mixed> $profile */
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
$pageTitle = 'طلباتي';
?>
<div class="customer-portal">
  <?php require __DIR__ . '/partials/customer-portal-hero.php'; ?>

  <?php if ($flash): ?>
    <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>">
      <?= h($flash) ?>
    </p>
  <?php endif; ?>

  <?php if ($orderDetails !== null): ?>
    <a href="/my-orders.php<?= $statusFilter !== '' ? '?status=' . rawurlencode($statusFilter) : '' ?>" class="customer-back-link">
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
            $rowShowsPrices = customer_order_shows_prices($rowStatus);
            $rowId = (string) ($row['id'] ?? '');
            $detailUrl = '/my-orders.php?order=' . rawurlencode($rowId)
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
              <?php if ($rowShowsPrices && (float) ($row['total_sp'] ?? 0) > 0): ?>
                <span>الإجمالي: <strong class="store-num" dir="ltr"><?= format_money((float) ($row['total_sp'] ?? 0), true) ?> ل.س</strong></span>
              <?php elseif (!$rowShowsPrices && $rowStatus === 'pending'): ?>
                <span class="text-amber-800 text-sm">يُحدد عند التأكيد</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
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
