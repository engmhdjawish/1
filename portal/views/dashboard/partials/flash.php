<?php

declare(strict_types=1);

/** @var string|null $flash */
/** @var string $flashType */

if (!empty($flash)):
?>
<div data-dashboard-flash data-type="<?= h($flashType ?? 'success') ?>" class="hidden" aria-hidden="true"><?= h((string) $flash) ?></div>
<?php endif; ?>
