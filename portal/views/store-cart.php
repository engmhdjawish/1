<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $cartItems */
/** @var list<array<string, mixed>> $unavailableItems */
/** @var array{total_sp: float, total_usd: float} $totals */
/** @var bool $allowCart */
/** @var bool $allowOrder */
/** @var bool $showPrice */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $defaultGuestName */
/** @var string $defaultGuestPhone */
/** @var float|null $maxPackagesPerMaterial */
?>
<div class="max-w-5xl mx-auto">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
      <h1 class="text-2xl md:text-3xl font-extrabold">سلة المتجر</h1>
      <p class="text-sm text-gray-600 mt-1">راجع الأصناف ثم أرسل طلبك.</p>
    </div>
    <a href="/store.php" class="h-10 inline-flex items-center rounded-xl border border-gray-300 px-4 text-sm font-bold hover:border-primary">متابعة التسوق</a>
  </div>

  <?php if ($error): ?>
    <p class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm"><?= h($error) ?></p>
  <?php endif; ?>
  <?php if ($notice): ?>
    <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm"><?= h($notice) ?></p>
  <?php endif; ?>

  <?php if ($cartItems === [] && $unavailableItems === []): ?>
    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
      السلة فارغة.
      <div class="mt-4"><a href="/store.php" class="inline-flex h-11 items-center rounded-xl bg-primary text-white px-6 font-bold">تصفح المتجر</a></div>
    </div>
  <?php else: ?>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div class="lg:col-span-8 space-y-4">
        <?php foreach ($cartItems as $line): ?>
          <?php
            $materialGuid = (string) ($line['material_guid'] ?? '');
            $qty = max(1, (int) round((float) ($line['quantity'] ?? 1)));
            $packageUnit = (string) ($line['package_unit'] ?? 'طرد');
            $priceSp = (float) ($line['sale_price_sp'] ?? 0);
            $lineTotalSp = $qty * $priceSp;
          ?>
          <article class="rounded-2xl border border-gray-200 bg-white p-4 flex flex-col sm:flex-row gap-4">
            <?php if (!empty($line['image_url'])): ?>
              <img src="<?= h((string) $line['image_url']) ?>" alt="" class="w-20 h-20 rounded-lg object-cover bg-gray-100 shrink-0">
            <?php endif; ?>
            <div class="flex-1 min-w-0">
              <h2 class="font-bold"><?= h((string) ($line['material_name_ar'] ?? '')) ?></h2>
              <?php if (!empty($line['material_code'])): ?>
                <p class="text-xs text-gray-500 font-mono" dir="ltr"><?= h((string) $line['material_code']) ?></p>
              <?php endif; ?>
              <?php if ($showPrice): ?>
                <p class="text-sm text-primary font-bold mt-2"><?= format_money($lineTotalSp, true) ?> ل.س</p>
              <?php endif; ?>
            </div>
            <div class="flex flex-col gap-2 shrink-0">
              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                <input type="number" name="quantity" min="1" <?php if ($maxPackagesPerMaterial !== null): ?>max="<?= (int) $maxPackagesPerMaterial ?>"<?php endif; ?> value="<?= (int) $qty ?>" class="w-16 h-10 rounded-lg border border-gray-300 text-center font-bold">
                <button type="submit" class="text-xs font-bold text-primary">تحديث</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="remove_item">
                <input type="hidden" name="material_guid" value="<?= h($materialGuid) ?>">
                <button type="submit" class="text-xs font-bold text-red-600">حذف</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if ($unavailableItems !== []): ?>
          <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <h3 class="font-bold text-amber-900 mb-2">غير متوفرة حالياً</h3>
            <ul class="text-sm text-amber-900 space-y-1">
              <?php foreach ($unavailableItems as $line): ?>
                <li><?= h((string) ($line['material_name_ar'] ?? '')) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
      </div>

      <aside class="lg:col-span-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4 sticky top-24">
          <?php if ($showPrice): ?>
            <div class="text-lg font-extrabold text-primary">الإجمالي: <?= format_money((float) $totals['total_sp'], true) ?> ل.س</div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="action" value="clear_cart">
            <button type="submit" class="text-sm font-bold text-gray-600 hover:text-red-600">تفريغ السلة</button>
          </form>

          <?php if ($allowOrder && $cartItems !== []): ?>
            <form method="post" class="space-y-3 border-t border-gray-100 pt-4">
              <input type="hidden" name="action" value="submit_order">
              <label class="block text-sm font-bold">الاسم الكامل *
                <input name="guest_name_ar" required value="<?= h($defaultGuestName) ?>" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1">
              </label>
              <label class="block text-sm font-bold">رقم الهاتف *
                <input name="guest_phone" required dir="ltr" value="<?= h($defaultGuestPhone) ?>" class="h-11 w-full rounded-lg border border-gray-300 px-3 mt-1 text-left">
              </label>
              <label class="block text-sm font-bold">ملاحظات
                <textarea name="notes_ar" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 mt-1 text-sm"></textarea>
              </label>
              <button type="submit" class="w-full h-12 rounded-xl bg-primary text-white font-extrabold">تأكيد وإرسال الطلب</button>
            </form>
          <?php elseif (!$allowOrder): ?>
            <p class="text-sm text-amber-800">سياسة المتجر لا تسمح بإرسال الطلبات حالياً.</p>
          <?php endif; ?>
        </div>
      </aside>
    </div>
  <?php endif; ?>
</div>
