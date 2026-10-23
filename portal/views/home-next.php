<?php

declare(strict_types=1);

use Portal\Services\HomeLayoutService;
use Portal\Services\HomeShopBlockService;
use Portal\Services\PortalSettingsService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
/** @var array<string, string>|null $companyContext */
/** @var array<string, string>|null $embeddedProductStrips */
/** @var array{show_price: bool, show_quantity: bool, allow_cart: bool, allow_order: bool, show_images: bool, price_mode: string}|null $storeCatalogDisplay */

$ads = is_array($ads ?? null) ? $ads : [];
$sections = is_array($sections ?? null) ? $sections : [];
$embeddedProductStrips = is_array($embeddedProductStrips ?? null) ? $embeddedProductStrips : [];
$storeCatalogDisplay = is_array($storeCatalogDisplay ?? null) ? $storeCatalogDisplay : [];
$company = is_array($companyContext ?? null) ? $companyContext : PortalSettingsService::companySettings();

try {
    $layout = HomeLayoutService::config();
} catch (Throwable) {
    $layout = HomeLayoutService::defaults();
}
$layoutPreview = HomeLayoutService::draftForPreview();
if ($layoutPreview !== null) {
    $layout = $layoutPreview;
}
try {
    $isCustomer = \Portal\Auth\CustomerSession::check();
    $isStaffViewer = !\Portal\Auth\CustomerSession::isLoggedIn() && \Portal\Auth\WebSession::check() && \Portal\Auth\WebSession::hasPermission('orders.view');
} catch (Throwable) {
    $isCustomer = false;
    $isStaffViewer = false;
}
$general = $layout['general'];
$layoutBlocks = HomeLayoutService::visibleBlocks($layout, $isCustomer);
$blockOn = static fn (string $key): bool => in_array($key, array_column($layoutBlocks, 'key'), true);
$blockOf = static fn (string $key): array => HomeLayoutService::block($key, $layout) ?? ['key' => $key, 'on' => false, 'title' => '', 'options' => []];

$allowCart = (bool) ($storeCatalogDisplay['allow_cart'] ?? false);
try {
    $cartPackages = $allowCart ? \Portal\Services\StoreCartService::packageCount() : 0.0;
} catch (Throwable) {
    $cartPackages = 0.0;
}
$whatsapp = preg_replace('/\D+/', '', (string) ($company['company_whatsapp'] ?? '')) ?? '';
$phone = preg_replace('/[^\d+]+/', '', (string) ($company['company_phone'] ?? '')) ?? '';
$companyName = trim((string) ($company['company_name'] ?? ''));


$sectionKey = static fn (array $section): string => (string) ($section['slug'] ?? $section['id'] ?? '');
$sectionTitle = static fn (array $section): string => trim((string) ($section['title_ar'] ?? '')) ?: 'قسم';
$offers = array_values(array_filter($sections, static fn (array $s): bool => !empty($s['is_offer_section'])));
$regularSections = array_values(array_filter($sections, static fn (array $s): bool => empty($s['is_offer_section'])));

$facetLabels = [
    'ageCategories' => 'الفئة',
    'materialTypes' => 'النوع',
    'manufacturers' => 'الشركة',
    'sizeRanges' => 'القياس',
];
$facetIcons = [
    'ageCategories' => 'groups',
    'materialTypes' => 'checkroom',
    'manufacturers' => 'verified',
    'sizeRanges' => 'straighten',
];
$facets = [];
foreach ($facetLabels as $param => $label) {
    $values = store_easy_facet_values($param) ?? [];
    if (count($values) >= 2) {
        $facets[$param] = ['label' => $label, 'values' => $values];
    }
}
$canFilterAvailable = store_easy_client_filter_visible('availability');

try {
    $season = \Portal\Services\StoreSeasonService::customerSeason();
} catch (Throwable) {
    $season = null;
}
$seasonUrl = static fn (array $extra = []): string => store_lab_url(['season' => '1'] + $extra);
$seasonTheme = in_array($season['icon'] ?? '', ['ac_unit', 'umbrella'], true) ? 'cool' : 'warm';

