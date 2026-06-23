<?php

declare(strict_types=1);

/** @var array<string, mixed> $customer */
/** @var array<string, mixed> $profile */
/** @var string $tab */
/** @var list<array<string, mixed>> $orders */
/** @var array<string, mixed>|null $orderDetails */
/** @var string $orderId */
/** @var string $statusFilter */
/** @var string|null $flash */
/** @var string $flashType */

use Portal\Services\OrderService;

$statusOptions = [
    '' => 'كل الحالات',
    'pending' => OrderService::statusLabel('pending'),
    'confirmed' => OrderService::statusLabel('confirmed'),
    'completed' => OrderService::statusLabel('completed'),
    'cancelled' => OrderService::statusLabel('cancelled'),
];
?>
<div class="max-w-4xl mx-auto">
  <h1 class="text-2xl md:text-3xl font-extrabold mb-1">حسابي</h1>
  <p class="text-sm text-gray-600 mb-6">إدارة بياناتك ومتابعة طلباتك.</p>

  <?php if ($flash): ?>
    <p class="mb-4 rounded-xl border px-4 py-3 text-sm <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-700' ?>">
      <?= h($flash) ?>
    </p>
  <?php endif; ?>

  <nav class="inline-flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm mb-6" aria-label="أقسام الحساب">
    <a href="/account.php?tab=profile" class="h-10 px-4 inline-flex items-center rounded-lg text-sm font-bold <?= $tab === 'profile' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-50' ?>">الملف الشخصي</a>
    <a href="/account.php?tab=orders" class="h-10 px-4 inline-flex items-center rounded-lg text-sm font-bold <?= $tab === 'orders' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-gray-50' ?>">طلباتي</a>
  </nav>

  <?php if ($tab === 'profile'): ?>
    <div class="grid gap-6 lg:grid-cols-2">
      <form method="post" class="rounded-2xl border border-gray-200 bg-white p-5 space-y-4 shadow-sm">
        <input type="hidden" name="action" value="update_profile">
        <h2 class="font-bold text-lg">بيانات الحساب</h2>
        <label class="block text-sm font-medium">
          الاسم
          <input name="name_ar" value="<?= h((string) ($profile['name_ar'] ?? $customer['name_ar'] ?? '')) ?>" required class="mt-1 h-11 w-full rounded-xl border border-gray-300 px-4">
        </label>
        <label class="block text-sm font-medium">
          رقم الهاتف
          <input value="<?= h((string) ($customer['phone'] ?? '')) ?>" disabled class="mt-1 h-11 w-full rounded-xl border border-gray-200 bg-gray-50 px-4 text-gray-500" dir="ltr">
        </label>
        <label class="block text-sm font-medium">
          البريد الإلكتروني
          <input type="email" name="email" value="<?= h((string) ($profile['email'] ?? '')) ?>" class="mt-1 h-11 w-full rounded-xl border border-gray-300 px-4" dir="ltr">
        </label>
        <button type="submit" class="h-11 px-5 rounded-xl bg-primary text-white font-bold hover:brightness-110">حفظ التغييرات</button>
      </form>

      <form method="post" class="rounded-2xl border border-gray-200 bg-white p-5 space-y-4 shadow-sm">
        <input type="hidden" name="action" value="change_password">
        <h2 class="font-bold text-lg">تغيير كلمة المرور</h2>
        <label class="block text-sm font-medium">
          كلمة المرور الحالية
          <input type="password" name="current_password" required autocomplete="current-password" class="mt-1 h-11 w-full rounded-xl border border-gray-300 px-4">
        </label>
        <label class="block text-sm font-medium">
          كلمة المرور الجديدة
          <input type="password" name="new_password" required minlength="6" autocomplete="new-password" class="mt-1 h-11 w-full rounded-xl border border-gray-300 px-4">
        </label>
        <button type="submit" class="h-11 px-5 rounded-xl border border-gray-300 font-bold hover:bg-gray-50">تحديث كلمة المرور</button>
      </form>
    </div>
  <?php else: ?>
    <?php if ($orderDetails !== null): ?>
      <?php
        $detailStatus = (string) ($orderDetails['status'] ?? 'pending');
        $items = is_array($orderDetails['items'] ?? null) ? $orderDetails['items'] : [];
      ?>
      <div class="mb-4">
        <a href="/account.php?tab=orders<?= $statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '' ?>" class="inline-flex items-center gap-1 text-sm font-bold text-primary hover:underline">
          <span class="material-symbols-outlined text-base">chevron_right</span>
          العودة إلى الطلبات
        </a>
      </div>
      <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-xl font-extrabold"><?= h((string) ($orderDetails['order_number'] ?? '')) ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?= h((string) ($orderDetails['created_at'] ?? '')) ?></p>
          </div>
          <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold bg-primary/10 text-primary">
            <?= h(OrderService::statusLabel($detailStatus)) ?>
          </span>
        </div>
        <?php if (!empty($orderDetails['notes_ar'])): ?>
          <p class="text-sm text-gray-600 rounded-lg bg-gray-50 px-3 py-2"><?= h((string) $orderDetails['notes_ar']) ?></p>
        <?php endif; ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm text-right min-w-[520px]">
            <thead>
              <tr class="border-b text-gray-500">
                <th class="py-2 font-bold">المادة</th>
                <th class="py-2 font-bold">الكمية</th>
                <th class="py-2 font-bold">الإجمالي ل.س</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <?php foreach ($items as $item): ?>
                <tr>
                  <td class="py-2">
                    <div class="font-bold"><?= h((string) ($item['material_name_ar'] ?? '')) ?></div>
                    <?php if (!empty($item['material_code'])): ?>
                      <div class="text-xs text-gray-500 font-mono" dir="ltr"><?= h((string) $item['material_code']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="py-2"><?= h((string) ($item['quantity'] ?? '0')) ?></td>
                  <td class="py-2 font-bold"><?= format_money((float) ($item['line_total_sp'] ?? 0), true) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="flex flex-wrap gap-4 text-sm font-bold border-t pt-4">
          <span>الإجمالي: <?= format_money((float) ($orderDetails['total_sp'] ?? 0), true) ?> ل.س</span>
          <?php if ((float) ($orderDetails['total_usd'] ?? 0) > 0): ?>
            <span class="text-emerald-700">$<?= number_format((float) $orderDetails['total_usd'], 2, '.', ',') ?></span>
          <?php endif; ?>
        </div>
      </section>
    <?php else: ?>
      <form method="get" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="tab" value="orders">
        <label class="text-sm">
          <span class="text-gray-600">الحالة</span>
          <select name="status" class="mt-1 h-10 rounded-xl border border-gray-300 px-3 text-sm">
            <?php foreach ($statusOptions as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="h-10 px-4 rounded-xl bg-primary text-white text-sm font-bold">تصفية</button>
      </form>

      <?php if ($orders === []): ?>
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500">
          لا توجد طلبات مرتبطة بحسابك حتى الآن.
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($orders as $row): ?>
            <?php
              $rowStatus = (string) ($row['status'] ?? 'pending');
              $rowId = (string) ($row['id'] ?? '');
              $detailUrl = '/account.php?tab=orders&order=' . rawurlencode($rowId)
                  . ($statusFilter !== '' ? '&status=' . rawurlencode($statusFilter) : '');
            ?>
            <a href="<?= h($detailUrl) ?>" class="block rounded-2xl border border-gray-200 bg-white p-4 shadow-sm hover:border-primary/40 transition">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <div class="font-extrabold"><?= h((string) ($row['order_number'] ?? '')) ?></div>
                  <div class="text-xs text-gray-500 mt-1"><?= h((string) ($row['created_at'] ?? '')) ?></div>
                </div>
                <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold bg-gray-100 text-gray-700">
                  <?= h(OrderService::statusLabel($rowStatus)) ?>
                </span>
              </div>
              <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-600">
                <span><?= (int) ($row['items_count'] ?? 0) ?> صنف</span>
                <span class="font-bold text-gray-900"><?= format_money((float) ($row['total_sp'] ?? 0), true) ?> ل.س</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
