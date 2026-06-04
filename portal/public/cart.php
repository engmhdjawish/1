<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\OrderService;
use Portal\Services\ShareCartService;
use Portal\Services\ShareLinkService;
use Portal\Support\SharePageAccess;

require dirname(__DIR__) . '/views/helpers.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$shareLink = $token !== '' ? ShareLinkService::getByPublicToken($token) : null;
$error = null;
$notice = null;
$access = SharePageAccess::state($shareLink, $token);
$requiresPassword = $access['requires_password'];
$hasAccess = $access['has_access'];
$policy = SharePageAccess::policyFlags($shareLink);
$allowCart = $policy['allow_cart'];
$allowOrder = $policy['allow_order'];
$showPrice = $policy['show_price'];

if ($token === '') {
    $error = 'يرجى فتح السلة من رابط مشاركة صحيح.';
} elseif ($shareLink === null) {
    $error = 'الرابط غير صالح أو غير نشط أو منتهي الصلاحية.';
} elseif (!$allowCart) {
    $error = 'سياسة هذا الرابط لا تسمح باستخدام السلة.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock' && $shareLink !== null) {
    if (SharePageAccess::unlock(
        $token,
        trim((string) ($_POST['access_username'] ?? '')),
        trim((string) ($_POST['access_password'] ?? ''))
    )) {
        $hasAccess = true;
    } else {
        $error = 'بيانات الدخول غير صحيحة.';
    }
}

if ($shareLink !== null && $hasAccess && !$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'update_item') {
        $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
        $quantity = (float) ($_POST['quantity'] ?? 0);
        if (ShareCartService::updateQuantity($token, $materialGuid, $quantity)) {
            $notice = $quantity > 0 ? 'تم تحديث الكمية.' : 'تم حذف المادة من السلة.';
        }
    } elseif ($action === 'remove_item') {
        $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
        if (ShareCartService::remove($token, $materialGuid)) {
            $notice = 'تم حذف المادة من السلة.';
        }
    } elseif ($action === 'clear_cart') {
        ShareCartService::clear($token);
        $notice = 'تم تفريغ السلة.';
    } elseif ($action === 'submit_order' && $allowOrder) {
        $guestName = trim((string) ($_POST['guest_name_ar'] ?? ''));
        $guestPhone = trim((string) ($_POST['guest_phone'] ?? ''));
        $notes = trim((string) ($_POST['notes_ar'] ?? ''));
        $cartItems = array_values(ShareCartService::items($token));
        if ($guestName === '' || mb_strlen($guestName) < 2) {
            $error = 'يرجى إدخال اسم صحيح (حرفان على الأقل).';
        } elseif ($guestPhone === '' || preg_match('/\d{8,}/', preg_replace('/\D+/', '', $guestPhone)) !== 1) {
            $error = 'يرجى إدخال رقم هاتف صحيح (8 أرقام على الأقل).';
        } elseif ($cartItems === []) {
            $error = 'السلة فارغة.';
        } else {
            $shareLinkId = (string) ($shareLink['id'] ?? ShareCartService::shareLinkId($token) ?? '');
            $order = OrderService::createGuestShareOrder(
                $shareLinkId,
                $guestName,
                $guestPhone,
                $notes !== '' ? $notes : null,
                $cartItems
            );
            if ($order === null) {
                $error = 'تعذر حفظ الطلب. حاول مرة أخرى أو تواصل معنا.';
            } else {
                ShareCartService::clear($token);
                if (!isset($_SESSION['share_order_success']) || !is_array($_SESSION['share_order_success'])) {
                    $_SESSION['share_order_success'] = [];
                }
                $_SESSION['share_order_success'][$token] = $order;
                header('Location: /order-confirmation.php?token=' . rawurlencode($token), true, 303);
                exit;
            }
        }
    } elseif ($action === 'submit_order' && !$allowOrder) {
        $error = 'سياسة هذا الرابط لا تسمح بإرسال الطلبات.';
    }
}

$cartItems = ($shareLink !== null && $hasAccess && !$error) ? ShareCartService::items($token) : [];
$totals = ($shareLink !== null && $hasAccess && !$error) ? ShareCartService::totals($token) : ['total_sp' => 0.0, 'total_usd' => 0.0];
$shareName = is_array($shareLink) ? (string) ($shareLink['name_ar'] ?? 'رابط مشاركة') : 'رابط مشاركة';

