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
$inCart = $cartQtyForItem > 0;
$canAdjustInCart = $inCart && !$partialPackage && !$atLimit;

$packaging = ShareCartService::packaging($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$primaryUnitsAvailable = ($partialPackage && $stockAvailable !== null && $packaging > 0)
    ? max(0.0, round($stockAvailable * $packaging, 4))
    : null;
$unitSaleSp = ShareCartService::unitSalePriceSp($item);
$unitSaleUsd = ShareCartService::unitSalePriceUsd($item);
$imageGuid = material_image_guid($item);
$imageUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '';

$cartMode = $inCart
    ? ($partialPackage || $atLimit ? 'in-cart-locked' : 'in-cart')
    : ($partialPackage ? 'partial-add' : 'add');
?>
<form
  method="post"
  class="store-add-cart<?= $inCart ? ' store-add-cart--in-cart' : '' ?><?= ($partialPackage || $atLimit) ? ' store-add-cart--locked' : '' ?>"
  action="#"
  data-store-add-cart="1"
  data-cart-mode="<?= h($cartMode) ?>"
  data-partial-package="<?= $partialPackage ? '1' : '0' ?>"
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
  data-package-unit="<?= h($packageUnit) ?>"
  data-primary-unit="<?= h($primaryUnit) ?>"
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

  <div class="store-add-cart__in-cart"<?= $inCart ? '' : ' hidden' ?>>
    <?php if ($partialPackage && $primaryUnitsAvailable !== null): ?>
      <p class="store-add-cart__note" data-qty-hint-partial>
        آخر كمية: <span class="store-num" dir="ltr"><?= h(format_packages_display((float) $stockAvailable)) ?></span> <?= h($packageUnit) ?>
        <span class="store-add-cart__note-sep">·</span>
        <span class="store-num" dir="ltr"><?= h(format_packages_display($primaryUnitsAvailable)) ?></span> <?= h($primaryUnit) ?>
      </p>
    <?php endif; ?>

    <div class="store-card-cart-bar">
      <span class="store-card-cart-bar__status">
        <span class="material-symbols-outlined text-base" aria-hidden="true">shopping_cart</span>
        <span>في السلة</span>
      </span>

      <div class="store-card-cart-bar__qty">
        <div class="store-card-cart-bar__qty-locked" data-cart-qty-locked<?= $canAdjustInCart ? ' hidden' : '' ?>>
          <strong class="store-num" dir="ltr" data-cart-qty-display><?= h(format_packages_display($cartQtyForItem)) ?></strong>
        </div>

        <div class="store-qty-stepper store-qty-stepper--inline store-qty-stepper--cart" data-cart-qty-adjust<?= $canAdjustInCart ? '' : ' hidden' ?>>
          <button type="button" data-cart-bump="-1" aria-label="إنقاص أو حذف من السلة">−</button>
          <output class="store-num" dir="ltr" data-cart-qty-display><?= h(format_packages_display($cartQtyForItem)) ?></output>
          <button type="button" data-cart-bump="1" aria-label="زيادة"<?= ($remaining !== null && $remaining <= 0) ? ' disabled' : '' ?>>+</button>
        </div>
      </div>

      <span class="store-card-cart-bar__unit"><?= h($packageUnit) ?></span>
    </div>
  </div>

  <div class="store-add-cart__add"<?= $inCart ? ' hidden' : '' ?>>
    <?php if ($partialPackage && $primaryUnitsAvailable !== null): ?>
      <p class="store-add-cart__note" data-qty-hint>
        آخر كمية: <span class="store-num" dir="ltr"><?= h(format_packages_display((float) $stockAvailable)) ?></span> <?= h($packageUnit) ?>
        <span class="store-add-cart__note-sep">·</span>
        <span class="store-num" dir="ltr"><?= h(format_packages_display($primaryUnitsAvailable)) ?></span> <?= h($primaryUnit) ?>
      </p>
    <?php elseif ($maxLabel !== null): ?>
      <p class="store-add-cart__note<?= $atLimit ? ' is-warning' : '' ?>" data-qty-hint>
        الحد الأقصى <?= h($maxLabel) ?> <?= h($packageUnit) ?> لكل مادة
      </p>
    <?php endif; ?>

    <div class="store-card-cart-bar store-card-cart-bar--add">
      <div class="store-qty-stepper store-qty-stepper--inline store-qty-stepper--cart<?= $partialPackage ? ' store-qty-stepper--locked' : '' ?>">
        <button type="button" data-qty-minus aria-label="إنقاص"<?= $partialPackage ? ' disabled' : '' ?>>−</button>
        <input
          type="number"
          name="quantity"
          class="store-num"
          dir="ltr"
          min="<?= h((string) $qtyMin) ?>"
          <?php if ($remaining !== null && $remaining > 0): ?>max="<?= h((string) $remaining) ?>"<?php elseif ($atLimit): ?>max="<?= h((string) $qtyMin) ?>"<?php endif; ?>
          step="<?= h((string) $qtyStep) ?>"
          value="<?= h((string) $defaultQty) ?>"
          <?= $partialPackage ? 'readonly' : '' ?>
        >
        <button type="button" data-qty-plus aria-label="زيادة"<?= ($partialPackage || ($remaining !== null && $remaining <= 0)) ? ' disabled' : '' ?>>+</button>
      </div>
      <span class="store-card-cart-bar__unit"><?= h($packageUnit) ?></span>
      <button type="submit" class="store-add-cart__submit" <?= $atLimit ? 'disabled' : '' ?>>
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
        <?= $atLimit ? 'مكتمل' : ($partialPackage ? 'طلب الكمية' : 'إضافة') ?>
      </button>
    </div>
  </div>
</form>
