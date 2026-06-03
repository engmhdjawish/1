<?php

declare(strict_types=1);

/** @var string $type */
/** @var string|null $error */
/** @var string|null $message */
?>
<div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border p-6">
  <h1 class="text-xl font-bold mb-4">تسجيل الدخول</h1>
  <?php if ($error): ?><p class="mb-3 text-sm text-red-600"><?= h($error) ?></p><?php endif; ?>
  <?php if ($message): ?><p class="mb-3 text-sm text-green-700"><?= h($message) ?></p><?php endif; ?>

  <div class="flex gap-2 mb-4 text-sm">
    <a href="?type=staff" class="px-3 py-1 rounded <?= $type === 'staff' ? 'bg-primary text-white' : 'bg-gray-100' ?>">موظّف</a>
    <a href="?type=customer" class="px-3 py-1 rounded <?= $type === 'customer' ? 'bg-primary text-white' : 'bg-gray-100' ?>">عميل</a>
  </div>

  <form method="post" class="space-y-3">
    <input type="hidden" name="type" value="<?= h($type) ?>">
    <?php if ($type === 'customer'): ?>
      <label class="block text-sm">رقم الهاتف<input name="phone" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <?php else: ?>
      <label class="block text-sm">اسم المستخدم<input name="user_name" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <?php endif; ?>
    <label class="block text-sm">كلمة المرور<input type="password" name="password" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <button type="submit" class="w-full bg-primary text-white rounded-lg py-2 font-semibold">دخول</button>
  </form>
</div>
