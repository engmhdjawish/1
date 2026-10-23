<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $sections */

$directorySections = array_values(array_filter(
    is_array($sections ?? null) ? $sections : [],
    static fn ($section): bool => is_array($section) && trim((string) ($section['title_ar'] ?? '')) !== ''
));
if (count($directorySections) < 2) {
    return;
}
?>
<section class="lab-dir" aria-labelledby="lab-dir-title">
  <h2 id="lab-dir-title" class="lab-dir__title">الأقسام</h2>
  <div class="lab-dir__grid">
    <?php foreach ($directorySections as $section): ?>
      <?php
        $isOffer = !empty($section['is_offer_section']);
        $banner = trim((string) ($section['banner_image_url'] ?? ''));
      ?>
      <a href="<?= h(store_lab_section_url($section)) ?>" class="lab-dir__card<?= $isOffer ? ' lab-dir__card--offer' : '' ?>">
        <span class="lab-dir__media">
          <?php if ($banner !== ''): ?>
            <img src="<?= h(portal_site_media_display_url($banner, 640)) ?>" alt="" loading="lazy" decoding="async">
          <?php else: ?>
            <span class="material-symbols-outlined" aria-hidden="true"><?= $isOffer ? 'local_offer' : 'grid_view' ?></span>
          <?php endif; ?>
          <?php if ($isOffer): ?>
            <span class="lab-dir__badge">عرض خاص</span>
          <?php endif; ?>
        </span>
        <span class="lab-dir__name"><?= h((string) $section['title_ar']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
