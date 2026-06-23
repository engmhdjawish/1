<?php

declare(strict_types=1);

/** @var string $badge */
/** @var string $size sm|md */

$badge = trim((string) ($badge ?? ''));
if ($badge === '') {
    $badge = 'عرض خاص';
}
$size = in_array(($size ?? 'sm'), ['sm', 'md'], true) ? (string) $size : 'sm';
?>
<span class="store-offer-badge store-offer-badge--<?= h($size) ?>">
  <span class="material-symbols-outlined" aria-hidden="true">sell</span>
  <?= h($badge) ?>
</span>
