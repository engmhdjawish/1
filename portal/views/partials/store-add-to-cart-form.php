<?php

declare(strict_types=1);

use Portal\Services\ShareCartService;

/** @var array<string, mixed> $item */
/** @var bool $capturePrices */
/** @var string|null $returnUrl */

$capturePrices = (bool) ($capturePrices ?? false);
$returnUrl = isset($returnUrl) ? (string) $returnUrl : ($_SERVER['REQUEST_URI'] ?? '/store.php');

$materialGuid = material_guid($item);
if ($materialGuid === '') {
    return;
}

$packaging = ShareCartService::packaging($item);
$primaryUnit = ShareCartService::primaryUnitLabel($item);
$packageUnit = ShareCartService::packageUnitLabel($item);
$unitSaleSp = ShareCartService::unitSalePriceSp($item);
$unitSaleUsd = ShareCartService::unitSalePriceUsd($item);
$imageGuid = material_image_guid($item);
$imageUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '';
?>
<form method="post" class="mt-auto pt-3 border-t border-gray-100 space-y-2" action="<?= h(strtok($returnUrl, '#')) ?>">
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
  <div class="flex items-center justify-between gap-2">
    <label class="text-xs font-bold text-gray-600 shrink-0">عدد <?= h($packageUnit) ?></label>
    <input type="number" name="quantity" min="1" step="1" value="1" class="h-10 w-20 rounded-lg border border-gray-300 px-2 text-center font-bold">
  </div>
  <button type="submit" class="w-full h-11 rounded-lg bg-primary text-white text-sm font-extrabold shadow-md hover:opacity-95 transition inline-flex items-center justify-center gap-2">
    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
    إضافة للسلة
  </button>
</form>
