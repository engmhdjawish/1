<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StoreCatalogService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
$ads ??= [];

$company = PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$companyLogoUrl = PortalSettingsService::companyLogoUrl($company);
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 200) {
        $aboutSnippet = substr($aboutSnippet, 0, 200) . '...';
    }
}
$sectionCount = count($sections);
$offerCount = count(array_filter($sections, static fn (array $s): bool => !empty($s['is_offer_section'])));
$storeCatalogDisplay = StoreCatalogService::displayOptions();
$storeShowPrice = (bool) ($storeCatalogDisplay['show_price'] ?? false);
?>
<div class="home-page">
  <section class="home-hero home-hero--premium" aria-label="ترحيب">
    <div class="home-hero__glow home-hero__glow--left" aria-hidden="true"></div>
    <div class="home-hero__glow home-hero__glow--right" aria-hidden="true"></div>
    <div class="home-hero__inner">
      <div class="home-hero__content">
        <p class="home-hero__kicker">
          <span class="home-hero__kicker-dot" aria-hidden="true"></span>
          مرحباً بكم في <?= h($siteName) ?>
        </p>
        <h1 class="home-hero__title">تجربة تسوّق جملة<br>احترافية وسلسة</h1>
        <p class="home-hero__lead">
          <?= $aboutSnippet !== '' ? h($aboutSnippet) : 'تصفّح أحدث المواد بأسعار واضحة، أضف للسلة، وتابع طلبك خطوة بخطوة.' ?>
        </p>
        <div class="home-hero__actions">
          <a href="/store.php" class="home-btn home-btn--light">
            <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
          <a href="/register.php" class="home-btn home-btn--ghost">
            <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
            تسجيل عميل
          </a>
        </div>
      </div>

      <div class="home-hero__panel">
        <div class="home-stat-grid">
          <article class="home-stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
            <strong><?= $sectionCount > 0 ? (int) $sectionCount : '—' ?></strong>
            <span>أقسام نشطة</span>
          </article>
          <article class="home-stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">sell</span>
            <strong><?= $offerCount > 0 ? (int) $offerCount : '—' ?></strong>
            <span>عروض خاصة</span>
          </article>
          <article class="home-stat-card">
            <span class="material-symbols-outlined" aria-hidden="true">verified</span>
            <strong>24/7</strong>
            <span>متابعة الطلب</span>
          </article>
        </div>
        <ul class="home-trust-list">
          <li><span class="material-symbols-outlined" aria-hidden="true">photo_camera</span> صور وأسعار واضحة</li>
          <li><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span> سلة وطلب فوري</li>
          <li><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span> تتبع حالة الطلب</li>
        </ul>
      </div>
    </div>
  </section>

  <?php if ($ads !== []): ?>
    <section class="home-ad-strip" aria-label="إعلانات" data-home-ad-carousel>
      <div class="home-ad-frame">
        <?php foreach ($ads as $i => $ad): ?>
          <?php
            $adAlt = trim((string) ($ad['title_ar'] ?? ''));
            if ($adAlt === '') {
                $adAlt = trim((string) ($ad['file_name'] ?? 'إعلان'));
            }
          ?>
          <img
            src="<?= h((string) ($ad['url'] ?? '')) ?>"
            alt="<?= h($adAlt) ?>"
            class="home-ad-slide<?= $i === 0 ? ' is-active' : '' ?>"
            loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"
            decoding="async"
            aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>"
            data-ad-index="<?= (int) $i ?>"
          >
        <?php endforeach; ?>
        <?php if (count($ads) > 1): ?>
          <button type="button" class="home-ad-nav home-ad-nav--prev" data-ad-prev aria-label="الإعلان السابق">
            <span class="material-symbols-outlined">chevron_right</span>
          </button>
          <button type="button" class="home-ad-nav home-ad-nav--next" data-ad-next aria-label="الإعلان التالي">
            <span class="material-symbols-outlined">chevron_left</span>
          </button>
        <?php endif; ?>
      </div>
      <?php if (count($ads) > 1): ?>
        <div class="home-ad-dots" role="tablist" aria-label="اختيار إعلان">
          <?php foreach ($ads as $i => $ad): ?>
            <button
              type="button"
              class="home-ad-dot<?= $i === 0 ? ' is-active' : '' ?>"
              aria-label="إعلان <?= (int) $i + 1 ?>"
              aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
              data-ad-dot="<?= (int) $i ?>"
            ></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($sections !== []): ?>
    <nav class="home-section-nav home-section-nav--sticky" aria-label="أقسام الرئيسية">
      <?php foreach ($sections as $section): ?>
        <?php $anchorId = (string) ($section['slug'] ?? $section['id'] ?? ''); ?>
        <?php if ($anchorId === '') continue; ?>
        <a href="#<?= h($anchorId) ?>" class="home-section-nav__link">
          <?= h((string) ($section['title_ar'] ?? 'قسم')) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="home-sections">
    <?php foreach ($sections as $sectionIndex => $section): ?>
      <?php
        $products = is_array($section['products'] ?? null) ? $section['products'] : [];
        $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
        $displayOptions = is_array($section['display_options'] ?? null) ? $section['display_options'] : [];
        $priceState = section_price_display_state($displayOptions, $storeCatalogDisplay);
        $showImages = (bool) ($priceState['preview_display_options']['show_images'] ?? true);
        $showAnyPrice = $priceState['show_any_price'];
        $showPriceSyp = $priceState['show_price_syp'];
        $showPriceUsd = $priceState['show_price_usd'];
        $isOfferSection = !empty($section['is_offer_section']);
      ?>
      <section
        class="home-section<?= $isOfferSection ? ' home-section--offer' : '' ?>"
        id="<?= h($sectionId) ?>"
      >
        <?php if (!empty($section['banner_image_url'])): ?>
          <div class="home-section__banner">
            <img src="<?= h((string) $section['banner_image_url']) ?>" alt="" loading="lazy">
          </div>
        <?php endif; ?>

        <div class="home-section__body">
          <header class="home-section__header">
            <div class="home-section__title-wrap">
              <?php if ($isOfferSection): ?>
                <span class="home-section__badge">عرض خاص</span>
              <?php endif; ?>
              <h2 class="home-section__title"><?= h((string) ($section['title_ar'] ?? '')) ?></h2>
              <?php if (!empty($section['subtitle_ar'])): ?>
                <p class="home-section__subtitle"><?= h((string) $section['subtitle_ar']) ?></p>
              <?php endif; ?>
            </div>
            <a href="<?= h(home_section_store_url($section)) ?>" class="home-section__more">
              عرض المزيد
              <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
            </a>
          </header>

          <?php if ($products === []): ?>
            <div class="home-section__empty">لا توجد منتجات في هذا القسم حالياً.</div>
          <?php else: ?>
            <?php
              $sectionGuids = array_values(array_filter(array_map(
                  static fn ($row): string => is_array($row) ? material_guid($row) : '',
                  $products
              ), static fn (string $g): bool => $g !== ''));
              $sectionSlug = trim((string) ($section['slug'] ?? ''));
              $sectionReturnUrl = home_section_return_url($section);
              $sectionOfferSlug = $isOfferSection && $sectionSlug !== '' ? $sectionSlug : null;
              $sectionPriceModeResolved = $priceState['price_mode_resolved'];
              $previewDisplayOptions = $priceState['preview_display_options'];
              $homeAllowCart = (bool) ($previewDisplayOptions['allow_cart'] ?? false);
            ?>
            <div class="home-strip">
              <?php foreach ($products as $item): ?>
                <?php
                  if (!is_array($item)) {
                      continue;
                  }
                  $guid = material_guid($item);
                  $contextOffer = $isOfferSection && $sectionSlug !== ''
                      ? SpecialOfferService::activeOfferBySlug($sectionSlug)
                      : null;
                  if ($guid !== '') {
                      $overlay = SpecialOfferService::pricingOverlay($item, $contextOffer);
                      if (!empty($overlay['has_offer'])) {
                          $item = array_merge($item, $overlay);
                      }
                  }
                  $cartQtyForItem = 0.0;
                  if ($homeAllowCart && $guid !== '') {
                      $cartItems = \Portal\Services\StoreCartService::items();
                      $cartQtyForItem = (float) ($cartItems[$guid]['quantity'] ?? 0);
                  }
                  $previewPayload = $guid !== ''
                      ? product_preview_payload($item, $previewDisplayOptions, $cartQtyForItem, $sectionReturnUrl, $sectionOfferSlug)
                      : null;
                  $previewJson = $previewPayload !== null
                      ? json_encode($previewPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                      : '';
                ?>
                <article
                  class="home-product-card"
                  <?php if ($guid !== '' && $previewJson !== ''): ?>
                    data-store-preview-card
                    data-preview-guid="<?= h($guid) ?>"
                    data-preview="<?= h($previewJson) ?>"
                  <?php endif; ?>
                >
                  <?php if ($showImages): ?>
                    <button
                      type="button"
                      class="home-product-card__media home-product-card__media--preview"
                      data-store-product-preview
                      title="معاينة الصورة والأسعار"
                    >
                      <span class="home-product-card__zoom-hint material-symbols-outlined" aria-hidden="true">zoom_in</span>
                      <?php $material = $item; $variant = 'strip'; require __DIR__ . '/partials/material-image-frame.php'; ?>
                    </button>
                  <?php endif; ?>
                  <div class="home-product-card__body">
                    <div class="home-product-card__name"><?= h((string) ($item['name'] ?? '-')) ?></div>
                    <?php if ($showAnyPrice): ?>
                      <?php require __DIR__ . '/partials/offer-price-block.php'; ?>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <?php if ($sections === []): ?>
    <section class="home-section home-section--empty">
      <span class="material-symbols-outlined text-4xl text-gray-300" aria-hidden="true">storefront</span>
      <p>لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php" class="home-btn home-btn--primary">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>

  <section class="home-cta" aria-label="ابدأ التسوق">
    <div class="home-cta__content">
      <h2>جاهز لبدء طلبك؟</h2>
      <p>استكشف المتجر كاملاً أو سجّل حسابك للحصول على أسعار وصلاحيات مخصصة.</p>
    </div>
    <div class="home-cta__actions">
      <a href="/store.php" class="home-btn home-btn--primary">
        <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
        فتح المتجر
      </a>
      <a href="/about.php" class="home-btn home-btn--ghost-dark">من نحن</a>
    </div>
  </section>
</div>
<?php if (empty($GLOBALS['storeCatalogPreviewRendered'])): ?>
  <?php $GLOBALS['storeCatalogPreviewRendered'] = true; ?>
  <?php require __DIR__ . '/partials/store-product-preview.php'; ?>
<?php endif; ?>
