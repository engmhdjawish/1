<?php

declare(strict_types=1);

/** @var array<string, mixed> $item */
/** @var bool $showPriceSyp */
/** @var bool $showPriceUsd */
/** @var bool $showLineTotal */
/** @var string $lineCardVariant */
/** @var array<string, mixed>|null $previewPayload */
/** @var string $previewGuid */

$showPriceSyp = (bool) ($showPriceSyp ?? true);
$showPriceUsd = (bool) ($showPriceUsd ?? false);
$showLineTotal = (bool) ($showLineTotal ?? true);
$lineCardVariant = (string) ($lineCardVariant ?? 'default');
$isDashboardLine = $lineCardVariant === 'dashboard';
$isCancelled = !empty($item['is_cancelled']) || (string) ($item['status'] ?? '') === 'cancelled';
$prices = store_order_line_prices($item);
$hasOffer = !$isCancelled && store_line_has_offer($item);
$imageUrl = trim((string) ($item['image_url'] ?? ''));
$zoomUrl = $imageUrl !== '' ? material_image_zoom_url($imageUrl) : '';
$materialCode = trim((string) ($item['material_code'] ?? ''));
$packagingLabel = format_packaging($prices['packaging']) . ' ' . $prices['primary_unit'] . ' / ' . $prices['package_unit'];
$previewGuid = trim((string) ($previewGuid ?? ($item['material_guid'] ?? $item['id'] ?? '')));
$previewPayload = $previewPayload ?? null;
$previewJson = is_array($previewPayload)
    ? json_encode($previewPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
    : null;
$useProductPreview = is_string($previewJson) && $previewJson !== '' && $previewGuid !== '';
?>
<article
  class="store-order-line-card<?= $hasOffer ? ' store-order-line-card--offer' : '' ?><?= $isCancelled ? ' store-order-line-card--cancelled' : '' ?><?= $isDashboardLine ? ' store-order-line-card--dashboard' : '' ?>"
  <?php if ($useProductPreview): ?>
    data-store-preview-card
    data-store-order-preview-line
    data-preview-guid="<?= h($previewGuid) ?>"
    data-preview="<?= h($previewJson) ?>"
  <?php endif; ?>
>
  <div class="store-order-line-card__media">
    <?php if ($imageUrl !== ''): ?>
      <button
        type="button"
        class="store-order-line-card__thumb"
        <?php if ($useProductPreview): ?>
          data-store-product-preview
          title="تصفح الصورة"
        <?php else: ?>
          data-cart-image-zoom="<?= h($zoomUrl) ?>"
          title="تكبير الصورة للتدقيق"
        <?php endif; ?>
      >
        <img src="<?= h($imageUrl) ?>" alt="" loading="lazy">
        <span class="store-order-line-card__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span>
      </button>
    <?php else: ?>
      <div class="store-order-line-card__placeholder">
        <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
      </div>
    <?php endif; ?>
  </div>

  <div class="store-order-line-card__body">
    <div class="store-order-line-card__head">
      <div class="store-order-line-card__head-main">
        <?php if ($isCancelled): ?>
          <span class="store-order-line-card__cancelled-badge">ملغى</span>
        <?php elseif ($hasOffer): ?>
          <?php $badge = store_line_offer_badge($item); $size = 'sm'; require __DIR__ . '/offer-item-badge.php'; ?>
        <?php endif; ?>
        <h3 class="store-order-line-card__title"><?= h((string) ($item['material_name_ar'] ?? '—')) ?></h3>
        <?php if ($isDashboardLine && $materialCode !== ''): ?>
          <div class="store-order-line-card__code-row">
            <span class="store-order-line-card__code-label">رقم المادة</span>
            <span class="store-order-line-card__code store-num" dir="ltr"><?= h($materialCode) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($isDashboardLine && $prices['packaging'] > 1): ?>
          <p class="store-order-line-card__packaging"><?= h($packagingLabel) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="store-order-line-card__meta">
      <?php if (!$isDashboardLine && $materialCode !== ''): ?>
        <span class="store-order-line-card__code store-num" dir="ltr"><?= h($materialCode) ?></span>
      <?php endif; ?>
      <span class="store-order-line-card__qty">
        <?php if ($isDashboardLine): ?>
          <span class="store-order-line-card__qty-label">الكمية</span>
        <?php endif; ?>
        <span class="store-num" dir="ltr"><?= h(format_packages_display($prices['quantity'])) ?></span>
        <?= h($prices['package_unit']) ?>
      </span>
    </div>

    <div class="store-order-line-card__foot">
      <?php if (!$isCancelled && ($showPriceSyp || $showPriceUsd)): ?>
        <?php $size = 'compact'; require __DIR__ . '/store-order-line-prices.php'; ?>
      <?php endif; ?>

      <?php if (!$isCancelled && $showLineTotal && ($showPriceSyp || $showPriceUsd)): ?>
        <div class="store-order-line-card__total">
          <span>الإجمالي</span>
          <strong class="store-num" dir="ltr">
            <?php if ($showPriceSyp): ?>
              <?= format_money($prices['line_total_sp'], true) ?> ل.س
            <?php else: ?>
              $<?= number_format($prices['line_total_usd'], 2, '.', ',') ?>
            <?php endif; ?>
          </strong>
        </div>
      <?php endif; ?>
    </div>
  </div>
</article>
