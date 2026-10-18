<?php

declare(strict_types=1);

/** @var string $shopTilesTitle */
/** @var list<array{label: string, href: string, icon: string, active: bool}> $shopTiles */

$shopTiles = is_array($shopTiles ?? null) ? $shopTiles : [];
if (count($shopTiles) < 2) {
    return;
}
$shopTilesTitle = trim((string) ($shopTilesTitle ?? '')) ?: 'تسوّق حسب الفئة';
?>
<section class="se-shelf" aria-labelledby="se-shelf-title">
  <h2 id="se-shelf-title" class="se-shelf__title"><?= h($shopTilesTitle) ?></h2>
  <div class="se-shelf__row">
    <?php foreach ($shopTiles as $tile): ?>
      <a
        href="<?= h($tile['href']) ?>"
        class="se-tile<?= $tile['active'] ? ' is-active' : '' ?>"
        <?= $tile['active'] ? 'aria-current="true"' : '' ?>
      >
        <span class="se-tile__icon material-symbols-outlined" aria-hidden="true"><?= h($tile['icon']) ?></span>
        <span class="se-tile__label"><?= h($tile['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
