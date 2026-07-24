<?php

declare(strict_types=1);

/**
 * @var list<array{code: string, label: string, tone: string, chips: list<array{text: string, url: string}>}> $activeFilterChipGroups
 * @var string $clearAllFiltersUrl
 */

if (!isset($activeFilterChipGroups) || $activeFilterChipGroups === []) {
    return;
}
?>
<section class="store-active-filters" aria-label="الفلاتر المطبّقة">
  <div class="store-active-filters-head">
    <span class="store-active-filters-title">الفلاتر المختارة</span>
    <div class="store-active-filters-actions">
      <?php if (!empty($showMobileFilterEdit)): ?>
        <button type="button" class="store-active-filters-edit lg:hidden" data-store-filters-open>
          <span class="material-symbols-outlined text-sm" aria-hidden="true">tune</span>
          تعديل
        </button>
      <?php endif; ?>
      <a href="<?= h($clearAllFiltersUrl) ?>" class="store-active-filters-clear">مسح الكل</a>
    </div>
  </div>
  <?php foreach ($activeFilterChipGroups as $group): ?>
    <?php if (!is_array($group) || ($group['chips'] ?? []) === []) continue; ?>
    <div class="store-active-filter-group store-active-filter-group--<?= h((string) ($group['tone'] ?? 'default')) ?>">
      <span class="store-active-filter-group-label"><?= h((string) ($group['label'] ?? '')) ?></span>
      <div class="store-active-filter-chips">
        <?php foreach ($group['chips'] as $chip): ?>
          <?php if (!is_array($chip)) continue; ?>
          <a href="<?= h((string) ($chip['url'] ?? '')) ?>" class="store-active-chip" title="إزالة <?= h((string) ($chip['text'] ?? '')) ?>">
            <span><?= h((string) ($chip['text'] ?? '')) ?></span>
            <span class="store-active-chip-remove material-symbols-outlined" aria-hidden="true">close</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
