<?php

declare(strict_types=1);

/** @var array<string, array{icon: string, sections: array<string, array<string, array{label: string, icon: string}>}>} $navigation */
/** @var string $currentRoute */
/** @var string $navIdPrefix */

$navIdPrefix ??= 'nav';
?>
<?php foreach ($navigation as $groupTitle => $group): ?>
  <section class="mb-5">
    <div class="flex items-center gap-2 px-2 py-2 rounded-xl bg-primary/5 border border-primary/10 mb-3">
      <span class="material-symbols-outlined text-primary text-[20px]"><?= h($group['icon']) ?></span>
      <h3 class="font-bold text-sm text-primary"><?= h($groupTitle) ?></h3>
    </div>
    <div class="space-y-3">
      <?php foreach ($group['sections'] as $sectionTitle => $items): ?>
        <div>
          <p class="text-[11px] font-semibold uppercase tracking-wide text-text-muted px-2 mb-1.5"><?= h($sectionTitle) ?></p>
          <div class="space-y-1">
            <?php foreach ($items as $route => $item): ?>
              <?php $isActive = $currentRoute === $route; ?>
              <a
                href="<?= h($route) ?>"
                data-nav-link="1"
                class="flex items-center gap-3 rounded-lg px-3 py-2.5 transition <?= $isActive ? 'bg-primary/10 text-primary font-bold border-r-4 border-primary' : 'text-text-muted hover:bg-surface-low hover:text-slate-800' ?>"
              >
                <span class="material-symbols-outlined <?= $isActive ? 'fill' : '' ?>"><?= h($item['icon']) ?></span>
                <span class="text-sm leading-snug"><?= h($item['label']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
