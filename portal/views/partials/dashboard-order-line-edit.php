<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var array<string, mixed> $orderDetails */
/** @var bool $canManageOrders */
/** @var string $orderId */

$canManageOrders = (bool) ($canManageOrders ?? false);
$orderId = (string) ($orderId ?? ($orderDetails['id'] ?? ''));
$editable = $canManageOrders && !empty($orderDetails['can_staff_edit']) && empty($item['is_cancelled']);
$itemId = (string) ($item['id'] ?? '');
$showPriceSyp = (float) ($orderDetails['total_sp'] ?? 0) > 0;
$showPriceUsd = !$showPriceSyp && (float) ($orderDetails['total_usd'] ?? 0) > 0;
?>
<div class="dashboard-order-line">
  <?php require __DIR__ . '/store-order-line-card.php'; ?>

  <?php if ($editable && $itemId !== ''): ?>
    <details class="dashboard-order-line__edit">
      <summary class="dashboard-order-line__edit-toggle">
        <span class="material-symbols-outlined text-sm" aria-hidden="true">edit</span>
        تعديل الصنف
      </summary>
      <div class="dashboard-order-line__edit-body">
        <form method="post" class="dashboard-order-line__form" data-dashboard-ajax>
          <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
          <input type="hidden" name="item_id" value="<?= h($itemId) ?>">
          <input type="hidden" name="item_action" value="update_qty">
          <label class="dashboard-order-line__field">
            <span>الكمية (طرد)</span>
            <input type="number" name="quantity" min="0.01" step="0.01" value="<?= h(format_packages_display((float) ($item['quantity'] ?? 1))) ?>" class="store-num" dir="ltr" required>
          </label>
          <label class="dashboard-order-line__field dashboard-order-line__field--wide">
            <span>سبب التعديل</span>
            <input type="text" name="reason_ar" maxlength="500" placeholder="مثال: نقص مخزون" required>
          </label>
          <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg bg-primary text-white text-xs font-bold">حفظ الكمية</button>
        </form>

        <form method="post" class="dashboard-order-line__form" data-dashboard-ajax>
          <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
          <input type="hidden" name="item_id" value="<?= h($itemId) ?>">
          <input type="hidden" name="item_action" value="update_price">
          <?php if ($showPriceSyp): ?>
            <label class="dashboard-order-line__field">
              <span>سعر الطرد (ل.س)</span>
              <input type="number" name="sale_price_sp" min="0" step="1" value="<?= h((string) (int) ($item['sale_price_sp'] ?? 0)) ?>" class="store-num" dir="ltr">
            </label>
          <?php endif; ?>
          <?php if ($showPriceUsd): ?>
            <label class="dashboard-order-line__field">
              <span>سعر الطرد ($)</span>
              <input type="number" name="sale_price_usd" min="0" step="0.01" value="<?= h(number_format((float) ($item['sale_price_usd'] ?? 0), 2, '.', '')) ?>" class="store-num" dir="ltr">
            </label>
          <?php endif; ?>
          <label class="dashboard-order-line__field dashboard-order-line__field--wide">
            <span>سبب تعديل السعر</span>
            <input type="text" name="reason_ar" maxlength="500" placeholder="مثال: اتفاق خاص مع العميل" required>
          </label>
          <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg border border-border-subtle text-xs font-bold">حفظ السعر</button>
        </form>

        <form method="post" class="dashboard-order-line__form dashboard-order-line__form--danger" data-dashboard-ajax data-confirm="إلغاء هذا الصنف من الطلب؟ سيظهر التغيير للعميل.">
          <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
          <input type="hidden" name="item_id" value="<?= h($itemId) ?>">
          <input type="hidden" name="item_action" value="cancel_item">
          <label class="dashboard-order-line__field dashboard-order-line__field--wide">
            <span>سبب الإلغاء</span>
            <input type="text" name="reason_ar" maxlength="500" placeholder="مثال: غير متوفر في المخزون" required>
          </label>
          <button type="submit" class="dashboard-btn h-8 px-3 rounded-lg bg-red-600 text-white text-xs font-bold">إلغاء الصنف</button>
        </form>
      </div>
    </details>
  <?php endif; ?>
</div>
