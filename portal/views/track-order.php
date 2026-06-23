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
$showPriceSyp = true;
$showPriceUsd = (float) ($order['total_usd'] ?? 0) > 0 && (float) ($order['total_sp'] ?? 0) <= 0;
if ((float) ($order['total_sp'] ?? 0) > 0) {
    $showPriceSyp = true;
    $showPriceUsd = false;
}
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
            <dd class="font-extrabold text-primary store-num" dir="ltr"><?= format_money((float) $order['total_sp'], true) ?> ل.س</dd>
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
        <div class="store-order-lines">
          <?php foreach ($items as $item): ?>
            <?php require __DIR__ . '/partials/store-order-line-card.php'; ?>
          <?php endforeach; ?>
        </div>
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

<?php require __DIR__ . '/partials/store-image-lightbox.php'; ?>

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
