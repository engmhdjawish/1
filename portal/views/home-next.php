<?php

declare(strict_types=1);

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

$allowCart = (bool) ($storeCatalogDisplay['allow_cart'] ?? false);
try {
    $isCustomer = \Portal\Auth\CustomerSession::check();
} catch (Throwable) {
    $isCustomer = false;
}
try {
    $cartPackages = $allowCart ? \Portal\Services\StoreCartService::packageCount() : 0.0;
} catch (Throwable) {
    $cartPackages = 0.0;
}
$whatsapp = preg_replace('/\D+/', '', (string) ($company['company_whatsapp'] ?? '')) ?? '';
$phone = preg_replace('/[^\d+]+/', '', (string) ($company['company_phone'] ?? '')) ?? '';
$accountHref = $isCustomer ? '/account.php' : portal_login_url('customer');

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

$stories = [];
foreach ($offers as $offer) {
    $stories[] = ['key' => 'o-' . $sectionKey($offer), 'label' => $sectionTitle($offer), 'href' => store_lab_section_url($offer), 'image' => (string) ($offer['banner_image_url'] ?? ''), 'icon' => 'local_offer', 'hot' => true];
}
foreach ($regularSections as $section) {
    $stories[] = ['key' => 's-' . $sectionKey($section), 'label' => $sectionTitle($section), 'href' => store_lab_section_url($section), 'image' => (string) ($section['banner_image_url'] ?? ''), 'icon' => 'auto_awesome', 'hot' => false];
}
foreach ($facets['ageCategories']['values'] ?? [] as $value) {
    $stories[] = ['key' => 'a-' . $value, 'label' => $value, 'href' => store_lab_url(['ageCategories' => [$value]]), 'image' => '', 'icon' => store_easy_tile_icon($value), 'hot' => false];
}
$stories = array_slice($stories, 0, 16);

$guideTitles = ['ageCategories' => 'لمن؟', 'materialTypes' => 'النوع', 'sizeRanges' => 'القياس'];
$guideSteps = [];
foreach ($guideTitles as $param => $title) {
    $values = $facets[$param]['values'] ?? [];
    if ($values === [] || ($param === 'sizeRanges' && count($values) > 30)) {
        continue;
    }
    $guideSteps[] = ['param' => $param, 'title' => $title, 'values' => array_slice($values, 0, 24)];
}

$labShopBlocks = lab_shop_blocks_resolve(
    HomeShopBlockService::activeBlocks(),
    static fn (array $params): string => store_lab_url($params)
);

