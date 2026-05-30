<?php

declare(strict_types=1);

/** @var string|null $error */
/** @var string|null $message */
?>
<div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border p-6">
  <h1 class="text-xl font-bold mb-2">تسجيل عميل جديد</h1>
  <p class="text-sm text-gray-600 mb-4">بعد التسجيل يتم مراجعة طلبك وتفعيل الحساب من الإدارة.</p>
  <?php if ($error): ?><p class="mb-3 text-sm text-red-600"><?= h($error) ?></p><?php endif; ?>
  <?php if ($message): ?><p class="mb-3 text-sm text-green-700"><?= h($message) ?></p><?php endif; ?>
  <form method="post" class="space-y-3">
    <label class="block text-sm">الاسم<input name="name" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <label class="block text-sm">الهاتف<input name="phone" class="w-full border rounded px-3 py-2 mt-1" required></label>
    <label class="block text-sm">البريد (اختياري)<input name="email" type="email" class="w-full border rounded px-3 py-2 mt-1"></label>
    <label class="block text-sm">كلمة المرور<input type="password" name="password" class="w-full border rounded px-3 py-2 mt-1" required minlength="6"></label>
    <button type="submit" class="w-full bg-primary text-white rounded-lg py-2 font-semibold">إرسال الطلب</button>
  </form>
</div>
