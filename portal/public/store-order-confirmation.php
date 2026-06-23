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

ob_start();
?>
<section class="max-w-lg mx-auto text-center bg-white rounded-2xl border border-gray-200 p-8 shadow-sm">
  <span class="material-symbols-outlined text-5xl text-emerald-600" aria-hidden="true">check_circle</span>
  <h1 class="text-2xl font-extrabold mt-4">تم إرسال طلبك</h1>
  <p class="text-sm text-gray-600 mt-2">رقم الطلب: <strong dir="ltr"><?= h((string) ($order['order_number'] ?? '')) ?></strong></p>
  <?php if (isset($order['total_sp'])): ?>
    <p class="text-sm text-gray-600 mt-1">الإجمالي: <?= format_money((float) $order['total_sp'], true) ?> ل.س</p>
  <?php endif; ?>
  <p class="text-sm text-gray-500 mt-4">سنتواصل معك قريباً لتأكيد الطلب.</p>
  <div class="mt-6 flex flex-wrap justify-center gap-2">
    <a href="/store.php" class="h-11 inline-flex items-center rounded-xl bg-primary text-white px-5 font-bold">العودة للمتجر</a>
    <a href="/login.php?type=customer" class="h-11 inline-flex items-center rounded-xl border border-gray-300 px-5 font-bold">دخول العملاء</a>
  </div>
</section>
<?php
$content = ob_get_clean();
$title = 'تأكيد الطلب';
require dirname(__DIR__) . '/views/layout.php';
