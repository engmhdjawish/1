<?php

declare(strict_types=1);

use Portal\Services\OrderService;

/** @var array<string, mixed>|null $order */
/** @var string|null $error */
/** @var string $trackingUrl */

$status = (string) ($order['status'] ?? 'pending');
$statusLabel = OrderService::statusLabel($status);
$statusClass = match ($status) {
    'confirmed' => 'track-status--confirmed',
    'completed' => 'track-status--completed',
    'cancelled' => 'track-status--cancelled',
    default => 'track-status--pending',
};
$items = is_array($order['items'] ?? null) ? $order['items'] : [];
$timeline = is_array($order['timeline'] ?? null) ? $order['timeline'] : [];
?>
<div class="store-cart-page max-w-3xl mx-auto">
  <?php if ($error): ?>
    <section class="store-panel text-center py-12">
      <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">search_off</span>
      <h1 class="text-2xl font-extrabold mt-4">تعذر عرض الطلب</h1>
      <p class="text-sm text-gray-600 mt-2"><?= h($error) ?></p>
      <a href="/store.php" class="store-btn store-btn--primary mt-6">العودة للمتجر</a>
    </section>
  <?php else: ?>
    <header class="store-cart-header mb-6">
      <p class="text-sm text-gray-500">متابعة الطلب</p>
      <h1 class="text-2xl md:text-3xl font-extrabold mt-1" dir="ltr"><?= h((string) ($order['order_number'] ?? '')) ?></h1>
      <div class="flex flex-wrap items-center gap-3 mt-3">
        <span class="track-status <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
        <?php if (!empty($order['created_at'])): ?>
          <span class="text-xs text-gray-500"><?= h(accounting_format_date($order['created_at'])) ?></span>
        <?php endif; ?>
      </div>
    </header>

    <section class="store-panel mb-6">
      <h2 class="font-bold text-sm text-gray-600 mb-3">بيانات الطلب</h2>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div>
          <dt class="text-gray-500">الاسم</dt>
          <dd class="font-bold"><?= h((string) ($order['display_name'] ?? '')) ?></dd>
        </div>
        <div>
          <dt class="text-gray-500">الهاتف</dt>
          <dd class="font-bold" dir="ltr"><?= h((string) ($order['display_phone'] ?? '')) ?></dd>
        </div>
        <?php if (!empty($order['total_sp'])): ?>
          <div>
            <dt class="text-gray-500">الإجمالي</dt>
            <dd class="font-extrabold text-primary"><?= format_money((float) $order['total_sp'], true) ?> ل.س</dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($order['notes_ar'])): ?>
          <div class="sm:col-span-2">
            <dt class="text-gray-500">ملاحظات</dt>
            <dd><?= h((string) $order['notes_ar']) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>

    <?php if ($items !== []): ?>
      <section class="store-panel mb-6">
        <h2 class="font-bold mb-4">الأصناف (<?= count($items) ?>)</h2>
        <ul class="divide-y divide-gray-100">
          <?php foreach ($items as $item): ?>
            <?php
              $itemHasOffer = store_line_has_offer($item);
              $itemBadge = store_line_offer_badge($item);
            ?>
            <li class="py-3 flex gap-3 items-start<?= $itemHasOffer ? ' store-order-item--offer' : '' ?>">
              <?php if (!empty($item['image_url'])): ?>
                <div class="relative shrink-0">
                  <img src="<?= h((string) $item['image_url']) ?>" alt="" class="w-14 h-14 rounded-lg object-cover bg-gray-100<?= $itemHasOffer ? ' ring-2 ring-primary/40' : '' ?>">
                  <?php if ($itemHasOffer): ?>
                    <span class="store-order-item__offer-dot" aria-hidden="true"></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="w-14 h-14 rounded-lg bg-gray-100 shrink-0 flex items-center justify-center text-gray-400<?= $itemHasOffer ? ' ring-2 ring-primary/30' : '' ?>">
                  <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                </div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <?php if ($itemHasOffer): ?>
                  <?php $badge = $itemBadge; $size = 'sm'; require __DIR__ . '/partials/offer-item-badge.php'; ?>
                <?php endif; ?>
                <div class="font-bold text-sm"><?= h((string) ($item['material_name_ar'] ?? '')) ?></div>
                <?php if (!empty($item['material_code'])): ?>
                  <div class="text-xs text-gray-500 font-mono store-num" dir="ltr"><?= h((string) $item['material_code']) ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-600 mt-1 store-num" dir="ltr">
                  <?= h(format_packages_display((float) ($item['quantity'] ?? 0))) ?> طرد
                  <?php if (!empty($item['line_total_sp'])): ?>
                    · <?= format_money((float) $item['line_total_sp'], true) ?> ل.س
                  <?php endif; ?>
                  <?php
                    $origSp = (float) ($item['original_sale_price_sp'] ?? 0);
                    $saleSp = (float) ($item['sale_price_sp'] ?? 0);
                  ?>
                  <?php if ($itemHasOffer && $origSp > $saleSp): ?>
                    <span class="text-gray-400 line-through ms-1"><?= format_money($origSp, true) ?> ل.س</span>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if ($timeline !== []): ?>
      <section class="store-panel mb-6">
        <h2 class="font-bold mb-4">سجل التحديثات</h2>
        <ol class="track-timeline">
          <?php foreach ($timeline as $entry): ?>
            <li class="track-timeline__item">
              <span class="track-timeline__dot" aria-hidden="true"></span>
              <div>
                <div class="font-bold text-sm"><?= h((string) ($entry['label'] ?? '')) ?></div>
                <?php if (!empty($entry['at'])): ?>
                  <div class="text-xs text-gray-500"><?= h(accounting_format_date($entry['at'])) ?></div>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
    <?php endif; ?>

    <?php if ($trackingUrl !== ''): ?>
      <section class="store-panel store-panel--accent">
        <h2 class="font-bold text-sm mb-2">احفظ رابط المتابعة</h2>
        <p class="text-xs text-gray-600 mb-3">استخدم هذا الرابط لمتابعة حالة طلبك في أي وقت.</p>
        <div class="flex flex-col sm:flex-row gap-2">
          <input
            type="text"
            readonly
            value="<?= h($trackingUrl) ?>"
            class="store-input flex-1 text-xs font-mono"
            dir="ltr"
            id="orderTrackingUrlField"
          >
          <button type="button" class="store-btn store-btn--secondary shrink-0" data-copy-tracking-url="<?= h($trackingUrl) ?>">
            نسخ الرابط
          </button>
        </div>
      </section>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 justify-center mt-8">
      <a href="/store.php" class="store-btn store-btn--primary">العودة للمتجر</a>
      <a href="/index.php" class="store-btn store-btn--secondary">الرئيسية</a>
    </div>
  <?php endif; ?>
</div>
<script>
  (() => {
    document.querySelectorAll('[data-copy-tracking-url]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const url = btn.getAttribute('data-copy-tracking-url') || '';
        if (!url) return;
        try {
          await navigator.clipboard.writeText(url);
          const prev = btn.textContent;
          btn.textContent = 'تم النسخ';
          setTimeout(() => { btn.textContent = prev; }, 2000);
        } catch {
          const field = document.getElementById('orderTrackingUrlField');
          if (field) {
            field.select();
            document.execCommand('copy');
          }
        }
      });
    });
  })();
</script>
