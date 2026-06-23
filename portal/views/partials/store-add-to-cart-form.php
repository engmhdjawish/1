<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StorePolicyService;

/** @var array<string, mixed> $item */
/** @var bool $capturePrices */
/** @var string|null $returnUrl */
/** @var int $cartQtyForItem */

$capturePrices = (bool) ($capturePrices ?? false);
$returnUrl = isset($returnUrl) ? (string) $returnUrl : ($_SERVER['REQUEST_URI'] ?? '/store.php');
$cartQtyForItem = max(0, (int) ($cartQtyForItem ?? 0));

$materialGuid = material_guid($item);
if ($materialGuid === '') {
    return;
}

$maxPackages = StorePolicyService::maxPackagesPerMaterial();
$maxLabel = $maxPackages !== null ? SpecialOfferService::formatQuantityLabel($maxPackages) : null;
$remaining = $maxPackages !== null ? max(0, (int) floor($maxPackages - $cartQtyForItem)) : null;
$atLimit = $maxPackages !== null && $remaining <= 0;

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
  action="<?= h(strtok($returnUrl, '#')) ?>"
  data-store-add-cart="1"
  data-material-guid="<?= h($materialGuid) ?>"
  data-cart-qty="<?= (int) $cartQtyForItem ?>"
  <?php if ($maxPackages !== null): ?>
    data-max-qty="<?= h((string) $maxPackages) ?>"
    data-max-qty-label="<?= h((string) $maxLabel) ?>"
  <?php endif; ?>
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

  <?php if ($maxLabel !== null): ?>
    <p class="store-add-cart__limit <?= $atLimit ? 'is-warning' : '' ?>" data-qty-hint>
      <?php if ($atLimit): ?>
        وصلت للحد الأقصى (<?= h($maxLabel) ?> <?= h($packageUnit) ?>)
      <?php elseif ($cartQtyForItem > 0): ?>
        الحد الأقصى <?= h($maxLabel) ?> <?= h($packageUnit) ?> — متبقي <?= (int) $remaining ?>
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
        min="1"
        <?php if ($remaining !== null && $remaining > 0): ?>max="<?= (int) $remaining ?>"<?php elseif ($atLimit): ?>max="1"<?php endif; ?>
        step="1"
        value="1"
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
    <?= $atLimit ? 'الحد الأقصى مكتمل' : 'إضافة للسلة' ?>
  </button>
</form>
