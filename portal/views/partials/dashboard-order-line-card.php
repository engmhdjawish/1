<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var array<string, mixed> $previewPayload */
/** @var string $previewGuid */

$showPriceSyp = (bool) ($showPriceSyp ?? false);
$showPriceUsd = (bool) ($showPriceUsd ?? true);
$isCancelled = !empty($item['is_cancelled']) || (string) ($item['status'] ?? '') === 'cancelled';
$prices = store_order_line_prices($item);
$hasOffer = !$isCancelled && store_line_has_offer($item);
$imageUrl = trim((string) ($item['image_url'] ?? ''));
$materialCode = trim((string) ($item['material_code'] ?? ''));
$materialName = trim((string) ($item['material_name_ar'] ?? ''));
$previewGuid = trim((string) ($previewGuid ?? ($item['material_guid'] ?? $item['id'] ?? '')));
$previewJson = json_encode($previewPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$formatUsd = static fn (float $amount): string => '$' . number_format($amount, 2, '.', ',');
$packPriceLabel = $showPriceSyp
    ? format_money($prices['pack_sp'], true) . ' ل.س'
    : $formatUsd($prices['pack_usd']);
$unitPriceLabel = $showPriceSyp
    ? format_money($prices['unit_sp'], true) . ' ل.س'
    : $formatUsd($prices['unit_usd']);
$lineTotalLabel = $showPriceSyp
    ? format_money($prices['line_total_sp'], true) . ' ل.س'
    : $formatUsd($prices['line_total_usd']);
$showPrices = !$isCancelled && ($showPriceSyp || $showPriceUsd) && (
    $prices['pack_sp'] > 0
    || $prices['pack_usd'] > 0
    || $prices['line_total_sp'] > 0
    || $prices['line_total_usd'] > 0
);
$hasPackPrice = $showPrices && ($prices['pack_sp'] > 0 || $prices['pack_usd'] > 0);
$hasUnitPrice = $showPrices && ($prices['unit_sp'] > 0 || $prices['unit_usd'] > 0);
$qtyLabel = format_packages_display($prices['quantity']) . ' ' . $prices['package_unit'];
$showLineTotal = $showPrices && $prices['quantity'] > 1.009;
?>
<article
  class="dash-order-item<?= $hasOffer ? ' dash-order-item--offer' : '' ?><?= $isCancelled ? ' dash-order-item--cancelled' : '' ?>"
  data-store-preview-card
  data-store-order-preview-line
  data-preview-guid="<?= h($previewGuid) ?>"
  data-preview="<?= h((string) $previewJson) ?>"
>
  <div class="dash-order-item__media">
    <?php if ($imageUrl !== ''): ?>
      <button type="button" class="dash-order-item__thumb" data-store-product-preview title="معاينة الصورة">
        <img src="<?= h($imageUrl) ?>" alt="" loading="lazy" decoding="async">
      </button>
    <?php else: ?>
      <div class="dash-order-item__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">inventory_2</span>
      </div>
    <?php endif; ?>
  </div>

  <div class="dash-order-item__body">
    <div class="dash-order-item__row">
      <div class="dash-order-item__info min-w-0">
        <div class="dash-order-item__title-row">
          <?php if ($isCancelled): ?>
            <span class="dash-order-item__badge dash-order-item__badge--cancelled">ملغى</span>
          <?php elseif ($hasOffer): ?>
            <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
          <?php endif; ?>
          <h4 class="dash-order-item__name"><?= h($materialName !== '' ? $materialName : '—') ?></h4>
        </div>
        <?php if ($materialCode !== ''): ?>
          <p class="dash-order-item__meta"><span class="store-num" dir="ltr">#<?= h($materialCode) ?></span></p>
        <?php endif; ?>
      </div>
      <?php if ($showPrices || $prices['quantity'] > 0): ?>
        <div class="dash-order-item__pricing">
          <span class="dash-order-item__qty store-num" dir="ltr"><?= h($qtyLabel) ?></span>
          <?php if ($hasPackPrice): ?>
            <strong class="dash-order-item__pack-price store-num" dir="ltr"><?= h($packPriceLabel) ?></strong>
          <?php endif; ?>
          <?php if ($hasUnitPrice): ?>
            <span class="dash-order-item__unit-price store-num" dir="ltr"><?= h($unitPriceLabel) ?> <span class="dash-order-item__unit-label">/ <?= h($prices['primary_unit']) ?></span></span>
          <?php endif; ?>
          <?php if ($showLineTotal): ?>
            <span class="dash-order-item__line-total store-num" dir="ltr"><?= h($lineTotalLabel) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</article>
