<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string|null $flash */
/** @var string $flashType */

if (!empty($error)): ?>
  <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h((string) $error) ?></div>
<?php endif; ?>

<?php if (!empty($flash)): ?>
  <div class="mb-4 rounded-xl border px-4 py-3 text-sm <?= ($flashType ?? 'success') === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700' ?>">
    <?= h((string) $flash) ?>
  </div>
<?php endif; ?>