ob_start();
?>
<div class="bg-white rounded-xl p-6 shadow-sm border">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-extrabold">سلة المشتريات</h1>
      <p class="text-sm text-gray-600 mt-1"><?= h($shareName) ?></p>
    </div>
    <?php if ($token !== '' && $hasAccess && !$error): ?>
      <a href="/share.php?token=<?= urlencode($token) ?>" class="h-10 inline-flex items-center px-4 rounded-full border border-gray-300 text-sm font-bold hover:border-primary">
        متابعة التصفح
      </a>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <p class="mb-4 rounded border bg-red-50 border-red-200 text-red-700 px-3 py-2 text-sm"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($notice): ?>
    <p class="mb-4 rounded border bg-green-50 border-green-200 text-green-700 px-3 py-2 text-sm"><?= h($notice) ?></p>
  <?php endif; ?>

  <?php if ($requiresPassword && !$hasAccess && $shareLink !== null): ?>
    <form method="post" class="max-w-md rounded-xl border border-gray-200 p-4 space-y-3">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="unlock">
      <p class="text-sm text-gray-600">هذا الرابط محمي بكلمة مرور.</p>
      <label class="block text-sm font-bold">اسم المستخدم
        <input name="access_username" class="h-11 w-full rounded border border-gray-300 px-3 mt-1">
      </label>
      <label class="block text-sm font-bold">كلمة المرور
        <input type="password" name="access_password" class="h-11 w-full rounded border border-gray-300 px-3 mt-1">
      </label>
      <button class="h-11 rounded bg-primary text-white px-6 font-bold">دخول</button>
    </form>
  <?php elseif ($hasAccess && !$error): ?>
    <?php if ($cartItems === []): ?>
      <p class="text-gray-500">السلة فارغة.</p>
      <a href="/share.php?token=<?= urlencode($token) ?>" class="mt-4 inline-flex h-11 items-center rounded bg-primary text-white px-6 font-bold">تصفح المواد</a>
    <?php else: ?>
      <div class="space-y-3 mb-6">
        <?php foreach ($cartItems as $line): ?>
          <?php
            $materialGuid = (string) ($line['material_guid'] ?? '');
            $lineTotalSp = (float) ($line['quantity'] ?? 0) * (float) ($line['sale_price_sp'] ?? 0);
            $lineTotalUsd = (float) ($line['quantity'] ?? 0) * (float) ($line['sale_price_usd'] ?? 0);
          ?>
          <article class="border rounded-lg p-4 flex flex-wrap gap-4 items-start">
            <?php if (!empty($line['image_url'])): ?>
              <img src="<?= h((string) $line['image_url']) ?>" alt="" class="w-20 h-20 object-cover rounded bg-gray-100" loading="lazy">
            <?php endif; ?>
            <div class="flex-1 min-w-[200px]">
              <div class="font-bold"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
              <?php if (!empty($line['material_code'])): ?>
                <div class="text-xs text-gray-500"><?= h((string) $line['material_code']) ?></div>
              <?php endif; ?>
              <?php if ($showPrice): ?>
                <div class="text-sm text-primary font-bold mt-1">
                  <?= format_money((float) ($line['sale_price_sp'] ?? 0), true) ?> ل.س
                  <?php if ((float) ($line['sale_price_usd'] ?? 0) > 0): ?>
                    <span class="text-emerald-700"> / $<?= number_format((float) $line['sale_price_usd'], 2, '.', ',') ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <form method="post" class="flex items-end gap-2">
              <input type="hidden" name="token" value="<?= h($token) ?>">
              <input type="hidden" name="action" value="update_item">
              <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
              <label class="text-xs font-bold">الكمية
                <input type="number" name="quantity" min="0" step="0.01" value="<?= h((string) ($line['quantity'] ?? 1)) ?>" class="h-10 w-24 rounded border border-gray-300 px-2 mt-1">
              </label>
              <button class="h-10 px-3 rounded border border-gray-300 text-sm font-bold">تحديث</button>
            </form>
            <form method="post">
              <input type="hidden" name="token" value="<?= h($token) ?>">
              <input type="hidden" name="action" value="remove_item">
              <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
              <button class="h-10 px-3 rounded border border-red-200 text-red-700 text-sm font-bold">حذف</button>
            </form>
            <?php if ($showPrice): ?>
              <div class="text-sm font-bold text-gray-700">
                المجموع: <?= format_money($lineTotalSp, true) ?> ل.س
                <?php if ($lineTotalUsd > 0): ?>
                  <span class="text-emerald-700">($<?= number_format($lineTotalUsd, 2, '.', ',') ?>)</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($showPrice): ?>
        <div class="rounded-lg bg-gray-50 border p-4 mb-6 text-sm">
          <div class="flex justify-between font-bold">
            <span>الإجمالي (ل.س)</span>
            <span><?= format_money((float) $totals['total_sp'], true) ?> ل.س</span>
          </div>
          <?php if ((float) $totals['total_usd'] > 0): ?>
            <div class="flex justify-between font-bold text-emerald-700 mt-1">
              <span>الإجمالي ($)</span>
              <span>$<?= number_format((float) $totals['total_usd'], 2, '.', ',') ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="inline">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <input type="hidden" name="action" value="clear_cart">
        <button type="submit" class="h-10 px-4 rounded border border-gray-300 text-sm font-bold mb-6">تفريغ السلة</button>
      </form>

      <?php if ($allowOrder): ?>
        <section class="border-t pt-6">
          <h2 class="text-lg font-extrabold mb-3">إرسال الطلب (بدون تسجيل دخول)</h2>
          <form method="post" class="max-w-lg space-y-3">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="submit_order">
            <label class="block text-sm font-bold">الاسم الكامل *
              <input name="guest_name_ar" required class="h-11 w-full rounded border border-gray-300 px-3 mt-1" placeholder="اسمك">
            </label>
            <label class="block text-sm font-bold">رقم الهاتف *
              <input name="guest_phone" required dir="ltr" class="h-11 w-full rounded border border-gray-300 px-3 mt-1 text-left" placeholder="09xxxxxxxx">
            </label>
            <label class="block text-sm font-bold">ملاحظات
              <textarea name="notes_ar" rows="3" class="w-full rounded border border-gray-300 px-3 py-2 mt-1" placeholder="اختياري"></textarea>
            </label>
            <button class="h-12 w-full rounded bg-primary text-white font-extrabold">تأكيد وإرسال الطلب</button>
          </form>
        </section>
      <?php else: ?>
        <p class="text-sm text-amber-700 rounded border border-amber-200 bg-amber-50 px-3 py-2">سياسة هذا الرابط تسمح بالسلة فقط دون إرسال طلب.</p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'السلة';
require dirname(__DIR__) . '/views/layout.php';
