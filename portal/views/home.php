<?php

declare(strict_types=1);

use Portal\Auth\CustomerSession;
use Portal\Services\PortalSettingsService;
use Portal\Services\StoreCatalogService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
/** @var array<string, string>|null $companyContext */
/** @var string|null $companyLogoUrl */
/** @var array{show_price: bool, show_quantity: bool, allow_cart: bool, allow_order: bool, show_images: bool, price_mode: string}|null $storeCatalogDisplay */
/** @var bool|null $deferHomeProducts */
/** @var array<string, string>|null $embeddedProductStrips */
$ads ??= [];
$deferHomeProducts = (bool) ($deferHomeProducts ?? false);
$embeddedProductStrips = is_array($embeddedProductStrips ?? null) ? $embeddedProductStrips : [];
$homeHasEmbeddedStrips = (bool) ($homeHasEmbeddedStrips ?? false);

$company = is_array($companyContext ?? null) ? $companyContext : PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$companyLogoUrl = trim((string) ($companyLogoUrl ?? '')) !== ''
    ? (string) $companyLogoUrl
    : PortalSettingsService::companyLogoUrl($company);
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/', ' ', $aboutSnippet) ?? $aboutSnippet;
    if (strlen($aboutSnippet) > 200) {
        $aboutSnippet = substr($aboutSnippet, 0, 200) . '...';
    }
}
$sectionCount = count($sections);
$offerCount = count(array_filter($sections, static fn (array $s): bool => !empty($s['is_offer_section'])));
$storeCatalogDisplay = is_array($storeCatalogDisplay ?? null)
    ? $storeCatalogDisplay
    : StoreCatalogService::displayOptions();
$homeCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
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
          <?php if ($homeCustomer === null): ?>
            <a href="/register.php" class="home-btn home-btn--ghost">
              <span class="material-symbols-outlined" aria-hidden="true">person_add</span>
              تسجيل عميل
            </a>
          <?php endif; ?>
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

  <div class="home-sections"<?= $deferHomeProducts ? ' data-home-deferred-products="1"' : '' ?><?= $homeHasEmbeddedStrips ? ' data-home-has-embedded-strips="1"' : '' ?>>
    <?php foreach ($sections as $sectionIndex => $section): ?>
      <?php
        $products = is_array($section['products'] ?? null) ? $section['products'] : [];
        $sectionId = (string) ($section['slug'] ?? $section['id'] ?? '');
        $embeddedStripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
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
            <img
              src="<?= h(portal_site_media_display_url((string) $section['banner_image_url'], 1280)) ?>"
              alt=""
              loading="lazy"
              decoding="async"
              sizes="(max-width: 768px) 100vw, 1280px"
            >
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

          <?php if ($deferHomeProducts): ?>
            <div
              class="home-strip-slot"
              data-home-products="<?= h($sectionId) ?>"
              <?= $embeddedStripHtml === '' ? ' data-home-products-pending="1"' : '' ?>
            >
              <?php if ($embeddedStripHtml !== ''): ?>
                <?= $embeddedStripHtml ?>
              <?php else: ?>
                <div class="home-strip-skeleton" aria-hidden="true">
                  <?php for ($sk = 0; $sk < 4; $sk++): ?>
                    <div class="home-product-skeleton"></div>
                  <?php endfor; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php elseif ($embeddedStripHtml !== ''): ?>
            <?= $embeddedStripHtml ?>
          <?php elseif ($products === []): ?>
            <div class="home-section__empty">لا توجد منتجات في هذا القسم حالياً.</div>
          <?php else: ?>
            <?php require __DIR__ . '/partials/home-section-product-strip.php'; ?>
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
