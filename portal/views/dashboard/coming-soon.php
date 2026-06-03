<?php

declare(strict_types=1);

/** @var string $heading */
/** @var string $description */
/** @var string $readiness */
/** @var list<array{href: string, label: string}> $nextActions */
?>
<section class="bg-white border rounded-xl p-5 mb-4">
  <h1 class="text-xl font-bold mb-2"><?= h($heading) ?></h1>
  <p class="text-sm text-gray-600 mb-3"><?= h($description) ?></p>
  <span class="inline-flex rounded-full bg-yellow-50 text-yellow-800 px-3 py-1 text-xs">
    حالة الربط: <?= h($readiness) ?>
  </span>
</section>

<section class="bg-white border rounded-xl p-4">
  <h2 class="font-semibold mb-2">الخطوة التالية المقترحة</h2>
  <ul class="space-y-2 text-sm text-gray-700">
    <?php foreach ($nextActions as $action): ?>
      <li>
        <a class="text-primary hover:underline" href="<?= h($action['href']) ?>"><?= h($action['label']) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
