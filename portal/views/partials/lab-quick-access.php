<?php

declare(strict_types=1);

/** @var list<array{label: string, icon: string, href: string, tone: string, external?: bool}> $quickAccessItems */

$quickAccessItems = is_array($quickAccessItems ?? null) ? $quickAccessItems : [];
if ($quickAccessItems === []) {
    return;
}
?>
<nav class="lab-quick" aria-label="وصول سريع">
  <?php foreach ($quickAccessItems as $item): ?>
    <a
      href="<?= h($item['href']) ?>"
      class="lab-quick__item lab-quick__item--<?= h($item['tone']) ?>"
      <?= !empty($item['external']) ? 'target="_blank" rel="noopener"' : '' ?>
    >
      <span class="lab-quick__icon material-symbols-outlined" aria-hidden="true"><?= h($item['icon']) ?></span>
      <span class="lab-quick__label"><?= h($item['label']) ?></span>
    </a>
  <?php endforeach; ?>
</nav>
