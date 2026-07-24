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
$isCancelled = !empty($item['is_cancelled']) || (string) ($item['status'] ?? '') === 'cancelled';
$showPriceSyp = (string) ($orderPriceCurrency ?? 'usd') === 'syp';
$showPriceUsd = (string) ($orderPriceCurrency ?? 'usd') === 'usd';
$previewPayload = order_preview_payload($item, [
    'show_price' => !$isCancelled && ($showPriceSyp || $showPriceUsd),
    'price_mode' => $showPriceSyp ? 'syp' : 'usd',
    'order_id' => $orderId,
    'editable' => $editable,
]);
$previewGuid = trim((string) ($item['material_guid'] ?? ''));
if ($previewGuid === '') {
    $previewGuid = $itemId;
}
?>
<div class="dashboard-order-line">
  <?php require __DIR__ . '/dashboard-order-line-card.php'; ?>

  <?php if ($editable && $itemId !== ''): ?>
    <details class="dashboard-order-line__edit">
      <summary class="dashboard-order-line__edit-toggle">
        <span class="material-symbols-outlined text-sm" aria-hidden="true">edit</span>
        تعديل الصنف
      </summary>
      <div class="dashboard-order-line__edit-body">
        <?php require __DIR__ . '/dashboard-order-line-edit-forms.php'; ?>
      </div>
    </details>
  <?php endif; ?>
</div>
