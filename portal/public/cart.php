<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\OrderService;
use Portal\Services\ShareCartService;
use Portal\Services\ShareLinkService;
use Portal\Services\StorePolicyService;
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
$loggedInCustomer = CustomerSession::check() ? CustomerSession::customer() : null;
$defaultGuestName = (string) ($loggedInCustomer['name_ar'] ?? '');
$defaultGuestPhone = (string) ($loggedInCustomer['phone'] ?? '');

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
    $materialGuid = trim((string) ($_POST['material_guid'] ?? ''));
    if ($action === 'update_item') {
        $quantity = max(0, (int) round((float) ($_POST['quantity'] ?? 0)));
        $result = ShareCartService::updateQuantity($token, $materialGuid, (float) $quantity);
        if ($result['ok']) {
            $notice = $quantity > 0 ? 'تم تحديث عدد الطرود.' : 'تم حذف المادة من السلة.';
            if ($result['message'] !== '') {
                $notice = $result['message'];
            }
        } else {
            if (!empty($result['moved_unavailable'])) {
                $notice = $result['message'] !== '' ? $result['message'] : 'نُقل الصنف إلى قائمة غير المتوفرة.';
            } else {
                $error = $result['message'] !== '' ? $result['message'] : 'تعذر تحديث الكمية.';
            }
        }
    } elseif ($action === 'bump_item') {
        $delta = (int) ($_POST['delta'] ?? 0);
        $items = ShareCartService::items($token);
        $current = (int) round((float) ($items[$materialGuid]['quantity'] ?? 1));
        $next = max(0, $current + $delta);
        $result = ShareCartService::updateQuantity($token, $materialGuid, (float) $next);
        if ($result['ok']) {
            $notice = $next > 0 ? 'تم تحديث عدد الطرود.' : 'تم حذف المادة من السلة.';
            if ($result['message'] !== '') {
                $notice = $result['message'];
            }
        } else {
            if (!empty($result['moved_unavailable'])) {
                $notice = $result['message'] !== '' ? $result['message'] : 'نُقل الصنف إلى قائمة غير المتوفرة.';
            } else {
                $error = $result['message'] !== '' ? $result['message'] : 'تعذر تحديث الكمية.';
            }
        }
    } elseif ($action === 'remove_item') {
        if (ShareCartService::remove($token, $materialGuid)) {
            $notice = 'تم حذف المادة من السلة.';
        }
    } elseif ($action === 'clear_cart') {
        ShareCartService::clear($token);
        $notice = 'تم تفريغ السلة.';
    } elseif ($action === 'remove_unavailable') {
        if (ShareCartService::removeUnavailable($token, $materialGuid)) {
            $notice = 'تم إزالة الصنف من قائمة غير المتوفرة.';
        }
    } elseif ($action === 'clear_unavailable') {
        ShareCartService::clearUnavailable($token);
        $notice = 'تم تفريغ قائمة الأصناف غير المتوفرة.';
    } elseif ($action === 'submit_order' && $allowOrder) {
        $guestName = trim((string) ($_POST['guest_name_ar'] ?? ''));
        $guestPhone = trim((string) ($_POST['guest_phone'] ?? ''));
        $notes = trim((string) ($_POST['notes_ar'] ?? ''));
        if ($loggedInCustomer !== null) {
            $guestName = trim((string) ($loggedInCustomer['name_ar'] ?? ''));
            $guestPhone = trim((string) ($loggedInCustomer['phone'] ?? ''));
        }
        $cartItems = array_values(ShareCartService::items($token));
        if ($guestName === '' || text_length($guestName) < 2) {
            $error = 'يرجى إدخال اسم صحيح (حرفان على الأقل).';
        } elseif ($guestPhone === '' || preg_match('/\d{8,}/', preg_replace('/\D+/', '', $guestPhone)) !== 1) {
            $error = 'يرجى إدخال رقم هاتف صحيح (8 أرقام على الأقل).';
        } elseif ($cartItems === []) {
            $error = 'السلة فارغة.';
        } else {
            $shareLinkId = (string) ($shareLink['id'] ?? ShareCartService::shareLinkId($token) ?? '');
            $result = OrderService::createGuestShareOrder(
                $shareLinkId,
                $guestName,
                $guestPhone,
                $notes !== '' ? $notes : null,
                $cartItems,
                $loggedInCustomer !== null ? (string) ($loggedInCustomer['id'] ?? '') : null
            );
            if (!$result['ok']) {
                ShareCartService::stashUnavailableLines($token, is_array($result['unavailable_items'] ?? null) ? $result['unavailable_items'] : []);
                $error = (string) ($result['message'] ?? 'تعذر حفظ الطلب. حاول مرة أخرى أو تواصل معنا.');
            } else {
                $order = is_array($result['order'] ?? null) ? $result['order'] : null;
                if ($order === null) {
                    $error = 'تعذر حفظ الطلب. حاول مرة أخرى أو تواصل معنا.';
                } else {
                    ShareCartService::finalizeAfterSuccessfulOrder(
                        $token,
                        is_array($result['submitted_material_guids'] ?? null) ? $result['submitted_material_guids'] : [],
                        is_array($result['unavailable_items'] ?? null) ? $result['unavailable_items'] : []
                    );
                    if (!isset($_SESSION['share_order_success']) || !is_array($_SESSION['share_order_success'])) {
                        $_SESSION['share_order_success'] = [];
                    }
                    $order['partial_notices'] = is_array($result['notices'] ?? null) ? $result['notices'] : [];
                    $order['had_unavailable_items'] = is_array($result['unavailable_items'] ?? null) && $result['unavailable_items'] !== [];
                    $_SESSION['share_order_success'][$token] = $order;
                    header('Location: /order-confirmation.php?token=' . rawurlencode($token), true, 303);
                    exit;
                }
            }
        }
    } elseif ($action === 'submit_order' && !$allowOrder) {
        $error = 'سياسة هذا الرابط لا تسمح بإرسال الطلبات.';
    }
}