$sheetPanes = [];
if ($sections !== []) {
    $sheetPanes[] = ['key' => 'sections', 'label' => 'الأقسام', 'icon' => 'grid_view'];
}
foreach ($facets as $param => $facet) {
    $sheetPanes[] = ['key' => $param, 'label' => $facet['label'], 'icon' => $facetIcons[$param]];
}
?>
<script>try{if(localStorage.getItem('hn-text')==='lg')document.documentElement.classList.add('hn-text-lg')}catch(e){}</script>
<div class="hn" data-hn>
  <section class="hn-top" aria-label="البحث">
    <form class="hn-search" action="/store-lab.php" method="get" role="search" data-hl-search data-hn-search>
      <label class="hn-search__box">
        <span class="material-symbols-outlined hn-search__icon" aria-hidden="true">search</span>
        <span class="sr-only">ابحث في المواد</span>
        <input
          type="search"
          name="q"
          class="hn-search__input"
          placeholder="ابحث عن مادة أو شركة"
          autocomplete="off"
          autocorrect="off"
          spellcheck="false"
          enterkeyhint="search"
          aria-controls="hn-suggest"
          aria-expanded="false"
          aria-autocomplete="list"
          data-hl-search-input
          data-hn-search-input
        >
      </label>
      <button type="button" class="hn-search__voice" data-hn-voice hidden aria-label="بحث بالصوت">
        <span class="material-symbols-outlined" aria-hidden="true">mic</span>
      </button>
      <button type="submit" class="hn-search__go">بحث</button>
      <div class="hn-suggest" id="hn-suggest" role="listbox" hidden data-hn-suggest></div>
    </form>
  </section>
  <div class="lab-recent-q hn-recent" data-lab-recent-q hidden>
    <div class="lab-recent-q__row" data-lab-recent-q-row></div>
    <button type="button" class="lab-recent-q__clear" data-lab-clear="q" aria-label="مسح سجل البحث">
      <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>
  </div>

  <?php if ($stories !== []): ?>
    <nav class="hn-stories" aria-label="تصفّح سريع">
      <button type="button" class="hn-story hn-story--all" data-hn-sheet-open>
        <span class="hn-story__ring"><span class="hn-story__img"><span class="material-symbols-outlined" aria-hidden="true">apps</span></span></span>
        <span class="hn-story__label">كل الأقسام</span>
      </button>
      <?php foreach ($stories as $story): ?>
        <a class="hn-story<?= $story['hot'] ? ' hn-story--hot' : '' ?>" href="<?= h($story['href']) ?>" data-hn-story="<?= h($story['key']) ?>">
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
  <?php endif; ?>

  <?php if ($ads !== []): ?>
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
  <?php endif; ?>

  <?php foreach (array_slice($offers, 0, 2) as $offer): ?>
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

  <?php if ($guideSteps !== []): ?>
    <section class="hn-guide" aria-labelledby="hn-guide-title" data-hn-guide data-action="/store-lab.php">
      <div class="hn-guide__head">
        <h2 id="hn-guide-title" class="hn-guide__title">
          <span class="material-symbols-outlined" aria-hidden="true">touch_app</span>
          تسوّق خطوة بخطوة
        </h2>
        <ol class="hn-guide__steps" aria-label="الخطوات">
          <?php foreach ($guideSteps as $i => $step): ?>
            <li class="hn-guide__dot<?= $i === 0 ? ' is-current' : '' ?>" data-hn-guide-dot="<?= (int) $i ?>"><span><?= (int) $i + 1 ?></span><?= h($step['title']) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
      <?php foreach ($guideSteps as $i => $step): ?>
        <div class="hn-guide__step" data-hn-guide-step="<?= (int) $i ?>" data-param="<?= h($step['param']) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
          <p class="hn-guide__question"><?= h($step['title']) ?></p>
          <div class="hn-guide__options<?= $step['param'] === 'sizeRanges' ? ' hn-guide__options--sizes' : '' ?>">
            <?php foreach ($step['values'] as $value): ?>
              <a class="hn-guide__option" href="<?= h(store_lab_url([$step['param'] => [$value]])) ?>" data-hn-guide-value="<?= h($value) ?>"<?= $step['param'] === 'sizeRanges' ? ' dir="ltr"' : '' ?>>
                <?php if ($step['param'] !== 'sizeRanges'): ?>
                  <span class="material-symbols-outlined" aria-hidden="true"><?= h(lab_shop_facet_icon($step['param'], $value)) ?></span>
                <?php endif; ?>
                <span><?= h($value) ?></span>
              </a>
            <?php endforeach; ?>
            <button type="button" class="hn-guide__option hn-guide__option--any" data-hn-guide-value="">
              <span class="material-symbols-outlined" aria-hidden="true">done_all</span>
              <span>الكل</span>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="hn-guide__result" data-hn-guide-result hidden>
        <div class="hn-guide__chosen" data-hn-guide-chosen></div>
        <?php if ($canFilterAvailable): ?>
          <label class="hn-guide__check">
            <input type="checkbox" data-hn-guide-available>
            <span>المتوفر فقط</span>
          </label>
        <?php endif; ?>
      </div>
      <div class="hn-guide__actions">
        <button type="button" class="hn-guide__back" data-hn-guide-back hidden>
          <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
          رجوع
        </button>
        <a class="hn-guide__show" href="/store-lab.php" data-hn-guide-show hidden>
          اعرض المواد
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
        </a>
      </div>
    </section>
  <?php endif; ?>

  <?php $labShopPlacement = 'tabs'; require __DIR__ . '/partials/lab-shop.php'; ?>

  <?php foreach ($regularSections as $section): ?>
    <?php
      $sectionId = $sectionKey($section);
      $stripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
      $products = is_array($section['products'] ?? null) ? $section['products'] : [];
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
        <?php elseif ($products !== []): ?>
          <?php require __DIR__ . '/partials/home-section-product-strip.php'; ?>
        <?php else: ?>
          <a class="hn-rail__empty" href="<?= h(store_lab_section_url($section)) ?>">فتح القسم</a>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php $labShopPlacement = 'band'; require __DIR__ . '/partials/lab-shop.php'; ?>

  <section class="lab-viewed hn-viewed" aria-labelledby="hn-viewed-title" data-lab-recent-viewed hidden>
    <div class="lab-viewed__head">
      <h2 id="hn-viewed-title" class="lab-viewed__title">شاهدتها مؤخراً</h2>
      <button type="button" class="lab-viewed__clear" data-lab-clear="viewed">مسح</button>
    </div>
    <div class="lab-viewed__row" data-lab-recent-viewed-row></div>
  </section>

  <section class="hn-feed" aria-labelledby="hn-feed-title" data-hn-feed data-src="/store-lab.php">
    <div class="hn-rail__head">
      <h2 id="hn-feed-title" class="hn-rail__title">كل المواد</h2>
      <a class="hn-rail__all" href="/store-lab.php">
        فتح المتجر
        <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
      </a>
    </div>
    <div class="store-product-grid se-grid hn-feed__grid" data-hn-feed-grid></div>
    <div class="hn-feed__skeleton" data-hn-feed-skeleton aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
    <div class="hn-feed__foot">
      <button type="button" class="hn-feed__more" data-hn-feed-more hidden>عرض المزيد</button>
      <a class="hn-feed__more" href="/store-lab.php" data-hn-feed-store hidden>فتح المتجر</a>
    </div>
    <div class="hn-feed__sentinel" data-hn-feed-sentinel></div>
  </section>

  <section class="hn-help" aria-labelledby="hn-help-title">
    <h2 id="hn-help-title" class="hn-help__title">تحتاج مساعدة؟</h2>
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
      <a class="hn-help__item" href="<?= $isCustomer ? '/my-orders.php' : '/track-order.php' ?>">
        <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
        <span><?= $isCustomer ? 'طلباتي' : 'تتبّع طلب' ?></span>
      </a>
      <button type="button" class="hn-help__item" data-hn-text-size aria-pressed="false">
        <span class="material-symbols-outlined" aria-hidden="true">text_increase</span>
        <span data-hn-text-size-label>تكبير الخط</span>
      </button>
    </div>
  </section>
