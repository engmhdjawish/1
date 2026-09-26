<?php

declare(strict_types=1);

/** @var string $context card|cart|preview|inline|detail */
/** @var string|null $redirect */
/** @var string|null $anchor */

$context = trim((string) ($context ?? 'card'));
if ($context === '') {
    $context = 'card';
}
$anchor = trim((string) ($anchor ?? ''));
$redirect = portal_price_lock_return(
    $anchor !== '' ? $anchor : null,
    isset($redirect) && trim((string) $redirect) !== '' ? (string) $redirect : null
);
$priceLockHint = portal_price_lock_hint($redirect);
$priceLockHref = $priceLockHint['href'] ?? null;
$priceLockTag = $priceLockHref !== null ? 'a' : 'div';
?>
<<?= $priceLockTag ?>
  class="store-price-veil store-price-veil--<?= h($context) ?>"
  <?php if ($priceLockHref !== null): ?>
    href="<?= h((string) $priceLockHref) ?>"
  <?php else: ?>
    role="note"
  <?php endif; ?>
>
  <span class="store-price-veil__ghost" aria-hidden="true">
    <span class="store-price-veil__row">
      <span class="store-price-veil__ink"></span>
      <span class="store-price-veil__currency">ل.س</span>
    </span>
    <span class="store-price-veil__row store-price-veil__row--sub">
      <span class="store-price-veil__ink"></span>
      <span class="store-price-veil__currency">ل.س</span>
    </span>
  </span>
  <?php if ($priceLockHref !== null): ?>
    <span class="store-price-veil__cta">
      <span class="material-symbols-outlined" aria-hidden="true">lock_open</span>
      <span><?= h((string) $priceLockHint['message']) ?></span>
    </span>
  <?php else: ?>
    <span class="store-price-veil__note"><?= h((string) $priceLockHint['message']) ?></span>
  <?php endif; ?>
</<?= $priceLockTag ?>>
