<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var string $aboutTitle */
/** @var array{intro_paragraphs: list<string>, sections: list<array<string, mixed>>} $aboutContent */
/** @var string|null $companyLogoUrl */

$sectionIcons = about_section_icons();
$introParagraphs = is_array($aboutContent['intro_paragraphs'] ?? null) ? $aboutContent['intro_paragraphs'] : [];
$sections = is_array($aboutContent['sections'] ?? null) ? $aboutContent['sections'] : [];
$companyName = trim((string) ($company['company_name'] ?? ''));

$contactItems = [];
if (trim((string) ($company['company_phone'] ?? '')) !== '') {
    $contactItems[] = ['icon' => 'call', 'label' => 'الهاتف', 'value' => (string) $company['company_phone'], 'href' => 'tel:' . preg_replace('/\D+/', '', (string) $company['company_phone']), 'dir' => 'ltr'];
}
if (trim((string) ($company['company_mobile'] ?? '')) !== '') {
    $contactItems[] = ['icon' => 'smartphone', 'label' => 'الموبايل', 'value' => (string) $company['company_mobile'], 'href' => 'tel:' . preg_replace('/\D+/', '', (string) $company['company_mobile']), 'dir' => 'ltr'];
}
if (trim((string) ($company['company_whatsapp'] ?? '')) !== '') {
    $wa = preg_replace('/\D+/', '', (string) $company['company_whatsapp']);
    $contactItems[] = ['icon' => 'chat', 'label' => 'واتساب', 'value' => (string) $company['company_whatsapp'], 'href' => 'https://wa.me/' . $wa, 'dir' => 'ltr'];
}
if (trim((string) ($company['company_email'] ?? '')) !== '') {
    $contactItems[] = ['icon' => 'mail', 'label' => 'البريد', 'value' => (string) $company['company_email'], 'href' => 'mailto:' . (string) $company['company_email'], 'dir' => 'ltr'];
}
if (trim((string) ($company['company_address'] ?? '')) !== '') {
    $contactItems[] = ['icon' => 'location_on', 'label' => 'العنوان', 'value' => (string) $company['company_address'], 'href' => null, 'dir' => 'rtl', 'kind' => 'address'];
}

$contactChannels = array_values(array_filter($contactItems, static fn (array $item): bool => ($item['kind'] ?? '') !== 'address'));
$contactAddress = null;
foreach ($contactItems as $item) {
    if (($item['kind'] ?? '') === 'address') {
        $contactAddress = $item;
        break;
    }
}

$whatsappItem = null;
foreach ($contactChannels as $index => $item) {
    if ($item['icon'] === 'chat') {
        $whatsappItem = $item;
        unset($contactChannels[$index]);
        break;
    }
}
$contactChannels = array_values($contactChannels);

