<?php

declare(strict_types=1);

/** @var array<string, array<string, array{label: string, icon: string}>> $navigation */
/** @var string $currentRoute */
/** @var string $navContext — sidebar|drawer|bottom */

$navContext ??= 'sidebar';
$linkClass = static function (bool $isActive) use ($navContext): string {
    if ($navContext === 'bottom') {
        return $isActive ? 'is-active' : '';
    }

    return $isActive
        ? 'flex items-center gap-3 rounded-lg px-3 py-2.5 transition bg-primary/10 text-primary font-bold border-r-4 border-primary'
        : 'flex items-center gap-3 rounded-lg px-3 py-2.5 transition text-text-muted hover:bg-surface-low';
};

$iconClass = static function (bool $isActive): string {
    return 'material-symbols-outlined' . ($isActive ? ' fill' : '');
};
?>
<?php foreach ($navigation as $groupTitle => $items): ?>
  <?php if ($navContext !== 'bottom'): ?>
    <section class="mb-4">
      <h3 class="text-xs text-text-muted mb-2 px-2"><?= h($groupTitle) ?></h3>
      <div class="space-y-1">
  <?php endif; ?>
  <?php foreach ($items as $route => $item): ?>
    <?php $isActive = $currentRoute === $route; ?>
    <a
      href="<?= h($route) ?>"
      data-dashboard-route="<?= h($route) ?>"
      class="<?= h($linkClass($isActive)) ?>"
      <?php if ($navContext === 'bottom'): ?>
        title="<?= h($item['label']) ?>"
      <?php endif; ?>
    >
      <span class="<?= h($iconClass($isActive)) ?>"><?= h($item['icon']) ?></span>
      <?php if ($navContext === 'bottom'): ?>
        <span><?= h(mb_strimwidth($item['label'], 0, 10, '…')) ?></span>
      <?php else: ?>
        <span class="text-sm"><?= h($item['label']) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if ($navContext !== 'bottom'): ?>
      </div>
    </section>
  <?php endif; ?>
<?php endforeach; ?>
