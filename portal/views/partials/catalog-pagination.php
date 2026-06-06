<?php

declare(strict_types=1);

/** @var int $page */
/** @var int $totalPages */
/** @var callable(int): string $buildUrl */

if ($totalPages <= 1) {
    return;
}
?>
<nav class="mt-8 flex flex-wrap items-center justify-center gap-2" aria-label="ترقيم الصفحات">
  <?php if ($page > 1): ?>
    <a href="<?= h($buildUrl($page - 1)) ?>" class="h-10 inline-flex items-center px-4 rounded-full border border-gray-300 text-sm font-bold hover:border-primary">السابق</a>
  <?php endif; ?>
  <?php
    $windowStart = max(1, $page - 2);
    $windowEnd = min($totalPages, $page + 2);
    for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++):
      $isCurrent = $pageNumber === $page;
  ?>
    <a
      href="<?= h($buildUrl($pageNumber)) ?>"
      class="h-10 min-w-10 inline-flex items-center justify-center px-3 rounded-full text-sm font-bold border <?= $isCurrent ? 'bg-primary text-white border-primary' : 'border-gray-300 hover:border-primary' ?>"
      <?= $isCurrent ? 'aria-current="page"' : '' ?>
    ><?= (int) $pageNumber ?></a>
  <?php endfor; ?>
  <?php if ($page < $totalPages): ?>
    <a href="<?= h($buildUrl($page + 1)) ?>" class="h-10 inline-flex items-center px-4 rounded-full border border-gray-300 text-sm font-bold hover:border-primary">التالي</a>
  <?php endif; ?>
</nav>