$stockNotices = [];
if ($shareLink !== null && $hasAccess && $token !== '') {
    $reconcile = ShareCartService::reconcileStock($token);
    if ($reconcile['notices'] !== []) {
        $stockNotices = $reconcile['notices'];
    }
}

$cartItems = ($shareLink !== null && $hasAccess) ? ShareCartService::items($token) : [];
$unavailableItems = ($shareLink !== null && $hasAccess) ? ShareCartService::unavailableItems($token) : [];
$totals = ($shareLink !== null && $hasAccess) ? ShareCartService::totals($token) : ['total_sp' => 0.0, 'total_usd' => 0.0];
$shareName = is_array($shareLink) ? (string) ($shareLink['name_ar'] ?? 'رابط مشاركة') : 'رابط مشاركة';
$cartCount = ShareCartService::itemCount($token);
$maxPackagesPerMaterial = StorePolicyService::maxPackagesPerMaterial();

ob_start();
?>
<div class="max-w-6xl mx-auto">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <h1 class="text-2xl md:text-3xl font-extrabold">سلة المشتريات</h1>
      <p class="text-sm text-gray-600 mt-1"><?= h($shareName) ?> — الطلب بالطرد (الوحدة الثانية)</p>
      <p class="text-xs text-amber-700 mt-1">إن ظهرت أسعار خاطئة، <strong>أفرغ السلة</strong> وأعد الإضافة من صفحة التصفح بعد التحديث.</p>
      <?php if ($maxPackagesPerMaterial !== null): ?>
        <p class="text-xs text-gray-600 mt-1">الحد الأقصى للطلب: <?= h(\Portal\Services\SpecialOfferService::formatQuantityLabel($maxPackagesPerMaterial)) ?> طرد لكل مادة.</p>
      <?php endif; ?>
    </div>
    <?php if ($token !== '' && $hasAccess): ?>
      <a
        href="/share.php?token=<?= urlencode($token) ?>"
        class="h-11 inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-5 text-sm font-bold hover:border-primary"
      >
        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">storefront</span>
        متابعة التصفح
      </a>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <p class="mb-4 rounded-xl border bg-red-50 border-red-200 text-red-700 px-4 py-3 text-sm"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($notice): ?>
    <p class="mb-4 rounded-xl border bg-green-50 border-green-200 text-green-700 px-4 py-3 text-sm"><?= h($notice) ?></p>
  <?php endif; ?>
  <?php if ($stockNotices !== []): ?>
    <div class="mb-4 rounded-xl border bg-amber-50 border-amber-200 text-amber-900 px-4 py-3 text-sm space-y-1">
      <p class="font-bold">تنبيه المخزون</p>
      <?php foreach ($stockNotices as $stockNotice): ?>
        <p><?= h($stockNotice) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($requiresPassword && !$hasAccess && $shareLink !== null): ?>
    <form method="post" class="max-w-md rounded-xl border border-gray-200 bg-white p-5 space-y-3 shadow-sm">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="action" value="unlock">
      <p class="text-sm text-gray-600">هذا الرابط محمي بكلمة مرور.</p>
      <label class="block text-sm font-bold">اسم المستخدم
        <input name="access_username" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1">
      </label>
      <label class="block text-sm font-bold">كلمة المرور
        <input type="password" name="access_password" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1">
      </label>
      <button class="h-11 rounded-lg bg-primary text-white px-6 font-bold">دخول</button>
    </form>
  <?php elseif ($hasAccess): ?>
    <?php if ($cartItems === [] && $unavailableItems === []): ?>
      <div class="bg-white rounded-xl border p-8 text-center shadow-sm">
        <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">shopping_cart</span>
        <p class="text-gray-500 mt-3">السلة فارغة.</p>
        <a href="/share.php?token=<?= urlencode($token) ?>" class="mt-4 inline-flex h-11 items-center rounded-lg bg-primary text-white px-6 font-bold">تصفح المواد</a>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-8">
          <?php if ($cartItems === []): ?>
            <div class="bg-white rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 mb-4">
              لا توجد أصناف جاهزة للطلب في السلة حالياً.
            </div>
          <?php else: ?>
          <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse min-w-[640px]">
                <thead>
                  <tr class="bg-gray-50 border-b border-gray-200 text-sm text-gray-600">
                    <th class="p-4 font-bold">المنتج</th>
                    <th class="p-4 font-bold">سعر الطرد</th>
                    <th class="p-4 font-bold">الكمية</th>
                    <th class="p-4 font-bold">الإجمالي</th>
                    <th class="p-4 font-bold text-center">حذف</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                  <?php foreach ($cartItems as $line): ?>
                    <?php
                      $materialGuid = (string) ($line['material_guid'] ?? '');
                      $packageUnit = (string) ($line['package_unit'] ?? 'طرد');
                      $primaryUnit = (string) ($line['primary_unit'] ?? 'قطعة');
                      $packaging = (float) ($line['packaging'] ?? 1);
                      $qty = max(1, (int) round((float) ($line['quantity'] ?? 1)));
                      $unitSp = (float) ($line['unit_sale_price_sp'] ?? 0);
                      $unitUsd = (float) ($line['unit_sale_price_usd'] ?? 0);
                      $priceSp = (float) ($line['sale_price_sp'] ?? 0);
                      $priceUsd = (float) ($line['sale_price_usd'] ?? 0);
                      $lineTotalSp = $qty * $priceSp;
                      $lineTotalUsd = $qty * $priceUsd;
                    ?>
                    <tr class="hover:bg-gray-50/80">
                      <td class="p-4">
                        <div class="flex items-center gap-3">
                          <?php if (!empty($line['image_url'])): ?>
                            <img src="<?= h((string) $line['image_url']) ?>" alt="" class="w-16 h-16 rounded-lg object-cover bg-gray-100 shrink-0" loading="lazy">
                          <?php else: ?>
                            <div class="w-16 h-16 rounded-lg bg-gray-100 shrink-0 flex items-center justify-center text-gray-400">
                              <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
                            </div>
                          <?php endif; ?>
                          <div>
                            <div class="font-bold text-sm"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
                            <?php if (!empty($line['material_code'])): ?>
                              <div class="text-xs text-gray-500"><?= h((string) $line['material_code']) ?></div>
                            <?php endif; ?>
                            <div class="text-xs text-gray-600 mt-1">
                              التعبئة:
                              <strong><?= h(rtrim(rtrim(number_format($packaging, 2, '.', ','), '0'), '.')) ?></strong>
                              <?= h($primaryUnit) ?> / <?= h($packageUnit) ?>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td class="p-4 text-sm whitespace-nowrap">
                        <?php if ($showPrice): ?>
                          <div class="text-xs text-gray-500"><?= format_money($unitSp, true) ?> ل.س / <?= h($primaryUnit) ?></div>
                          <div class="font-bold text-primary"><?= format_money($priceSp, true) ?> ل.س / <?= h($packageUnit) ?></div>
                          <?php if ($priceUsd > 0): ?>
                            <div class="text-emerald-700 text-xs mt-0.5">$<?= number_format($priceUsd, 2, '.', ',') ?> / <?= h($packageUnit) ?></div>
                          <?php endif; ?>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td class="p-4">
                        <div class="flex items-center border border-gray-200 rounded-lg w-max bg-white overflow-hidden">
                          <form method="post">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="bump_item">
                            <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                            <input type="hidden" name="delta" value="-1">
                            <button type="submit" class="px-3 py-2 text-primary hover:bg-gray-50 border-l border-gray-200" aria-label="إنقاص">−</button>
                          </form>
                          <form method="post" class="flex items-center">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="update_item">
                            <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                            <input
                              type="number"
                              name="quantity"
                              min="1"
                              <?php if ($maxPackagesPerMaterial !== null): ?>max="<?= (int) $maxPackagesPerMaterial ?>"<?php endif; ?>
                              step="1"
                              value="<?= (int) $qty ?>"
                              class="w-14 text-center font-bold border-0 focus:ring-0 py-2"
                              onchange="this.form.submit()"
                            >
                          </form>
                          <form method="post">
                            <input type="hidden" name="token" value="<?= h($token) ?>">
                            <input type="hidden" name="action" value="bump_item">
                            <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                            <input type="hidden" name="delta" value="1">
                            <button type="submit" class="px-3 py-2 text-primary hover:bg-gray-50 border-r border-gray-200" aria-label="زيادة">+</button>
                          </form>
                        </div>
                        <div class="text-xs text-gray-500 mt-1"><?= h($packageUnit) ?></div>
                      </td>
                      <td class="p-4 text-sm font-bold whitespace-nowrap">
                        <?php if ($showPrice): ?>
                          <?= format_money($lineTotalSp, true) ?> ل.س
                          <?php if ($lineTotalUsd > 0): ?>
                            <div class="text-emerald-700 text-xs font-normal">$<?= number_format($lineTotalUsd, 2, '.', ',') ?></div>
                          <?php endif; ?>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </td>
                      <td class="p-4 text-center">
                        <form method="post">
                          <input type="hidden" name="token" value="<?= h($token) ?>">
                          <input type="hidden" name="action" value="remove_item">
                          <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                          <button type="submit" class="p-2 rounded-full text-red-600 hover:bg-red-50" aria-label="حذف">
                            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <form method="post" class="mt-4">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="clear_cart">
            <button type="submit" class="text-sm font-bold text-gray-600 hover:text-red-600" <?= $cartItems === [] ? 'disabled' : '' ?>>تفريغ السلة</button>
          </form>
          <?php endif; ?>

          <?php if ($unavailableItems !== []): ?>
            <section class="mt-8 bg-white rounded-xl border border-red-200 shadow-sm overflow-hidden">
              <div class="px-4 py-3 border-b border-red-100 bg-red-50 flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h2 class="text-lg font-extrabold text-red-800">غير متوفرة للطلب</h2>
                  <p class="text-xs text-red-700 mt-0.5">هذه الأصناف لن تُرسل مع الطلب. قد يكون السبب حجز كميتها لطلبات أخرى قيد المعالجة.</p>
                </div>
                <form method="post">
                  <input type="hidden" name="token" value="<?= h($token) ?>">
                  <input type="hidden" name="action" value="clear_unavailable">
                  <button type="submit" class="text-xs font-bold text-red-700 hover:underline">إزالة الكل</button>
                </form>
              </div>
              <div class="divide-y divide-red-50">
                <?php foreach ($unavailableItems as $line): ?>
                  <?php
                    $materialGuid = (string) ($line['material_guid'] ?? '');
                    $packageUnit = (string) ($line['package_unit'] ?? 'طرد');
                    $qty = max(1, (int) round((float) ($line['quantity'] ?? 1)));
                  ?>
                  <div class="p-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                      <?php if (!empty($line['image_url'])): ?>
                        <img src="<?= h((string) $line['image_url']) ?>" alt="" class="w-14 h-14 rounded-lg object-cover bg-gray-100 shrink-0 opacity-70" loading="lazy">
                      <?php endif; ?>
                      <div class="min-w-0">
                        <div class="font-bold text-sm text-gray-800"><?= h((string) ($line['material_name_ar'] ?? '')) ?></div>
                        <div class="text-xs text-red-700 mt-1"><?= h((string) ($line['stock_message'] ?? 'نفدت الكمية المتاحة.')) ?></div>
                        <div class="text-xs text-gray-500 mt-1">الكمية المطلوبة: <?= (int) $qty ?> <?= h($packageUnit) ?></div>
                      </div>
                    </div>
                    <form method="post">
                      <input type="hidden" name="token" value="<?= h($token) ?>">
                      <input type="hidden" name="action" value="remove_unavailable">
                      <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                      <button type="submit" class="text-xs font-bold text-gray-600 hover:text-red-600">إزالة</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        </div>

        <div class="lg:col-span-4">
          <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 sticky top-24">
            <h2 class="text-lg font-extrabold border-b border-gray-100 pb-3 mb-4">ملخص الطلب</h2>
            <p class="text-xs text-gray-500 mb-4">
              <?= (int) $cartCount ?> صنف — الإجمالي = سعر الطرد × عدد الطرود
              (سعر الطرد = سعر الوحدة الأولى × التعبئة)
            </p>

            <?php if ($showPrice): ?>
              <div class="space-y-2 text-sm mb-4">
                <div class="flex justify-between font-bold text-base">
                  <span>الإجمالي (ل.س)</span>
                  <span class="text-primary"><?= format_money((float) $totals['total_sp'], true) ?> ل.س</span>
                </div>
                <?php if ((float) $totals['total_usd'] > 0): ?>
                  <div class="flex justify-between font-bold text-emerald-700">
                    <span>الإجمالي ($)</span>
                    <span>$<?= number_format((float) $totals['total_usd'], 2, '.', ',') ?></span>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($allowOrder): ?>
              <form method="post" class="space-y-3 border-t border-gray-100 pt-4">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <input type="hidden" name="action" value="submit_order">
                <?php if ($loggedInCustomer): ?>
                  <p class="text-xs text-gray-600 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">إرسال الطلب بحسابك المسجّل — بياناتك مأخوذة من ملفك.</p>
                <?php else: ?>
                  <p class="text-xs font-bold text-gray-600">إرسال الطلب بدون تسجيل دخول</p>
                  <label class="block text-sm font-bold">الاسم الكامل *
                    <input name="guest_name_ar" required value="<?= h($defaultGuestName) ?>" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1">
                  </label>
                  <label class="block text-sm font-bold">رقم الهاتف *
                    <input name="guest_phone" required dir="ltr" value="<?= h($defaultGuestPhone) ?>" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1 text-left" placeholder="09xxxxxxxx">
                  </label>
                <?php endif; ?>
                <label class="block text-sm font-bold">ملاحظات
                  <textarea name="notes_ar" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 mt-1 text-sm" placeholder="اختياري"></textarea>
                </label>
                <button
                  class="w-full h-12 rounded-lg bg-primary text-white font-extrabold shadow-md hover:opacity-95 transition inline-flex items-center justify-center gap-2<?= $cartItems === [] ? ' opacity-50 cursor-not-allowed' : '' ?>"
                  <?= $cartItems === [] ? 'disabled' : '' ?>
                >
                  <span class="material-symbols-outlined" aria-hidden="true">send</span>
                  تأكيد وإرسال الطلب
                </button>
              </form>
            <?php else: ?>
              <p class="text-sm text-amber-800 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">هذا الرابط يسمح بالسلة فقط دون إرسال طلب.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$title = 'السلة';
require dirname(__DIR__) . '/views/layout.php';
