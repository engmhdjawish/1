<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

require dirname(__DIR__) . '/views/helpers.php';

$order = is_array($_SESSION['store_order_success'] ?? null) ? $_SESSION['store_order_success'] : null;
unset($_SESSION['store_order_success']);

if ($order === null) {
    header('Location: /store.php');
    exit;
}

$quoteToken = trim((string) ($order['quote_access_token'] ?? ''));
$trackingUrl = $quoteToken !== '' ? absolute_order_tracking_url($quoteToken) : '';

ob_start();
?>
<section class="store-cart-page max-w-lg mx-auto">
  <div class="store-panel text-center py-10">
    <span class="material-symbols-outlined text-6xl text-emerald-600" aria-hidden="true">check_circle</span>
    <h1 class="text-2xl font-extrabold mt-4">تم إرسال طلبك</h1>
    <p class="text-sm text-gray-600 mt-2">رقم الطلب: <strong dir="ltr"><?= h((string) ($order['order_number'] ?? '')) ?></strong></p>
    <?php if (isset($order['total_sp'])): ?>
      <p class="text-sm text-gray-600 mt-1">الإجمالي: <?= format_money((float) $order['total_sp'], true) ?> ل.س</p>
    <?php endif; ?>
    <p class="text-sm text-gray-500 mt-4">سنتواصل معك قريباً لتأكيد الطلب.</p>

    <?php if ($trackingUrl !== ''): ?>
      <div class="mt-6 text-right store-panel store-panel--accent text-sm">
        <h2 class="font-bold mb-2">رابط متابعة الطلب</h2>
        <p class="text-xs text-gray-600 mb-3">احفظ هذا الرابط لمتابعة حالة طلبك في أي وقت.</p>
        <div class="flex flex-col sm:flex-row gap-2">
          <input type="text" readonly value="<?= h($trackingUrl) ?>" class="store-input flex-1 text-xs font-mono" dir="ltr" id="storeOrderTrackingUrl">
          <button type="button" class="store-btn store-btn--secondary shrink-0" data-copy-tracking-url="<?= h($trackingUrl) ?>">نسخ الرابط</button>
        </div>
        <a href="<?= h(order_tracking_url($quoteToken)) ?>" class="store-btn store-btn--primary w-full mt-3">متابعة الطلب الآن</a>
      </div>
    <?php endif; ?>

    <div class="mt-6 flex flex-wrap justify-center gap-2">
      <a href="/store.php" class="store-btn store-btn--secondary">العودة للمتجر</a>
      <a href="/login.php?type=customer" class="store-btn store-btn--ghost">دخول العملاء</a>
    </div>
  </div>
</section>
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
          const field = document.getElementById('storeOrderTrackingUrl');
          if (field) { field.select(); document.execCommand('copy'); }
        }
      });
    });
  })();
</script>
<?php
$content = ob_get_clean();
$title = 'تأكيد الطلب';
$extraHead = '<link href="/css/store-cart.css" rel="stylesheet">';
require dirname(__DIR__) . '/views/layout.php';
