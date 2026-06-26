<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StorePolicyService;

/** @var array<string, mixed> $item */
/** @var bool $capturePrices */
/** @var string|null $returnUrl */
/** @var float $cartQtyForItem */
/** @var bool $showQuantity */

$capturePrices = (bool) ($capturePrices ?? false);
$returnUrl = isset($returnUrl) ? (string) $returnUrl : ($_SERVER['REQUEST_URI'] ?? '/store.php');
$cartQtyForItem = max(0.0, (float) ($cartQtyForItem ?? 0));
$showQuantity = (bool) ($showQuantity ?? false);

$materialGuid = material_guid($item);
if ($materialGuid === '') {
    return;
}

$maxPackages = StorePolicyService::maxPackagesPerMaterial();
$maxLabel = $maxPackages !== null ? SpecialOfferService::formatQuantityLabel($maxPackages) : null;
$qtyBounds = store_cart_qty_bounds($item, $cartQtyForItem, $showQuantity);
$remaining = $qtyBounds['effectiveMax'];
$atLimit = $qtyBounds['atLimit'];
$defaultQty = $qtyBounds['defaultQty'];
$qtyStep = $qtyBounds['qtyStep'];
$qtyMin = $qtyBounds['qtyMin'];
$partialPackage = $qtyBounds['partialPackage'];
$stockAvailable = $qtyBounds['stockAvailable'];

$packaging = ShareCartService::packaging($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$unitSaleSp = ShareCartService::unitSalePriceSp($item);
$unitSaleUsd = ShareCartService::unitSalePriceUsd($item);
$imageGuid = material_image_guid($item);
$imageUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '';
?>
<form
  method="post"
  class="store-add-cart"
  action="#"
  data-store-add-cart="1"
  data-material-guid="<?= h($materialGuid) ?>"
  data-cart-qty="<?= h((string) $cartQtyForItem) ?>"
  <?php if ($maxPackages !== null): ?>
    data-max-qty="<?= h((string) $maxPackages) ?>"
    data-max-qty-label="<?= h((string) $maxLabel) ?>"
  <?php endif; ?>
  <?php if ($remaining !== null): ?>
    data-effective-max="<?= h((string) $remaining) ?>"
  <?php endif; ?>
  data-qty-step="<?= h((string) $qtyStep) ?>"
>
  <input type="hidden" name="action" value="add_to_cart">
  <?php if ($returnUrl !== ''): ?>
    <input type="hidden" name="return" value="<?= h($returnUrl) ?>">
  <?php endif; ?>
  <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
  <input type="hidden" name="material_code" value="<?= h((string) ($item['materialCode'] ?? $item['code'] ?? '')) ?>">
  <input type="hidden" name="material_name_ar" value="<?= h((string) ($item['name'] ?? 'مادة')) ?>">
  <input type="hidden" name="primary_unit" value="<?= h($primaryUnit) ?>">
  <input type="hidden" name="package_unit" value="<?= h($packageUnit) ?>">
  <input type="hidden" name="packaging" value="<?= h((string) $packaging) ?>">
  <input type="hidden" name="unit_sale_price_sp" value="<?= h((string) $unitSaleSp) ?>">
  <input type="hidden" name="unit_sale_price_usd" value="<?= h((string) $unitSaleUsd) ?>">
  <?php if ($imageUrl !== ''): ?>
    <input type="hidden" name="image_url" value="<?= h($imageUrl) ?>">
  <?php endif; ?>

  <?php if ($partialPackage && $stockAvailable !== null && $stockAvailable > 0): ?>
    <p class="store-add-cart__limit" data-qty-hint>
      متوفر أقل من طرد كامل: <span class="store-num" dir="ltr"><?= h(format_packages_display($stockAvailable)) ?></span> <?= h($packageUnit) ?> — يمكن طلب الكمية المتبقية.
    </p>
  <?php elseif ($maxLabel !== null): ?>
    <p class="store-add-cart__limit <?= $atLimit ? 'is-warning' : '' ?>" data-qty-hint>
      <?php if ($atLimit): ?>
        وصلت للحد الأقصى (<?= h($maxLabel) ?> <?= h($packageUnit) ?>)
      <?php elseif ($cartQtyForItem > 0): ?>
        الحد الأقصى <?= h($maxLabel) ?> <?= h($packageUnit) ?> — متبقي <span class="store-num" dir="ltr"><?= h(format_packages_display((float) $remaining)) ?></span>
      <?php else: ?>
        الحد الأقصى <?= h($maxLabel) ?> <?= h($packageUnit) ?> لكل مادة
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <div class="store-add-cart__row">
    <span class="text-xs font-bold text-gray-600 shrink-0"><?= h($packageUnit) ?></span>
    <div class="store-qty-stepper">
      <button type="button" data-qty-minus aria-label="إنقاص">−</button>
      <input
        type="number"
        name="quantity"
        class="store-num"
        dir="ltr"
        min="<?= h((string) $qtyMin) ?>"
        <?php if ($remaining !== null && $remaining > 0): ?>max="<?= h((string) $remaining) ?>"<?php elseif ($atLimit): ?>max="<?= h((string) $qtyMin) ?>"<?php endif; ?>
        step="<?= h((string) $qtyStep) ?>"
        value="<?= h((string) $defaultQty) ?>"
        <?= $atLimit ? 'disabled' : '' ?>
      >
      <button type="button" data-qty-plus aria-label="زيادة" <?= ($atLimit || ($remaining !== null && $remaining <= 0)) ? 'disabled' : '' ?>>+</button>
    </div>
  </div>
  <button
    type="submit"
    class="store-add-cart__submit"
    <?= $atLimit ? 'disabled' : '' ?>
  >
    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
    <?= $atLimit ? 'الحد الأقصى مكتمل' : ($partialPackage ? 'طلب الكمية المتاحة' : 'إضافة للسلة') ?>
  </button>
</form>