$storyOptions = $blockOf('stories')['options'];
$stories = [];
if ($season !== null && !empty($storyOptions['season'])) {
    $stories[] = ['key' => 'season-' . $season['id'], 'label' => $season['title_ar'], 'href' => $seasonUrl(), 'image' => '', 'icon' => $season['icon'], 'hot' => false, 'season' => true];
}
if (!empty($storyOptions['offers'])) {
    foreach ($offers as $offer) {
        $stories[] = ['key' => 'o-' . $sectionKey($offer), 'label' => $sectionTitle($offer), 'href' => store_lab_section_url($offer), 'image' => (string) ($offer['banner_image_url'] ?? ''), 'icon' => 'local_offer', 'hot' => true];
    }
}
if (!empty($storyOptions['sections'])) {
    foreach ($regularSections as $section) {
        $stories[] = ['key' => 's-' . $sectionKey($section), 'label' => $sectionTitle($section), 'href' => store_lab_section_url($section), 'image' => (string) ($section['banner_image_url'] ?? ''), 'icon' => 'auto_awesome', 'hot' => false];
    }
}
if (!empty($storyOptions['ages'])) {
    foreach ($facets['ageCategories']['values'] ?? [] as $value) {
        $stories[] = ['key' => 'a-' . $value, 'label' => $value, 'href' => store_lab_url(['ageCategories' => [$value]]), 'image' => '', 'icon' => store_easy_tile_icon($value), 'hot' => false];
    }
}
$stories = array_slice($stories, 0, (int) ($storyOptions['max'] ?? 16));

$guideOptions = $blockOf('guide')['options'];
$guideTitles = [
    'ageCategories' => ['title' => 'لمن؟', 'on' => $guideOptions['ages'] ?? true],
    'materialTypes' => ['title' => 'النوع', 'on' => $guideOptions['types'] ?? true],
    'sizeRanges' => ['title' => 'القياس', 'on' => $guideOptions['sizes'] ?? true],
];
$guideSteps = [];
foreach ($guideTitles as $param => $meta) {
    $values = $facets[$param]['values'] ?? [];
    if ($values === [] || !$meta['on']) {
        continue;
    }
    if ($param === 'sizeRanges') {
        usort($values, static fn ($a, $b): int => strnatcasecmp((string) $a, (string) $b));
    }
    $perPage = $param === 'sizeRanges' ? 9 : 6;
    $guideSteps[] = ['param' => $param, 'title' => $meta['title'], 'pages' => array_chunk(array_slice($values, 0, 60), $perPage)];
}

$shopBlocks = [];
if ($blockOn('shop') || $blockOn('bands')) {
    $shopBlocks = array_values(array_filter(
        lab_shop_blocks_resolve(HomeShopBlockService::activeBlocks(), static fn (array $params): string => store_lab_url($params)),
        static fn (array $block): bool => ($block['type'] ?? '') !== 'combo' && $block['tiles'] !== []
    ));
}
$shopTabs = array_values(array_filter($shopBlocks, static fn (array $block): bool => ($block['placement'] ?? 'tabs') !== 'band'));
$shopBands = array_values(array_filter($shopBlocks, static fn (array $block): bool => ($block['placement'] ?? 'tabs') === 'band'));

$showcaseOptions = $blockOf('showcase')['options'];
$showcaseSections = array_slice($regularSections, 0, (int) ($showcaseOptions['limit'] ?? 6));
$showcaseStyle = (string) ($showcaseOptions['style'] ?? 'mosaic');
$showcaseIcons = ['auto_awesome', 'local_mall', 'styler', 'footprint', 'diamond', 'redeem', 'checkroom', 'new_releases'];
$showcaseSpans = [];
if ($showcaseStyle === 'mosaic') {
    $n = count($showcaseSections);
    for ($i = 0; $i < $n; $i++) {
        $spanPhone = $i === 0 || ($i === $n - 1 && ($n - 1) % 2 === 1) ? 2 : 1;
        $spanWide = match (true) {
            $i === 0 => $n === 1 ? 4 : 2,
            $n === 2, $n === 3 => 2,
            $n === 4 => $i === 3 ? 2 : 1,
            $i === $n - 1 && $i >= 5 && ($n - 5) % 4 !== 0 => 5 - (($n - 5) % 4),
            default => 1,
        };
        $showcaseSpans[$i] = '--span-m:' . $spanPhone . ';--span-d:' . $spanWide . ($n === 2 && $i === 1 ? ';--rows-d:2' : '');
    }
}

$sheetPanes = [];
if ($sections !== []) {
    $sheetPanes[] = ['key' => 'sections', 'label' => 'الأقسام', 'icon' => 'grid_view'];
}
foreach ($facets as $param => $facet) {
    $sheetPanes[] = ['key' => $param, 'label' => $facet['label'], 'icon' => $facetIcons[$param]];
}