</div>

<div class="hn-sheet" role="dialog" aria-modal="true" aria-labelledby="hn-sheet-title" data-hn-sheet hidden>
  <div class="hn-sheet__bar">
    <h2 id="hn-sheet-title" class="hn-sheet__title">كل الأقسام</h2>
    <button type="button" class="hn-sheet__close" data-hn-sheet-close aria-label="إغلاق">
      <span class="material-symbols-outlined" aria-hidden="true">close</span>
    </button>
  </div>
  <div class="hn-sheet__quick">
    <a href="/store-lab.php" class="hn-sheet__pill">كل المواد</a>
    <?php if ($canFilterAvailable): ?>
      <a href="/store-lab.php?isAvailable=1" class="hn-sheet__pill">المتوفر فقط</a>
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

<nav class="hn-dock" aria-label="تنقّل سريع">
  <a href="/home-next.php" class="hn-dock__item is-active" aria-current="page">
    <span class="material-symbols-outlined" aria-hidden="true">home</span>
    <span>الرئيسية</span>
  </a>
  <button type="button" class="hn-dock__item" data-hn-sheet-open>
    <span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
    <span>الأقسام</span>
  </button>
  <button type="button" class="hn-dock__item" data-hn-focus-search>
    <span class="material-symbols-outlined" aria-hidden="true">search</span>
    <span>بحث</span>
  </button>
  <?php if ($allowCart): ?>
    <a href="/store-cart.php" class="hn-dock__item">
      <span class="hn-dock__icon">
        <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
        <span class="hn-dock__badge<?= $cartPackages > 0 ? '' : ' hidden' ?>" data-store-cart-badge><?= h(format_packages_display($cartPackages)) ?></span>
      </span>
      <span>السلة</span>
    </a>
  <?php endif; ?>
  <a href="<?= h($accountHref) ?>" class="hn-dock__item">
    <span class="material-symbols-outlined" aria-hidden="true">person</span>
    <span><?= $isCustomer ? 'حسابي' : 'دخول' ?></span>
  </a>
</nav>
