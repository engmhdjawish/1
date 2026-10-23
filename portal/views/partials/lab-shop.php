<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $labShopBlocks resolved by lab_shop_blocks_resolve() */
/** @var string $labShopPlacement 'tabs' or 'band' */

$labShopPlacement = ($labShopPlacement ?? 'tabs') === 'band' ? 'band' : 'tabs';
$labShopList = array_values(array_filter(
    is_array($labShopBlocks ?? null) ? $labShopBlocks : [],
    static fn (array $block): bool => ($block['placement'] ?? 'tabs') === $labShopPlacement
));
if ($labShopList === []) {
    return;
}

if (!isset($labShopRenderTiles)) {
    $labShopRenderTiles = static function (array $tiles, string $style, int $max): void {
        $style = in_array($style, ['icons', 'chips', 'cards'], true) ? $style : 'icons';
        $extra = max(0, count($tiles) - $max);
        ?>
        <div class="lab-shop__<?= h($style) ?>">
          <?php foreach ($tiles as $i => $tile): ?>
            <?php $tileClass = $i >= $max ? ' is-extra' : ''; ?>
            <?php if ($style === 'chips'): ?>
              <a class="lab-shop-chip<?= $tileClass ?>" href="<?= h($tile['href']) ?>"><?= h($tile['label']) ?></a>
            <?php elseif ($style === 'cards'): ?>
              <a class="lab-shop-card<?= $tileClass ?>" href="<?= h($tile['href']) ?>">
                <span class="lab-shop-card__media" aria-hidden="true">
                  <?php if (($tile['image'] ?? '') !== ''): ?>
                    <img src="<?= h($tile['image']) ?>" alt="" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="lab-shop-card__initial"><?= h($tile['initial']) ?></span>
                  <?php endif; ?>
                </span>
                <span class="lab-shop-card__label"><?= h($tile['label']) ?></span>
              </a>
            <?php else: ?>
              <a class="lab-shop-tile<?= $tileClass ?>" href="<?= h($tile['href']) ?>">
                <span class="lab-shop-tile__icon" aria-hidden="true">
                  <?php if (($tile['image'] ?? '') !== ''): ?>
                    <img src="<?= h($tile['image']) ?>" alt="" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="material-symbols-outlined"><?= h($tile['icon']) ?></span>
                  <?php endif; ?>
                </span>
                <span class="lab-shop-tile__label"><?= h($tile['label']) ?></span>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if ($extra > 0): ?>
          <button type="button" class="lab-shop__more" data-lab-shop-more aria-expanded="false" data-label-more="عرض الكل (<?= (int) count($tiles) ?>)" data-label-less="عرض أقل">عرض الكل (<?= (int) count($tiles) ?>)</button>
        <?php endif; ?>
        <?php
    };
}

if (!isset($labShopRenderBody)) {
    $labShopRenderBody = static function (array $block) use ($labShopRenderTiles): void {
        if (($block['type'] ?? '') !== 'combo') {
            $labShopRenderTiles($block['tiles'], (string) $block['style'], (int) $block['max']);

            return;
        }
        $groups = $block['groups'];
        ?>
        <div class="lab-shop__combo" data-lab-shop-combo>
          <div class="lab-shop__picks">
            <?php foreach ($groups as $i => $group): ?>
              <button
                type="button"
                class="lab-shop__pick<?= $i === 0 ? ' is-active' : '' ?>"
                aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"
                data-lab-shop-pick="<?= (int) $i ?>"
              >
                <span class="lab-shop__pick-initial" aria-hidden="true"><?= h($group['initial']) ?></span>
                <span><?= h($group['label']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <?php foreach ($groups as $i => $group): ?>
            <div class="lab-shop__group" data-lab-shop-group="<?= (int) $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
              <?php $labShopRenderTiles($group['tiles'], (string) $block['style'], (int) $block['max']); ?>
              <a class="lab-shop__all" href="<?= h($group['all']['href']) ?>">
                <span>عرض <?= h($group['all']['label']) ?></span>
                <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
        <?php
    };
}
?>
<?php if ($labShopPlacement === 'tabs'): ?>
  <section class="lab-shop" aria-labelledby="lab-shop-title" data-lab-shop>
    <div class="lab-shop__head">
      <h2 id="lab-shop-title" class="lab-shop__title">تسوّق حسب</h2>
      <?php if (count($labShopList) > 1): ?>
        <div class="lab-shop__tabs" role="tablist" aria-labelledby="lab-shop-title">
          <?php foreach ($labShopList as $i => $block): ?>
            <button
              type="button"
              role="tab"
              id="<?= h($block['key']) ?>-tab"
              class="lab-shop__tab<?= $i === 0 ? ' is-active' : '' ?>"
              aria-controls="<?= h($block['key']) ?>"
              aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
              tabindex="<?= $i === 0 ? '0' : '-1' ?>"
              data-lab-shop-tab
            ><?= h($block['title']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php foreach ($labShopList as $i => $block): ?>
      <div
        class="lab-shop__panel"
        id="<?= h($block['key']) ?>"
        <?= count($labShopList) > 1 ? 'role="tabpanel" aria-labelledby="' . h($block['key']) . '-tab"' : '' ?>
        <?= $i === 0 ? '' : 'hidden' ?>
      >
        <?php $labShopRenderBody($block); ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php else: ?>
  <?php foreach ($labShopList as $block): ?>
    <section class="lab-shop lab-shop--band" aria-labelledby="<?= h($block['key']) ?>-title">
      <div class="lab-shop__head">
        <h2 id="<?= h($block['key']) ?>-title" class="lab-shop__title"><?= h($block['title']) ?></h2>
      </div>
      <?php $labShopRenderBody($block); ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