$pagerNav = static function (int $count): void {
    if ($count < 2) {
        return;
    }
    ?>
    <div class="hn-pager__nav">
      <button type="button" class="hn-pager__arrow" data-hn-pager-prev aria-label="الصفحة السابقة" disabled>
        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
      </button>
      <div class="hn-pager__dots">
        <?php for ($p = 0; $p < $count; $p++): ?>
          <button type="button" class="hn-pager__dot<?= $p === 0 ? ' is-active' : '' ?>" data-hn-pager-goto="<?= (int) $p ?>" aria-label="صفحة <?= (int) $p + 1 ?>"<?= $p === 0 ? ' aria-current="true"' : '' ?>></button>
        <?php endfor; ?>
      </div>
      <button type="button" class="hn-pager__arrow" data-hn-pager-next aria-label="الصفحة التالية">
        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
      </button>
    </div>
    <?php
};

$shopPages = static function (array $block) use ($pagerNav): void {
    $style = in_array($block['style'] ?? '', ['icons', 'chips', 'cards'], true) ? (string) $block['style'] : 'icons';
    $perPage = ['icons' => 8, 'cards' => 6, 'chips' => 12][$style];
    $pages = array_chunk($block['tiles'], $perPage);
    ?>
    <div class="hn-pages" data-hn-pages>
      <div class="hn-pager" data-hn-pager>
        <?php foreach ($pages as $p => $chunk): ?>
          <div class="hn-shop__page hn-shop__page--<?= h($style) ?>" role="group" aria-label="صفحة <?= (int) $p + 1 ?> من <?= count($pages) ?>">
            <?php foreach ($chunk as $tile): ?>
              <?php if ($style === 'chips'): ?>
                <a class="hn-shop__chip" href="<?= h($tile['href']) ?>"><?= h($tile['label']) ?></a>
              <?php else: ?>
                <a class="hn-shop__tile hn-shop__tile--<?= h($style) ?>" href="<?= h($tile['href']) ?>">
                  <span class="hn-shop__media" aria-hidden="true">
                    <?php if (($tile['image'] ?? '') !== ''): ?>
                      <img src="<?= h($tile['image']) ?>" alt="" loading="lazy" decoding="async">
                    <?php elseif ($style === 'cards' || $tile['icon'] === 'verified'): ?>
                      <span class="hn-shop__initial"><?= h($tile['initial']) ?></span>
                    <?php else: ?>
                      <span class="material-symbols-outlined"><?= h($tile['icon']) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="hn-shop__label"><?= h($tile['label']) ?></span>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php $pagerNav(count($pages)); ?>
    </div>
    <?php
};
?>
<script>try{if(localStorage.getItem('hn-text')==='lg')document.documentElement.classList.add('hn-text-lg')}catch(e){}</script>
<div class="hn" data-hn data-product-share-scope data-product-share-company="<?= h($companyName) ?>">
  <section class="hn-top" aria-label="البحث">
    <form class="hn-search" action="/store.php" method="get" role="search" data-hl-search data-hn-search>
      <label class="hn-search__box">
        <span class="material-symbols-outlined hn-search__icon" aria-hidden="true">search</span>
        <span class="hn-search__spinner" aria-hidden="true"></span>
        <span class="sr-only">ابحث في المواد</span>
        <input
          type="search"
          name="q"
          class="hn-search__input"
          placeholder="<?= h($general['search_placeholder']) ?>"
          autocomplete="off"
          autocorrect="off"
          spellcheck="false"
          enterkeyhint="search"
          role="combobox"
          aria-controls="hn-suggest"
          aria-expanded="false"
          aria-autocomplete="list"
          data-hl-search-input
          data-hn-search-input
        >
      </label>
      <button type="button" class="hn-search__voice" data-hn-voice hidden aria-label="بحث بالصوت" aria-pressed="false">
        <span class="material-symbols-outlined" aria-hidden="true">mic</span>
      </button>
      <button type="submit" class="hn-search__go">بحث</button>
      <div class="hn-suggest" id="hn-suggest" role="listbox" aria-label="نتائج البحث" hidden data-hn-suggest></div>
    </form>
  </section>
  <div class="lab-recent-q hn-recent" data-lab-recent-q hidden>
    <div class="lab-recent-q__row" data-lab-recent-q-row></div>
    <button type="button" class="lab-recent-q__clear" data-lab-clear="q" aria-label="مسح سجل البحث">
      <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>
  </div>

  <?php foreach ($layoutBlocks as $block): ?>
    <?php $blockKey = $block['key']; $blockTitle = $block['title']; $blockOptions = $block['options']; $blockDevice = (string) ($block['device'] ?? 'all'); ?>
    <?php if ($blockDevice !== 'all'): ?><div class="hn-vis hn-vis--<?= h($blockDevice) ?>"><?php endif; ?>

    <?php if ($blockKey === 'stories' && $stories !== []): ?>
      <div class="hn-scroller hn-stories-wrap" data-hn-scroller>
        <nav class="hn-stories" aria-label="تصفّح سريع" data-hn-scroller-track>
          <button type="button" class="hn-story hn-story--all" data-hn-sheet-open>
            <span class="hn-story__ring"><span class="hn-story__img"><span class="material-symbols-outlined" aria-hidden="true">apps</span></span></span>
            <span class="hn-story__label">كل الأقسام</span>
          </button>
          <?php foreach ($stories as $story): ?>
            <a class="hn-story<?= $story['hot'] ? ' hn-story--hot' : '' ?><?= !empty($story['season']) ? ' hn-story--season' : '' ?>" href="<?= h($story['href']) ?>" data-hn-story="<?= h($story['key']) ?>">
              <span class="hn-story__ring">
                <span class="hn-story__img">
                  <?php if ($story['image'] !== ''): ?>
                    <img src="<?= h(portal_site_media_display_url($story['image'], 240)) ?>" alt="" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="material-symbols-outlined" aria-hidden="true"><?= h($story['icon']) ?></span>
                  <?php endif; ?>
                </span>
              </span>
              <span class="hn-story__label"><?= h($story['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </nav>
        <button type="button" class="hn-scroller__arrow hn-scroller__arrow--prev" data-hn-scroller-prev aria-label="السابق" tabindex="-1">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>
        <button type="button" class="hn-scroller__arrow hn-scroller__arrow--next" data-hn-scroller-next aria-label="المزيد" tabindex="-1">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>
      </div>

    <?php elseif ($blockKey === 'ads' && $ads !== []): ?>
      <section class="home-ad-strip hn-ads" aria-label="إعلانات" data-home-ad-carousel>
        <div class="home-ad-frame">
          <?php foreach ($ads as $i => $ad): ?>
            <?php
              $adAlt = trim((string) ($ad['title_ar'] ?? '')) ?: 'إعلان';
              $adSrc = portal_site_media_display_url((string) ($ad['url'] ?? ''), 1280);
            ?>
            <img
              src="<?= h($adSrc) ?>"
              alt="<?= h($adAlt) ?>"
              class="home-ad-slide<?= $i === 0 ? ' is-active' : '' ?>"
              loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
              decoding="async"
              <?= $i === 0 ? 'fetchpriority="high"' : '' ?>
              sizes="(max-width: 768px) 100vw, 1280px"
              aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"
              data-ad-index="<?= (int) $i ?>"
            >
          <?php endforeach; ?>
        </div>
        <?php if (count($ads) > 1): ?>
          <div class="home-ad-dots" role="tablist" aria-label="اختيار إعلان">
            <?php foreach ($ads as $i => $ad): ?>
              <button type="button" class="home-ad-dot<?= $i === 0 ? ' is-active' : '' ?>" aria-label="إعلان <?= (int) $i + 1 ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-ad-dot="<?= (int) $i ?>"></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($blockKey === 'offers'): ?>
      <?php foreach (array_slice($offers, 0, (int) ($blockOptions['limit'] ?? 2)) as $offer): ?>
        <?php
          $offerId = $sectionKey($offer);
          $countdown = hn_offer_countdown((string) ($offer['ends_at'] ?? ''));
          $stripHtml = trim((string) ($embeddedProductStrips[$offerId] ?? ''));
          $offerProducts = is_array($offer['products'] ?? null) ? $offer['products'] : [];
        ?>
        <section class="hn-offer" id="<?= h($offerId) ?>" aria-labelledby="hn-offer-<?= h($offerId) ?>">
          <div class="hn-offer__head">
            <div class="hn-offer__copy">
              <span class="hn-offer__badge"><?= h(hn_offer_badge($offer)) ?></span>
              <h2 id="hn-offer-<?= h($offerId) ?>" class="hn-offer__title"><?= h($sectionTitle($offer)) ?></h2>
              <?php if ($countdown !== ''): ?>
                <p class="hn-offer__timer" data-hn-countdown="<?= h((string) $offer['ends_at']) ?>">
                  <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                  <span data-hn-countdown-text><?= h($countdown) ?></span>
                </p>
              <?php endif; ?>
            </div>
            <a class="hn-offer__cta" href="<?= h(store_lab_section_url($offer)) ?>">
              تسوّق العرض
              <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            </a>
          </div>
          <div class="hn-rail__body">
            <?php if ($stripHtml !== ''): ?>
              <?= $stripHtml ?>
            <?php elseif ($offerProducts !== []): ?>
              <?php $section = $offer; $products = $offerProducts; require __DIR__ . '/partials/home-section-product-strip.php'; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>

    <?php elseif ($blockKey === 'season' && $season !== null): ?>
      <section class="hn-season hn-season--<?= h($seasonTheme) ?>" aria-labelledby="hn-season-title">
        <div class="hn-season__head">
          <span class="hn-season__icon"><span class="material-symbols-outlined" aria-hidden="true"><?= h($season['icon']) ?></span></span>
          <h2 id="hn-season-title" class="hn-season__title"><?= h($season['title_ar']) ?></h2>
          <a class="hn-season__cta" href="<?= h($seasonUrl()) ?>">
            تسوّق التشكيلة
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          </a>
        </div>
        <?php $seasonChips = array_slice($facets['ageCategories']['values'] ?? [], 0, (int) ($blockOptions['chips'] ?? 8)); ?>
        <?php if ($seasonChips !== []): ?>
          <div class="hn-season__chips">
            <?php foreach ($seasonChips as $value): ?>
              <a class="hn-season__chip" href="<?= h($seasonUrl(['ageCategories' => [$value]])) ?>">
                <span class="material-symbols-outlined" aria-hidden="true"><?= h(store_easy_tile_icon($value)) ?></span>
                <?= h($value) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($blockKey === 'showcase' && $showcaseSections !== []): ?>
      <section class="hn-showcase hn-showcase--<?= h($showcaseStyle) ?>" aria-labelledby="hn-showcase-title">
        <div class="hn-rail__head">
          <h2 id="hn-showcase-title" class="hn-rail__title"><?= h($blockTitle) ?></h2>
          <button type="button" class="hn-rail__all" data-hn-sheet-open>
            كل الأقسام
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          </button>
        </div>
        <div class="hn-showcase__grid">
          <?php foreach ($showcaseSections as $i => $section): ?>
            <?php
              $banner = trim((string) ($section['banner_image_url'] ?? ''));
              $subtitle = trim((string) ($section['subtitle_ar'] ?? ''));
              $thumbs = [];
              if ($banner === '') {
                  foreach ((array) ($section['products'] ?? []) as $product) {
                      $imageGuid = is_array($product) ? material_image_guid($product) : '';
                      if ($imageGuid !== '') {
                          $thumbs[] = material_image_api_url($imageGuid, true);
                      }
                      if (count($thumbs) >= 3) {
                          break;
                      }
                  }
              }
            ?>
            <a class="hn-showcase__tile hn-tone-<?= (int) ($i % 6) ?><?= $banner !== '' ? ' has-banner' : '' ?>" href="<?= h(store_lab_section_url($section)) ?>"<?= isset($showcaseSpans[$i]) ? ' style="' . h($showcaseSpans[$i]) . '"' : '' ?>>
              <?php if ($banner !== ''): ?>
                <img class="hn-showcase__banner" src="<?= h(portal_site_media_display_url($banner, $i === 0 ? 960 : 480)) ?>" alt="" loading="lazy" decoding="async">
              <?php elseif ($thumbs !== []): ?>
                <span class="hn-showcase__thumbs" aria-hidden="true">
                  <?php foreach ($thumbs as $thumb): ?>
                    <img src="<?= h($thumb) ?>" alt="" loading="lazy" decoding="async">
                  <?php endforeach; ?>
                </span>
              <?php else: ?>
                <span class="hn-showcase__icon material-symbols-outlined" aria-hidden="true"><?= h($showcaseIcons[$i % count($showcaseIcons)]) ?></span>
              <?php endif; ?>
              <span class="hn-showcase__text">
                <span class="hn-showcase__name"><?= h($sectionTitle($section)) ?></span>
                <?php if ($subtitle !== ''): ?>
                  <span class="hn-showcase__sub"><?= h($subtitle) ?></span>
                <?php endif; ?>
              </span>
              <span class="hn-showcase__go" aria-hidden="true"><span class="material-symbols-outlined">arrow_back</span></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

    <?php elseif ($blockKey === 'guide' && $guideSteps !== []): ?>
      <section class="hn-guide" aria-labelledby="hn-guide-title" data-hn-guide data-action="/store.php">
        <div class="hn-guide__head">
          <h2 id="hn-guide-title" class="hn-guide__title">
            <span class="material-symbols-outlined" aria-hidden="true">touch_app</span>
            <?= h($blockTitle) ?>
          </h2>
          <ol class="hn-guide__steps" aria-label="الخطوات">
            <?php foreach ($guideSteps as $i => $step): ?>
              <li class="hn-guide__dot<?= $i === 0 ? ' is-current' : '' ?>" data-hn-guide-dot="<?= (int) $i ?>"><span><?= (int) $i + 1 ?></span><?= h($step['title']) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
        <?php foreach ($guideSteps as $i => $step): ?>
          <?php $pageCount = count($step['pages']); $isSizes = $step['param'] === 'sizeRanges'; ?>
          <div class="hn-guide__step hn-pages" data-hn-pages data-hn-guide-step="<?= (int) $i ?>" data-param="<?= h($step['param']) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
            <div class="hn-guide__ask">
              <p class="hn-guide__question"><?= h($step['title']) ?></p>
              <span class="hn-guide__picked" data-hn-guide-picked hidden></span>
            </div>
            <div class="hn-pager" data-hn-pager>
              <?php foreach ($step['pages'] as $p => $chunk): ?>
                <div class="hn-guide__page<?= $isSizes ? ' hn-guide__page--sizes' : '' ?>" role="group" aria-label="صفحة <?= (int) $p + 1 ?> من <?= (int) $pageCount ?>">
                  <?php foreach ($chunk as $value): ?>
                    <button type="button" class="hn-guide__option" aria-pressed="false" data-hn-guide-value="<?= h((string) $value) ?>"<?= $isSizes ? ' dir="ltr"' : '' ?>>
                      <?php if (!$isSizes): ?>
                        <span class="material-symbols-outlined" aria-hidden="true"><?= h(lab_shop_facet_icon($step['param'], (string) $value)) ?></span>
                      <?php endif; ?>
                      <span class="hn-guide__label"><?= h((string) $value) ?></span>
                      <span class="hn-guide__tick material-symbols-outlined" aria-hidden="true">check</span>
                    </button>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php $pagerNav($pageCount); ?>
          </div>
        <?php endforeach; ?>
        <div class="hn-guide__result" data-hn-guide-result hidden>
          <div class="hn-guide__chosen" data-hn-guide-chosen></div>
          <div class="hn-guide__checks">
            <?php if ($canFilterAvailable): ?>
              <label class="hn-guide__check">
                <input type="checkbox" data-hn-guide-available>
                <span>المتوفر فقط</span>
              </label>
            <?php endif; ?>
            <?php if ($season !== null): ?>
              <label class="hn-guide__check">
                <input type="checkbox" data-hn-guide-season>
                <span class="material-symbols-outlined" aria-hidden="true"><?= h($season['icon']) ?></span>
                <span><?= h($season['title_ar']) ?> فقط</span>
              </label>
            <?php endif; ?>
          </div>
        </div>
        <div class="hn-guide__actions">
          <button type="button" class="hn-guide__back" data-hn-guide-back hidden>
            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            رجوع
          </button>
          <button type="button" class="hn-guide__show" data-hn-guide-next>
            <span data-hn-guide-next-label>الكل</span>
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          </button>
          <a class="hn-guide__show" href="/store.php" data-hn-guide-show hidden>
            <span>اعرض المواد</span>
            <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          </a>
        </div>
      </section>

    <?php elseif ($blockKey === 'shop' && $shopTabs !== []): ?>
      <section class="hn-shop" aria-labelledby="hn-shop-title" data-hn-shop>
        <div class="hn-shop__head">
          <h2 id="hn-shop-title" class="hn-rail__title"><?= h($blockTitle) ?></h2>
          <?php if (count($shopTabs) > 1): ?>
            <div class="hn-shop__tabs" role="tablist" aria-labelledby="hn-shop-title">
              <?php foreach ($shopTabs as $i => $shopBlock): ?>
                <button
                  type="button"
                  role="tab"
                  id="hn-<?= h($shopBlock['key']) ?>-tab"
                  class="hn-shop__tab<?= $i === 0 ? ' is-active' : '' ?>"
                  aria-controls="hn-<?= h($shopBlock['key']) ?>"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                  tabindex="<?= $i === 0 ? '0' : '-1' ?>"
                  data-hn-shop-tab
                ><?= h($shopBlock['title']) ?></button>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php foreach ($shopTabs as $i => $shopBlock): ?>
          <div
            class="hn-shop__panel"
            id="hn-<?= h($shopBlock['key']) ?>"
            <?= count($shopTabs) > 1 ? 'role="tabpanel" aria-labelledby="hn-' . h($shopBlock['key']) . '-tab"' : '' ?>
            <?= $i === 0 ? '' : 'hidden' ?>
          >
            <?php $shopPages($shopBlock); ?>
          </div>
        <?php endforeach; ?>
      </section>

    <?php elseif ($blockKey === 'rails'): ?>
      <?php foreach ($regularSections as $section): ?>
        <?php
          $sectionId = $sectionKey($section);
          $stripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
          $products = is_array($section['products'] ?? null) ? $section['products'] : [];
          if ($stripHtml === '' && $products === []) {
              continue;
          }
        ?>
        <section class="hn-rail" id="<?= h($sectionId) ?>" aria-labelledby="hn-rail-<?= h($sectionId) ?>">
          <div class="hn-rail__head">
            <h2 id="hn-rail-<?= h($sectionId) ?>" class="hn-rail__title"><?= h($sectionTitle($section)) ?></h2>
            <a class="hn-rail__all" href="<?= h(store_lab_section_url($section)) ?>">
              الكل
              <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            </a>
          </div>
          <div class="hn-rail__body">
            <?php if ($stripHtml !== ''): ?>
              <?= $stripHtml ?>
            <?php else: ?>
              <?php require __DIR__ . '/partials/home-section-product-strip.php'; ?>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>

    <?php elseif ($blockKey === 'bands'): ?>
      <?php foreach ($shopBands as $shopBlock): ?>
        <section class="hn-shop hn-shop--band" aria-labelledby="hn-<?= h($shopBlock['key']) ?>-title">
          <div class="hn-shop__head">
            <h2 id="hn-<?= h($shopBlock['key']) ?>-title" class="hn-rail__title"><?= h($shopBlock['title']) ?></h2>
          </div>
          <?php $shopPages($shopBlock); ?>
        </section>
      <?php endforeach; ?>

    <?php elseif ($blockKey === 'viewed'): ?>
      <section class="lab-viewed hn-viewed" aria-labelledby="hn-viewed-title" data-lab-recent-viewed hidden>
        <div class="lab-viewed__head">
          <h2 id="hn-viewed-title" class="lab-viewed__title"><?= h($blockTitle) ?></h2>
          <button type="button" class="lab-viewed__clear" data-lab-clear="viewed">مسح</button>
        </div>
        <div class="lab-viewed__row" data-lab-recent-viewed-row></div>
      </section>

    <?php elseif ($blockKey === 'feed'): ?>
      <section class="hn-feed" aria-labelledby="hn-feed-title" data-hn-feed data-src="/store.php" data-limit="<?= (int) ($blockOptions['limit'] ?? 12) ?>">
        <div class="hn-rail__head">
          <h2 id="hn-feed-title" class="hn-rail__title">
            <?= h($blockTitle) ?>
            <?php if ($season !== null): ?>
              <span class="hn-feed__badge">
                <span class="material-symbols-outlined" aria-hidden="true"><?= h($season['icon']) ?></span>
                <?= h($season['title_ar']) ?> أولاً
              </span>
            <?php endif; ?>
          </h2>
        </div>
        <div class="store-product-grid se-grid hn-feed__grid" data-hn-feed-grid></div>
        <div class="hn-feed__skeleton" data-hn-feed-skeleton aria-hidden="true">
          <span></span><span></span><span></span><span></span>
        </div>
        <a class="hn-feed__cta" href="/store.php">
          <span class="hn-feed__cta-icon material-symbols-outlined" aria-hidden="true">storefront</span>
          <span class="hn-feed__cta-text">تصفّح كل المواد في المتجر</span>
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        </a>
      </section>

    <?php elseif ($blockKey === 'help'): ?>
      <section class="hn-help" aria-labelledby="hn-help-title">
        <h2 id="hn-help-title" class="hn-help__title"><?= h($blockTitle) ?></h2>
        <div class="hn-help__grid">
          <?php if ($whatsapp !== ''): ?>
            <a class="hn-help__item hn-help__item--green" href="https://wa.me/<?= h($whatsapp) ?>" target="_blank" rel="noopener">
              <span class="material-symbols-outlined" aria-hidden="true">chat</span>
              <span>واتساب</span>
            </a>
          <?php endif; ?>
          <?php if ($phone !== ''): ?>
            <a class="hn-help__item" href="tel:<?= h($phone) ?>">
              <span class="material-symbols-outlined" aria-hidden="true">call</span>
              <span>اتصال</span>
            </a>
          <?php endif; ?>
          <?php [$helpOrdersHref, $helpOrdersLabel] = $isCustomer ? ['/my-orders.php', 'طلباتي'] : ($isStaffViewer ? ['/dashboard/orders.php', 'الطلبات'] : ['/track-order.php', 'تتبّع طلب']); ?>
          <a class="hn-help__item" href="<?= h($helpOrdersHref) ?>">
            <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
            <span><?= h($helpOrdersLabel) ?></span>
          </a>
          <button type="button" class="hn-help__item" data-hn-text-size aria-pressed="false">
            <span class="material-symbols-outlined" aria-hidden="true">text_increase</span>
            <span data-hn-text-size-label>تكبير الخط</span>
          </button>
          <button type="button" class="hn-help__item" data-site-guide-replay>
            <span class="material-symbols-outlined" aria-hidden="true">tour</span>
            <span>جولة تعريفية</span>
          </button>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($blockDevice !== 'all'): ?></div><?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="hn-sheet" role="dialog" aria-modal="true" aria-labelledby="hn-sheet-title" data-hn-sheet hidden>
  <div class="hn-sheet__bar">
    <h2 id="hn-sheet-title" class="hn-sheet__title">كل الأقسام</h2>
    <button type="button" class="hn-sheet__close" data-hn-sheet-close aria-label="إغلاق">
      <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>
  </div>
  <div class="hn-sheet__quick">
    <?php if ($season !== null): ?>
      <a href="<?= h($seasonUrl()) ?>" class="hn-sheet__pill hn-sheet__pill--season">
        <span class="material-symbols-outlined" aria-hidden="true"><?= h($season['icon']) ?></span>
        <?= h($season['title_ar']) ?>
      </a>
    <?php endif; ?>
    <a href="/store.php" class="hn-sheet__pill">كل المواد</a>
    <?php if ($canFilterAvailable): ?>
      <a href="/store.php?isAvailable=1" class="hn-sheet__pill">المتوفر فقط</a>
    <?php endif; ?>
    <?php if ($offers !== []): ?>
      <a href="<?= h(store_lab_section_url($offers[0])) ?>" class="hn-sheet__pill hn-sheet__pill--red">العروض</a>
    <?php endif; ?>
  </div>
  <div class="hn-sheet__body">
    <div class="hn-sheet__nav" role="tablist" aria-orientation="vertical" aria-label="تصنيفات">
      <?php foreach ($sheetPanes as $i => $pane): ?>
        <button
          type="button"
          role="tab"
          id="hn-pane-tab-<?= h($pane['key']) ?>"
          class="hn-sheet__tab<?= $i === 0 ? ' is-active' : '' ?>"
          aria-controls="hn-pane-<?= h($pane['key']) ?>"
          aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
          data-hn-pane-tab
        >
          <span class="material-symbols-outlined" aria-hidden="true"><?= h($pane['icon']) ?></span>
          <span><?= h($pane['label']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="hn-sheet__panes">
      <?php foreach ($sheetPanes as $i => $pane): ?>
        <div class="hn-sheet__pane" id="hn-pane-<?= h($pane['key']) ?>" role="tabpanel" aria-labelledby="hn-pane-tab-<?= h($pane['key']) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <?php if ($pane['key'] === 'sections'): ?>
            <?php foreach ($sections as $section): ?>
              <?php $banner = trim((string) ($section['banner_image_url'] ?? '')); ?>
              <a class="hn-sheet__tile" href="<?= h(store_lab_section_url($section)) ?>">
                <span class="hn-sheet__media<?= !empty($section['is_offer_section']) ? ' is-hot' : '' ?>">
                  <?php if ($banner !== ''): ?>
                    <img src="<?= h(portal_site_media_display_url($banner, 240)) ?>" alt="" loading="lazy" decoding="async">
                  <?php else: ?>
                    <span class="material-symbols-outlined" aria-hidden="true"><?= !empty($section['is_offer_section']) ? 'local_offer' : 'auto_awesome' ?></span>
                  <?php endif; ?>
                </span>
                <span class="hn-sheet__label"><?= h($sectionTitle($section)) ?></span>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <?php foreach ($facets[$pane['key']]['values'] as $value): ?>
              <a class="hn-sheet__tile" href="<?= h(store_lab_url([$pane['key'] => [$value]])) ?>">
                <span class="hn-sheet__media">
                  <?php if ($pane['key'] === 'manufacturers'): ?>
                    <span class="hn-sheet__initial"><?= h(lab_shop_initial($value)) ?></span>
                  <?php elseif ($pane['key'] === 'sizeRanges'): ?>
                    <span class="hn-sheet__size" dir="ltr"><?= h($value) ?></span>
                  <?php else: ?>
                    <span class="material-symbols-outlined" aria-hidden="true"><?= h(lab_shop_facet_icon($pane['key'], $value)) ?></span>
                  <?php endif; ?>
                </span>
                <span class="hn-sheet__label"<?= $pane['key'] === 'sizeRanges' ? ' dir="ltr"' : '' ?>><?= h($value) ?></span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($general['dock']): ?>
  <?php
    $dockContext = 'home';
    $dockItems = $general['dock_items'];
    $dockAllowCart = $allowCart;
    $dockCartPackages = $cartPackages;
    $dockOfferHref = $offers !== [] ? store_lab_section_url($offers[0]) : '';
    require __DIR__ . '/partials/app-dock.php';
  ?>
<?php endif; ?>
<?php if ($layoutPreview !== null): ?>
  <div class="hn-preview-flag" role="status">معاينة التخطيط — لم يُحفظ بعد</div>
<?php endif; ?>
