<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $order */
/** @var string|null $error */
/** @var string $trackingUrl */
/** @var string $token */
?>
<div class="customer-portal max-w-3xl">
  <?php if ($error): ?>
    <section class="customer-form-card text-center py-12">
      <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">search_off</span>
      <h1 class="text-2xl font-extrabold mt-4">تعذر عرض الطلب</h1>
      <p class="text-sm text-gray-600 mt-2"><?= h($error) ?></p>

      <form method="get" class="track-lookup mt-8 text-right">
        <label for="trackTokenInput">أدخل رمز المتابعة من رابط الطلب</label>
        <input id="trackTokenInput" type="text" name="token" value="<?= h($token) ?>" class="store-input w-full font-mono text-sm" dir="ltr" placeholder="الصق الرمز هنا..." required>
        <button type="submit" class="store-btn store-btn--primary">عرض الطلب</button>
      </form>

      <a href="/store.php" class="store-btn store-btn--secondary mt-4">العودة للمتجر</a>
    </section>
  <?php else: ?>
    <?php
      $showTrackingLink = true;
      require __DIR__ . '/partials/customer-order-detail.php';
    ?>
    <?php require __DIR__ . '/partials/store-image-lightbox.php'; ?>

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
          if (field) { field.select(); document.execCommand('copy'); }
        }
      });
    });
  })();
</script>
