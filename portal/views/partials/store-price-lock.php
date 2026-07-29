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
$loginUrl = portal_login_url('customer', $redirect);
?>
<div class="store-price-hidden store-price-hidden--<?= h($context) ?>" role="note">
  <span class="store-price-hidden__label" aria-hidden="true">
    <span class="material-symbols-outlined">lock</span>
    <span>سعر مخفي</span>
  </span>
  <a href="<?= h($loginUrl) ?>" class="store-price-hidden__link">سجّل الدخول لعرض السعر</a>
</div>
