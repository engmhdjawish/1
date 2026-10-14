<?php

declare(strict_types=1);

use Portal\Services\PortalSettingsService;

/** @var list<array<string, mixed>> $sections */
/** @var list<array<string, mixed>> $ads */
/** @var array<string, string>|null $companyContext */
/** @var array<string, string>|null $embeddedProductStrips */

$ads ??= [];
$embeddedProductStrips = is_array($embeddedProductStrips ?? null) ? $embeddedProductStrips : [];
$sections = is_array($sections ?? null) ? $sections : [];

$company = is_array($companyContext ?? null) ? $companyContext : PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== '' ? (string) $company['company_name'] : 'جاويش للتجارة';
$aboutSnippet = trim((string) ($company['about_us_ar'] ?? ''));
if ($aboutSnippet !== '') {
    $aboutSnippet = preg_replace('/\s+/u', ' ', $aboutSnippet) ?? $aboutSnippet;
    $aboutLength = function_exists('mb_strlen') ? mb_strlen($aboutSnippet) : strlen($aboutSnippet);
    if ($aboutLength > 140) {
        $aboutSnippet = function_exists('mb_substr')
            ? mb_substr($aboutSnippet, 0, 140) . '...'
            : substr($aboutSnippet, 0, 140) . '...';
    }
}

$sectionLabel = static function (array $section): string {
    $title = trim((string) ($section['title_ar'] ?? ''));

    return $title !== '' ? $title : 'قسم';
};
$sectionKey = static function (array $section): string {
    return (string) ($section['slug'] ?? $section['id'] ?? '');
};
?>
<div class="hl">
  <aside class="hl-lab-note" aria-label="ملاحظة النسخة التجريبية">
    <p><strong>نسخة تجريبية.</strong> الصفحة الحالية لم تتغيّر. هنا إعلان واحد، ثم كل قسم كرف منتجات فقط.</p>
    <a href="/index.php">الصفحة الحالية</a>
  </aside>

  <?php if ($ads !== []): ?>
    <section class="hl-hero" aria-label="إعلان">
      <div class="home-ad-strip" data-home-ad-carousel>
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
              sizes="(max-width: 1024px) 100vw, 1100px"
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
      </div>
    </section>
  <?php else: ?>
    <header class="hl-masthead">
      <p><?= h($siteName) ?></p>
      <h1>أقسام واضحة، وصف منتجات واحد</h1>
      <?php if ($aboutSnippet !== ''): ?>
        <p class="hl-masthead__lead"><?= h($aboutSnippet) ?></p>
      <?php endif; ?>
      <a href="/store.php">تصفّح المتجر</a>
    </header>
  <?php endif; ?>

  <?php if ($sections !== []): ?>
    <nav class="hl-index" aria-label="أقسام الصفحة">
      <?php foreach ($sections as $section): ?>
        <a href="#<?= h($sectionKey($section)) ?>"><?= h($sectionLabel($section)) ?></a>
      <?php endforeach; ?>
      <a class="hl-index__store" href="/store.php">المتجر</a>
    </nav>

    <div class="hl-shelves">
      <?php foreach ($sections as $section): ?>
        <?php
          $sectionId = $sectionKey($section);
          $embeddedStripHtml = trim((string) ($embeddedProductStrips[$sectionId] ?? ''));
          $isOfferSection = !empty($section['is_offer_section']);
          $banner = trim((string) ($section['banner_image_url'] ?? ''));
          $title = $sectionLabel($section);
        ?>
        <section class="hl-shelf<?= $isOfferSection ? ' hl-shelf--offer' : '' ?>" id="<?= h($sectionId) ?>">
          <?php if ($banner !== ''): ?>
            <img
              class="hl-shelf__banner"
              src="<?= h(portal_site_media_display_url($banner, 1280)) ?>"
              alt=""
              loading="lazy"
              decoding="async"
            >
          <?php endif; ?>
          <div class="hl-shelf__body">
            <header class="hl-shelf__intro">
              <?php if ($isOfferSection): ?>
                <span class="hl-shelf__kicker">عرض خاص</span>
              <?php endif; ?>
              <h2><?= h($title) ?></h2>
              <?php if (!empty($section['subtitle_ar'])): ?>
                <p><?= h((string) $section['subtitle_ar']) ?></p>
              <?php endif; ?>
              <a href="<?= h(home_section_store_url($section)) ?>">عرض الكل</a>
            </header>
            <div class="hl-rail" data-hl-rail>
              <button type="button" class="hl-rail__nav hl-rail__nav--prev" data-hl-prev aria-label="المنتجات السابقة">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
              </button>
              <div class="hl-rail__scroller">
                <?php require __DIR__ . '/partials/home-lab-products.php'; ?>
              </div>
              <button type="button" class="hl-rail__nav hl-rail__nav--next" data-hl-next aria-label="المنتجات التالية">
                <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
              </button>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <section class="hl-empty">
      <p>لا توجد أقسام نشطة حالياً.</p>
      <a href="/store.php">الذهاب للمتجر</a>
    </section>
  <?php endif; ?>
</div>
