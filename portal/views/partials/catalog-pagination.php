<?php

declare(strict_types=1);

/** @var int $page */
/** @var int $totalPages */
/** @var callable(int): string $buildUrl */

if ($totalPages <= 1) {
    return;
}
?>
<nav class="store-pagination" aria-label="ترقيم الصفحات">
  <?php if ($page > 1): ?>
    <a href="<?= h($buildUrl(1)) ?>" class="store-pagination__btn" title="الصفحة الأولى">
      <span class="material-symbols-outlined text-base" aria-hidden="true">first_page</span>
      <span>الأول</span>
    </a>
    <a href="<?= h($buildUrl($page - 1)) ?>" class="store-pagination__btn">
      <span class="material-symbols-outlined text-base" aria-hidden="true">chevron_right</span>
      <span>السابق</span>
    </a>
  <?php endif; ?>

  <div class="store-pagination__pages">
    <?php
      $windowStart = max(1, $page - 2);
      $windowEnd = min($totalPages, $page + 2);
      for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++):
        $isCurrent = $pageNumber === $page;
    ?>
      <a
        href="<?= h($buildUrl($pageNumber)) ?>"
        class="store-pagination__page <?= $isCurrent ? 'is-current' : '' ?>"
        <?= $isCurrent ? 'aria-current="page"' : '' ?>
      ><?= (int) $pageNumber ?></a>
    <?php endfor; ?>
  </div>

  <?php if ($page < $totalPages): ?>
    <a href="<?= h($buildUrl($page + 1)) ?>" class="store-pagination__btn">
      <span>التالي</span>
      <span class="material-symbols-outlined text-base" aria-hidden="true">chevron_left</span>
    </a>
    <a href="<?= h($buildUrl($totalPages)) ?>" class="store-pagination__btn" title="الصفحة الأخيرة">
      <span>الأخير</span>
      <span class="material-symbols-outlined text-base" aria-hidden="true">last_page</span>
    </a>
  <?php endif; ?>
</nav>
