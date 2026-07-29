<?php

declare(strict_types=1);

/** @var string $context card|cart|preview|inline */
/** @var string|null $redirect */

$context = trim((string) ($context ?? 'card'));
if ($context === '') {
    $context = 'card';
}
$redirect = isset($redirect) && trim((string) $redirect) !== ''
    ? (string) $redirect
    : portal_request_path();
$priceLockHint = portal_price_lock_hint($redirect);
?>
<div class="store-price-hidden store-price-hidden--<?= h($context) ?>" role="note">
  <span class="store-price-hidden__label" aria-hidden="true">
    <span class="material-symbols-outlined">lock</span>
    <span>سعر مخفي</span>
  </span>
  <?php if (($priceLockHint['href'] ?? null) !== null): ?>
    <a href="<?= h((string) $priceLockHint['href']) ?>" class="store-price-hidden__link"><?= h((string) $priceLockHint['message']) ?></a>
  <?php else: ?>
    <span class="store-price-hidden__note"><?= h((string) $priceLockHint['message']) ?></span>
  <?php endif; ?>
</div>