$hasContent = $introParagraphs !== [] || $sections !== [];
?>
<div class="about-page max-w-6xl mx-auto space-y-10 md:space-y-14">
  <section class="about-hero about-hero-pattern relative overflow-hidden rounded-[2rem] text-white shadow-xl">
    <div class="absolute -left-16 top-0 h-56 w-56 rounded-full bg-white/10 blur-3xl"></div>
    <div class="absolute -right-10 bottom-0 h-44 w-44 rounded-full bg-black/10 blur-2xl"></div>
    <div class="relative px-6 py-10 md:px-12 md:py-14 grid gap-8 lg:grid-cols-[1.15fr_0.85fr] items-center">
      <div>
        <p class="about-hero-kicker text-sm font-bold tracking-wide mb-3 opacity-90">من نحن</p>
        <h1 class="text-3xl md:text-5xl font-extrabold leading-tight"><?= h($aboutTitle) ?></h1>
        <?php if ($companyName !== ''): ?>
          <p class="mt-4 text-lg md:text-xl font-semibold opacity-95"><?= h($companyName) ?></p>
        <?php endif; ?>
        <?php if ($introParagraphs !== []): ?>
          <p class="about-hero-intro mt-5 leading-8 text-base md:text-lg max-w-2xl">
            <?= format_about_inline((string) $introParagraphs[0], true) ?>
          </p>
        <?php endif; ?>
        <div class="mt-8 flex flex-wrap gap-3">
          <a href="/store.php" class="h-11 inline-flex items-center gap-2 rounded-xl bg-white text-primary px-5 font-extrabold shadow-md hover:brightness-105 transition">
            <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
          <?php if ($contactItems !== []): ?>
            <a href="#about-contact" class="about-hero-link h-11 inline-flex items-center gap-2 rounded-xl border border-white/50 px-5 font-bold hover:bg-white/10 transition">
              <span class="material-symbols-outlined text-base" aria-hidden="true">support_agent</span>
              تواصل معنا
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="rounded-2xl border border-white/20 bg-white/10 backdrop-blur-md p-6 md:p-8">
        <?php if (!empty($companyLogoUrl)): ?>
          <img src="<?= h((string) $companyLogoUrl) ?>" alt="<?= h($companyName) ?>" class="h-24 w-auto mx-auto object-contain mb-5 drop-shadow">
        <?php else: ?>
          <div class="h-24 w-24 mx-auto mb-5 rounded-2xl bg-white/15 flex items-center justify-center">
            <span class="material-symbols-outlined text-4xl" aria-hidden="true">storefront</span>
          </div>
        <?php endif; ?>
        <div class="grid grid-cols-3 gap-3 text-center text-sm">
          <div class="rounded-xl bg-white/10 px-2 py-3 border border-white/10">
            <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
            <p class="font-bold mt-1">تشكيلة</p>
          </div>
          <div class="rounded-xl bg-white/10 px-2 py-3 border border-white/10">
            <span class="material-symbols-outlined" aria-hidden="true">verified</span>
            <p class="font-bold mt-1">جودة</p>
          </div>
          <div class="rounded-xl bg-white/10 px-2 py-3 border border-white/10">
            <span class="material-symbols-outlined" aria-hidden="true">handshake</span>
            <p class="font-bold mt-1">ثقة</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if (count($introParagraphs) > 1): ?>
    <section class="about-intro rounded-3xl border border-gray-200 bg-white px-6 py-8 md:px-10 md:py-10 shadow-sm">
      <?php foreach (array_slice($introParagraphs, 1) as $paragraph): ?>
        <p class="text-slate-700 leading-8 text-base md:text-lg"><?= format_about_inline((string) $paragraph) ?></p>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <?php foreach ($sections as $sectionIndex => $section): ?>
    <?php
      $sectionTitle = trim((string) ($section['title'] ?? ''));
      $sectionSubtitle = trim((string) ($section['subtitle'] ?? ''));
      $cards = is_array($section['cards'] ?? null) ? $section['cards'] : [];
      $paragraphs = is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [];
      $listItems = is_array($section['list_items'] ?? null) ? $section['list_items'] : [];
      $quote = trim((string) ($section['quote'] ?? ''));
      $isQuoteSection = $quote !== '' && $cards === [] && $paragraphs === [] && $listItems === [];
    ?>

    <section class="<?= $isQuoteSection ? '' : 'rounded-3xl border border-gray-200 bg-white shadow-sm overflow-hidden' ?>">
      <?php if (!$isQuoteSection && $sectionTitle !== ''): ?>
        <header class="px-6 md:px-10 pt-8 md:pt-10 pb-4 border-b border-gray-100">
          <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary font-extrabold text-sm">
              <?= str_pad((string) ($sectionIndex + 1), 2, '0', STR_PAD_LEFT) ?>
            </span>
            <div>
              <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900"><?= h($sectionTitle) ?></h2>
              <?php if ($sectionSubtitle !== ''): ?>
                <p class="text-sm md:text-base text-slate-500 mt-1"><?= format_about_inline($sectionSubtitle) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </header>
      <?php endif; ?>

      <?php if ($cards !== []): ?>
        <div class="px-6 md:px-10 py-8 md:py-10 space-y-0">
          <?php foreach ($cards as $cardIndex => $card): ?>
            <?php
              $cardTitle = trim((string) ($card['title'] ?? ''));
              $cardBody = trim((string) ($card['body'] ?? ''));
              $icon = $sectionIcons[$cardIndex % count($sectionIcons)];
            ?>
            <article class="about-value-row grid grid-cols-1 md:grid-cols-[4.5rem_1fr] gap-4 md:gap-6 pb-8 md:pb-10">
              <div class="relative z-10 flex md:flex-col items-center md:items-start gap-3">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary text-white shadow-md">
                  <span class="material-symbols-outlined" aria-hidden="true"><?= h($icon) ?></span>
                </span>
                <span class="text-xs font-extrabold text-primary/70 md:mt-1"><?= str_pad((string) ($cardIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
              </div>
              <div class="md:pt-1">
                <?php if ($cardTitle !== ''): ?>
                  <h3 class="text-xl md:text-2xl font-extrabold text-slate-900 mb-2"><?= h($cardTitle) ?></h3>
                <?php endif; ?>
                <?php if ($cardBody !== ''): ?>
                  <p class="text-slate-600 leading-8 text-base md:text-lg"><?= format_about_inline($cardBody) ?></p>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($paragraphs !== []): ?>
        <div class="px-6 md:px-10 <?= $cards !== [] ? 'pb-8 md:pb-10 border-t border-gray-100 pt-6' : 'py-8 md:py-10' ?> space-y-4">
          <?php foreach ($paragraphs as $paragraph): ?>
            <p class="text-slate-700 leading-8 text-base md:text-lg"><?= format_about_inline((string) $paragraph) ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($listItems !== []): ?>
        <ul class="px-6 md:px-10 pb-8 md:pb-10 space-y-3 <?= ($cards !== [] || $paragraphs !== []) ? 'border-t border-gray-100 pt-6' : 'py-8 md:py-10' ?>">
          <?php foreach ($listItems as $item): ?>
            <li class="flex items-start gap-3 text-slate-700 leading-7">
              <span class="mt-2 h-2 w-2 rounded-full bg-primary shrink-0"></span>
              <span><?= format_about_inline((string) $item) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($quote !== ''): ?>
        <blockquote class="about-quote mx-6 md:mx-10 mb-8 md:mb-10 rounded-2xl border border-primary/15 bg-gradient-to-l from-primary/5 via-white to-white px-6 py-8 md:px-10 md:py-10">
          <?php if ($isQuoteSection && $sectionTitle !== ''): ?>
            <p class="text-sm font-bold text-primary mb-3"><?= h($sectionTitle) ?></p>
          <?php endif; ?>
          <p class="text-xl md:text-2xl font-bold text-slate-800 leading-10 relative z-10"><?= format_about_inline($quote) ?></p>
        </blockquote>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if (!$hasContent): ?>
    <section class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-slate-500">
      لم يُضف محتوى تعريفي بعد. يمكن إدارته من لوحة التحكم → الإعدادات → الشركة ومن نحن.
    </section>
  <?php endif; ?>

  <?php if ($contactItems !== []): ?>
    <section id="about-contact" class="about-contact-section overflow-hidden rounded-[2rem] border border-gray-200 bg-white shadow-sm">
      <div class="about-contact-layout grid grid-cols-1 lg:grid-cols-[1fr_1.15fr]">
        <div class="about-contact-intro px-6 py-8 md:px-10 md:py-10 lg:border-l border-gray-100">
          <span class="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-extrabold text-primary mb-4">
            <span class="material-symbols-outlined text-base" aria-hidden="true">support_agent</span>
            تواصل معنا
          </span>
          <h2 class="text-2xl md:text-3xl font-extrabold text-slate-900 leading-tight">نرحّب بتواصلكم</h2>
          <p class="mt-3 text-slate-600 leading-8 text-sm md:text-base max-w-md">
            <?php if ($companyName !== ''): ?>
              فريق <strong class="text-slate-900"><?= h($companyName) ?></strong> جاهز للرد على استفساراتكم ومساعدتكم في الطلبات وتفاصيل المنتجات.
            <?php else: ?>
              نحن جاهزون للرد على استفساراتكم ومساعدتكم في الطلبات وتفاصيل المنتجات.
            <?php endif; ?>
          </p>

          <?php if ($whatsappItem !== null): ?>
            <a
              href="<?= h((string) $whatsappItem['href']) ?>"
              target="_blank"
              rel="noopener"
              class="about-contact-whatsapp mt-6 inline-flex h-12 items-center gap-3 rounded-2xl bg-emerald-600 px-5 text-white font-extrabold shadow-md hover:bg-emerald-700 transition no-underline"
            >
              <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/15">
                <span class="material-symbols-outlined" aria-hidden="true">chat</span>
              </span>
              <span>
                <span class="block text-[11px] font-bold text-emerald-100">تواصل سريع عبر واتساب</span>
                <span class="block text-sm" dir="ltr"><?= h((string) $whatsappItem['value']) ?></span>
              </span>
            </a>
          <?php endif; ?>

          <a href="/store.php" class="mt-4 inline-flex h-10 items-center gap-2 rounded-xl border border-gray-200 px-4 text-sm font-bold text-slate-700 hover:border-primary hover:text-primary transition no-underline">
            <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
            تصفّح المتجر
          </a>
        </div>

        <div class="about-contact-methods px-6 py-8 md:px-10 md:py-10 bg-slate-50/80">
          <p class="text-xs font-bold text-slate-500 mb-4">قنوات التواصل</p>
          <div class="space-y-3">
            <?php foreach ($contactChannels as $item): ?>
              <?php if ($item['href'] !== null): ?>
                <a
                  href="<?= h((string) $item['href']) ?>"
                  <?= str_starts_with((string) $item['href'], 'http') ? 'target="_blank" rel="noopener"' : '' ?>
                  class="about-contact-row group flex items-center gap-4 rounded-2xl border border-gray-200 bg-white px-4 py-4 no-underline text-inherit hover:border-primary/30 hover:shadow-md transition"
                >
              <?php else: ?>
                <div class="about-contact-row flex items-center gap-4 rounded-2xl border border-gray-200 bg-white px-4 py-4">
              <?php endif; ?>
                <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary group-hover:bg-primary group-hover:text-white transition">
                  <span class="material-symbols-outlined" aria-hidden="true"><?= h((string) $item['icon']) ?></span>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="text-xs font-bold text-slate-500 mb-1"><?= h((string) $item['label']) ?></p>
                  <p class="text-base md:text-lg font-extrabold text-slate-900 break-words" dir="<?= h((string) $item['dir']) ?>"><?= h((string) $item['value']) ?></p>
                </div>
                <?php if ($item['href'] !== null): ?>
                  <span class="material-symbols-outlined text-slate-300 group-hover:text-primary transition shrink-0" aria-hidden="true">chevron_left</span>
                <?php endif; ?>
              <?php if ($item['href'] !== null): ?>
                </a>
              <?php else: ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if ($contactAddress !== null): ?>
        <div class="about-contact-address border-t border-gray-100 px-6 py-5 md:px-10 md:py-6 bg-white flex flex-col sm:flex-row sm:items-start gap-3">
          <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
            <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
          </span>
          <div>
            <p class="text-xs font-bold text-slate-500 mb-1"><?= h((string) $contactAddress['label']) ?></p>
            <p class="text-sm md:text-base font-bold text-slate-800 leading-7"><?= h((string) $contactAddress['value']) ?></p>
          </div>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
