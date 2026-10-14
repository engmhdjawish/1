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

$sectionLabel = static function (array $section): string {
    $title = trim((string) ($section['title_ar'] ?? ''));

    return $title !== '' ? $title : 'قسم';
};
$sectionKey = static function (array $section): string {
    return (string) ($section['slug'] ?? $section['id'] ?? '');
};

$featured = null;
foreach ($sections as $section) {
    if (!empty($section['is_offer_section'])) {
        $featured = $section;
        break;
    }
}
if ($featured === null && $sections !== []) {
    $featured = $sections[0];
}
$featuredKey = is_array($featured) ? $sectionKey($featured) : '';

$discover = [];
foreach ($sections as $section) {
    $label = $sectionLabel($section);
    $discover[] = [
        'kind' => 'section',
        'label' => $label,
        'hint' => !empty($section['is_offer_section']) ? 'عرض' : 'قسم',
        'href' => home_section_store_url($section),
    ];
}
$discoverJson = json_encode($discover, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="hl">
  <aside class="hl-lab-note" aria-label="ملاحظة النسخة التجريبية">
    <p><strong>نسخة تجريبية.</strong> الصفحة الحالية لم تتغيّر. البحث يتوقع من أقسام المتجر وأنواع المواد وأسماء المنتجات.</p>
    <a href="/index.php">الصفحة الحالية</a>
  </aside>

  <section class="hl-stage" aria-labelledby="hl-search-title">
    <p class="hl-stage__kicker"><?= h($siteName) ?></p>
    <h1 id="hl-search-title">ماذا تريد أن تجد؟</h1>
    <p class="hl-stage__lead">اكتب جزءاً من الاسم أو القسم أو القياس. الحقل يخمن الباقي قبل أن تكمل.</p>

    <form class="hl-search" action="/store.php" method="get" role="search" data-hl-search>
      <div class="hl-search__box">
        <span class="material-symbols-outlined hl-search__icon" aria-hidden="true">search</span>
        <div class="hl-search__field">
          <span class="hl-search__ghost" data-hl-ghost hidden></span>
          <input
            id="hl-search-input"
            type="search"
            name="q"
            placeholder="اسم مادة، قسم، أو قياس"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            enterkeyhint="search"
            aria-autocomplete="list"
            aria-controls="hl-suggest"
            aria-expanded="false"
            data-hl-search-input
          >
        </div>
        <button type="button" class="hl-search__accept" data-hl-accept hidden>
          <kbd>Tab</kbd>
          <span>إكمال</span>
        </button>
        <button type="submit" class="hl-search__submit">بحث</button>
      </div>
      <div id="hl-suggest" class="hl-suggest" role="listbox" hidden data-hl-suggest></div>
      <script type="application/json" id="hl-search-dictionary"><?= $discoverJson !== false ? $discoverJson : '[]' ?></script>
    </form>

    <?php if ($discover !== []): ?>
      <div class="hl-prompts" aria-label="اقتراحات للبدء">
        <span>جرّب</span>
        <?php foreach (array_slice($discover, 0, 6) as $item): ?>
          <button type="button" data-hl-prompt="<?= h($item['label']) ?>"><?= h($item['label']) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if (is_array($featured)): ?>
    <?php
      $embeddedStripHtml = trim((string) ($embeddedProductStrips[$featuredKey] ?? ''));
      $featuredTitle = $sectionLabel($featured);
    ?>
    <section class="hl-spot" aria-labelledby="hl-spot-title">
      <header class="hl-spot__head">
        <div>
          <?php if (!empty($featured['is_offer_section'])): ?>
            <p>يُعرض الآن</p>
          <?php else: ?>
            <p>ابدأ من هنا</p>
          <?php endif; ?>
          <h2 id="hl-spot-title"><?= h($featuredTitle) ?></h2>
        </div>
        <a href="<?= h(home_section_store_url($featured)) ?>">عرض الكل</a>
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
    </section>
  <?php endif; ?>

  <?php
    $directory = array_values(array_filter(
        $sections,
        static fn (array $section): bool => $sectionKey($section) !== $featuredKey
    ));
  ?>
  <?php if ($directory !== []): ?>
    <section class="hl-directory" aria-labelledby="hl-directory-title">
      <h2 id="hl-directory-title">تابع الاكتشاف</h2>
      <div class="hl-directory__grid">
        <?php foreach ($directory as $section): ?>
          <a href="<?= h(home_section_store_url($section)) ?>">
            <span class="hl-directory__copy">
              <strong><?= h($sectionLabel($section)) ?></strong>
              <span><?= h(trim((string) ($section['subtitle_ar'] ?? '')) !== '' ? (string) $section['subtitle_ar'] : 'افتح هذا القسم في المتجر') ?></span>
            </span>
            <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

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
  <?php endif; ?>
</div>
