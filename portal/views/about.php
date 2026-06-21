<?php

declare(strict_types=1);

/** @var array<string, string> $company */
/** @var string $aboutTitle */
/** @var array{intro: string, sections: list<array{title: string, items: list<array{title: string, body: string}>, paragraphs: list<string>}>} $aboutContent */
/** @var string|null $companyLogoUrl */

$commitmentIcons = about_commitment_icons();
$hasContact = trim((string) ($company['company_address'] ?? '')) !== ''
    || trim((string) ($company['company_phone'] ?? '')) !== ''
    || trim((string) ($company['company_mobile'] ?? '')) !== ''
    || trim((string) ($company['company_whatsapp'] ?? '')) !== ''
    || trim((string) ($company['company_email'] ?? '')) !== '';
?>
<section class="max-w-5xl mx-auto">
  <header class="relative overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm mb-8">
    <div class="absolute inset-0 bg-gradient-to-br from-primary/10 via-white to-amber-50"></div>
    <div class="absolute -left-10 -top-10 h-40 w-40 rounded-full bg-primary/10 blur-2xl"></div>
    <div class="absolute -right-8 bottom-0 h-32 w-32 rounded-full bg-amber-200/40 blur-2xl"></div>
    <div class="relative px-6 py-10 md:px-10 md:py-12 text-center">
      <?php if (!empty($companyLogoUrl)): ?>
        <img src="<?= h((string) $companyLogoUrl) ?>" alt="<?= h((string) ($company['company_name'] ?? '')) ?>" class="h-20 w-auto mx-auto mb-5 object-contain drop-shadow-sm">
      <?php endif; ?>
      <p class="text-xs font-bold tracking-wide text-primary mb-2">تعرف علينا</p>
      <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900"><?= h($aboutTitle) ?></h1>
      <?php if (trim((string) ($company['company_name'] ?? '')) !== ''): ?>
        <p class="text-text-muted mt-3 text-base md:text-lg"><?= h((string) $company['company_name']) ?></p>
      <?php endif; ?>
    </div>
  </header>

  <?php if (($aboutContent['intro'] ?? '') !== ''): ?>
    <article class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 md:p-8 shadow-sm">
      <div class="flex items-start gap-4">
        <span class="hidden sm:flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary">
          <span class="material-symbols-outlined text-2xl" aria-hidden="true">storefront</span>
        </span>
        <p class="text-slate-700 leading-8 text-base md:text-lg"><?= nl2br(h((string) $aboutContent['intro'])) ?></p>
      </div>
    </article>
  <?php endif; ?>

  <?php foreach ($aboutContent['sections'] as $section): ?>
    <?php
      $sectionTitle = (string) ($section['title'] ?? '');
      $items = is_array($section['items'] ?? null) ? $section['items'] : [];
      $paragraphs = is_array($section['paragraphs'] ?? null) ? $section['paragraphs'] : [];
    ?>
    <section class="mb-8">
      <?php if ($sectionTitle !== ''): ?>
        <div class="mb-5 flex items-center gap-3">
          <span class="h-10 w-1.5 rounded-full bg-primary"></span>
          <h2 class="text-2xl font-extrabold text-slate-900"><?= h($sectionTitle) ?></h2>
        </div>
      <?php endif; ?>

      <?php if ($items !== []): ?>
        <?php
          $gridClass = match (min(3, count($items))) {
              1 => 'md:grid-cols-1',
              2 => 'md:grid-cols-2',
              default => 'md:grid-cols-3',
          };
        ?>
        <div class="grid grid-cols-1 <?= $gridClass ?> gap-4">
          <?php foreach ($items as $index => $item): ?>
            <?php
              $itemTitle = trim((string) ($item['title'] ?? ''));
              $itemBody = trim((string) ($item['body'] ?? ''));
              $icon = $commitmentIcons[$index % count($commitmentIcons)];
            ?>
            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm hover:shadow-md transition">
              <div class="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <span class="material-symbols-outlined" aria-hidden="true"><?= h($icon) ?></span>
              </div>
              <?php if ($itemTitle !== ''): ?>
                <h3 class="font-extrabold text-slate-900 mb-2"><?= h($itemTitle) ?></h3>
              <?php endif; ?>
              <?php if ($itemBody !== ''): ?>
                <p class="text-sm md:text-base text-slate-600 leading-7"><?= h($itemBody) ?></p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php foreach ($paragraphs as $paragraph): ?>
        <?php $paragraph = trim((string) $paragraph); ?>
        <?php if ($paragraph === '') continue; ?>
        <article class="mt-4 rounded-2xl border border-primary/20 bg-gradient-to-l from-primary/5 to-white p-6 md:p-7 shadow-sm">
          <p class="text-slate-700 leading-8 text-base md:text-lg"><?= h($paragraph) ?></p>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if (($aboutContent['intro'] ?? '') === '' && ($aboutContent['sections'] ?? []) === []): ?>
    <p class="text-center text-text-muted mb-8 rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-10">
      لم يُضف محتوى تعريفي بعد. يمكن إدارته من لوحة التحكم → الإعدادات → الشركة ومن نحن.
    </p>
  <?php endif; ?>

  <div class="mb-8 flex flex-wrap items-center justify-center gap-3">
    <a href="/store.php" class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary text-white px-6 font-bold hover:brightness-110 transition">
      <span class="material-symbols-outlined text-base" aria-hidden="true">storefront</span>
      تصفّح المتجر
    </a>
    <?php if ($hasContact): ?>
      <a href="#about-contact" class="inline-flex h-11 items-center gap-2 rounded-xl border border-gray-300 bg-white px-6 font-bold text-slate-700 hover:border-primary hover:text-primary transition">
        <span class="material-symbols-outlined text-base" aria-hidden="true">call</span>
        تواصل معنا
      </a>
    <?php endif; ?>
  </div>

  <?php if ($hasContact): ?>
    <aside id="about-contact" class="rounded-2xl border border-gray-200 bg-white p-6 md:p-8 shadow-sm">
      <div class="mb-5 flex items-center gap-3">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
          <span class="material-symbols-outlined" aria-hidden="true">contact_phone</span>
        </span>
        <h2 class="font-extrabold text-xl text-slate-900">تواصل معنا</h2>
      </div>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <?php if (trim((string) ($company['company_address'] ?? '')) !== ''): ?>
          <div class="rounded-xl border border-gray-100 bg-surface-bg p-4">
            <dt class="text-text-muted text-xs mb-1">العنوان</dt>
            <dd class="font-semibold leading-relaxed"><?= h((string) $company['company_address']) ?></dd>
          </div>
        <?php endif; ?>
        <?php if (trim((string) ($company['company_phone'] ?? '')) !== ''): ?>
          <div class="rounded-xl border border-gray-100 bg-surface-bg p-4">
            <dt class="text-text-muted text-xs mb-1">الهاتف</dt>
            <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_phone']) ?></dd>
          </div>
        <?php endif; ?>
        <?php if (trim((string) ($company['company_mobile'] ?? '')) !== ''): ?>
          <div class="rounded-xl border border-gray-100 bg-surface-bg p-4">
            <dt class="text-text-muted text-xs mb-1">الموبايل</dt>
            <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_mobile']) ?></dd>
          </div>
        <?php endif; ?>
        <?php if (trim((string) ($company['company_whatsapp'] ?? '')) !== ''): ?>
          <div class="rounded-xl border border-gray-100 bg-surface-bg p-4">
            <dt class="text-text-muted text-xs mb-1">واتساب</dt>
            <dd class="font-semibold" dir="ltr"><?= h((string) $company['company_whatsapp']) ?></dd>
          </div>
        <?php endif; ?>
        <?php if (trim((string) ($company['company_email'] ?? '')) !== ''): ?>
          <div class="rounded-xl border border-gray-100 bg-surface-bg p-4 sm:col-span-2">
            <dt class="text-text-muted text-xs mb-1">البريد</dt>
            <dd><a href="mailto:<?= h((string) $company['company_email']) ?>" class="font-semibold text-primary hover:underline" dir="ltr"><?= h((string) $company['company_email']) ?></a></dd>
          </div>
        <?php endif; ?>
      </dl>
    </aside>
  <?php endif; ?>
</section>
