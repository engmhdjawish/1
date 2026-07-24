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
$packagingLabel = format_packaging($prices['packaging']) . ' ' . $prices['primary_unit'] . ' / ' . $prices['package_unit'];
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
    || $prices['unit_sp'] > 0
    || $prices['unit_usd'] > 0
    || $prices['line_total_sp'] > 0
    || $prices['line_total_usd'] > 0
);
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
      <button type="button" class="dash-order-item__thumb" data-store-product-preview title="معاينة الصورة والتنقل بين الأصناف">
        <img src="<?= h($imageUrl) ?>" alt="" loading="lazy" decoding="async">
        <span class="dash-order-item__thumb-overlay">
          <span class="material-symbols-outlined" aria-hidden="true">zoom_in</span>
          <span>معاينة</span>
        </span>
      </button>
    <?php else: ?>
      <div class="dash-order-item__placeholder" aria-hidden="true">
        <span class="material-symbols-outlined">inventory_2</span>
      </div>
    <?php endif; ?>
  </div>

  <div class="dash-order-item__content">
    <div class="dash-order-item__header">
      <div class="dash-order-item__identity min-w-0">
        <div class="dash-order-item__badges">
          <?php if ($isCancelled): ?>
            <span class="dash-order-item__badge dash-order-item__badge--cancelled">ملغى</span>
          <?php elseif ($hasOffer): ?>
            <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
          <?php endif; ?>
        </div>
        <h4 class="dash-order-item__name"><?= h($materialName !== '' ? $materialName : '—') ?></h4>
        <?php if ($materialCode !== ''): ?>
          <div class="dash-order-item__code">
            <span class="dash-order-item__code-label">رقم المادة</span>
            <code class="dash-order-item__code-value store-num" dir="ltr"><?= h($materialCode) ?></code>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($showPrices): ?>
        <div class="dash-order-item__line-total">
          <span class="dash-order-item__line-total-label">إجمالي الصنف</span>
          <strong class="dash-order-item__line-total-value store-num" dir="ltr"><?= h($lineTotalLabel) ?></strong>
        </div>
      <?php endif; ?>
    </div>

    <dl class="dash-order-item__specs">
      <div class="dash-order-item__spec">
        <dt>الكمية</dt>
        <dd class="store-num" dir="ltr"><?= h(format_packages_display($prices['quantity'])) ?> <?= h($prices['package_unit']) ?></dd>
      </div>
      <?php if ($prices['packaging'] > 0): ?>
        <div class="dash-order-item__spec">
          <dt>التعبئة</dt>
          <dd><?= h($packagingLabel) ?></dd>
        </div>
      <?php endif; ?>
      <?php if ($showPrices && ($prices['pack_sp'] > 0 || $prices['pack_usd'] > 0)): ?>
        <div class="dash-order-item__spec">
          <dt>سعر <?= h($prices['package_unit']) ?></dt>
          <dd class="store-num" dir="ltr"><?= h($packPriceLabel) ?></dd>
        </div>
      <?php endif; ?>
      <?php if ($showPrices && ($prices['unit_sp'] > 0 || $prices['unit_usd'] > 0)): ?>
        <div class="dash-order-item__spec">
          <dt>سعر <?= h($prices['primary_unit']) ?></dt>
          <dd class="store-num" dir="ltr"><?= h($unitPriceLabel) ?></dd>
        </div>
      <?php endif; ?>
    </dl>
  </div>
</article>
