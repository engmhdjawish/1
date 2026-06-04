<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\ShareLinkService;
use Portal\Support\SharePageAccess;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? ''));
$shareLink = $token !== '' ? ShareLinkService::getByPublicToken($token) : null;
$access = SharePageAccess::state($shareLink, $token);
$hasAccess = $access['has_access'];
$requiresPassword = $access['requires_password'];

$order = null;
if ($token !== '' && isset($_SESSION['share_order_success'][$token]) && is_array($_SESSION['share_order_success'][$token])) {
    $order = $_SESSION['share_order_success'][$token];
    unset($_SESSION['share_order_success'][$token]);
}

$error = null;
if ($token === '') {
    $error = 'رابط غير صالح.';
} elseif ($shareLink === null) {
    $error = 'رابط المشاركة غير صالح.';
} elseif ($requiresPassword && !$hasAccess) {
    $error = 'يرجى فتح رابط المشاركة أولاً.';
} elseif ($order === null) {
    $error = 'لا توجد بيانات طلب للعرض. ربما تم تحديث الصفحة بعد إتمام الطلب.';
}

ob_start();
?>
<div class="bg-white rounded-xl p-6 shadow-sm border max-w-xl mx-auto text-center">
  <?php if ($error): ?>
    <h1 class="text-xl font-extrabold text-red-700 mb-3">تعذر عرض التأكيد</h1>
    <p class="text-sm text-gray-600 mb-4"><?= h($error) ?></p>
    <?php if ($token !== ''): ?>
      <a href="/share.php?token=<?= urlencode($token) ?>" class="inline-flex h-11 items-center rounded bg-primary text-white px-6 font-bold">العودة للمشاركة</a>
    <?php endif; ?>
  <?php else: ?>
    <div class="text-5xl mb-3" aria-hidden="true">✓</div>
    <h1 class="text-2xl font-extrabold text-primary mb-2">تم استلام طلبك</h1>
    <p class="text-sm text-gray-600 mb-6">شكراً لك. سيتواصل معك فريقنا قريباً.</p>
    <div class="rounded-lg border bg-gray-50 p-4 text-right text-sm space-y-2 mb-6">
      <div><span class="text-gray-500">رقم الطلب:</span> <strong><?= h((string) ($order['order_number'] ?? '')) ?></strong></div>
      <?php if (!empty($order['total_sp'])): ?>
        <div><span class="text-gray-500">الإجمالي (ل.س):</span> <strong><?= format_money((float) ($order['total_sp'] ?? 0), true) ?> ل.س</strong></div>
      <?php endif; ?>
      <?php if (!empty($order['total_usd'])): ?>
        <div><span class="text-gray-500">الإجمالي ($):</span> <strong>$<?= number_format((float) ($order['total_usd'] ?? 0), 2, '.', ',') ?></strong></div>
      <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2 justify-center">
      <a href="/share.php?token=<?= urlencode($token) ?>" class="h-11 inline-flex items-center rounded bg-primary text-white px-6 font-bold">متابعة التصفح</a>
      <a href="/index.php" class="h-11 inline-flex items-center rounded border border-gray-300 px-6 text-sm font-bold">الرئيسية</a>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'تأكيد الطلب';
require dirname(__DIR__) . '/views/layout.php';
